<?php

function masraf_fisi_tablosunu_hazirla(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS expense_receipts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        receipt_date TEXT NOT NULL,
        vendor TEXT,
        category TEXT NOT NULL DEFAULT 'diger',
        total_amount REAL NOT NULL DEFAULT 0,
        vat_rate REAL NOT NULL DEFAULT 0,
        subtotal REAL NOT NULL DEFAULT 0,
        vat_amount REAL NOT NULL DEFAULT 0,
        include_in_vat INTEGER NOT NULL DEFAULT 1,
        note TEXT,
        created_by INTEGER,
        created_at TEXT,
        updated_by INTEGER,
        updated_at TEXT
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_expense_receipts_date ON expense_receipts(receipt_date)");
}

function masraf_fisi_period(string $value): string
{
    $value = trim($value);
    return preg_match('/^\d{4}-\d{2}$/', $value) ? $value : date('Y-m');
}

function masraf_fisi_kategorileri(): array
{
    return [
        'yemek' => 'Yemek / Ağırlama',
        'yol' => 'Yol / Ulaşım',
        'akaryakit' => 'Akaryakıt',
        'market' => 'Market / Fabrika Alışverişi',
        'kirtasiye' => 'Kırtasiye / Ofis',
        'bakim' => 'Bakım / Küçük Malzeme',
        'diger' => 'Diğer',
    ];
}

function masraf_fisi_ozeti(string $period): array
{
    masraf_fisi_tablosunu_hazirla();
    $start = $period . '-01';
    $end = date('Y-m-t', strtotime($start));
    $stmt = db()->prepare("SELECT
        COUNT(*) AS receipt_count,
        COALESCE(SUM(total_amount),0) AS total_amount,
        COALESCE(SUM(subtotal),0) AS subtotal,
        COALESCE(SUM(CASE WHEN include_in_vat=1 THEN vat_amount ELSE 0 END),0) AS vat_amount
        FROM expense_receipts
        WHERE receipt_date BETWEEN ? AND ?");
    $stmt->execute([$start, $end]);
    $row = $stmt->fetch() ?: [];
    return [
        'count' => (int)($row['receipt_count'] ?? 0),
        'total' => (float)($row['total_amount'] ?? 0),
        'subtotal' => (float)($row['subtotal'] ?? 0),
        'vat' => (float)($row['vat_amount'] ?? 0),
    ];
}

function masraf_fisi_satirlari(string $period): array
{
    masraf_fisi_tablosunu_hazirla();
    $start = $period . '-01';
    $end = date('Y-m-t', strtotime($start));
    $stmt = db()->prepare("SELECT * FROM expense_receipts WHERE receipt_date BETWEEN ? AND ? ORDER BY receipt_date DESC, id DESC LIMIT 200");
    $stmt->execute([$start, $end]);
    return $stmt->fetchAll() ?: [];
}
