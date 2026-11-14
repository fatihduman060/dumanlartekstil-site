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

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'log_exchange_remove') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 0);
        $role = trim((string)($_POST['exchange_role'] ?? ''));
        $eventSource = trim((string)($_POST['event_source'] ?? 'exchange_remove'));
        $reason = preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', (string)($_POST['removal_reason'] ?? '')) ?? '';
        if ($productId <= 0 || $quantity <= 0 || $quantity > 9999) throw new RuntimeException('Silinecek ürün bilgisi geçersiz.');
        if (!in_array($role, ['returned','issued'], true)) throw new RuntimeException('Değişim bölümü geçersiz.');
        if (!in_array($eventSource, ['exchange_remove','exchange_single_decrement','exchange_quantity_decrease'], true)) throw new RuntimeException('Değişim silme işlemi geçersiz.');
        if (preg_match_all('/./us', $reason) < 6) throw new RuntimeException('Silme nedeni en az 6 karakter olmalıdır.');

        pos_db_ensure();
        $stmt = db()->prepare("SELECT id,name,variant_name,barcode,sale_price FROM pos_products WHERE id=? AND is_active=1 AND COALESCE(product_source,'pos')='pos' LIMIT 1");
        $stmt->execute([$productId]);
        $product = $stmt->fetch() ?: null;
        if (!$product) throw new RuntimeException('Silinecek ürün bulunamadı.');

        $productName = pos_exchange_product_name($product);
        $unitPrice = round((float)$product['sale_price'], 2);
        $lineTotal = round($unitPrice * $quantity, 2);
        audit_action('pos_cart', 0, 'urun_cikarildi', null, [
            'event'=>'item_removed',
            'event_source'=>$eventSource,
            'exchange_role'=>$role,
            'removal_reason'=>$reason,
            'items'=>[[
                'product_id'=>$productId,
                'product_name'=>$productName,
                'name'=>$productName,
                'barcode'=>(string)$product['barcode'],
                'quantity'=>$quantity,
                'unit_price'=>$unitPrice,
                'line_total'=>$lineTotal,
            ]],
            'item_count'=>1,
            'cart_total'=>$lineTotal,
            'discount_amount'=>0,
            'payment_method'=>'exchange',
            'event_time'=>now(),
        ], 'Değişimden silinen ürün');

        echo json_encode([
            'ok'=>true,
            'message'=>'Ürün değişim sepetinden silindi ve nedeni kaydedildi.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action !== 'complete_exchange') throw new RuntimeException('Geçersiz işlem.');
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
