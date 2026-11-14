<?php
// cPanel cron: bağlantı kesilen sepetleri, açık bir tarayıcı olmasa da arşivle.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/barkod-satis-lib.php';
pos_db_ensure();
pos_archive_stale_live_carts();
