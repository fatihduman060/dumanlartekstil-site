<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/magaza-odeme-dagilim-lib.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    $today = date('Y-m-d');
    $period = date('Y-m');
    $periodStart = $period . '-01';
    $periodEnd = date('Y-m-t', strtotime($periodStart));
    $cutoff = $today < $periodEnd ? $today : $periodEnd;

    magaza_odeme_dagilim_tablosunu_hazirla();
    $processedDueCount = magaza_odeme_dagilim_vadesi_gelenleri_isle($today);

    $stmt = db()->prepare("SELECT
        COUNT(*) AS day_count,
        COALESCE(SUM(COALESCE(cash_amount,0) + COALESCE(cash_credit_collection_amount,0)),0) AS cash_total,
        COALESCE(SUM(COALESCE(card_amount,0) + COALESCE(card_credit_collection_amount,0)),0) AS card_total,
        MAX(sale_date) AS latest_sale_date
        FROM store_daily_payment_breakdown
        WHERE sale_date BETWEEN ? AND ?");
    $stmt->execute([$periodStart, $cutoff]);
    $store = $stmt->fetch() ?: [];

    $settledStmt = db()->prepare("SELECT
        COALESCE(SUM(m.amount),0) AS settled_total,
        COUNT(*) AS settled_day_count,
        MAX(m.movement_date) AS latest_settlement_date
        FROM store_daily_payment_breakdown s
        JOIN movements m ON m.id=s.card_movement_id
        WHERE COALESCE(m.is_cancelled,0)=0
          AND m.movement_date BETWEEN ? AND ?");
    $settledStmt->execute([$periodStart, $cutoff]);
    $settled = $settledStmt->fetch() ?: [];

    echo json_encode([
        'ok' => true,
        'period' => $period,
        'period_label' => date('m.Y', strtotime($periodStart)),
        'cutoff_date' => $cutoff,
        'day_count' => (int)($store['day_count'] ?? 0),
        'latest_sale_date' => (string)($store['latest_sale_date'] ?? ''),
        'cash_total' => (float)($store['cash_total'] ?? 0),
        'card_total' => (float)($store['card_total'] ?? 0),
        'settled_pos_total' => (float)($settled['settled_total'] ?? 0),
        'settled_pos_day_count' => (int)($settled['settled_day_count'] ?? 0),
        'latest_settlement_date' => (string)($settled['latest_settlement_date'] ?? ''),
        'processed_due_count' => $processedDueCount,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
