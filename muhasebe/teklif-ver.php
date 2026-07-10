<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/teklif-db.php';
require_login();
teklif_db_ensure();

if (!function_exists('teklif_next_offer_no')) {
    function teklif_next_offer_no(): string
    {
        $max = 0;
        try {
            $rows = db()->query('SELECT offer_no FROM offers')->fetchAll();
            foreach ($rows as $row) {
                $raw = trim((string)($row['offer_no'] ?? ''));
                if ($raw !== '' && ctype_digit($raw)) {
                    $max = max($max, (int)$raw);
                }
            }
        } catch (Throwable $e) {}
        return str_pad((string)($max + 1), 5, '0', STR_PAD_LEFT);
    }
}

$today = date('Y-m-d');
$defaultNo = teklif_next_offer_no();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write();
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $offerId = teklif_save_from_post((int)($_POST['id'] ?? 0));
            flash('success', 'Teklif kaydedildi. İstersen alttan PDF alabilir veya tekrar düzenleyebilirsin.');
            redirect('teklif-ver.php?edit=' . $offerId);
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if (teklif_delete($id)) flash('success', 'Teklif silindi.');
            else flash('error', 'Silinecek teklif bulunamadı.');
            redirect('teklif-ver.php');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('teklif-ver.php' . (!empty($_POST['id']) ? '?edit=' . (int)$_POST['id'] : ''));
    }
}

