<?php

function magaza_odeme_dagilim_kolonu_var_mi(string $column): bool
{
    $rows = db()->query("PRAGMA table_info(store_daily_payment_breakdown)")->fetchAll() ?: [];
    foreach ($rows as $row) {
        if ((string)($row['name'] ?? '') === $column) return true;
    }
    return false;
}

function magaza_odeme_dagilim_tablosunu_hazirla(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS store_daily_payment_breakdown (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_date TEXT NOT NULL UNIQUE,
        cash_amount REAL NOT NULL DEFAULT 0,
        card_amount REAL NOT NULL DEFAULT 0,
        credit_amount REAL NOT NULL DEFAULT 0,
        credit_collection_amount REAL NOT NULL DEFAULT 0,
        cash_credit_collection_amount REAL NOT NULL DEFAULT 0,
        card_credit_collection_amount REAL NOT NULL DEFAULT 0,
        cash_change_left_amount REAL NOT NULL DEFAULT 0,
        daily_total REAL NOT NULL DEFAULT 0,
        created_by INTEGER,
        created_at TEXT,
        updated_by INTEGER,
        updated_at TEXT
    )");

    if (!magaza_odeme_dagilim_kolonu_var_mi('cash_credit_collection_amount')) {
        db()->exec("ALTER TABLE store_daily_payment_breakdown ADD COLUMN cash_credit_collection_amount REAL NOT NULL DEFAULT 0");
    }
    if (!magaza_odeme_dagilim_kolonu_var_mi('card_credit_collection_amount')) {
        db()->exec("ALTER TABLE store_daily_payment_breakdown ADD COLUMN card_credit_collection_amount REAL NOT NULL DEFAULT 0");
    }
    if (!magaza_odeme_dagilim_kolonu_var_mi('cash_change_left_amount')) {
        db()->exec("ALTER TABLE store_daily_payment_breakdown ADD COLUMN cash_change_left_amount REAL NOT NULL DEFAULT 0");
    }

    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_store_daily_payment_breakdown_date ON store_daily_payment_breakdown(sale_date)");

    // Önceki tek veresiye tahsilatı alanındaki kayıtları nakit tahsilata taşı ve eski alanı temizle.
    if (setting_get('migration_store_payment_collection_split_v1', '0') !== '1') {
        db()->exec("UPDATE store_daily_payment_breakdown
            SET cash_credit_collection_amount = COALESCE(credit_collection_amount, 0),
                card_credit_collection_amount = 0,
                credit_collection_amount = 0
            WHERE COALESCE(credit_collection_amount, 0) <> 0
              AND COALESCE(cash_credit_collection_amount, 0) = 0
              AND COALESCE(card_credit_collection_amount, 0) = 0");
        setting_set('migration_store_payment_collection_split_v1', '1');
    }
}

function magaza_odeme_dagilim_period(string $value): string
{
    $value = trim($value);
    return preg_match('/^\d{4}-\d{2}$/', $value) ? $value : date('Y-m');
}

function magaza_odeme_dagilim_gunluk_toplam(float $cash, float $card, float $credit): float
{
    // Tahsilatlar ve kasada bırakılan bozuk para geçmiş/ertesi gün bilgisidir; satış toplamına eklenmez.
    return round($cash + $card + $credit, 2);
}

function magaza_odeme_dagilim_onceki_kasa_parasi(string $saleDate): array
{
    magaza_odeme_dagilim_tablosunu_hazirla();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate) || strtotime($saleDate) === false) {
        return ['sale_date' => '', 'amount' => 0.0];
    }

    $stmt = db()->prepare("SELECT sale_date, cash_change_left_amount
        FROM store_daily_payment_breakdown
        WHERE sale_date < ?
        ORDER BY sale_date DESC, id DESC
        LIMIT 1");
    $stmt->execute([$saleDate]);
    $row = $stmt->fetch() ?: [];

    return [
        'sale_date' => (string)($row['sale_date'] ?? ''),
        'amount' => (float)($row['cash_change_left_amount'] ?? 0),
    ];
}

function magaza_odeme_dagilim_ozeti(string $period): array
{
    magaza_odeme_dagilim_tablosunu_hazirla();
    $start = $period . '-01';
    $end = date('Y-m-t', strtotime($start));
    $stmt = db()->prepare("SELECT
        COUNT(*) AS day_count,
        COALESCE(SUM(cash_amount),0) AS cash_sales_amount,
        COALESCE(SUM(card_amount),0) AS card_sales_amount,
        COALESCE(SUM(credit_amount),0) AS credit_amount,
        COALESCE(SUM(cash_credit_collection_amount),0) AS cash_credit_collection_amount,
        COALESCE(SUM(card_credit_collection_amount),0) AS card_credit_collection_amount,
        COALESCE(SUM(cash_amount + cash_credit_collection_amount),0) AS cash_total_amount,
        COALESCE(SUM(card_amount + card_credit_collection_amount),0) AS card_total_amount,
        COALESCE(SUM(daily_total),0) AS daily_total
        FROM store_daily_payment_breakdown
        WHERE sale_date BETWEEN ? AND ?");
    $stmt->execute([$start, $end]);
    $row = $stmt->fetch() ?: [];

    $cashCollection = (float)($row['cash_credit_collection_amount'] ?? 0);
    $cardCollection = (float)($row['card_credit_collection_amount'] ?? 0);

    return [
        'count' => (int)($row['day_count'] ?? 0),
        'cash_sales' => (float)($row['cash_sales_amount'] ?? 0),
        'card_sales' => (float)($row['card_sales_amount'] ?? 0),
        'cash' => (float)($row['cash_total_amount'] ?? 0),
        'card' => (float)($row['card_total_amount'] ?? 0),
        'credit' => (float)($row['credit_amount'] ?? 0),
        'cash_credit_collection' => $cashCollection,
        'card_credit_collection' => $cardCollection,
        'credit_collection' => round($cashCollection + $cardCollection, 2),
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
