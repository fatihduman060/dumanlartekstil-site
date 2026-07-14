<?php

function magaza_satis_tablosunu_hazirla(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS store_daily_sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_date TEXT NOT NULL UNIQUE,
        gross_amount REAL NOT NULL DEFAULT 0,
        vat_rate REAL NOT NULL DEFAULT 10,
        subtotal REAL NOT NULL DEFAULT 0,
        vat_amount REAL NOT NULL DEFAULT 0,
        note TEXT,
        created_by INTEGER,
        created_at TEXT,
        updated_by INTEGER,
        updated_at TEXT
    )");
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_store_daily_sales_date ON store_daily_sales(sale_date)");
}

function magaza_satis_period(string $value): string
{
    $value = trim($value);
    return preg_match('/^\d{4}-\d{2}$/', $value) ? $value : date('Y-m');
}

function magaza_satis_ozeti(string $period): array
{
    magaza_satis_tablosunu_hazirla();
    $start = $period . '-01';
    $end = date('Y-m-t', strtotime($start));
    $stmt = db()->prepare("SELECT
        COUNT(*) AS sale_day_count,
        COALESCE(SUM(gross_amount),0) AS gross_amount,
        COALESCE(SUM(subtotal),0) AS subtotal,
        COALESCE(SUM(vat_amount),0) AS vat_amount
        FROM store_daily_sales
        WHERE sale_date BETWEEN ? AND ?");
    $stmt->execute([$start, $end]);
    $row = $stmt->fetch() ?: [];
    return [
        'count' => (int)($row['sale_day_count'] ?? 0),
        'gross' => (float)($row['gross_amount'] ?? 0),
        'subtotal' => (float)($row['subtotal'] ?? 0),
        'vat' => (float)($row['vat_amount'] ?? 0),
    ];
}

function magaza_satis_satirlari(string $period): array
{
    magaza_satis_tablosunu_hazirla();
    $start = $period . '-01';
    $end = date('Y-m-t', strtotime($start));
    $stmt = db()->prepare("SELECT * FROM store_daily_sales WHERE sale_date BETWEEN ? AND ? ORDER BY sale_date DESC, id DESC LIMIT 100");
    $stmt->execute([$start, $end]);
    return $stmt->fetchAll() ?: [];
}
