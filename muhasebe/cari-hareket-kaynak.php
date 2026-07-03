<?php
require_once __DIR__ . '/teklif-db.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function chk_money($amount): string { return number_format((float)$amount, 2, ',', '.'); }
function chk_qty($amount): string {
    $n = (float)$amount;
    return abs($n - round($n)) < 0.00001 ? number_format($n, 0, ',', '.') : number_format($n, 2, ',', '.');
}

try {
    teklif_db_ensure();
    try {
        ensure_column(db(), 'offers', 'posted_to_cari', 'INTEGER NOT NULL DEFAULT 0');
        ensure_column(db(), 'offers', 'cari_movement_id', 'INTEGER');
        ensure_column(db(), 'offers', 'posted_at', 'TEXT');
        ensure_column(db(), 'offers', 'posted_by', 'INTEGER');
    } catch (Throwable $e) {}

    $movementId = (int)($_GET['movement_id'] ?? 0);
    if ($movementId <= 0) throw new RuntimeException('Hareket seçilmedi.');

    $stmt = db()->prepare('SELECT * FROM movements WHERE id=? AND COALESCE(is_cancelled,0)=0');
    $stmt->execute([$movementId]);
    $movement = $stmt->fetch();
    if (!$movement) throw new RuntimeException('Hareket bulunamadı.');

    $offer = null;
    try {
        $stmt = db()->prepare('SELECT * FROM offers WHERE cari_movement_id=? AND COALESCE(is_deleted,0)=0 LIMIT 1');
        $stmt->execute([$movementId]);
        $offer = $stmt->fetch() ?: null;
    } catch (Throwable $e) {}

    if (!$offer) {
        $desc = (string)($movement['description'] ?? '');
        if (preg_match('/no\s*:\s*([0-9]+)/iu', $desc, $m)) {
            $offerNo = $m[1];
            $stmt = db()->prepare('SELECT * FROM offers WHERE offer_no=? AND cari_id=? AND COALESCE(is_deleted,0)=0 ORDER BY id DESC LIMIT 1');
            $stmt->execute([$offerNo, (int)($movement['cari_id'] ?? 0)]);
            $offer = $stmt->fetch() ?: null;
        }
    }

    if (!$offer) throw new RuntimeException('Bu hareket için bağlı teklif/sipariş fişi bulunamadı.');

    $itemsStmt = db()->prepare('SELECT * FROM offer_items WHERE offer_id=? ORDER BY sort_order ASC, id ASC');
    $itemsStmt->execute([(int)$offer['id']]);
    $items = [];
    foreach ($itemsStmt->fetchAll() as $item) {
        $name = trim((string)($item['product_name'] ?? ''));
        $ptype = trim((string)($item['product_type'] ?? ''));
        $qty = (float)($item['quantity'] ?? 0);
        $price = (float)($item['unit_price'] ?? 0);
        $line = (float)($item['line_total'] ?? 0);
        if ($name === '' && $ptype === '' && $qty <= 0 && $price <= 0 && $line <= 0) continue;
        $items[] = [
            'product_name' => $name,
            'product_type' => $ptype,
            'quantity' => $qty,
            'quantity_text' => chk_qty($qty),
            'unit_price' => $price,
            'unit_price_text' => chk_money($price),
            'line_total' => $line,
            'line_total_text' => chk_money($line),
        ];
    }

    echo json_encode([
        'ok' => true,
        'type' => 'offer',
        'offer' => [
            'id' => (int)$offer['id'],
            'offer_no' => (string)($offer['offer_no'] ?? ''),
            'document_title' => (string)($offer['document_title'] ?? 'TEKLİF FORMU'),
            'offer_date' => tr_date($offer['offer_date'] ?? ''),
            'customer_name' => (string)($offer['customer_name'] ?? ''),
            'currency' => (string)($offer['currency'] ?? 'TL'),
            'quantity_label' => (string)($offer['quantity_label'] ?? 'DZ'),
            'subtotal_text' => chk_money($offer['subtotal'] ?? 0),
            'vat_enabled' => (int)($offer['vat_enabled'] ?? 0),
            'vat_rate' => (float)($offer['vat_rate'] ?? 0),
            'vat_amount_text' => chk_money($offer['vat_amount'] ?? 0),
            'grand_total_text' => chk_money($offer['grand_total'] ?? 0),
            'note' => (string)($offer['note'] ?? ''),
            'pdf_url' => 'teklif-yazdir.php?id=' . (int)$offer['id'],
            'edit_url' => 'teklif-ver.php?edit=' . (int)$offer['id'],
        ],
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
