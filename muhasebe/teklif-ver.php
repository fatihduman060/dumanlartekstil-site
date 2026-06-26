<?php
require_once __DIR__ . '/layout.php';
require_login();

$today = date('Y-m-d');
$defaultNo = 'TV-' . date('Ymd-His');
page_header('Teklif Ver', 'teklif_ver');
?>
<style>
.offer-builder{display:grid;gap:16px;max-width:1500px;margin:0 auto}.offer-hero{display:flex;justify-content:space-between;gap:16px;align-items:center;padding:22px 24px;border-radius:24px;background:linear-gradient(135deg,#102818,#23613c);color:#fff;box-shadow:0 18px 50px rgba(7,27,63,.10)}.offer-hero h2{margin:5px 0 6px;color:#fff;font-size:clamp(24px,3vw,38px);line-height:1}.offer-hero p{margin:0;color:#e9f5ed;max-width:760px}.offer-hero span{display:inline-flex;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.16);font-size:11px;font-weight:900;letter-spacing:.08em}.offer-card{background:#fff;border:1px solid #e5dccf;border-radius:22px;box-shadow:0 12px 34px rgba(7,27,63,.06);overflow:hidden}.offer-card header{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px 18px;background:#fbf6ed;border-bottom:1px solid #e5dccf}.offer-card h3{margin:0;color:#102818}.offer-body{padding:18px}.offer-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.offer-grid label{display:grid;gap:6px;font-size:12px;color:#102818;font-weight:850}.offer-grid input,.offer-grid select,.offer-grid textarea{min-height:42px;border:1px solid #e5dccf;border-radius:13px;padding:9px 11px;background:#fff;color:#102818;width:100%}.offer-grid .wide{grid-column:span 2}.offer-grid .full{grid-column:1/-1}.offer-table-wrap{overflow:auto;border:1px solid #e5dccf;border-radius:18px}.offer-table{width:100%;min-width:920px;border-collapse:collapse}.offer-table th{background:#16482e;color:#fff;text-align:left;padding:10px 9px;font-size:11px;text-transform:uppercase;letter-spacing:.03em}.offer-table td{border-bottom:1px solid #efe7dc;padding:8px}.offer-table input{width:100%;min-height:38px;border:1px solid #e5dccf;border-radius:11px;padding:7px 9px}.offer-table .right{text-align:right}.offer-total{display:flex;justify-content:flex-end;gap:10px;align-items:center;margin-top:12px;font-size:18px;color:#102818}.offer-total strong{font-size:24px}.offer-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.offer-actions button,.offer-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border:0;border-radius:999px;padding:9px 16px;font-weight:900;text-decoration:none}.offer-actions .primary{background:#16482e;color:#fff}.offer-actions .secondary{background:#efe6d9;color:#102818}.mini-help{margin-top:12px;padding:12px;border-radius:14px;background:#fbf6ed;color:#776b5c;font-weight:700}.row-remove{border:0!important;background:#fff1ed!important;color:#b64242!important;font-weight:900!important;cursor:pointer}@media(max-width:1000px){.offer-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.offer-grid .wide{grid-column:span 2}}@media(max-width:640px){.offer-hero{display:block}.offer-grid{grid-template-columns:1fr}.offer-grid .wide{grid-column:1}.offer-card header{display:block}.offer-actions button,.offer-actions a{width:100%}}
</style>

<div class="offer-builder">
  <section class="offer-hero">
    <div>
      <span>DUMANLAR / BİTKE TEKLİF MODÜLÜ</span>
      <h2>Teklif fişini panelden hazırla.</h2>
      <p>Firma, tarih, ürün satırları ve notu gir; tek tıkla A4 proforma/teklif çıktısı açılır. Logo ve üst şablonu sen atınca aynı alana birebir yerleştiririz.</p>
    </div>
    <div class="offer-actions"><a class="secondary" href="belgeler.php">Belgeler</a></div>
  </section>

  <section class="offer-card">
    <header><div><h3>Teklif bilgileri</h3><small>Örnek fiş formatına göre A4 çıktı üretir.</small></div><strong>Yeni teklif</strong></header>
    <div class="offer-body">
      <?php if (can_write()): ?>
      <form method="post" action="teklif-yazdir.php" target="_blank" id="offerForm">
        <?php echo csrf_field(); ?>
        <div class="offer-grid">
          <label><span>Belge başlığı</span><input name="document_title" value="TEKLİF FORMU" required></label>
          <label><span>Teklif / Sipariş no</span><input name="offer_no" value="<?php echo e($defaultNo); ?>" required></label>
          <label><span>Tarih</span><input type="date" name="offer_date" value="<?php echo e($today); ?>" required></label>
          <label><span>Para birimi</span><select name="currency"><option value="TL">TL</option><option value="USD">USD</option><option value="EUR">EUR</option></select></label>
          <label class="wide"><span>Firma / Müşteri</span><input name="customer_name" placeholder="EFEOĞLU TEKSTİL" required></label>
          <label><span>Şehir</span><input name="customer_city" placeholder="BURSA"></label>
          <label><span>Miktar başlığı</span><input name="quantity_label" value="DZ"></label>
          <label class="full"><span>Açıklama / alt not</span><textarea name="note" rows="2" placeholder="MODAL çorap kutusu 6080 kutusu gibi olacak."></textarea></label>
          <label class="wide"><span>Alt slogan</span><input name="footer_text" value="MALIMIZDAN HAYIR GÖRÜN."></label>
          <label class="wide"><span>Teklif notu</span><input name="term_text" placeholder="Fiyatlara KDV dahil değildir / Teslim süresi ..."></label>
        </div>

        <div style="height:16px"></div>
        <div class="offer-table-wrap">
          <table class="offer-table" id="offerRows">
            <thead><tr><th style="width:34%">Ürün adı</th><th style="width:22%">Ürün cinsi</th><th style="width:12%">Miktar</th><th style="width:14%">Birim fiyat</th><th style="width:14%">Tutar</th><th style="width:4%"></th></tr></thead>
            <tbody>
              <?php for ($i=0; $i<8; $i++): ?>
              <tr>
                <td><input name="product_name[]" placeholder="BİTKE ERKEK BAMBU ÇORAP"></td>
                <td><input name="product_type[]" placeholder="Ürün cinsi / açıklama"></td>
                <td><input name="quantity[]" class="calc qty" inputmode="decimal" placeholder="0"></td>
                <td><input name="unit_price[]" class="calc price" inputmode="decimal" placeholder="0,00"></td>
                <td class="right"><strong class="line-total">0,00</strong></td>
                <td><button class="row-remove" type="button">×</button></td>
              </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>
        <div class="offer-total"><span>Genel toplam:</span><strong id="grandTotal">0,00</strong></div>
        <div class="offer-actions">
          <button class="secondary" type="button" id="addRow">Satır ekle</button>
          <button class="primary" type="submit">Proforma / PDF çıktısı aç</button>
        </div>
        <p class="mini-help">Çıkan sayfada “Yazdır / PDF al” butonuna basıp PDF olarak kaydedebilirsin. Logo görselini attığında üst kısmı birebir tasarıma çeviririz.</p>
      </form>
      <?php else: ?>
        <p class="muted">Görüntüleme yetkisindesiniz. Teklif oluşturma kapalı.</p>
      <?php endif; ?>
    </div>
  </section>
</div>

<script>
(function(){
  const table = document.getElementById('offerRows');
  if (!table) return;
  const tbody = table.querySelector('tbody');
  const grand = document.getElementById('grandTotal');
  const add = document.getElementById('addRow');
  const fmt = new Intl.NumberFormat('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2});
  function num(v){
    v = String(v || '').replace(/\s/g,'').replace(/\./g,'').replace(',', '.');
    const n = parseFloat(v);
    return Number.isFinite(n) ? n : 0;
  }
  function recalc(){
    let sum = 0;
    tbody.querySelectorAll('tr').forEach(row => {
      const q = num(row.querySelector('.qty')?.value);
      const p = num(row.querySelector('.price')?.value);
      const total = q * p;
      sum += total;
      const out = row.querySelector('.line-total');
      if (out) out.textContent = fmt.format(total);
    });
    if (grand) grand.textContent = fmt.format(sum);
  }
  tbody.addEventListener('input', e => { if (e.target.classList.contains('calc')) recalc(); });
  tbody.addEventListener('click', e => {
    if (!e.target.classList.contains('row-remove')) return;
    const rows = tbody.querySelectorAll('tr');
    if (rows.length <= 1) return;
    e.target.closest('tr').remove();
    recalc();
  });
  add?.addEventListener('click', () => {
    const row = tbody.querySelector('tr').cloneNode(true);
    row.querySelectorAll('input').forEach(i => i.value = '');
    row.querySelector('.line-total').textContent = '0,00';
    tbody.appendChild(row);
  });
  recalc();
})();
</script>
<?php page_footer(); ?>