$cariler = [];
try {
    $cariler = db()->query('SELECT id, name, city, address, tax_no, tax_office, phone, authorized_person FROM cariler ORDER BY name ASC')->fetchAll();
} catch (Throwable $e) { $cariler = []; }
$cariJson = json_encode(array_map(function ($cari) {
    return [
        'id' => (int)($cari['id'] ?? 0),
        'name' => (string)($cari['name'] ?? ''),
        'city' => (string)($cari['city'] ?? ''),
        'address' => (string)($cari['address'] ?? ''),
        'tax_no' => (string)($cari['tax_no'] ?? ''),
        'tax_office' => (string)($cari['tax_office'] ?? ''),
        'phone' => (string)($cari['phone'] ?? ''),
        'authorized_person' => (string)($cari['authorized_person'] ?? ''),
    ];
}, $cariler), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$productRows = teklif_products_for_select();
$productJson = json_encode(array_map(function ($p) {
    return [
        'barcode' => (string)($p['barcode'] ?? ''),
        'name' => (string)($p['name'] ?? ''),
        'product_type' => (string)($p['product_type'] ?? ''),
        'default_unit_price' => (float)($p['default_unit_price'] ?? 0),
    ];
}, $productRows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId > 0 ? teklif_load($editId) : null;
$list = teklifler_list(120);
$titleOptions = ['SİPARİŞ FİŞİ', 'TEKLİF FORMU', 'PROFORMA', 'PROFORMA FATURA', 'SİPARİŞ FORMU'];

function offer_field($offer, string $key, string $default = ''): string
{
    return e($offer[$key] ?? $default);
}
function offer_item_value(array $items, int $i, string $key, string $default = ''): string
{
    return e($items[$i][$key] ?? $default);
}

$items = $edit['items'] ?? [];
$minRows = max(8, count($items) + 2);
$pageTitleValue = (string)($edit['document_title'] ?? 'SİPARİŞ FİŞİ');
if ($pageTitleValue === '') $pageTitleValue = 'SİPARİŞ FİŞİ';
page_header('Teklif Ver', 'teklif_ver');
?>
<style>
.offer-builder{display:grid;gap:16px;max-width:1500px;margin:0 auto}.offer-hero{display:flex;justify-content:space-between;gap:16px;align-items:center;padding:22px 24px;border-radius:24px;background:linear-gradient(135deg,#102818,#23613c);color:#fff;box-shadow:0 18px 50px rgba(7,27,63,.10)}.offer-hero h2{margin:5px 0 6px;color:#fff;font-size:clamp(24px,3vw,38px);line-height:1}.offer-hero p{margin:0;color:#e9f5ed;max-width:760px}.offer-hero span{display:inline-flex;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.16);font-size:11px;font-weight:900;letter-spacing:.08em}.offer-card{background:#fff;border:1px solid #e5dccf;border-radius:22px;box-shadow:0 12px 34px rgba(7,27,63,.06);overflow:hidden}.offer-card header{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px 18px;background:#fbf6ed;border-bottom:1px solid #e5dccf}.offer-card h3{margin:0;color:#102818}.offer-body{padding:18px}.offer-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.offer-grid label{display:grid;gap:6px;font-size:12px;color:#102818;font-weight:850}.offer-grid input,.offer-grid select,.offer-grid textarea{min-height:42px;border:1px solid #e5dccf;border-radius:13px;padding:9px 11px;background:#fff;color:#102818;width:100%}.offer-grid small{color:#7b6c5a;font-weight:700}.offer-grid .wide{grid-column:span 2}.offer-grid .full{grid-column:1/-1}.offer-table-wrap{overflow:auto;border:1px solid #e5dccf;border-radius:18px}.offer-table{width:100%;min-width:1180px;border-collapse:collapse}.offer-table th{background:#16482e;color:#fff;text-align:left;padding:10px 9px;font-size:11px;text-transform:uppercase;letter-spacing:.03em}.offer-table td{border-bottom:1px solid #efe7dc;padding:8px}.offer-table input{width:100%;min-height:38px;border:1px solid #e5dccf;border-radius:11px;padding:7px 9px}.offer-table .right{text-align:right}.offer-totals{display:grid;justify-content:end;gap:5px;margin-top:12px;color:#102818}.offer-total-line{display:grid;grid-template-columns:160px 150px;gap:10px;text-align:right;align-items:center}.offer-total-line strong{font-size:22px}.offer-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.offer-actions button,.offer-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border:0;border-radius:999px;padding:9px 16px;font-weight:900;text-decoration:none;cursor:pointer}.offer-actions .primary{background:#16482e;color:#fff}.offer-actions .secondary{background:#efe6d9;color:#102818}.offer-actions .danger{background:#fff1ed;color:#b64242}.mini-help{margin-top:12px;padding:12px;border-radius:14px;background:#fbf6ed;color:#776b5c;font-weight:700}.row-remove{border:0!important;background:#fff1ed!important;color:#b64242!important;font-weight:900!important;cursor:pointer}.cari-selected-note{display:none;margin-top:4px;padding:8px 10px;border-radius:12px;background:#eef8f1;color:#16482e;font-size:12px;font-weight:850}.cari-selected-note.active{display:block}.vat-box{display:flex;align-items:center;gap:9px;min-height:42px;border:1px solid #e5dccf;border-radius:13px;padding:9px 11px;background:#fff}.vat-box input{width:auto!important;min-height:auto!important}.saved-offers{width:100%;border-collapse:collapse;min-width:900px}.saved-offers th{background:#16482e;color:#fff;text-align:left;padding:10px;font-size:11px}.saved-offers td{border-bottom:1px solid #efe7dc;padding:10px;vertical-align:top}.saved-offers small{display:block;color:#776b5c;margin-top:3px}.saved-actions{display:flex;gap:6px;flex-wrap:wrap}.saved-actions a,.saved-actions button{border:1px solid #e5dccf;background:#fff;border-radius:999px;padding:7px 10px;color:#102818;font-weight:850;text-decoration:none;cursor:pointer}.saved-actions button{color:#b64242}.pill{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:900;background:#eef8f1;color:#16482e}.pill.off{background:#f1f3f5;color:#667085}@media(max-width:1000px){.offer-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.offer-grid .wide{grid-column:span 2}}@media(max-width:640px){.offer-hero{display:block}.offer-grid{grid-template-columns:1fr}.offer-grid .wide{grid-column:1}.offer-card header{display:block}.offer-actions button,.offer-actions a{width:100%}.offer-total-line{grid-template-columns:1fr 1fr}.saved-offers{min-width:760px}}
</style>

<div class="offer-builder">
  <section class="offer-hero">
    <div>
      <span>DUMANLAR / BİTKE TEKLİF MODÜLÜ</span>
      <h2><?php echo $edit ? 'Teklifi düzenle.' : 'Teklif fişini kaydet, sonra PDF al.'; ?></h2>
      <p>Cari seçtiğinde firma adı, şehir, adres, vergi ve telefon bilgileri otomatik gelir; ürün satırlarında barkod, ürün adı ve ürün cinsi ayrı ayrı saklanır.</p>
    </div>
    <div class="offer-actions"><a class="secondary" href="teklif-ver.php">Yeni teklif</a></div>
  </section>

  <section class="offer-card">
    <header><div><h3><?php echo $edit ? 'Teklif düzenle' : 'Yeni teklif'; ?></h3><small><?php echo $edit ? 'Kayıt no #' . e($edit['id']) : 'Kaydedince alttaki listede görünür.'; ?></small></div><strong><?php echo $edit ? e($edit['offer_no']) : 'Yeni teklif'; ?></strong></header>
    <div class="offer-body">
      <?php if (can_write()): ?>
      <form method="post" id="offerForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo e($edit['id'] ?? 0); ?>">
        <div class="offer-grid">
          <label><span>Belge başlığı</span><select name="document_title" required><?php foreach($titleOptions as $titleOption): ?><option value="<?php echo e($titleOption); ?>" <?php echo $pageTitleValue===$titleOption?'selected':''; ?>><?php echo e($titleOption); ?></option><?php endforeach; ?><?php if(!in_array($pageTitleValue,$titleOptions,true)): ?><option value="<?php echo e($pageTitleValue); ?>" selected><?php echo e($pageTitleValue); ?></option><?php endif; ?></select></label>
          <label><span>Teklif / Sipariş no</span><input name="offer_no" value="<?php echo offer_field($edit, 'offer_no', $defaultNo); ?>" required></label>
          <label><span>Tarih</span><input type="date" name="offer_date" value="<?php echo offer_field($edit, 'offer_date', $today); ?>" required></label>
          <label><span>Para birimi</span><select name="currency"><?php foreach(['TL','USD','EUR'] as $cur): ?><option value="<?php echo e($cur); ?>" <?php echo (($edit['currency'] ?? 'TL')===$cur)?'selected':''; ?>><?php echo e($cur); ?></option><?php endforeach; ?></select></label>

          <label class="wide"><span>Carilerden firma seç</span><select id="cariSelect" name="cari_id"><option value="">Cari seçmeden elle yazacağım</option><?php foreach ($cariler as $cari): ?><option value="<?php echo e($cari['id']); ?>" <?php echo ((string)($edit['cari_id'] ?? '')===(string)$cari['id'])?'selected':''; ?>><?php echo e($cari['name']); ?><?php echo !empty($cari['city']) ? ' — ' . e($cari['city']) : ''; ?></option><?php endforeach; ?></select><small>Seçince müşteri bilgileri otomatik dolar.</small></label>
          <label class="wide"><span>Firma / Müşteri</span><input id="customerName" name="customer_name" value="<?php echo offer_field($edit, 'customer_name'); ?>" placeholder="EFEOĞLU TEKSTİL" required><small>İstersen cariden geldikten sonra elle değiştirebilirsin.</small></label>
          <label><span>Şehir</span><input id="customerCity" name="customer_city" value="<?php echo offer_field($edit, 'customer_city'); ?>" placeholder="BURSA"></label>
          <label><span>Telefon</span><input id="customerPhone" name="customer_phone" value="<?php echo offer_field($edit, 'customer_phone'); ?>" placeholder="0 (___) ___ __ __"></label>
          <label><span>Vergi dairesi</span><input id="customerTaxOffice" name="customer_tax_office" value="<?php echo offer_field($edit, 'customer_tax_office'); ?>" placeholder="Vergi dairesi"></label>
          <label><span>Vergi no / T.C.</span><input id="customerTaxNo" name="customer_tax_no" value="<?php echo offer_field($edit, 'customer_tax_no'); ?>" placeholder="Vergi numarası"></label>
          <label class="wide"><span>Miktar başlığı</span><input name="quantity_label" value="<?php echo offer_field($edit, 'quantity_label', 'DZ'); ?>"></label>
          <label><span>KDV uygulansın mı?</span><div class="vat-box"><input type="checkbox" id="vatEnabled" name="vat_enabled" value="1" <?php echo ((int)($edit['vat_enabled'] ?? 0)===1)?'checked':''; ?>> <strong>Evet, KDV ekle</strong></div></label>
          <label><span>KDV oranı</span><input id="vatRate" name="vat_rate" value="<?php echo e((string)($edit['vat_rate'] ?? '10')); ?>" inputmode="decimal"><small>Varsayılan %10</small></label>
          <label class="full"><span>Adres</span><textarea id="customerAddress" name="customer_address" rows="2" placeholder="Müşteri adresi"><?php echo e($edit['customer_address'] ?? ''); ?></textarea></label>
          <div class="full cari-selected-note" id="cariSelectedNote"></div>
          <label class="full"><span>Açıklama / alt not</span><textarea name="note" rows="2" placeholder="MODAL çorap kutusu 6080 kutusu gibi olacak."><?php echo e($edit['note'] ?? ''); ?></textarea></label>
          <label class="wide"><span>Alt slogan</span><input name="footer_text" value="<?php echo offer_field($edit, 'footer_text', 'MALIMIZDAN HAYIR GÖRÜN.'); ?>"></label>
          <label class="wide"><span>Teklif notu</span><input name="term_text" value="<?php echo offer_field($edit, 'term_text'); ?>" placeholder="Fiyatlara KDV dahil değildir / Teslim süresi ..."></label>
        </div>

        <datalist id="productOptions">
          <?php foreach ($productRows as $p): ?><option value="<?php echo e($p['name']); ?>"></option><?php endforeach; ?>
        </datalist>
        <div style="height:16px"></div>
        <div class="offer-table-wrap">
          <table class="offer-table" id="offerRows">
            <thead><tr><th style="width:14%">Barkod</th><th style="width:26%">Ürün adı</th><th style="width:24%">Ürün cinsi / açıklama</th><th style="width:10%">Miktar</th><th style="width:12%">Birim fiyat</th><th style="width:10%">Tutar</th><th style="width:4%"></th></tr></thead>
            <tbody>
              <?php for ($i=0; $i<$minRows; $i++): ?>
              <tr>
                <td><input name="product_barcode[]" class="product-barcode" value="<?php echo offer_item_value($items, $i, 'product_barcode'); ?>" placeholder="Barkod"></td>
                <td><input name="product_name[]" list="productOptions" class="product-name" value="<?php echo offer_item_value($items, $i, 'product_name'); ?>" placeholder="Ürün adı"></td>
                <td><input name="product_type[]" class="product-type" value="<?php echo offer_item_value($items, $i, 'product_type'); ?>" placeholder="Ürün cinsi / açıklama"></td>
                <td><input name="quantity[]" class="calc qty" inputmode="decimal" value="<?php echo isset($items[$i]) && (float)$items[$i]['quantity'] > 0 ? e((string)$items[$i]['quantity']) : ''; ?>" placeholder="0"></td>
                <td><input name="unit_price[]" class="calc price" inputmode="decimal" value="<?php echo isset($items[$i]) && (float)$items[$i]['unit_price'] > 0 ? e((string)$items[$i]['unit_price']) : ''; ?>" placeholder="0,00"></td>
                <td class="right"><strong class="line-total">0,00</strong></td>
                <td><button class="row-remove" type="button">×</button></td>
              </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>
        <div class="offer-totals">
          <div class="offer-total-line"><span>Ara toplam:</span><strong id="subtotalTotal">0,00</strong></div>
          <div class="offer-total-line"><span>KDV:</span><strong id="vatTotal">0,00</strong></div>
          <div class="offer-total-line"><span>Genel toplam:</span><strong id="grandTotal">0,00</strong></div>
        </div>
        <div class="offer-actions">
          <button class="secondary" type="button" id="addRow">Satır ekle</button>
          <button class="primary" type="submit"><?php echo $edit ? 'Teklifi Güncelle' : 'Teklifi Kaydet'; ?></button>
          <?php if($edit): ?><a class="secondary" target="_blank" href="teklif-yazdir.php?id=<?php echo e($edit['id']); ?>">PDF / Yazdır</a><?php endif; ?>
        </div>
        <p class="mini-help">Ürün satırları artık Barkod, Ürün Adı ve Ürün Cinsi/Açıklama olarak ayrı ayrı kaydedilir. PDF ve cari satış detayında da aynı sıra kullanılır.</p>
      </form>
      <?php else: ?>
        <p class="muted">Görüntüleme yetkisindesiniz. Teklif oluşturma kapalı.</p>
      <?php endif; ?>
    </div>
  </section>

  <section class="offer-card">
    <header><div><h3>Kayıtlı teklifler</h3><small>Düzenle, sil veya sonradan PDF al.</small></div><strong><?php echo e(count($list)); ?> teklif</strong></header>
    <div class="offer-body">
      <div class="offer-table-wrap">
        <table class="saved-offers">
          <thead><tr><th>Tarih / No</th><th>Firma</th><th>Tutar</th><th>KDV</th><th>İşlem</th></tr></thead>
          <tbody>
            <?php if(!$list): ?><tr><td colspan="5" class="empty">Kayıtlı teklif yok.</td></tr><?php endif; ?>
            <?php foreach($list as $offer): ?>
            <tr>
              <td><strong><?php echo e(tr_date($offer['offer_date'])); ?></strong><small><?php echo e($offer['offer_no']); ?></small></td>
              <td><strong><?php echo e($offer['customer_name']); ?></strong><small><?php echo e($offer['customer_city'] ?: '-'); ?><?php echo !empty($offer['customer_phone']) ? ' · Tel: ' . e($offer['customer_phone']) : ''; ?></small></td>
              <td><strong><?php echo e(teklif_money((float)$offer['grand_total']) . ' ' . $offer['currency']); ?></strong><small>Ara toplam: <?php echo e(teklif_money((float)$offer['subtotal'])); ?></small></td>
              <td><?php echo ((int)$offer['vat_enabled']===1) ? '<span class="pill">%'.e((string)$offer['vat_rate']).' KDV</span><small>'.e(teklif_money((float)$offer['vat_amount'])).'</small>' : '<span class="pill off">KDV yok</span>'; ?></td>
              <td><div class="saved-actions"><a href="teklif-ver.php?edit=<?php echo e($offer['id']); ?>">Düzenle</a><a target="_blank" href="teklif-yazdir.php?id=<?php echo e($offer['id']); ?>">PDF</a><?php if(can_write()): ?><form method="post" onsubmit="return confirm('Bu teklif silinsin mi?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e($offer['id']); ?>"><button type="submit">Sil</button></form><?php endif; ?></div></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</div>

<script>
(function(){
  const cariler = <?php echo $cariJson ?: '[]'; ?>;
  const products = <?php echo $productJson ?: '[]'; ?>;
  const cariSelect = document.getElementById('cariSelect');
  const customerName = document.getElementById('customerName');
  const customerCity = document.getElementById('customerCity');
  const customerAddress = document.getElementById('customerAddress');
  const customerTaxOffice = document.getElementById('customerTaxOffice');
  const customerTaxNo = document.getElementById('customerTaxNo');
  const customerPhone = document.getElementById('customerPhone');
  const note = document.getElementById('cariSelectedNote');

  cariSelect?.addEventListener('change', () => {
    const id = Number(cariSelect.value || 0);
    const cari = cariler.find(item => Number(item.id) === id);
    if (!cari) { if (note) { note.classList.remove('active'); note.textContent = ''; } return; }
    if (customerName) customerName.value = cari.name || '';
    if (customerCity) customerCity.value = cari.city || '';
    if (customerAddress) customerAddress.value = cari.address || '';
    if (customerTaxOffice) customerTaxOffice.value = cari.tax_office || '';
    if (customerTaxNo) customerTaxNo.value = cari.tax_no || '';
    if (customerPhone) customerPhone.value = cari.phone || '';
    if (note) {
      const details = [];
      if (cari.authorized_person) details.push('Yetkili: ' + cari.authorized_person);
      if (cari.phone) details.push('Telefon: ' + cari.phone);
      if (cari.tax_office || cari.tax_no) details.push('Vergi: ' + [cari.tax_office, cari.tax_no].filter(Boolean).join(' / '));
      if (cari.address) details.push('Adres aktarıldı');
      note.textContent = details.length ? details.join(' · ') : 'Cari bilgisi forma aktarıldı.';
      note.classList.add('active');
    }
  });

  const table = document.getElementById('offerRows');
  if (!table) return;
  const tbody = table.querySelector('tbody');
  const subtotalEl = document.getElementById('subtotalTotal');
  const vatEl = document.getElementById('vatTotal');
  const grand = document.getElementById('grandTotal');
  const add = document.getElementById('addRow');
  const vatEnabled = document.getElementById('vatEnabled');
  const vatRate = document.getElementById('vatRate');
  const fmt = new Intl.NumberFormat('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2});
  function num(v){ v = String(v || '').replace(/\s/g,'').replace(/\./g,'').replace(',', '.'); const n = parseFloat(v); return Number.isFinite(n) ? n : 0; }
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
    const rate = vatEnabled?.checked ? num(vatRate?.value || '10') : 0;
    const vat = sum * rate / 100;
    if (subtotalEl) subtotalEl.textContent = fmt.format(sum);
    if (vatEl) vatEl.textContent = fmt.format(vat);
    if (grand) grand.textContent = fmt.format(sum + vat);
  }
  function applyProduct(row){
    const nameInput = row.querySelector('.product-name');
    const name = (nameInput?.value || '').trim();
    const p = products.find(item => item.name === name);
    if (!p) return;
    const barcode = row.querySelector('.product-barcode');
    const type = row.querySelector('.product-type');
    const price = row.querySelector('.price');
    if (barcode && !barcode.value && p.barcode) barcode.value = p.barcode;
    if (type && !type.value && p.product_type) type.value = p.product_type;
    if (price && !price.value && Number(p.default_unit_price || 0) > 0) price.value = String(p.default_unit_price).replace('.', ',');
    recalc();
  }
  tbody.addEventListener('input', e => { if (e.target.classList.contains('calc')) recalc(); });
  tbody.addEventListener('change', e => { if (e.target.classList.contains('product-name')) applyProduct(e.target.closest('tr')); });
  vatEnabled?.addEventListener('change', recalc);
  vatRate?.addEventListener('input', recalc);
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
