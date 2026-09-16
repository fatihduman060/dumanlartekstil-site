<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';

require_login();
require_write();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

// Bu dosya Bayrak Gross 19.09.2026 / 250.000 TL için geçmişte kullanılan
// tek seferlik veri onarımıydı. Onarım tamamlandıktan sonra aynı cari/tutar/vade
// ile yeniden girilen gerçek çekleri de hedefleyebildiği için kalıcı olarak
// devre dışı bırakılmıştır. Buradan artık hiçbir finansal kayıt değiştirilemez.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'Bu eski onarım artık devre dışı.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'Oturum doğrulaması yenilenmeli.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'ok' => true,
    'deleted' => false,
    'repaired' => false,
    'retired' => true,
    'message' => 'Bayrak Gross için eski otomatik onarım kapatıldı. Yeni veya mevcut çek kayıtlarına dokunulmadı.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
