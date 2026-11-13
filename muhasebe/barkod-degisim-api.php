<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_once __DIR__ . '/barkod-degisim-lib.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('Geçersiz istek.');
    if (!can_manage_store_sales()) throw new RuntimeException('Değişim için mağaza satış yetkisi gerekiyor.');
    if (!verify_csrf($_POST['csrf_token'] ?? null)) throw new RuntimeException('Oturum doğrulaması yenilenmeli. Sayfayı yenileyin.');
    if (($_POST['action'] ?? '') !== 'complete_exchange') throw new RuntimeException('Geçersiz işlem.');
    if (strlen((string)($_POST['returned_json'] ?? '')) > 100000 || strlen((string)($_POST['issued_json'] ?? '')) > 100000) throw new RuntimeException('Ürün listesi çok uzun.');
    $returned = json_decode((string)($_POST['returned_json'] ?? ''), true);
    $issued = json_decode((string)($_POST['issued_json'] ?? ''), true);
    if (!is_array($returned) || !is_array($issued)) throw new RuntimeException('Ürün listesi geçersiz.');
    $result = pos_exchange_save(
        $returned,
        $issued,
        trim((string)($_POST['payment_method'] ?? '')),
        trim((string)($_POST['source_token'] ?? ''))
    );
    echo json_encode([
        'ok'=>true,
        'message'=>!empty($result['duplicate_prevented']) ? 'Değişim zaten kaydedilmişti; ikinci kayıt oluşturulmadı.' : 'Değişim kaydedildi.',
        'receipt_no'=>$result['receipt_no'],
        'receipt_url'=>'barkod-fis.php?id=' . (int)$result['id'],
        'duplicate_prevented'=>!empty($result['duplicate_prevented']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
