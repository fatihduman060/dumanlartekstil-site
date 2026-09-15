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

function bayrak_norm(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    return mb_strtolower($value, 'UTF-8');
}

function bayrak_same_physical_check(array $candidate, array $active): bool
{
    $candidateNo = trim((string)($candidate['check_no'] ?? ''));
    $activeNo = trim((string)($active['check_no'] ?? ''));
    if ($candidateNo !== '' && $activeNo !== '' && $candidateNo === $activeNo) return true;

    $candidateDoc = trim((string)($candidate['document_path'] ?? ''));
    $activeDoc = trim((string)($active['document_path'] ?? ''));
    if ($candidateDoc !== '' && $activeDoc !== '' && $candidateDoc === $activeDoc) return true;

    return false;
}

function bayrak_candidate_score(array $candidate, array $movement): int
{
    $score = 0;
    $movementId = (int)($movement['id'] ?? 0);
    if ((int)($candidate['movement_id'] ?? 0) === $movementId && $movementId > 0) $score += 120;

    $candidateDoc = trim((string)($candidate['document_path'] ?? ''));
    $movementDoc = trim((string)($movement['document_path'] ?? ''));
    if ($candidateDoc !== '' && $movementDoc !== '' && $candidateDoc === $movementDoc) $score += 80;
    elseif ($candidateDoc !== '') $score += 20;

    $candidateNo = trim((string)($candidate['check_no'] ?? ''));
    $movementDescription = bayrak_norm((string)($movement['description'] ?? ''));
    if ($candidateNo !== '' && $movementDescription !== '' && mb_strpos($movementDescription, bayrak_norm($candidateNo)) !== false) $score += 55;
    elseif ($candidateNo !== '') $score += 12;

    $candidateDescription = bayrak_norm((string)($candidate['description'] ?? ''));
    if ($candidateDescription !== '' && $movementDescription !== ''
        && (mb_strpos($movementDescription, $candidateDescription) !== false
            || mb_strpos($candidateDescription, $movementDescription) !== false)) {
        $score += 18;
    }

    $reason = (string)($candidate['cancel_reason'] ?? '');
    if (mb_strpos(bayrak_norm($reason), 'otomatik mükerrer çek birleştirildi') !== false) $score += 15;

    if (trim((string)($candidate['bank_name'] ?? '')) !== '') $score += 3;
    if (trim((string)($candidate['drawer'] ?? '')) !== '') $score += 3;
    return $score;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Bu onarım yalnız sistem içinden çalıştırılabilir.');
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

    $cariIds = [];
    foreach ($candidates as $candidate) {
        $cid = (int)($candidate['cari_id'] ?? 0);
        if ($cid > 0) $cariIds[$cid] = true;
    }
    if (!$cariIds) {
        bayrak_cek_json(['ok'=>true,'repaired'=>false,'needs_review'=>true,'message'=>'Bayrak Gross iptal çeklerinde cari bağlantısı bulunamadı.']);
    }

    $restorable = [];
    $diagnostics = [];

    foreach ($candidates as $candidate) {
        $checkId = (int)$candidate['id'];
        $cariId = (int)($candidate['cari_id'] ?? 0);
        if ($cariId <= 0) continue;

        $aStmt = $pdo->prepare("SELECT * FROM checks
            WHERE COALESCE(is_cancelled,0)=0 AND cari_id=? AND direction='alinacak'
              AND due_date='2026-09-19' AND ABS(amount-250000)<0.01
            ORDER BY id ASC");
        $aStmt->execute([$cariId]);
        $activeChecks = $aStmt->fetchAll() ?: [];

        $physicalConflict = false;
        foreach ($activeChecks as $activeCheck) {
            if (bayrak_same_physical_check($candidate, $activeCheck)) {
                $physicalConflict = true;
                break;
            }
        }

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

        $chosenMovement = null;
        $targetMovementId = (int)($candidate['movement_id'] ?? 0);
        if ($targetMovementId > 0) {
            foreach ($unclaimed as $movement) {
                if ((int)$movement['id'] === $targetMovementId) {
                    $chosenMovement = $movement;
                    break;
                }
            }
        }

        if (!$chosenMovement) {
            $doc = trim((string)($candidate['document_path'] ?? ''));
            if ($doc !== '') {
                $matches = array_values(array_filter($unclaimed, fn($movement) => trim((string)($movement['document_path'] ?? '')) === $doc));
                if (count($matches) === 1) $chosenMovement = $matches[0];
            }
        }

        if (!$chosenMovement) {
            $checkNo = trim((string)($candidate['check_no'] ?? ''));
            if ($checkNo !== '') {
                $needle = bayrak_norm($checkNo);
                $matches = array_values(array_filter($unclaimed, function($movement) use ($needle) {
                    return mb_strpos(bayrak_norm((string)($movement['description'] ?? '')), $needle) !== false;
                }));
                if (count($matches) === 1) $chosenMovement = $matches[0];
            }
        }

        if (!$chosenMovement && count($unclaimed) === 1) {
            $chosenMovement = $unclaimed[0];
        }

        $diagnostics[] = [
            'check_id'=>$checkId,
            'cari_id'=>$cariId,
            'active_checks'=>count($activeChecks),
            'active_movements'=>count($activeMovements),
            'unclaimed_movements'=>count($unclaimed),
            'physical_conflict'=>$physicalConflict,
            'candidate_movement_id'=>$targetMovementId ?: null,
            'chosen_movement_id'=>$chosenMovement ? (int)$chosenMovement['id'] : null,
            'has_document'=>trim((string)($candidate['document_path'] ?? '')) !== '',
            'check_no'=>trim((string)($candidate['check_no'] ?? '')),
            'cancel_reason'=>(string)($candidate['cancel_reason'] ?? ''),
        ];

        if ($physicalConflict || !$chosenMovement) continue;

        $restorable[] = [
            'candidate'=>$candidate,
            'movement'=>$chosenMovement,
            'score'=>bayrak_candidate_score($candidate, $chosenMovement),
        ];
    }

    if (!$restorable) {
        bayrak_cek_json([
            'ok'=>true,
            'repaired'=>false,
            'needs_review'=>true,
            'message'=>'Birden fazla iptal kayıt incelendi ancak aktif cari hareketiyle güvenli eşleşen çek bulunamadı. Finansal kayda dokunulmadı.',
            'cancelled_count'=>count($candidates),
            'diagnostics'=>$diagnostics,
        ]);
    }

    // Birden fazla iptal satır aynı tek boştaki cari hareketine bağlanıyorsa bunlar aynı
    // kaydın eski kopyalarıdır. En güçlü belge/çek no/hareket bağlantısı olan satırı seç.
    $movementGroups = [];
    foreach ($restorable as $item) {
        $mid = (int)$item['movement']['id'];
        $movementGroups[$mid][] = $item;
    }

    if (count($movementGroups) > 1) {
        bayrak_cek_json([
            'ok'=>true,
            'repaired'=>false,
            'needs_review'=>true,
            'message'=>'Birden fazla ayrı aktif 250.000 TL cari hareketi bulundu. Birden fazla gerçek çek olabileceği için otomatik seçim yapılmadı.',
            'cancelled_count'=>count($candidates),
            'restorable_count'=>count($restorable),
            'movement_group_count'=>count($movementGroups),
            'diagnostics'=>$diagnostics,
        ]);
    }

    usort($restorable, function(array $a, array $b): int {
        if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
        $aDoc = trim((string)($a['candidate']['document_path'] ?? '')) !== '' ? 1 : 0;
        $bDoc = trim((string)($b['candidate']['document_path'] ?? '')) !== '' ? 1 : 0;
        if ($aDoc !== $bDoc) return $bDoc <=> $aDoc;
        $aNo = trim((string)($a['candidate']['check_no'] ?? '')) !== '' ? 1 : 0;
        $bNo = trim((string)($b['candidate']['check_no'] ?? '')) !== '' ? 1 : 0;
        if ($aNo !== $bNo) return $bNo <=> $aNo;
        return ((int)$b['candidate']['id']) <=> ((int)$a['candidate']['id']);
    });

    $winner = $restorable[0];
    $target = $winner['candidate'];
    $chosenMovement = $winner['movement'];
    $checkId = (int)$target['id'];
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
            'cancelled_candidates'=>count($candidates),
            'restorable_candidates'=>count($restorable),
            'selection_score'=>(int)$winner['score'],
            'source'=>'Bayrak Gross 250.000 / 19.09.2026 çoklu iptal güvenli onarım',
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
        'cancelled_count'=>count($candidates),
        'selected_score'=>(int)$winner['score'],
        'message'=>'Bayrak Gross 250.000 TL çek yeniden aktif edildi. Aynı hareketin eski iptal kopyaları kapalı kaldı; cari bakiyesi ve banka/kasa değiştirilmedi.',
    ]);
} catch (Throwable $e) {
    bayrak_cek_json(['ok'=>false,'error'=>$e->getMessage()], 422);
}
