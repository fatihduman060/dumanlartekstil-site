<?php

function stok_db_ensure(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        article_code TEXT NOT NULL UNIQUE,
        product_name TEXT NOT NULL,
        product_info TEXT,
        unit TEXT NOT NULL DEFAULT 'DZ',
        is_active INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_movements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        direction TEXT NOT NULL,
        quantity_dozen REAL NOT NULL DEFAULT 0,
        movement_date TEXT NOT NULL,
        source_type TEXT NOT NULL DEFAULT 'manual',
        source_id INTEGER,
        description TEXT,
        is_cancelled INTEGER NOT NULL DEFAULT 0,
        cancelled_at TEXT,
        cancelled_by INTEGER,
        cancel_reason TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(product_id) REFERENCES stock_products(id) ON DELETE RESTRICT,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_stock_lines (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        quantity_dozen REAL NOT NULL DEFAULT 0,
        stock_movement_id INTEGER,
        is_cancelled INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(invoice_id, product_id),
        FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
        FOREIGN KEY(product_id) REFERENCES stock_products(id) ON DELETE RESTRICT,
        FOREIGN KEY(stock_movement_id) REFERENCES stock_movements(id) ON DELETE SET NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stock_movements_product_date ON stock_movements(product_id, movement_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stock_movements_source ON stock_movements(source_type, source_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invoice_stock_invoice ON invoice_stock_lines(invoice_id)');
}

function stok_urunler(bool $activeOnly = true): array
{
    stok_db_ensure();
    $sql = 'SELECT p.*, COALESCE(SUM(CASE WHEN sm.is_cancelled=0 AND sm.direction=\'in\' THEN sm.quantity_dozen WHEN sm.is_cancelled=0 AND sm.direction=\'out\' THEN -sm.quantity_dozen ELSE 0 END),0) AS stock_dozen
        FROM stock_products p LEFT JOIN stock_movements sm ON sm.product_id=p.id';
    if ($activeOnly) $sql .= ' WHERE p.is_active=1';
    $sql .= ' GROUP BY p.id ORDER BY p.article_code, p.product_name';
    return db()->query($sql)->fetchAll() ?: [];
}

function stok_urun(int $id): ?array
{
    stok_db_ensure();
    $stmt = db()->prepare('SELECT * FROM stock_products WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function stok_bakiye(int $productId, int $excludeMovementId = 0): float
{
    stok_db_ensure();
    $sql = "SELECT COALESCE(SUM(CASE WHEN direction='in' THEN quantity_dozen WHEN direction='out' THEN -quantity_dozen ELSE 0 END),0)
        FROM stock_movements WHERE product_id=? AND is_cancelled=0";
    $params = [$productId];
    if ($excludeMovementId > 0) {
        $sql .= ' AND id<>?';
        $params[] = $excludeMovementId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return round((float)$stmt->fetchColumn(), 2);
}

function stok_fatura_post_satirlari(array $post): array
{
    $ids = is_array($post['stock_product_id'] ?? null) ? $post['stock_product_id'] : [];
    $quantities = is_array($post['stock_quantity_dozen'] ?? null) ? $post['stock_quantity_dozen'] : [];
    $lines = [];
    foreach ($ids as $index => $rawId) {
        $productId = (int)$rawId;
        $quantity = max(0, decimal_from_input($quantities[$index] ?? 0));
        if ($productId <= 0 || $quantity <= 0) continue;
        if (!isset($lines[$productId])) $lines[$productId] = 0.0;
        $lines[$productId] = round($lines[$productId] + $quantity, 2);
    }
    return $lines;
}

function stok_fatura_satirlari(int $invoiceId): array
{
    stok_db_ensure();
    $stmt = db()->prepare("SELECT isl.*, p.article_code, p.product_name
        FROM invoice_stock_lines isl JOIN stock_products p ON p.id=isl.product_id
        WHERE isl.invoice_id=? AND isl.is_cancelled=0 ORDER BY isl.id");
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll() ?: [];
}

function stok_fatura_dogrula(int $invoiceId, string $direction, array $lines): void
{
    stok_db_ensure();
    if ($direction !== 'giden') return;
    foreach ($lines as $productId => $quantity) {
        $product = stok_urun((int)$productId);
        if (!$product || (int)$product['is_active'] !== 1) throw new RuntimeException('Seçilen stok ürünü bulunamadı veya pasif.');
        $oldStmt = db()->prepare('SELECT quantity_dozen, stock_movement_id FROM invoice_stock_lines WHERE invoice_id=? AND product_id=? AND is_cancelled=0 LIMIT 1');
        $oldStmt->execute([$invoiceId, (int)$productId]);
        $old = $oldStmt->fetch() ?: null;
        $available = stok_bakiye((int)$productId, (int)($old['stock_movement_id'] ?? 0));
        if ($quantity > $available + 0.004) {
            throw new RuntimeException(($product['article_code'] ?? '') . ' · ' . ($product['product_name'] ?? '') . ' için stok yetersiz. Mevcut: ' . number_format($available, 2, ',', '.') . ' DZ');
        }
    }
}

function stok_fatura_senkronla(int $invoiceId, string $direction, string $invoiceDate, array $lines, bool $cancelled = false): void
{
    stok_db_ensure();
    if ($direction !== 'giden' || $cancelled) $lines = [];
    $pdo = db();
    $started = !$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();
    try {
        $existingStmt = $pdo->prepare('SELECT * FROM invoice_stock_lines WHERE invoice_id=?');
        $existingStmt->execute([$invoiceId]);
        $existing = [];
        foreach ($existingStmt->fetchAll() as $row) $existing[(int)$row['product_id']] = $row;

        foreach ($lines as $productId => $quantity) {
            $productId = (int)$productId;
            $old = $existing[$productId] ?? null;
            $movementId = (int)($old['stock_movement_id'] ?? 0);
            $description = 'Satış faturası #' . $invoiceId . ' stok çıkışı';
            if ($old) {
                $pdo->prepare('UPDATE invoice_stock_lines SET quantity_dozen=?, is_cancelled=0, updated_at=? WHERE id=?')
                    ->execute([$quantity, now(), (int)$old['id']]);
                if ($movementId > 0) {
                    $pdo->prepare("UPDATE stock_movements SET product_id=?, direction='out', quantity_dozen=?, movement_date=?, source_type='invoice_sale', source_id=?, description=?, is_cancelled=0, cancelled_at=NULL, cancelled_by=NULL, cancel_reason=NULL, updated_at=? WHERE id=?")
                        ->execute([$productId, $quantity, $invoiceDate, (int)$old['id'], $description, now(), $movementId]);
                } else {
                    $pdo->prepare("INSERT INTO stock_movements (product_id,direction,quantity_dozen,movement_date,source_type,source_id,description,created_by,created_at,updated_at) VALUES (?,'out',?,?, 'invoice_sale',?,?, ?,?,?)")
                        ->execute([$productId, $quantity, $invoiceDate, (int)$old['id'], $description, current_user()['id'] ?? null, now(), now()]);
                    $movementId = (int)$pdo->lastInsertId();
                    $pdo->prepare('UPDATE invoice_stock_lines SET stock_movement_id=?, updated_at=? WHERE id=?')->execute([$movementId, now(), (int)$old['id']]);
                }
            } else {
                $pdo->prepare('INSERT INTO invoice_stock_lines (invoice_id,product_id,quantity_dozen,created_at,updated_at) VALUES (?,?,?,?,?)')
                    ->execute([$invoiceId, $productId, $quantity, now(), now()]);
                $lineId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO stock_movements (product_id,direction,quantity_dozen,movement_date,source_type,source_id,description,created_by,created_at,updated_at) VALUES (?,'out',?,?, 'invoice_sale',?,?, ?,?,?)")
                    ->execute([$productId, $quantity, $invoiceDate, $lineId, $description, current_user()['id'] ?? null, now(), now()]);
                $movementId = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE invoice_stock_lines SET stock_movement_id=?, updated_at=? WHERE id=?')->execute([$movementId, now(), $lineId]);
            }
            unset($existing[$productId]);
        }

        foreach ($existing as $old) {
            if ((int)$old['is_cancelled'] === 1) continue;
            $pdo->prepare('UPDATE invoice_stock_lines SET is_cancelled=1, updated_at=? WHERE id=?')->execute([now(), (int)$old['id']]);
            if (!empty($old['stock_movement_id'])) {
                $pdo->prepare('UPDATE stock_movements SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=? WHERE id=?')
                    ->execute([now(), current_user()['id'] ?? null, 'Satış faturası ürün satırı kaldırıldı veya fatura iptal edildi', now(), (int)$old['stock_movement_id']]);
            }
        }
        if ($started) $pdo->commit();
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
