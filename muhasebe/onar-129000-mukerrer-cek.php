<?php
require_once __DIR__ . '/layout.php';
require_login();
require_write();

function repair129_candidates(): array
{
    $pdo = db();
    $sql = "SELECT m.*, cat.name AS category_name,
                   CASE WHEN COALESCE(TRIM(m.document_path),'')<>'' THEN 1 ELSE 0 END AS has_document,
                   (SELECT COUNT(*) FROM checks ch WHERE COALESCE(ch.is_cancelled,0)=0 AND (ch.movement_id=m.id OR ch.id=m.check_id)) AS direct_check_count
            FROM movements m
            LEFT JOIN categories cat ON cat.id=m.category_id
            WHERE COALESCE(m.is_cancelled,0)=0
              AND ABS(m.amount-129000)<0.01
            ORDER BY m.cari_id, m.movement_date, COALESCE(m.due_date,''), m.movement_type, m.id";
    $rows = $pdo->query($sql)->fetchAll() ?: [];
    $groups = [];
    foreach ($rows as $row) {
        $key = implode('|', [
            (int)($row['cari_id'] ?? 0),
            (string)($row['movement_type'] ?? ''),
            (string)($row['movement_date'] ?? ''),
            (string)($row['due_date'] ?? ''),
        ]);
        $groups[$key][] = $row;
    }

    $candidates = [];
    foreach ($groups as $group) {
        if (count($group) !== 2) continue;
        $withDoc = array_values(array_filter($group, function ($r) {
            return (int)($r['has_document'] ?? 0) === 1;
        }));
        $withoutDoc = array_values(array_filter($group, function ($r) {
            return (int)($r['has_document'] ?? 0) === 0;
        }));
        if (count($withDoc) !== 1 || count($withoutDoc) !== 1) continue;

        $keep = $withDoc[0];
        $duplicate = $withoutDoc[0];
        $cariId = (int)($keep['cari_id'] ?? 0);
        if ($cariId <= 0) continue;

        $checkLike = function (array $r): bool {
            $category = trim((string)($r['category_name'] ?? ''));
            return movement_is_check_like($r)
                || strcasecmp($category, 'Çek') === 0
                || stripos((string)($r['payment_method'] ?? ''), 'çek') !== false
                || stripos((string)($r['document_type'] ?? ''), 'çek') !== false
                || (int)($r['direct_check_count'] ?? 0) > 0;
        };
        if (!$checkLike($keep) && !$checkLike($duplicate)) continue;

        $checkSql = "SELECT * FROM checks
                     WHERE COALESCE(is_cancelled,0)=0
                       AND cari_id=?
                       AND ABS(amount-129000)<0.01
                       AND COALESCE(due_date,'')=COALESCE(?, '')
                     ORDER BY id";
        $stmt = $pdo->prepare($checkSql);
        $stmt->execute([$cariId, $keep['due_date'] ?? null]);
        $checks = $stmt->fetchAll() ?: [];
        if (count($checks) !== 1) continue;

        $candidates[] = [
            'keep' => $keep,
            'duplicate' => $duplicate,
            'check' => $checks[0],
        ];
    }
    return $candidates;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $candidates = repair129_candidates();
    if (count($candidates) !== 1) {
        flash('error', count($candidates) === 0
            ? 'Güvenli biçimde eşleşen 129.000 TL mükerrer çek çifti bulunamadı. Hiçbir kayıt değiştirilmedi.'
            : 'Birden fazla 129.000 TL mükerrer çek çifti bulundu. Yanlış kayıt değişmesin diye işlem yapılmadı.');
        redirect('onar-129000-mukerrer-cek.php');
    }

    $candidate = $candidates[0];
    $keep = $candidate['keep'];
    $duplicate = $candidate['duplicate'];
    $check = $candidate['check'];
    $cariId = (int)$keep['cari_id'];
    $keepId = (int)$keep['id'];
    $duplicateId = (int)$duplicate['id'];
    $checkId = (int)$check['id'];
    $pdo = db();

    try {
        $pdo->beginTransaction();

        // Tek gerçek çek daima görselli hareketle bağlı kalsın.
        $pdo->prepare('UPDATE checks SET movement_id=?, updated_at=? WHERE id=?')
            ->execute([$keepId, now(), $checkId]);
        $pdo->prepare('UPDATE movements SET check_id=?, updated_at=? WHERE id=?')
            ->execute([$checkId, now(), $keepId]);
        $pdo->prepare('UPDATE movements SET check_id=NULL, updated_at=? WHERE id=?')
            ->execute([now(), $duplicateId]);

        // Görselsiz fazlalık kayıt silinmez; denetim geçmişi için iptal edilir.
        $reason = 'Eski çek görseli hatasından oluşan mükerrer 129.000 TL hareket; görselli hareket #' . $keepId . ' ve çek #' . $checkId . ' korundu';
        $pdo->prepare('UPDATE movements SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=? WHERE id=? AND COALESCE(is_cancelled,0)=0')
            ->execute([now(), current_user()['id'] ?? null, $reason, now(), $duplicateId]);

        sync_movement_account_transaction($duplicateId);
        sync_check_to_movement($checkId, true);
        sync_check_account_transaction($checkId);
        sync_check_balance_adjustment($checkId);
        sync_check_unpaid_movement($checkId);

        audit_action('hareket', $duplicateId, 'mukerrer_129000_cek_iptal', $duplicate, [
            'is_cancelled'=>1,
            'kept_movement_id'=>$keepId,
            'check_id'=>$checkId,
            'cancel_reason'=>$reason,
        ], 'Cari #' . $cariId);
        audit_action('cek', $checkId, 'mukerrer_hareket_baglantisi_duzeltildi', $check, [
            'movement_id'=>$keepId,
            'duplicate_movement_id'=>$duplicateId,
        ], 'Cari #' . $cariId);
        log_action('129.000 TL mükerrer çek hareketi düzeltildi', 'Cari #' . $cariId . ' · iptal #' . $duplicateId . ' · korunan #' . $keepId . ' · çek #' . $checkId);

        $pdo->commit();
        flash('success', '129.000 TL mükerrer çek hareketi düzeltildi. Görselsiz fazlalık cari hareketi iptal edildi; görselli hareket ve gerçek çek kaydı korundu.');
        redirect('cari-detay.php?id=' . $cariId . '#hareketler');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', 'Düzeltme yapılamadı: ' . $e->getMessage());
        redirect('onar-129000-mukerrer-cek.php');
    }
}

