<?php
require_once __DIR__ . '/layout.php';
require_login();
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$weekAhead = date('Y-m-d', strtotime('+7 days'));
$totals = dashboard_totals();
$monthTotals = dashboard_totals($monthStart, $monthEnd);
$checkTotals = check_totals(null, null, true);
$accountSummary = account_summary();
$checkSoon = check_totals($today, $weekAhead, true);
$overdueChecks = overdue_check_count();
$cariCount = (int)db()->query('SELECT COUNT(*) FROM cariler')->fetchColumn();
$movementCount = (int)db()->query('SELECT COUNT(*) FROM movements')->fetchColumn();
$docCount = (int)db()->query("SELECT COUNT(*) FROM movements WHERE document_path IS NOT NULL AND document_path != ''")->fetchColumn()
    + (int)db()->query("SELECT COUNT(*) FROM checks WHERE document_path IS NOT NULL AND document_path != ''")->fetchColumn();
$recent = db()->query("SELECT m.*, c.name AS cari_name, cat.name AS category_name, a.name AS account_name FROM movements m LEFT JOIN cariler c ON c.id=m.cari_id LEFT JOIN categories cat ON cat.id=m.category_id LEFT JOIN accounts a ON a.id=m.account_id WHERE COALESCE(m.is_cancelled,0)=0 ORDER BY m.movement_date DESC, m.id DESC LIMIT 8")->fetchAll();
$dueMovStmt = db()->prepare("SELECT m.*, c.name AS cari_name FROM movements m LEFT JOIN cariler c ON c.id=m.cari_id WHERE COALESCE(m.is_cancelled,0)=0 AND m.due_date IS NOT NULL AND m.due_date <= ? AND m.movement_type IN ('alacak','verecek') ORDER BY m.due_date ASC, m.id DESC LIMIT 8");
$dueMovStmt->execute([$weekAhead]);
$dueMovements = $dueMovStmt->fetchAll();
$dueCheckStmt = db()->prepare("SELECT ch.*, c.name AS cari_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id WHERE ch.status='bekliyor' AND ch.due_date <= ? ORDER BY ch.due_date ASC, ch.id DESC LIMIT 8");
$dueCheckStmt->execute([$weekAhead]);
$dueChecks = $dueCheckStmt->fetchAll();
$topStmt = db()->query("SELECT c.id, c.name,
    SUM(CASE WHEN m.movement_type='alacak' THEN m.amount ELSE 0 END) - SUM(CASE WHEN m.movement_type='tahsilat' THEN m.amount ELSE 0 END) AS net_alacak,
    SUM(CASE WHEN m.movement_type='verecek' THEN m.amount ELSE 0 END) - SUM(CASE WHEN m.movement_type='odeme' THEN m.amount ELSE 0 END) AS net_verecek
  FROM cariler c LEFT JOIN movements m ON m.cari_id=c.id AND COALESCE(m.is_cancelled,0)=0 GROUP BY c.id ORDER BY ABS((COALESCE(net_alacak,0)-COALESCE(net_verecek,0))) DESC LIMIT 6");
$topCariler = $topStmt->fetchAll();
$monthly = monthly_summary(6);
// Chart.js data
$chartLabels = [];
$chartIn = [];
$chartOut = [];
$chartNet = [];
foreach ($monthly as $m) {
    $chartLabels[] = $m['label'];
    $chartIn[] = round($m['gelir'] + $m['tahsilat'], 2);
    $chartOut[] = round($m['gider'] + $m['odeme'], 2);
    $chartNet[] = round($m['net'], 2);
}
// Yedek uyarısı
$lastBackup = setting_get('last_backup_date');
$daysSinceBackup = $lastBackup ? (int)floor((time() - strtotime($lastBackup)) / 86400) : null;
$showBackupWarning = is_admin() && ($lastBackup === null || $daysSinceBackup >= 7);
page_header('Genel Bakış', 'dashboard');
?>

<?php if ($showBackupWarning): ?>
<div class="alert alert-warning backup-warning">
  ⚠️ <?php echo $lastBackup ? 'Son yedek <strong>' . $daysSinceBackup . ' gün</strong> önce alındı.' : 'Henüz yedek alınmamış.'; ?>
  <a href="yedekler.php">Şimdi yedekle →</a>
</div>
<?php endif; ?>

<section class="hero-card">
  <div>
    <span class="status-pill"><?php echo e(APP_VERSION); ?> aktif · kasa/banka + gelişmiş takip</span>
    <h2>Alacak, verecek, çek vadesi ve raporlar tek ekranda.</h2>
    <p>Bu panel iç takip amaçlıdır. Resmi muhasebe/e-fatura yerine geçmez; günlük cari, çek, belge ve kasa takibini düzenli tutmak için tasarlandı.</p>
  </div>
  <div class="hero-actions">
    <?php if (can_write()): ?>
      <a class="btn btn-primary" href="hareketler.php">Hareket ekle</a>
      <a class="btn btn-secondary" href="cekler.php">Çek ekle</a>
      <a class="btn btn-secondary" href="cariler.php">Cari ekle</a>
    <?php endif; ?>
  </div>
</section>

<section class="quick-grid mobile-focus">
  <?php if (can_write()): ?>
    <a class="quick-action" href="hareketler.php?movement_type=tahsilat"><strong>+ Tahsilat</strong><span>Hızlı para girişi</span></a>
    <a class="quick-action" href="hareketler.php?movement_type=odeme"><strong>+ Ödeme</strong><span>Hızlı para çıkışı</span></a>
    <a class="quick-action" href="cekler.php"><strong>+ Çek</strong><span>Vade takibi</span></a>
    <a class="quick-action" href="hesaplar.php"><strong>Kasa/Banka</strong><span>Para nerede?</span></a>
  <?php endif; ?>
</section>

<section class="stats-grid four">
  <article class="stat-card"><span>Toplam cari</span><strong><?php echo e($cariCount); ?></strong><small>Kişi / firma kartı</small></article>
  <article class="stat-card"><span>Net alacak</span><strong><?php echo e(money($totals['net_alacak'])); ?></strong><small>Alacak - tahsilat</small></article>
  <article class="stat-card"><span>Net verecek</span><strong><?php echo e(money($totals['net_verecek'])); ?></strong><small>Verecek - ödeme</small></article>
  <article class="stat-card"><span>Bu ay net</span><strong class="<?php echo $monthTotals['net_gelir_gider'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($monthTotals['net_gelir_gider'])); ?></strong><small>Gelir - gider</small></article>
</section>

<section class="stats-grid four">
  <article class="stat-card soft"><span>Kasa toplamı</span><strong><?php echo e(money($accountSummary['kasa'])); ?></strong><small>Nakit hesapları</small></article>
  <article class="stat-card soft"><span>Banka toplamı</span><strong><?php echo e(money($accountSummary['banka'])); ?></strong><small>Banka hesapları</small></article>
  <article class="stat-card soft"><span>Genel kasa/banka</span><strong class="<?php echo $accountSummary['total'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($accountSummary['total'])); ?></strong><small>Tüm hesap bakiyesi</small></article>
  <article class="stat-card soft"><span>Aktif hesap</span><strong><?php echo e($accountSummary['active']); ?></strong><small>Kasa/banka/POS</small></article>
</section>

<section class="stats-grid four">
  <article class="stat-card soft"><span>Bekleyen alınacak çek</span><strong><?php echo e(money($checkTotals['alinacak'])); ?></strong><small><?php echo e($checkTotals['alinacak_count']); ?> adet</small></article>
  <article class="stat-card soft"><span>Bekleyen verilecek çek</span><strong><?php echo e(money($checkTotals['verilecek'])); ?></strong><small><?php echo e($checkTotals['verilecek_count']); ?> adet</small></article>
  <article class="stat-card soft"><span>7 gün içinde alınacak</span><strong><?php echo e(money($checkSoon['alinacak'])); ?></strong><small>Vadesi yaklaşan çek</small></article>
  <article class="stat-card soft"><span>Vadesi geçen çek</span><strong class="<?php echo $overdueChecks>0?'text-danger':''; ?>"><?php echo e($overdueChecks); ?></strong><small>Bekleyen kayıt</small></article>
</section>

<section class="content-grid">
  <article class="panel-card">
    <div class="card-head"><h3>6 aylık nakit akışı</h3><a href="raporlar.php">Raporlar →</a></div>
    <div class="chartjs-wrap">
      <canvas id="cashflowChart" height="200"></canvas>
    </div>
    <div class="chart-legend">
      <span class="legend-in">● Giriş</span>
      <span class="legend-out">● Çıkış</span>
      <span class="legend-net">— Net</span>
    </div>
  </article>
  <article class="panel-card">
    <div class="card-head"><h3>Panel sağlığı</h3><span>Özet</span></div>
    <div class="info-list">
      <p><strong>Hareket kaydı:</strong> <?php echo e($movementCount); ?></p>
      <p><strong>Yüklü belge:</strong> <?php echo e($docCount); ?></p>
      <p><strong>Kasa/Banka toplam:</strong> <?php echo e(money($accountSummary['total'])); ?></p>
      <p><strong>Otomatik çıkış:</strong> <?php echo (int)(SESSION_TIMEOUT_SECONDS / 60); ?> dakika</p>
      <p><strong>Giriş koruması:</strong> <?php echo LOGIN_MAX_ATTEMPTS; ?> hatalı deneme sonrası <?php echo (int)(LOGIN_LOCK_SECONDS / 60); ?> dakika kilit</p>
      <?php if (is_admin()): ?>
      <p><strong>Son yedek:</strong> <?php echo $lastBackup ? '<span class="' . ($daysSinceBackup >= 7 ? 'text-danger' : 'text-success') . '">' . e(tr_date($lastBackup)) . ' (' . $daysSinceBackup . ' gün önce)</span>' : '<span class="text-danger">Yedek alınmamış</span>'; ?></p>
      <?php endif; ?>
    </div>
  </article>
</section>

<section class="content-grid">
  <article class="panel-card">
    <div class="card-head"><h3>Vade uyarıları</h3><a href="cekler.php?status=bekliyor&end=<?php echo e($weekAhead); ?>">Çekleri gör</a></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Vade</th><th>Tür</th><th>Cari</th><th>Açıklama</th><th class="right">Tutar</th></tr></thead>
        <tbody>
          <?php if (!$dueMovements && !$dueChecks): ?><tr><td colspan="5" class="empty">Yaklaşan vade kaydı yok.</td></tr><?php endif; ?>
          <?php foreach ($dueMovements as $r): ?>
            <tr>
              <td><?php echo e(tr_date($r['due_date'])); ?></td>
              <td><?php echo badge(movement_label($r['movement_type']), movement_tone($r['movement_type'])); ?></td>
              <td><?php echo $r['cari_id'] ? '<a href="cari-detay.php?id=' . e($r['cari_id']) . '">' . e($r['cari_name']) . '</a>' : '-'; ?></td>
              <td><?php echo e($r['description'] ?: 'Hareket vadesi'); ?></td>
              <td class="right"><strong><?php echo e(money($r['amount'])); ?></strong></td>
            </tr>
          <?php endforeach; ?>
          <?php foreach ($dueChecks as $r): ?>
            <tr>
              <td><?php echo e(tr_date($r['due_date'])); ?></td>
              <td><?php echo badge(check_direction_label($r['direction']), check_direction_tone($r['direction'])); ?></td>
              <td><?php echo $r['cari_id'] ? '<a href="cari-detay.php?id=' . e($r['cari_id']) . '">' . e($r['cari_name']) . '</a>' : '-'; ?></td>
              <td><?php echo e(trim(($r['bank_name'] ?: '') . ' ' . ($r['check_no'] ?: '')) ?: 'Çek vadesi'); ?></td>
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

<section class="panel-card">
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
</section>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
(function() {
  var labels = <?php echo json_encode($chartLabels); ?>;
  var inData  = <?php echo json_encode($chartIn); ?>;
  var outData = <?php echo json_encode($chartOut); ?>;
  var netData = <?php echo json_encode($chartNet); ?>;
  if (!document.getElementById('cashflowChart')) return;
  new Chart(document.getElementById('cashflowChart').getContext('2d'), {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        {
          type: 'bar',
          label: 'Giriş',
          data: inData,
          backgroundColor: 'rgba(41,122,74,0.65)',
          borderRadius: 6,
          order: 2
        },
        {
          type: 'bar',
          label: 'Çıkış',
          data: outData,
          backgroundColor: 'rgba(185,66,58,0.55)',
          borderRadius: 6,
          order: 2
        },
        {
          type: 'line',
          label: 'Net',
          data: netData,
          borderColor: '#c8914b',
          backgroundColor: 'rgba(200,145,75,0.08)',
          borderWidth: 2.5,
          pointBackgroundColor: '#c8914b',
          pointRadius: 4,
          tension: 0.35,
          fill: true,
          order: 1
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function(ctx) {
              var v = ctx.parsed.y;
              return ctx.dataset.label + ': ' + v.toLocaleString('tr-TR', {minimumFractionDigits:2,maximumFractionDigits:2}) + ' TL';
            }
          }
        }
      },
      scales: {
        x: { grid: { display: false }, ticks: { font: { size: 11, weight: '800' } } },
        y: {
          grid: { color: 'rgba(0,0,0,0.06)' },
          ticks: {
            font: { size: 11 },
            callback: function(v) {
              if (Math.abs(v) >= 1000) return (v/1000).toLocaleString('tr-TR',{maximumFractionDigits:1}) + 'B';
              return v.toLocaleString('tr-TR');
            }
          }
        }
      }
    }
  });
})();
</script>
<?php page_footer(); ?>
