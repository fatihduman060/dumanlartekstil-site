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
        cash_movement_id INTEGER,
        card_movement_id INTEGER,
        daily_total REAL NOT NULL DEFAULT 0,
        created_by INTEGER,
        created_at TEXT,
        updated_by INTEGER,
        updated_at TEXT
    )");

    $columns = [
        'cash_credit_collection_amount' => 'REAL NOT NULL DEFAULT 0',
        'card_credit_collection_amount' => 'REAL NOT NULL DEFAULT 0',
        'cash_change_left_amount' => 'REAL NOT NULL DEFAULT 0',
        'cash_movement_id' => 'INTEGER',
        'card_movement_id' => 'INTEGER',
    ];
    foreach ($columns as $column => $definition) {
        if (!magaza_odeme_dagilim_kolonu_var_mi($column)) {
            db()->exec("ALTER TABLE store_daily_payment_breakdown ADD COLUMN {$column} {$definition}");
        }
    }

    db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_store_daily_payment_breakdown_date ON store_daily_payment_breakdown(sale_date)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_store_daily_payment_breakdown_cash_movement ON store_daily_payment_breakdown(cash_movement_id)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_store_daily_payment_breakdown_card_movement ON store_daily_payment_breakdown(card_movement_id)");

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
    return round($cash + $card + $credit, 2);
}

function magaza_odeme_dagilim_kart_hesaba_gecis_tarihi(string $saleDate): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate) || strtotime($saleDate) === false) return '';
    return (new DateTimeImmutable($saleDate))->modify('+13 days')->format('Y-m-d');
}

function magaza_odeme_dagilim_hesap_anahtari(string $value): string
{
    $map = [
        'Ç'=>'C','Ğ'=>'G','İ'=>'I','I'=>'I','Ö'=>'O','Ş'=>'S','Ü'=>'U',
        'ç'=>'c','ğ'=>'g','ı'=>'i','i'=>'i','ö'=>'o','ş'=>'s','ü'=>'u',
    ];
    $value = strtolower(strtr(trim($value), $map));
    return preg_replace('/[^a-z0-9]+/', '', $value) ?: '';
}

