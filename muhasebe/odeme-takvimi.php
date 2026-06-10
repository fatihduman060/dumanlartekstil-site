<?php
require_once __DIR__ . '/layout.php';
require_login();

function calendar_month_options(): array
{
    return [
        1=>'Ocak', 2=>'Şubat', 3=>'Mart', 4=>'Nisan', 5=>'Mayıs', 6=>'Haziran',
        7=>'Temmuz', 8=>'Ağustos', 9=>'Eylül', 10=>'Ekim', 11=>'Kasım', 12=>'Aralık'
    ];
}

$period = $_GET['period'] ?? 'monthly';
if (!in_array($period, ['daily','monthly','yearly'], true)) $period = 'monthly';

$todayObj = new DateTimeImmutable('today');
$year = (int)($_GET['year'] ?? $todayObj->format('Y'));
if ($year < 2000 || $year > 2100) $year = (int)$todayObj->format('Y');
$month = (int)($_GET['month'] ?? $todayObj->format('n'));
if ($month < 1 || $month > 12) $month = (int)$todayObj->format('n');
$dateInput = $_GET['date'] ?? $todayObj->format('Y-m-d');
$dateObj = DateTimeImmutable::createFromFormat('Y-m-d', $dateInput) ?: $todayObj;

if ($period === 'daily') {
    $start = $dateObj->format('Y-m-d');
    $end = $start;
    $titleText = 'Günlük ödeme takvimi';
} elseif ($period === 'yearly') {
    $start = sprintf('%04d-01-01', $year);
    $end = sprintf('%04d-12-31', $year);
    $titleText = $year . ' yılı ödeme takvimi';
} else {
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $titleText = calendar_month_options()[$month] . ' ' . $year . ' ödeme takvimi';
}

$rows = payment_calendar_rows($start, $end, true);
$summary = payment_calendar_summary($rows);
$groups = payment_calendar_group_summary($rows, $period);
$periodLabel = payment_calendar_period_label($start, $end);
$print = isset($_GET['print']);
$queryBase = http_build_query(['period'=>$period, 'date'=>$dateObj->format('Y-m-d'), 'month'=>$month, 'year'=>$year]);
$exportQuery = http_build_query(['type'=>'payment_calendar', 'start'=>$start, 'end'=>$end, 'period'=>$period]);
$printQuery = $queryBase . '&print=1';

page_header('Ödeme Takvimi', 'odeme_takvimi');
?>
<section class="hero-card compact-hero <?php echo $print ? 'print-hide' : ''; ?>">
  <div>
    <span class="status-pill">Takvim</span>
    <h2><?php echo e($titleText); ?></h2>
    <p>Bu sayfa mevcut cari hareketleri, alınan/verilen çekleri ve açık özel alacakları okur; veri değiştirmez. Amaç, hangi gün/ay ne alacağın ve ne ödeyeceğini tek ekranda basitçe görmektir.</p>
  </div>
  <div class="hero-actions">
    <a class="btn btn-primary" href="export.php?<?php echo e($exportQuery); ?>">Excel çıktısı</a>
    <a class="btn btn-secondary" href="odeme-takvimi.php?<?php echo e($printQuery); ?>" target="_blank">PDF / Yazdır</a>
  </div>
</section>

<section class="panel-card report-controls <?php echo $print ? 'print-hide' : ''; ?>">
  <form class="filterbar payment-calendar-filter" method="get">
    <select name="period" aria-label="Takvim türü">
      <option value="daily" <?php echo $period==='daily'?'selected':''; ?>>Günlük</option>
      <option value="monthly" <?php echo $period==='monthly'?'selected':''; ?>>Aylık</option>
      <option value="yearly" <?php echo $period==='yearly'?'selected':''; ?>>Yıllık</option>
    </select>
    <input type="date" name="date" value="<?php echo e($dateObj->format('Y-m-d')); ?>" title="Günlük görünüm için tarih">
    <select name="month" title="Aylık görünüm için ay">
      <?php foreach (calendar_month_options() as $m=>$name): ?>
        <option value="<?php echo e($m); ?>" <?php echo $month===$m?'selected':''; ?>><?php echo e($name); ?></option>
      <?php endforeach; ?>
    </select>
    <input type="number" name="year" min="2000" max="2100" value="<?php echo e($year); ?>" title="Aylık/yıllık görünüm için yıl">
    <button class="btn btn-primary">Göster</button>
    <a class="btn btn-secondary" href="odeme-takvimi.php">Bu ay</a>
  </form>
  <div class="report-period-note"><strong>Seçili dönem:</strong> <?php echo e($periodLabel); ?>. Günlükte tarih alanı, aylıkta ay/yıl, yıllıkta sadece yıl esas alınır.</div>
</section>

