<?php
require_once __DIR__ . '/layout.php';
require_login();
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$totals = dashboard_totals();
$monthTotals = dashboard_totals($monthStart, $monthEnd);
$cariCount = (int)db()->query('SELECT COUNT(*) FROM cariler')->fetchColumn();
$movementCount = (int)db()->query('SELECT COUNT(*) FROM movements')->fetchColumn();
$recent = db()->query("SELECT m.*, c.name AS cari_name, cat.name AS category_name FROM movements m LEFT JOIN cariler c ON c.id=m.cari_id LEFT JOIN categories cat ON cat.id=m.category_id ORDER BY m.movement_date DESC, m.id DESC LIMIT 8")->fetchAll();
$topStmt = db()->query("SELECT c.id, c.name,
    SUM(CASE WHEN m.movement_type='alacak' THEN m.amount ELSE 0 END) - SUM(CASE WHEN m.movement_type='tahsilat' THEN m.amount ELSE 0 END) AS net_alacak,
    SUM(CASE WHEN m.movement_type='verecek' THEN m.amount ELSE 0 END) - SUM(CASE WHEN m.movement_type='odeme' THEN m.amount ELSE 0 END) AS net_verecek
  FROM cariler c LEFT JOIN movements m ON m.cari_id=c.id GROUP BY c.id ORDER BY ABS((COALESCE(net_alacak,0)-COALESCE(net_verecek,0))) DESC LIMIT 6");
$topCariler = $topStmt->fetchAll();
page_header('Genel Bakış', 'dashboard');
?>
<section class="hero-card">
  <div>
    <span class="status-pill">Mini muhasebe sistemi aktif</span>
    <h2>Alacak, verecek, tahsilat, ödeme ve raporlar tek ekranda.</h2>
    <p>Bu panel iç takip amaçlıdır. Resmi muhasebe/e-fatura yerine geçmez; günlük cari ve kasa takibini düzenli tutmak için tasarlandı.</p>
  </div>
  <div class="hero-actions">
    <?php if (can_write()): ?>
      <a class="btn btn-primary" href="hareketler.php">Hareket ekle</a>
      <a class="btn btn-secondary" href="cariler.php">Cari ekle</a>
    <?php endif; ?>
  </div>
</section>

<section class="stats-grid">
  <article class="stat-card"><span>Toplam cari</span><strong><?php echo e($cariCount); ?></strong><small>Kişi / firma kartı</small></article>
  <article class="stat-card"><span>Toplam hareket</span><strong><?php echo e($movementCount); ?></strong><small>Kayıt adedi</small></article>
  <article class="stat-card"><span>Net alacak</span><strong><?php echo e(money($totals['net_alacak'])); ?></strong><small>Alacak - tahsilat</small></article>
  <article class="stat-card"><span>Net verecek</span><strong><?php echo e(money($totals['net_verecek'])); ?></strong><small>Verecek - ödeme</small></article>
</section>

<section class="stats-grid two">
  <article class="stat-card soft"><span>Bu ay tahsilat</span><strong><?php echo e(money($monthTotals['tahsilat'])); ?></strong><small><?php echo tr_date($monthStart); ?> - <?php echo tr_date($monthEnd); ?></small></article>
  <article class="stat-card soft"><span>Bu ay ödeme/gider</span><strong><?php echo e(money($monthTotals['odeme'] + $monthTotals['gider'])); ?></strong><small>Ödeme + gider toplamı</small></article>
</section>

<section class="content-grid">
  <article class="panel-card">
    <div class="card-head"><h3>Son hareketler</h3><a href="hareketler.php">Tümünü gör</a></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Tarih</th><th>Tip</th><th>Cari</th><th>Açıklama</th><th class="right">Tutar</th></tr></thead>
        <tbody>
          <?php if (!$recent): ?><tr><td colspan="5" class="empty">Henüz hareket yok.</td></tr><?php endif; ?>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td><?php echo e(tr_date($r['movement_date'])); ?></td>
              <td><?php echo badge(movement_label($r['movement_type']), movement_tone($r['movement_type'])); ?></td>
              <td><?php echo $r['cari_id'] ? '<a href="cari-detay.php?id=' . e($r['cari_id']) . '">' . e($r['cari_name']) . '</a>' : '-'; ?></td>
              <td><?php echo e($r['description'] ?: $r['category_name'] ?: '-'); ?></td>
              <td class="right"><strong><?php echo e(money($r['amount'])); ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>

  <article class="panel-card">
    <div class="card-head"><h3>Öne çıkan cariler</h3><a href="cariler.php">Cariler</a></div>
    <div class="cari-mini-list">
      <?php if (!$topCariler): ?><p class="muted">Cari kartı eklendiğinde burada özet görünür.</p><?php endif; ?>
      <?php foreach ($topCariler as $c): $net = (float)$c['net_alacak'] - (float)$c['net_verecek']; ?>
        <a class="mini-row" href="cari-detay.php?id=<?php echo e($c['id']); ?>">
          <span><?php echo e($c['name']); ?></span>
          <strong class="<?php echo $net >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($net)); ?></strong>
        </a>
      <?php endforeach; ?>
    </div>
  </article>
</section>
<?php page_footer(); ?>