function magaza_odeme_dagilim_hesap_id(string $target): int
{
    $rows = db()->query("SELECT * FROM accounts ORDER BY is_active DESC, id ASC")->fetchAll() ?: [];
    foreach ($rows as $row) {
        $nameKey = magaza_odeme_dagilim_hesap_anahtari((string)($row['name'] ?? ''));
        $bankKey = magaza_odeme_dagilim_hesap_anahtari((string)($row['bank_name'] ?? ''));
        if ($target === 'cash' && in_array($nameKey, ['anakasa','genelkasa','merkezkasa'], true)) return (int)$row['id'];
        if ($target === 'card') {
            $exact = in_array($nameKey, ['garantidumanlar','dumanlargaranti'], true);
            $combined = strpos($nameKey, 'garanti') !== false && strpos($nameKey, 'dumanlar') !== false;
            $bankMatch = strpos($bankKey, 'garanti') !== false && strpos($nameKey, 'dumanlar') !== false;
            if ($exact || $combined || $bankMatch) return (int)$row['id'];
        }
    }

    $now = now();
    if ($target === 'cash') {
        db()->prepare("INSERT INTO accounts (account_type, name, iban, bank_name, opening_balance, is_active, notes, created_at, updated_at)
            VALUES ('kasa', 'Ana Kasa', '', '', 0, 1, ?, ?, ?)")
            ->execute(['Mağaza günlük nakit girişleri için otomatik oluşturuldu.', $now, $now]);
    } else {
        db()->prepare("INSERT INTO accounts (account_type, name, iban, bank_name, opening_balance, is_active, notes, created_at, updated_at)
            VALUES ('banka', 'Garanti Dumanlar', '', 'Garanti BBVA', 0, 1, ?, ?, ?)")
            ->execute(['Mağaza kredi kartı tahsilatları için otomatik oluşturuldu.', $now, $now]);
    }
    return (int)db()->lastInsertId();
}

function magaza_odeme_dagilim_satis_kategori_id(): int
{
    $stmt = db()->prepare("SELECT id FROM categories WHERE name=? LIMIT 1");
    $stmt->execute(['Satış']);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) return $id;
    db()->prepare("INSERT OR IGNORE INTO categories (name, type, created_at) VALUES (?, 'gelir', ?)")->execute(['Satış', now()]);
    $stmt->execute(['Satış']);
    return (int)($stmt->fetchColumn() ?: 0);
}

function magaza_odeme_dagilim_hareketi_sil(int $movementId): void
{
    if ($movementId <= 0) return;
    db()->prepare("DELETE FROM account_transactions WHERE source_type='movement' AND source_id=?")->execute([$movementId]);
    db()->prepare("DELETE FROM movements WHERE id=?")->execute([$movementId]);
}

function magaza_odeme_dagilim_hareketi_yaz(int $movementId, int $accountId, float $amount, string $movementDate, string $paymentMethod, string $description, ?int $createdBy): int
{
    $amount = round($amount, 2);
    if ($amount <= 0 || $movementDate === '' || $movementDate > date('Y-m-d')) {
        magaza_odeme_dagilim_hareketi_sil($movementId);
        return 0;
    }

    $categoryId = magaza_odeme_dagilim_satis_kategori_id();
    $existing = false;
    if ($movementId > 0) {
        $stmt = db()->prepare("SELECT id FROM movements WHERE id=?");
        $stmt->execute([$movementId]);
        $existing = (bool)$stmt->fetchColumn();
    }

    if ($existing) {
        db()->prepare("UPDATE movements SET cari_id=NULL, category_id=?, account_id=?, movement_type='gelir', amount=?, movement_date=?, due_date=NULL, payment_method=?, description=?, is_cancelled=0, cancelled_at=NULL, cancelled_by=NULL, cancel_reason=NULL, updated_at=? WHERE id=?")
            ->execute([$categoryId ?: null, $accountId, $amount, $movementDate, $paymentMethod, $description, now(), $movementId]);
    } else {
        db()->prepare("INSERT INTO movements (cari_id, category_id, account_id, movement_type, amount, movement_date, due_date, payment_method, description, created_by, created_at, updated_at, is_cancelled)
            VALUES (NULL, ?, ?, 'gelir', ?, ?, NULL, ?, ?, ?, ?, ?, 0)")
            ->execute([$categoryId ?: null, $accountId, $amount, $movementDate, $paymentMethod, $description, $createdBy, now(), now()]);
        $movementId = (int)db()->lastInsertId();
    }
    sync_movement_account_transaction($movementId);
    return $movementId;
}

function magaza_odeme_dagilim_hareketlerini_senkronla(int $recordId): array
{
    magaza_odeme_dagilim_tablosunu_hazirla();
    $stmt = db()->prepare("SELECT * FROM store_daily_payment_breakdown WHERE id=?");
    $stmt->execute([$recordId]);
    $row = $stmt->fetch();
    if (!$row) return ['cash_movement_id'=>0,'card_movement_id'=>0,'card_settlement_date'=>''];

    $saleDate = (string)$row['sale_date'];
    $cardSettlementDate = magaza_odeme_dagilim_kart_hesaba_gecis_tarihi($saleDate);
    $cashAmount = round((float)$row['cash_amount'] + (float)$row['cash_credit_collection_amount'], 2);
    $cardAmount = round((float)$row['card_amount'] + (float)$row['card_credit_collection_amount'], 2);
    $userId = (int)($row['updated_by'] ?: $row['created_by'] ?: (current_user()['id'] ?? 0));
    $userId = $userId > 0 ? $userId : null;

    $cashMovementId = magaza_odeme_dagilim_hareketi_yaz(
        (int)($row['cash_movement_id'] ?? 0), magaza_odeme_dagilim_hesap_id('cash'), $cashAmount, $saleDate,
        'Nakit', 'Mağaza günlük nakit girişi / Satış tarihi: ' . $saleDate, $userId
    );
    $cardMovementId = magaza_odeme_dagilim_hareketi_yaz(
        (int)($row['card_movement_id'] ?? 0), magaza_odeme_dagilim_hesap_id('card'), $cardAmount, $cardSettlementDate,
        'Kredi Kartı', 'Mağaza kart/POS tahsilatı / Satış tarihi: ' . $saleDate . ' / Hesaba geçiş: ' . $cardSettlementDate, $userId
    );

    db()->prepare("UPDATE store_daily_payment_breakdown SET cash_movement_id=?, card_movement_id=? WHERE id=?")
        ->execute([$cashMovementId ?: null, $cardMovementId ?: null, $recordId]);
    return ['cash_movement_id'=>$cashMovementId,'card_movement_id'=>$cardMovementId,'card_settlement_date'=>$cardSettlementDate];
}

function magaza_odeme_dagilim_hareketlerini_kaldir(array $row): void
{
    magaza_odeme_dagilim_hareketi_sil((int)($row['cash_movement_id'] ?? 0));
    magaza_odeme_dagilim_hareketi_sil((int)($row['card_movement_id'] ?? 0));
}

function magaza_odeme_dagilim_vadesi_gelenleri_isle(?string $today = null): int
{
    magaza_odeme_dagilim_tablosunu_hazirla();
    $today = $today ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) $today = date('Y-m-d');

    $stmt = db()->prepare("SELECT s.id FROM store_daily_payment_breakdown s WHERE (
        (ROUND(COALESCE(s.cash_amount,0)+COALESCE(s.cash_credit_collection_amount,0),2)>0 AND s.sale_date<=? AND (COALESCE(s.cash_movement_id,0)=0 OR NOT EXISTS (SELECT 1 FROM movements m WHERE m.id=s.cash_movement_id AND COALESCE(m.is_cancelled,0)=0)))
        OR
        (ROUND(COALESCE(s.card_amount,0)+COALESCE(s.card_credit_collection_amount,0),2)>0 AND date(s.sale_date,'+13 days')<=? AND (COALESCE(s.card_movement_id,0)=0 OR NOT EXISTS (SELECT 1 FROM movements m WHERE m.id=s.card_movement_id AND COALESCE(m.is_cancelled,0)=0)))
    ) ORDER BY s.sale_date ASC, s.id ASC LIMIT 500");
    $stmt->execute([$today, $today]);
    $ids = array_map('intval', array_column($stmt->fetchAll() ?: [], 'id'));
    foreach ($ids as $id) magaza_odeme_dagilim_hareketlerini_senkronla($id);
    return count($ids);
}

function magaza_odeme_dagilim_onceki_kasa_parasi(string $saleDate): array
{
    magaza_odeme_dagilim_tablosunu_hazirla();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate) || strtotime($saleDate) === false) return ['sale_date'=>'','amount'=>0.0];
    $stmt = db()->prepare("SELECT sale_date, cash_change_left_amount FROM store_daily_payment_breakdown WHERE sale_date < ? ORDER BY sale_date DESC, id DESC LIMIT 1");
    $stmt->execute([$saleDate]);
    $row = $stmt->fetch() ?: [];
    return ['sale_date'=>(string)($row['sale_date'] ?? ''),'amount'=>(float)($row['cash_change_left_amount'] ?? 0)];
}

