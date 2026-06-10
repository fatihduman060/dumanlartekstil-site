<?php
require_once __DIR__ . '/layout.php';
require_login();

function dashboard_cashflow_periods(): array
{
    return [
        'daily' => ['label'=>'Günlük', 'title'=>'Son 30 gün', 'group'=>'day', 'steps'=>30],
        'monthly' => ['label'=>'Aylık', 'title'=>'Son 12 ay', 'group'=>'month', 'steps'=>12],
        'year' => ['label'=>'Yıllık', 'title'=>'Bu yılın ay ay görünümü', 'group'=>'current_year_month', 'steps'=>12],
        '5y' => ['label'=>'5 Yıllık', 'title'=>'Son 5 yıl', 'group'=>'year', 'steps'=>5],
        '10y' => ['label'=>'10 Yıllık', 'title'=>'Son 10 yıl', 'group'=>'year', 'steps'=>10],
    ];
}

function dashboard_cashflow_rows(string $period): array
{
    $periods = dashboard_cashflow_periods();
    $meta = $periods[$period] ?? $periods['monthly'];
    $today = new DateTimeImmutable('today');
    $rows = [];

    if ($meta['group'] === 'day') {
        $start = $today->modify('-' . ($meta['steps'] - 1) . ' days');
        $end = $today;
        $sqlDate = "COALESCE(NULLIF(due_date,''), movement_date)";
        $sqlGroup = "date($sqlDate)";
        $checkDate = "date(COALESCE(NULLIF(closed_at,''), due_date))";
        $checkGroup = "date(COALESCE(NULLIF(closed_at,''), due_date))";
        for ($i = $meta['steps'] - 1; $i >= 0; $i--) {
            $d = $today->modify('-' . $i . ' days');
            $key = $d->format('Y-m-d');
            $rows[$key] = [
                'key'=>$key,
                'label'=>$d->format('d.m'),
                'full_label'=>$d->format('d.m.Y'),
                'gelir'=>0,'gider'=>0,'tahsilat'=>0,'odeme'=>0,'in'=>0,'out'=>0,'net'=>0,
            ];
        }
        $startSql = $start->format('Y-m-d');
        $endSql = $end->format('Y-m-d');
    } elseif ($meta['group'] === 'current_year_month') {
        $year = (int)$today->format('Y');
        $start = new DateTimeImmutable($year . '-01-01');
        $end = new DateTimeImmutable($year . '-12-31');
        $sqlDate = "COALESCE(NULLIF(due_date,''), movement_date)";
        $sqlGroup = "strftime('%Y-%m', $sqlDate)";
        $checkDate = "date(COALESCE(NULLIF(closed_at,''), due_date))";
        $checkGroup = "strftime('%Y-%m', COALESCE(NULLIF(closed_at,''), due_date))";
        for ($m = 1; $m <= 12; $m++) {
            $d = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
            $key = $d->format('Y-m');
            $rows[$key] = [
                'key'=>$key,
                'label'=>$d->format('m.Y'),
                'full_label'=>$d->format('m.Y'),
                'gelir'=>0,'gider'=>0,'tahsilat'=>0,'odeme'=>0,'in'=>0,'out'=>0,'net'=>0,
            ];
        }
        $startSql = $start->format('Y-m-d');
        $endSql = $end->format('Y-m-d');
    } elseif ($meta['group'] === 'year') {
        $currentYear = (int)$today->format('Y');
        $firstYear = $currentYear - ($meta['steps'] - 1);
        $start = new DateTimeImmutable($firstYear . '-01-01');
        $end = new DateTimeImmutable($currentYear . '-12-31');
        $sqlDate = "COALESCE(NULLIF(due_date,''), movement_date)";
        $sqlGroup = "strftime('%Y', $sqlDate)";
        $checkDate = "date(COALESCE(NULLIF(closed_at,''), due_date))";
        $checkGroup = "strftime('%Y', COALESCE(NULLIF(closed_at,''), due_date))";
        for ($y = $firstYear; $y <= $currentYear; $y++) {
            $key = (string)$y;
            $rows[$key] = [
                'key'=>$key,
                'label'=>$key,
                'full_label'=>$key . ' yılı',
                'gelir'=>0,'gider'=>0,'tahsilat'=>0,'odeme'=>0,'in'=>0,'out'=>0,'net'=>0,
            ];
        }
        $startSql = $start->format('Y-m-d');
        $endSql = $end->format('Y-m-d');
    } else {
        $start = $today->modify('first day of this month')->modify('-' . ($meta['steps'] - 1) . ' months');
        $end = $today->modify('last day of this month');
        $sqlDate = "COALESCE(NULLIF(due_date,''), movement_date)";
        $sqlGroup = "strftime('%Y-%m', $sqlDate)";
        $checkDate = "date(COALESCE(NULLIF(closed_at,''), due_date))";
        $checkGroup = "strftime('%Y-%m', COALESCE(NULLIF(closed_at,''), due_date))";
        for ($i = $meta['steps'] - 1; $i >= 0; $i--) {
            $d = $today->modify('first day of this month')->modify('-' . $i . ' months');
            $key = $d->format('Y-m');
            $rows[$key] = [
                'key'=>$key,
                'label'=>$d->format('m.Y'),
                'full_label'=>$d->format('m.Y'),
                'gelir'=>0,'gider'=>0,'tahsilat'=>0,'odeme'=>0,'in'=>0,'out'=>0,'net'=>0,
            ];
        }
        $startSql = $start->format('Y-m-d');
        $endSql = $end->format('Y-m-d');
    }

    $stmt = db()->prepare("SELECT {$sqlGroup} AS period_key, movement_type, SUM(amount) AS total
        FROM movements
        WHERE {$sqlDate} >= ?
          AND {$sqlDate} <= ?
          AND COALESCE(is_cancelled,0)=0
          AND COALESCE(source_type,'') NOT IN ('check_acceptance','check_reversal')
          AND movement_type IN ('tahsilat','gelir','odeme','gider')
        GROUP BY period_key, movement_type
        ORDER BY period_key ASC");
    $stmt->execute([$startSql, $endSql]);
    foreach ($stmt->fetchAll() as $r) {
        $key = (string)$r['period_key'];
        $type = (string)$r['movement_type'];
        if (!isset($rows[$key]) || !array_key_exists($type, $rows[$key])) continue;
        $rows[$key][$type] += (float)$r['total'];
    }

    $stmt = db()->prepare("SELECT {$checkGroup} AS period_key, status, SUM(amount) AS total
        FROM checks
        WHERE {$checkDate} >= ?
          AND {$checkDate} <= ?
          AND COALESCE(is_cancelled,0)=0
          AND status IN ('tahsil_edildi','odendi')
        GROUP BY period_key, status
        ORDER BY period_key ASC");
    $stmt->execute([$startSql, $endSql]);
    foreach ($stmt->fetchAll() as $r) {
        $key = (string)$r['period_key'];
        if (!isset($rows[$key])) continue;
        if ($r['status'] === 'tahsil_edildi') $rows[$key]['tahsilat'] += (float)$r['total'];
        if ($r['status'] === 'odendi') $rows[$key]['odeme'] += (float)$r['total'];
    }
    foreach ($rows as &$r) {
        $r['in'] = $r['gelir'] + $r['tahsilat'];
        $r['out'] = $r['gider'] + $r['odeme'];
        $r['net'] = $r['in'] - $r['out'];
    }
    unset($r);
    return array_values($rows);
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$weekAhead = date('Y-m-d', strtotime('+7 days'));
$totals = dashboard_totals();
$monthTotals = dashboard_totals($monthStart, $monthEnd);
$monthCashTotals = cashflow_totals($monthStart, $monthEnd);
$monthCashIn = (float)$monthCashTotals['in'];
$monthCashOut = (float)$monthCashTotals['out'];
$monthCashNet = (float)$monthCashTotals['net'];
$netPosition = (float)$totals['net_alacak'] - (float)$totals['net_verecek'];
$checkTotals = check_totals(null, null, true);
$accountSummary = account_summary();
$checkSoon = check_totals($today, $weekAhead, true);
$overdueChecks = overdue_check_count();
$cariCount = (int)db()->query('SELECT COUNT(*) FROM cariler')->fetchColumn();
$movementCount = (int)db()->query('SELECT COUNT(*) FROM movements')->fetchColumn();
$docCount = (int)db()->query("SELECT COUNT(*) FROM movements WHERE document_path IS NOT NULL AND document_path != ''")->fetchColumn()
    + (int)db()->query("SELECT COUNT(*) FROM checks WHERE COALESCE(is_cancelled,0)=0 AND document_path IS NOT NULL AND document_path != ''")->fetchColumn()
    + (int)db()->query("SELECT COUNT(*) FROM private_receivables WHERE document_path IS NOT NULL AND document_path != ''")->fetchColumn()
    + (int)db()->query("SELECT COUNT(*) FROM standalone_documents WHERE document_path IS NOT NULL AND document_path != ''")->fetchColumn();
$recent = db()->query("SELECT m.*, c.name AS cari_name, cat.name AS category_name, a.name AS account_name FROM movements m LEFT JOIN cariler c ON c.id=m.cari_id LEFT JOIN categories cat ON cat.id=m.category_id LEFT JOIN accounts a ON a.id=m.account_id WHERE COALESCE(m.is_cancelled,0)=0 ORDER BY m.movement_date DESC, m.id DESC LIMIT 8")->fetchAll();
$dueMovStmt = db()->prepare("SELECT m.*, c.name AS cari_name FROM movements m LEFT JOIN cariler c ON c.id=m.cari_id WHERE COALESCE(m.is_cancelled,0)=0 AND COALESCE(m.source_type,'') NOT IN ('check_acceptance','check_reversal') AND m.due_date IS NOT NULL AND m.due_date <= ? AND m.movement_type IN ('alacak','verecek') ORDER BY m.due_date ASC, m.id DESC LIMIT 8");
$dueMovStmt->execute([$weekAhead]);
$dueMovements = $dueMovStmt->fetchAll();
$dueCheckStmt = db()->prepare("SELECT ch.*, c.name AS cari_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id WHERE COALESCE(ch.is_cancelled,0)=0 AND ch.status='bekliyor' AND ch.due_date <= ? ORDER BY ch.due_date ASC, ch.id DESC LIMIT 8");
$dueCheckStmt->execute([$weekAhead]);
$dueChecks = $dueCheckStmt->fetchAll();
$topStmt = db()->query("SELECT c.id, c.name,
    SUM(CASE WHEN m.movement_type='alacak' THEN m.amount ELSE 0 END) - SUM(CASE WHEN m.movement_type='tahsilat' THEN m.amount ELSE 0 END) AS net_alacak,
    SUM(CASE WHEN m.movement_type='verecek' THEN m.amount ELSE 0 END) - SUM(CASE WHEN m.movement_type='odeme' THEN m.amount ELSE 0 END) AS net_verecek
  FROM cariler c LEFT JOIN movements m ON m.cari_id=c.id AND COALESCE(m.is_cancelled,0)=0 GROUP BY c.id ORDER BY ABS((COALESCE(net_alacak,0)-COALESCE(net_verecek,0))) DESC LIMIT 6");
$topCariler = $topStmt->fetchAll();

$privateOpenSummary = private_receivable_totals(['status'=>'acik']);
$topReceivables = db()->query("SELECT c.id, c.name,
    COALESCE(SUM(CASE WHEN m.movement_type='alacak' THEN m.amount ELSE 0 END),0) - COALESCE(SUM(CASE WHEN m.movement_type='tahsilat' THEN m.amount ELSE 0 END),0) -
    (COALESCE(SUM(CASE WHEN m.movement_type='verecek' THEN m.amount ELSE 0 END),0) - COALESCE(SUM(CASE WHEN m.movement_type='odeme' THEN m.amount ELSE 0 END),0)) AS net
  FROM cariler c LEFT JOIN movements m ON m.cari_id=c.id AND COALESCE(m.is_cancelled,0)=0
  GROUP BY c.id HAVING net > 0 ORDER BY net DESC LIMIT 5")->fetchAll();
$topPayables = db()->query("SELECT c.id, c.name,
    COALESCE(SUM(CASE WHEN m.movement_type='alacak' THEN m.amount ELSE 0 END),0) - COALESCE(SUM(CASE WHEN m.movement_type='tahsilat' THEN m.amount ELSE 0 END),0) -
    (COALESCE(SUM(CASE WHEN m.movement_type='verecek' THEN m.amount ELSE 0 END),0) - COALESCE(SUM(CASE WHEN m.movement_type='odeme' THEN m.amount ELSE 0 END),0)) AS net
  FROM cariler c LEFT JOIN movements m ON m.cari_id=c.id AND COALESCE(m.is_cancelled,0)=0
  GROUP BY c.id HAVING net < 0 ORDER BY net ASC LIMIT 5")->fetchAll();
$upcomingPayments = [];
$payMoveStmt = db()->prepare("SELECT 'Verecek' AS source_type, m.due_date, m.amount, m.description, c.id AS cari_id, c.name AS cari_name FROM movements m LEFT JOIN cariler c ON c.id=m.cari_id WHERE COALESCE(m.is_cancelled,0)=0 AND COALESCE(m.source_type,'') NOT IN ('check_acceptance','check_reversal') AND m.movement_type='verecek' AND m.due_date BETWEEN ? AND ? ORDER BY m.due_date ASC LIMIT 8");
$payMoveStmt->execute([$today, $monthEnd]);
foreach ($payMoveStmt->fetchAll() as $row) $upcomingPayments[] = $row;
$payCheckStmt = db()->prepare("SELECT 'Çek' AS source_type, ch.due_date, ch.amount, TRIM(COALESCE(ch.bank_name,'') || ' ' || COALESCE(ch.check_no,'')) AS description, c.id AS cari_id, c.name AS cari_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id WHERE COALESCE(ch.is_cancelled,0)=0 AND ch.status='bekliyor' AND ch.direction='verilecek' AND ch.due_date BETWEEN ? AND ? ORDER BY ch.due_date ASC LIMIT 8");
$payCheckStmt->execute([$today, $monthEnd]);
foreach ($payCheckStmt->fetchAll() as $row) $upcomingPayments[] = $row;
usort($upcomingPayments, fn($a,$b)=>strcmp((string)$a['due_date'], (string)$b['due_date']));
$upcomingPayments = array_slice($upcomingPayments, 0, 8);

$noCollectionThreshold = date('Y-m-d', strtotime('-30 days'));
$noCollectionStmt = db()->prepare("SELECT * FROM (
    SELECT c.id, c.name,
      COALESCE(SUM(CASE WHEN m.movement_type='alacak' THEN m.amount ELSE 0 END),0) - COALESCE(SUM(CASE WHEN m.movement_type='tahsilat' THEN m.amount ELSE 0 END),0) AS net_alacak,
      MAX(CASE WHEN m.movement_type='tahsilat' THEN m.movement_date ELSE NULL END) AS last_tahsilat
    FROM cariler c LEFT JOIN movements m ON m.cari_id=c.id AND COALESCE(m.is_cancelled,0)=0
    GROUP BY c.id
  ) x WHERE net_alacak > 0 AND (last_tahsilat IS NULL OR last_tahsilat < ?) ORDER BY net_alacak DESC LIMIT 6");
$noCollectionStmt->execute([$noCollectionThreshold]);
$noCollectionCariler = $noCollectionStmt->fetchAll();

$smartAlertCount = count($noCollectionCariler) + count($dueChecks);

$chartPeriods = dashboard_cashflow_periods();
$chartPeriod = $_GET['chart_period'] ?? 'monthly';
if (!isset($chartPeriods[$chartPeriod])) $chartPeriod = 'monthly';
$chartRows = dashboard_cashflow_rows($chartPeriod);
$chartMeta = $chartPeriods[$chartPeriod];
$chartLabels = [];
$chartFullLabels = [];
$chartIn = [];
$chartOut = [];
$chartNet = [];
$chartTotals = ['in'=>0,'out'=>0,'net'=>0];
foreach ($chartRows as $m) {
    $chartLabels[] = $m['label'];
    $chartFullLabels[] = $m['full_label'];
    $chartIn[] = round($m['in'], 2);
    $chartOut[] = round($m['out'], 2);
    $chartNet[] = round($m['net'], 2);
    $chartTotals['in'] += $m['in'];
    $chartTotals['out'] += $m['out'];
}
$chartTotals['net'] = $chartTotals['in'] - $chartTotals['out'];

$lastBackup = setting_get('last_backup_date');
$lastBackupFile = setting_get('last_backup_file', '');
$daysSinceBackup = $lastBackup ? (int)floor((time() - strtotime($lastBackup)) / 86400) : null;
if (!$lastBackup) {
    $backupStatusLabel = 'Yedek yok';
    $backupStatusClass = 'backup-state-danger';
    $backupStatusIcon = '⚠️';
    $backupStatusDetail = 'Canlı veri için ilk yedeği almanız önerilir.';
} elseif ($daysSinceBackup === 0) {
    $backupStatusLabel = 'Güvende';
    $backupStatusClass = 'backup-state-ok';
    $backupStatusIcon = '✅';
    $backupStatusDetail = 'Bugün yedek alınmış.';
} elseif ($daysSinceBackup < 7) {
    $backupStatusLabel = 'Güvende';
    $backupStatusClass = 'backup-state-ok';
    $backupStatusIcon = '✅';
    $backupStatusDetail = $daysSinceBackup . ' gün önce yedek alınmış.';
} else {
    $backupStatusLabel = 'Yedek önerilir';
    $backupStatusClass = 'backup-state-warn';
    $backupStatusIcon = '⚠️';
    $backupStatusDetail = $daysSinceBackup . ' gün önce yedek alınmış; yeni yedek iyi olur.';
}
page_header('Genel Bakış', 'dashboard');
?>

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

<?php if (is_admin()): ?>
<section class="backup-mini-strip <?php echo e($backupStatusClass); ?>">
  <div>
    <span><?php echo e($backupStatusIcon); ?> Yedek durumu</span>
    <strong><?php echo e($backupStatusLabel); ?></strong>
    <small><?php echo e($backupStatusDetail); ?><?php echo $lastBackupFile ? ' · ' . e($lastBackupFile) : ''; ?></small>
  </div>
  <a href="yedekler.php">Yedekler →</a>
</section>
<?php endif; ?>

<section class="quick-grid mobile-focus">
  <?php if (can_write()): ?>
    <a class="quick-action" href="hareketler.php?movement_type=tahsilat"><strong>+ Tahsilat</strong><span>Hızlı para girişi</span></a>
    <a class="quick-action" href="hareketler.php?movement_type=odeme"><strong>+ Ödeme</strong><span>Hızlı para çıkışı</span></a>
    <a class="quick-action" href="cekler.php"><strong>+ Çek</strong><span>Vade takibi</span></a>
    <a class="quick-action" href="hareketler.php?movement_type=ozel_alacak"><strong>+ Özel Alacak</strong><span>İzole takip</span></a>
    <a class="quick-action" href="hesaplar.php"><strong>Kasa/Banka</strong><span>Para nerede?</span></a>
  <?php endif; ?>
</section>

<section class="dashboard-section">
  <div class="dashboard-section-head">
    <div><span>Cari Durum</span><h3>Genel cari pozisyon</h3></div>
    <p>Alacak, verecek ve genel durum ayrı ayrı okunur.</p>
  </div>
  <div class="stats-grid four section-stats">
    <article class="stat-card"><span>Toplam cari</span><strong><?php echo e($cariCount); ?></strong><small>Kişi / firma kartı</small></article>
    <article class="stat-card"><span>Net alacak</span><strong><?php echo e(money($totals['net_alacak'])); ?></strong><small>Alacak - tahsilat</small></article>
    <article class="stat-card"><span>Net verecek</span><strong><?php echo e(money($totals['net_verecek'])); ?></strong><small>Verecek - ödeme</small></article>
    <article class="stat-card status"><span>Genel durum</span><strong class="<?php echo $netPosition >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($netPosition)); ?></strong><small>Net alacak - net verecek</small></article>
  </div>
  <p class="calc-note"><strong>Genel durum</strong> = net alacak - net verecek. Pozitifse genel olarak alacaklı, negatifse borçlu görünürsün.</p>
</section>

<section class="dashboard-section">
  <div class="dashboard-section-head">
    <div><span>Nakit / Kasa</span><h3>Para akışı ve hesaplar</h3></div>
    <p>Bu ay kasaya giren/çıkan ve mevcut kasa-banka durumu.</p>
  </div>
  <div class="stats-grid four section-stats">
    <article class="stat-card cash"><span>Bu ay giren para</span><strong class="text-success"><?php echo e(money($monthCashIn)); ?></strong><small>Vade/tahsil tarihine göre</small></article>
    <article class="stat-card cash"><span>Bu ay çıkan para</span><strong class="text-danger"><?php echo e(money($monthCashOut)); ?></strong><small>Vade/ödeme tarihine göre</small></article>
    <article class="stat-card cash"><span>Bu ay nakit neti</span><strong class="<?php echo $monthCashNet >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($monthCashNet)); ?></strong><small>Giren - çıkan</small></article>
    <article class="stat-card soft"><span>Genel kasa/banka</span><strong class="<?php echo $accountSummary['total'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($accountSummary['total'])); ?></strong><small>Tüm hesap bakiyesi</small></article>
    <article class="stat-card soft"><span>Kasa toplamı</span><strong><?php echo e(money($accountSummary['kasa'])); ?></strong><small>Nakit hesapları</small></article>
    <article class="stat-card soft"><span>Banka toplamı</span><strong><?php echo e(money($accountSummary['banka'])); ?></strong><small>Banka hesapları</small></article>
    <article class="stat-card soft"><span>Aktif hesap</span><strong><?php echo e($accountSummary['active']); ?></strong><small>Kasa/banka/POS</small></article>
  </div>
  <p class="calc-note"><strong>Bu ay nakit neti</strong> = vade/tahsil tarihine göre giren para - çıkan para. Bekleyen çekler nakit sayılmaz.</p>
</section>

<section class="dashboard-section">
  <div class="dashboard-section-head">
    <div><span>Çek Takibi</span><h3>Çek vade ve bekleyenler</h3></div>
    <p>Alınacak, verilecek, yaklaşan ve vadesi geçen çekler.</p>
  </div>
  <div class="stats-grid four section-stats">
    <article class="stat-card soft"><span>Bekleyen alınacak çek</span><strong><?php echo e(money($checkTotals['alinacak'])); ?></strong><small><?php echo e($checkTotals['alinacak_count']); ?> adet</small></article>
    <article class="stat-card soft"><span>Bekleyen verilecek çek</span><strong><?php echo e(money($checkTotals['verilecek'])); ?></strong><small><?php echo e($checkTotals['verilecek_count']); ?> adet</small></article>
    <article class="stat-card soft"><span>7 gün içinde alınacak</span><strong><?php echo e(money($checkSoon['alinacak'])); ?></strong><small>Vadesi yaklaşan çek</small></article>
    <article class="stat-card soft"><span>Vadesi geçen çek</span><strong class="<?php echo $overdueChecks>0?'text-danger':''; ?>"><?php echo e($overdueChecks); ?></strong><small>Bekleyen kayıt</small></article>
  </div>
  <p class="calc-note"><strong>Çek takibi</strong> bekleyen çekleri ve vadeleri gösterir; tahsil edildi/ödendi yapılınca kasa-banka tarafı ayrıca etkilenir.</p>
</section>

<section class="dashboard-section">
  <div class="dashboard-section-head">
    <div><span>Özel Alan</span><h3>Özel alacak ve ödeme takibi</h3></div>
    <p>Genel cari toplamına karışmayan özel takip alanı.</p>
  </div>
  <div class="stats-grid three section-stats">
    <article class="stat-card special"><span>Açık özel alacak</span><strong><?php echo e(money($privateOpenSummary['acik'])); ?></strong><small><a href="ozel-alacaklar.php?status=acik">Özel rapora git</a></small></article>
    <article class="stat-card soft"><span>Özel alacak kayıt</span><strong><?php echo e((string)$privateOpenSummary['count']); ?></strong><small>Açık kayıt sayısı</small></article>
    <article class="stat-card soft"><span>Bu ay ödeme takibi</span><strong><?php echo e((string)count($upcomingPayments)); ?></strong><small>Verecek + verilecek çek</small></article>
  </div>
  <p class="calc-note"><strong>Özel alacak</strong> genel alacak/verecek toplamına dahil edilmez; sadece özel takip için ayrı tutulur.</p>
</section>

<section class="dashboard-section smart-alert-section">
  <div class="dashboard-section-head">
    <div><span>Akıllı Uyarılar</span><h3>Vade ve tahsilat hatırlatmaları</h3></div>
    <p><?php echo e((string)$smartAlertCount); ?> takip sinyali · gereksiz log değil, sadece dikkat isteyen işler.</p>
  </div>
  <div class="content-grid dashboard-focus">
    <article class="panel-card">
      <div class="card-head"><h3>30 gündür tahsilat görünmeyenler</h3><a href="cariler.php">Cariler</a></div>
      <div class="cari-mini-list">
        <?php if (!$noCollectionCariler): ?><p class="muted">Açık alacağı olup 30 gündür tahsilat görünmeyen cari yok.</p><?php endif; ?>
        <?php foreach($noCollectionCariler as $c): ?>
          <a class="mini-row alert-row" href="cari-detay.php?id=<?php echo e($c['id']); ?>">
            <span><?php echo e($c['name']); ?><small>Son tahsilat: <?php echo e($c['last_tahsilat'] ? tr_date($c['last_tahsilat']) : 'Yok'); ?></small></span>
            <strong class="text-danger"><?php echo e(money($c['net_alacak'])); ?></strong>
          </a>
        <?php endforeach; ?>
      </div>
    </article>
    <article class="panel-card">
      <div class="card-head"><h3>Çek vadesi yaklaşanlar</h3><a href="cekler.php?status=bekliyor&end=<?php echo e($weekAhead); ?>">Çekleri gör</a></div>
      <div class="cari-mini-list">
        <?php if (!$dueChecks): ?><p class="muted">Önümüzdeki 7 gün için bekleyen çek vadesi yok.</p><?php endif; ?>
        <?php foreach(array_slice($dueChecks, 0, 6) as $ch): ?>
          <a class="mini-row alert-row" href="cekler.php?status=bekliyor">
            <span><?php echo e($ch['cari_name'] ?: 'Cari yok'); ?><small><?php echo e(tr_date($ch['due_date'])); ?> · <?php echo e(trim(($ch['bank_name'] ?: '') . ' ' . ($ch['check_no'] ?: '')) ?: check_direction_label($ch['direction'])); ?></small></span>
            <strong class="<?php echo $ch['direction']==='verilecek' ? 'text-danger' : 'text-success'; ?>"><?php echo e(money($ch['amount'])); ?></strong>
          </a>
        <?php endforeach; ?>
      </div>
    </article>
  </div>
</section>

<section id="nakit-akisi" class="content-grid chart-layout" style="scroll-margin-top:18px;">
  <article class="panel-card cashflow-pro-card">
    <div class="card-head chart-card-head">
      <div>
        <h3>Nakit akışı</h3>
        <p><?php echo e($chartMeta['title']); ?> · giriş / çıkış / net karşılaştırması</p>
      </div>
      <a href="raporlar.php">Raporlar →</a>
    </div>

    <div class="chart-toolbar" role="tablist" aria-label="Nakit akışı dönem seçimi">
      <?php foreach ($chartPeriods as $key => $meta): ?>
        <a class="chart-period-btn <?php echo $chartPeriod === $key ? 'active' : ''; ?>" href="dashboard.php?chart_period=<?php echo e($key); ?>#nakit-akisi"><?php echo e($meta['label']); ?></a>
      <?php endforeach; ?>
    </div>

    <div class="chart-summary-grid">
      <div class="chart-summary-item in"><span>Toplam giriş</span><strong><?php echo e(money($chartTotals['in'])); ?></strong></div>
      <div class="chart-summary-item out"><span>Toplam çıkış</span><strong><?php echo e(money($chartTotals['out'])); ?></strong></div>
      <div class="chart-summary-item net"><span>Net sonuç</span><strong class="<?php echo $chartTotals['net'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($chartTotals['net'])); ?></strong></div>
    </div>

    <div class="chartjs-wrap chartjs-wrap-pro">
      <canvas id="cashflowChart" height="320"></canvas>
    </div>
    <div class="chart-legend chart-legend-pro">
      <span class="legend-in">● Giriş</span>
      <span class="legend-out">● Çıkış</span>
      <span class="legend-net">— Net</span>
      <small>Üzerine gelince tam tutar ve dönem detayı görünür.</small>
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
      <p class="backup-health-line"><strong>Yedek durumu:</strong> <span class="backup-mini-badge <?php echo e($backupStatusClass); ?>"><?php echo e($backupStatusLabel); ?></span> <small><?php echo e($backupStatusDetail); ?></small></p>
      <?php endif; ?>
    </div>
  </article>
</section>

<section class="content-grid dashboard-focus">
  <article class="panel-card">
    <div class="card-head"><h3>En yüksek alacak / borç</h3><a href="cariler.php">Cariler</a></div>
    <div class="split-mini-list">
      <div>
        <h4>Alacaklı oldukların</h4>
        <?php if (!$topReceivables): ?><p class="muted">Açık alacak görünmüyor.</p><?php endif; ?>
        <?php foreach($topReceivables as $c): ?>
          <a class="mini-row" href="cari-detay.php?id=<?php echo e($c['id']); ?>"><span><?php echo e($c['name']); ?></span><strong class="text-success"><?php echo e(money($c['net'])); ?></strong></a>
        <?php endforeach; ?>
      </div>
      <div>
        <h4>Borçlu oldukların</h4>
        <?php if (!$topPayables): ?><p class="muted">Açık borç görünmüyor.</p><?php endif; ?>
        <?php foreach($topPayables as $c): ?>
          <a class="mini-row" href="cari-detay.php?id=<?php echo e($c['id']); ?>"><span><?php echo e($c['name']); ?></span><strong class="text-danger"><?php echo e(money(abs((float)$c['net']))); ?></strong></a>
        <?php endforeach; ?>
      </div>
    </div>
  </article>
  <article class="panel-card">
    <div class="card-head"><h3>Bu ay yapılacak ödemeler</h3><a href="raporlar.php?start=<?php echo e($today); ?>&end=<?php echo e($monthEnd); ?>">Raporlar</a></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Vade</th><th>Tür</th><th>Cari</th><th class="right">Tutar</th></tr></thead>
        <tbody>
          <?php if (!$upcomingPayments): ?><tr><td colspan="4" class="empty">Bu ay için ödeme kaydı yok.</td></tr><?php endif; ?>
          <?php foreach($upcomingPayments as $p): ?>
            <tr>
              <td><?php echo e(tr_date($p['due_date'])); ?></td>
              <td><?php echo badge($p['source_type'], $p['source_type']==='Çek' ? 'warning' : 'danger'); ?><small><?php echo e($p['description'] ?: ''); ?></small></td>
              <td><?php echo $p['cari_id'] ? '<a href="cari-detay.php?id=' . e($p['cari_id']) . '">' . e($p['cari_name']) . '</a>' : '-'; ?></td>
              <td class="right"><strong><?php echo e(money($p['amount'])); ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
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
  if (window.location.hash === '#nakit-akisi') {
    window.requestAnimationFrame(function() {
      var target = document.getElementById('nakit-akisi');
      if (target) target.scrollIntoView({block: 'start'});
    });
  }
  var labels = <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>;
  var fullLabels = <?php echo json_encode($chartFullLabels, JSON_UNESCAPED_UNICODE); ?>;
  var inData  = <?php echo json_encode($chartIn); ?>;
  var outData = <?php echo json_encode($chartOut); ?>;
  var netData = <?php echo json_encode($chartNet); ?>;
  var canvas = document.getElementById('cashflowChart');
  if (!canvas || typeof Chart === 'undefined') return;
  var ctx = canvas.getContext('2d');
  var gradientIn = ctx.createLinearGradient(0, 0, 0, 320);
  gradientIn.addColorStop(0, 'rgba(41,122,74,0.86)');
  gradientIn.addColorStop(1, 'rgba(41,122,74,0.42)');
  var gradientOut = ctx.createLinearGradient(0, 0, 0, 320);
  gradientOut.addColorStop(0, 'rgba(185,66,58,0.78)');
  gradientOut.addColorStop(1, 'rgba(185,66,58,0.34)');
  var formatMoney = function(v) {
    v = Number(v || 0);
    return v.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' TL';
  };
  var compactMoney = function(v) {
    v = Number(v || 0);
    var abs = Math.abs(v);
    if (abs >= 1000000000) return (v / 1000000000).toLocaleString('tr-TR', {maximumFractionDigits:1}) + ' Mr';
    if (abs >= 1000000) return (v / 1000000).toLocaleString('tr-TR', {maximumFractionDigits:1}) + ' Mn';
    if (abs >= 1000) return (v / 1000).toLocaleString('tr-TR', {maximumFractionDigits:0}) + ' bin';
    return v.toLocaleString('tr-TR');
  };
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        {
          type: 'bar',
          label: 'Giriş',
          data: inData,
          backgroundColor: gradientIn,
          borderColor: 'rgba(41,122,74,0.95)',
          borderWidth: 1,
          borderRadius: 8,
          borderSkipped: false,
          maxBarThickness: 54,
          categoryPercentage: 0.72,
          barPercentage: 0.82,
          order: 2
        },
        {
          type: 'bar',
          label: 'Çıkış',
          data: outData,
          backgroundColor: gradientOut,
          borderColor: 'rgba(185,66,58,0.88)',
          borderWidth: 1,
          borderRadius: 8,
          borderSkipped: false,
          maxBarThickness: 54,
          categoryPercentage: 0.72,
          barPercentage: 0.82,
          order: 2
        },
        {
          type: 'line',
          label: 'Net',
          data: netData,
          borderColor: '#c8914b',
          backgroundColor: 'rgba(200,145,75,0.10)',
          borderWidth: 3,
          pointBackgroundColor: '#c8914b',
          pointBorderColor: '#fffaf3',
          pointBorderWidth: 2,
          pointHoverRadius: 7,
          pointRadius: 4,
          tension: 0.36,
          fill: true,
          order: 1
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      layout: { padding: { top: 8, right: 14, bottom: 2, left: 2 } },
      plugins: {
        legend: { display: false },
        tooltip: {
          enabled: true,
          backgroundColor: 'rgba(28,28,25,0.94)',
          titleColor: '#ffffff',
          bodyColor: '#ffffff',
          footerColor: '#e9dcc7',
          padding: 13,
          cornerRadius: 12,
          displayColors: true,
          boxPadding: 6,
          callbacks: {
            title: function(items) {
              var i = items && items[0] ? items[0].dataIndex : 0;
              return fullLabels[i] || labels[i] || '';
            },
            label: function(ctx) {
              return ' ' + ctx.dataset.label + ': ' + formatMoney(ctx.parsed.y);
            },
            footer: function(items) {
              var i = items && items[0] ? items[0].dataIndex : 0;
              var net = Number(netData[i] || 0);
              return 'Net durum: ' + (net >= 0 ? '+' : '') + formatMoney(net);
            }
          }
        }
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: {
            color: '#6f6a5d',
            autoSkip: true,
            maxRotation: 0,
            font: { size: 12, weight: '800' }
          }
        },
        y: {
          beginAtZero: true,
          grid: { color: 'rgba(31,59,45,0.08)', drawBorder: false },
          border: { display: false },
          ticks: {
            color: '#777267',
            padding: 8,
            font: { size: 11, weight: '700' },
            callback: function(v) { return compactMoney(v); }
          }
        }
      }
    }
  });
})();
</script>
<?php page_footer(); ?>
