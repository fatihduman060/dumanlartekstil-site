<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/barkod-satis-lib.php';
require_login();
if (!can_manage_store_sales()) {
    flash('error', 'Stok dökümü için mağaza satış yetkisi gerekiyor.');
    redirect('dashboard.php');
}
$rows = pos_inventory_report();
$totalReceived = 0.0;
$totalSold = 0.0;
$totalSold30 = 0.0;
$totalStock = 0.0;
foreach ($rows as $row) {
    $totalReceived += (float)$row['received_quantity'];
    $totalSold += (float)$row['sold_quantity'];
    $totalSold30 += (float)$row['sold_last_30_days'];
    $totalStock += (float)$row['stock_quantity'];
}
page_header('Stok ve Satış Dökümü', 'barkod_satis');
?>
<style>
.stock-report-actions{display:flex;gap:9px;flex-wrap:wrap}.stock-report-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0}.stock-report-card{padding:16px;border:1px solid #ded4c2;border-radius:16px;background:#fff}.stock-report-card span{display:block;color:#777268;font-size:12px;font-weight:900}.stock-report-card strong{display:block;margin-top:7px;font-size:25px;color:#173d29}.stock-report-search{display:grid;gap:6px;max-width:520px;margin:14px 0;font-weight:900}.stock-report-search input{min-height:46px;border:1px solid #d9c79e;border-radius:12px;padding:9px 12px;font-size:16px}.stock-report-wrap{overflow:auto;border:1px solid #ded4c2;border-radius:16px}.stock-report-table{width:100%;border-collapse:collapse;min-width:850px;background:#fff}.stock-report-table th,.stock-report-table td{padding:12px;border-bottom:1px solid #eee5d5;text-align:right}.stock-report-table th{background:#f6f0e4;color:#625e55;font-size:12px;position:sticky;top:0}.stock-report-table th:first-child,.stock-report-table td:first-child,.stock-report-table th:nth-child(2),.stock-report-table td:nth-child(2){text-align:left}.stock-report-table small{display:block;color:#777268;margin-top:3px}.stock-low{color:#b33c35;font-weight:900}.stock-report-note{margin-top:12px;color:#706b62;font-size:12px}@media(max-width:720px){.stock-report-summary{grid-template-columns:1fr 1fr}.stock-report-card strong{font-size:20px}.stock-report-actions .btn{flex:1}.stock-report-wrap{border:0;overflow:visible}.stock-report-table{min-width:0;display:block}.stock-report-table thead{display:none}.stock-report-table tbody{display:grid;gap:12px}.stock-report-table tr{display:grid;gap:6px;padding:14px;border:1px solid #ded4c2;border-radius:15px;background:#fff}.stock-report-table td{display:flex;justify-content:space-between;gap:12px;padding:4px 0;border:0;text-align:right!important}.stock-report-table td:before{content:attr(data-label);font-size:11px;font-weight:900;color:#716c62;text-transform:uppercase}.stock-report-table td:first-child{display:block;text-align:left!important;font-size:16px}.stock-report-table td:first-child:before{display:none}}@media print{.sidebar,.topbar,.mobile-bottom-nav,.stock-report-actions,.stock-report-search{display:none!important}.app-shell,.main{display:block!important;margin:0!important;padding:0!important}.panel-card{box-shadow:none!important;border:0!important}.stock-report-table{min-width:0}.stock-report-table th{position:static}}
</style>
<section class="panel-card">
  <div class="card-head">
    <div><h2>Stok ve Satış Dökümü</h2><p class="muted">Ürün bazında giriş, satış ve kalan stok görünümü.</p></div>
    <div class="stock-report-actions"><a class="btn btn-secondary" href="barkod-satis.php">Barkodlu Satışa Dön</a><button type="button" class="btn btn-primary" onclick="window.print()">Yazdır</button></div>
  </div>
  <div class="stock-report-summary">
    <article class="stock-report-card"><span>TOPLAM ÜRÜN</span><strong><?php echo e(count($rows)); ?></strong></article>
    <article class="stock-report-card"><span>TOPLAM GİRİŞ</span><strong><?php echo e(number_format($totalReceived, 0, ',', '.')); ?></strong></article>
    <article class="stock-report-card"><span>TOPLAM SATILAN</span><strong><?php echo e(number_format($totalSold, 0, ',', '.')); ?></strong></article>
    <article class="stock-report-card"><span>ELDE KALAN</span><strong><?php echo e(number_format($totalStock, 0, ',', '.')); ?></strong></article>
  </div>
  <label class="stock-report-search"><span>Ürün veya barkod ara</span><input type="search" placeholder="Ürün adı, barkod veya artikel" data-stock-search /></label>
  <div class="stock-report-wrap">
    <table class="stock-report-table">
      <thead><tr><th>Ürün</th><th>Barkod</th><th>Toplam giriş</th><th>Toplam satılan</th><th>Son 30 gün</th><th>Elde kalan</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): $variant = trim((string)($row['variant_name'] ?? '')); $search = mb_strtolower($row['name'].' '.$variant.' '.implode(' ', $row['barcodes'] ?? [$row['barcode']]), 'UTF-8'); ?>
        <tr data-stock-row data-search="<?php echo e($search); ?>">
          <td data-label="Ürün"><strong><?php echo e($row['name'].($variant !== '' ? ' - '.$variant : '')); ?></strong></td>
          <td data-label="Barkod"><span><?php echo e($row['barcode']); ?></span><?php if ((int)($row['barcode_count'] ?? 1)>1): ?><small><?php echo e((int)$row['barcode_count']); ?> barkod</small><?php endif; ?></td>
          <td data-label="Toplam giriş"><?php echo e(number_format((float)$row['received_quantity'],0,',','.')); ?></td>
          <td data-label="Toplam satılan"><?php echo e(number_format((float)$row['sold_quantity'],0,',','.')); ?></td>
          <td data-label="Son 30 gün"><?php echo e(number_format((float)$row['sold_last_30_days'],0,',','.')); ?></td>
          <td data-label="Elde kalan" class="<?php echo (float)$row['stock_quantity']<=0?'stock-low':''; ?>"><?php echo e(number_format((float)$row['stock_quantity'],0,',','.')); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="stock-report-note">Toplam giriş, mevcut stok ile iptal edilmemiş satışların toplamından hesaplanır. İptal edilen satışlar satılan miktara dahil edilmez.</p>
</section>
<script>
(function(){var input=document.querySelector('[data-stock-search]');if(!input)return;input.addEventListener('input',function(){var q=String(this.value||'').toLocaleLowerCase('tr-TR').trim();document.querySelectorAll('[data-stock-row]').forEach(function(row){row.hidden=q!==''&&String(row.dataset.search||'').indexOf(q)===-1;});});})();
</script>
<?php page_footer(); ?>
