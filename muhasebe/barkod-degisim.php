<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/barkod-degisim-lib.php';
require_login();
if (!can_manage_store_sales()) { flash('error', 'Değişim için mağaza satış yetkisi gerekiyor.'); redirect('dashboard.php'); }
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$recent = pos_exchange_recent();
page_header('Ürün Değişimi', 'barkod_satis');
?>
<link rel="stylesheet" href="assets/barkod-degisim.css?v=2">
<div class="exchange" data-exchange-root data-api="barkod-degisim-api.php" data-product-api="barkod-satis-api.php" data-csrf="<?php echo e(csrf_token()); ?>">
  <header class="exchange-heading">
    <div><span class="exchange-kicker">MAĞAZA KASASI</span><h2>Ürün Değişimi</h2><p>Önce müşterinin getirdiği ürünleri, ardından vereceğiniz yeni ürünleri okutun. Tutarlar güncel satış fiyatlarıyla hesaplanır.</p></div>
    <a class="btn btn-secondary" href="barkod-satis.php">← Barkodlu Satış</a>
  </header>

  <div class="exchange-grid">
    <section class="exchange-card" data-exchange-section="returned" aria-labelledby="exchangeReturnedTitle">
      <div class="exchange-card-head"><span class="exchange-step">1</span><div><h3 id="exchangeReturnedTitle">İade edilen ürünler</h3><p>Müşterinin getirdiği ürünleri barkodla okutun.</p></div></div>
      <div class="exchange-search"><input type="text" data-exchange-scan="returned" inputmode="none" autocomplete="off" placeholder="Barkod okutun veya ürün adı yazın" aria-label="İade edilen ürün barkodu" autofocus><button type="button" data-exchange-search="returned">Ürün Bul</button></div>
      <div class="exchange-results" data-exchange-results="returned" hidden></div>
      <div class="exchange-lines" data-exchange-lines="returned"><p class="exchange-empty">Henüz ürün okutulmadı.</p></div>
      <div class="exchange-subtotal"><span>Getirilen ürünler</span><strong data-exchange-total="returned">0,00 TL</strong></div>
      <button type="button" class="exchange-next" data-exchange-next disabled>Yeni ürünlere geç →</button>
    </section>

    <section class="exchange-card" data-exchange-section="issued" aria-labelledby="exchangeIssuedTitle" data-locked="true">
      <div class="exchange-card-head"><span class="exchange-step">2</span><div><h3 id="exchangeIssuedTitle">Yeni alınan ürünler</h3><p>Müşteriye verilecek ürünleri barkodla okutun.</p></div></div>
      <div class="exchange-search"><input type="text" data-exchange-scan="issued" inputmode="none" autocomplete="off" placeholder="Barkod okutun veya ürün adı yazın" aria-label="Yeni ürün barkodu" disabled><button type="button" data-exchange-search="issued" disabled>Ürün Bul</button></div>
      <div class="exchange-results" data-exchange-results="issued" hidden></div>
      <div class="exchange-lines" data-exchange-lines="issued"><p class="exchange-empty">Önce getirilen ürünleri okutun.</p></div>
      <div class="exchange-subtotal"><span>Yeni ürünler</span><strong data-exchange-total="issued">0,00 TL</strong></div>
    </section>
  </div>

  <section class="exchange-checkout" aria-labelledby="exchangeCheckoutTitle">
    <div><span class="exchange-kicker">TUTAR ÖZETİ</span><h3 id="exchangeCheckoutTitle">Değişimi tamamla</h3><p>Para iadesi yapılmaz. Yeni ürünlerin toplamı getirilen ürünlerin tutarından az olamaz.</p></div>
    <div class="exchange-difference"><span>Alınacak fark</span><strong data-exchange-difference>0,00 TL</strong></div>
    <fieldset data-exchange-payment hidden><legend>Fark nasıl alındı?</legend><label><input type="radio" name="exchange_payment" value="cash"> Nakit</label><label><input type="radio" name="exchange_payment" value="card"> Kart</label></fieldset>
    <p class="exchange-status" data-exchange-status role="status"></p>
    <button type="button" class="exchange-complete" data-exchange-complete disabled>Değişimi Tamamla ve Fiş Yazdır</button>
  </section>

  <div class="exchange-remove-modal" data-exchange-removal-modal hidden role="dialog" aria-modal="true" aria-labelledby="exchangeRemovalTitle">
    <div class="exchange-remove-dialog">
      <h3 id="exchangeRemovalTitle">Ürünü değişim sepetinden sil</h3>
      <p data-exchange-removal-product></p>
      <label>Silme nedeni / Açıklama
        <textarea data-exchange-removal-reason maxlength="250" placeholder="Örn. Yanlış ürün okutuldu"></textarea>
      </label>
      <p class="exchange-remove-status" data-exchange-removal-status role="alert"></p>
      <div class="exchange-remove-actions">
        <button type="button" data-exchange-removal-cancel>Vazgeç</button>
        <button type="button" class="danger" data-exchange-removal-confirm>Ürünü Sil</button>
      </div>
    </div>
  </div>

  <section class="exchange-history" aria-labelledby="exchangeHistoryTitle">
    <h3 id="exchangeHistoryTitle">Son değişimler</h3>
    <?php if (!$recent): ?><p>Henüz değişim kaydı yok.</p><?php endif; ?>
    <?php foreach ($recent as $exchange): ?>
      <a href="barkod-fis.php?id=<?php echo e($exchange['id']); ?>" target="_blank" rel="noopener"><strong><?php echo e($exchange['receipt_no']); ?></strong><span><?php echo e(tr_date($exchange['sale_date'])); ?> <?php echo e(substr($exchange['sale_time'], 0, 5)); ?></span><b><?php echo e(money((float)$exchange['grand_total'])); ?> fark</b><span>Fişi aç ↗</span></a>
    <?php endforeach; ?>
  </section>
</div>
<script src="assets/barkod-degisim.js?v=3"></script>
<?php page_footer(); ?>
