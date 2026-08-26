<?php

require_once __DIR__ . '/magaza-odeme-dagilim-lib.php';

function magaza_veresiye_auto_only_cutoff(): string
{
    return '2026-08-26';
}

function magaza_veresiye_auto_only_totals(string $saleDate): array
{
    $result = [
        'debt' => 0.0,
        'cash_collection' => 0.0,
        'card_collection' => 0.0,
    ];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate)) return $result;
    $exists = (bool)db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='store_credit_entries' LIMIT 1")->fetchColumn();
    if (!$exists) return $result;

    $stmt = db()->prepare("SELECT
        COALESCE(SUM(CASE WHEN entry_type='debt' THEN amount ELSE 0 END),0) AS debt_total,
        COALESCE(SUM(CASE WHEN entry_type='payment' AND payment_method='cash' THEN amount ELSE 0 END),0) AS cash_collection,
        COALESCE(SUM(CASE WHEN entry_type='payment' AND payment_method='card' THEN amount ELSE 0 END),0) AS card_collection
        FROM store_credit_entries
        WHERE entry_date=? AND COALESCE(is_cancelled,0)=0");
    $stmt->execute([$saleDate]);
    $row = $stmt->fetch() ?: [];

    return [
        'debt' => round((float)($row['debt_total'] ?? 0), 2),
        'cash_collection' => round((float)($row['cash_collection'] ?? 0), 2),
        'card_collection' => round((float)($row['card_collection'] ?? 0), 2),
    ];
}

function magaza_veresiye_auto_only_sync_date(string $saleDate, ?int $userId = null): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate) || strtotime($saleDate) === false) {
        return ['updated'=>false, 'date'=>$saleDate];
    }
    if ($saleDate < magaza_veresiye_auto_only_cutoff()) {
        return ['updated'=>false, 'date'=>$saleDate, 'legacy'=>true];
    }

    magaza_odeme_dagilim_tablosunu_hazirla();
    $totals = magaza_veresiye_auto_only_totals($saleDate);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM store_daily_payment_breakdown WHERE sale_date=? LIMIT 1');
    $stmt->execute([$saleDate]);
    $row = $stmt->fetch() ?: null;

    if (!$row && $totals['debt'] <= 0 && $totals['cash_collection'] <= 0 && $totals['card_collection'] <= 0) {
        return ['updated'=>false, 'date'=>$saleDate, 'totals'=>$totals];
    }

    $now = now();
    $userId = $userId ?: (current_user()['id'] ?? null);
    if (!$row) {
        $dailyTotal = magaza_odeme_dagilim_gunluk_toplam(0, 0, $totals['debt']);
        $collectionTotal = round($totals['cash_collection'] + $totals['card_collection'], 2);
        $pdo->prepare('INSERT INTO store_daily_payment_breakdown
            (sale_date,cash_amount,card_amount,manual_credit_amount,credit_amount,credit_collection_amount,cash_credit_collection_amount,card_credit_collection_amount,cash_change_left_amount,daily_total,created_by,created_at,updated_by,updated_at)
            VALUES (?,0,0,0,?,?,?,?,0,?,?,?,?,?)')
            ->execute([
                $saleDate,
                $totals['debt'],
                $collectionTotal,
                $totals['cash_collection'],
                $totals['card_collection'],
                $dailyTotal,
                $userId,
                $now,
                $userId,
                $now,
            ]);
        $recordId = (int)$pdo->lastInsertId();
    } else {
        $recordId = (int)$row['id'];
        $dailyTotal = magaza_odeme_dagilim_gunluk_toplam(
            (float)($row['cash_amount'] ?? 0),
            (float)($row['card_amount'] ?? 0),
            $totals['debt']
        );
        $collectionTotal = round($totals['cash_collection'] + $totals['card_collection'], 2);
        $pdo->prepare('UPDATE store_daily_payment_breakdown
            SET manual_credit_amount=0,
                credit_amount=?,
                credit_collection_amount=?,
                cash_credit_collection_amount=?,
                card_credit_collection_amount=?,
                daily_total=?,
                updated_by=?,
                updated_at=?
            WHERE id=?')
            ->execute([
                $totals['debt'],
                $collectionTotal,
                $totals['cash_collection'],
                $totals['card_collection'],
                $dailyTotal,
                $userId,
                $now,
                $recordId,
            ]);
    }

    magaza_odeme_dagilim_hareketlerini_senkronla($recordId);

    return [
        'updated'=>true,
        'date'=>$saleDate,
        'record_id'=>$recordId,
        'credit'=>$totals['debt'],
        'cash_collection'=>$totals['cash_collection'],
        'card_collection'=>$totals['card_collection'],
    ];
}

function magaza_veresiye_auto_only_sync_period(string $period): void
{
    $period = magaza_odeme_dagilim_period($period);
    $start = max($period . '-01', magaza_veresiye_auto_only_cutoff());
    $end = date('Y-m-t', strtotime($period . '-01'));
    if ($start > $end) return;

    $dates = [];
    $stmt = db()->prepare('SELECT sale_date FROM store_daily_payment_breakdown WHERE sale_date BETWEEN ? AND ?');
    $stmt->execute([$start, $end]);
    foreach ($stmt->fetchAll() ?: [] as $row) $dates[(string)$row['sale_date']] = true;

    $creditTable = (bool)db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='store_credit_entries' LIMIT 1")->fetchColumn();
    if ($creditTable) {
        $stmt = db()->prepare('SELECT DISTINCT entry_date FROM store_credit_entries WHERE entry_date BETWEEN ? AND ? AND COALESCE(is_cancelled,0)=0');
        $stmt->execute([$start, $end]);
        foreach ($stmt->fetchAll() ?: [] as $row) $dates[(string)$row['entry_date']] = true;
    }

    ksort($dates);
    foreach (array_keys($dates) as $date) {
        magaza_veresiye_auto_only_sync_date($date);
    }
}
