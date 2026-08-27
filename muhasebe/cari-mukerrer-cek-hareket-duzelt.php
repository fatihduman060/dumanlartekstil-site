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

    $category = trim((string)($target['category_name'] ?? ''));
    $checkLike = movement_is_check_like($target) || strcasecmp($category, 'Çek') === 0 || stripos((string)($target['payment_method'] ?? ''), 'çek') !== false || stripos((string)($target['document_type'] ?? ''), 'çek') !== false;
    if (!$checkLike) {
        throw new RuntimeException('Bu hareket çek hareketi olarak doğrulanamadı. İşlem yapılmadı.');
    }

    // Aynı cari/tarih/vade/tip/tutarda belgesi olan gerçek hareketi bul.
    // Çek bağlantısı yanlışlıkla görselsiz satıra bağlı olsa bile görselli satır korunur.
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
            ORDER BY CASE WHEN COALESCE(TRIM(m.document_path),'')<>'' THEN 0 ELSE 1 END, m.id ASC";
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
        // Belgeli tek kayıt varsa onu güvenle seç; birden fazla belgeli kayıt varsa dur.
        $documentKeepers = array_values(array_filter($keepers, function($row) {
            return trim((string)($row['document_path'] ?? '')) !== '';
        }));
        if (count($documentKeepers) === 1) {
            $keepers = $documentKeepers;
        } else {
            throw new RuntimeException('Birden fazla korunacak hareket bulundu. Yanlış kayıt iptal edilmesin diye işlem durduruldu.');
        }
    }

    $keep = $keepers[0];
    $keepId = (int)$keep['id'];

    // Tek gerçek çek kaydını iki hareketten hangisine bağlı olursa olsun bul.
    $relatedIds = array_values(array_unique(array_filter([
        (int)($target['check_id'] ?? 0),
        (int)($keep['check_id'] ?? 0),
    ])));
    $checkRows = [];
    $checkSql = "SELECT * FROM checks WHERE COALESCE(is_cancelled,0)=0 AND (movement_id IN (?,?)";
    $checkParams = [$movementId, $keepId];
    if ($relatedIds) {
        $placeholders = implode(',', array_fill(0, count($relatedIds), '?'));
        $checkSql .= " OR id IN ($placeholders)";
        foreach ($relatedIds as $rid) $checkParams[] = $rid;
    }
    $checkSql .= ") ORDER BY id ASC LIMIT 3";
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute($checkParams);
    $checkRows = $checkStmt->fetchAll() ?: [];

    // Eski bağlantılar boşsa aynı cari/tutar/vade üzerinden tek gerçek çek kaydını bul.
    if (!$checkRows) {
        $direction = null;
        if ((string)$target['movement_type'] === 'tahsilat') $direction = 'alinacak';
        elseif ((string)$target['movement_type'] === 'odeme') $direction = 'verilecek';
        if ($direction !== null) {
            $checkStmt = $pdo->prepare("SELECT * FROM checks WHERE COALESCE(is_cancelled,0)=0 AND cari_id=? AND direction=? AND ABS(amount-?)<0.01 AND due_date=? ORDER BY id ASC LIMIT 3");
            $checkStmt->execute([$cariId, $direction, (float)$target['amount'], (string)($target['due_date'] ?? '')]);
            $checkRows = $checkStmt->fetchAll() ?: [];
        }
    }
    if (count($checkRows) > 1) {
        throw new RuntimeException('Aynı bilgilerde birden fazla aktif çek kaydı bulundu. Yanlış bağlantı kurulmasın diye işlem durduruldu.');
    }
    $realCheck = count($checkRows) === 1 ? $checkRows[0] : null;
    $realCheckId = $realCheck ? (int)$realCheck['id'] : 0;

    $pdo->beginTransaction();
    try {
        // Gerçek çek yanlışlıkla görselsiz harekete bağlıysa, önce görselli harekete taşı.
        if ($realCheckId > 0) {
            $pdo->prepare('UPDATE movements SET check_id=NULL, updated_at=? WHERE id=? AND check_id=?')
                ->execute([now(), $movementId, $realCheckId]);
            $pdo->prepare('UPDATE movements SET check_id=?, updated_at=? WHERE id=?')
                ->execute([$realCheckId, now(), $keepId]);
            $pdo->prepare('UPDATE checks SET movement_id=?, updated_at=? WHERE id=?')
                ->execute([$keepId, now(), $realCheckId]);
        }

        $reason = 'Mükerrer çek hareketi — görselsiz kopya; hareket #' . $keepId . ' korundu' . ($realCheckId > 0 ? ', çek #' . $realCheckId . ' bu harekete bağlandı' : '');
        $pdo->prepare("UPDATE movements SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=? WHERE id=? AND COALESCE(is_cancelled,0)=0")
            ->execute([now(), current_user()['id'] ?? null, $reason, now(), $movementId]);

        // Bu özel düzeltmede çek iptal senkronu çağrılmaz; gerçek çek aktif kalır.
        sync_movement_account_transaction($movementId);
        if ($realCheckId > 0) {
            sync_check_account_transaction($realCheckId);
            sync_check_balance_adjustment($realCheckId);
            sync_check_unpaid_movement($realCheckId);
        }

        audit_action('hareket', $movementId, 'mukerrer_cek_hareketi_iptal', $target, [
            'is_cancelled'=>1,
            'cancel_reason'=>$reason,
            'kept_movement_id'=>$keepId,
            'relinked_check_id'=>$realCheckId ?: null,
        ], 'Cari #' . $cariId);
        if ($realCheckId > 0) {
            audit_action('cek', $realCheckId, 'hareket_baglantisi_duzeltildi', $realCheck, [
                'movement_id'=>$keepId,
                'old_duplicate_movement_id'=>$movementId,
            ], 'Mükerrer cari hareket düzeltmesi');
        }
        log_action('Mükerrer çek cari hareketi iptal edildi', '#' . $movementId . ' / korunan #' . $keepId . ' / ' . money((float)$target['amount']));

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    cmch_json([
        'ok'=>true,
        'message'=>'Görselsiz mükerrer çek hareketi iptal edildi. Cari bakiye tek çek tutarına döndü; görselli hareket ve gerçek çek kaydı korundu.',
        'cancelled_movement_id'=>$movementId,
        'kept_movement_id'=>$keepId,
        'check_id'=>$realCheckId ?: null,
        'amount'=>(float)$target['amount'],
    ]);
} catch (Throwable $e) {
    cmch_json(['ok'=>false,'error'=>$e->getMessage()], 422);
}
