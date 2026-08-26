<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_once __DIR__ . '/magaza-veresiye-auto-only.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    if (!can_manage_store_sales()) {
        throw new RuntimeException('Mağaza satış yetkisi gerekiyor.');
    }

    $saleDate = trim((string)($_REQUEST['sale_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate) || strtotime($saleDate) === false) {
        throw new RuntimeException('Tarih geçersiz.');
    }

    $beforeStmt = db()->prepare('SELECT manual_credit_amount,credit_amount,cash_credit_collection_amount,card_credit_collection_amount,daily_total FROM store_daily_payment_breakdown WHERE sale_date=? LIMIT 1');
    $beforeStmt->execute([$saleDate]);
    $before = $beforeStmt->fetch() ?: [];

    $result = magaza_veresiye_auto_only_sync_date($saleDate, current_user()['id'] ?? null);

    $afterStmt = db()->prepare('SELECT manual_credit_amount,credit_amount,cash_credit_collection_amount,card_credit_collection_amount,daily_total FROM store_daily_payment_breakdown WHERE sale_date=? LIMIT 1');
    $afterStmt->execute([$saleDate]);
    $after = $afterStmt->fetch() ?: [];

    $repaired = round((float)($before['manual_credit_amount'] ?? 0), 2) !== round((float)($after['manual_credit_amount'] ?? 0), 2)
        || round((float)($before['credit_amount'] ?? 0), 2) !== round((float)($after['credit_amount'] ?? 0), 2)
        || round((float)($before['cash_credit_collection_amount'] ?? 0), 2) !== round((float)($after['cash_credit_collection_amount'] ?? 0), 2)
        || round((float)($before['card_credit_collection_amount'] ?? 0), 2) !== round((float)($after['card_credit_collection_amount'] ?? 0), 2);

    echo json_encode([
        'ok'=>true,
        'repaired'=>$repaired,
        'date'=>$saleDate,
        'credit_total'=>(float)($after['credit_amount'] ?? 0),
        'cash_collection'=>(float)($after['cash_credit_collection_amount'] ?? 0),
        'card_collection'=>(float)($after['card_credit_collection_amount'] ?? 0),
        'message'=>'Veresiye satış ve tahsilatlar Personel Veresiye hareketlerinden otomatik senkronlandı.',
        'sync'=>$result,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
