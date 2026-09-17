<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Bu dosya yalnızca sunucu cron/CLI için çalışır.');
}
require_once __DIR__ . '/cari-haftalik-yedek-lib.php';

try {
    $path = cari_haftalik_auto_if_due();
    if ($path) {
        echo '[' . date('Y-m-d H:i:s') . '] Cari haftalık yedek hazır: ' . basename($path) . PHP_EOL;
    } else {
        echo '[' . date('Y-m-d H:i:s') . '] Henüz haftalık yedek zamanı değil.' . PHP_EOL;
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Cari haftalık yedek hatası: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