$candidates = repair129_candidates();
page_header('129.000 TL Çek Düzeltme', 'cariler');
?>
<section class="panel-card" style="max-width:820px;margin:20px auto;padding:22px">
  <h2 style="margin-top:0">129.000 TL mükerrer çek düzeltmesi</h2>
  <?php if (count($candidates) === 1):
      $c = $candidates[0];
      $keep = $c['keep']; $dup = $c['duplicate']; $ch = $c['check'];
      $nameStmt = db()->prepare('SELECT name FROM cariler WHERE id=?');
      $nameStmt->execute([(int)$keep['cari_id']]);
      $cariName = (string)($nameStmt->fetchColumn() ?: ('Cari #' . (int)$keep['cari_id']));
  ?>
    <p><strong><?php echo e($cariName); ?></strong> carisinde güvenli eşleşme bulundu.</p>
    <p>Görselli hareket <strong>#<?php echo e($keep['id']); ?></strong> korunacak. Görselsiz mükerrer hareket <strong>#<?php echo e($dup['id']); ?></strong> iptal edilecek. Gerçek çek <strong>#<?php echo e($ch['id']); ?></strong> görselli harekete bağlanacak.</p>
    <p><strong>Tutar:</strong> <?php echo e(money(129000)); ?> · <strong>Vade:</strong> <?php echo e(tr_date((string)$keep['due_date'])); ?></p>
    <form method="post" onsubmit="return confirm('Görselsiz mükerrer 129.000 TL hareket iptal edilsin ve gerçek çek görselli harekete bağlansın mı?');">
      <?php echo csrf_field(); ?>
      <button type="submit" class="btn btn-primary">129.000 TL mükerrer kaydı düzelt</button>
    </form>
  <?php elseif (count($candidates) === 0): ?>
    <p>Güvenli eşleşen bir çift bulunamadı. Hiçbir veri değiştirilmedi.</p>
  <?php else: ?>
    <p>Birden fazla güvenli eşleşme bulundu. Yanlış kaydı değiştirmemek için otomatik işlem kapatıldı.</p>
  <?php endif; ?>
</section>
<?php page_footer(); ?>
