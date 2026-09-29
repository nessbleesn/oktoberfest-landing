<?php
declare(strict_types=1);
// Local PHP built-in server only. Never route production traffic with this file.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/oktoberfest-api/promo') { require __DIR__ . '/promo.php'; return true; }
if ($path === '/oktoberfest-api/cashier') { require __DIR__ . '/cashier.php'; return true; }
return false;
