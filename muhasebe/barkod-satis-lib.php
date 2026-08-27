<?php

require_once __DIR__ . '/magaza-satis-lib.php';
require_once __DIR__ . '/magaza-odeme-dagilim-lib.php';

function pos_store_credit_ensure(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS store_credit_people (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        search_name TEXT NOT NULL UNIQUE,
        notes TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS store_credit_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        person_id INTEGER NOT NULL,
        entry_type TEXT NOT NULL,
        amount REAL NOT NULL DEFAULT 0,
        entry_date TEXT NOT NULL,
        payment_method TEXT,
        daily_breakdown_id INTEGER,
        description TEXT,
        is_cancelled INTEGER NOT NULL DEFAULT 0,
        cancelled_at TEXT,
        cancelled_by INTEGER,
        cancel_reason TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(person_id) REFERENCES store_credit_people(id) ON DELETE RESTRICT,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_store_credit_person_date ON store_credit_entries(person_id,entry_date)');
}

function pos_credit_people(): array
{
    pos_store_credit_ensure();
    return db()->query("SELECT id, full_name FROM store_credit_people WHERE is_active=1 ORDER BY full_name ASC")->fetchAll() ?: [];
}

function pos_credit_person(int $personId): ?array
{
    if ($personId <= 0) return null;
    pos_store_credit_ensure();
    $stmt = db()->prepare("SELECT id, full_name FROM store_credit_people WHERE id=? AND is_active=1 LIMIT 1");
    $stmt->execute([$personId]);
    return $stmt->fetch() ?: null;
}

function pos_mark_old_offer_products(): void
{
    $pdo = db();
    $migrationKey = 'migration_pos_offer_product_source_v1';
    if (setting_get($migrationKey, '0') === '1') return;

    $offerProductsExist = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='offer_products' LIMIT 1")->fetchColumn();
    if ($offerProductsExist) {
        // Eski sistem teklif ürünlerini POS'a otomatik kopyalıyordu. Yalnızca otomatik
        // oluştuğu güvenle anlaşılabilen kayıtları ayır; satış geçmişini silme.
        $pdo->exec("UPDATE pos_products
            SET product_source='offer_import', updated_at=COALESCE(updated_at, datetime('now'))
            WHERE COALESCE(created_by,0)=0
              AND COALESCE(product_source,'pos')='pos'
              AND EXISTS (
                  SELECT 1 FROM offer_products op
                  WHERE TRIM(COALESCE(op.barcode,''))=TRIM(COALESCE(pos_products.barcode,''))
                    AND TRIM(COALESCE(op.name,''))=TRIM(COALESCE(pos_products.name,''))
                    AND ABS(COALESCE(op.default_unit_price,0)-COALESCE(pos_products.sale_price,0))<0.01
              )");
    }
    setting_set($migrationKey, '1');
}

function pos_db_ensure(): void
{
    $pdo = db();
    pos_store_credit_ensure();
    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        barcode TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        unit TEXT NOT NULL DEFAULT 'Adet',
        sale_price REAL NOT NULL DEFAULT 0,
        vat_rate REAL NOT NULL DEFAULT 10,
        stock_quantity REAL NOT NULL DEFAULT 0,
        track_stock INTEGER NOT NULL DEFAULT 1,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    ensure_column($pdo, 'pos_products', 'variant_name', 'TEXT');
    ensure_column($pdo, 'pos_products', 'product_source', "TEXT NOT NULL DEFAULT 'pos'");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_pos_products_barcode ON pos_products(barcode)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pos_products_name ON pos_products(name)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pos_products_source ON pos_products(product_source,is_active)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        receipt_no TEXT UNIQUE,
        sale_date TEXT NOT NULL,
        sale_time TEXT NOT NULL,
        customer_name TEXT,
        cari_id INTEGER,
        credit_person_id INTEGER,
        payment_method TEXT NOT NULL,
        subtotal REAL NOT NULL DEFAULT 0,
        discount_amount REAL NOT NULL DEFAULT 0,
        vat_amount REAL NOT NULL DEFAULT 0,
        grand_total REAL NOT NULL DEFAULT 0,
        note TEXT,
        cari_movement_id INTEGER,
        credit_entry_id INTEGER,
        is_cancelled INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        FOREIGN KEY(cari_id) REFERENCES cariler(id) ON DELETE SET NULL,
        FOREIGN KEY(credit_person_id) REFERENCES store_credit_people(id) ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    ensure_column($pdo, 'pos_sales', 'credit_person_id', 'INTEGER');
    ensure_column($pdo, 'pos_sales', 'credit_entry_id', 'INTEGER');
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pos_sales_date ON pos_sales(sale_date, id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pos_sales_credit_person ON pos_sales(credit_person_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pos_sales_credit_entry ON pos_sales(credit_entry_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_sale_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        barcode TEXT NOT NULL,
        product_name TEXT NOT NULL,
        quantity REAL NOT NULL DEFAULT 0,
        unit_price REAL NOT NULL DEFAULT 0,
        vat_rate REAL NOT NULL DEFAULT 0,
        line_total REAL NOT NULL DEFAULT 0,
        FOREIGN KEY(sale_id) REFERENCES pos_sales(id) ON DELETE CASCADE,
        FOREIGN KEY(product_id) REFERENCES pos_products(id) ON DELETE RESTRICT
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pos_sale_items_sale ON pos_sale_items(sale_id)");

    // ÖNEMLİ: offer_products artık pos_products içine aktarılmaz.
    // Barkodlu Satış kendi ürün havuzunu kullanır.
    pos_mark_old_offer_products();
}

function pos_products(string $query = ''): array
{
    pos_db_ensure();
    $query = trim($query);
    if ($query === '') {
        return db()->query("SELECT * FROM pos_products WHERE is_active=1 AND COALESCE(product_source,'pos')='pos' ORDER BY name ASC, COALESCE(variant_name,'') ASC LIMIT 300")->fetchAll() ?: [];
    }
    $stmt = db()->prepare("SELECT * FROM pos_products WHERE is_active=1 AND COALESCE(product_source,'pos')='pos' AND (barcode=? OR name LIKE ? OR COALESCE(variant_name,'') LIKE ?) ORDER BY CASE WHEN barcode=? THEN 0 ELSE 1 END, name ASC, COALESCE(variant_name,'') ASC LIMIT 50");
    $stmt->execute([$query, '%' . $query . '%', '%' . $query . '%', $query]);
    return $stmt->fetchAll() ?: [];
}

function pos_product_by_barcode(string $barcode): ?array
{
    pos_db_ensure();
    $stmt = db()->prepare("SELECT * FROM pos_products WHERE barcode=? AND is_active=1 AND COALESCE(product_source,'pos')='pos' LIMIT 1");
    $stmt->execute([trim($barcode)]);
    return $stmt->fetch() ?: null;
}

function pos_receipt_no(int $saleId, string $saleDate): string
{
    return 'POS-' . str_replace('-', '', $saleDate) . '-' . str_pad((string)$saleId, 6, '0', STR_PAD_LEFT);
}

function pos_daily_totals_delta(string $saleDate, float $grossDelta, float $cashDelta, float $cardDelta, float $creditDelta, ?int $userId): void
{
    magaza_satis_tablosunu_hazirla();
    magaza_odeme_dagilim_tablosunu_hazirla();
    $pdo = db();
    $now = now();

    $stmt = $pdo->prepare("SELECT * FROM store_daily_sales WHERE sale_date=? LIMIT 1");
    $stmt->execute([$saleDate]);
    $daily = $stmt->fetch();
    $vatRate = 10.0;
    if ($daily) {
        $gross = round((float)$daily['gross_amount'] + $grossDelta, 2);
        $vatRate = (float)($daily['vat_rate'] ?? 10);
        $subtotal = round($gross / (1 + ($vatRate / 100)), 2);
        $vat = round($gross - $subtotal, 2);
        $pdo->prepare("UPDATE store_daily_sales SET gross_amount=?, subtotal=?, vat_amount=?, note=?, updated_by=?, updated_at=? WHERE id=?")
            ->execute([$gross, $subtotal, $vat, 'Barkodlu satışlar dahil', $userId, $now, (int)$daily['id']]);
    } else {
        $gross = round($grossDelta, 2);
        $subtotal = round($gross / 1.10, 2);
        $vat = round($gross - $subtotal, 2);
        $pdo->prepare("INSERT INTO store_daily_sales (sale_date,gross_amount,vat_rate,subtotal,vat_amount,note,created_by,created_at,updated_by,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$saleDate, $gross, $vatRate, $subtotal, $vat, 'Barkodlu satış', $userId, $now, $userId, $now]);
    }

    $stmt = $pdo->prepare("SELECT * FROM store_daily_payment_breakdown WHERE sale_date=? LIMIT 1");
    $stmt->execute([$saleDate]);
    $payment = $stmt->fetch();
    if ($payment) {
        $cash = round((float)$payment['cash_amount'] + $cashDelta, 2);
        $card = round((float)$payment['card_amount'] + $cardDelta, 2);
        $manualCredit = round((float)$payment['manual_credit_amount'] + $creditDelta, 2);
        $personnelCredit = magaza_odeme_dagilim_personel_veresiye_toplami($saleDate);
        $credit = round($manualCredit + $personnelCredit, 2);
        $total = magaza_odeme_dagilim_gunluk_toplam($cash, $card, $credit);
        $pdo->prepare("UPDATE store_daily_payment_breakdown SET cash_amount=?,card_amount=?,manual_credit_amount=?,credit_amount=?,daily_total=?,updated_by=?,updated_at=? WHERE id=?")
            ->execute([$cash, $card, $manualCredit, $credit, $total, $userId, $now, (int)$payment['id']]);
        $paymentId = (int)$payment['id'];
    } else {
        $personnelCredit = magaza_odeme_dagilim_personel_veresiye_toplami($saleDate);
        $credit = round($creditDelta + $personnelCredit, 2);
        $total = magaza_odeme_dagilim_gunluk_toplam($cashDelta, $cardDelta, $credit);
        $pdo->prepare("INSERT INTO store_daily_payment_breakdown (sale_date,cash_amount,card_amount,credit_amount,manual_credit_amount,daily_total,created_by,created_at,updated_by,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$saleDate, $cashDelta, $cardDelta, $credit, $creditDelta, $total, $userId, $now, $userId, $now]);
        $paymentId = (int)$pdo->lastInsertId();
    }
    magaza_odeme_dagilim_hareketlerini_senkronla($paymentId);
}

function pos_sale(int $id): ?array
{
    pos_db_ensure();
    $stmt = db()->prepare("SELECT s.*, c.name AS cari_name, p.full_name AS credit_person_name, u.display_name AS user_name
        FROM pos_sales s
        LEFT JOIN cariler c ON c.id=s.cari_id
        LEFT JOIN store_credit_people p ON p.id=s.credit_person_id
        LEFT JOIN users u ON u.id=s.created_by
        WHERE s.id=? LIMIT 1");
    $stmt->execute([$id]);
    $sale = $stmt->fetch();
    if (!$sale) return null;
    $stmt = db()->prepare("SELECT * FROM pos_sale_items WHERE sale_id=? ORDER BY id ASC");
    $stmt->execute([$id]);
    $sale['items'] = $stmt->fetchAll() ?: [];
    return $sale;
}

function pos_recent_sales(int $limit = 30): array
{
    pos_db_ensure();
    $limit = max(1, min(100, $limit));
    return db()->query("SELECT s.*, c.name AS cari_name, p.full_name AS credit_person_name
        FROM pos_sales s
        LEFT JOIN cariler c ON c.id=s.cari_id
        LEFT JOIN store_credit_people p ON p.id=s.credit_person_id
        WHERE s.is_cancelled=0
        ORDER BY s.sale_date DESC,s.sale_time DESC,s.id DESC LIMIT " . $limit)->fetchAll() ?: [];
}
