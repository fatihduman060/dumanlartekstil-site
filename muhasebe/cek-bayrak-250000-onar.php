<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';

require_login();
require_write();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

function bayrak_cek_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Bu onarım yalnız Çekler ekranından çalıştırılabilir.');
    }
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        throw new RuntimeException('Oturum doğrulaması yenilenmeli. Sayfayı yenileyin.');
    }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT ch.*, c.name AS cari_name
        FROM checks ch
        LEFT JOIN cariler c ON c.id=ch.cari_id
        WHERE COALESCE(ch.is_cancelled,0)=1
          AND ch.direction='alinacak'
          AND ch.due_date='2026-09-19'
          AND ABS(ch.amount-250000) < 0.01
          AND LOWER(COALESCE(c.name,'')) LIKE '%bayrak%'
        ORDER BY ch.id DESC");
    $stmt->execute();
    $candidates = $stmt->fetchAll() ?: [];

    if (!$candidates) {
        $activeStmt = $pdo->prepare("SELECT ch.id FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id
            WHERE COALESCE(ch.is_cancelled,0)=0 AND ch.direction='alinacak'
              AND ch.due_date='2026-09-19' AND ABS(ch.amount-250000)<0.01
              AND LOWER(COALESCE(c.name,'')) LIKE '%bayrak%' LIMIT 1");
        $activeStmt->execute();
        $activeId = (int)($activeStmt->fetchColumn() ?: 0);
        bayrak_cek_json([
            'ok'=>true,
            'repaired'=>false,
            'already_active'=>$activeId > 0,
            'id'=>$activeId ?: null,
            'message'=>$activeId > 0
                ? 'Bayrak Gross 250.000 TL çek zaten aktif durumda.'
                : 'Bayrak Gross 250.000 TL için hedef iptal çek bulunmadı.',
        ]);
    }

    if (count($candidates) !== 1) {
        bayrak_cek_json([
            'ok'=>true,
            'repaired'=>false,
            'needs_review'=>true,
            'message'=>'19.09.2026 / 250.000 TL Bayrak Gross için birden fazla iptal çek bulundu. Yanlış kaydı açmamak için otomatik işlem yapılmadı.',
            'cancelled_count'=>count($candidates),
        ]);
    }

    $target = $candidates[0];
    $checkId = (int)$target['id'];
    $cariId = (int)($target['cari_id'] ?? 0);
    if ($cariId <= 0) {
        bayrak_cek_json(['ok'=>true,'repaired'=>false,'needs_review'=>true,'message'=>'Bayrak Gross çek kaydında cari bağlantısı yok. Otomatik işlem yapılmadı.']);
    }

    // Aynı cari/vade/tutarda halen aktif olan çekleri bul.
    $aStmt = $pdo->prepare("SELECT * FROM checks
        WHERE id<>? AND COALESCE(is_cancelled,0)=0 AND cari_id=? AND direction='alinacak'
          AND due_date='2026-09-19' AND ABS(amount-250000)<0.01
        ORDER BY id ASC");
    $aStmt->execute([$checkId, $cariId]);
    $activeChecks = $aStmt->fetchAll() ?: [];

    // Fiziksel olarak aynı çek olduğu açıkça görülüyorsa otomatik geri açma.
    $targetNo = trim((string)($target['check_no'] ?? ''));
    $targetDoc = trim((string)($target['document_path'] ?? ''));
    foreach ($activeChecks as $activeCheck) {
        $activeNo = trim((string)($activeCheck['check_no'] ?? ''));
        $activeDoc = trim((string)($activeCheck['document_path'] ?? ''));
        if ($targetNo !== '' && $activeNo !== '' && $targetNo === $activeNo) {
            bayrak_cek_json([
                'ok'=>true,'repaired'=>false,'needs_review'=>true,
                'message'=>'İptal çek ile aktif çekin çek numarası aynı. Gerçek mükerrer olabileceği için otomatik geri açılmadı.',
            ]);
        }
        if ($targetDoc !== '' && $activeDoc !== '' && $targetDoc === $activeDoc) {
            bayrak_cek_json([
                'ok'=>true,'repaired'=>false,'needs_review'=>true,
                'message'=>'İptal çek ile aktif çek aynı çek görselini kullanıyor. Gerçek mükerrer olabileceği için otomatik geri açılmadı.',
            ]);
        }
    }

    // Finansal hareket tarafını kontrol et. Eski mükerrer birleştirme çekleri tek kayda indirirken
    // cari hareketleri silmedi; bu yüzden ayrı gerçek çek için fazladan aktif hareket kalmış olmalı.
    $mStmt = $pdo->prepare("SELECT * FROM movements
        WHERE COALESCE(is_cancelled,0)=0 AND cari_id=? AND movement_type='alacak'
          AND due_date='2026-09-19' AND ABS(amount-250000)<0.01
          AND COALESCE(is_check_adjustment,0)=0
          AND COALESCE(is_check_unpaid_adjustment,0)=0
        ORDER BY id ASC");
    $mStmt->execute([$cariId]);
    $activeMovements = $mStmt->fetchAll() ?: [];

    $usedMovementIds = [];
    foreach ($activeChecks as $activeCheck) {
        $mid = (int)($activeCheck['movement_id'] ?? 0);
        if ($mid > 0) $usedMovementIds[$mid] = true;
    }

    $unclaimed = [];
    foreach ($activeMovements as $movement) {
        $mid = (int)$movement['id'];
        if (!isset($usedMovementIds[$mid])) $unclaimed[] = $movement;
    }

    $targetMovementId = (int)($target['movement_id'] ?? 0);
    $chosenMovement = null;
    foreach ($unclaimed as $movement) {
        if ((int)$movement['id'] === $targetMovementId) {
            $chosenMovement = $movement;
            break;
        }
    }
    if (!$chosenMovement && count($unclaimed) === 1) {
        $chosenMovement = $unclaimed[0];
    }

    if (!$chosenMovement) {
        bayrak_cek_json([
            'ok'=>true,
            'repaired'=>false,
            'needs_review'=>true,
            'message'=>'Çek kaydı bulundu ancak cari bakiyede bu çeke ait ayrı ve boştaki aktif 250.000 TL hareket güvenle ayırt edilemedi. Otomatik işlem yapılmadı.',
            'active_check_count'=>count($activeChecks),
            'active_movement_count'=>count($activeMovements),
            'unclaimed_movement_count'=>count($unclaimed),
            'cancel_reason'=>(string)($target['cancel_reason'] ?? ''),
        ]);
    }

    $movementId = (int)$chosenMovement['id'];
    $restoreStatus = 'bekliyor';
    $auditStmt = $pdo->prepare("SELECT old_value FROM audit_logs
        WHERE entity_type='cek' AND entity_id=? AND action='iptal'
        ORDER BY id DESC LIMIT 1");
    $auditStmt->execute([$checkId]);
    $oldJson = $auditStmt->fetchColumn();
    if (is_string($oldJson) && $oldJson !== '') {
        $oldValue = json_decode($oldJson, true);
        $oldStatus = is_array($oldValue) ? (string)($oldValue['status'] ?? '') : '';
        if (in_array($oldStatus, ['bekliyor','bankaya_verildi'], true)) $restoreStatus = $oldStatus;
    }

    $pdo->beginTransaction();
    try {
        // Yalnızca görünmez olmuş çek kaydını, zaten aktif olan ayrı cari hareketine geri bağlıyoruz.
        // Yeni finansal hareket oluşturulmaz; mevcut hareket tutarı ve cari bakiyesi değiştirilmez.
        $pdo->prepare("UPDATE checks
            SET movement_id=?, is_cancelled=0, status=?, closed_at=NULL,
                cancelled_at=NULL, cancelled_by=NULL, cancel_reason=NULL, updated_at=?
            WHERE id=?")
            ->execute([$movementId, $restoreStatus, now(), $checkId]);

        $pdo->prepare('UPDATE movements SET check_id=?, updated_at=? WHERE id=?')
            ->execute([$checkId, now(), $movementId]);

        audit_action('cek', $checkId, 'otomatik_mukerrer_hatasi_onarildi', $target, [
            'is_cancelled'=>0,
            'status'=>$restoreStatus,
            'movement_id'=>$movementId,
            'balance_changed'=>false,
            'active_check_count_before'=>count($activeChecks),
            'active_movement_count'=>count($activeMovements),
            'source'=>'Bayrak Gross 250.000 / 19.09.2026 ayrı aktif hareket onarımı',
        ], (string)($target['cari_name'] ?? 'Bayrak Gross'));
        log_action('Otomatik mükerrer çek hatası onarıldı', '#'.$checkId.' '.(string)($target['cari_name'] ?? 'Bayrak Gross').' 250.000,00 / 19.09.2026');

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    bayrak_cek_json([
        'ok'=>true,
        'repaired'=>true,
        'id'=>$checkId,
        'movement_id'=>$movementId,
        'status'=>$restoreStatus,
        'message'=>'Bayrak Gross 250.000 TL çek yeniden aktif edildi. Mevcut ayrı cari hareketine bağlandı; cari bakiyesi ve banka/kasa değiştirilmedi.',
    ]);
} catch (Throwable $e) {
    bayrak_cek_json(['ok'=>false,'error'=>$e->getMessage()], 422);
}
