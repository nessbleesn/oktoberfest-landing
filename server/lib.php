<?php
declare(strict_types=1);

const PROMO_CAMPAIGN = 'oktoberfest';

final class PromoInputError extends RuntimeException {}
final class PromoIdentityConflict extends RuntimeException {}

function promo_db(): PDO {
    $dsn = getenv('OKTOBERFEST_DB_DSN');
    if ($dsn === false || $dsn === '') {
        // Reuse the existing Moguta database; do not copy its credentials.
        $path = getenv('OKTOBERFEST_MOGUTA_CONFIG') ?: '/var/www/parkskazka.ru/config.ini';
        if (!is_file($path)) throw new RuntimeException('Database is not configured');
        $config = parse_ini_file($path);
        if (!is_array($config)) throw new RuntimeException('Database is not configured');
        $dsn = 'mysql:host=' . $config['HOST'] . ';dbname=' . $config['NAME_BD'] . ';charset=utf8mb4';
        $user = (string)$config['USER'];
        $password = (string)$config['PASSWORD'];
    } else {
        $user = getenv('OKTOBERFEST_DB_USER') ?: '';
        $password = getenv('OKTOBERFEST_DB_PASSWORD') ?: '';
    }
    $db = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') $db->exec('PRAGMA busy_timeout = 10000');
    return $db;
}

function promo_email(string $value): string {
    $email = strtolower((string)preg_replace('/\s+/u', '', $value));
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new PromoInputError('Укажите корректную почту');
    return $email;
}

function promo_phone(string $value): ?string {
    $value = trim($value);
    if ($value === '') return null;
    $digits = preg_replace('/\D+/', '', $value);
    if (strlen($digits) === 10) $digits = '7' . $digits;
    if (strlen($digits) === 11 && $digits[0] === '8') $digits = '7' . substr($digits, 1);
    if (strlen($digits) < 11 || strlen($digits) > 15 || $digits[0] === '0') throw new PromoInputError('Укажите телефон в международном формате');
    return '+' . $digits;
}

function promo_name(string $value): string {
    $name = trim((string)preg_replace('/\s+/u', ' ', $value));
    if (mb_strlen($name) > 80) throw new PromoInputError('Имя слишком длинное');
    return $name;
}

final class PromoStore {
    public function __construct(private PDO $db) {}

