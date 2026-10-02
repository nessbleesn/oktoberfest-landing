<?php
declare(strict_types=1);

$database = tempnam(sys_get_temp_dir(), 'eda-http-');
$cookie = tempnam(sys_get_temp_dir(), 'eda-cookie-');
if ($database === false) throw new RuntimeException('temp database unavailable');
if ($cookie === false) throw new RuntimeException('temp cookie unavailable');
$db = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec(file_get_contents(dirname(__DIR__) . '/schema.sqlite.sql'));
unset($db);
$port = random_int(18000, 28000);
$server = dirname(__DIR__);
$env = [
    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    'OKTOBERFEST_DB_DSN' => 'sqlite:' . $database,
    'OKTOBERFEST_RATE_LIMIT_SECRET' => str_repeat('t', 40),
    'UNISENDER_API_KEY' => '',
    'UNISENDER_LIST_ID' => '',
    'BITRIX24_WEBHOOK_URL' => '',
    'OKTOBERFEST_CASHIER_PASSWORD_HASH' => password_hash('test-password', PASSWORD_DEFAULT)
];
$process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', dirname($server), $server . '/dev-router.php'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, dirname($server), $env);
if (!is_resource($process)) throw new RuntimeException('PHP test server unavailable');

function request(int $port, array $payload): array {
    $ch = curl_init("http://127.0.0.1:{$port}/oktoberfest-api/promo");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [(int)$status, is_string($body) ? json_decode($body, true) : null];
}
function cashierRequest(int $port, string $cookie, ?array $payload = null): string {
    $ch = curl_init("http://127.0.0.1:{$port}/oktoberfest-api/cashier");
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie];
    if ($payload !== null) { $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = http_build_query($payload); }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!is_string($body)) throw new RuntimeException('cashier HTTP failure');
    return $body;
}
function csrf(string $html): string {
    if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $match)) throw new RuntimeException('CSRF field absent');
    return $match[1];
}

try {
    $payload = ['email' => 'http@example.test', 'phone' => '+7 999 888 77 66', 'name' => 'HTTP test', 'personal_consent' => true, 'marketing_consent' => false,
        'utm_source' => 'vk', 'utm_medium' => 'cpc', 'utm_campaign' => 'oktoberfest_2026', 'utm_content' => 'button', 'utm_term' => 'еда'];
    $first = null;
    for ($i = 0; $i < 20; $i++) {
        $first = request($port, $payload);
        if ($first[0] !== 0) break;
        usleep(100000);
    }
    if ($first[0] !== 200 || ($first[1]['promo_code'] ?? '') !== 'EDA1' || ($first[1]['delivery_pending'] ?? false) !== true || ($first[1]['bitrix_success'] ?? null) !== false) throw new RuntimeException('HTTP first code failed');
    $snapshotDb = new PDO('sqlite:' . $database);
    $storedUtm = $snapshotDb->query('SELECT utm_source,utm_medium,utm_campaign,utm_content,utm_term FROM oktoberfest_promos')->fetch(PDO::FETCH_ASSOC);
    if ($storedUtm !== ['utm_source' => 'vk', 'utm_medium' => 'cpc', 'utm_campaign' => 'oktoberfest_2026', 'utm_content' => 'button', 'utm_term' => 'еда']) throw new RuntimeException('HTTP UTM fields not stored');
    $before = $snapshotDb->query('SELECT COUNT(*) AS n, MAX(sync_attempts) AS attempts FROM oktoberfest_promos')->fetch(PDO::FETCH_ASSOC);
    $again = request($port, array_merge($payload, ['email' => '  HTTP@EXAMPLE.TEST  ']));
    if ($again[0] !== 409 || ($again[1]['error'] ?? '') !== 'duplicate_email' || isset($again[1]['promo_code'])) throw new RuntimeException('HTTP duplicate email was not blocked');
    $after = $snapshotDb->query('SELECT COUNT(*) AS n, MAX(sync_attempts) AS attempts FROM oktoberfest_promos')->fetch(PDO::FETCH_ASSOC);
    if ($before !== $after || (int)$snapshotDb->query('SELECT last_sequence FROM oktoberfest_campaign_counters')->fetchColumn() !== 1) throw new RuntimeException('HTTP duplicate changed registration or sync state');
    $invalid = request($port, $payload + ['promo_code' => 'EDA999']);
    if ($invalid[0] !== 400) throw new RuntimeException('Client code was accepted');
    $honeypot = request($port, $payload + ['company' => 'bot']);
    if ($honeypot[0] !== 400) throw new RuntimeException('Honeypot was accepted');
    echo "PASS HTTP EDA1, duplicate email rejected without resync, client code rejected\n";
    $loginPage = cashierRequest($port, $cookie);
    if (!str_contains($loginPage, 'Пароль кассира') || str_contains($loginPage, 'Владелец')) throw new RuntimeException('cashier exposed private data');
    $authorized = cashierRequest($port, $cookie, ['csrf' => csrf($loginPage), 'action' => 'login', 'password' => 'test-password']);
    if (!str_contains($authorized, 'Промокод')) throw new RuntimeException('cashier login failed');
    $token = csrf($authorized);
    $lookup = cashierRequest($port, $cookie, ['csrf' => $token, 'action' => 'lookup', 'code' => 'EDA1']);
    if (!str_contains($lookup, 'Действителен') || !str_contains($lookup, 'HTTP test')) throw new RuntimeException('cashier lookup failed');
    $redeemed = cashierRequest($port, $cookie, ['csrf' => $token, 'action' => 'redeem', 'code' => 'EDA1']);
    if (!str_contains($redeemed, 'Промокод погашен')) throw new RuntimeException('cashier redemption failed');
    $againRedeem = cashierRequest($port, $cookie, ['csrf' => $token, 'action' => 'redeem', 'code' => 'EDA1']);
    if (!str_contains($againRedeem, 'Уже использован')) throw new RuntimeException('cashier repeat redemption failed');
    $missing = cashierRequest($port, $cookie, ['csrf' => $token, 'action' => 'lookup', 'code' => 'EDA999']);
    if (!str_contains($missing, 'Не найден')) throw new RuntimeException('cashier unknown code failed');
    echo "PASS cashier auth, lookup, redemption, repeated/unknown code\n";
} finally {
    proc_terminate($process);
    foreach ($pipes as $pipe) fclose($pipe);
    proc_close($process);
    @unlink($database);
    @unlink($cookie);
}
