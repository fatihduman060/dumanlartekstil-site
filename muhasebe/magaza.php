<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/magaza-odeme-dagilim-lib.php';
require_login();

$period = trim((string)($_GET['period'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');

$reportYear = (int)($_GET['report_year'] ?? date('Y'));
$currentYear = (int)date('Y');
if ($reportYear < 2020 || $reportYear > $currentYear + 2) $reportYear = $currentYear;

magaza_odeme_dagilim_tablosunu_hazirla();
for ($monthNo = 1; $monthNo <= 12; $monthNo++) {
    magaza_odeme_dagilim_veresiye_period_senkronla(sprintf('%04d-%02d', $reportYear, $monthNo));
}

$reportStart = sprintf('%04d-01-01', $reportYear);
$reportEnd = sprintf('%04d-12-31', $reportYear);
$reportStmt = db()->prepare("SELECT
        substr(sale_date, 6, 2) AS month_no,
        COALESCE(SUM(cash_amount),0) AS cash_total,
        COALESCE(SUM(card_amount),0) AS card_total,
        COALESCE(SUM(credit_amount),0) AS credit_total,
        COALESCE(SUM(daily_total),0) AS sales_total,
        COUNT(*) AS sales_day_count
    FROM store_daily_payment_breakdown
    WHERE sale_date BETWEEN ? AND ?
    GROUP BY substr(sale_date, 6, 2)
    ORDER BY month_no ASC");
$reportStmt->execute([$reportStart, $reportEnd]);
$reportRows = $reportStmt->fetchAll() ?: [];
$reportByMonth = [];
foreach ($reportRows as $reportRow) {
    $reportByMonth[(int)$reportRow['month_no']] = $reportRow;
}

$monthNames = [
    1=>'Ocak', 2=>'Şubat', 3=>'Mart', 4=>'Nisan', 5=>'Mayıs', 6=>'Haziran',
    7=>'Temmuz', 8=>'Ağustos', 9=>'Eylül', 10=>'Ekim', 11=>'Kasım', 12=>'Aralık',
];
$monthlyReport = [];
$yearSalesTotal = 0.0;
$yearCashTotal = 0.0;
$yearCardTotal = 0.0;
$yearCreditTotal = 0.0;
$yearSalesDays = 0;
$maxMonthTotal = 0.0;

for ($monthNo = 1; $monthNo <= 12; $monthNo++) {
    $row = $reportByMonth[$monthNo] ?? [];
    $cash = (float)($row['cash_total'] ?? 0);
    $card = (float)($row['card_total'] ?? 0);
    $credit = (float)($row['credit_total'] ?? 0);
    $total = (float)($row['sales_total'] ?? 0);
    $days = (int)($row['sales_day_count'] ?? 0);
    $monthlyReport[$monthNo] = [
        'name'=>$monthNames[$monthNo],
        'cash'=>$cash,
        'card'=>$card,
        'credit'=>$credit,
        'total'=>$total,
        'days'=>$days,
    ];
    $yearSalesTotal += $total;
    $yearCashTotal += $cash;
    $yearCardTotal += $card;
    $yearCreditTotal += $credit;
    $yearSalesDays += $days;
    if ($total > $maxMonthTotal) $maxMonthTotal = $total;
}

page_header('Mağaza', 'magaza');
?>
<section class="dashboard-section magaza-page-shell">
  <div class="dashboard-section-head">
    <div><span>Mağaza</span><h3>Günlük satışlar</h3><p>Günlük ödeme dağılımını kaydet; nakit aynı gün Mağaza Kasa’ya, kart/POS satışları 13 gün sonra Garanti Dumanlar hesabına otomatik işlensin.</p></div>
    <a class="btn btn-primary" href="magaza-veresiye.php">Personel Veresiye</a>
  </div>
  <a class="panel-card magaza-veresiye-card" href="magaza-veresiye.php">
    <span>Fabrika personeli</span><strong>Personel Veresiye Takibi</strong><small>Personel ekle, isimden ara, veresiye alışveriş ve tahsilatları takip et.</small>
  </a>

  <section class="magaza-report-panel" aria-labelledby="magaza-report-title">
    <div class="magaza-report-head">
      <div>
        <span>Mağaza raporu</span>
        <h3 id="magaza-report-title"><?php echo e((string)$reportYear); ?> Satış Raporu</h3>
        <p>Tahsilatlar dahil değildir. Nakit satış + Kart/POS satış + Veresiye satış toplamıdır.</p>
      </div>
      <form method="get" action="magaza.php" class="magaza-report-year-form">
        <input type="hidden" name="period" value="<?php echo e($period); ?>">
        <label>Yıl
          <select name="report_year" onchange="this.form.submit()">
            <?php for ($yearOption = $currentYear + 1; $yearOption >= max(2020, $currentYear - 7); $yearOption--): ?>
              <option value="<?php echo e((string)$yearOption); ?>" <?php echo $reportYear === $yearOption ? 'selected' : ''; ?>><?php echo e((string)$yearOption); ?></option>
            <?php endfor; ?>
          </select>
        </label>
      </form>
    </div>

    <div class="magaza-report-year-card">
      <div>
        <span>Yıllık toplam satış</span>
        <strong><?php echo e(money($yearSalesTotal)); ?></strong>
        <small><?php echo e((string)$yearSalesDays); ?> satış günü</small>
      </div>
      <div class="magaza-report-year-breakdown">
        <span>Nakit satış <strong><?php echo e(money($yearCashTotal)); ?></strong></span>
        <span>Kart / POS <strong><?php echo e(money($yearCardTotal)); ?></strong></span>
        <span>Veresiye <strong><?php echo e(money($yearCreditTotal)); ?></strong></span>
      </div>
    </div>

    <div class="magaza-report-months">
      <?php foreach ($monthlyReport as $monthNo => $month):
        $barWidth = $maxMonthTotal > 0 ? min(100, max(0, ($month['total'] / $maxMonthTotal) * 100)) : 0;
      ?>
      <article class="magaza-report-month-card <?php echo $month['total'] <= 0 ? 'is-empty' : ''; ?>">
        <div class="magaza-report-month-top">
          <div><span><?php echo e($month['name']); ?></span><small><?php echo e((string)$month['days']); ?> gün</small></div>
          <strong><?php echo e(money($month['total'])); ?></strong>
        </div>
        <div class="magaza-report-bar" aria-hidden="true"><i style="width:<?php echo e(number_format($barWidth, 2, '.', '')); ?>%"></i></div>
        <div class="magaza-report-month-breakdown">
          <span>Nakit <strong><?php echo e(money($month['cash'])); ?></strong></span>
          <span>Kart <strong><?php echo e(money($month['card'])); ?></strong></span>
          <span>Veresiye <strong><?php echo e(money($month['credit'])); ?></strong></span>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </section>

  <form class="filterbar magaza-period-filter" method="get" action="magaza.php">
    <input type="hidden" name="report_year" value="<?php echo e((string)$reportYear); ?>">
    <input type="month" name="period" value="<?php echo e($period); ?>">
    <button class="btn btn-secondary" type="submit">Ayı göster</button>
  </form>

  <div class="magaza-page-body" data-magaza-odeme-dagilimi-body></div>
  <div class="magaza-page-body" data-fatura-alt-kontrol-body></div>
</section>

<style>
.magaza-page-shell{display:grid;gap:16px;max-width:none}
.magaza-page-shell .dashboard-section-head{margin-bottom:0}
.magaza-period-filter{margin:0;padding:12px;border:1px solid var(--border);border-radius:14px;background:#fff}
.magaza-page-body{display:grid;gap:14px}.magaza-veresiye-card{display:grid;gap:5px;text-decoration:none;color:inherit;padding:16px;border-color:#b8d8c2;background:linear-gradient(135deg,#fff,#f1faf4)}.magaza-veresiye-card span{font-size:11px;font-weight:850;color:#4f765c;text-transform:uppercase}.magaza-veresiye-card strong{font-size:20px;color:#173f29}.magaza-veresiye-card small{color:#66736b}
.magaza-report-panel{display:grid;gap:14px;padding:18px;border:1px solid #d8c38f;border-radius:22px;background:linear-gradient(145deg,#fffaf0,#fff);box-shadow:0 10px 28px rgba(43,34,16,.05)}
.magaza-report-head{display:flex;align-items:end;justify-content:space-between;gap:16px}.magaza-report-head>div{display:grid;gap:3px}.magaza-report-head span{font-size:11px;font-weight:950;text-transform:uppercase;letter-spacing:.06em;color:#9a6b16}.magaza-report-head h3{margin:0;color:#173f29;font-size:22px}.magaza-report-head p{margin:0;color:#746b5f;font-size:12px}.magaza-report-year-form label{display:grid;gap:5px;font-size:11px;font-weight:900;color:#6b5b45}.magaza-report-year-form select{min-width:116px;height:43px;border:1px solid #d7c89f;border-radius:12px;background:#fff;padding:0 12px;font-weight:900;color:#173f29}
.magaza-report-year-card{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,.9fr);gap:18px;align-items:center;padding:20px 22px;border-radius:20px;background:#123f2b;color:#fff;border:1px solid #d4aa54}.magaza-report-year-card>div:first-child{display:grid;gap:5px}.magaza-report-year-card span{color:#f1cf85;font-size:12px;font-weight:850}.magaza-report-year-card>div:first-child>strong{font-size:36px;line-height:1.05;letter-spacing:-.03em}.magaza-report-year-card small{color:#c7d8cd}.magaza-report-year-breakdown{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.magaza-report-year-breakdown span{display:grid;gap:4px;padding:11px 12px;border:1px solid rgba(255,255,255,.14);border-radius:13px;background:rgba(255,255,255,.07);color:#c9d7ce}.magaza-report-year-breakdown strong{color:#fff;font-size:13px}
.magaza-report-months{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.magaza-report-month-card{display:grid;gap:10px;padding:14px;border:1px solid #e2d7bf;border-radius:16px;background:#fff}.magaza-report-month-card.is-empty{opacity:.55}.magaza-report-month-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px}.magaza-report-month-top>div{display:grid;gap:2px}.magaza-report-month-top span{font-size:13px;font-weight:950;color:#173f29;text-transform:none;letter-spacing:0}.magaza-report-month-top small{font-size:10px;color:#8d8275}.magaza-report-month-top>strong{font-size:17px;color:#173f29;text-align:right}.magaza-report-bar{height:6px;border-radius:999px;background:#f1ecdf;overflow:hidden}.magaza-report-bar i{display:block;height:100%;border-radius:inherit;background:#b9862e}.magaza-report-month-breakdown{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:5px}.magaza-report-month-breakdown span{display:grid;gap:2px;font-size:9px;color:#8a7d6c;text-transform:none;letter-spacing:0}.magaza-report-month-breakdown strong{font-size:10px;color:#4c4031}
@media(max-width:980px){.magaza-report-months{grid-template-columns:repeat(2,minmax(0,1fr))}.magaza-report-year-card{grid-template-columns:1fr}}
@media(max-width:700px){
.magaza-page-shell .dashboard-section-head{order:1}
.magaza-page-shell .dashboard-section-head p{display:none}
.magaza-page-shell>*,
.magaza-page-body,
.magaza-period-filter,
.magaza-satis-panel,
.magaza-mobile-latest,
.magaza-satis-list,
.magaza-satis-summary,
.magaza-satis-form,
.magaza-odeme-panel,
.magaza-odeme-head,
.magaza-odeme-summary,
.magaza-onceki-bozuk,
.magaza-odeme-form,
.magaza-odeme-list{width:100%;max-width:100%;min-width:0;box-sizing:border-box}
.magaza-report-panel{order:2;padding:13px;border-radius:18px}.magaza-report-head{align-items:stretch;flex-direction:column}.magaza-report-head p{font-size:11px}.magaza-report-year-form,.magaza-report-year-form label,.magaza-report-year-form select{width:100%;max-width:100%;box-sizing:border-box}.magaza-report-year-card{grid-template-columns:1fr;padding:17px;gap:14px}.magaza-report-year-card>div:first-child>strong{font-size:32px}.magaza-report-year-breakdown{grid-template-columns:1fr 1fr 1fr;gap:6px}.magaza-report-year-breakdown span{padding:9px 8px;font-size:9px}.magaza-report-year-breakdown strong{font-size:11px}.magaza-report-months{grid-template-columns:1fr;gap:8px}.magaza-report-month-card{padding:12px}.magaza-report-month-top>strong{font-size:18px}.magaza-report-month-breakdown span{font-size:9px}.magaza-report-month-breakdown strong{font-size:11px}
.magaza-period-filter{order:3;display:grid;grid-template-columns:minmax(0,1fr) 106px;gap:8px;align-items:center;padding:9px}
.magaza-period-filter input,.magaza-period-filter .btn{width:100%;max-width:100%;margin:0;min-width:0;box-sizing:border-box}
.magaza-period-filter .btn{white-space:nowrap;padding-left:10px;padding-right:10px}
.magaza-satis-list .table-wrap{width:100%;max-width:100%;min-width:0;overflow:hidden}
.magaza-odeme-panel{overflow:hidden}
.magaza-odeme-list .table-wrap{width:100%;max-width:100%;min-width:0;overflow:auto}
.magaza-satis-summary article,.magaza-satis-form label,.magaza-satis-form input,
.magaza-odeme-summary article,.magaza-odeme-form label,.magaza-odeme-form input{min-width:0;max-width:100%;box-sizing:border-box}
[data-fatura-alt-kontrol-body]{order:4}
[data-magaza-odeme-dagilimi-body]{order:5}
}
</style>
<script src="assets/magaza-odeme-dagilimi.js?v=6"></script>
<script src="assets/magaza-veresiye-manuel.js?v=2"></script>
<script src="assets/magaza-nakit-toplam-fix.js?v=1"></script>
<?php if (!is_store_sales_user()): ?>
<script src="assets/magaza-gunluk-satis.js?v=10"></script>
<?php endif; ?>
<?php page_footer(); ?>
