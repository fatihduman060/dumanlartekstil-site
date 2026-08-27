<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_once __DIR__ . '/barkod-satis-lib.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function pos_price_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pos_price_display_name(array $product): string
{
    $name = trim((string)($product['name'] ?? ''));
    $variant = trim((string)($product['variant_name'] ?? ''));
    return $variant !== '' ? $name . ' - ' . $variant : $name;
}

try {
    pos_db_ensure();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $barcode = preg_replace('/\s+/', '', trim((string)($_GET['barcode'] ?? '')));
        if ($barcode === '') throw new RuntimeException('Barkod girilmedi.');
        $product = pos_product_by_barcode($barcode);
        if (!$product) throw new RuntimeException('Bu barkod Barkodlu Satış ürünlerinde bulunamadı.');
        pos_price_json([
            'ok' => true,
            'product' => [
                'id' => (int)$product['id'],
                'barcode' => (string)$product['barcode'],
                'name' => pos_price_display_name($product),
                'sale_price' => (float)$product['sale_price'],
                'stock_quantity' => (float)$product['stock_quantity'],
            ],
        ]);
    }

    if (!can_manage_store_sales()) throw new RuntimeException('Bu işlem için mağaza satış yetkisi gerekiyor.');
    if (!verify_csrf($_POST['csrf_token'] ?? null)) throw new RuntimeException('Oturum doğrulaması yenilenmeli. Sayfayı yenileyin.');

    $productId = (int)($_POST['product_id'] ?? 0);
    $newPrice = max(0, decimal_from_input($_POST['sale_price'] ?? 0));
    if ($productId <= 0) throw new RuntimeException('Ürün seçilmedi. Barkodu tekrar okutun.');
    if ($newPrice <= 0) throw new RuntimeException('Yeni satış fiyatı sıfırdan büyük olmalıdır.');

    $stmt = db()->prepare("SELECT * FROM pos_products WHERE id=? AND is_active=1 AND COALESCE(product_source,'pos')='pos' LIMIT 1");
    $stmt->execute([$productId]);
    $old = $stmt->fetch();
    if (!$old) throw new RuntimeException('Ürün artık aktif değil veya Barkodlu Satış ürününe ait değil.');

    $oldPrice = (float)$old['sale_price'];
    $newPrice = round($newPrice, 2);
    if (abs($oldPrice - $newPrice) < 0.001) {
        pos_price_json([
            'ok' => true,
            'message' => 'Fiyat zaten bu tutarda.',
            'product_id' => $productId,
            'sale_price' => $newPrice,
        ]);
    }

    db()->prepare('UPDATE pos_products SET sale_price=?, updated_at=? WHERE id=?')
        ->execute([$newPrice, now(), $productId]);

    $label = pos_price_display_name($old);
    audit_action('pos_product', $productId, 'hizli_fiyat_guncellendi', [
        'sale_price' => $oldPrice,
    ], [
        'sale_price' => $newPrice,
    ], $label);
    log_action('Barkodlu satış hızlı fiyat güncellendi', $label . ' · ' . money($oldPrice) . ' → ' . money($newPrice));

    pos_price_json([
        'ok' => true,
        'message' => $label . ' fiyatı güncellendi.',
        'product_id' => $productId,
        'sale_price' => $newPrice,
    ]);
} catch (Throwable $e) {
    pos_price_json(['ok' => false, 'error' => $e->getMessage()], 422);
}
