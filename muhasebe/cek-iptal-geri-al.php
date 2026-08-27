<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_login();
require_write();
header('Content-Type: application/json; charset=utf-8');

function cek_geri_al_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Bu işlem yalnızca Çekler ekranındaki İptali geri al butonundan yapılabilir.');
    }
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        throw new RuntimeException('Oturum doğrulaması yenilenmeli. Sayfayı yenileyin.');
    }

    $pdo = db();
    $id = (int)($_POST['id'] ?? 0);
    $target = null;

    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT ch.*, c.name AS cari_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id WHERE ch.id=? LIMIT 1");
        $stmt->execute([$id]);
        $target = $stmt->fetch() ?: null;
    } else {
        $cariId = (int)($_POST['cari_id'] ?? 0);
        $direction = trim((string)($_POST['direction'] ?? 'alinacak'));
        $checkNo = trim((string)($_POST['check_no'] ?? ''));
        $dueDate = trim((string)($_POST['due_date'] ?? ''));
        $amount = decimal_from_input($_POST['amount'] ?? '0');

        if ($cariId <= 0 || !in_array($direction, ['alinacak','verilecek'], true) || $dueDate === '' || $amount <= 0) {
            throw new RuntimeException('İptal çek bilgileri eksik. Sayfayı yenileyip tekrar deneyin.');
        }

        $sql = "SELECT ch.*, c.name AS cari_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id
                WHERE COALESCE(ch.is_cancelled,0)=1 AND ch.cari_id=? AND ch.direction=? AND ch.due_date=? AND ABS(ch.amount-?) < 0.01";
        $params = [$cariId, $direction, $dueDate, $amount];
        if ($checkNo !== '') {
            $sql .= " AND TRIM(COALESCE(ch.check_no,''))=?";
            $params[] = $checkNo;
        }
        $sql .= " ORDER BY ch.id DESC LIMIT 3";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $matches = $stmt->fetchAll() ?: [];
        if (count($matches) === 0) throw new RuntimeException('Bu satıra ait iptal çek bulunamadı.');
        if (count($matches) > 1) throw new RuntimeException('Aynı bilgilerde birden fazla iptal çek bulundu. Yanlış kaydı açmamak için işlem durduruldu.');
        $target = $matches[0];
    }

    if (!$target) throw new RuntimeException('Çek bulunamadı.');
    if ((int)($target['is_cancelled'] ?? 0) !== 1) {
        cek_geri_al_json(['ok'=>true,'message'=>'Çek zaten aktif.','id'=>(int)$target['id'],'direction'=>(string)($target['direction'] ?? 'alinacak')]);
    }

    $checkId = (int)$target['id'];
    $direction = (string)($target['direction'] ?? 'alinacak');
    $movementId = (int)($target['movement_id'] ?? 0);

    // Aynı çek zaten aktifse mükerrer üretme.
    $dupSql = "SELECT id FROM checks WHERE id<>? AND COALESCE(is_cancelled,0)=0 AND cari_id=? AND direction=? AND due_date=? AND ABS(amount-?)<0.01";
    $dupParams = [$checkId, (int)$target['cari_id'], $direction, (string)$target['due_date'], (float)$target['amount']];
    if (trim((string)($target['check_no'] ?? '')) !== '') {
        $dupSql .= " AND TRIM(COALESCE(check_no,''))=?";
        $dupParams[] = trim((string)$target['check_no']);
    }
    $dupSql .= " LIMIT 1";
    $dupStmt = $pdo->prepare($dupSql);
    $dupStmt->execute($dupParams);
    $activeDuplicateId = (int)($dupStmt->fetchColumn() ?: 0);
    if ($activeDuplicateId > 0) {
        throw new RuntimeException('Aynı çek zaten aktif listede #' . $activeDuplicateId . ' numarasıyla bulunuyor. Mükerrer oluşturmamak için iptal geri alınmadı.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE checks SET is_cancelled=0, status='bekliyor', closed_at=NULL, cancelled_at=NULL, cancelled_by=NULL, cancel_reason=NULL, updated_at=? WHERE id=?")
            ->execute([now(), $checkId]);

        // Çek iptal olurken bağlı cari hareketi de iptal edilmişse yeniden aktif et.
        if ($movementId > 0) {
            $mStmt = $pdo->prepare("SELECT * FROM movements WHERE id=? LIMIT 1");
            $mStmt->execute([$movementId]);
            $movement = $mStmt->fetch();
            if ($movement && (int)($movement['is_cancelled'] ?? 0) === 1) {
                $pdo->prepare("UPDATE movements SET is_cancelled=0, cancelled_at=NULL, cancelled_by=NULL, cancel_reason=NULL, updated_at=? WHERE id=?")
                    ->execute([now(), $movementId]);
                sync_movement_account_transaction($movementId);
            }
        }

        sync_check_to_movement($checkId, true);
        sync_check_account_transaction($checkId);
        sync_check_balance_adjustment($checkId);
        sync_check_unpaid_movement($checkId);

        audit_action('cek', $checkId, 'iptal_geri_alindi', $target, [
            'is_cancelled'=>0,
            'status'=>'bekliyor',
            'movement_id'=>$movementId ?: null,
        ], (string)($target['cari_name'] ?? ('#'.$checkId)));
        log_action('Çek iptali geri alındı', '#' . $checkId . ' ' . (string)($target['cari_name'] ?? '') . ' ' . money((float)$target['amount']));

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    cek_geri_al_json([
        'ok'=>true,
        'message'=>'Çek iptallerden çıkarıldı. Normal çek listesine ve bağlı cari hareketine geri alındı.',
        'id'=>$checkId,
        'direction'=>$direction,
        'cari_name'=>(string)($target['cari_name'] ?? ''),
        'amount'=>(float)$target['amount'],
    ]);
} catch (Throwable $e) {
    cek_geri_al_json(['ok'=>false,'error'=>$e->getMessage()], 422);
}