function magaza_odeme_dagilim_ozeti(string $period): array
{
    magaza_odeme_dagilim_tablosunu_hazirla();
    $start = $period . '-01';
    $end = date('Y-m-t', strtotime($start));
    $stmt = db()->prepare("SELECT COUNT(*) AS day_count, COALESCE(SUM(cash_amount),0) AS cash_sales_amount, COALESCE(SUM(card_amount),0) AS card_sales_amount, COALESCE(SUM(credit_amount),0) AS credit_amount, COALESCE(SUM(cash_credit_collection_amount),0) AS cash_credit_collection_amount, COALESCE(SUM(card_credit_collection_amount),0) AS card_credit_collection_amount, COALESCE(SUM(cash_amount+cash_credit_collection_amount),0) AS cash_total_amount, COALESCE(SUM(card_amount+card_credit_collection_amount),0) AS card_total_amount, COALESCE(SUM(daily_total),0) AS daily_total FROM store_daily_payment_breakdown WHERE sale_date BETWEEN ? AND ?");
    $stmt->execute([$start, $end]);
    $row = $stmt->fetch() ?: [];
    $cashCollection = (float)($row['cash_credit_collection_amount'] ?? 0);
    $cardCollection = (float)($row['card_credit_collection_amount'] ?? 0);
    return [
        'count'=>(int)($row['day_count'] ?? 0), 'cash_sales'=>(float)($row['cash_sales_amount'] ?? 0),
        'card_sales'=>(float)($row['card_sales_amount'] ?? 0), 'cash'=>(float)($row['cash_total_amount'] ?? 0),
        'card'=>(float)($row['card_total_amount'] ?? 0), 'credit'=>(float)($row['credit_amount'] ?? 0),
        'cash_credit_collection'=>$cashCollection, 'card_credit_collection'=>$cardCollection,
        'credit_collection'=>round($cashCollection+$cardCollection,2), 'daily_total'=>(float)($row['daily_total'] ?? 0),
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
