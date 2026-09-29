<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
$lock = fopen(sys_get_temp_dir() . '/oktoberfest-bitrix-retry.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "retry_busy\n"); exit(1); }

try {
    $store = new PromoStore(promo_db());
    $client = new BitrixLeadClient();
    $ok = 0; $failed = 0;
    foreach ($store->pendingBitrix(50) as $promo) {
        $result = promo_bitrix_sync($store, $client, $promo);
        if ($result['confirmed']) $ok++;
        else $failed++;
    }
    echo "synced={$ok} pending={$failed}\n";
    exit($failed ? 1 : 0);
} catch (Throwable $error) {
    error_log('oktoberfest_bitrix_retry_failed class=' . get_class($error));
    fwrite(STDERR, "retry_failed\n");
    exit(1);
}
