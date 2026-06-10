<?php
require_once __DIR__ . '/layout.php';
require_login();
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-t');
$totals = dashboard_totals($start, $end);
$cashTotals = cashflow_totals($start, $end);
$reportCashIn = (float)$cashTotals['in'];
$reportCashOut = (float)$cashTotals['out'];
$reportCashNet = (float)$cashTotals['net'];
$reportNetPosition = (float)$totals['net_alacak'] - (float)$totals['net_verecek'];
$checkTotalsRange = check_totals($start, $end, false);
$stmt = db()->prepare("SELECT cat.name, cat.type, SUM(m.amount) AS total FROM movements m LEFT JOIN categories cat ON cat.id=m.category_id WHERE COALESCE(m.is_cancelled,0)=0 AND m.movement_date BETWEEN ? AND ? GROUP BY cat.id ORDER BY total DESC");
$stmt->execute([$start,$end]); $categoryTotals = $stmt->fetchAll();
$stmt = db()->prepare("SELECT strftime('%Y-%m', movement_date) AS month, movement_type, SUM(amount) AS total FROM movements WHERE COALESCE(is_cancelled,0)=0 AND movement_date BETWEEN ? AND ? GROUP BY month, movement_type ORDER BY month ASC");
$stmt->execute([$start,$end]); $monthly = $stmt->fetchAll();
$stmt = db()->prepare("SELECT c.id,c.name,
 SUM(CASE WHEN m.movement_type='alacak' THEN m.amount ELSE 0 END) AS alacak,
 SUM(CASE WHEN m.movement_type='tahsilat' THEN m.amount ELSE 0 END) AS tahsilat,
 SUM(CASE WHEN m.movement_type='verecek' THEN m.amount ELSE 0 END) AS verecek,
 SUM(CASE WHEN m.movement_type='odeme' THEN m.amount ELSE 0 END) AS odeme
 FROM cariler c JOIN movements m ON m.cari_id=c.id WHERE COALESCE(m.is_cancelled,0)=0 AND m.movement_date BETWEEN ? AND ? GROUP BY c.id ORDER BY ABS((alacak-tahsilat)-(verecek-odeme)) DESC LIMIT 20");
$stmt->execute([$start,$end]); $cariTotals=$stmt->fetchAll();
$stmt = db()->prepare("SELECT ch.*, c.name AS cari_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id WHERE COALESCE(ch.is_cancelled,0)=0 AND ch.due_date BETWEEN ? AND ? ORDER BY ch.due_date ASC LIMIT 30");
$stmt->execute([$start,$end]); $checks=$stmt->fetchAll();
$accountSummary = account_summary();
$privateSummary = private_receivable_totals(['start'=>$start, 'end'=>$end]);
$accountRows = accounts_for_select(false);
$acctStmt = db()->prepare("SELECT at.*, a.name AS account_name, a.account_type FROM account_transactions at JOIN accounts a ON a.id=at.account_id WHERE at.transaction_date BETWEEN ? AND ? ORDER BY at.transaction_date DESC, at.id DESC LIMIT 50");
$acctStmt->execute([$start,$end]); $accountTransactions = $acctStmt->fetchAll();
$cariler = cariler_for_select();
$print = isset($_GET['print']);
page_header('Raporlar', 'raporlar');
?>
<section class="panel-card report-controls <?php echo $print?'print-hide':''; ?>">
  <form class="filterbar" method="get"><input type="date" name="start" value="<?php echo e($start); ?>"><input type="date" name="end" value="<?php echo e($end); ?>"><button class="btn btn-secondary">Raporla</button><a class="btn btn-primary" href="export.php?type=movements&start=<?php echo e($start); ?>&end=<?php echo e($end); ?>">Hareket CSV</a><a class="btn btn-secondary" href="export.php?type=checks&start=<?php echo e($start); ?>&end=<?php echo e($end); ?>">Çek CSV</a><a class="btn btn-secondary" href="export.php?type=account_transactions&start=<?php echo e($start); ?>&end=<?php echo e($end); ?>">Kasa/Banka CSV</a><a class="btn btn-secondary" href="ozel-alacaklar.php?start=<?php echo e($start); ?>&end=<?php echo e($end); ?>">Özel Alacak</a><a class="btn btn-secondary" href="raporlar.php?start=<?php echo e($start); ?>&end=<?php echo e($end); ?>&print=1" target="_blank">PDF/Yazdır</a></form>
  <div class="report-period-note">Seçili dönem: <strong><?php echo e(tr_date($start)); ?> - <?php echo e(tr_date($end)); ?></strong>. Aşağıdaki kartlar bu tarih aralığına göre hesaplanır.</div>
</section>

<section class="panel-card report-controls <?php echo $print?'print-hide':''; ?>">
  <form class="filterbar" method="get" action="cari-ekstre.php" target="_blank"><select name="id" required><option value="">Cari seçerek ekstre aç</option><?php foreach($cariler as $c): ?><option value="<?php echo e($c['id']); ?>"><?php echo e($c['name']); ?></option><?php endforeach; ?></select><input type="date" name="start" value="<?php echo e($start); ?>"><input type="date" name="end" value="<?php echo e($end); ?>"><button class="btn btn-primary">Cari ekstresi / PDF</button></form>
</section>

<section class="stats-grid five report-block report-position-grid">
  <article class="stat-card status report-hero-stat"><span>Genel Durum</span><strong class="<?php echo $reportNetPosition>=0?'text-success':'text-danger'; ?>"><?php echo e(money($reportNetPosition)); ?></strong><small>Net alacak - net verecek</small></article>
  <article class="stat-card"><span>Kalan alacak</span><strong><?php echo e(money($totals['net_alacak'])); ?></strong><small>Alacak - tahsilat</small></article>
  <article class="stat-card"><span>Kalan verecek</span><strong><?php echo e(money($totals['net_verecek'])); ?></strong><small>Verecek - ödeme</small></article>
  <article class="stat-card cash"><span>Nakit neti</span><strong class="<?php echo $reportCashNet>=0?'text-success':'text-danger'; ?>"><?php echo e(money($reportCashNet)); ?></strong><small>Vade/tahsil tarihine göre</small></article>
  <article class="stat-card special"><span>Özel alacak</span><strong><?php echo e(money($privateSummary['acik'])); ?></strong><small>Genel duruma dahil değil</small></article>
</section>
<p class="calc-note report-calc-note"><strong>Okuma notu:</strong> Genel Durum = net alacak - net verecek. Nakit Neti = vade/tahsil tarihine göre giren para - çıkan para. Bekleyen çekler nakit sayılmaz. Özel Alacak bu iki hesaba dahil edilmez.</p>

<section class="stats-grid four report-block">
  <article class="stat-card"><span>Alacak</span><strong><?php echo e(money($totals['alacak'])); ?></strong><small>Brüt alacak</small></article>
  <article class="stat-card"><span>Tahsilat</span><strong><?php echo e(money($totals['tahsilat'])); ?></strong><small>Seçili tarih aralığı</small></article>
  <article class="stat-card"><span>Verecek</span><strong><?php echo e(money($totals['verecek'])); ?></strong><small>Brüt verecek</small></article>
  <article class="stat-card"><span>Giren / Çıkan</span><strong class="<?php echo $reportCashNet>=0?'text-success':'text-danger'; ?>"><?php echo e(money($reportCashNet)); ?></strong><small>Giriş: <?php echo e(money($reportCashIn)); ?> / Çıkış: <?php echo e(money($reportCashOut)); ?></small></article>
</section>
<section class="stats-grid two report-block">
  <article class="stat-card soft"><span>Aralıktaki alınacak çek</span><strong><?php echo e(money($checkTotalsRange['alinacak'])); ?></strong><small><?php echo e($checkTotalsRange['alinacak_count']); ?> adet</small></article>
  <article class="stat-card soft"><span>Aralıktaki verilecek çek</span><strong><?php echo e(money($checkTotalsRange['verilecek'])); ?></strong><small><?php echo e($checkTotalsRange['verilecek_count']); ?> adet</small></article>
</section>

<section class="stats-grid three report-block">
  <article class="stat-card special"><span>Özel Alacak Açık</span><strong><?php echo e(money($privateSummary['acik'])); ?></strong><small>Genel cari toplamına dahil değildir</small></article>
  <article class="stat-card soft"><span>Özel Alacak Kapandı</span><strong><?php echo e(money($privateSummary['kapandi'])); ?></strong><small>Seçili tarih aralığı</small></article>
  <article class="stat-card soft"><span>Özel Alacak kayıt</span><strong><?php echo e((string)$privateSummary['count']); ?></strong><small><a href="ozel-alacaklar.php?start=<?php echo e($start); ?>&end=<?php echo e($end); ?>">Detay raporu aç</a></small></article>
</section>

<section class="stats-grid four report-block">
  <article class="stat-card soft"><span>Kasa toplamı</span><strong><?php echo e(money($accountSummary['kasa'])); ?></strong><small>Nakit hesapları</small></article>
  <article class="stat-card soft"><span>Banka toplamı</span><strong><?php echo e(money($accountSummary['banka'])); ?></strong><small>Banka hesapları</small></article>
  <article class="stat-card soft"><span>Genel hesap bakiyesi</span><strong class="<?php echo $accountSummary['total']>=0?'text-success':'text-danger'; ?>"><?php echo e(money($accountSummary['total'])); ?></strong><small>Kasa + banka + POS</small></article>
  <article class="stat-card soft"><span>Aktif hesap</span><strong><?php echo e($accountSummary['active']); ?></strong><small>Panelde kullanılan hesap</small></article>
</section>

<section class="content-grid">
  <article class="panel-card"><div class="card-head"><h3>Kategori bazlı toplamlar</h3><span><?php echo e(tr_date($start)); ?> - <?php echo e(tr_date($end)); ?></span></div><div class="table-wrap"><table><thead><tr><th>Kategori</th><th>Tür</th><th class="right">Toplam</th></tr></thead><tbody><?php if(!$categoryTotals): ?><tr><td colspan="3" class="empty">Veri yok.</td></tr><?php endif; ?><?php foreach($categoryTotals as $r): ?><tr><td><?php echo e($r['name'] ?: 'Kategorisiz'); ?></td><td><?php echo badge($r['type'] ?: 'genel', ($r['type']==='gider')?'danger':(($r['type']==='gelir')?'success':'neutral')); ?></td><td class="right"><strong><?php echo e(money($r['total'])); ?></strong></td></tr><?php endforeach; ?></tbody></table></div></article>
  <article class="panel-card"><div class="card-head"><h3>Ay sonu özeti</h3><span>Aylık kırılım</span></div><div class="table-wrap"><table><thead><tr><th>Ay</th><th>Tip</th><th class="right">Toplam</th></tr></thead><tbody><?php if(!$monthly): ?><tr><td colspan="3" class="empty">Veri yok.</td></tr><?php endif; ?><?php foreach($monthly as $r): ?><tr><td><?php echo e(month_label($r['month'])); ?></td><td><?php echo badge(movement_label($r['movement_type']), movement_tone($r['movement_type'])); ?></td><td class="right"><strong><?php echo e(money($r['total'])); ?></strong></td></tr><?php endforeach; ?></tbody></table></div></article>
</section>

<section class="panel-card report-block"><div class="card-head"><h3>Cari bakiye raporu</h3><span>İlk 20 cari</span></div><div class="table-wrap"><table><thead><tr><th>Cari</th><th class="right">Alacak</th><th class="right">Tahsilat</th><th class="right">Verecek</th><th class="right">Ödeme</th><th class="right">Net</th></tr></thead><tbody><?php if(!$cariTotals): ?><tr><td colspan="6" class="empty">Veri yok.</td></tr><?php endif; ?><?php foreach($cariTotals as $c): $net=((float)$c['alacak']-(float)$c['tahsilat'])-((float)$c['verecek']-(float)$c['odeme']); ?><tr><td><a href="cari-detay.php?id=<?php echo e($c['id']); ?>"><?php echo e($c['name']); ?></a></td><td class="right"><?php echo e(money($c['alacak'])); ?></td><td class="right"><?php echo e(money($c['tahsilat'])); ?></td><td class="right"><?php echo e(money($c['verecek'])); ?></td><td class="right"><?php echo e(money($c['odeme'])); ?></td><td class="right"><strong class="<?php echo $net>=0?'text-success':'text-danger'; ?>"><?php echo e(money($net)); ?></strong></td></tr><?php endforeach; ?></tbody></table></div></section>

<section class="panel-card report-block"><div class="card-head"><h3>Çek vade raporu</h3><span>İlk 30 çek</span></div><div class="table-wrap"><table><thead><tr><th>Vade</th><th>Yön</th><th>Durum</th><th>Cari</th><th>Banka</th><th class="right">Tutar</th></tr></thead><tbody><?php if(!$checks): ?><tr><td colspan="6" class="empty">Veri yok.</td></tr><?php endif; ?><?php foreach($checks as $ch): ?><tr><td><?php echo e(tr_date($ch['due_date'])); ?></td><td><?php echo badge(check_direction_label($ch['direction']), check_direction_tone($ch['direction'])); ?></td><td><?php echo badge(check_status_label($ch['status']), check_status_tone($ch['status'])); ?></td><td><?php echo e($ch['cari_name'] ?: '-'); ?></td><td><?php echo e($ch['bank_name'] ?: '-'); ?></td><td class="right"><strong><?php echo e(money($ch['amount'])); ?></strong></td></tr><?php endforeach; ?></tbody></table></div></section>

<section class="content-grid report-block">
  <article class="panel-card"><div class="card-head"><h3>Kasa/Banka hesap raporu</h3><a href="hesaplar.php">Hesaplara git</a></div><div class="table-wrap"><table><thead><tr><th>Hesap</th><th>Tip</th><th>Durum</th><th class="right">Bakiye</th></tr></thead><tbody><?php foreach($accountRows as $a): $bal=account_balance((int)$a['id']); ?><tr><td><?php echo e($a['name']); ?><small><?php echo e($a['bank_name'] ?: $a['iban']); ?></small></td><td><?php echo badge(account_type_label($a['account_type']), account_type_tone($a['account_type'])); ?></td><td><?php echo ((int)$a['is_active']===1)?badge('Aktif','success'):badge('Pasif','neutral'); ?></td><td class="right"><strong class="<?php echo $bal>=0?'text-success':'text-danger'; ?>"><?php echo e(money($bal)); ?></strong></td></tr><?php endforeach; ?></tbody></table></div></article>
  <article class="panel-card"><div class="card-head"><h3>Seçili aralıktaki hesap hareketleri</h3><span>İlk 50</span></div><div class="table-wrap"><table><thead><tr><th>Tarih</th><th>Hesap</th><th>Kaynak</th><th class="right">Giriş</th><th class="right">Çıkış</th></tr></thead><tbody><?php if(!$accountTransactions): ?><tr><td colspan="5" class="empty">Veri yok.</td></tr><?php endif; ?><?php foreach($accountTransactions as $tr): ?><tr><td><?php echo e(tr_date($tr['transaction_date'])); ?></td><td><?php echo e($tr['account_name']); ?><small><?php echo e($tr['description'] ?: ''); ?></small></td><td><?php echo badge($tr['source_type'], 'neutral'); ?></td><td class="right"><?php echo $tr['direction']==='in'?'<strong class="text-success">'.e(money($tr['amount'])).'</strong>':'-'; ?></td><td class="right"><?php echo $tr['direction']==='out'?'<strong class="text-danger">'.e(money($tr['amount'])).'</strong>':'-'; ?></td></tr><?php endforeach; ?></tbody></table></div></article>
</section>
<?php if($print): ?><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),350));</script><?php endif; ?>
<?php page_footer(); ?>
