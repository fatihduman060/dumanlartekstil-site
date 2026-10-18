<?php
require_once __DIR__ . '/depo-cikis-lib.php';
require_once __DIR__ . '/hareket-satis-db.php';

require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

function muf_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function muf_norm(string $value): string
{
    $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?: $value));
    return mb_strtolower($value, 'UTF-8');
}

function muf_unique_map_add(array &$map, string $key, int $id): void
{
    if ($key === '' || $id <= 0) return;
    if (!array_key_exists($key, $map)) {
        $map[$key] = $id;
        return;
    }
    if ((int)$map[$key] !== $id) $map[$key] = 0;
}

function muf_product_maps(array $products): array
{
    $maps = ['barcode'=>[], 'article'=>[], 'name_type'=>[], 'name'=>[], 'by_id'=>[]];
    foreach ($products as $product) {
        $id = (int)($product['id'] ?? 0);
        if ($id <= 0) continue;
        $name = trim((string)($product['name'] ?? ''));
        $type = trim((string)($product['product_type'] ?? ''));
        $rawBarcode = trim((string)($product['barcode'] ?? ''));
        $barcode = teklif_normalize_barcode($rawBarcode, $name, $type);
        $article = teklif_article_from_text($rawBarcode) ?: teklif_article_from_text($name) ?: teklif_article_from_text($type);
        $normName = muf_norm($name);
        $normType = muf_norm($type);

        $maps['by_id'][$id] = [
            'id'=>$id,
            'barcode'=>$barcode,
            'name'=>$name,
            'product_type'=>$type,
        ];
        muf_unique_map_add($maps['barcode'], $barcode, $id);
        muf_unique_map_add($maps['article'], $article, $id);
        muf_unique_map_add($maps['name_type'], $normName !== '' ? $normName . '|' . $normType : '', $id);
        muf_unique_map_add($maps['name'], $normName, $id);
    }
    return $maps;
}

function muf_product_id(array $maps, string $barcode, string $name, string $type): int
{
    $rawBarcode = trim($barcode);
    $name = trim($name);
    $type = trim($type);

    if ($rawBarcode !== '') {
        $normalized = teklif_normalize_barcode($rawBarcode, $name, $type);
        $id = (int)($maps['barcode'][$normalized] ?? 0);
        // Barkod yazılmış bir geçmiş kaydını, barkod eşleşmiyorsa yalnız isim benziyor
        // diye başka ürüne bağlama. Yanlış müşteri fiyatı liste fiyatının önüne geçmesin.
        return $id > 0 ? $id : 0;
    }

    $article = teklif_article_from_text($name) ?: teklif_article_from_text($type);
    if ($article !== '') {
        $id = (int)($maps['article'][$article] ?? 0);
        if ($id > 0) return $id;
    }

    $normName = muf_norm($name);
    $normType = muf_norm($type);
    if ($normName !== '') {
        $id = (int)($maps['name_type'][$normName . '|' . $normType] ?? 0);
        if ($id > 0) return $id;
        $id = (int)($maps['name'][$normName] ?? 0);
        if ($id > 0) return $id;
    }
    return 0;
}

