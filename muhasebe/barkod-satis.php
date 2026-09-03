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
<link rel="stylesheet" href="assets/barkod-satis.css?v=9" />
<div class="pos-shell" data-pos-root data-api="barkod-satis-api.php" data-csrf="<?php echo e(csrf_token()); ?>">
  <div class="pos-price-check" data-price-check hidden role="dialog" aria-modal="true" aria-labelledby="posPriceCheckTitle">
    <div class="pos-price-check-card">
      <div class="pos-price-check-head"><div><span class="pos-kicker">HIZLI SORGULAMA</span><h3 id="posPriceCheckTitle">Fiyat Bak</h3></div><button type="button" data-price-check-close aria-label="Kapat">×</button></div>
      <label><span>Barkod veya ürün adı</span><input type="text" autocomplete="off" placeholder="Barkodu okutun veya ürün adını yazın" data-price-check-input data-barcode-input /></label>
      <button type="button" class="btn btn-primary" data-price-check-search>Fiyatı Göster</button>
      <p class="pos-price-check-status" data-price-check-status></p>
      <div class="pos-price-check-results" data-price-check-results></div>
    </div>
  </div>
  <section class="pos-main panel-card">
    <div class="pos-title-row">
      <div><span class="pos-kicker">MAĞAZA KASASI</span><div class="pos-heading-line"><h2>Yeni Satış</h2><button type="button" class="btn btn-secondary pos-price-check-open" data-price-check-open>₺ Fiyat Bak</button></div><p>Barkodu okut veya ürün adından herhangi bir kelimeyi yaz.</p></div>
      <div class="pos-clock"><strong data-pos-clock>--:--</strong><span><?php echo e(tr_date(date('Y-m-d'))); ?></span></div>
    </div>

    <div class="pos-scan-row">
      <label class="pos-scan"><span>Barkod / ürün ara</span><input type="text" name="barcode_scan" inputmode="none" autocomplete="off" placeholder="Ürün adından bir kelime yazın veya barkodu okutun…" data-pos-scan data-barcode-input autofocus /></label>
      <button class="btn btn-secondary" type="button" data-pos-search>Ara</button>
    </div>
    <div class="pos-search-results" data-pos-results hidden></div>

    <div class="pos-cart-head"><h3>Sepet</h3><button type="button" class="pos-link danger" data-pos-clear>Sepeti temizle</button></div>
    <div class="pos-cart" data-pos-cart><div class="pos-empty">Henüz ürün okutulmadı.</div></div>
  </section>

  <aside class="pos-checkout panel-card">
    <div class="pos-total-box"><span>ÖDENECEK TOPLAM</span><strong data-pos-total>0,00 TL</strong><small data-pos-count>0 ürün</small></div>
    <label><span>İskonto tutarı</span><input type="number" min="0" step="0.01" value="0" data-pos-discount /></label>
    <fieldset class="pos-payments"><legend>Ödeme şekli</legend>
      <label><input type="radio" name="pos_payment" value="cash" checked /><span>💵 Nakit</span></label>
      <label><input type="radio" name="pos_payment" value="card" /><span>💳 Kart</span></label>
      <label><input type="radio" name="pos_payment" value="credit" /><span>🧾 Veresiye</span></label>
    </fieldset>
    <label class="pos-cari" data-pos-person-wrap hidden><span>Personel</span><select data-pos-person><option value="">Personel seçin</option><?php foreach ($creditPeople as $person): ?><option value="<?php echo e($person['id']); ?>"><?php echo e($person['full_name']); ?></option><?php endforeach; ?></select><small>Yalnızca Personel Veresiye Takibi'ndeki aktif personeller gösterilir.</small></label>
    <label><span>Not</span><input type="text" maxlength="160" placeholder="İsteğe bağlı" data-pos-note /></label>
    <button type="button" class="btn btn-primary pos-complete" data-pos-complete>Satışı Tamamla ve Direkt Yazdır</button>
    <a class="pos-direct-print-setup" href="#" data-windows-launcher>Windows sessiz yazdırma başlatıcısını indir</a>
    <small class="muted">Başlatıcıyla açıldığında fiş, yazdırma penceresi gösterilmeden varsayılan XP-Q805K yazıcısına gönderilir.</small>
    <p class="pos-status" data-pos-status></p>
  </aside>

  <section class="panel-card pos-products-panel">
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
<script src="assets/zxing-browser-0.1.5.min.js?v=1"></script>
<script src="assets/barkod-hizli-fiyat.js?v=2"></script>
<script src="assets/barkod-kamera.js?v=2"></script>
<script src="assets/barkod-satis.js?v=15"></script>
<script src="assets/barkod-canli-arama.js?v=1"></script>
<?php page_footer(); ?>