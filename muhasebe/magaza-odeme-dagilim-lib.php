<?php

function magaza_odeme_dagilim_tablosunu_hazirla(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS store_daily_payment_breakdown (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_date TEXT NOT NULL UNIQUE,
        cash_amount REAL NOT NULL DEFAULT 0,
        card_amount REAL NOT NULL DEFAULT 0,
        credit_amount REAL NOT NULL DEFAULT 0,
        credit_collection_amount REAL NOT NULL DEFAULT 0,
        daily_total REAL NOT NULL DEFAULT 0,
        created_by INTEGER,
        created_at TEXT,
        updated_by INTEGER,
        updated_at TEXT
    )");
    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_store_daily_payment_breakdown_date ON store_daily_payment_breakdown(sale_date)");
}

function magaza_odeme_dagilim_period(string $value): string
{
    $value = trim($value);
    return preg_match('/^\d{4}-\d{2}$/', $value) ? $value : date('Y-m');
}

function magaza_odeme_dagilim_gunluk_toplam(float $cash, float $card, float $credit): float
{
    // Veresiye tahsilatı geçmiş satışın tahsilatıdır; bugünün satış toplamına yeniden eklenmez.
    return round($cash + $card + $credit, 2);
}

function magaza_odeme_dagilim_ozeti(string $period): array
{
    magaza_odeme_dagilim_tablosunu_hazirla();
    $start = $period . '-01';
    $end = date('Y-m-t', strtotime($start));
    $stmt = db()->prepare("SELECT
        COUNT(*) AS day_count,
        COALESCE(SUM(cash_amount),0) AS cash_amount,
        COALESCE(SUM(card_amount),0) AS card_amount,
        COALESCE(SUM(credit_amount),0) AS credit_amount,
        COALESCE(SUM(credit_collection_amount),0) AS credit_collection_amount,
        COALESCE(SUM(daily_total),0) AS daily_total
        FROM store_daily_payment_breakdown
        WHERE sale_date BETWEEN ? AND ?");
    $stmt->execute([$start, $end]);
    $row = $stmt->fetch() ?: [];

    return [
        'count' => (int)($row['day_count'] ?? 0),
        'cash' => (float)($row['cash_amount'] ?? 0),
        'card' => (float)($row['card_amount'] ?? 0),
        'credit' => (float)($row['credit_amount'] ?? 0),
        'credit_collection' => (float)($row['credit_collection_amount'] ?? 0),
        'daily_total' => (float)($row['daily_total'] ?? 0),
    ];
}

function magaza_odeme_dagilim_satirlari(string $period): array
{
    magaza_odeme_dagilim_tablosunu_hazirla();
    $start = $period . '-01';
    $end = date('Y-m-t', strtotime($start));
    $stmt = db()->prepare("SELECT * FROM store_daily_payment_breakdown WHERE sale_date BETWEEN ? AND ? ORDER BY sale_date DESC, id DESC LIMIT 100");
    $stmt->execute([$start, $end]);
    return $stmt->fetchAll() ?: [];
}
