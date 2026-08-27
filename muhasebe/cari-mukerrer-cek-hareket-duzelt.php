<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
require_write();
header('Content-Type: application/json; charset=utf-8');

function cmch_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Bu işlem yalnızca cari ekranından yapılabilir.');
    }
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        throw new RuntimeException('Oturum doğrulaması yenilenmeli. Sayfayı yenileyin.');
    }

    $movementId = (int)($_POST['movement_id'] ?? 0);
    $cariId = (int)($_POST['cari_id'] ?? 0);
    if ($movementId <= 0 || $cariId <= 0) {
        throw new RuntimeException('Hareket bilgisi eksik.');
    }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT m.*, cat.name AS category_name FROM movements m LEFT JOIN categories cat ON cat.id=m.category_id WHERE m.id=? AND m.cari_id=? LIMIT 1");
    $stmt->execute([$movementId, $cariId]);
    $target = $stmt->fetch();
    if (!$target) throw new RuntimeException('Cari hareketi bulunamadı.');
    if ((int)($target['is_cancelled'] ?? 0) === 1) {
        cmch_json(['ok'=>true,'message'=>'Bu mükerrer hareket zaten iptal edilmiş.']);
    }

    if (!empty($target['document_path'])) {
        throw new RuntimeException('Bu hareketin belgesi var. Görselli gerçek hareket iptal edilmedi.');
    }
    if (!empty($target['check_id'])) {
        throw new RuntimeException('Bu hareket gerçek çek kaydına bağlı. İptal edilmedi.');
    }

    $category = trim((string)($target['category_name'] ?? ''));
    $checkLike = movement_is_check_like($target) || strcasecmp($category, 'Çek') === 0 || stripos((string)($target['payment_method'] ?? ''), 'çek') !== false || stripos((string)($target['document_type'] ?? ''), 'çek') !== false;
    if (!$checkLike) {
        throw new RuntimeException('Bu hareket çek hareketi olarak doğrulanamadı. İşlem yapılmadı.');
    }

    $sql = "SELECT m.*, cat.name AS category_name,
                   EXISTS(SELECT 1 FROM checks ch WHERE ch.movement_id=m.id AND COALESCE(ch.is_cancelled,0)=0) AS has_linked_check
            FROM movements m
            LEFT JOIN categories cat ON cat.id=m.category_id
            WHERE m.cari_id=?
              AND m.id<>?
              AND COALESCE(m.is_cancelled,0)=0
              AND m.movement_type=?
              AND ABS(m.amount-?)<0.01
              AND m.movement_date=?
              AND COALESCE(m.due_date,'')=COALESCE(?, '')
              AND (
                    COALESCE(TRIM(m.document_path),'')<>''
                    OR m.check_id IS NOT NULL
                    OR EXISTS(SELECT 1 FROM checks ch2 WHERE ch2.movement_id=m.id AND COALESCE(ch2.is_cancelled,0)=0)
                  )
            ORDER BY m.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $cariId,
        $movementId,
        (string)$target['movement_type'],
        (float)$target['amount'],
        (string)$target['movement_date'],
        $target['due_date'] ?? null,
    ]);
    $keepers = $stmt->fetchAll() ?: [];

    if (count($keepers) === 0) {
        throw new RuntimeException('Aynı tarih ve tutarda korunacak görselli/bağlı çek hareketi bulunamadı. İşlem yapılmadı.');
    }
    if (count($keepers) > 1) {
        throw new RuntimeException('Birden fazla korunacak hareket bulundu. Yanlış kayıt iptal edilmesin diye işlem durduruldu.');
    }

    $keep = $keepers[0];
    $pdo->beginTransaction();
    try {
        $reason = 'Mükerrer çek hareketi — görselsiz ve gerçek çek kaydına bağlı değil; hareket #' . (int)$keep['id'] . ' korundu';
        $pdo->prepare("UPDATE movements SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=? WHERE id=? AND COALESCE(is_cancelled,0)=0")
            ->execute([now(), current_user()['id'] ?? null, $reason, now(), $movementId]);

        // Bu özel düzeltmede çek senkronunu çağırma. Amaç yalnızca fazladan cari etkisini kaldırmak.
        sync_movement_account_transaction($movementId);

        audit_action('hareket', $movementId, 'mukerrer_cek_hareketi_iptal', $target, [
            'is_cancelled'=>1,
            'cancel_reason'=>$reason,
            'kept_movement_id'=>(int)$keep['id'],
        ], 'Cari #' . $cariId);
        log_action('Mükerrer çek cari hareketi iptal edildi', '#' . $movementId . ' / korunan #' . (int)$keep['id'] . ' / ' . money((float)$target['amount']));

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    cmch_json([
        'ok'=>true,
        'message'=>'Görselsiz mükerrer çek hareketi iptal edildi. Cari bakiye tek çek tutarına döndü; görselli gerçek çek kaydı korundu.',
        'cancelled_movement_id'=>$movementId,
        'kept_movement_id'=>(int)$keep['id'],
        'amount'=>(float)$target['amount'],
    ]);
} catch (Throwable $e) {
    cmch_json(['ok'=>false,'error'=>$e->getMessage()], 422);
}
