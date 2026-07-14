<?php
require_once __DIR__ . '/teklif-db.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function chk_money($amount): string { return number_format((float)$amount, 2, ',', '.'); }
function chk_qty($amount): string {
    $n = (float)$amount;
    return abs($n - round($n)) < 0.00001 ? number_format($n, 0, ',', '.') : number_format($n, 2, ',', '.');
}
function chk_invoice_short_no(string $invoiceNo, int $invoiceId): string {
    $compact = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($invoiceNo))) ?: '';
    if ($compact !== '' && preg_match('/^[A-Z]{2,8}20\d{2}(\d+)$/', $compact, $m)) {
        $serial = ltrim((string)$m[1], '0');
        return $serial !== '' ? $serial : '0';
    }
    if ($compact !== '' && preg_match('/(\d+)$/', $compact, $m)) {
        $serial = ltrim((string)$m[1], '0');
        return $serial !== '' ? $serial : '0';
    }
    return (string)$invoiceId;
}

try {
    teklif_db_ensure();
    try {
        ensure_column(db(), 'offers', 'posted_to_cari', 'INTEGER NOT NULL DEFAULT 0');
        ensure_column(db(), 'offers', 'cari_movement_id', 'INTEGER');
        ensure_column(db(), 'offers', 'posted_at', 'TEXT');
        ensure_column(db(), 'offers', 'posted_by', 'INTEGER');
        ensure_column(db(), 'offers', 'discount_enabled', 'INTEGER NOT NULL DEFAULT 0');
        ensure_column(db(), 'offers', 'discount_rate', 'REAL NOT NULL DEFAULT 0');
        ensure_column(db(), 'offers', 'discount_amount', 'REAL NOT NULL DEFAULT 0');
        ensure_column(db(), 'offer_items', 'product_barcode', 'TEXT');
    } catch (Throwable $e) {}

    $movementId = (int)($_GET['movement_id'] ?? 0);
    if ($movementId <= 0) throw new RuntimeException('Hareket seçilmedi.');

    $stmt = db()->prepare('SELECT * FROM movements WHERE id=? AND COALESCE(is_cancelled,0)=0');
    $stmt->execute([$movementId]);
    $movement = $stmt->fetch();
    if (!$movement) throw new RuntimeException('Hareket bulunamadı.');

    // Önce bağlı faturayı ara. Fatura hareketleri teklif/sipariş fişlerinden bağımsızdır.
    $invoice = null;
    try {
        $tableExists = (int)db()->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='invoices'")->fetchColumn() > 0;
        if ($tableExists) {
            $stmt = db()->prepare("SELECT i.*, COALESCE(c.name,'') AS cari_name
                FROM invoices i
                LEFT JOIN cariler c ON c.id=i.cari_id
                WHERE i.cari_movement_id=? AND COALESCE(i.is_cancelled,0)=0
                ORDER BY i.id DESC LIMIT 1");
            $stmt->execute([$movementId]);
            $invoice = $stmt->fetch() ?: null;
        }
    } catch (Throwable $e) {
        $invoice = null;
    }

    if ($invoice) {
        $invoiceId = (int)$invoice['id'];
        $invoiceNo = trim((string)($invoice['invoice_no'] ?? ''));
        $currency = trim((string)($invoice['currency'] ?? 'TL')) ?: 'TL';
        echo json_encode([
            'ok' => true,
            'type' => 'invoice',
            'invoice' => [
                'id' => $invoiceId,
                'invoice_no' => $invoiceNo,
                'short_no' => chk_invoice_short_no($invoiceNo, $invoiceId),
                'direction' => (string)($invoice['direction'] ?? 'giden'),
                'direction_label' => (string)($invoice['direction'] ?? '') === 'gelen' ? 'Gelen fatura' : 'Giden fatura',
                'invoice_date' => tr_date($invoice['invoice_date'] ?? ''),
                'due_date' => tr_date($invoice['due_date'] ?? ''),
                'cari_name' => (string)($invoice['cari_name'] ?? ''),
                'currency' => $currency,
                'subtotal_text' => chk_money($invoice['subtotal'] ?? 0),
                'vat_text' => chk_money($invoice['vat_amount'] ?? 0),
                'total_text' => chk_money($invoice['total_amount'] ?? 0),
                'description' => (string)($invoice['description'] ?? ''),
                'document_name' => (string)($invoice['document_name'] ?? ''),
                'has_document' => !empty($invoice['document_path']),
                'document_url' => !empty($invoice['document_path']) ? 'fatura-indir.php?id=' . $invoiceId : '',
                'edit_url' => 'faturalar.php?edit=' . $invoiceId,
                'list_url' => 'faturalar.php?period=' . substr((string)($invoice['invoice_date'] ?? date('Y-m-d')), 0, 7),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

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

    if (!$offer) throw new RuntimeException('Bu hareket için bağlı fatura, teklif veya sipariş fişi bulunamadı.');

    $itemsStmt = db()->prepare('SELECT * FROM offer_items WHERE offer_id=? ORDER BY sort_order ASC, id ASC');
    $itemsStmt->execute([(int)$offer['id']]);
    $items = [];
    foreach ($itemsStmt->fetchAll() as $item) {
        $barcode = trim((string)($item['product_barcode'] ?? ''));
        $name = trim((string)($item['product_name'] ?? ''));
        $ptype = trim((string)($item['product_type'] ?? ''));
        $qty = (float)($item['quantity'] ?? 0);
        $price = (float)($item['unit_price'] ?? 0);
        $line = (float)($item['line_total'] ?? 0);
        if ($barcode === '' && $name === '' && $ptype === '' && $qty <= 0 && $price <= 0 && $line <= 0) continue;
        $items[] = [
            'product_barcode' => $barcode,
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
            'discount_enabled' => (int)($offer['discount_enabled'] ?? 0),
            'discount_rate' => (float)($offer['discount_rate'] ?? 0),
            'discount_amount_text' => chk_money($offer['discount_amount'] ?? 0),
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
