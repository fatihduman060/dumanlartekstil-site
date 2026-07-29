<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/teklif-db.php';

function hareket_satis_db_ensure(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS movement_sales (
        movement_id INTEGER PRIMARY KEY,
        discount_enabled INTEGER NOT NULL DEFAULT 0,
        discount_rate REAL NOT NULL DEFAULT 0,
        discount_amount REAL NOT NULL DEFAULT 0,
        vat_enabled INTEGER NOT NULL DEFAULT 0,
        vat_rate REAL NOT NULL DEFAULT 10,
        subtotal REAL NOT NULL DEFAULT 0,
        vat_amount REAL NOT NULL DEFAULT 0,
        grand_total REAL NOT NULL DEFAULT 0,
        item_count INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(movement_id) REFERENCES movements(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS movement_sale_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        movement_id INTEGER NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        product_barcode TEXT,
        product_name TEXT NOT NULL,
        quantity REAL NOT NULL DEFAULT 0,
        unit_price REAL NOT NULL DEFAULT 0,
        line_total REAL NOT NULL DEFAULT 0,
        line_note TEXT,
        FOREIGN KEY(movement_id) REFERENCES movements(id) ON DELETE CASCADE
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_movement_sale_items_movement ON movement_sale_items(movement_id, sort_order)');
}

function hareket_satis_decimal($value): float
{
    return decimal_from_input($value);
}

function hareket_satis_parse_payload($raw): array
{
    if (is_string($raw)) $raw = json_decode($raw, true);
    if (!is_array($raw)) throw new RuntimeException('Satış detayları okunamadı.');

    $sourceItems = is_array($raw['items'] ?? null) ? $raw['items'] : [];
    $items = [];
    $subtotal = 0.0;
    $productStmt = null;

    foreach (array_slice($sourceItems, 0, 100) as $index => $row) {
        if (!is_array($row)) continue;
        $barcode = trim((string)($row['barcode'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        $quantity = hareket_satis_decimal($row['quantity'] ?? 0);
        $unitPrice = hareket_satis_decimal($row['unit_price'] ?? 0);
        $note = trim((string)($row['note'] ?? ''));

        if ($barcode === '' && $name === '' && $quantity <= 0 && $unitPrice <= 0 && $note === '') continue;
        if ($quantity <= 0) throw new RuntimeException(($index + 1) . '. ürün satırında miktar sıfırdan büyük olmalı.');
        if ($unitPrice < 0) throw new RuntimeException(($index + 1) . '. ürün satırında birim fiyat eksi olamaz.');

        if ($name === '' && $barcode !== '') {
            try {
                if (!$productStmt) $productStmt = db()->prepare('SELECT name FROM offer_products WHERE barcode=? AND is_active=1 LIMIT 1');
                $productStmt->execute([$barcode]);
                $name = trim((string)($productStmt->fetchColumn() ?: ''));
            } catch (Throwable $e) {}
        }
        if ($name === '') throw new RuntimeException(($index + 1) . '. ürün satırında ürün adı zorunludur.');

        $lineTotal = round($quantity * $unitPrice, 2);
        $subtotal += $lineTotal;
        $items[] = [
            'barcode' => $barcode,
            'name' => $name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'note' => $note,
        ];
    }

    if (!$items) throw new RuntimeException('Satış detayına en az bir ürün eklemelisin.');

    $discountEnabled = !empty($raw['discount_enabled']) ? 1 : 0;
    $discountRate = $discountEnabled ? max(0, min(100, hareket_satis_decimal($raw['discount_rate'] ?? 0))) : 0.0;
    $discountAmount = round($subtotal * $discountRate / 100, 2);
    $vatEnabled = !empty($raw['vat_enabled']) ? 1 : 0;
    $vatRate = $vatEnabled ? max(0, min(100, hareket_satis_decimal($raw['vat_rate'] ?? 10))) : 0.0;
    $vatBase = max(0, $subtotal - $discountAmount);
    $vatAmount = round($vatBase * $vatRate / 100, 2);
    $grandTotal = round($vatBase + $vatAmount, 2);

    if ($grandTotal <= 0) throw new RuntimeException('Satışın genel toplamı sıfırdan büyük olmalı.');

    return [
        'items' => $items,
        'discount_enabled' => $discountEnabled,
        'discount_rate' => $discountRate,
        'discount_amount' => $discountAmount,
        'vat_enabled' => $vatEnabled,
        'vat_rate' => $vatRate,
        'subtotal' => round($subtotal, 2),
        'vat_amount' => $vatAmount,
        'grand_total' => $grandTotal,
        'item_count' => count($items),
    ];
}

function hareket_satis_save(int $movementId, array $sale): void
{
    hareket_satis_db_ensure();
    if ($movementId <= 0) throw new RuntimeException('Satışın bağlı hareketi bulunamadı.');
    $pdo = db();
    $now = now();

    $existsStmt = $pdo->prepare('SELECT movement_id FROM movement_sales WHERE movement_id=?');
    $existsStmt->execute([$movementId]);
    $exists = (bool)$existsStmt->fetchColumn();

    if ($exists) {
        $pdo->prepare('UPDATE movement_sales SET discount_enabled=?, discount_rate=?, discount_amount=?, vat_enabled=?, vat_rate=?, subtotal=?, vat_amount=?, grand_total=?, item_count=?, updated_at=? WHERE movement_id=?')
            ->execute([$sale['discount_enabled'], $sale['discount_rate'], $sale['discount_amount'], $sale['vat_enabled'], $sale['vat_rate'], $sale['subtotal'], $sale['vat_amount'], $sale['grand_total'], $sale['item_count'], $now, $movementId]);
    } else {
        $pdo->prepare('INSERT INTO movement_sales (movement_id, discount_enabled, discount_rate, discount_amount, vat_enabled, vat_rate, subtotal, vat_amount, grand_total, item_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$movementId, $sale['discount_enabled'], $sale['discount_rate'], $sale['discount_amount'], $sale['vat_enabled'], $sale['vat_rate'], $sale['subtotal'], $sale['vat_amount'], $sale['grand_total'], $sale['item_count'], $now, $now]);
    }

    $pdo->prepare('DELETE FROM movement_sale_items WHERE movement_id=?')->execute([$movementId]);
    $itemStmt = $pdo->prepare('INSERT INTO movement_sale_items (movement_id, sort_order, product_barcode, product_name, quantity, unit_price, line_total, line_note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($sale['items'] as $index => $item) {
        $itemStmt->execute([$movementId, $index, $item['barcode'] ?: null, $item['name'], $item['quantity'], $item['unit_price'], $item['line_total'], $item['note'] ?: null]);
    }
}

function hareket_satis_load(int $movementId): ?array
{
    hareket_satis_db_ensure();
    if ($movementId <= 0) return null;
    $stmt = db()->prepare('SELECT * FROM movement_sales WHERE movement_id=?');
    $stmt->execute([$movementId]);
    $sale = $stmt->fetch();
    if (!$sale) return null;
    $stmt = db()->prepare('SELECT product_barcode AS barcode, product_name AS name, quantity, unit_price, line_total, line_note AS note FROM movement_sale_items WHERE movement_id=? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$movementId]);
    $sale['items'] = $stmt->fetchAll();
    return $sale;
}

function hareket_satis_products(): array
{
    teklif_db_ensure();
    $rows = teklif_products_for_select();
    return array_map(function ($row) {
        return [
            'barcode' => (string)($row['barcode'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'unit_price' => (float)($row['default_unit_price'] ?? 0),
        ];
    }, $rows);
}

function hareket_satis_summaries(array $ids): array
{
    hareket_satis_db_ensure();
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) { return $id > 0; })));
    if (!$ids) return [];
    $ids = array_slice($ids, 0, 500);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare('SELECT movement_id, item_count, subtotal, discount_enabled, discount_rate, discount_amount, vat_enabled, vat_rate, vat_amount, grand_total FROM movement_sales WHERE movement_id IN (' . $marks . ')');
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $row) $out[(string)$row['movement_id']] = $row;
    return $out;
}

function hareket_satis_category_is_sales(int $categoryId): bool
{
    if ($categoryId <= 0) return false;
    $stmt = db()->prepare('SELECT name FROM categories WHERE id=? LIMIT 1');
    $stmt->execute([$categoryId]);
    $name = trim((string)($stmt->fetchColumn() ?: ''));
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') === 'satış' : strtolower($name) === 'satış';
}
