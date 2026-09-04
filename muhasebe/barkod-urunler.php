<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/barkod-satis-lib.php';
require_login();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if (!can_manage_store_products()) {
    flash('error', 'Ürün yönetimi için Barkodlu Satış ürün yetkisi gerekiyor.');
    redirect('barkod-satis.php');
}
pos_db_ensure();
ensure_column(db(), 'pos_products', 'variant_name', 'TEXT');
$products = pos_products();
page_header('Yeni Ürün Girişi', 'barkod_satis');
?>
<link rel="stylesheet" href="assets/barkod-satis.css?v=9" />
<style>
.pos-product-page{max-width:1320px;margin:0 auto;display:grid;gap:16px}.pos-product-page-head{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:18px 20px;border:1px solid #e5dccf;border-radius:22px;background:#fff;box-shadow:0 12px 32px rgba(7,27,63,.06)}.pos-product-page-head h2{margin:0;color:#102818}.pos-product-page-head p{margin:5px 0 0;color:#776b5c}.pos-product-page-actions{display:flex;gap:8px;flex-wrap:wrap}.pos-product-page-actions a,.pos-product-page-actions button{white-space:nowrap}.pos-product-page .pos-products-panel{display:block!important}.pos-product-page .pos-product-form{display:grid!important}.pos-product-page .pos-product-manager{display:block!important}.pos-product-page .pos-product-manager-toolbar{margin-top:0}.pos-product-status{min-height:22px;margin:0;font-weight:850}.pos-product-status[data-tone="success"]{color:#167243}.pos-product-status[data-tone="error"]{color:#b64242}.pos-product-page .card-head{align-items:flex-start}.pos-product-page .pos-product-table-wrap{max-height:none}.pos-product-page .pos-product-manager-footer{position:sticky;bottom:10px;z-index:3;background:rgba(255,255,255,.94);backdrop-filter:blur(8px);padding-top:10px}.pos-back-sale{display:inline-flex;align-items:center;gap:6px;text-decoration:none;font-weight:900}@media(max-width:760px){.pos-product-page-head{display:block}.pos-product-page-actions{margin-top:12px}.pos-product-page-actions a,.pos-product-page-actions button{flex:1}.pos-product-page .pos-product-form{grid-template-columns:1fr}.pos-product-page .pos-product-form .wide{grid-column:1}}
</style>

<div class="pos-product-page" data-product-root data-api="barkod-satis-api.php" data-csrf="<?php echo e(csrf_token()); ?>">
  <section class="pos-product-page-head">
    <div>
      <span class="pos-kicker">BARKODLU SATIŞ · ÜRÜN YÖNETİMİ</span>
      <h2>Yeni Ürün Girişi</h2>
      <p>Ürün tanımlama, barkod, fiyat ve stok işlemlerini burada yap. Satış ekranı ayrı ve sade kalır.</p>
    </div>
    <div class="pos-product-page-actions">
      <a class="btn btn-secondary pos-back-sale" href="barkod-satis.php">← Satış ekranına dön</a>
      <a class="btn btn-secondary" href="barkod-stok-raporu.php">Stok ve Satış Dökümü</a>
      <button type="button" class="btn btn-primary" data-product-new>+ Yeni Ürün</button>
    </div>
  </section>

  <section class="panel-card pos-products-panel">
    <div class="card-head">
      <div><h3 data-form-title>Yeni Ürün Girişi</h3><p class="muted">Ana barkod, ürün adı ve satış fiyatı zorunludur. Beden/varyant ve ek barkod isteğe bağlıdır.</p></div>
    </div>
    <form class="pos-product-form" data-product-form>
      <input type="hidden" name="id" value="" />
      <label><span>Ana barkod</span><input name="barcode" autocomplete="off" required placeholder="Barkodu okutun veya yazın" /></label>
      <label class="wide"><span>Ürün adı</span><input name="name" required placeholder="Örn. Bitke Erkek Patik" /></label>
      <div class="wide pos-extra-barcodes">
        <span class="pos-field-label">Ek barkodlar</span>
        <div class="pos-extra-barcode-add"><input type="text" autocomplete="off" placeholder="Diğer barkodu okutun" data-extra-barcode-input /><button class="btn btn-secondary" type="button" data-extra-barcode-add>Ekle</button></div>
        <input type="hidden" name="extra_barcodes" value="" />
        <div class="pos-extra-barcode-list" data-extra-barcode-list><small>Henüz ek barkod yok.</small></div>
        <small>Ek barkodların tamamı aynı ürün, fiyat ve stok kaydını kullanır.</small>
      </div>
      <label><span>Satış fiyatı</span><input name="sale_price" type="number" min="0.01" step="0.01" required /></label>
      <label><span>KDV %</span><input name="vat_rate" type="number" min="0" max="100" step="1" value="10" /></label>
      <label><span>Stok adedi</span><input name="stock_quantity" type="number" min="0" step="1" value="0" /></label>
      <label class="pos-check"><input name="track_stock" type="checkbox" value="1" checked /><span>Stok takip edilsin</span></label>
      <label><span>Beden / Varyant <small>(isteğe bağlı)</small></span><input name="variant_name" autocomplete="off" maxlength="40" placeholder="Örn. S, M, L, XL" /></label>
      <button class="btn btn-primary" type="submit">Ürünü Kaydet</button>
    </form>
    <p class="pos-product-status" data-product-status></p>
  </section>

  <section class="panel-card pos-products-panel">
    <div class="card-head"><div><h3>Ürün Listesi</h3><p class="muted"><?php echo e(count($products)); ?> aktif Barkodlu Satış ürünü. Buradan fiyat ve stokları hızlıca düzenleyebilirsin.</p></div></div>
    <div class="pos-product-manager" data-product-manager>
      <div class="pos-product-manager-toolbar">
        <label><span>Ürünlerde ara</span><input type="search" autocomplete="off" placeholder="Ürün adı, barkod veya beden" data-product-list-search /></label>
        <button type="button" class="btn btn-primary" data-product-bulk-save>Tüm Değişiklikleri Kaydet</button>
      </div>
      <div class="pos-product-table-wrap">
        <table class="pos-product-table">
          <thead><tr><th>Ürün</th><th>Barkod</th><th>Satış fiyatı</th><th>Stok adedi</th><th>İşlem</th></tr></thead>
          <tbody>
          <?php foreach ($products as $p):
              $variant = trim((string)($p['variant_name'] ?? ''));
              $barcodes = $p['barcodes'] ?? [$p['barcode']];
              $searchText = mb_strtolower($p['name'] . ' ' . $variant . ' ' . implode(' ', $barcodes), 'UTF-8');
          ?>
            <tr data-bulk-product="<?php echo e($p['id']); ?>" data-product-search="<?php echo e($searchText); ?>">
              <td data-label="Ürün"><strong><?php echo e($p['name'] . ($variant !== '' ? ' - ' . $variant : '')); ?></strong></td>
              <td data-label="Barkod"><span><?php echo e($p['barcode']); ?></span><?php if ((int)($p['barcode_count'] ?? 1) > 1): ?><small><?php echo e((int)$p['barcode_count']); ?> barkod</small><?php endif; ?></td>
              <td data-label="Satış fiyatı"><input type="number" min="0.01" step="0.01" value="<?php echo e(number_format((float)$p['sale_price'], 2, '.', '')); ?>" data-bulk-price /></td>
              <td data-label="Stok adedi"><input type="number" step="1" value="<?php echo e(number_format((float)$p['stock_quantity'], 0, '.', '')); ?>" data-bulk-stock /></td>
              <td data-label="İşlem"><button type="button" class="btn btn-secondary" data-product-edit='<?php echo e(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>'>Düzenle</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="pos-product-manager-footer"><button type="button" class="btn btn-primary" data-product-bulk-save>Tüm Değişiklikleri Kaydet</button></div>
    </div>
  </section>
</div>
<script src="assets/barkod-urun-yonetimi.js?v=1"></script>
<?php page_footer(); ?>
