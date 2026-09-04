<?php

function magaza_satis_kolonu_var_mi(string $column): bool
{
    $rows = db()->query("PRAGMA table_info(store_daily_sales)")->fetchAll() ?: [];
    foreach ($rows as $row) {
        if ((string)($row['name'] ?? '') === $column) return true;
    }
    return false;
}

function magaza_satis_pos_tablosu_var_mi(): bool
{
    return (bool)db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='pos_sales' LIMIT 1")->fetchColumn();
}

function magaza_satis_pos_kart_toplami(string $saleDate): float
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate) || !magaza_satis_pos_tablosu_var_mi()) return 0.0;
    $stmt = db()->prepare("SELECT COALESCE(SUM(grand_total),0) FROM pos_sales WHERE sale_date=? AND payment_method='card' AND COALESCE(is_cancelled,0)=0");
    $stmt->execute([$saleDate]);
    return round((float)($stmt->fetchColumn() ?: 0), 2);
}

function magaza_satis_pos_kart_senkronla(string $saleDate, bool $resetManual = false, ?float $minimumCardAmount = null): float
{
    magaza_satis_tablosunu_hazirla();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate) || $saleDate < '2026-09-04') return 0.0;

    $cardAmount = magaza_satis_pos_kart_toplami($saleDate);
    if ($minimumCardAmount !== null) $cardAmount = max($cardAmount, round($minimumCardAmount, 2));

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM store_daily_sales WHERE sale_date=? LIMIT 1");
    $stmt->execute([$saleDate]);
    $row = $stmt->fetch();
    $userId = function_exists('current_user') ? (current_user()['id'] ?? null) : null;
    $now = function_exists('now') ? now() : date('Y-m-d H:i:s');

    if (!$row && $cardAmount <= 0) return 0.0;

    $manualAdjustment = 0.0;
    if ($row && !$resetManual && (int)($row['pos_card_sync'] ?? 0) === 1) {
        $manualAdjustment = round((float)($row['manual_adjustment'] ?? 0), 2);
    }
    $gross = max(0, round($cardAmount + $manualAdjustment, 2));
    $vatRate = $row ? (float)($row['vat_rate'] ?? 10) : 10.0;
    if ($vatRate <= 0) $vatRate = 10.0;
    $subtotal = round($gross / (1 + ($vatRate / 100)), 2);
    $vat = round($gross - $subtotal, 2);

    $note = trim((string)($row['note'] ?? ''));
    $autoNotes = ['Barkodlu satış', 'Barkodlu satışlar dahil', 'Barkodlu satış - Kredi Kartı otomatik'];
    if ($note === '' || in_array($note, $autoNotes, true)) $note = 'Barkodlu satış - Kredi Kartı otomatik';

    if ($row) {
        $pdo->prepare("UPDATE store_daily_sales SET gross_amount=?,vat_rate=?,subtotal=?,vat_amount=?,note=?,pos_card_amount=?,manual_adjustment=?,pos_card_sync=1,updated_by=?,updated_at=? WHERE id=?")
            ->execute([$gross,$vatRate,$subtotal,$vat,$note,$cardAmount,$manualAdjustment,$userId,$now,(int)$row['id']]);
    } else {
        $pdo->prepare("INSERT INTO store_daily_sales (sale_date,gross_amount,vat_rate,subtotal,vat_amount,note,pos_card_amount,manual_adjustment,pos_card_sync,created_by,created_at,updated_by,updated_at) VALUES (?,?,?,?,?,?,?,?,1,?,?,?,?)")
            ->execute([$saleDate,$gross,$vatRate,$subtotal,$vat,$note,$cardAmount,$manualAdjustment,$userId,$now,$userId,$now]);
    }
    return $gross;
}

