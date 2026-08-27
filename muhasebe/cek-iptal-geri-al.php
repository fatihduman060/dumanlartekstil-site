<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_login();
require_write();

const CEK_GERI_AL_YIGIDO_KEY = 'yigido-20260827-7f4c91';

function cek_geri_al_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cek_geri_al_normalize(string $value): string
{
    $value = strtoupper(strtr(trim($value), [
        'Ç'=>'C','Ğ'=>'G','İ'=>'I','Ö'=>'O','Ş'=>'S','Ü'=>'U',
        'ç'=>'C','ğ'=>'G','ı'=>'I','i'=>'I','ö'=>'O','ş'=>'S','ü'=>'U',
    ]));
    return preg_replace('/[^A-Z0-9]+/', '', $value) ?: $value;
}

function cek_geri_al_fail(string $message): void
{
    if (!empty($_GET['key'])) {
        flash('error', $message);
        redirect('cekler.php?include_cancelled=1');
    }
    cek_geri_al_json(['ok'=>false,'error'=>$message], 422);
}

try {
    $pdo = db();
    $id = (int)($_REQUEST['id'] ?? 0);
    $target = null;

    if ($id > 0) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Çek numarasıyla geri alma işlemi POST ile yapılmalıdır.');
        if (!verify_csrf($_POST['csrf_token'] ?? null)) throw new RuntimeException('Oturum doğrulaması yenilenmeli.');
        $stmt = $pdo->prepare("SELECT ch.*, c.name AS cari_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id WHERE ch.id=? LIMIT 1");
        $stmt->execute([$id]);
        $target = $stmt->fetch() ?: null;
    } else {
        if (!hash_equals(CEK_GERI_AL_YIGIDO_KEY, (string)($_GET['key'] ?? ''))) {
            throw new RuntimeException('Geri alma bağlantısı geçersiz.');
        }
        $rows = $pdo->query("SELECT ch.*, c.name AS cari_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id WHERE COALESCE(ch.is_cancelled,0)=1 ORDER BY ch.cancelled_at DESC, ch.id DESC")->fetchAll() ?: [];
        $matches = [];
        foreach ($rows as $row) {
            $name = cek_geri_al_normalize((string)($row['cari_name'] ?? ''));
            if (strpos($name, 'YIGIDO') !== false) $matches[] = $row;
        }
        if (count($matches) === 1) $target = $matches[0];
        elseif (count($matches) > 1) cek_geri_al_fail('Yiğido adına birden fazla iptal çek var. Yanlış kaydı açmamak için işlem yapılmadı.');
        else cek_geri_al_fail('Yiğido adına iptal edilmiş çek bulunamadı.');
    }

    if (!$target) cek_geri_al_fail('Çek bulunamadı.');
    if ((int)($target['is_cancelled'] ?? 0) !== 1) {
        flash('success', 'Çek zaten aktif durumda.');
        redirect('cekler.php?direction=' . urlencode((string)($target['direction'] ?? 'alinacak')));
    }

    $checkId = (int)$target['id'];
    $direction = (string)($target['direction'] ?? 'alinacak');
    $movementId = (int)($target['movement_id'] ?? 0);
    $restoreStatus = 'bekliyor';

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE checks SET is_cancelled=0, status=?, closed_at=NULL, cancelled_at=NULL, cancelled_by=NULL, cancel_reason=NULL, updated_at=? WHERE id=?")
            ->execute([$restoreStatus, now(), $checkId]);

        if ($movementId > 0) {
            $mStmt = $pdo->prepare("SELECT * FROM movements WHERE id=? LIMIT 1");
            $mStmt->execute([$movementId]);
            $movement = $mStmt->fetch();
            if ($movement && (int)($movement['is_cancelled'] ?? 0) === 1) {
                $reason = (string)($movement['cancel_reason'] ?? '');
                $checkRelated = $reason === '' || strpos($reason, 'Bağlı çek iptal edildi') !== false || stripos($reason, 'çek') !== false;
                if ($checkRelated) {
                    $pdo->prepare("UPDATE movements SET is_cancelled=0, cancelled_at=NULL, cancelled_by=NULL, cancel_reason=NULL, updated_at=? WHERE id=?")
                        ->execute([now(), $movementId]);
                    sync_movement_account_transaction($movementId);
                }
            }
        }

        sync_check_to_movement($checkId, true);
        sync_check_account_transaction($checkId);
        sync_check_balance_adjustment($checkId);
        sync_check_unpaid_movement($checkId);

        audit_action('cek', $checkId, 'iptal_geri_alindi', $target, [
            'is_cancelled'=>0,
            'status'=>$restoreStatus,
            'movement_id'=>$movementId ?: null,
        ], (string)($target['cari_name'] ?? ('#'.$checkId)));
        log_action('Çek iptali geri alındı', '#' . $checkId . ' ' . (string)($target['cari_name'] ?? '') . ' ' . money((float)$target['amount']));

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    if (!empty($_GET['key'])) {
        flash('success', 'Yiğido çeki iptallerden çıkarıldı. Çek ve bağlı cari hareketi tekrar aktif edildi.');
        redirect('cekler.php?direction=' . urlencode($direction));
    }

    cek_geri_al_json([
        'ok'=>true,
        'message'=>'Çek iptallerden çıkarıldı ve normal çek listesine geri alındı. Bağlı cari hareketi de yeniden aktif edildi.',
        'id'=>$checkId,
        'direction'=>$direction,
        'cari_name'=>(string)($target['cari_name'] ?? ''),
        'amount'=>(float)$target['amount'],
    ]);
} catch (Throwable $e) {
    cek_geri_al_fail($e->getMessage());
}