<section class="stats-grid four report-block payment-calendar-stats">
  <article class="stat-card status"><span>Beklenen alacak</span><strong class="text-success"><?php echo e(money($summary['in'])); ?></strong><small><?php echo e((string)$summary['in_count']); ?> kayıt · cari + alınan çek + özel alacak</small></article>
  <article class="stat-card cash"><span>Yapılacak ödeme</span><strong class="text-danger"><?php echo e(money($summary['out'])); ?></strong><small><?php echo e((string)$summary['out_count']); ?> kayıt · cari + verilen çek</small></article>
  <article class="stat-card <?php echo $summary['net']>=0?'status':'cash'; ?>"><span>Takvim neti</span><strong class="<?php echo $summary['net']>=0?'text-success':'text-danger'; ?>"><?php echo e(money($summary['net'])); ?></strong><small>Alacak - ödeme</small></article>
  <article class="stat-card soft"><span>Toplam kayıt</span><strong><?php echo e((string)$summary['total_count']); ?></strong><small>Seçili dönemdeki açık/vadeli kayıtlar</small></article>
</section>

<p class="calc-note report-calc-note"><strong>Okuma notu:</strong> Alınan çek, vadesi gelene kadar nakit sayılmaz ama takvimde alacak olarak görünür. Verilen çek, vadesi gelene kadar ödeme planında görünür. Tahsil edilmiş/ödenmiş/ciro edilmiş/iptal edilmiş çekler beklenen alacak-ödeme toplamına dahil edilmez. Günü geçmiş satırlar kırmızı uyarı ile görünür.</p>

<section class="content-grid payment-calendar-grid">
  <article class="panel-card">
    <div class="card-head">
      <h3><?php echo $period === 'yearly' ? 'Aylık özet' : 'Gün gün özet'; ?></h3>
      <span><?php echo e($periodLabel); ?></span>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Dönem</th><th class="right">Alacak</th><th class="right">Ödeme</th><th class="right">Net</th><th class="right">Kayıt</th></tr></thead>
        <tbody>
        <?php if (!$groups): ?>
          <tr><td colspan="5" class="empty">Bu dönem için takvim kaydı yok.</td></tr>
        <?php else: foreach ($groups as $g): ?>
          <tr>
            <td><strong><?php echo e($g['label']); ?></strong></td>
            <td class="right text-success"><?php echo e(money($g['in'])); ?></td>
            <td class="right text-danger"><?php echo e(money($g['out'])); ?></td>
            <td class="right <?php echo $g['net']>=0?'text-success':'text-danger'; ?>"><?php echo e(money($g['net'])); ?></td>
            <td class="right"><?php echo e((string)$g['count']); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </article>

  <article class="panel-card">
    <div class="card-head"><h3>Kaynak dağılımı</h3><span>Ne nereden geliyor?</span></div>
    <div class="info-list source-breakdown">
      <?php if (empty($summary['sources'])): ?>
        <p><strong>Kayıt yok</strong> <span>Seçili dönem boş.</span></p>
      <?php else: foreach ($summary['sources'] as $source=>$s): ?>
        <p>
          <strong><?php echo e($source); ?></strong>
          <span>Alacak: <?php echo e(money($s['in'])); ?> · Ödeme: <?php echo e(money($s['out'])); ?> · <?php echo e((string)$s['count']); ?> kayıt</span>
        </p>
      <?php endforeach; endif; ?>
    </div>
  </article>
</section>

<section class="panel-card report-block">
  <div class="card-head">
    <h3>Detaylı ödeme takvimi</h3>
    <span>Basit liste: tarih, cari, açıklama ve tutar</span>
  </div>
  <div class="table-wrap payment-calendar-table">
    <table>
      <thead>
        <tr>
          <th>Vade</th>
          <th>Tür</th>
          <th>Kaynak</th>
          <th>Cari</th>
          <th>Açıklama</th>
          <th>Durum</th>
          <th class="right">Tutar</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="empty">Bu dönem için alacak veya ödeme görünmüyor.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr class="<?php echo $r['tone']==='danger' ? 'row-overdue' : ''; ?>">
          <td><strong><?php echo e(tr_date($r['date'])); ?></strong></td>
          <td><?php echo badge($r['kind'], $r['direction']==='in' ? 'success' : 'danger'); ?></td>
          <td><?php echo e($r['source']); ?></td>
          <td>
            <?php if (!empty($r['cari_id'])): ?><a href="cari-detay.php?id=<?php echo e($r['cari_id']); ?>"><strong><?php echo e($r['cari_name']); ?></strong></a><?php else: ?><strong><?php echo e($r['cari_name']); ?></strong><?php endif; ?>
          </td>
          <td><?php echo e($r['description'] ?: '-'); ?></td>
          <td><?php echo badge($r['status'], $r['tone']); ?></td>
          <td class="right <?php echo $r['direction']==='in'?'text-success':'text-danger'; ?>"><strong><?php echo e(money($r['amount'])); ?></strong></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($print): ?>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 350); });</script>
<?php endif; ?>
<?php page_footer(); ?>
