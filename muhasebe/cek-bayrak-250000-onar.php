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
        throw new RuntimeException('Bu işlem yalnız sistem içinden çalıştırılabilir.');
    }
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        throw new RuntimeException('Oturum doğrulaması yenilenmeli. Sayfayı yenileyin.');
    }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT ch.*, c.name AS cari_name
        FROM checks ch
        LEFT JOIN cariler c ON c.id=ch.cari_id
        WHERE ch.direction='alinacak'
          AND ch.due_date='2026-09-19'
          AND ABS(ch.amount-250000) < 0.01
          AND LOWER(COALESCE(c.name,'')) LIKE '%bayrak%'
        ORDER BY ch.id ASC");
    $stmt->execute();
    $checks = $stmt->fetchAll() ?: [];

    if (!$checks) {
        bayrak_cek_json([
            'ok'=>true,
            'deleted'=>false,
            'already_clean'=>true,
            'message'=>'Bayrak Gross 19.09.2026 / 250.000 TL çek kaydı artık sistemde yok.',
        ]);
    }

    $cariIds = [];
    foreach ($checks as $check) {
        $cid = (int)($check['cari_id'] ?? 0);
        if ($cid > 0) $cariIds[$cid] = true;
    }
    if (count($cariIds) !== 1) {
        bayrak_cek_json([
            'ok'=>true,
            'deleted'=>false,
            'needs_review'=>true,
            'message'=>'Aynı ölçütlerde birden fazla farklı cari bulundu; yanlış kaydı silmemek için işlem yapılmadı.',
        ]);
    }

    $cariId = (int)array_key_first($cariIds);
    $before = cari_balance($cariId);

    $checkIds = [];
    $directMovementIds = [];
    foreach ($checks as $check) {
        $checkIds[] = (int)$check['id'];
        foreach (['movement_id','adjustment_movement_id','unpaid_movement_id'] as $field) {
            $mid = (int)($check[$field] ?? 0);
            if ($mid > 0) $directMovementIds[$mid] = true;
        }
    }
    $checkIds = array_values(array_unique(array_filter($checkIds)));
    $directMovementIds = array_keys($directMovementIds);

    $movementRows = [];
    $movementSeen = [];

    if ($checkIds) {
        $ph = implode(',', array_fill(0, count($checkIds), '?'));
        $mStmt = $pdo->prepare("SELECT * FROM movements WHERE check_id IN ($ph) ORDER BY id ASC");
        $mStmt->execute($checkIds);
        foreach ($mStmt->fetchAll() ?: [] as $row) {
            $movementRows[] = $row;
            $movementSeen[(int)$row['id']] = true;
        }
    }

    if ($directMovementIds) {
        $ph = implode(',', array_fill(0, count($directMovementIds), '?'));
        $mStmt = $pdo->prepare("SELECT * FROM movements WHERE id IN ($ph) ORDER BY id ASC");
        $mStmt->execute($directMovementIds);
        foreach ($mStmt->fetchAll() ?: [] as $row) {
            $mid = (int)$row['id'];
            if (!isset($movementSeen[$mid])) {
                $movementRows[] = $row;
                $movementSeen[$mid] = true;
            }
        }
    }

    // Eski mükerrer birleştirme sırasında check_id bağı kopmuş olabilecek aynı çeke ait
    // aktif çek hareketlerini de yakala. Yalnız bu cari + bu tutar + bu vade + çek işaretli kayıtlar alınır.
    $extraStmt = $pdo->prepare("SELECT * FROM movements
        WHERE cari_id=?
          AND due_date='2026-09-19'
          AND ABS(amount-250000) < 0.01
          AND (
                UPPER(COALESCE(payment_method,'')) LIKE '%ÇEK%'
             OR UPPER(COALESCE(payment_method,'')) LIKE '%CEK%'
             OR document_type='cek_gorseli'
          )
        ORDER BY id ASC");
    $extraStmt->execute([$cariId]);
    foreach ($extraStmt->fetchAll() ?: [] as $row) {
        $mid = (int)$row['id'];
        if (!isset($movementSeen[$mid])) {
            $movementRows[] = $row;
            $movementSeen[$mid] = true;
        }
    }

    $pdo->beginTransaction();
    try {
        $cancelledMovementIds = [];
        foreach ($movementRows as $movement) {
            $mid = (int)$movement['id'];
            if ($mid <= 0) continue;
            if ((int)($movement['is_cancelled'] ?? 0) === 0) {
                $pdo->prepare("UPDATE movements
                    SET is_cancelled=1,
                        cancelled_at=?,
                        cancelled_by=?,
                        cancel_reason=?,
                        updated_at=?
                    WHERE id=?")
                    ->execute([
                        now(),
                        current_user()['id'] ?? null,
                        'Bayrak Gross 250.000 TL çek yeniden giriş için temizlendi',
                        now(),
                        $mid,
                    ]);
                sync_movement_account_transaction($mid);
                $cancelledMovementIds[] = $mid;
            }
        }

        if ($checkIds) {
            $ph = implode(',', array_fill(0, count($checkIds), '?'));
            $pdo->prepare("DELETE FROM account_transactions WHERE source_type='check' AND source_id IN ($ph)")
                ->execute($checkIds);
            $pdo->prepare("UPDATE movements SET check_id=NULL, updated_at=? WHERE check_id IN ($ph)")
                ->execute(array_merge([now()], $checkIds));
        }

        foreach ($checks as $check) {
            $id = (int)$check['id'];
            audit_action('cek', $id, 'yeniden_giris_icin_silindi', $check, [
                'deleted'=>true,
                'reason'=>'Bayrak Gross 19.09.2026 / 250.000 TL kayıtları temizlendi; yeniden çek girilecek',
                'financial_movements_deleted'=>false,
            ], (string)($check['cari_name'] ?? 'Bayrak Gross'));
        }

        if ($checkIds) {
            $ph = implode(',', array_fill(0, count($checkIds), '?'));
            $pdo->prepare("DELETE FROM checks WHERE id IN ($ph)")->execute($checkIds);
        }

        log_action(
            'Bayrak Gross çek kayıtları yeniden giriş için temizlendi',
            '19.09.2026 / 250.000 TL · silinen çek satırı: '.count($checkIds).' · iptal edilen finansal hareket: '.count($cancelledMovementIds)
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $after = cari_balance($cariId);

    bayrak_cek_json([
        'ok'=>true,
        'deleted'=>true,
        'deleted_check_count'=>count($checkIds),
        'cancelled_movement_count'=>count($cancelledMovementIds),
        'cari_id'=>$cariId,
        'before_net_alacak'=>(float)($before['net_alacak'] ?? 0),
        'after_net_alacak'=>(float)($after['net_alacak'] ?? 0),
        'message'=>'Bayrak Gross 19.09.2026 / 250.000 TL eski çek kayıtları tamamen kaldırıldı. Çeke bağlı finansal hareketler silinmedi; iptal geçmişinde korundu. Cari bakiye artık çek yeniden girilmeden önceki durumu gösterir.',
    ]);
} catch (Throwable $e) {
    bayrak_cek_json(['ok'=>false,'error'=>$e->getMessage()], 422);
}
