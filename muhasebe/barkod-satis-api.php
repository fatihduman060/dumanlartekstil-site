<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_once __DIR__ . '/barkod-satis-lib.php';
require_once __DIR__ . '/magaza-veresiye-auto-only.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function pos_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    pos_db_ensure();
    $action = trim((string)($_REQUEST['action'] ?? 'products'));

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'barcode') {
            $product = pos_product_by_barcode((string)($_GET['barcode'] ?? ''));
            pos_json(['ok'=>true, 'product'=>$product]);
        }
        if ($action === 'sales') pos_json(['ok'=>true, 'sales'=>pos_recent_sales()]);
        pos_json(['ok'=>true, 'products'=>pos_products((string)($_GET['q'] ?? ''))]);
    }

    if (!can_manage_store_sales()) throw new RuntimeException('Bu işlem için mağaza satış yetkisi gerekiyor.');
    if (!verify_csrf($_POST['csrf_token'] ?? null)) throw new RuntimeException('Oturum doğrulaması yenilenmeli. Sayfayı yenileyin.');

    if ($action === 'save_product') {
        $id = (int)($_POST['id'] ?? 0);
        $barcode = preg_replace('/\s+/', '', trim((string)($_POST['barcode'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $price = max(0, decimal_from_input($_POST['sale_price'] ?? 0));
        $vatRate = max(0, min(100, decimal_from_input($_POST['vat_rate'] ?? 10)));
        $stock = max(0, decimal_from_input($_POST['stock_quantity'] ?? 0));
        $trackStock = !empty($_POST['track_stock']) ? 1 : 0;
        if ($barcode === '' || $name === '') throw new RuntimeException('Barkod ve ürün adı zorunludur.');
        if ($price <= 0) throw new RuntimeException('Satış fiyatı sıfırdan büyük olmalıdır.');
        $now = now();
        if ($id > 0) {
            db()->prepare("UPDATE pos_products SET barcode=?,name=?,sale_price=?,vat_rate=?,stock_quantity=?,track_stock=?,updated_at=? WHERE id=?")
                ->execute([$barcode,$name,$price,$vatRate,$stock,$trackStock,$now,$id]);
        } else {
            db()->prepare("INSERT INTO pos_products (barcode,name,sale_price,vat_rate,stock_quantity,track_stock,is_active,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,1,?,?,?)")
                ->execute([$barcode,$name,$price,$vatRate,$stock,$trackStock,current_user()['id'] ?? null,$now,$now]);
            $id = (int)db()->lastInsertId();
        }
        audit_action('pos_product', $id, 'kaydedildi', null, ['barcode'=>$barcode,'name'=>$name,'sale_price'=>$price,'stock_quantity'=>$stock], $name);
        pos_json(['ok'=>true,'message'=>'Ürün kaydedildi.','products'=>pos_products()]);
    }

    if ($action !== 'complete_sale') throw new RuntimeException('Geçersiz işlem.');
    $rawItems = json_decode((string)($_POST['items_json'] ?? ''), true);
    if (!is_array($rawItems) || !$rawItems) throw new RuntimeException('Sepette ürün bulunmuyor.');
    $paymentMethod = trim((string)($_POST['payment_method'] ?? 'cash'));
    if (!in_array($paymentMethod, ['cash','card','credit'], true)) throw new RuntimeException('Ödeme şekli geçersiz.');
    $personId = (int)($_POST['person_id'] ?? 0);
    $creditPerson = null;
    if ($paymentMethod === 'credit') {
        $creditPerson = pos_credit_person($personId);
        if (!$creditPerson) throw new RuntimeException('Veresiye satışta Personel Veresiye Takibi listesinden aktif bir personel seçmelisiniz.');
    } else {
        $personId = 0;
    }
    $discount = max(0, decimal_from_input($_POST['discount_amount'] ?? 0));
    $saleDate = date('Y-m-d');
    $saleTime = date('H:i:s');
    $userId = (int)(current_user()['id'] ?? 0) ?: null;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $items = [];
        $subtotal = 0.0;
        $vatAmount = 0.0;
        foreach ($rawItems as $raw) {
            $productId = (int)($raw['product_id'] ?? 0);
            $quantity = max(0, decimal_from_input($raw['quantity'] ?? 0));
            if ($productId <= 0 || $quantity <= 0) continue;
            $stmt = $pdo->prepare("SELECT * FROM pos_products WHERE id=? AND is_active=1 LIMIT 1");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) throw new RuntimeException('Sepetteki ürünlerden biri artık aktif değil.');
            if ((int)$product['track_stock'] === 1 && $quantity > (float)$product['stock_quantity'] + 0.0001) {
                throw new RuntimeException($product['name'] . ' için stok yetersiz. Mevcut: ' . number_format((float)$product['stock_quantity'], 0, ',', '.'));
            }
            $lineTotal = round($quantity * (float)$product['sale_price'], 2);
            $lineVat = round($lineTotal - ($lineTotal / (1 + ((float)$product['vat_rate'] / 100))), 2);
            $subtotal += $lineTotal;
            $vatAmount += $lineVat;
            $items[] = ['product'=>$product,'quantity'=>$quantity,'line_total'=>$lineTotal];
        }
        if (!$items) throw new RuntimeException('Satışa uygun ürün bulunamadı.');
        $discount = min(round($discount, 2), round($subtotal, 2));
        $grandTotal = round($subtotal - $discount, 2);
        if ($grandTotal <= 0) throw new RuntimeException('Satış toplamı sıfır olamaz.');
        if ($subtotal > 0 && $discount > 0) $vatAmount = round($vatAmount * ($grandTotal / $subtotal), 2);

        $customerName = $paymentMethod === 'credit' && $creditPerson
            ? (string)$creditPerson['full_name']
            : 'Perakende Müşteri';

        $pdo->prepare("INSERT INTO pos_sales (sale_date,sale_time,customer_name,cari_id,credit_person_id,payment_method,subtotal,discount_amount,vat_amount,grand_total,note,created_by,created_at) VALUES (?,?,?,NULL,?,?,?,?,?,?,?,?,?)")
            ->execute([$saleDate,$saleTime,$customerName,$personId ?: null,$paymentMethod,round($subtotal,2),$discount,round($vatAmount,2),$grandTotal,trim((string)($_POST['note'] ?? '')),$userId,now()]);
        $saleId = (int)$pdo->lastInsertId();
        $receiptNo = pos_receipt_no($saleId, $saleDate);
        $pdo->prepare("UPDATE pos_sales SET receipt_no=? WHERE id=?")->execute([$receiptNo,$saleId]);

        $lineStmt = $pdo->prepare("INSERT INTO pos_sale_items (sale_id,product_id,barcode,product_name,quantity,unit_price,vat_rate,line_total) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($items as $item) {
            $p = $item['product'];
            $lineStmt->execute([$saleId,(int)$p['id'],$p['barcode'],$p['name'],$item['quantity'],(float)$p['sale_price'],(float)$p['vat_rate'],$item['line_total']]);
            if ((int)$p['track_stock'] === 1) {
                $pdo->prepare("UPDATE pos_products SET stock_quantity=stock_quantity-?,updated_at=? WHERE id=?")
                    ->execute([$item['quantity'],now(),(int)$p['id']]);
            }
        }

        $creditEntryId = 0;
        if ($paymentMethod === 'credit' && $creditPerson) {
            $description = 'Barkodlu mağaza veresiye satışı / ' . $receiptNo;
            $pdo->prepare("INSERT INTO store_credit_entries (person_id,entry_type,amount,entry_date,payment_method,daily_breakdown_id,description,is_cancelled,created_by,created_at,updated_at) VALUES (?,'debt',?,?,NULL,NULL,?,0,?,?,?)")
                ->execute([$personId,$grandTotal,$saleDate,$description,$userId,now(),now()]);
            $creditEntryId = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE pos_sales SET credit_entry_id=? WHERE id=?")->execute([$creditEntryId,$saleId]);
        }

        pos_daily_totals_delta(
            $saleDate,
            $grandTotal,
            $paymentMethod==='cash' ? $grandTotal : 0,
            $paymentMethod==='card' ? $grandTotal : 0,
            0,
            $userId
        );

        // 26.08.2026 itibarıyla veresiye tutarları yalnızca Personel Veresiye
        // hareketlerinden hesaplanır. Eski manuel alan günlük toplamı etkileyemez.
        magaza_veresiye_auto_only_sync_date($saleDate, $userId);

        if ($creditEntryId > 0) {
            $stmt = $pdo->prepare("SELECT id FROM store_daily_payment_breakdown WHERE sale_date=? LIMIT 1");
            $stmt->execute([$saleDate]);
            $dailyBreakdownId = (int)($stmt->fetchColumn() ?: 0);
            if ($dailyBreakdownId > 0) {
                $pdo->prepare("UPDATE store_credit_entries SET daily_breakdown_id=?,updated_at=? WHERE id=?")
                    ->execute([$dailyBreakdownId,now(),$creditEntryId]);
            }
        }

        audit_action('pos_sale', $saleId, 'satildi', null, [
            'receipt_no'=>$receiptNo,
            'payment_method'=>$paymentMethod,
            'grand_total'=>$grandTotal,
            'credit_person_id'=>$personId ?: null,
            'credit_entry_id'=>$creditEntryId ?: null,
        ], $receiptNo);
        if ($creditEntryId > 0) {
            audit_action('magaza_personel_veresiye_hareketi', $creditEntryId, 'eklendi', null, [
                'person_id'=>$personId,
                'type'=>'debt',
                'amount'=>$grandTotal,
                'date'=>$saleDate,
                'source'=>'barkod_satis',
                'sale_id'=>$saleId,
            ], (string)$creditPerson['full_name']);
        }
        $pdo->commit();
        pos_json(['ok'=>true,'message'=>'Satış tamamlandı.','sale_id'=>$saleId,'receipt_no'=>$receiptNo,'receipt_url'=>'barkod-fis.php?id='.$saleId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
} catch (Throwable $e) {
    pos_json(['ok'=>false,'error'=>$e->getMessage()], 422);
}