try {
    $cariId = (int)($_GET['cari_id'] ?? 0);
    $currency = strtoupper(trim((string)($_GET['currency'] ?? 'TL')));
    if (!in_array($currency, ['TL','USD','EUR'], true)) muf_json(['ok'=>false, 'error'=>'Geçersiz para birimi.'], 400);
    if ($cariId <= 0) {
        muf_json(['ok' => true, 'items' => []]);
    }

    teklif_db_ensure();
    depo_cikis_db_ensure();
    hareket_satis_db_ensure();
    $pdo = db();
    $productMaps = muf_product_maps(teklif_products_for_select());

    $movementCurrency = depo_cikis_table_has_column($pdo, 'movements', 'currency')
        ? "COALESCE(NULLIF(UPPER(TRIM(m.currency)),''),'TL')" : "'TL'";
    $sql = "
        SELECT
            'teklif' AS source_type,
            o.id AS source_id,
            o.offer_date AS document_date,
            o.updated_at AS updated_at,
            oi.id AS item_id,
            COALESCE(oi.product_barcode,'') AS product_barcode,
            COALESCE(oi.product_name,'') AS product_name,
            COALESCE(oi.product_type,'') AS product_type,
            oi.unit_price AS unit_price
        FROM offer_items oi
        INNER JOIN offers o ON o.id=oi.offer_id
        WHERE o.cari_id=?
          AND COALESCE(o.is_deleted,0)=0
          AND COALESCE(NULLIF(UPPER(TRIM(o.currency)),''),'TL')=?
          AND oi.unit_price>0

        UNION ALL

        SELECT
            'depo' AS source_type,
            w.id AS source_id,
            w.dispatch_date AS document_date,
            w.updated_at AS updated_at,
            wi.id AS item_id,
            COALESCE(wi.product_barcode,'') AS product_barcode,
            COALESCE(wi.product_name,'') AS product_name,
            COALESCE(wi.product_type,'') AS product_type,
            wi.unit_price AS unit_price
        FROM warehouse_dispatch_items wi
        INNER JOIN warehouse_dispatches w ON w.id=wi.dispatch_id
        WHERE w.cari_id=?
          AND COALESCE(w.is_cancelled,0)=0
          AND COALESCE(NULLIF(UPPER(TRIM(w.currency)),''),'TL')=?
          AND wi.unit_price>0

        UNION ALL

        SELECT
            'hareket' AS source_type,
            m.id AS source_id,
            m.movement_date AS document_date,
            ms.updated_at AS updated_at,
            msi.id AS item_id,
            COALESCE(msi.product_barcode,'') AS product_barcode,
            COALESCE(msi.product_name,'') AS product_name,
            '' AS product_type,
            msi.unit_price AS unit_price
        FROM movement_sale_items msi
        INNER JOIN movement_sales ms ON ms.movement_id=msi.movement_id
        INNER JOIN movements m ON m.id=ms.movement_id
        WHERE m.cari_id=?
          AND COALESCE(m.is_cancelled,0)=0
          AND m.movement_type='alacak'
          AND {$movementCurrency}=?
          AND msi.unit_price>0

        ORDER BY updated_at DESC, document_date DESC, source_id DESC, item_id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$cariId, $currency, $cariId, $currency, $cariId, $currency]);
    $rows = $stmt->fetchAll() ?: [];

    // Bilinen ürünlerde geçmiş fiyatı gerçek offer_products kimliğiyle tekilleştir.
    // Kimliği çözülemeyen özel/serbest ürünlerde eski isim/barkod davranışını koru.
    $seenProduct = [];
    $seenBarcode = [];
    $seenNameType = [];
    $seenName = [];
    $items = [];

    foreach ($rows as $row) {
        $barcode = trim((string)($row['product_barcode'] ?? ''));
        $name = trim((string)($row['product_name'] ?? ''));
        $type = trim((string)($row['product_type'] ?? ''));
        $price = (float)($row['unit_price'] ?? 0);
        if ($price <= 0 || ($barcode === '' && $name === '')) continue;

        $productId = muf_product_id($productMaps, $barcode, $name, $type);
        if ($productId > 0) {
            if (isset($seenProduct[$productId])) continue;
            $seenProduct[$productId] = true;
            // Eski fişte barkod/ad hatalı veya eksik olsa bile tarayıcıya ürün kartındaki
            // kanonik bilgiyi gönder. Böylece geçmiş fiyat yalnız aynı gerçek ürünü ezer.
            $canonical = $productMaps['by_id'][$productId] ?? null;
            if ($canonical) {
                $barcode = (string)$canonical['barcode'];
                $name = (string)$canonical['name'];
                $type = (string)$canonical['product_type'];
            }
        } else {
            $normName = muf_norm($name);
            $normType = muf_norm($type);
            $barcodeKey = $barcode !== '' ? $barcode : '';
            $nameTypeKey = $normName !== '' ? $normName . '|' . $normType : '';
            $nameKey = $normName;

            $isNew = false;
            if ($barcodeKey !== '' && !isset($seenBarcode[$barcodeKey])) {
                $seenBarcode[$barcodeKey] = true;
                $isNew = true;
            }
            if ($nameTypeKey !== '' && !isset($seenNameType[$nameTypeKey])) {
                $seenNameType[$nameTypeKey] = true;
                $isNew = true;
            }
            if ($nameKey !== '' && !isset($seenName[$nameKey])) {
                $seenName[$nameKey] = true;
                $isNew = true;
            }
            if (!$isNew) continue;
        }

        $items[] = [
            'product_id' => $productId,
            'barcode' => $barcode,
            'name' => $name,
            'product_type' => $type,
            'unit_price' => $price,
            'source_type' => (string)$row['source_type'],
            'source_id' => (int)$row['source_id'],
            'document_date' => (string)$row['document_date'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }

    muf_json(['ok' => true, 'cari_id' => $cariId, 'items' => $items]);
} catch (Throwable $e) {
    muf_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
