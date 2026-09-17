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

try {
    $cariId = (int)($_GET['cari_id'] ?? 0);
    if ($cariId <= 0) {
        muf_json(['ok' => true, 'items' => []]);
    }

    teklif_db_ensure();
    depo_cikis_db_ensure();
    hareket_satis_db_ensure();
    $pdo = db();

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
          AND msi.unit_price>0

        ORDER BY updated_at DESC, document_date DESC, source_id DESC, item_id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$cariId, $cariId, $cariId]);
    $rows = $stmt->fetchAll() ?: [];

    // Aynı ürün geçmişte birçok kez kullanılmış olabilir. Sorgu en yeniden eskiye
    // sıralı geldiği için her barkod/ürün adına yalnızca ilk (son kullanılan) fiyatı bırak.
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

        $normName = mb_strtolower(preg_replace('/\s+/u', ' ', $name) ?: $name, 'UTF-8');
        $normType = mb_strtolower(preg_replace('/\s+/u', ' ', $type) ?: $type, 'UTF-8');
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

        $items[] = [
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
