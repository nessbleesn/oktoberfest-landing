<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Referrer-Policy: no-referrer");
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'");
$hash = getenv('OKTOBERFEST_CASHIER_PASSWORD_HASH');
if (!$hash) { http_response_code(503); exit('Касса не настроена'); }
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Strict', 'path' => '/oktoberfest-api/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
if (!empty($_SESSION['cashier_auth']) && (int)($_SESSION['auth_at'] ?? 0) < time() - 28800) unset($_SESSION['cashier_auth']);

function cashier_escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$message = '';
$code = strtoupper(trim((string)($_POST['code'] ?? '')));
$row = null;
try {
    $db = promo_db();
    $store = new PromoStore($db);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $csrf = (string)($_POST['csrf'] ?? '');
        if (!hash_equals($_SESSION['csrf'], $csrf)) { http_response_code(403); exit('Неверный токен формы'); }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'login') {
            if (!promo_rate_limit($db, 'cashier:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) { http_response_code(429); exit('Слишком много попыток'); }
            if (password_verify((string)($_POST['password'] ?? ''), $hash)) {
                session_regenerate_id(true);
                $_SESSION['cashier_auth'] = true;
                $_SESSION['auth_at'] = time();
                $_SESSION['csrf'] = bin2hex(random_bytes(24));
            } else $message = 'Неверный пароль';
        } elseif ($action === 'logout') {
            unset($_SESSION['cashier_auth']);
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
        } elseif (!empty($_SESSION['cashier_auth']) && $action === 'redeem') {
            $result = $store->redeem($code);
            $message = ['redeemed' => 'Промокод погашен. Скидка 10% на еду.', 'already_used' => 'Уже использован', 'not_found' => 'Не найден'][$result];
        }
    }
    if (!empty($_SESSION['cashier_auth']) && preg_match('/\AEDA[1-9][0-9]*\z/', $code)) $row = $store->findCode($code);
} catch (Throwable $error) {
    error_log('oktoberfest_cashier_failed class=' . get_class($error));
    http_response_code(503);
    $message = 'Касса временно недоступна';
}
$loggedIn = !empty($_SESSION['cashier_auth']);
?><!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Проверка промокода · Сказка</title>
<style>body{font:18px/1.5 system-ui,sans-serif;background:#f7f4e9;color:#153d32;margin:0;padding:24px}main{max-width:560px;margin:7vh auto;padding:28px;border-radius:18px;background:#fff;box-shadow:0 8px 36px #1233}h1{font-size:1.5em}label{display:block;margin-top:18px}input{font:inherit;box-sizing:border-box;width:100%;padding:12px;border:1px solid #789;border-radius:8px}button{font:inherit;padding:12px 18px;margin-top:18px;border:0;border-radius:8px;background:#153d32;color:#fff;cursor:pointer}.muted{color:#52655e}form+form{margin-top:20px;border-top:1px solid #ddd}strong{font-size:1.2em}</style>
<main><h1>Проверка промокода</h1><?php if ($message !== ''): ?><p role="status"><strong><?= cashier_escape($message) ?></strong></p><?php endif; ?>
<?php if (!$loggedIn): ?><form method="post"><input type="hidden" name="csrf" value="<?= cashier_escape($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="login"><label>Пароль кассира<input name="password" type="password" autocomplete="current-password" required></label><button>Войти</button></form>
<?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?= cashier_escape($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="lookup"><label>Промокод<input name="code" value="<?= cashier_escape($code) ?>" inputmode="latin" pattern="EDA[1-9][0-9]*" maxlength="40" required></label><button>Проверить</button></form>
<?php if ($code !== ''): ?><?php if (!$row): ?><p><strong>Не найден</strong></p><?php else: ?>
<p><strong><?= $row['status'] === 'used' ? 'Уже использован' : 'Действителен' ?></strong></p>
<p>Владелец: <?= cashier_escape((string)($row['name'] ?: 'Не указано')) ?></p>
<?php if (getenv('OKTOBERFEST_CASHIER_SHOW_PHONE_LAST4') === '1'): ?><p>Телефон: <?= $row['phone_normalized'] ? '•••• ' . cashier_escape(substr((string)$row['phone_normalized'], -4)) : 'Не указан' ?></p><?php endif; ?>
<?php if ($row['status'] === 'issued'): ?><form method="post"><input type="hidden" name="csrf" value="<?= cashier_escape($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="redeem"><input type="hidden" name="code" value="<?= cashier_escape($row['promo_code']) ?>"><button>Погасить промокод</button></form><?php endif; ?><?php endif; ?><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= cashier_escape($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="logout"><button>Выйти</button></form><?php endif; ?>
</main></html>
