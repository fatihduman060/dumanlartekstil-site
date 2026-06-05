<?php
require_once __DIR__ . '/layout.php';
require_login();
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-t');
$totals = dashboard_totals($start, $end);
$stmt = db()->prepare("SELECT cat.name, cat.type, SUM(m.amount) AS total FROM movements m LEFT JOIN categories cat ON cat.id=m.category_id WHERE m.movement_date BETWEEN ? AND ? GROUP BY cat.id ORDER BY total DESC");
$stmt->execute([$start,$end]);
$categoryTotals = $stmt->fetchAll();
$stmt = db()->prepare("SELECT strftime('%Y-%m', movement_date) AS month, movement_type, SUM(amount) AS total FROM movements WHERE movement_date BETWEEN ? AND ? GROUP BY month, movement_type ORDER BY month ASC");
$stmt->execute([$start,$end]);
$monthly = $stmt->fetchAll();
$stmt = db()->prepare("SELECT c.id,c.name,
 SUM(CASE WHEN m.movement_type='alacak' THEN m.amount ELSE 0 END) AS alacak,
 SUM(CASE WHEN m.movement_type='tahsilat' THEN m.amount ELSE 0 END) AS tahsilat,
 SUM(CASE WHEN m.movement_type='verecek' THEN m.amount ELSE 0 END) AS verecek,
 SUM(CASE WHEN m.movement_type='odeme' THEN m.amount ELSE 0 END) AS odeme
 FROM cariler c JOIN movements m ON m.cari_id=c.id WHERE m.movement_date BETWEEN ? AND ? GROUP BY c.id ORDER BY ABS((alacak-tahsilat)-(verecek-odeme)) DESC LIMIT 20");
$stmt->execute([$start,$end]);
$cariTotals=$stmt->fetchAll();
$print = isset($_GET['print']);
page_header('Raporlar', 'raporlar');
?>
<section class="panel-card report-controls <?php echo $print?'print-hide':''; ?>">
  <form class="filterbar" method="get"><input type="date" name="start" value="<?php echo e($start); ?>"><input type="date" name="end" value="<?php echo e($end); ?>"><button class="btn btn-secondary">Raporla</button><a class="btn btn-primary" href="export.php?type=movements&start=<?php echo e($start); ?>&end=<?php echo e($end); ?>">Excel CSV</a><a class="btn btn-secondary" href="raporlar.php?start=<?php echo e($start); ?>&end=<?php echo e($end); ?>&print=1" target="_blank">PDF/Yazdır</a></form>
</section>

<section class="stats-grid four report-block">
  <article class="stat-card"><span>Alacak</span><strong><?php echo e(money($totals['alacak'])); ?></strong><small>Kalan: <?php echo e(money($totals['net_alacak'])); ?></small></article>
  <article class="stat-card"><span>Tahsilat</span><strong><?php echo e(money($totals['tahsilat'])); ?></strong><small>Seçili tarih aralığı</small></article>
  <article class="stat-card"><span>Verecek</span><strong><?php echo e(money($totals['verecek'])); ?></strong><small>Kalan: <?php echo e(money($totals['net_verecek'])); ?></small></article>
  <article class="stat-card"><span>Gelir - gider</span><strong class="<?php echo $totals['net_gelir_gider']>=0?'text-success':'text-danger'; ?>"><?php echo e(money($totals['net_gelir_gider'])); ?></strong><small>Gelir: <?php echo e(money($totals['gelir'])); ?> / Gider: <?php echo e(money($totals['gider'])); ?></small></article>
</section>

<section class="content-grid">
  <article class="panel-card">
    <div class="card-head"><h3>Kategori bazlı toplamlar</h3><span><?php echo e(tr_date($start)); ?> - <?php echo e(tr_date($end)); ?></span></div>
    <div class="table-wrap"><table><thead><tr><th>Kategori</th><th>Tür</th><th class="right">Toplam</th></tr></thead><tbody><?php if(!$categoryTotals): ?><tr><td colspan="3" class="empty">Veri yok.</td></tr><?php endif; ?><?php foreach($categoryTotals as $r): ?><tr><td><?php echo e($r['name'] ?: 'Kategorisiz'); ?></td><td><?php echo badge($r['type'] ?: 'genel', ($r['type']==='gider')?'danger':(($r['type']==='gelir')?'success':'neutral')); ?></td><td class="right"><strong><?php echo e(money($r['total'])); ?></strong></td></tr><?php endforeach; ?></tbody></table></div>
  </article>
  <article class="panel-card">
    <div class="card-head"><h3>Ay sonu özeti</h3><span>Aylık kırılım</span></div>
    <div class="table-wrap"><table><thead><tr><th>Ay</th><th>Tip</th><th class="right">Toplam</th></tr></thead><tbody><?php if(!$monthly): ?><tr><td colspan="3" class="empty">Veri yok.</td></tr><?php endif; ?><?php foreach($monthly as $r): ?><tr><td><?php echo e($r['month']); ?></td><td><?php echo badge(movement_label($r['movement_type']), movement_tone($r['movement_type'])); ?></td><td class="right"><strong><?php echo e(money($r['total'])); ?></strong></td></tr><?php endforeach; ?></tbody></table></div>
  </article>
</section>

<section class="panel-card report-block">
  <div class="card-head"><h3>Cari bakiye raporu</h3><span>İlk 20 cari</span></div>
  <div class="table-wrap"><table><thead><tr><th>Cari</th><th class="right">Alacak</th><th class="right">Tahsilat</th><th class="right">Verecek</th><th class="right">Ödeme</th><th class="right">Net</th></tr></thead><tbody><?php if(!$cariTotals): ?><tr><td colspan="6" class="empty">Veri yok.</td></tr><?php endif; ?><?php foreach($cariTotals as $c): $net=((float)$c['alacak']-(float)$c['tahsilat'])-((float)$c['verecek']-(float)$c['odeme']); ?><tr><td><a href="cari-detay.php?id=<?php echo e($c['id']); ?>"><?php echo e($c['name']); ?></a></td><td class="right"><?php echo e(money($c['alacak'])); ?></td><td class="right"><?php echo e(money($c['tahsilat'])); ?></td><td class="right"><?php echo e(money($c['verecek'])); ?></td><td class="right"><?php echo e(money($c['odeme'])); ?></td><td class="right"><strong class="<?php echo $net>=0?'text-success':'text-danger'; ?>"><?php echo e(money($net)); ?></strong></td></tr><?php endforeach; ?></tbody></table></div>
</section>
<?php if($print): ?><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),350));</script><?php endif; ?>
<?php page_footer(); ?>