function magaza_satis_tablosunu_hazirla(): void
{
    static $running = false;
    if ($running) return;
    $running = true;
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS store_daily_sales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_date TEXT NOT NULL UNIQUE,
            gross_amount REAL NOT NULL DEFAULT 0,
            vat_rate REAL NOT NULL DEFAULT 10,
            subtotal REAL NOT NULL DEFAULT 0,
            vat_amount REAL NOT NULL DEFAULT 0,
            note TEXT,
            pos_card_amount REAL NOT NULL DEFAULT 0,
            manual_adjustment REAL NOT NULL DEFAULT 0,
            pos_card_sync INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER,
            created_at TEXT,
            updated_by INTEGER,
            updated_at TEXT
        )");
        if (!magaza_satis_kolonu_var_mi('pos_card_amount')) $pdo->exec("ALTER TABLE store_daily_sales ADD COLUMN pos_card_amount REAL NOT NULL DEFAULT 0");
        if (!magaza_satis_kolonu_var_mi('manual_adjustment')) $pdo->exec("ALTER TABLE store_daily_sales ADD COLUMN manual_adjustment REAL NOT NULL DEFAULT 0");
        if (!magaza_satis_kolonu_var_mi('pos_card_sync')) $pdo->exec("ALTER TABLE store_daily_sales ADD COLUMN pos_card_sync INTEGER NOT NULL DEFAULT 0");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_store_daily_sales_date ON store_daily_sales(sale_date)");

        if (magaza_satis_pos_tablosu_var_mi()) {
            $cardExpr = "ROUND(COALESCE((SELECT SUM(grand_total) FROM pos_sales WHERE sale_date=NEW.sale_date AND payment_method='card' AND COALESCE(is_cancelled,0)=0),0),2)";
            $adjustExpr = "CASE WHEN COALESCE(pos_card_sync,0)=1 THEN COALESCE(manual_adjustment,0) ELSE 0 END";
            $grossExpr = "MAX(0, ROUND(({$cardExpr}) + ({$adjustExpr}),2))";
            $subtotalExpr = "ROUND(({$grossExpr}) / (1 + (COALESCE(vat_rate,10) / 100.0)),2)";
            $vatExpr = "ROUND(({$grossExpr}) - ({$subtotalExpr}),2)";

            $pdo->exec("CREATE TRIGGER IF NOT EXISTS trg_store_daily_sales_pos_card_insert_v1
                AFTER INSERT ON store_daily_sales
                WHEN NEW.sale_date >= '2026-09-04' AND NEW.note IN ('Barkodlu satış','Barkodlu satışlar dahil')
                BEGIN
                    UPDATE store_daily_sales
                    SET pos_card_sync=1,
                        pos_card_amount={$cardExpr},
                        manual_adjustment={$adjustExpr},
                        gross_amount={$grossExpr},
                        subtotal={$subtotalExpr},
                        vat_amount={$vatExpr},
                        note='Barkodlu satış - Kredi Kartı otomatik'
                    WHERE id=NEW.id;
                END");

            $pdo->exec("CREATE TRIGGER IF NOT EXISTS trg_store_daily_sales_pos_card_update_v1
                AFTER UPDATE OF gross_amount,note ON store_daily_sales
                WHEN NEW.sale_date >= '2026-09-04' AND NEW.note IN ('Barkodlu satış','Barkodlu satışlar dahil')
                BEGIN
                    UPDATE store_daily_sales
                    SET pos_card_sync=1,
                        pos_card_amount={$cardExpr},
                        manual_adjustment={$adjustExpr},
                        gross_amount={$grossExpr},
                        subtotal={$subtotalExpr},
                        vat_amount={$vatExpr},
                        note='Barkodlu satış - Kredi Kartı otomatik'
                    WHERE id=NEW.id;
                END");

            if (function_exists('setting_get') && function_exists('setting_set')
                && setting_get('migration_store_z_pos_card_20260904_v1', '0') !== '1') {
                // Kullanıcının 04.09.2026 için teyit ettiği kredi kartı toplamı 15.006,50 TL.
                // Aynı gün POS toplamı daha yüksekse güncel gerçek toplamı kullan.
                magaza_satis_pos_kart_senkronla('2026-09-04', true, 15006.50);
                setting_set('migration_store_z_pos_card_20260904_v1', '1');
            }
        }
    } finally {
        $running = false;
    }
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
