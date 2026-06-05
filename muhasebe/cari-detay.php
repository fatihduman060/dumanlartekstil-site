<?php
require_once __DIR__ . '/layout.php';
require_login();
$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM cariler WHERE id=?');
$stmt->execute([$id]);
$cari = $stmt->fetch();
if (!$cari) { flash('error','Cari bulunamadı.'); redirect('cariler.php'); }
$balance = cari_balance($id);
$stmt = db()->prepare("SELECT m.*, cat.name AS category_name, u.display_name AS user_name FROM movements m LEFT JOIN categories cat ON cat.id=m.category_id LEFT JOIN users u ON u.id=m.created_by WHERE m.cari_id=? ORDER BY m.movement_date DESC, m.id DESC");
$stmt->execute([$id]);
$movements = $stmt->fetchAll();
page_header($cari['name'], 'cariler');
?>
<section class="hero-card detail-hero">
  <div>
    <span class="status-pill"><?php echo e($cari['cari_type']); ?></span>
    <h2><?php echo e($cari['name']); ?></h2>
    <p><?php echo e($cari['address'] ?: $cari['notes'] ?: 'Cari detayları ve hareket geçmişi.'); ?></p>
  </div>
  <div class="hero-actions"><?php if (can_write()): ?><a class="btn btn-primary" href="hareketler.php?cari_id=<?php echo e($id); ?>">Hareket ekle</a><a class="btn btn-secondary" href="cariler.php?edit=<?php echo e($id); ?>">Cariyi düzenle</a><?php endif; ?></div>
</section>

<section class="stats-grid four">
  <article class="stat-card"><span>Alacak</span><strong><?php echo e(money($balance['alacak'])); ?></strong><small>Tahsilat: <?php echo e(money($balance['tahsilat'])); ?></small></article>
  <article class="stat-card"><span>Kalan alacak</span><strong><?php echo e(money($balance['net_alacak'])); ?></strong><small>Alacak - tahsilat</small></article>
  <article class="stat-card"><span>Verecek</span><strong><?php echo e(money($balance['verecek'])); ?></strong><small>Ödeme: <?php echo e(money($balance['odeme'])); ?></small></article>
  <article class="stat-card"><span>Net bakiye</span><strong class="<?php echo $balance['net'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($balance['net'])); ?></strong><small><?php echo $balance['net'] >= 0 ? 'Alacaklı' : 'Borçlu'; ?> durum</small></article>
</section>

<section class="panel-card">
  <div class="card-head"><h3>Hareket geçmişi</h3><a href="export.php?type=movements&cari_id=<?php echo e($id); ?>">Excel CSV indir</a></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Tarih</th><th>Vade</th><th>Tip</th><th>Kategori</th><th>Açıklama</th><th>Belge</th><th class="right">Tutar</th></tr></thead>
      <tbody>
        <?php if (!$movements): ?><tr><td colspan="7" class="empty">Bu cariye ait hareket yok.</td></tr><?php endif; ?>
        <?php foreach ($movements as $m): ?>
          <tr>
            <td><?php echo e(tr_date($m['movement_date'])); ?></td>
            <td><?php echo e(tr_date($m['due_date'])); ?></td>
            <td><?php echo badge(movement_label($m['movement_type']), movement_tone($m['movement_type'])); ?></td>
            <td><?php echo e($m['category_name'] ?: '-'); ?></td>
            <td><?php echo e($m['description'] ?: '-'); ?><small><?php echo e($m['payment_method'] ?: ''); ?></small></td>
            <td><?php echo $m['document_path'] ? '<a href="belge-indir.php?id=' . e($m['id']) . '" target="_blank">Belge</a>' : '-'; ?></td>
            <td class="right"><strong><?php echo e(money($m['amount'])); ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php page_footer(); ?>
