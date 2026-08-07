<?php
require_once __DIR__ . '/layout.php';
require_login();

$period = trim((string)($_GET['period'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');

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
.magaza-page-body{display:grid;gap:14px}.magaza-veresiye-card{display:grid;gap:5px;text-decoration:none;color:inherit;padding:16px;border-color:#b8d8c2;background:linear-gradient(135deg,#fff,#f1faf4)}.magaza-veresiye-card span{font-size:11px;font-weight:850;color:#4f765c;text-transform:uppercase}.magaza-veresiye-card strong{font-size:20px;color:#173f29}.magaza-veresiye-card small{color:#66736b}
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
.magaza-period-filter{order:2;display:grid;grid-template-columns:minmax(0,1fr) 106px;gap:8px;align-items:center;padding:9px}
.magaza-period-filter input,.magaza-period-filter .btn{width:100%;max-width:100%;margin:0;min-width:0;box-sizing:border-box}
.magaza-period-filter .btn{white-space:nowrap;padding-left:10px;padding-right:10px}
.magaza-satis-list .table-wrap{width:100%;max-width:100%;min-width:0;overflow:hidden}
.magaza-odeme-panel{overflow:hidden}
.magaza-odeme-list .table-wrap{width:100%;max-width:100%;min-width:0;overflow:auto}
.magaza-satis-summary article,.magaza-satis-form label,.magaza-satis-form input,
.magaza-odeme-summary article,.magaza-odeme-form label,.magaza-odeme-form input{min-width:0;max-width:100%;box-sizing:border-box}
[data-fatura-alt-kontrol-body]{order:3}
[data-magaza-odeme-dagilimi-body]{order:4}
}
</style>
<script src="assets/magaza-odeme-dagilimi.js?v=5"></script>
<?php if (!is_store_sales_user()): ?>
<script src="assets/magaza-gunluk-satis.js?v=10"></script>
<?php endif; ?>
<?php page_footer(); ?>
