<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_once __DIR__ . '/barkod-satis-lib.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Geçersiz istek.');
    }
    if (!can_manage_store_sales()) {
        throw new RuntimeException('Mağaza satış yetkisi gerekiyor.');
    }
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        throw new RuntimeException('Oturum doğrulaması yenilenmeli. Sayfayı yenileyip tekrar deneyin.');
    }

    pos_db_ensure();
    $amount = round(decimal_from_input($_POST['amount'] ?? '0'), 2);
    if ($amount <= 0) throw new RuntimeException('Muhtelif satış tutarı sıfırdan büyük olmalı.');
    if ($amount > 1000000) throw new RuntimeException('Muhtelif satış tutarı çok yüksek. Tutarı kontrol edin.');

    $cents = (int)round($amount * 100);
    $barcode = 'MUHTELIF-' . $cents;
    $variant = number_format($amount, 2, ',', '.') . ' TL';
    $pdo = db();

    $stmt = $pdo->prepare("SELECT id FROM pos_products WHERE barcode=? LIMIT 1");
    $stmt->execute([$barcode]);
    $productId = (int)($stmt->fetchColumn() ?: 0);
    $now = now();
    $userId = current_user()['id'] ?? null;

    if ($productId > 0) {
        $pdo->prepare("UPDATE pos_products SET name='Muhtelif Satış',variant_name=?,sale_price=?,vat_rate=10,stock_quantity=0,track_stock=0,is_active=1,product_source='pos',updated_at=? WHERE id=?")
            ->execute([$variant,$amount,$now,$productId]);
    } else {
        $pdo->prepare("INSERT INTO pos_products (barcode,name,variant_name,unit,sale_price,vat_rate,stock_quantity,track_stock,is_active,product_source,created_by,created_at,updated_at) VALUES (?,'Muhtelif Satış',?,'Adet',?,10,0,0,1,'pos',?,?,?)")
            ->execute([$barcode,$variant,$amount,$userId,$now,$now]);
        $productId = (int)$pdo->lastInsertId();
    }

    audit_action('pos_product', $productId, 'muhtelif_satis_hazirlandi', null, [
        'barcode'=>$barcode,
        'name'=>'Muhtelif Satış',
        'amount'=>$amount,
        'track_stock'=>0,
    ], 'Muhtelif Satış');

    echo json_encode([
        'ok'=>true,
        'barcode'=>$barcode,
        'amount'=>$amount,
        'message'=>'Muhtelif satış sepete eklenmeye hazır.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
