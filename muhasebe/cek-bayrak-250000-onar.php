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
        bayrak_cek_json(['ok'=>true, 'repaired'=>false, 'message'=>'Hedef iptal çek bulunmadı; onarım gerekmiyor.']);
    }

    $autoMerged = [];
    foreach ($candidates as $candidate) {
        $reason = trim((string)($candidate['cancel_reason'] ?? ''));
        if (preg_match('/^Otomatik mükerrer çek birleştirildi \(#(\d+)\)$/u', $reason, $m)) {
            $candidate['_survivor_id'] = (int)$m[1];
            $autoMerged[] = $candidate;
        }
    }

    if (count($autoMerged) !== 1) {
        bayrak_cek_json([
            'ok'=>true,
            'repaired'=>false,
            'needs_review'=>true,
            'message'=>count($autoMerged) > 1
                ? 'Aynı ölçütlerde birden fazla otomatik iptal çek var; yanlış kaydı açmamak için işlem yapılmadı.'
                : 'İptal nedeni otomatik mükerrer birleştirme değil; bakiyeyi riske atmamak için otomatik onarım yapılmadı.',
        ]);
    }

    $target = $autoMerged[0];
    $checkId = (int)$target['id'];
    $survivorId = (int)$target['_survivor_id'];
    $movementId = (int)($target['movement_id'] ?? 0);
    if ($movementId <= 0) {
        bayrak_cek_json(['ok'=>true,'repaired'=>false,'needs_review'=>true,'message'=>'İptal çekin kendine ait cari hareket bağlantısı yok; otomatik açmak güvenli değil.']);
    }

    $mStmt = $pdo->prepare('SELECT * FROM movements WHERE id=? LIMIT 1');
    $mStmt->execute([$movementId]);
    $movement = $mStmt->fetch() ?: null;
    if (!$movement || (int)($movement['is_cancelled'] ?? 0) === 1) {
        bayrak_cek_json(['ok'=>true,'repaired'=>false,'needs_review'=>true,'message'=>'Çekin bağlı cari hareketi aktif değil; cari bakiyeyi değiştirmemek için otomatik onarım durduruldu.']);
    }

    $expectedType = 'alacak';
    if ((int)($movement['cari_id'] ?? 0) !== (int)($target['cari_id'] ?? 0)
        || (string)($movement['movement_type'] ?? '') !== $expectedType
        || abs((float)($movement['amount'] ?? 0) - 250000.0) >= 0.01
        || (string)($movement['due_date'] ?? '') !== '2026-09-19') {
        bayrak_cek_json(['ok'=>true,'repaired'=>false,'needs_review'=>true,'message'=>'Bağlı hareket çekle birebir eşleşmiyor; otomatik onarım yapılmadı.']);
    }

    $survivor = null;
    if ($survivorId > 0) {
        $sStmt = $pdo->prepare('SELECT * FROM checks WHERE id=? LIMIT 1');
        $sStmt->execute([$survivorId]);
        $survivor = $sStmt->fetch() ?: null;
    }

    if ($survivor && (int)($survivor['is_cancelled'] ?? 0) === 0) {
        $survivorMovementId = (int)($survivor['movement_id'] ?? 0);
        if ($survivorMovementId === $movementId) {
            bayrak_cek_json(['ok'=>true,'repaired'=>false,'needs_review'=>true,'message'=>'İptal çek ile aktif çek aynı cari hareketine bağlı; gerçek mükerrer olabileceği için otomatik onarım yapılmadı.']);
        }

        $targetNo = trim((string)($target['check_no'] ?? ''));
        $survivorNo = trim((string)($survivor['check_no'] ?? ''));
        if ($targetNo !== '' && $survivorNo !== '' && $targetNo === $survivorNo) {
            bayrak_cek_json(['ok'=>true,'repaired'=>false,'needs_review'=>true,'message'=>'Aktif çek ile iptal çekin çek numarası aynı; gerçek mükerrer olabileceği için otomatik onarım yapılmadı.']);
        }
    }

    $otherStmt = $pdo->prepare("SELECT id FROM checks
        WHERE id<>? AND COALESCE(is_cancelled,0)=0 AND movement_id=? LIMIT 1");
    $otherStmt->execute([$checkId, $movementId]);
    $otherCheckId = (int)($otherStmt->fetchColumn() ?: 0);
    if ($otherCheckId > 0) {
        bayrak_cek_json(['ok'=>true,'repaired'=>false,'needs_review'=>true,'message'=>'Bu cari hareketi başka aktif bir çeke bağlı (#'.$otherCheckId.'); otomatik onarım yapılmadı.']);
    }

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
        // Sadece çek kaydını yeniden görünür hale getiriyoruz. Cari hareketin tutarı,
        // iptal durumu ve cari bakiyeyi etkileyen hiçbir alan değiştirilmez.
        $pdo->prepare("UPDATE checks
            SET is_cancelled=0, status=?, closed_at=NULL, cancelled_at=NULL, cancelled_by=NULL,
                cancel_reason=NULL, updated_at=?
            WHERE id=?")
            ->execute([$restoreStatus, now(), $checkId]);

        // Eski mükerrer birleştirme bu hareketin check_id alanını survivor çeke taşımıştı.
        // Finansal hareketi değiştirmeden yalnız doğru çek kaydına geri bağlıyoruz.
        $pdo->prepare('UPDATE movements SET check_id=?, updated_at=? WHERE id=?')
            ->execute([$checkId, now(), $movementId]);

        audit_action('cek', $checkId, 'otomatik_mukerrer_hatasi_onarildi', $target, [
            'is_cancelled'=>0,
            'status'=>$restoreStatus,
            'movement_id'=>$movementId,
            'balance_changed'=>false,
            'source'=>'Bayrak Gross 250.000 / 19.09.2026 güvenli onarım',
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
        'message'=>'Bayrak Gross 250.000 TL çek otomatik mükerrer iptalinden çıkarıldı. Cari hareket ve bakiye değiştirilmedi.',
    ]);
} catch (Throwable $e) {
    bayrak_cek_json(['ok'=>false,'error'=>$e->getMessage()], 422);
}
