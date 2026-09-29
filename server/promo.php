<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function promo_reply(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') promo_reply(405, ['success' => false, 'error' => 'method_not_allowed']);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]) promo_reply(403, ['success' => false, 'error' => 'origin_denied']);
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) promo_reply(415, ['success' => false, 'error' => 'json_required']);
$body = file_get_contents('php://input', false, null, 0, 8193);
if ($body === false || strlen($body) > 8192) promo_reply(413, ['success' => false, 'error' => 'too_large']);
$input = json_decode($body, true);
if (!is_array($input) || array_is_list($input)) promo_reply(400, ['success' => false, 'error' => 'invalid_json']);
if (!empty($input['company'])) promo_reply(400, ['success' => false, 'error' => 'invalid_request']);
if (isset($input['promo_code']) || isset($input['sequence_number'])) promo_reply(400, ['success' => false, 'error' => 'server_issued_only']);

try {
    $db = promo_db();
    if (!promo_rate_limit($db, (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) promo_reply(429, ['success' => false, 'error' => 'rate_limited']);
    $store = new PromoStore($db);
    $promo = $store->issue($input);
    $delivered = promo_sync($store, new UniSenderClient(), $promo);
    $bitrix = ['confirmed' => false, 'new_lead' => false];
    try { $bitrix = promo_bitrix_sync($store, new BitrixLeadClient(), $promo); }
    catch (Throwable $error) { error_log('oktoberfest_bitrix_config_failed class=' . get_class($error)); }
    promo_reply(200, [
        'success' => true, 'promo_code' => $promo['promo_code'],
        'delivery_pending' => !$delivered,
        'bitrix_success' => $bitrix['confirmed'],
        'bitrix_new_lead' => $bitrix['new_lead']
    ]);
} catch (PromoInputError $error) {
    promo_reply(422, ['success' => false, 'error' => $error->getMessage()]);
} catch (PromoIdentityConflict $error) {
    promo_reply(409, ['success' => false, 'error' => 'contact_conflict']);
} catch (Throwable $error) {
    error_log('oktoberfest_promo_request_failed class=' . get_class($error));
    promo_reply(503, ['success' => false, 'error' => 'temporarily_unavailable']);
}
