<?php
require_once __DIR__ . '/layout.php';
require_login();

$period = trim((string)($_GET['period'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');

page_header('Mağaza', 'magaza');
?>
<section class="dashboard-section magaza-page-shell">
  <div class="dashboard-section-head">
    <div><span>Mağaza</span><h3>Günlük satışlar</h3></div>
    <p>Günlük ödeme dağılımını kaydet; nakit aynı gün Ana Kasa’ya, kart/POS satışları 13 gün sonra Garanti Dumanlar hesabına otomatik işlensin.</p>
  </div>

  <form class="filterbar magaza-period-filter" method="get" action="magaza.php">
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
.magaza-page-body{display:grid;gap:14px}
@media(max-width:700px){
.magaza-page-shell .dashboard-section-head{order:1}
.magaza-page-shell .dashboard-section-head p{display:none}
.magaza-period-filter{order:2;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center;padding:9px}
.magaza-period-filter input,.magaza-period-filter .btn{margin:0;min-width:0}
.magaza-period-filter .btn{white-space:nowrap;padding-left:12px;padding-right:12px}
[data-fatura-alt-kontrol-body]{order:3}
[data-magaza-odeme-dagilimi-body]{order:4}
}
</style>
<script src="assets/magaza-odeme-dagilimi.js?v=4"></script>
<?php if (!is_store_sales_user()): ?>
<script src="assets/magaza-gunluk-satis.js?v=4"></script>
<?php endif; ?>
<?php page_footer(); ?>
