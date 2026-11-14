<?php

require_once __DIR__ . '/barkod-satis-lib.php';
require_once __DIR__ . '/magaza-veresiye-auto-only.php';

function pos_exchange_no(int $id, string $date): string
{
    return 'DGS-' . str_replace('-', '', $date) . '-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

function pos_exchange_product_name(array $product): string
{
    $name = trim((string)$product['name']);
    $variant = trim((string)($product['variant_name'] ?? ''));
    return $variant === '' ? $name : $name . ' - ' . $variant;
}

function pos_exchange_recent(int $limit = 20): array
{
    pos_db_ensure();
    $limit = max(1, min(100, $limit));
    return db()->query("SELECT id,receipt_no,sale_date,sale_time,grand_total,payment_method
        FROM pos_sales WHERE sale_type='exchange' AND is_cancelled=0
        ORDER BY id DESC LIMIT " . $limit)->fetchAll() ?: [];
}

function pos_exchange_rows(PDO $pdo, array $raw, string $role): array
{
    if (!$raw || count($raw) > 100) throw new RuntimeException('Değişim için ürün okutun (en çok 100 satır).');
    $stmt = $pdo->prepare("SELECT * FROM pos_products WHERE id=? AND is_active=1 AND COALESCE(product_source,'pos')='pos' LIMIT 1");
    $rows = [];
    foreach ($raw as $item) {
        if (!is_array($item)) throw new RuntimeException('Ürün bilgisi geçersiz.');
        $id = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || !$quantity || $id < 1 || $quantity < 1 || $quantity > 9999) {
            throw new RuntimeException('Ürün adedi 1 ile 9999 arasında tam sayı olmalı.');
        }
        if (isset($rows[$id])) throw new RuntimeException('Aynı ürün bir listede yalnızca bir kez bulunabilir.');
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        if (!$product) throw new RuntimeException('Ürün artık Barkodlu Satış ürün havuzunda aktif değil.');
        $price = round((float)$product['sale_price'], 2);
        if ($price <= 0) throw new RuntimeException('Fiyatı olmayan ürün değişime eklenemez.');
        $shownPrice = $item['unit_price'] ?? null;
        if ($shownPrice === null || abs(round((float)$shownPrice, 2) - $price) > 0.004) {
            throw new RuntimeException('Ürün fiyatı değişmiş. Değişime yeniden başlayıp ürünleri tekrar okutun.');
        }
        $rows[$id] = [
            'product'=>$product,
            'role'=>$role,
            'quantity'=>$quantity,
            'price'=>$price,
            'line_total'=>round($price * $quantity, 2),
        ];
    }
    return array_values($rows);
}

function pos_exchange_save(array $returned, array $issued, string $paymentMethod, string $sourceToken): array
{
    pos_db_ensure();
    if (!preg_match('/^[a-zA-Z0-9-]{20,100}$/', $sourceToken)) throw new RuntimeException('İşlem kimliği geçersiz. Sayfayı yenileyin.');
    $pdo = db();
    $userId = (int)(current_user()['id'] ?? 0) ?: null;
    $transactionStarted = false;
    $pdo->exec('BEGIN IMMEDIATE');
    $transactionStarted = true;
    try {
        $existing = $pdo->prepare("SELECT id,receipt_no,is_cancelled FROM pos_sales WHERE source_token=? AND created_by=? AND sale_type='exchange' LIMIT 1");
        $existing->execute([$sourceToken,$userId]);
        if ($prior = $existing->fetch()) {
            if ((int)$prior['is_cancelled'] === 1) throw new RuntimeException('Bu değişim daha önce kaydedilip iptal edilmiş. Yeni bir değişim başlatın.');
            $pdo->exec('COMMIT');
            $transactionStarted = false;
            return ['id'=>(int)$prior['id'],'receipt_no'=>(string)$prior['receipt_no'],'duplicate_prevented'=>true];
        }

        $returnRows = pos_exchange_rows($pdo, $returned, 'returned');
        $issueRows = pos_exchange_rows($pdo, $issued, 'issued');
        $returnTotal = round(array_sum(array_column($returnRows, 'line_total')), 2);
        $issueTotal = round(array_sum(array_column($issueRows, 'line_total')), 2);
        $difference = round($issueTotal - $returnTotal, 2);
        if ($difference < 0) throw new RuntimeException('Para iadesi yapılmıyor. Yeni ürünlerin toplamı getirilen ürünlerin tutarından az olamaz.');
        if ($difference > 0 && !in_array($paymentMethod, ['cash','card'], true)) {
            throw new RuntimeException('Alınacak fark için nakit veya kart seçin.');
        }
        if ($difference === 0.0) $paymentMethod = 'none';
        $cash = $paymentMethod === 'cash' ? $difference : 0.0;
        $card = $paymentMethod === 'card' ? $difference : 0.0;
        $vat = 0.0;
        foreach ($returnRows as $row) {
            $rate = (float)$row['product']['vat_rate'];
            $vat -= round($row['line_total'] - $row['line_total'] / (1 + $rate / 100), 2);
        }
        foreach ($issueRows as $row) {
            $rate = (float)$row['product']['vat_rate'];
            $vat += round($row['line_total'] - $row['line_total'] / (1 + $rate / 100), 2);
        }
        $date = date('Y-m-d');
        $time = date('H:i:s');
        $now = now();
        $pdo->prepare("INSERT INTO pos_sales (sale_date,sale_time,customer_name,payment_method,cash_amount,card_amount,credit_amount,subtotal,discount_amount,vat_amount,grand_total,note,source_token,sale_type,created_by,created_at)
            VALUES (?,?,? ,?,?,?,?,?,?,?,?,?,?, 'exchange',?,?)")
            ->execute([$date,$time,'Perakende Müşteri',$paymentMethod,$cash,$card,0,$difference,0,round($vat,2),$difference,'Ürün değişimi',$sourceToken,$userId,$now]);
        $id = (int)$pdo->lastInsertId();
        $receiptNo = pos_exchange_no($id, $date);
        $pdo->prepare('UPDATE pos_sales SET receipt_no=? WHERE id=?')->execute([$receiptNo,$id]);

        $line = $pdo->prepare("INSERT INTO pos_sale_items (sale_id,product_id,barcode,product_name,quantity,unit_price,vat_rate,line_total,exchange_role)
            VALUES (?,?,?,?,?,?,?,?,?)");
        $stock = $pdo->prepare('UPDATE pos_products SET stock_quantity=stock_quantity-?,updated_at=? WHERE id=?');
        foreach (array_merge($returnRows, $issueRows) as $row) {
            $product = $row['product'];
            $direction = $row['role'] === 'returned' ? -1 : 1;
            $line->execute([$id,(int)$product['id'],(string)$product['barcode'],pos_exchange_product_name($product),$direction*$row['quantity'],$row['price'],(float)$product['vat_rate'],$direction*$row['line_total'],$row['role']]);
            if ((int)$product['track_stock'] === 1) $stock->execute([$direction*$row['quantity'],$now,(int)$product['id']]);
        }
        if ($difference > 0) {
            pos_daily_totals_delta($date, $difference, $cash, $card, 0, $userId);
            magaza_veresiye_auto_only_sync_date($date, $userId);
        }
        audit_action('pos_sale', $id, 'degisim', null, [
            'receipt_no'=>$receiptNo,'returned_total'=>$returnTotal,'issued_total'=>$issueTotal,
            'difference'=>$difference,'payment_method'=>$paymentMethod,
            'returned_items'=>array_map(static fn($row) => ['product_id'=>(int)$row['product']['id'],'quantity'=>$row['quantity']], $returnRows),
            'issued_items'=>array_map(static fn($row) => ['product_id'=>(int)$row['product']['id'],'quantity'=>$row['quantity']], $issueRows),
        ], $receiptNo);
        $pdo->exec('COMMIT');
        $transactionStarted = false;
        return ['id'=>$id,'receipt_no'=>$receiptNo,'returned_total'=>$returnTotal,'issued_total'=>$issueTotal,'difference'=>$difference];
    } catch (Throwable $error) {
        if ($transactionStarted) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $rollbackError) {}
        }
        throw $error;
    }
}
