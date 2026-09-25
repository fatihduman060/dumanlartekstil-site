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
<style>
/* stok-mobile-fix */
.pos-stock-search-box{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:end;flex:1;max-width:620px}
.pos-stock-search-box label{max-width:none!important;width:100%}
.pos-stock-search-box .btn{min-height:44px}
.pos-stock-search-info{margin:2px 0 10px;color:#6e695f;font-size:12px;font-weight:800}
.pos-stock-search-empty{margin:10px 0 0;padding:14px;border:1px dashed #d8c7a9;border-radius:12px;background:#fffaf1;color:#7a5e2e;font-weight:800}
#stokta-urunler [data-product-search][hidden]{display:none!important}

#stokta-urunler{scroll-margin-top:18px}
@media(max-width:760px){
  .pos-product-page{gap:12px;min-width:0}
  .pos-product-page-head{display:block;padding:14px;border-radius:16px}
  .pos-product-page-head h2{font-size:22px}
  .pos-product-page-head p{font-size:12px;line-height:1.45}
  .pos-product-page-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px}
  .pos-product-page-actions a,.pos-product-page-actions button{width:100%;min-width:0;white-space:normal;text-align:center;justify-content:center;line-height:1.2}
  .pos-product-page-actions .pos-back-sale{grid-column:1/-1}
  .pos-product-page .pos-products-panel{min-width:0;padding:14px;border-radius:16px}
  .pos-product-page .card-head h3{font-size:18px}
  .pos-product-page .card-head p{font-size:11px;line-height:1.4}
  .pos-product-page .pos-product-form{grid-template-columns:1fr;gap:10px}
  .pos-product-page .pos-product-form .wide{grid-column:1}
  .pos-product-page .pos-product-form input{min-width:0;width:100%;font-size:16px}
  .pos-product-page .pos-product-form>button[type="submit"]{width:100%;min-height:48px}
  #stokta-urunler .pos-product-manager{margin-top:10px;padding-top:0;border-top:0}
  #stokta-urunler .pos-product-manager-toolbar{display:grid;grid-template-columns:1fr;gap:9px;margin-bottom:8px}
  #stokta-urunler .pos-stock-search-box{display:grid;grid-template-columns:minmax(0,1fr) 74px;gap:8px;max-width:none;width:100%}
  #stokta-urunler .pos-product-manager-toolbar label{max-width:none;width:100%}
  #stokta-urunler .pos-product-manager-toolbar input{width:100%;min-width:0;min-height:50px;font-size:16px}
  #stokta-urunler .pos-stock-search-box .btn{width:74px;min-height:50px;padding:8px}
  #stokta-urunler .pos-product-manager-toolbar>[data-product-bulk-save]{width:100%;min-height:46px}
  #stokta-urunler .pos-product-table-wrap{overflow:visible;border:0;background:transparent}
  #stokta-urunler .pos-product-table{display:block;min-width:0;width:100%}
  #stokta-urunler .pos-product-table thead{display:none}
  #stokta-urunler .pos-product-table tbody{display:grid;gap:10px}
  #stokta-urunler .pos-product-table tr{display:grid;grid-template-columns:1fr 1fr;gap:10px 8px;padding:13px;border:1px solid #e0d4bd;border-radius:15px;background:#fff;min-width:0}
  #stokta-urunler .pos-product-table td{width:auto!important;min-width:0;padding:0;border:0}
  #stokta-urunler .pos-product-table td:before{display:block;margin-bottom:4px;font-size:10px;font-weight:900;color:#7a7469;text-transform:uppercase}
  #stokta-urunler .pos-product-table td:nth-child(1),
  #stokta-urunler .pos-product-table td:nth-child(2),
  #stokta-urunler .pos-product-table td:nth-child(5){grid-column:1/-1;display:block}
  #stokta-urunler .pos-product-table td:nth-child(1){padding-bottom:7px;border-bottom:1px solid #f0e8db}
  #stokta-urunler .pos-product-table td:nth-child(1) strong{display:block;font-size:15px;line-height:1.35;overflow-wrap:anywhere}
  #stokta-urunler .pos-product-table td:nth-child(2) span{font-size:13px;overflow-wrap:anywhere}
  #stokta-urunler .pos-product-table td:nth-child(3),
  #stokta-urunler .pos-product-table td:nth-child(4){display:block}
  #stokta-urunler .pos-product-table td input{width:100%;min-width:0;min-height:46px;font-size:16px}
  #stokta-urunler .pos-product-table td:nth-child(5) .btn{width:100%;min-height:44px}
  .pos-product-page .pos-product-manager-footer{position:static!important;margin-top:10px;padding-top:0;background:transparent;backdrop-filter:none}
  .pos-product-page .pos-product-manager-footer .btn{width:100%;min-height:48px}
}
@media(max-width:390px){
  .pos-product-page-actions{grid-template-columns:1fr}
  .pos-product-page-actions .pos-back-sale{grid-column:auto}
  #stokta-urunler .pos-product-table tr{grid-template-columns:1fr}
  #stokta-urunler .pos-product-table td:nth-child(3),
  #stokta-urunler .pos-product-table td:nth-child(4){grid-column:1}
}
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
      <label><span>Ana barkod</span><input name="barcode" autocomplete="off" required placeholder="Barkodu okutun veya yazın" data-barcode-input /></label>
      <label class="wide"><span>Ürün adı</span><input name="name" required placeholder="Örn. Bitke Erkek Patik" /></label>
      <div class="wide pos-extra-barcodes">
        <span class="pos-field-label">Ek barkodlar</span>
        <div class="pos-extra-barcode-add"><input type="text" autocomplete="off" placeholder="Diğer barkodu okutun" data-extra-barcode-input data-barcode-input /><button class="btn btn-secondary" type="button" data-extra-barcode-add>Ekle</button></div>
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

  <section class="panel-card pos-products-panel" id="stokta-urunler">
    <div class="card-head"><div><h3>Stoktaki Ürünler</h3><p class="muted"><?php echo e(count($products)); ?> aktif Barkodlu Satış ürünü. Ürün adına veya barkoda göre ara; fiyat, stok ve barkod bilgilerini düzenle.</p></div></div>
    <div class="pos-product-manager" data-product-manager>
      <div class="pos-product-manager-toolbar">
        <div class="pos-stock-search-box">
          <label><span>Ürünlerde ara</span><input type="search" autocomplete="off" enterkeyhint="search" placeholder="Ürün adı, barkod veya beden" data-product-list-search /></label>
          <button type="button" class="btn btn-secondary" data-product-search-button>Ara</button>
        </div>
        <button type="button" class="btn btn-primary" data-product-bulk-save>Tüm Değişiklikleri Kaydet</button>
      </div>
      <p class="pos-stock-search-info" data-product-search-info><?php echo e(count($products)); ?> ürün gösteriliyor.</p>
      <p class="pos-stock-search-empty" data-product-search-empty hidden>Aradığın ürünü bulamadım. Ürün adından başka bir kelime veya barkod deneyebilirsin.</p>
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
<script>
(function(){
  var section=document.getElementById('stokta-urunler');
  var search=document.querySelector('[data-product-list-search]');
  var button=document.querySelector('[data-product-search-button]');
  var info=document.querySelector('[data-product-search-info]');
  var empty=document.querySelector('[data-product-search-empty]');
  if(!section||!search)return;

  function normalize(value){
    return String(value||'')
      .toLocaleLowerCase('tr-TR')
      .replace(/[çÇ]/g,'c').replace(/[ğĞ]/g,'g').replace(/[ıİI]/g,'i')
      .replace(/[öÖ]/g,'o').replace(/[şŞ]/g,'s').replace(/[üÜ]/g,'u')
      .replace(/[^a-z0-9]+/g,' ')
      .trim();
  }

  function runSearch(){
    var query=normalize(search.value);
    var tokens=query?query.split(/\s+/).filter(Boolean):[];
    var total=0;
    var visible=0;
    section.querySelectorAll('[data-product-search]').forEach(function(row){
      total++;
      var hay=normalize((row.getAttribute('data-product-search')||'')+' '+(row.textContent||''));
      var match=!tokens.length||tokens.every(function(token){return hay.indexOf(token)!==-1;});
      row.hidden=!match;
      if(match)visible++;
    });
    if(info)info.textContent=tokens.length ? (visible+' ürün bulundu.') : (total+' ürün gösteriliyor.');
    if(empty)empty.hidden=visible!==0;
  }

  search.addEventListener('input',runSearch);
  search.addEventListener('search',runSearch);
  search.addEventListener('keydown',function(event){
    if(event.key==='Enter'){
      event.preventDefault();
      runSearch();
      search.blur();
    }
  });
  if(button)button.addEventListener('click',function(){runSearch();search.focus();});
  runSearch();

  if(location.hash==='#stokta-urunler'){
    setTimeout(function(){section.scrollIntoView({behavior:'smooth',block:'start'});},80);
    setTimeout(function(){search.focus();},350);
  }
})();
</script>
<script src="assets/zxing-browser-0.1.5.min.js?v=1"></script>
<script src="assets/barkod-kamera.js?v=3"></script>
<?php page_footer(); ?>
