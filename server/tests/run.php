<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function testDb(string $dsn = 'sqlite::memory:'): PDO {
    $db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout = 10000');
    if ($dsn === 'sqlite::memory:') $db->exec(file_get_contents(dirname(__DIR__) . '/schema.sqlite.sql'));
    return $db;
}
function submit(PromoStore $store, int $n, ?string $phone = null): array {
    return $store->issue(['name' => 'Тест ' . $n, 'email' => "person{$n}@example.test", 'phone' => $phone ?? '', 'personal_consent' => true]);
}
final class FakeClient implements PromoContactClient {
    public array $fields = [];
    public function __construct(private bool $works) {}
    public function sync(array $promo): bool { $this->fields = UniSenderClient::contactFields($promo); return $this->works; }
}
final class FakeLeadClient implements PromoLeadClient {
    public int $creates = 0;
    public int $lookups = 0;
    public ?int $existing = null;
    public bool $fails = false;
    public function find(string $applicationId): ?int {
        $this->lookups++;
        if ($this->fails) throw new RuntimeException('simulated CRM outage');
        return $this->existing;
    }
    public function create(array $promo): ?int {
        $this->creates++;
        return $this->fails ? null : 501;
    }
}

$checks = 0;
try {
    $db = testDb(); $store = new PromoStore($db);
    for ($i = 1; $i <= 7; $i++) {
        $promo = submit($store, $i, $i === 7 ? '+7 (999) 000-00-07' : null);
        if (in_array($i, [1, 5, 7], true)) { check($promo['promo_code'] === 'EDA' . $i, "sequence {$i}"); $checks++; }
    }
    check($store->issue(['email' => ' PERSON1@EXAMPLE.TEST ', 'personal_consent' => true])['promo_code'] === 'EDA1', 'repeat normalized email'); $checks++;
    $samePhone = $store->issue(['email' => 'other@example.test', 'phone' => '8 999 000 00 07', 'personal_consent' => true]);
    check($samePhone['promo_code'] === 'EDA7', 'repeat phone'); $checks++;
    check((int)$db->query("SELECT last_sequence FROM oktoberfest_campaign_counters WHERE campaign_key='oktoberfest'")->fetchColumn() === 7, 'duplicate changed counter'); $checks++;
    $failed = new FakeClient(false);
    check(!promo_sync($store, $failed, $promo), 'sync failure reported');
    check($store->findCode('EDA7') !== null && $store->pending() !== [], 'sync failure preserves code'); $checks++;
    $good = new FakeClient(true);
    check(promo_sync($store, $good, $promo), 'sync success');
    check(($good->fields['promo_code'] ?? '') === 'EDA7', 'UniSender field'); $checks++;
    check(UniSenderClient::contactTags($promo) === 'oktoberfest-2026', 'no marketing tag without consent'); $checks++;
    check(UniSenderClient::contactTags(['marketing_consent' => 1]) === 'oktoberfest-2026,marketing-consent', 'marketing tag with consent'); $checks++;
    $leadFields = BitrixLeadClient::leadFields($promo);
    check(($leadFields['ORIGIN_ID'] ?? '') === $promo['application_id'] && str_contains($leadFields['COMMENTS'] ?? '', 'EDA7'), 'Bitrix lead fields'); $checks++;
    $failedLead = new FakeLeadClient(); $failedLead->fails = true;
    check(!promo_bitrix_sync($store, $failedLead, $promo)['confirmed'], 'Bitrix outage reported');
    check($store->findCode('EDA7') !== null && count($store->pendingBitrix()) === 7, 'Bitrix outage preserves promo'); $checks++;
    $workingLead = new FakeLeadClient();
    $firstLead = promo_bitrix_sync($store, $workingLead, $promo);
    check($firstLead === ['confirmed' => true, 'new_lead' => true] && $workingLead->creates === 1, 'new lead confirmation'); $checks++;
    $repeatLead = $store->issue(['email' => $promo['email'], 'personal_consent' => true]);
    check(promo_bitrix_sync($store, $workingLead, $repeatLead) === ['confirmed' => true, 'new_lead' => false] && $workingLead->creates === 1, 'repeat does not create lead or new conversion'); $checks++;
    $recoveredLead = new FakeLeadClient(); $recoveredLead->existing = 777;
    check(promo_bitrix_sync($store, $recoveredLead, submit($store, 6))['confirmed'] && $recoveredLead->creates === 0, 'ambiguous CRM timeout recovered'); $checks++;
    $stalePromo = submit($store, 2);
    $racingClient = new class($store) implements PromoContactClient {
        public function __construct(private PromoStore $store) {}
        public function sync(array $promo): bool {
            $this->store->issue(['email' => $promo['email'], 'phone' => '+7 999 000 00 02', 'personal_consent' => true]);
            return true;
        }
    };
    check(!promo_sync($store, $racingClient, $stalePromo), 'stale sync marked complete'); $checks++;
    check($store->redeem('EDA7') === 'redeemed', 'redeem'); $checks++;
    check($store->redeem('EDA7') === 'already_used', 'repeat redemption'); $checks++;
    check($store->redeem('EDA999') === 'not_found', 'unknown code'); $checks++;
    check($store->findCode('EDA7')['used_at'] !== null, 'used_at'); $checks++;
    putenv('OKTOBERFEST_RATE_LIMIT_SECRET=' . str_repeat('r', 40));
    for ($i = 0; $i < 10; $i++) check(promo_rate_limit($db, 'test-ip'), 'rate limit early rejection');
    check(!promo_rate_limit($db, 'test-ip'), 'rate limit missing'); $checks++;
    echo "PASS core {$checks} checks\n";

    // Separate processes and one persistent SQLite file exercise overlapping submissions.
    if (!function_exists('pcntl_fork')) throw new RuntimeException('pcntl unavailable: parallel test not run');
    $path = tempnam(sys_get_temp_dir(), 'eda-test-');
    if ($path === false) throw new RuntimeException('Could not create test database');
    try {
        $db2 = testDb('sqlite:' . $path);
        $db2->exec(file_get_contents(dirname(__DIR__) . '/schema.sqlite.sql'));
        unset($db2);
        $children = [];
        for ($i = 0; $i < 8; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) throw new RuntimeException('fork failed');
            if ($pid === 0) {
                try { submit(new PromoStore(testDb('sqlite:' . $path)), 100 + $i); exit(0); }
                catch (Throwable $error) { fwrite(STDERR, 'child_failed class=' . get_class($error) . "\n"); exit(1); }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) { pcntl_waitpid($pid, $status); check(pcntl_wexitstatus($status) === 0, 'parallel child failed'); }
        $verify = testDb('sqlite:' . $path);
        $codes = $verify->query('SELECT sequence_number FROM oktoberfest_promos ORDER BY sequence_number')->fetchAll(PDO::FETCH_COLUMN);
        check($codes === range(1, 8), 'parallel codes not sequential');
        echo "PASS parallel 8 unique consecutive codes\n";
    } finally { @unlink($path); }
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . "\n");
    exit(1);
}
