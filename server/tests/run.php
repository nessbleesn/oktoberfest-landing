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
    $issued = [];
    for ($i = 1; $i <= 7; $i++) {
        $promo = submit($store, $i, $i === 7 ? '+7 (999) 000-00-07' : null);
        $issued[$i] = $promo;
        if (in_array($i, [1, 5, 7], true)) { check($promo['promo_code'] === 'EDA' . $i, "sequence {$i}"); $checks++; }
    }
    try {
        $store->issue(['email' => ' PERSON1@EXAMPLE.TEST ', 'personal_consent' => true]);
        throw new RuntimeException('repeat normalized email accepted');
    } catch (PromoDuplicateEmail $error) { $checks++; }
    $samePhone = $store->issue(['email' => 'other@example.test', 'phone' => '8 999 000 00 07', 'personal_consent' => true]);
    check($samePhone['promo_code'] === 'EDA7', 'repeat phone'); $checks++;
    check((int)$db->query("SELECT last_sequence FROM oktoberfest_campaign_counters WHERE campaign_key='oktoberfest'")->fetchColumn() === 7, 'duplicate changed counter'); $checks++;
    $storedConsent = $db->query('SELECT personal_consent,personal_consented_at,marketing_consent,marketing_choice_at FROM oktoberfest_promos WHERE sequence_number=7')->fetch();
    check((int)$storedConsent['personal_consent'] === 1 && (int)$storedConsent['marketing_consent'] === 0 && $storedConsent['personal_consented_at'] !== null && $storedConsent['marketing_choice_at'] !== null, 'opt-out and both decision times stored'); $checks++;
    $consentDb = testDb(); $consentStore = new PromoStore($consentDb);
    $consentStore->issue(['email' => 'opt-in@example.test', 'personal_consent' => true, 'marketing_consent' => true]);
    check((int)$consentDb->query('SELECT marketing_consent FROM oktoberfest_promos')->fetchColumn() === 1, 'explicit opt-in stored'); $checks++;
    try {
        $consentStore->issue(['email' => 'no-personal@example.test', 'personal_consent' => false]);
        throw new RuntimeException('missing personal consent accepted');
    } catch (PromoInputError $error) { $checks++; }
    $failed = new FakeClient(false);
    check(!promo_sync($store, $failed, $promo), 'sync failure reported');
    check($store->findCode('EDA7') !== null && $store->pending() !== [], 'sync failure preserves code'); $checks++;
    $good = new FakeClient(true);
    check(promo_sync($store, $good, $promo), 'sync success');
    check(($good->fields['promo_code'] ?? '') === 'EDA7', 'UniSender field'); $checks++;
    check(($good->fields['consent'] ?? '') === 'Да' && ($good->fields['sogl'] ?? '') === 'Нет', 'UniSender separate consent fields'); $checks++;
    check(!empty($promo['personal_consented_at']) && !empty($promo['marketing_choice_at']), 'database consent timestamps'); $checks++;
    check(UniSenderClient::contactTags($promo) === 'oktoberfest-2026', 'no marketing tag without consent'); $checks++;
    check(UniSenderClient::contactTags(['marketing_consent' => 1]) === 'oktoberfest-2026,marketing-consent', 'marketing tag with consent'); $checks++;
    $import = UniSenderClient::importFields($promo);
    check(in_array('promo_code', $import['field_names'], true) && in_array('tags', $import['field_names'], true) && $import['data'][0][2] === 'EDA7' && !str_contains(implode(',', $import['data'][0]), 'marketing-consent'), 'opt-out import without advertising tag'); $checks++;
    check($import['overwrite_tags'] === 0 && $import['overwrite_lists'] === 0, 'UniSender import preserves tags and lists'); $checks++;
    $optedIn = array_merge($promo, ['marketing_consent' => 1]);
    check(UniSenderClient::contactFields($optedIn)['sogl'] === 'Да' && in_array('tags', UniSenderClient::importFields($optedIn)['field_names'], true), 'opt-in is explicit in field and tag'); $checks++;
    putenv('BITRIX24_PERSONAL_CONSENT_AT_FIELD=UF_CRM_TEST_PERSONAL_AT');
    putenv('BITRIX24_MARKETING_CHOICE_AT_FIELD=UF_CRM_TEST_MARKETING_AT');
    $leadFields = BitrixLeadClient::leadFields($promo);
    check(($leadFields['ORIGIN_ID'] ?? '') === $promo['application_id'] && str_contains($leadFields['COMMENTS'] ?? '', 'EDA7'), 'Bitrix lead fields'); $checks++;
    check($leadFields['UF_CRM_PERSONALDATA_APPROVED'] === 1 && $leadFields['UF_CRM_SUBSCRIPTION_APPROVED'] === 0, 'Bitrix separate boolean consent fields'); $checks++;
    $optedInLeadFields = BitrixLeadClient::leadFields($optedIn);
    check($optedInLeadFields['UF_CRM_PERSONALDATA_APPROVED'] === 1 && $optedInLeadFields['UF_CRM_SUBSCRIPTION_APPROVED'] === 1, 'Bitrix explicit advertising opt-in'); $checks++;
    check(!empty($leadFields['UF_CRM_TEST_PERSONAL_AT']) && !empty($leadFields['UF_CRM_TEST_MARKETING_AT']), 'Bitrix consent timestamps'); $checks++;
    $failedLead = new FakeLeadClient(); $failedLead->fails = true;
    check(!promo_bitrix_sync($store, $failedLead, $promo)['confirmed'], 'Bitrix outage reported');
    check($store->findCode('EDA7') !== null && count($store->pendingBitrix()) === 7, 'Bitrix outage preserves promo'); $checks++;
    $workingLead = new FakeLeadClient();
    $firstLead = promo_bitrix_sync($store, $workingLead, $promo);
    check($firstLead === ['confirmed' => true, 'new_lead' => true] && $workingLead->creates === 1, 'new lead confirmation'); $checks++;
    try {
        $store->issue(['email' => $promo['email'], 'personal_consent' => true]);
        throw new RuntimeException('repeat email reached CRM');
    } catch (PromoDuplicateEmail $error) {
        check($workingLead->creates === 1, 'repeat created a lead'); $checks++;
    }
    $recoveredLead = new FakeLeadClient(); $recoveredLead->existing = 777;
    check(promo_bitrix_sync($store, $recoveredLead, $issued[6])['confirmed'] && $recoveredLead->creates === 0, 'ambiguous CRM timeout recovered'); $checks++;
    $stalePromo = submit($store, 8, '+7 999 000 00 08');
    $racingClient = new class($store) implements PromoContactClient {
        public function __construct(private PromoStore $store) {}
        public function sync(array $promo): bool {
            $this->store->issue(['email' => 'another@example.test', 'phone' => $promo['phone'], 'name' => 'Changed', 'personal_consent' => true]);
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
    $samePath = tempnam(sys_get_temp_dir(), 'eda-same-');
    if ($samePath === false) throw new RuntimeException('Could not create duplicate test database');
    try {
        $sameDb = testDb('sqlite:' . $samePath);
        $sameDb->exec(file_get_contents(dirname(__DIR__) . '/schema.sqlite.sql'));
        unset($sameDb);
        $children = [];
        for ($i = 0; $i < 8; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) throw new RuntimeException('fork failed');
            if ($pid === 0) {
                try { (new PromoStore(testDb('sqlite:' . $samePath)))->issue(['email' => ' SAME@EXAMPLE.TEST ', 'personal_consent' => true]); exit(0); }
                catch (PromoDuplicateEmail $error) { exit(2); }
                catch (Throwable $error) { exit(1); }
            }
            $children[] = $pid;
        }
        $issued = 0; $duplicates = 0;
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $exit = pcntl_wexitstatus($status);
            if ($exit === 0) $issued++;
            elseif ($exit === 2) $duplicates++;
            else throw new RuntimeException('parallel duplicate child failed');
        }
        $verify = testDb('sqlite:' . $samePath);
        check($issued === 1 && $duplicates === 7, 'parallel email was not deduplicated');
        check((int)$verify->query('SELECT COUNT(*) FROM oktoberfest_promos')->fetchColumn() === 1, 'parallel email made multiple rows');
        check((int)$verify->query('SELECT last_sequence FROM oktoberfest_campaign_counters')->fetchColumn() === 1, 'parallel email incremented counter twice');
        echo "PASS parallel same email: 1 issued, 7 rejected\n";
    } finally { @unlink($samePath); }
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . "\n");
    exit(1);
}
