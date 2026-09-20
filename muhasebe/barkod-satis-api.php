<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_once __DIR__ . '/barkod-satis-lib.php';
require_once __DIR__ . '/barkod-satis-urun-kaynagi.php';
require_once __DIR__ . '/magaza-veresiye-auto-only.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function pos_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pos_product_display_name(array $product): string
{
    $name = trim((string)($product['name'] ?? ''));
    $variant = trim((string)($product['variant_name'] ?? ''));
    return $variant !== '' ? $name . ' - ' . $variant : $name;
}

try {
    pos_db_ensure();
    pos_product_source_harden();
    ensure_column(db(), 'pos_products', 'variant_name', 'TEXT');
    $action = trim((string)($_REQUEST['action'] ?? 'products'));

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'live_carts') {
            if (!pos_can_delete_sales()) pos_json(['ok'=>false,'error'=>'Canlı sepeti yalnızca Fatih görebilir.'],403);
            header('Cache-Control: no-store');
            pos_json(['ok'=>true,'sales'=>pos_live_carts()]);
        }
        if ($action === 'abandoned_carts') {
            if (!pos_can_delete_sales()) pos_json(['ok'=>false,'error'=>'Terk edilen sepetleri yalnızca Fatih görebilir.'],403);
            $date = is_string($_GET['date'] ?? null) ? $_GET['date'] : '';
            header('Cache-Control: no-store');
            pos_json(['ok'=>true,'sales'=>pos_abandoned_carts($date)]);
        }
        if ($action === 'barcode') {
            $product = pos_product_by_barcode((string)($_GET['barcode'] ?? ''));
            pos_json(['ok'=>true, 'product'=>$product]);
        }
        if ($action === 'cancelled_sales' || $action === 'removed_cart_items') {
            if (!pos_can_delete_sales()) pos_json(['ok'=>false, 'error'=>'Bu kayıtları yalnızca Fatih kullanıcısı görebilir.'], 403);
            $date = is_string($_GET['date'] ?? null) ? $_GET['date'] : '';
            pos_json(['ok'=>true, 'sales'=>pos_history_audit($action, $date)]);
        }
        if ($action === 'sales') {
            $date = array_key_exists('date', $_GET)
                ? (is_string($_GET['date']) ? $_GET['date'] : '')
                : date('Y-m-d');
            $sales = array_key_exists('date', $_GET)
                ? pos_sales_on_date($date)
                : pos_recent_sales();
            pos_json([
                'ok'=>true,
                'sales'=>$sales,
                'credit_collections'=>pos_credit_collections_on_date($date),
            ]);
        }
        pos_json(['ok'=>true, 'products'=>pos_products((string)($_GET['q'] ?? ''))]);
    }

    if (!can_manage_store_sales()) throw new RuntimeException('Bu işlem için mağaza satış yetkisi gerekiyor.');
    if (!verify_csrf($_POST['csrf_token'] ?? null)) throw new RuntimeException('Oturum doğrulaması yenilenmeli. Sayfayı yenileyin.');

    if ($action === 'sync_live_cart') {
        pos_live_save($_POST);
        pos_json(['ok'=>true]);
    }

    if ($action === 'save_product') {
        $id = (int)($_POST['id'] ?? 0);
        $barcode = preg_replace('/\s+/', '', trim((string)($_POST['barcode'] ?? '')));
        $extraBarcodes = pos_normalize_extra_barcodes((string)($_POST['extra_barcodes'] ?? ''), $barcode);
        $name = trim((string)($_POST['name'] ?? ''));
        $variant = trim((string)($_POST['variant_name'] ?? ''));
        $price = max(0, decimal_from_input($_POST['sale_price'] ?? 0));
        $vatRate = max(0, min(100, decimal_from_input($_POST['vat_rate'] ?? 10)));
        $stock = max(0, decimal_from_input($_POST['stock_quantity'] ?? 0));
        $trackStock = !empty($_POST['track_stock']) ? 1 : 0;
        if ($barcode === '' || $name === '') throw new RuntimeException('Ana barkod ve ürün adı zorunludur.');
        if ($price <= 0) throw new RuntimeException('Satış fiyatı sıfırdan büyük olmalıdır.');
        $now = now();
        $userId = current_user()['id'] ?? null;
        $pdo = db();

        if ($id <= 0) {
            $existingStmt = $pdo->prepare("SELECT id,name,variant_name,COALESCE(product_source,'pos') AS product_source,is_active FROM pos_products WHERE barcode=? LIMIT 1");
            $existingStmt->execute([$barcode]);
            $existing = $existingStmt->fetch();
            if ($existing) {
                if ((string)$existing['product_source'] !== 'pos' || (int)$existing['is_active'] !== 1) {
                    $id = (int)$existing['id'];
                } else {
                    $existingName = trim((string)$existing['name']);
                    $existingVariant = trim((string)($existing['variant_name'] ?? ''));
                    if ($existingVariant !== '') $existingName .= ' - ' . $existingVariant;
                    throw new RuntimeException('Bu barkod daha önce kullanıldı. ' . $barcode . ' barkodu “' . $existingName . '” ürününde ana barkod olarak kullanılıyor.');
                }
            }
        }
        pos_assert_barcodes_available(array_merge([$barcode], $extraBarcodes), $id);

        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE pos_products SET barcode=?,name=?,variant_name=?,sale_price=?,vat_rate=?,stock_quantity=?,track_stock=?,product_source='pos',is_active=1,created_by=COALESCE(created_by,?),updated_at=? WHERE id=?");
                $stmt->execute([$barcode,$name,$variant,$price,$vatRate,$stock,$trackStock,$userId,$now,$id]);
                if ($stmt->rowCount() < 1) throw new RuntimeException('Ürün kaydı bulunamadı.');
            } else {
                $pdo->prepare("INSERT INTO pos_products (barcode,name,variant_name,sale_price,vat_rate,stock_quantity,track_stock,is_active,product_source,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,1,'pos',?,?,?)")
                    ->execute([$barcode,$name,$variant,$price,$vatRate,$stock,$trackStock,$userId,$now,$now]);
                $id = (int)$pdo->lastInsertId();
            }
            $pdo->prepare("DELETE FROM pos_product_barcodes WHERE product_id=?")->execute([$id]);
            $aliasInsert = $pdo->prepare("INSERT INTO pos_product_barcodes (product_id,barcode,created_by,created_at) VALUES (?,?,?,?)");
            foreach ($extraBarcodes as $extraBarcode) {
                $aliasInsert->execute([$id,$extraBarcode,$userId,$now]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $displayName = $variant !== '' ? $name . ' - ' . $variant : $name;
        audit_action('pos_product', $id, 'kaydedildi', null, ['barcode'=>$barcode,'extra_barcodes'=>$extraBarcodes,'name'=>$name,'variant_name'=>$variant,'sale_price'=>$price,'stock_quantity'=>$stock,'product_source'=>'pos'], $displayName);
        pos_json(['ok'=>true,'message'=>'Ürün ve barkodları kaydedildi.','products'=>pos_products()]);
    }

    if ($action === 'cart_event') {
        $eventType = trim((string)($_POST['event_type'] ?? ''));
        if (!in_array($eventType, ['cart_cleared','item_removed'], true)) throw new RuntimeException('Sepet işlemi geçersiz.');
        $eventSource = trim((string)($_POST['event_source'] ?? ''));
        $removalReason = preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', (string)($_POST['removal_reason'] ?? '')) ?? '';
        $rawItems = json_decode((string)($_POST['items_json'] ?? ''), true);
        if (!is_array($rawItems) || !$rawItems) throw new RuntimeException('Kaydedilecek sepet bilgisi bulunamadı.');
        $rawItems = array_slice($rawItems, 0, 100);

        $singleDecrement = false;
        if ($eventType === 'item_removed' && $eventSource === 'single_decrement' && count($rawItems) === 1) {
            $singleQuantity = max(0, decimal_from_input($rawItems[0]['quantity'] ?? 0));
            $singleDecrement = abs($singleQuantity - 1.0) < 0.001;
        }
        if (!$singleDecrement && preg_match_all('/./us', $removalReason) < 6) {
            throw new RuntimeException('Sepetten ürün kaldırma veya sepet temizleme nedeni en az 6 karakter olmalıdır.');
        }
        if ($singleDecrement) {
            $removalReason = '1 adet barkod düzeltmesi — açıklama istenmedi';
        }

        $itemStmt = db()->prepare("SELECT id,name,variant_name,barcode,sale_price FROM pos_products WHERE id=? LIMIT 1");
        $loggedItems = [];
        $cartTotal = 0.0;
        foreach ($rawItems as $rawItem) {
            $productId = (int)($rawItem['product_id'] ?? 0);
            $quantity = max(0, decimal_from_input($rawItem['quantity'] ?? 0));
            if ($productId <= 0 || $quantity <= 0) continue;
            $itemStmt->execute([$productId]);
            $product = $itemStmt->fetch();
            if (!$product) continue;
            $productName = trim((string)$product['name']);
            $variant = trim((string)($product['variant_name'] ?? ''));
            if ($variant !== '') $productName .= ' - ' . $variant;
            $lineTotal = round($quantity * (float)$product['sale_price'], 2);
            $cartTotal += $lineTotal;
            $loggedItems[] = [
                'product_id'=>$productId,
                'name'=>$productName,
                'barcode'=>(string)$product['barcode'],
                'quantity'=>$quantity,
                'unit_price'=>(float)$product['sale_price'],
                'line_total'=>$lineTotal,
            ];
        }
        if (!$loggedItems) throw new RuntimeException('Kaydedilecek geçerli sepet ürünü bulunamadı.');
        $discount = max(0, decimal_from_input($_POST['discount_amount'] ?? 0));
        $paymentMethod = trim((string)($_POST['payment_method'] ?? 'cash'));
        $eventLabel = $eventType === 'cart_cleared' ? 'Sepet temizlendi' : 'Ürün sepetten çıkarıldı';
        audit_action('pos_cart', 0, $eventType === 'cart_cleared' ? 'sepet_temizlendi' : 'urun_cikarildi', null, [
            'event'=>$eventType,
            'event_source'=>$eventSource,
            'reason_required'=>!$singleDecrement,
            'removal_reason'=>$removalReason,
            'items'=>$loggedItems,
            'item_count'=>count($loggedItems),
            'cart_total'=>round(max(0, $cartTotal - $discount), 2),
            'discount_amount'=>$discount,
            'payment_method'=>$paymentMethod,
            'event_time'=>now(),
        ], $eventLabel);
        if ($eventType === 'cart_cleared') {
            pos_live_clear_terminal(trim((string)($_POST['terminal_id'] ?? '')), (int)(current_user()['id'] ?? 0));
        }
        $message = $singleDecrement
            ? '1 adet azaltıldı ve Sepetten Silinen Ürünler kaydına işlendi.'
            : $eventLabel . ' ve denetim kaydına işlendi.';
        pos_json(['ok'=>true,'message'=>$message]);
    }

    if ($action === 'bulk_update_products') {
        $updates = json_decode((string)($_POST['updates_json'] ?? ''), true);
        if (!is_array($updates) || !$updates) throw new RuntimeException('Güncellenecek ürün bulunamadı.');
        if (count($updates) > 500) throw new RuntimeException('Tek işlemde en fazla 500 ürün güncellenebilir.');
        $pdo = db();
        $now = now();
        $stmt = $pdo->prepare("SELECT id,name,variant_name,sale_price,stock_quantity FROM pos_products WHERE id=? AND is_active=1 AND COALESCE(product_source,'pos')='pos' LIMIT 1");
        $updateStmt = $pdo->prepare("UPDATE pos_products SET sale_price=?,stock_quantity=?,updated_at=? WHERE id=?");
        $pdo->beginTransaction();
        $changed = 0;
        try {
            foreach ($updates as $update) {
                $productId = (int)($update['id'] ?? 0);
                $price = decimal_from_input($update['sale_price'] ?? 0);
                $stock = decimal_from_input($update['stock_quantity'] ?? 0);
                if ($productId <= 0) continue;
                if ($price <= 0) throw new RuntimeException('Satış fiyatı sıfırdan büyük olmalıdır.');
                $stmt->execute([$productId]);
                $product = $stmt->fetch();
                if (!$product) throw new RuntimeException('Güncellenecek ürünlerden biri bulunamadı.');
                $oldPrice = (float)$product['sale_price'];
                $oldStock = (float)$product['stock_quantity'];
                if (abs($oldPrice - $price) < 0.001 && abs($oldStock - $stock) < 0.001) continue;
                $updateStmt->execute([$price,$stock,$now,$productId]);
                $displayName = trim((string)$product['name']);
                $variant = trim((string)($product['variant_name'] ?? ''));
                if ($variant !== '') $displayName .= ' - ' . $variant;
                audit_action('pos_product', $productId, 'toplu_guncellendi',
                    ['sale_price'=>$oldPrice,'stock_quantity'=>$oldStock],
                    ['sale_price'=>$price,'stock_quantity'=>$stock],
                    $displayName
                );
                $changed++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        pos_json(['ok'=>true,'message'=>$changed . ' ürünün fiyat/stok bilgisi güncellendi.','changed'=>$changed]);
    }

    if ($action === 'credit_collection') {
        $personId = (int)($_POST['person_id'] ?? 0);
        $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
        $amount = round(max(0, decimal_from_input($_POST['amount'] ?? 0)), 2);
        $sourceToken = preg_replace('/[^A-Za-z0-9_.:-]/', '', trim((string)($_POST['source_token'] ?? ''))) ?? '';
        if ($personId <= 0) throw new RuntimeException('Tahsilat yapılacak kişi seçilmedi.');
        if (!in_array($paymentMethod, ['cash','card'], true)) throw new RuntimeException('Tahsilat türü Nakit veya Kart olmalıdır.');
        if ($amount <= 0) throw new RuntimeException('Tahsilat tutarı sıfırdan büyük olmalıdır.');
        if ($sourceToken === '' || strlen($sourceToken) > 100) throw new RuntimeException('Tahsilat güvenlik anahtarı geçersiz. Sayfayı yenileyip tekrar deneyin.');

        $person = pos_credit_person_with_balance($personId);
        if (!$person) throw new RuntimeException('Seçilen kişi bulunamadı.');
        $balance = round(max(0, (float)($person['balance'] ?? 0)), 2);
        if ($balance <= 0.004) throw new RuntimeException('Seçilen kişinin açık veresiye borcu bulunmuyor.');
        if ($amount > $balance + 0.004) {
            throw new RuntimeException('Tahsilat tutarı kalan borçtan fazla olamaz. Kalan borç: ' . number_format($balance, 2, ',', '.') . ' TL');
        }

        $pdo = db();
        $existing = $pdo->prepare("SELECT id,amount FROM store_credit_entries WHERE source_token=? LIMIT 1");
        $existing->execute([$sourceToken]);
        $existingRow = $existing->fetch();
        if ($existingRow) {
            pos_json([
                'ok'=>true,
                'duplicate'=>true,
                'message'=>'Bu tahsilat zaten kaydedilmiş.',
                'entry_id'=>(int)$existingRow['id'],
            ]);
        }

        $entryDate = date('Y-m-d');
        $userId = (int)(current_user()['id'] ?? 0) ?: null;
        $description = 'Barkodlu Satış ekranından ' . ($paymentMethod === 'cash' ? 'nakit' : 'kart') . ' veresiye tahsilatı';
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO store_credit_entries
                (person_id,entry_type,amount,entry_date,payment_method,daily_breakdown_id,source_token,description,is_cancelled,created_by,created_at,updated_at)
                VALUES (?,'payment',?,?,?,NULL,?,?,0,?,?,?)")
                ->execute([$personId,$amount,$entryDate,$paymentMethod,$sourceToken,$description,$userId,now(),now()]);
            $entryId = (int)$pdo->lastInsertId();

            $sync = magaza_veresiye_auto_only_sync_date($entryDate, $userId);
            $dailyBreakdownId = (int)($sync['record_id'] ?? 0);
            if ($dailyBreakdownId > 0) {
                $pdo->prepare('UPDATE store_credit_entries SET daily_breakdown_id=?,updated_at=? WHERE id=?')
                    ->execute([$dailyBreakdownId,now(),$entryId]);
            }

            audit_action('magaza_personel_veresiye_hareketi', $entryId, 'pos_tahsilat', null, [
                'person_id'=>$personId,
                'type'=>'payment',
                'amount'=>$amount,
                'payment_method'=>$paymentMethod,
                'date'=>$entryDate,
                'source'=>'barkod_satis',
                'source_token'=>$sourceToken,
            ], (string)$person['full_name']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $remaining = round(max(0, $balance - $amount), 2);
        pos_json([
            'ok'=>true,
            'message'=>(string)$person['full_name'] . ' için ' . number_format($amount, 2, ',', '.') . ' TL ' . ($paymentMethod === 'cash' ? 'nakit' : 'kart') . ' tahsilat kaydedildi.',
            'entry_id'=>$entryId,
            'remaining_balance'=>$remaining,
            'payment_method'=>$paymentMethod,
        ]);
    }

    if ($action === 'delete_sale') {
        if (!pos_can_delete_sales()) throw new RuntimeException('Satış iptal yetkisi yalnızca Fatih kullanıcısına aittir.');
        $saleId = (int)($_POST['sale_id'] ?? 0);
        $cancelReason = preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', (string)($_POST['cancel_reason'] ?? '')) ?? '';
        if ($saleId <= 0) throw new RuntimeException('İptal edilecek satış seçilmedi.');
        if (preg_match_all('/./us', $cancelReason) < 6) throw new RuntimeException('Satış iptal nedeni en az 6 karakter olmalıdır.');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM pos_sales WHERE id=? AND is_cancelled=0 LIMIT 1");
            $stmt->execute([$saleId]);
            $sale = $stmt->fetch();
            if (!$sale) throw new RuntimeException('Satış bulunamadı veya daha önce silinmiş.');

            $itemStmt = $pdo->prepare("SELECT i.product_id,i.quantity,p.track_stock FROM pos_sale_items i LEFT JOIN pos_products p ON p.id=i.product_id WHERE i.sale_id=?");
            $itemStmt->execute([$saleId]);
            foreach ($itemStmt->fetchAll() ?: [] as $item) {
                if ((int)($item['track_stock'] ?? 0) === 1) {
                    $pdo->prepare("UPDATE pos_products SET stock_quantity=stock_quantity+?,updated_at=? WHERE id=?")
                        ->execute([(float)$item['quantity'],now(),(int)$item['product_id']]);
                }
            }

            $creditEntryId = (int)($sale['credit_entry_id'] ?? 0);
            if ($creditEntryId > 0) {
                $pdo->prepare("UPDATE store_credit_entries SET is_cancelled=1,cancelled_at=?,cancelled_by=?,cancel_reason=?,updated_at=? WHERE id=? AND is_cancelled=0")
                    ->execute([now(),current_user()['id'] ?? null,$cancelReason,now(),$creditEntryId]);
            }

            $pdo->prepare("UPDATE pos_sales SET is_cancelled=1,cancelled_at=?,cancelled_by=?,cancel_reason=? WHERE id=?")
                ->execute([now(),current_user()['id'] ?? null,$cancelReason,$saleId]);
            $grandTotal = (float)$sale['grand_total'];
            $paymentMethod = (string)$sale['payment_method'];
            $paymentAmounts = pos_sale_payment_amounts($sale);
            pos_daily_totals_delta(
                (string)$sale['sale_date'],
                -$grandTotal,
                -$paymentAmounts['cash'],
                -$paymentAmounts['card'],
                0,
                (int)(current_user()['id'] ?? 0) ?: null
            );
            magaza_veresiye_auto_only_sync_date((string)$sale['sale_date'], (int)(current_user()['id'] ?? 0) ?: null);

            audit_action('pos_sale', $saleId, 'silindi', [
                'receipt_no'=>$sale['receipt_no'],
                'payment_method'=>$paymentMethod,
                'cash_amount'=>$paymentAmounts['cash'],
                'card_amount'=>$paymentAmounts['card'],
                'credit_amount'=>$paymentAmounts['credit'],
                'grand_total'=>$grandTotal,
                'is_cancelled'=>0,
            ], [
                'is_cancelled'=>1,
                'cancel_reason'=>$cancelReason,
                'stock_restored'=>true,
                'totals_reversed'=>true,
            ], (string)$sale['receipt_no']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        pos_json(['ok'=>true,'message'=>'Satış iptal edildi; stok ve mağaza toplamları geri alındı.']);
    }

    if ($action !== 'complete_sale') throw new RuntimeException('Geçersiz işlem.');
    $rawItems = json_decode((string)($_POST['items_json'] ?? ''), true);
    if (!is_array($rawItems) || !$rawItems) throw new RuntimeException('Sepette ürün bulunmuyor.');
    $paymentMethod = trim((string)($_POST['payment_method'] ?? 'cash'));
    if (!in_array($paymentMethod, ['cash','card','credit','mixed'], true)) throw new RuntimeException('Ödeme şekli geçersiz.');
    $sourceToken = trim((string)($_POST['source_token'] ?? ''));
    if ($sourceToken !== '' && !preg_match('/^[a-zA-Z0-9-]{20,100}$/D', $sourceToken)) throw new RuntimeException('Satış işlem kimliği geçersiz.');
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
    if ($sourceToken !== '') {
        $tokenStmt = $pdo->prepare("SELECT id,receipt_no,payment_method,cash_amount,card_amount,credit_amount,is_cancelled FROM pos_sales WHERE source_token=? AND created_by=? LIMIT 1");
        $tokenStmt->execute([$sourceToken,$userId]);
        $existingSale = $tokenStmt->fetch();
        if ($existingSale) {
            if ((int)$existingSale['is_cancelled'] === 1) throw new RuntimeException('Bu satış işlem kimliği daha önce kullanılmış ve satış sonradan iptal edilmiş.');
            pos_json([
                'ok'=>true,
                'message'=>'Satış daha önce kaydedilmişti; ikinci kez oluşturulmadı.',
                'sale_id'=>(int)$existingSale['id'],
                'receipt_no'=>(string)$existingSale['receipt_no'],
                'receipt_url'=>'barkod-fis.php?id='.(int)$existingSale['id'],
                'payment_method'=>(string)$existingSale['payment_method'],
                'cash_amount'=>(float)$existingSale['cash_amount'],
                'card_amount'=>(float)$existingSale['card_amount'],
                'credit_amount'=>(float)$existingSale['credit_amount'],
                'duplicate_prevented'=>true,
            ]);
        }
    }
    $pdo->beginTransaction();
    try {
        $items = [];
        $subtotal = 0.0;
        $vatAmount = 0.0;
        foreach ($rawItems as $raw) {
            $productId = (int)($raw['product_id'] ?? 0);
            $quantity = max(0, decimal_from_input($raw['quantity'] ?? 0));
            if ($productId <= 0 || $quantity <= 0) continue;
            $stmt = $pdo->prepare("SELECT * FROM pos_products WHERE id=? AND is_active=1 AND COALESCE(product_source,'pos')='pos' LIMIT 1");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) throw new RuntimeException('Sepetteki ürünlerden biri Barkodlu Satış ürün havuzunda değil veya artık aktif değil.');
            $displayName = pos_product_display_name($product);
            $lineTotal = round($quantity * (float)$product['sale_price'], 2);
            $lineVat = round($lineTotal - ($lineTotal / (1 + ((float)$product['vat_rate'] / 100))), 2);
            $subtotal += $lineTotal;
            $vatAmount += $lineVat;
            $items[] = ['product'=>$product,'quantity'=>$quantity,'line_total'=>$lineTotal,'display_name'=>$displayName];
        }
        if (!$items) throw new RuntimeException('Satışa uygun ürün bulunamadı.');
        $discount = min(round($discount, 2), round($subtotal, 2));
        $grandTotal = round($subtotal - $discount, 2);
        if ($grandTotal <= 0) throw new RuntimeException('Satış toplamı sıfır olamaz.');
        if ($subtotal > 0 && $discount > 0) $vatAmount = round($vatAmount * ($grandTotal / $subtotal), 2);

        $cashAmount = 0.0;
        $cardAmount = 0.0;
        $creditAmount = 0.0;
        if ($paymentMethod === 'cash') {
            $cashAmount = $grandTotal;
        } elseif ($paymentMethod === 'card') {
            $cardAmount = $grandTotal;
        } elseif ($paymentMethod === 'credit') {
            $creditAmount = $grandTotal;
        } elseif ($paymentMethod === 'mixed') {
            $cashAmount = round(max(0, decimal_from_input($_POST['cash_amount'] ?? 0)), 2);
            if ($cashAmount <= 0) throw new RuntimeException('Nakit + Kredi Kartı ödemede nakit tutarı sıfırdan büyük olmalıdır.');
            if ($cashAmount >= $grandTotal) throw new RuntimeException('Nakit + Kredi Kartı ödemede nakit tutarı satış toplamından küçük olmalıdır.');
            $cardAmount = round($grandTotal - $cashAmount, 2);
            if ($cardAmount <= 0) throw new RuntimeException('Kredi kartı tutarı sıfırdan büyük olmalıdır.');
        }

        $customerName = $paymentMethod === 'credit' && $creditPerson
            ? (string)$creditPerson['full_name']
            : 'Perakende Müşteri';

        $pdo->prepare("INSERT INTO pos_sales (sale_date,sale_time,customer_name,cari_id,credit_person_id,payment_method,cash_amount,card_amount,credit_amount,subtotal,discount_amount,vat_amount,grand_total,note,source_token,created_by,created_at) VALUES (?,?,?,NULL,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$saleDate,$saleTime,$customerName,$personId ?: null,$paymentMethod,$cashAmount,$cardAmount,$creditAmount,round($subtotal,2),$discount,round($vatAmount,2),$grandTotal,trim((string)($_POST['note'] ?? '')),$sourceToken !== '' ? $sourceToken : null,$userId,now()]);
        $saleId = (int)$pdo->lastInsertId();
        $receiptNo = pos_receipt_no($saleId, $saleDate);
        $pdo->prepare("UPDATE pos_sales SET receipt_no=? WHERE id=?")->execute([$receiptNo,$saleId]);

        $lineStmt = $pdo->prepare("INSERT INTO pos_sale_items (sale_id,product_id,barcode,product_name,quantity,unit_price,vat_rate,line_total) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($items as $item) {
            $p = $item['product'];
            $lineStmt->execute([$saleId,(int)$p['id'],$p['barcode'],$item['display_name'],$item['quantity'],(float)$p['sale_price'],(float)$p['vat_rate'],$item['line_total']]);
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
            $cashAmount,
            $cardAmount,
            0,
            $userId
        );

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
            'cash_amount'=>$cashAmount,
            'card_amount'=>$cardAmount,
            'credit_amount'=>$creditAmount,
            'grand_total'=>$grandTotal,
            'credit_person_id'=>$personId ?: null,
            'credit_entry_id'=>$creditEntryId ?: null,
            'source_token'=>$sourceToken !== '' ? $sourceToken : null,
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
        pos_live_clear_terminal(trim((string)($_POST['terminal_id'] ?? '')), $userId);
        pos_json(['ok'=>true,'message'=>'Satış tamamlandı.','sale_id'=>$saleId,'receipt_no'=>$receiptNo,'receipt_url'=>'barkod-fis.php?id='.$saleId,'payment_method'=>$paymentMethod,'cash_amount'=>$cashAmount,'card_amount'=>$cardAmount,'credit_amount'=>$creditAmount]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
} catch (Throwable $e) {
    pos_json(['ok'=>false,'error'=>$e->getMessage()], 422);
}
