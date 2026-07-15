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
    <p>Fabrika satış mağazasının günlük KDV dâhil satışlarını bu bölümden kaydet ve takip et.</p>
  </div>

  <form class="filterbar magaza-period-filter" method="get" action="magaza.php">
    <input type="month" name="period" value="<?php echo e($period); ?>">
    <button class="btn btn-secondary" type="submit">Ayı göster</button>
  </form>

  <div class="magaza-page-body" data-fatura-alt-kontrol-body></div>
</section>

<style>
.magaza-page-shell{display:grid;gap:16px;max-width:none}
.magaza-page-shell .dashboard-section-head{margin-bottom:0}
.magaza-period-filter{margin:0;padding:12px;border:1px solid var(--border);border-radius:14px;background:#fff}
.magaza-page-body{display:grid;gap:14px}
@media(max-width:700px){.magaza-period-filter{align-items:stretch}}
</style>
<script src="assets/magaza-gunluk-satis.js?v=3"></script>
<?php page_footer(); ?>
