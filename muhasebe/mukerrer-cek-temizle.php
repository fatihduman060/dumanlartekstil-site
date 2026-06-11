<?php
require_once __DIR__ . '/layout.php';
require_admin();

$duplicates = [16, 37, 48];
$kept = [16 => 2, 37 => 3, 48 => 1];
$reason = 'Mükerrer çek kaydı - kullanıcı onayıyla canlı sistemde iptal edildi';

function cleanup_check_rows(array $ids): array
{
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT ch.*, c.name AS cari_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id WHERE ch.id IN ($placeholders) ORDER BY ch.id ASC");
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

function cleanup_related_movement_ids(array $check): array
{
    $ids = [];
    foreach (['movement_id', 'adjustment_movement_id'] as $field) {
        if (!empty($check[$field])) $ids[] = (int)$check[$field];
    }
    $stmt = db()->prepare('SELECT id FROM movements WHERE check_id=?');
    $stmt->execute([(int)$check['id']]);
    foreach ($stmt->fetchAll() as $row) $ids[] = (int)$row['id'];
    return array_values(array_unique(array_filter($ids)));
}

$rowsBefore = cleanup_check_rows($duplicates);
$allMovementIds = [];
foreach ($rowsBefore as $row) {
    $allMovementIds = array_merge($allMovementIds, cleanup_related_movement_ids($row));
}
$allMovementIds = array_values(array_unique(array_filter($allMovementIds)));

$done = false;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $confirm = trim($_POST['confirm'] ?? '');
    if ($confirm !== 'MUKERRER IPTAL') {
        flash('error', 'Onay alanına MUKERRER IPTAL yazılmalı.');
        redirect('mukerrer-cek-temizle.php');
    }

    $now = now();
    $user = current_user();
    $userId = $user['id'] ?? null;
    $cancelledChecks = 0;
    $cancelledMovements = 0;

    db()->beginTransaction();
    try {
        foreach ($rowsBefore as $check) {
            $checkId = (int)$check['id'];
            $movementIds = cleanup_related_movement_ids($check);

            db()->prepare('UPDATE checks SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=? WHERE id=?')
                ->execute([$now, $userId, $reason, $now, $checkId]);
            $cancelledChecks++;

            if ($movementIds) {
                $mPlaceholders = implode(',', array_fill(0, count($movementIds), '?'));
                db()->prepare("UPDATE movements SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=? WHERE id IN ($mPlaceholders)")
                    ->execute(array_merge([$now, $userId, $reason, $now], $movementIds));
                $cancelledMovements += count($movementIds);
                foreach ($movementIds as $movementId) {
                    sync_movement_account_transaction((int)$movementId);
                }
            }

            sync_check_account_transaction($checkId);
            log_action('Mükerrer çek iptal edildi', '#' . $checkId . ' / kalan kayıt #' . ($GLOBALS['kept'][$checkId] ?? '-') . ' / ' . money((float)$check['amount']));
            audit_action('cek', $checkId, 'mukerrer_iptal', $check, ['is_cancelled'=>1,'cancel_reason'=>$reason], 'Mükerrer çek canlı sistemde iptal edildi');
        }
        db()->commit();
        $done = true;
        $result = ['checks' => $cancelledChecks, 'movements' => $cancelledMovements];
    } catch (Throwable $e) {
        db()->rollBack();
        flash('error', 'Temizlik başarısız: ' . $e->getMessage());
        redirect('mukerrer-cek-temizle.php');
    }
}

$rowsAfter = cleanup_check_rows($duplicates);
page_header('Mükerrer Çek Temizleme', 'cekler');
?>
<section class="panel-card">
  <div class="card-head">
    <h3>Mükerrer çek temizleme</h3>
    <a href="cekler.php">Çeklere dön</a>
  </div>
  <?php if ($done): ?>
    <div class="alert alert-success">
      İşlem tamamlandı. <?php echo e($result['checks']); ?> çek ve <?php echo e($result['movements']); ?> bağlı hareket iptal edildi.
    </div>
  <?php else: ?>
    <div class="security-note">
      Bu geçici araç #16, #37 ve #48 numaralı mükerrer çekleri fiziksel silmez; güvenli şekilde <strong>iptal edildi</strong> durumuna alır. Asıl kayıtlar #1, #2 ve #3 kalır.
    </div>
  <?php endif; ?>

  <div class="table-wrap">
    <table>
      <thead><tr><th>İptal edilecek</th><th>Kalacak</th><th>Cari</th><th>Banka / Çek No</th><th class="right">Tutar</th><th>Vade</th><th>Durum</th></tr></thead>
      <tbody>
        <?php foreach ($rowsAfter as $row): ?>
          <tr>
            <td>#<?php echo e($row['id']); ?></td>
            <td>#<?php echo e($kept[(int)$row['id']] ?? '-'); ?></td>
            <td><?php echo e($row['cari_name'] ?: '-'); ?></td>
            <td><?php echo e(trim(($row['bank_name'] ?: '-') . ' / ' . ($row['check_no'] ?: '-'))); ?></td>
            <td class="right"><strong><?php echo e(money((float)$row['amount'])); ?></strong></td>
            <td><?php echo e(tr_date($row['due_date'])); ?></td>
            <td><?php echo ((int)($row['is_cancelled'] ?? 0) === 1) ? badge('İptal edildi', 'neutral') : badge('Aktif', 'danger'); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (!$done): ?>
    <form method="post" class="stack-form" onsubmit="return confirm('Mükerrer çekler iptal edilecek. Devam edilsin mi?');" style="margin-top:16px">
      <?php echo csrf_field(); ?>
      <label>Onay için yazın: <strong>MUKERRER IPTAL</strong><input name="confirm" placeholder="MUKERRER IPTAL" required></label>
      <button class="btn btn-danger" type="submit">Mükerrerleri iptal et</button>
    </form>
  <?php endif; ?>
</section>
<?php page_footer(); ?>
