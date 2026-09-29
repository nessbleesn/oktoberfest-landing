<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() !== 0)) exit(1);
$mode = $argv[1] ?? '';
if (!in_array($mode, ['unisender', 'bitrix'], true)) exit(1);

$secretsPath = '/etc/parkskazka/oktoberfest-fpm-secrets.conf';
$stat = @stat($secretsPath);
if ($stat === false || $stat['uid'] !== 0 || ($stat['mode'] & 077) !== 0) {
    fwrite(STDERR, "retry_secrets_unavailable\n");
    exit(1);
}
$lines = file($secretsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!is_array($lines)) exit(1);
$allowed = ['UNISENDER_API_KEY', 'UNISENDER_LIST_ID', 'BITRIX24_WEBHOOK_URL', 'OKTOBERFEST_RATE_LIMIT_SECRET'];
foreach ($lines as $line) {
    if (!preg_match('/\Aenv\[([A-Z0-9_]+)\] = (.+)\z/', $line, $match)
        || !in_array($match[1], $allowed, true)) exit(1);
    putenv($match[1] . '=' . $match[2]);
}
require __DIR__ . '/retry-' . $mode . '.php';
