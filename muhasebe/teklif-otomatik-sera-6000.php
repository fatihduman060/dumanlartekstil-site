<?php
require_once __DIR__ . '/teklif-db.php';

function teklif_seratekstil_key($value): string
{
    $value = mb_strtolower(trim((string)$value), 'UTF-8');
    $value = strtr($value, [
        'ı'=>'i','ğ'=>'g','ü'=>'u','ş'=>'s','ö'=>'o','ç'=>'c',
        'İ'=>'i','Ğ'=>'g','Ü'=>'u','Ş'=>'s','Ö'=>'o','Ç'=>'c',
    ]);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?: '';
}

function teklif_seratekstil_requested_offer(): array
{
    $settingKey = 'requested_offer_sera_tekstil_6000_100x400_v1';
    $saved = trim((string)setting_get($settingKey, ''));
    if ($saved !== '') {
        $decoded = json_decode($saved, true);
        return [
            'created' => false,
            'existing' => true,
            'offer_id' => (int)($decoded['offer_id'] ?? 0),
        ];
    }

    teklif_db_ensure();
    $pdo = db();

    $cari = null;
    $cariler = $pdo->query('SELECT id, name, city, address, tax_no, tax_office, phone FROM cariler ORDER BY id ASC')->fetchAll() ?: [];
    foreach ($cariler as $row) {
        $key = teklif_seratekstil_key($row['name'] ?? '');
        if ($key === 'seratekstil' || (str_contains($key, 'sera') && str_contains($key, 'tekstil'))) {
            $cari = $row;
            break;
        }
    }
    if (!$cari) {
        return ['created'=>false, 'existing'=>false, 'offer_id'=>0, 'error'=>'Sera Tekstil cari kaydı bulunamadı.'];
    }

    $article = '6000';
    $barcode = teklif_barcode_from_article($article);
    $productName = 'BİTKE ERKEK MODAL ÇORABI';
    $productType = '6000';

    $products = teklif_products_for_select();
    foreach ($products as $product) {
        $candidateArticle = teklif_article_from_text((string)($product['barcode'] ?? ''))
            ?: teklif_article_from_text((string)($product['name'] ?? ''))
            ?: teklif_article_from_text((string)($product['product_type'] ?? ''));
        $candidateBarcode = teklif_normalize_barcode(
            (string)($product['barcode'] ?? ''),
            (string)($product['name'] ?? ''),
            (string)($product['product_type'] ?? '')
        );
        if ($candidateArticle === $article || ($barcode !== '' && $candidateBarcode === $barcode)) {
            $barcode = $candidateBarcode ?: $barcode;
            $productName = trim((string)($product['name'] ?? '')) ?: $productName;
            $productType = trim((string)($product['product_type'] ?? '')) ?: $productType;
            break;
        }
    }

    $existingStmt = $pdo->prepare("SELECT o.id
        FROM offers o
        JOIN offer_items oi ON oi.offer_id=o.id
        WHERE COALESCE(o.is_deleted,0)=0
          AND o.cari_id=?
          AND ABS(oi.quantity-100)<0.0001
          AND ABS(oi.unit_price-400)<0.0001
          AND (oi.product_barcode=? OR oi.product_name=?)
        ORDER BY o.id DESC LIMIT 1");
    $existingStmt->execute([(int)$cari['id'], $barcode, $productName]);
    $existingId = (int)($existingStmt->fetchColumn() ?: 0);
    if ($existingId > 0) {
        setting_set($settingKey, json_encode(['offer_id'=>$existingId, 'existing'=>true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['created'=>false, 'existing'=>true, 'offer_id'=>$existingId];
    }

    $creatorId = null;
    try {
        $users = $pdo->query("SELECT id, username, display_name FROM users WHERE is_active=1 ORDER BY id ASC")->fetchAll() ?: [];
        foreach ($users as $user) {
            $usernameKey = teklif_seratekstil_key($user['username'] ?? '');
            $displayKey = teklif_seratekstil_key($user['display_name'] ?? '');
            if (in_array($usernameKey, ['fatih','fatihduman'], true) || in_array($displayKey, ['fatih','fatihduman'], true)) {
                $creatorId = (int)$user['id'];
                break;
            }
        }
    } catch (Throwable $e) {}
    if (!$creatorId) $creatorId = (int)(current_user()['id'] ?? 0) ?: null;

    $offerNo = teklif_next_offer_no();
    $offerDate = date('Y-m-d');
    $quantity = 100.0;
    $unitPrice = 400.0;
    $subtotal = $quantity * $unitPrice;
    $now = now();

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO offers (
            offer_no, offer_date, document_title, cari_id,
            customer_name, customer_city, customer_address,
            customer_tax_office, customer_tax_no, customer_phone,
            currency, quantity_label, note, footer_text, term_text,
            discount_enabled, discount_rate, discount_amount,
            vat_enabled, vat_rate, subtotal, vat_amount, grand_total,
            created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 10, ?, 0, ?, ?, ?, ?)');
        $stmt->execute([
            $offerNo,
            $offerDate,
            'TEKLİF FORMU',
            (int)$cari['id'],
            (string)$cari['name'],
            (string)($cari['city'] ?? ''),
            (string)($cari['address'] ?? ''),
            (string)($cari['tax_office'] ?? ''),
            (string)($cari['tax_no'] ?? ''),
            (string)($cari['phone'] ?? ''),
            'TL',
            'DZ',
            '',
            'MALIMIZDAN HAYIR GÖRÜN.',
            '',
            $subtotal,
            $subtotal,
            $creatorId,
            $now,
            $now,
        ]);
        $offerId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO offer_items (offer_id, sort_order, product_barcode, product_name, product_type, quantity, unit_price, line_total) VALUES (?, 1, ?, ?, ?, ?, ?, ?)')
            ->execute([$offerId, $barcode, $productName, $productType, $quantity, $unitPrice, $subtotal]);

        teklif_save_product_suggestion($productName, $productType, $unitPrice, $barcode);
        setting_set($settingKey, json_encode([
            'offer_id'=>$offerId,
            'offer_no'=>$offerNo,
            'created_at'=>$now,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        log_action('Teklif eklendi', $offerNo . ' ' . (string)$cari['name']);
        audit_action('teklif', $offerId, 'eklendi', null, [
            'offer_no'=>$offerNo,
            'offer_date'=>$offerDate,
            'customer_name'=>(string)$cari['name'],
            'product_barcode'=>$barcode,
            'product_name'=>$productName,
            'product_type'=>$productType,
            'quantity'=>$quantity,
            'quantity_label'=>'DZ',
            'unit_price'=>$unitPrice,
            'grand_total'=>$subtotal,
            'currency'=>'TL',
        ], $offerNo);

        $pdo->commit();
        return ['created'=>true, 'existing'=>false, 'offer_id'=>$offerId, 'offer_no'=>$offerNo];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['created'=>false, 'existing'=>false, 'offer_id'=>0, 'error'=>$e->getMessage()];
    }
}
