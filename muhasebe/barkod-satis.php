<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/barkod-satis-lib.php';
require_login();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if (!can_manage_store_sales()) { flash('error', 'Barkodlu satış için mağaza satış yetkisi gerekiyor.'); redirect('dashboard.php'); }
pos_db_ensure();
ensure_column(db(), 'pos_products', 'variant_name', 'TEXT');
$products = pos_products();
$creditPeople = pos_credit_people();
$recentSales = pos_recent_sales(20);
$canDeleteSales = pos_can_delete_sales();
page_header('Barkodlu Satış', 'barkod_satis');
?>
<link rel="stylesheet" href="assets/barkod-satis.css?v=17" />
<style>
.pos-product-entry-tile{display:grid;grid-template-columns:46px 1fr auto;align-items:center;gap:11px;padding:13px 14px;margin:2px 0 10px;border:1px solid #d8cbb9;border-radius:16px;background:linear-gradient(135deg,#fffaf1,#f3eadc);text-decoration:none;color:#102818;box-shadow:0 8px 22px rgba(7,27,63,.05)}.pos-product-entry-tile:hover{border-color:#b89f7d;transform:translateY(-1px)}.pos-product-entry-icon{display:grid;place-items:center;width:46px;height:46px;border-radius:14px;background:#16482e;color:#fff;font-size:23px;font-weight:900}.pos-product-entry-tile strong{display:block;font-size:14px;color:#102818}.pos-product-entry-tile small{display:block;margin-top:3px;color:#776b5c;font-size:10px;line-height:1.35}.pos-product-entry-arrow{font-size:20px;color:#16482e;font-weight:900}.pos-products-panel.pos-products-legacy{display:none!important}@media(max-width:680px){.pos-product-entry-tile{grid-template-columns:42px 1fr auto;padding:11px}.pos-product-entry-icon{width:42px;height:42px}}
.pos-payment-modal[hidden]{display:none!important}.pos-payment-modal{position:fixed;inset:0;z-index:10100;display:grid;place-items:center;padding:18px;background:rgba(10,24,16,.7);backdrop-filter:blur(4px)}.pos-payment-dialog{width:min(520px,100%);padding:22px;border-radius:22px;background:#fff;box-shadow:0 25px 80px rgba(0,0,0,.35);display:grid;gap:16px}.pos-payment-head{display:flex;justify-content:space-between;gap:12px;align-items:start}.pos-payment-head h3{margin:3px 0;font-size:27px}.pos-payment-close{width:40px;height:40px;border:0;border-radius:50%;background:#f1eee7;font-size:24px;cursor:pointer}.pos-payment-dialog .pos-payments span{min-height:72px;font-size:15px}.pos-payment-confirm{min-height:54px;font-size:15px}.pos-payment-help{margin:0;color:var(--muted);font-size:11px}.pos-payment-dialog .pos-cari{display:grid;gap:6px}.pos-payment-dialog .pos-cari[hidden]{display:none!important}
@media(min-width:981px){
  .pos-checkout-slot{position:static;align-self:start;min-width:0}
  .pos-checkout-slot.is-locked{position:sticky;top:var(--pos-checkout-sticky-top,18px);z-index:5}
  .pos-checkout-slot>.pos-checkout{position:static!important;top:auto!important;align-self:start!important;max-height:none!important;height:max-content!important;overflow:visible!important;width:100%}
}
@media(max-width:980px){.pos-checkout-slot{position:static!important;grid-row:2}.pos-checkout-slot>.pos-checkout{position:static!important;top:auto!important}}
/* Kasada bırakılan para sağ kolonda değil, Son Satışlar'ın altında tam genişlikte akar. */
.pos-shell .pos-history-cash-grid{grid-template-columns:minmax(0,1fr)!important}
.pos-shell .pos-history-cash-grid>.pos-history,.pos-shell .pos-history-cash-grid>.pos-cash-left-card{grid-column:1/-1!important;width:100%}
</style>
<div class="pos-shell" data-pos-root data-api="barkod-satis-api.php" data-csrf="<?php echo e(csrf_token()); ?>">
  <div class="pos-price-check" data-price-check hidden role="dialog" aria-modal="true" aria-labelledby="posPriceCheckTitle">
    <div class="pos-price-check-card">
      <div class="pos-price-check-head"><div><span class="pos-kicker">HIZLI SORGULAMA</span><h3 id="posPriceCheckTitle">Fiyat Bak</h3></div><button type="button" data-price-check-close aria-label="Kapat">×</button></div>
      <label><span>Barkod veya ürün adı</span><input type="text" autocomplete="off" placeholder="Barkodu okutun veya ürün adını yazın" data-price-check-input data-barcode-input /></label>
      <button type="button" class="btn btn-primary" data-price-check-search>Fiyatı Göster</button>
      <p class="pos-price-check-status" data-price-check-status></p>
      <div class="pos-price-check-results" data-pos-price-check-results></div>
    </div>
  </div>
  <section class="pos-main panel-card">
    <div class="pos-title-row">
      <div><span class="pos-kicker">MAĞAZA KASASI</span><div class="pos-heading-line"><h2>Yeni Satış</h2><button type="button" class="btn btn-secondary pos-price-check-open" data-price-check-open>₺ Fiyat Bak</button><a class="btn btn-secondary pos-product-entry-button" href="barkod-urunler.php">+ Yeni Ürün Ekle</a></div><p>Barkodu okut veya ürün adından herhangi bir kelimeyi yaz. Son ürünü artırmak için örneğin +3 yazman yeterli.</p></div>
      <div class="pos-clock"><strong data-pos-clock>--:--</strong><span><?php echo e(tr_date(date('Y-m-d'))); ?></span></div>
    </div>

    <div class="pos-scan-row">
      <label class="pos-scan"><span>Barkod / ürün ara</span><input type="text" name="barcode_scan" inputmode="none" autocomplete="off" placeholder="Ürün adından bir kelime yazın veya barkodu okutun…" data-pos-scan data-barcode-input autofocus /></label>
      <button class="btn btn-secondary" type="button" data-pos-search>Ara</button>
    </div>
    <div class="pos-search-results" data-pos-results hidden></div>

    <div class="pos-cart-head"><h3>Satış Ürünleri</h3><button type="button" class="pos-link danger" data-pos-clear>Sepeti temizle</button></div>
    <div class="pos-cart-columns" aria-hidden="true"><span>Ürün</span><span>Miktar</span><span>Birim</span><span>Fiyat</span><span>İskonto</span><span>Vergi</span><span>Tutar</span><span></span></div>
    <div class="pos-cart" data-pos-cart><div class="pos-empty">Henüz ürün okutulmadı.</div></div>
  </section>

  <div class="pos-checkout-slot">
    <aside class="pos-checkout panel-card">
      <div class="pos-total-box"><span>ÖDENECEK TOPLAM</span><strong data-pos-total>0,00 TL</strong><small data-pos-count>0 ürün</small></div>
      <div class="pos-customer-summary"><span>MÜŞTERİ</span><strong data-pos-customer-name>Perakende Müşteri</strong><small>Veresiye seçildiğinde müşteri belirleyin.</small></div>
      <label><span>İskonto tutarı</span><input type="number" min="0" step="0.01" value="0" data-pos-discount /></label>
      <label><span>Not</span><input type="text" maxlength="160" placeholder="İsteğe bağlı" data-pos-note /></label>
      <button type="button" class="btn btn-primary pos-complete" data-pos-complete>Satışı Tamamla ve Direkt Yazdır</button>
      <a class="pos-direct-print-setup" href="#" data-windows-launcher>Windows sessiz yazdırma başlatıcısını indir</a>
      <small class="muted">Başlatıcıyla açıldığında fiş, yazdırma penceresi gösterilmeden varsayılan XP-Q805K yazıcısına gönderilir.</small>
      <p class="pos-status" data-pos-status></p>
    </aside>
  </div>

  <div class="pos-payment-modal" data-pos-payment-modal hidden role="dialog" aria-modal="true" aria-labelledby="posPaymentTitle">
    <div class="pos-payment-dialog">
      <div class="pos-payment-head"><div><span class="pos-kicker">SATIŞI TAMAMLA</span><h3 id="posPaymentTitle">Ödeme şeklini seçin</h3></div><button type="button" class="pos-payment-close" data-pos-payment-close aria-label="Kapat">×</button></div>
      <p class="pos-payment-help">Satış, aşağıdaki ödeme türünü seçip onayladıktan sonra kaydedilecek ve fiş yazdırılacaktır.</p>
      <fieldset class="pos-payments"><legend>Ödeme şekli</legend>
        <label><input type="radio" name="pos_payment" value="cash" /><span>💵 Nakit</span></label>
        <label><input type="radio" name="pos_payment" value="card" /><span>💳 Kredi Kartı</span></label>
        <label><input type="radio" name="pos_payment" value="credit" /><span>🧾 Veresiye</span></label>
      </fieldset>
      <label class="pos-cari" data-pos-person-wrap hidden><span>Personel</span><select data-pos-person><option value="">Personel seçin</option><?php foreach ($creditPeople as $person): ?><option value="<?php echo e($person['id']); ?>"><?php echo e($person['full_name']); ?></option><?php endforeach; ?></select><small>Veresiye satış için müşteri seçimi zorunludur.</small></label>
      <p class="pos-status" data-pos-payment-status></p>
      <button type="button" class="btn btn-primary pos-payment-confirm" data-pos-payment-confirm>Seçimi Onayla ve Satışı Tamamla</button>
    </div>
  </div>

  <section class="panel-card pos-products-panel pos-products-legacy" aria-hidden="true">
    <div class="card-head"><div><h3>Ürün Tanımlama</h3><p class="muted">Yeni ürün ekleyebilir veya ürün listesinden fiyat ve stokları topluca düzenleyebilirsin.</p></div><div class="pos-product-head-actions"><a class="btn btn-secondary" href="barkod-stok-raporu.php">Stok ve Satış Dökümü</a><button type="button" class="btn btn-secondary" data-product-list-toggle aria-expanded="false">Ürün Listesi (<?php echo e(count($products)); ?>)</button><button type="button" class="btn btn-secondary" data-product-new>Yeni ürün</button></div></div>
    <form class="pos-product-form" data-product-form>
      <input type="hidden" name="id" value="" />
      <label><span>Ana barkod</span><input name="barcode" autocomplete="off" required placeholder="Barkodu okutun" data-barcode-input /></label>
      <label class="wide"><span>Ürün adı</span><input name="name" required placeholder="Örn. Bitke Erkek Patik" /></label>
      <div class="wide pos-extra-barcodes">
        <span class="pos-field-label">Ek barkodlar</span>
        <div class="pos-extra-barcode-add"><input type="text" autocomplete="off" placeholder="Diğer barkodu okutun" data-extra-barcode-input data-barcode-input /><button class="btn btn-secondary" type="button" data-extra-barcode-add>Ekle</button></div>
        <input type="hidden" name="extra_barcodes" value="" />
        <div class="pos-extra-barcode-list" data-extra-barcode-list><small>Henüz ek barkod yok.</small></div>
        <small>Eklediğiniz barkodların tamamı aynı ürünü, fiyatı ve stoğu kullanır.</small>
      </div>
      <label><span>Satış fiyatı</span><input name="sale_price" type="number" min="0.01" step="0.01" required /></label>
      <label><span>KDV %</span><input name="vat_rate" type="number" min="0" max="100" step="1" value="10" /></label>
      <label><span>Başlangıç stoku</span><input name="stock_quantity" type="number" min="0" step="1" value="0" /></label>
      <label class="pos-check"><input name="track_stock" type="checkbox" value="1" checked /><span>Stok takip edilsin</span></label>
      <label><span>Beden / Varyant <small>(isteğe bağlı)</small></span><input name="variant_name" autocomplete="off" maxlength="40" placeholder="Örn. S, M, L, XL" /></label>
      <button class="btn btn-primary" type="submit">Ürünü Kaydet</button>
    </form>
    <div class="pos-product-manager" data-product-manager hidden>
      <div class="pos-product-manager-toolbar">
        <label><span>Ürünlerde ara</span><input type="search" autocomplete="off" placeholder="Ürün adı, barkod veya artikel" data-product-list-search /></label>
        <button type="button" class="btn btn-primary" data-product-bulk-save>Tüm Değişiklikleri Kaydet</button>
      </div>
      <p class="pos-product-manager-status" data-product-manager-status></p>
      <div class="pos-product-table-wrap">
        <table class="pos-product-table">
          <thead><tr><th>Ürün</th><th>Barkod</th><th>Satış fiyatı</th><th>Stok adedi</th><th>İşlem</th></tr></thead>
          <tbody>
          <?php foreach ($products as $p): $variant = trim((string)($p['variant_name'] ?? '')); $searchText = mb_strtolower($p['name'] . ' ' . $variant . ' ' . implode(' ', $p['barcodes'] ?? [$p['barcode']]), 'UTF-8'); ?>
            <tr data-bulk-product="<?php echo e($p['id']); ?>" data-product-search="<?php echo e($searchText); ?>">
              <td data-label="Ürün"><strong><?php echo e($p['name'] . ($variant !== '' ? ' - ' . $variant : '')); ?></strong></td>
              <td data-label="Barkod"><span><?php echo e($p['barcode']); ?></span><?php if ((int)($p['barcode_count'] ?? 1) > 1): ?><small><?php echo e((int)$p['barcode_count']); ?> barkod</small><?php endif; ?></td>
              <td data-label="Satış fiyatı"><input type="number" min="0.01" step="0.01" value="<?php echo e(number_format((float)$p['sale_price'], 2, '.', '')); ?>" data-bulk-price /></td>
              <td data-label="Stok adedi"><input type="number" step="1" value="<?php echo e(number_format((float)$p['stock_quantity'], 0, '.', '')); ?>" data-bulk-stock /></td>
              <td data-label="İşlem"><button type="button" class="btn btn-secondary pos-product-edit-button" data-product-edit='<?php echo e(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>'>Düzenle</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="pos-product-manager-footer"><button type="button" class="btn btn-primary" data-product-bulk-save>Tüm Değişiklikleri Kaydet</button></div>
    </div>
  </section>

  <section class="panel-card pos-history">
    <div class="card-head"><h3>Son Satışlar</h3><span>Fişi yeniden yazdır</span></div>
    <div class="pos-history-list">
      <?php if (!$recentSales): ?><p class="muted">Henüz barkodlu satış yok.</p><?php endif; ?>
      <?php foreach ($recentSales as $sale): ?>
      <div class="pos-history-item">
        <a href="barkod-fis.php?id=<?php echo e($sale['id']); ?>" target="_blank" class="pos-history-row"><span><strong><?php echo e($sale['receipt_no']); ?></strong><small><?php echo e(tr_date($sale['sale_date'])); ?> <?php echo e(substr($sale['sale_time'],0,5)); ?> · <?php echo e($sale['customer_name']); ?></small></span><strong><?php echo e(money((float)$sale['grand_total'])); ?></strong></a>
        <?php if ($canDeleteSales): ?><button type="button" class="pos-sale-delete" data-sale-delete="<?php echo e($sale['id']); ?>" data-receipt-no="<?php echo e($sale['receipt_no']); ?>">Sil</button><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
<script>
(function(){
  var slot=document.querySelector('.pos-checkout-slot');
  if(!slot||!window.matchMedia('(min-width:981px)').matches)return;
  var lock=function(){
    slot.classList.remove('is-locked');
    slot.style.removeProperty('--pos-checkout-sticky-top');
    requestAnimationFrame(function(){
      var naturalTop=Math.round(slot.getBoundingClientRect().top+window.scrollY);
      if(naturalTop<0)naturalTop=0;
      slot.style.setProperty('--pos-checkout-sticky-top',naturalTop+'px');
      slot.classList.add('is-locked');
    });
  };
  if(document.readyState==='complete')lock();
  else window.addEventListener('load',lock,{once:true});
})();
</script>
<script src="assets/zxing-browser-0.1.5.min.js?v=1"></script>
<script src="assets/barkod-hizli-fiyat.js?v=2"></script>
<script src="assets/barkod-kamera.js?v=2"></script>
<script src="assets/barkod-satis.js?v=22"></script>
<script src="assets/barkod-canli-arama.js?v=7"></script>
<script src="assets/barkod-veresiye-yeni-kisi.js?v=1"></script>
<script src="assets/barkod-cuma-hizli-satis.js?v=1"></script>
<script src="assets/barkod-satis-gecmis.js?v=5"></script>
<?php page_footer(); ?>