<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_login();
require_write();

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

try {
    $pdo = db();
    $id = (int)($_REQUEST['id'] ?? 0);
    $target = null;

    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT ch.*, c.name AS cari_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id WHERE ch.id=? LIMIT 1");
        $stmt->execute([$id]);
        $target = $stmt->fetch() ?: null;
    } else {
        // Kullanıcının tarif ettiği Yiğido iptal çekini güvenli biçimde bul.
        // Birden fazla eşleşme varsa yanlış çeki açmamak için işlem yapma.
        $rows = $pdo->query("SELECT ch.*, c.name AS cari_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id WHERE COALESCE(ch.is_cancelled,0)=1 ORDER BY ch.cancelled_at DESC, ch.id DESC")->fetchAll() ?: [];
        $matches = [];
        foreach ($rows as $row) {
            $name = cek_geri_al_normalize((string)($row['cari_name'] ?? ''));
            if (strpos($name, 'YIGIDO') !== false) $matches[] = $row;
        }
        if (count($matches) === 1) $target = $matches[0];
        elseif (count($matches) > 1) throw new RuntimeException('Yiğido adına birden fazla iptal çek var. Yanlış kaydı açmamak için çek numarası veya tutar ile seçim yapılmalı.');
        else throw new RuntimeException('Yiğido adına iptal edilmiş çek bulunamadı.');
    }

    if (!$target) throw new RuntimeException('Çek bulunamadı.');
    if ((int)($target['is_cancelled'] ?? 0) !== 1) {
        cek_geri_al_json(['ok'=>true,'message'=>'Çek zaten aktif.','id'=>(int)$target['id']]);
    }

    $checkId = (int)$target['id'];
    $direction = (string)($target['direction'] ?? 'alinacak');
    $movementId = (int)($target['movement_id'] ?? 0);
    $restoreStatus = 'bekliyor';

    $pdo->beginTransaction();
    try {
        // Çeki normal portföye geri al. Tahsil edilmiş gibi işaretleme; bekliyor durumuna dönsün.
        $pdo->prepare("UPDATE checks SET is_cancelled=0, status=?, closed_at=NULL, cancelled_at=NULL, cancelled_by=NULL, cancel_reason=NULL, updated_at=? WHERE id=?")
            ->execute([$restoreStatus, now(), $checkId]);

        // İptal sırasında bağlı cari hareketi de iptal edilmişse aynı hareketi geri aç.
        if ($movementId > 0) {
            $mStmt = $pdo->prepare("SELECT * FROM movements WHERE id=? LIMIT 1");
            $mStmt->execute([$movementId]);
            $movement = $mStmt->fetch();
            if ($movement && (int)($movement['is_cancelled'] ?? 0) === 1) {
                $reason = (string)($movement['cancel_reason'] ?? '');
                if ($reason === '' || strpos($reason, 'Bağlı çek iptal edildi') !== false || strpos($reason, 'çek') !== false || strpos($reason, 'Çek') !== false) {
                    $pdo->prepare("UPDATE movements SET is_cancelled=0, cancelled_at=NULL, cancelled_by=NULL, cancel_reason=NULL, updated_at=? WHERE id=?")
                        ->execute([now(), $movementId]);
                    sync_movement_account_transaction($movementId);
                }
            }
        }

        // Çek-movement/kasa-banka bağlarını mevcut tek kaynak senkronuyla yeniden kur.
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

    cek_geri_al_json([
        'ok'=>true,
        'message'=>'Çek iptallerden çıkarıldı ve normal çek listesine geri alındı. Bağlı cari hareketi de yeniden aktif edildi.',
        'id'=>$checkId,
        'direction'=>$direction,
        'cari_name'=>(string)($target['cari_name'] ?? ''),
        'amount'=>(float)$target['amount'],
    ]);
} catch (Throwable $e) {
    cek_geri_al_json(['ok'=>false,'error'=>$e->getMessage()], 422);
}