    public function issue(array $input): array {
        foreach (['email', 'phone', 'name'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) throw new PromoInputError('Некорректные данные формы');
        }
        if (isset($input['marketing_consent']) && !is_bool($input['marketing_consent'])) throw new PromoInputError('Некорректные данные формы');
        $email = promo_email($input['email'] ?? '');
        $phone = promo_phone($input['phone'] ?? '');
        $name = promo_name($input['name'] ?? '');
        if (($input['personal_consent'] ?? null) !== true) throw new PromoInputError('Требуется согласие на обработку данных');
        $marketing = ($input['marketing_consent'] ?? false) === true ? 1 : 0;
        $key = $input['idempotency_key'] ?? null;
        if ($key !== null && (!is_string($key) || !preg_match('/\A[A-Za-z0-9_-]{8,128}\z/', $key))) throw new PromoInputError('Некорректный ключ заявки');
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $transactionOpen = false;
            try {
                if ($driver === 'sqlite') $this->db->exec('BEGIN IMMEDIATE');
                else $this->db->beginTransaction();
                $transactionOpen = true;
                $lock = $this->db->prepare('SELECT last_sequence FROM oktoberfest_campaign_counters WHERE campaign_key = ?' . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
                $lock->execute([PROMO_CAMPAIGN]);
                if ($lock->fetchColumn() === false) throw new RuntimeException('Campaign counter is not initialized');

                $find = $this->db->prepare('SELECT * FROM oktoberfest_promos WHERE campaign_key = :campaign AND (email_normalized = :email OR (:phone IS NOT NULL AND phone_normalized = :phone_match))' . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
                $find->execute(['campaign' => PROMO_CAMPAIGN, 'email' => $email, 'phone' => $phone, 'phone_match' => $phone]);
                $matches = $find->fetchAll();
                if (count($matches) > 1) throw new PromoIdentityConflict('Контактные данные относятся к разным заявкам');
                if ($matches) {
                    $row = $matches[0];
                    // A phone-matched request with a different email must not silently retarget delivery.
                    $newName = $name !== '' ? $name : $row['name'];
                    $newPhone = $row['email_normalized'] === $email && $phone !== null ? $phone : $row['phone_normalized'];
                    $changed = $newName !== $row['name'] || $newPhone !== $row['phone_normalized'];
                    if ($changed) {
                        $update = $this->db->prepare("UPDATE oktoberfest_promos SET name=?, phone=?, phone_normalized=?, sync_status='pending', sync_version=sync_version+1, synced_at=NULL WHERE id=?");
                        $update->execute([$newName, $newPhone, $newPhone, $row['id']]);
                        $row['name'] = $newName;
                        $row['phone'] = $newPhone;
                        $row['phone_normalized'] = $newPhone;
                        $row['sync_status'] = 'pending';
                        $row['sync_version'] = (int)$row['sync_version'] + 1;
                    }
                    if ($driver === 'sqlite') $this->db->exec('COMMIT');
                    else $this->db->commit();
                    $transactionOpen = false;
                    return $row;
                }

                $this->db->prepare('UPDATE oktoberfest_campaign_counters SET last_sequence = last_sequence + 1 WHERE campaign_key = ?')->execute([PROMO_CAMPAIGN]);
                $seq = (int)$this->db->query("SELECT last_sequence FROM oktoberfest_campaign_counters WHERE campaign_key = 'oktoberfest'")->fetchColumn();
                $code = 'EDA' . $seq;
                $id = bin2hex(random_bytes(16));
                $insert = $this->db->prepare("INSERT INTO oktoberfest_promos (campaign_key, sequence_number, promo_code, name, email, email_normalized, phone, phone_normalized, application_id, idempotency_key, marketing_consent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insert->execute([PROMO_CAMPAIGN, $seq, $code, $name, $email, $email, $phone, $phone, $id, $key, $marketing]);
                $promoId = (int)$this->db->lastInsertId();
                if ($driver === 'sqlite') $this->db->exec('COMMIT');
                else $this->db->commit();
                $transactionOpen = false;
                return ['id' => $promoId, 'campaign_key' => PROMO_CAMPAIGN, 'sequence_number' => $seq, 'promo_code' => $code, 'name' => $name, 'email' => $email, 'phone' => $phone, 'phone_normalized' => $phone, 'application_id' => $id, 'marketing_consent' => $marketing, 'sync_status' => 'pending', 'sync_version' => 0, 'bitrix_status' => 'pending'];
            } catch (Throwable $error) {
                if ($transactionOpen) {
                    if ($driver === 'sqlite') $this->db->exec('ROLLBACK');
                    else $this->db->rollBack();
                }
                if ($error instanceof PDOException && $attempt < 2 && in_array((string)$error->getCode(), ['23000', '40001', 'HY000'], true)) continue;
                throw $error;
            }
        }
        throw new RuntimeException('Could not issue promo code');
    }

    public function findCode(string $code): ?array {
        $q = $this->db->prepare('SELECT id,promo_code,name,phone_normalized,status,used_at FROM oktoberfest_promos WHERE campaign_key=? AND promo_code=?');
        $q->execute([PROMO_CAMPAIGN, strtoupper(trim($code))]);
        return $q->fetch() ?: null;
    }

    public function redeem(string $code): string {
        $code = strtoupper(trim($code));
        if (!preg_match('/\AEDA[1-9][0-9]*\z/', $code)) return 'not_found';
        $q = $this->db->prepare("UPDATE oktoberfest_promos SET status='used', used_at=CURRENT_TIMESTAMP WHERE campaign_key=? AND promo_code=? AND status='issued'");
        $q->execute([PROMO_CAMPAIGN, $code]);
        if ($q->rowCount() === 1) return 'redeemed';
        $row = $this->findCode($code);
        return $row ? 'already_used' : 'not_found';
    }

    public function markSync(int $id, int $version, bool $ok): bool {
        $sql = $ok
            ? "UPDATE oktoberfest_promos SET sync_status='synced', synced_at=CURRENT_TIMESTAMP, sync_attempts=sync_attempts+1, last_sync_attempt_at=CURRENT_TIMESTAMP WHERE id=? AND sync_version=?"
            : "UPDATE oktoberfest_promos SET sync_status='pending', sync_attempts=sync_attempts+1, last_sync_attempt_at=CURRENT_TIMESTAMP WHERE id=? AND sync_version=?";
        $q = $this->db->prepare($sql);
        $q->execute([$id, $version]);
        return $q->rowCount() === 1;
    }

    public function pending(int $limit = 50): array {
        $q = $this->db->prepare("SELECT id,email,phone,name,promo_code,marketing_consent,sync_version FROM oktoberfest_promos WHERE sync_status='pending' ORDER BY id LIMIT ?");
        $q->bindValue(1, $limit, PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll();
    }

    public function claimBitrix(int $id): bool {
        $stale = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'CURRENT_TIMESTAMP - INTERVAL 10 MINUTE' : "datetime('now', '-10 minutes')";
        $q = $this->db->prepare("UPDATE oktoberfest_promos SET bitrix_status='sending', bitrix_claimed_at=CURRENT_TIMESTAMP, bitrix_attempts=bitrix_attempts+1, last_bitrix_attempt_at=CURRENT_TIMESTAMP WHERE id=? AND campaign_key=? AND (bitrix_status='pending' OR (bitrix_status='sending' AND bitrix_claimed_at < {$stale}))");
        $q->execute([$id, PROMO_CAMPAIGN]);
        return $q->rowCount() === 1;
    }

    public function finishBitrix(int $id, ?int $leadId): void {
        $q = $this->db->prepare($leadId !== null
            ? "UPDATE oktoberfest_promos SET bitrix_status='synced', bitrix_lead_id=?, bitrix_claimed_at=NULL WHERE id=? AND bitrix_status='sending'"
            : "UPDATE oktoberfest_promos SET bitrix_status='pending', bitrix_claimed_at=NULL WHERE id=? AND bitrix_status='sending'");
        $q->execute($leadId !== null ? [$leadId, $id] : [$id]);
    }

    public function pendingBitrix(int $limit = 50): array {
        $stale = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'CURRENT_TIMESTAMP - INTERVAL 10 MINUTE' : "datetime('now', '-10 minutes')";
        $q = $this->db->prepare("SELECT id,name,email,phone,promo_code,application_id,bitrix_status FROM oktoberfest_promos WHERE campaign_key=? AND (bitrix_status='pending' OR (bitrix_status='sending' AND bitrix_claimed_at < {$stale})) ORDER BY id LIMIT ?");
        $q->bindValue(1, PROMO_CAMPAIGN);
        $q->bindValue(2, $limit, PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll();
    }
}

interface PromoContactClient { public function sync(array $promo): bool; }

final class UniSenderClient implements PromoContactClient {
    public static function contactFields(array $promo): array {
        $fields = ['email' => $promo['email'], 'Name' => $promo['name'], 'promo_code' => $promo['promo_code']];
        if (!empty($promo['phone'])) $fields['phone'] = $promo['phone'];
        return $fields;
    }

    public static function contactTags(array $promo): string {
        return !empty($promo['marketing_consent']) ? 'oktoberfest-2026,marketing-consent' : 'oktoberfest-2026';
    }

    public function sync(array $promo): bool {
        $key = getenv('UNISENDER_API_KEY');
        $list = getenv('UNISENDER_LIST_ID');
        if (!$key || !$list || !ctype_digit((string)$list)) return false;
        $post = http_build_query(['api_key' => $key, 'list_ids' => $list, 'fields' => self::contactFields($promo), 'tags' => self::contactTags($promo), 'double_optin' => 3, 'overwrite' => 2], '', '&', PHP_QUERY_RFC3986);
        $ch = curl_init('https://api.unisender.com/ru/api/subscribe?format=json');
        if ($ch === false) return false;
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12, CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'], CURLOPT_FOLLOWLOCATION => false]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $status !== 200) return false;
        $response = json_decode($body, true);
        return is_array($response) && isset($response['result']['person_id']) && !isset($response['error']);
    }
}

function promo_sync(PromoStore $store, PromoContactClient $client, array $promo): bool {
    if (($promo['sync_status'] ?? 'pending') === 'synced') return true;
    try { $ok = $client->sync($promo); }
    catch (Throwable $error) { $ok = false; }
    $current = $store->markSync((int)$promo['id'], (int)($promo['sync_version'] ?? 0), $ok);
    if (!$ok) error_log('oktoberfest_unisender_sync_failed promo_id=' . (int)$promo['id']);
    return $ok && $current;
}

interface PromoLeadClient {
    /** Returns an existing lead id, or null when not found. */
    public function find(string $applicationId): ?int;
    public function create(array $promo): ?int;
}

final class BitrixLeadClient implements PromoLeadClient {
    private string $webhook;

    public function __construct() {
        $url = getenv('BITRIX24_WEBHOOK_URL') ?: '';
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'portal.parkskazka.com'
            || !preg_match('~\A/rest/[0-9]+/[A-Za-z0-9._-]+/?\z~', $parts['path'] ?? '')
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Bitrix24 webhook is not configured');
        }
        $this->webhook = rtrim($url, '/') . '/';
    }

    private function call(string $method, array $data): ?array {
        $ch = curl_init($this->webhook . $method . '.json');
        if ($ch === false) return null;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $status !== 200) return null;
        $result = json_decode($body, true);
        return is_array($result) && !isset($result['error']) ? $result : null;
    }

    public function find(string $applicationId): ?int {
        $result = $this->call('crm.lead.list', [
            'filter' => ['=ORIGINATOR_ID' => PROMO_CAMPAIGN, '=ORIGIN_ID' => $applicationId],
            'select' => ['ID'], 'start' => 0
        ]);
        if ($result === null || !isset($result['result']) || !is_array($result['result'])) {
            throw new RuntimeException('Bitrix24 lookup unavailable');
        }
        $id = $result['result'][0]['ID'] ?? null;
        return is_numeric($id) && (int)$id > 0 ? (int)$id : null;
    }

    public static function leadFields(array $promo): array {
        $fields = [
            'TITLE' => 'Октоберфест — купон 10% на еду',
            'STATUS_ID' => 'NEW', 'SOURCE_ID' => 'WEB',
            'SOURCE_DESCRIPTION' => 'parkskazka.ru/oktoberfest/',
            'OPENED' => 'Y', 'ORIGINATOR_ID' => PROMO_CAMPAIGN,
            'ORIGIN_ID' => $promo['application_id'],
            'NAME' => $promo['name'],
            'EMAIL' => [['VALUE' => $promo['email'], 'VALUE_TYPE' => 'WORK']],
            'COMMENTS' => 'Промокод: ' . $promo['promo_code'] . '; скидка 10% на еду.'
        ];
        if (!empty($promo['phone'])) $fields['PHONE'] = [['VALUE' => $promo['phone'], 'VALUE_TYPE' => 'WORK']];
        return $fields;
    }

    public function create(array $promo): ?int {
        $result = $this->call('crm.lead.add', ['fields' => self::leadFields($promo), 'params' => ['REGISTER_SONET_EVENT' => 'Y']]);
        $id = $result['result'] ?? null;
        return is_numeric($id) && (int)$id > 0 ? (int)$id : null;
    }
}

/** confirmed/new_lead are true only on this request's first successful CRM confirmation. */
function promo_bitrix_sync(PromoStore $store, PromoLeadClient $client, array $promo): array {
    if (($promo['bitrix_status'] ?? 'pending') === 'synced') return ['confirmed' => true, 'new_lead' => false];
    $id = (int)$promo['id'];
    if (!$store->claimBitrix($id)) return ['confirmed' => false, 'new_lead' => false];
    try {
        // Lookup first also recovers a lead created just before an ambiguous timeout.
        $leadId = $client->find($promo['application_id']);
        if ($leadId === null) $leadId = $client->create($promo);
        if ($leadId === null) throw new RuntimeException('Bitrix24 lead creation failed');
        $store->finishBitrix($id, $leadId);
        return ['confirmed' => true, 'new_lead' => true];
    } catch (Throwable $error) {
        $store->finishBitrix($id, null);
        error_log('oktoberfest_bitrix_sync_failed promo_id=' . $id . ' class=' . get_class($error));
        return ['confirmed' => false, 'new_lead' => false];
    }
}

function promo_rate_limit(PDO $db, string $ip): bool {
    $secret = getenv('OKTOBERFEST_RATE_LIMIT_SECRET');
    if (!$secret || strlen($secret) < 32) throw new RuntimeException('Rate limit secret is not configured');
    $hash = hash_hmac('sha256', $ip, $secret);
    $start = gmdate('Y-m-d H:00:00');
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $q = $db->prepare('INSERT INTO oktoberfest_request_limits (key_hash,window_start,request_count) VALUES (?,?,1) ON DUPLICATE KEY UPDATE request_count=request_count+1');
    } else {
        $q = $db->prepare('INSERT INTO oktoberfest_request_limits (key_hash,window_start,request_count) VALUES (?,?,1) ON CONFLICT(key_hash,window_start) DO UPDATE SET request_count=request_count+1');
    }
    $q->execute([$hash, $start]);
    $read = $db->prepare('SELECT request_count FROM oktoberfest_request_limits WHERE key_hash=? AND window_start=?');
    $read->execute([$hash, $start]);
    return (int)$read->fetchColumn() <= 10;
}
