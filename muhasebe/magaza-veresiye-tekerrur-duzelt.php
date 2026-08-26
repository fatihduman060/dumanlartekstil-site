<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_once __DIR__ . '/barkod-satis-lib.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    if (!can_manage_store_sales()) {
        throw new RuntimeException('Mağaza satış yetkisi gerekiyor.');
    }

    pos_db_ensure();
    $saleDate = trim((string)($_REQUEST['sale_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate) || strtotime($saleDate) === false) {
        throw new RuntimeException('Tarih geçersiz.');
    }

    $stmt = db()->prepare('SELECT * FROM store_daily_payment_breakdown WHERE sale_date=? LIMIT 1');
    $stmt->execute([$saleDate]);
    $daily = $stmt->fetch() ?: null;
    if (!$daily) {
        echo json_encode(['ok'=>true,'repaired'=>false,'message'=>'Günlük kayıt yok.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $manualCredit = round((float)($daily['manual_credit_amount'] ?? 0), 2);
    if ($manualCredit <= 0) {
        echo json_encode(['ok'=>true,'repaired'=>false,'message'=>'Manuel veresiye tekrarı yok.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $stmt = db()->prepare("SELECT COALESCE(SUM(s.grand_total),0) AS total, COUNT(*) AS sale_count
        FROM pos_sales s
        JOIN store_credit_entries e ON e.id=s.credit_entry_id
        WHERE s.sale_date=?
          AND s.payment_method='credit'
          AND COALESCE(s.is_cancelled,0)=0
          AND s.credit_entry_id IS NOT NULL
          AND e.entry_type='debt'
          AND COALESCE(e.is_cancelled,0)=0");
    $stmt->execute([$saleDate]);
    $linked = $stmt->fetch() ?: ['total'=>0,'sale_count'=>0];
    $barcodeCredit = round((float)($linked['total'] ?? 0), 2);
    $saleCount = (int)($linked['sale_count'] ?? 0);
    $personnelCredit = magaza_odeme_dagilim_personel_veresiye_toplami($saleDate);

    $sameManualAndBarcode = $barcodeCredit > 0 && abs($manualCredit - $barcodeCredit) < 0.005;
    $onlyBarcodePersonnelCredit = abs($personnelCredit - $barcodeCredit) < 0.005;

    if (!$sameManualAndBarcode || !$onlyBarcodePersonnelCredit) {
        echo json_encode([
            'ok'=>true,
            'repaired'=>false,
            'manual_credit'=>$manualCredit,
            'barcode_credit'=>$barcodeCredit,
            'personnel_credit'=>$personnelCredit,
            'message'=>'Otomatik silme için güvenli birebir tekrar bulunmadı.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $newCredit = round($personnelCredit, 2);
    $newDailyTotal = magaza_odeme_dagilim_gunluk_toplam(
        (float)($daily['cash_amount'] ?? 0),
        (float)($daily['card_amount'] ?? 0),
        $newCredit
    );

    db()->beginTransaction();
    try {
        db()->prepare('UPDATE store_daily_payment_breakdown SET manual_credit_amount=0, credit_amount=?, daily_total=?, updated_by=?, updated_at=? WHERE id=?')
            ->execute([$newCredit, $newDailyTotal, current_user()['id'] ?? null, now(), (int)$daily['id']]);
        magaza_odeme_dagilim_hareketlerini_senkronla((int)$daily['id']);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }

    audit_action('magaza_odeme_dagilimi', (int)$daily['id'], 'veresiye_tekerrur_duzeltildi', $daily, [
        'manual_credit_amount'=>0,
        'credit_amount'=>$newCredit,
        'daily_total'=>$newDailyTotal,
        'barcode_credit'=>$barcodeCredit,
        'barcode_sale_count'=>$saleCount,
    ], $saleDate . ' barkod veresiye tekerrürü');

    echo json_encode([
        'ok'=>true,
        'repaired'=>true,
        'date'=>$saleDate,
        'removed_duplicate'=>$manualCredit,
        'credit_total'=>$newCredit,
        'barcode_sale_count'=>$saleCount,
        'message'=>'Barkodlu veresiye satışının manuel tekrarı kaldırıldı.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
