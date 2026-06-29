<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/tahsilat-db.php';
require_login();
tahsilat_db_ensure();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write();
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id = tahsilat_save_from_post((int)($_POST['id'] ?? 0));
            flash('success', 'Tahsilat makbuzu kaydedildi. PDF/Yazdır butonundan çıktısını alabilirsin.');
            redirect('tahsilat-makbuzu.php?edit=' . $id);
        }
        if ($action === 'delete') {
            if (tahsilat_delete((int)($_POST['id'] ?? 0))) flash('success', 'Tahsilat makbuzu silindi.');
            else flash('error', 'Silinecek makbuz bulunamadı.');
            redirect('tahsilat-makbuzu.php');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('tahsilat-makbuzu.php' . (!empty($_POST['id']) ? '?edit=' . (int)$_POST['id'] : ''));
    }
}

$cariler = [];
try {
    $cariler = db()->query('SELECT id, name, city, address, tax_no, tax_office, phone, authorized_person FROM cariler ORDER BY name ASC')->fetchAll();
} catch (Throwable $e) {}
$cariJson = json_encode(array_map(function ($c) {
    return [
        'id' => (int)($c['id'] ?? 0),
        'name' => (string)($c['name'] ?? ''),
        'city' => (string)($c['city'] ?? ''),
        'address' => (string)($c['address'] ?? ''),
        'tax_no' => (string)($c['tax_no'] ?? ''),
        'tax_office' => (string)($c['tax_office'] ?? ''),
        'phone' => (string)($c['phone'] ?? ''),
        'authorized_person' => (string)($c['authorized_person'] ?? ''),
    ];
}, $cariler), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$bankAccounts = [];
try {
    $stmt = db()->query("SELECT id, account_type, name, bank_name, iban FROM accounts WHERE COALESCE(is_active,1)=1 ORDER BY account_type DESC, name ASC");
    $seenBanks = [];
    foreach ($stmt->fetchAll() as $acc) {
        $type = (string)($acc['account_type'] ?? '');
        $name = trim((string)($acc['name'] ?? ''));
        $bank = trim((string)($acc['bank_name'] ?? ''));
        $iban = trim((string)($acc['iban'] ?? ''));
        if ($type !== 'banka' && $bank === '') continue;
        $value = $bank !== '' ? $bank : $name;
        if ($value === '') continue;
        $key = mb_strtolower($value);
        if (isset($seenBanks[$key])) continue;
        $seenBanks[$key] = true;
        $detail = $name;
        if ($bank !== '' && $name !== '' && strcasecmp($bank, $name) !== 0) $detail = $bank . ' - ' . $name;
        if ($iban !== '') $detail .= ' / ' . $iban;
        $bankAccounts[] = ['name' => $value, 'detail' => trim($detail)];
    }
} catch (Throwable $e) {}

$editId = (int)($_GET['edit'] ?? 0);
$edit = $editId > 0 ? tahsilat_load($editId) : null;
$list = tahsilatlar_list(150);
$paymentTypes = ['nakit'=>'Nakit', 'cek'=>'Çek', 'senet'=>'Senet', 'havale_eft'=>'Havale / EFT', 'kredi_karti'=>'Kredi Kartı'];

function tahsilat_field($row, string $key, string $default = ''): string
{
    return e($row[$key] ?? $default);
}

page_header('Tahsilat Makbuzu', 'tahsilat_makbuzu');
?>
<style>
.receipt-wrap{display:grid;gap:16px;max-width:1500px;margin:0 auto}.receipt-hero{display:flex;justify-content:space-between;gap:16px;align-items:center;padding:22px 24px;border-radius:24px;background:linear-gradient(135deg,#061a33,#16482e);color:#fff;box-shadow:0 18px 50px rgba(7,27,63,.10)}.receipt-hero h2{margin:5px 0 6px;color:#fff;font-size:clamp(24px,3vw,38px);line-height:1}.receipt-hero p{margin:0;color:#e9f5ed;max-width:760px}.receipt-hero span{display:inline-flex;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.16);font-size:11px;font-weight:900;letter-spacing:.08em}.receipt-card{background:#fff;border:1px solid #e5dccf;border-radius:22px;box-shadow:0 12px 34px rgba(7,27,63,.06);overflow:hidden}.receipt-card header{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px 18px;background:#fbf6ed;border-bottom:1px solid #e5dccf}.receipt-card h3{margin:0;color:#102818}.receipt-body{padding:18px}.receipt-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.receipt-grid label{display:grid;gap:6px;font-size:12px;color:#102818;font-weight:850}.receipt-grid input,.receipt-grid select,.receipt-grid textarea{min-height:42px;border:1px solid #e5dccf;border-radius:13px;padding:9px 11px;background:#fff;color:#102818;width:100%}.receipt-grid .wide{grid-column:span 2}.receipt-grid .full{grid-column:1/-1}.receipt-grid small{color:#7b6c5a;font-weight:700}.payment-extra{display:none}.payment-extra.active{display:grid}.receipt-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.receipt-actions button,.receipt-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border:0;border-radius:999px;padding:9px 16px;font-weight:900;text-decoration:none;cursor:pointer}.receipt-actions .primary{background:#16482e;color:#fff}.receipt-actions .secondary{background:#efe6d9;color:#102818}.receipt-actions .danger{background:#fff1ed;color:#b64242}.mini-help{margin-top:12px;padding:12px;border-radius:14px;background:#fbf6ed;color:#776b5c;font-weight:700}.cari-note{display:none;margin-top:4px;padding:8px 10px;border-radius:12px;background:#eef8f1;color:#16482e;font-size:12px;font-weight:850}.cari-note.active{display:block}.receipt-table-wrap{overflow:auto;border:1px solid #e5dccf;border-radius:18px}.receipt-table{width:100%;border-collapse:collapse;min-width:960px}.receipt-table th{background:#16482e;color:#fff;text-align:left;padding:10px;font-size:11px;text-transform:uppercase;letter-spacing:.03em}.receipt-table td{border-bottom:1px solid #efe7dc;padding:10px;vertical-align:top}.receipt-table small{display:block;color:#776b5c;margin-top:3px}.saved-actions{display:flex;gap:6px;flex-wrap:wrap}.saved-actions a,.saved-actions button{border:1px solid #e5dccf;background:#fff;border-radius:999px;padding:7px 10px;color:#102818;font-weight:850;text-decoration:none;cursor:pointer}.saved-actions button{color:#b64242}.saved-actions .whatsapp-receipt-link{color:#061a33;background:#dcfce7;border-color:#25d366}.saved-actions button:disabled{opacity:.65;cursor:wait}.pill{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:900;background:#eef8f1;color:#16482e}@media(max-width:1000px){.receipt-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.receipt-grid .wide{grid-column:span 2}}@media(max-width:640px){.receipt-hero{display:block}.receipt-grid{grid-template-columns:1fr}.receipt-grid .wide{grid-column:1}.receipt-actions button,.receipt-actions a{width:100%}.receipt-table{min-width:760px}}
</style>

<div class="receipt-wrap">
  <section class="receipt-hero">
    <div>
      <span>DUMANLAR / TAHSİLAT MODÜLÜ</span>
      <h2><?php echo $edit ? 'Makbuzu düzenle.' : 'Tahsilat makbuzu oluştur.'; ?></h2>
      <p>Cari seç, ödeme türünü belirle, tutarı yaz ve PDF/Yazdır ile profesyonel makbuz çıktısı al.</p>
    </div>
    <div class="receipt-actions"><a class="secondary" href="tahsilat-makbuzu.php">Yeni makbuz</a></div>
  </section>

  <section class="receipt-card">
    <header><div><h3><?php echo $edit ? 'Makbuz düzenle' : 'Yeni tahsilat makbuzu'; ?></h3><small><?php echo $edit ? 'Kayıt no #' . e($edit['id']) : 'Kaydedince alttaki listede görünür.'; ?></small></div><strong><?php echo $edit ? e($edit['receipt_no']) : 'Yeni kayıt'; ?></strong></header>
    <div class="receipt-body">
      <?php if (can_write()): ?>
      <form method="post" id="receiptForm" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo e($edit['id'] ?? 0); ?>">
        <div class="receipt-grid">
          <label><span>Makbuz no</span><input name="receipt_no" value="<?php echo tahsilat_field($edit, 'receipt_no', tahsilat_next_no()); ?>" required></label>
          <label><span>Tarih</span><input type="date" name="receipt_date" value="<?php echo tahsilat_field($edit, 'receipt_date', date('Y-m-d')); ?>" required></label>
          <label><span>Para birimi</span><select name="currency" id="currency"><?php foreach(['TL','USD','EUR'] as $cur): ?><option value="<?php echo e($cur); ?>" <?php echo (($edit['currency'] ?? 'TL')===$cur)?'selected':''; ?>><?php echo e($cur); ?></option><?php endforeach; ?></select></label>
          <label><span>Tahsilat türü</span><select name="payment_type" id="paymentType"><?php foreach($paymentTypes as $key=>$label): ?><option value="<?php echo e($key); ?>" <?php echo (($edit['payment_type'] ?? 'nakit')===$key)?'selected':''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></label>

          <label class="wide"><span>Carilerden firma seç</span><select id="cariSelect" name="cari_id"><option value="">Cari seçmeden elle yazacağım</option><?php foreach($cariler as $cari): ?><option value="<?php echo e($cari['id']); ?>" <?php echo ((string)($edit['cari_id'] ?? '')===(string)$cari['id'])?'selected':''; ?>><?php echo e($cari['name']); ?><?php echo !empty($cari['city']) ? ' — ' . e($cari['city']) : ''; ?></option><?php endforeach; ?></select><small>Cari seçince firma bilgileri otomatik dolar.</small></label>
          <label class="wide"><span>Firma / Müşteri</span><input id="customerName" name="customer_name" value="<?php echo tahsilat_field($edit, 'customer_name'); ?>" required></label>
          <label><span>Şehir</span><input id="customerCity" name="customer_city" value="<?php echo tahsilat_field($edit, 'customer_city'); ?>"></label>
          <label><span>Telefon</span><input id="customerPhone" name="customer_phone" value="<?php echo tahsilat_field($edit, 'customer_phone'); ?>"></label>
          <label><span>Vergi dairesi</span><input id="customerTaxOffice" name="customer_tax_office" value="<?php echo tahsilat_field($edit, 'customer_tax_office'); ?>"></label>
          <label><span>Vergi no / T.C.</span><input id="customerTaxNo" name="customer_tax_no" value="<?php echo tahsilat_field($edit, 'customer_tax_no'); ?>"></label>
          <label class="wide"><span>Tutar</span><input id="amount" name="amount" inputmode="decimal" value="<?php echo isset($edit['amount']) ? e((string)$edit['amount']) : ''; ?>" placeholder="25.000,00" required></label>
          <label class="wide"><span>Tutar yazıyla</span><input name="amount_text" value="<?php echo tahsilat_field($edit, 'amount_text'); ?>" placeholder="Boş bırakırsan sistem otomatik doldurur"></label>
          <label class="full"><span>Adres</span><textarea id="customerAddress" name="customer_address" rows="2"><?php echo e($edit['customer_address'] ?? ''); ?></textarea></label>
          <div class="full cari-note" id="cariNote"></div>

          <label class="payment-extra extra-cek extra-senet extra-havale_eft wide"><span>Banka adı</span><select name="bank_name"><option value="">Banka seçiniz</option><?php $currentBank = (string)($edit['bank_name'] ?? ''); $bankFound=false; foreach($bankAccounts as $bank): $selected = $currentBank !== '' && strcasecmp($currentBank, $bank['name']) === 0; if($selected) $bankFound=true; ?><option value="<?php echo e($bank['name']); ?>" <?php echo $selected?'selected':''; ?>><?php echo e($bank['name']); ?><?php echo !empty($bank['detail']) && $bank['detail'] !== $bank['name'] ? ' — ' . e($bank['detail']) : ''; ?></option><?php endforeach; ?><?php if($currentBank !== '' && !$bankFound): ?><option value="<?php echo e($currentBank); ?>" selected><?php echo e($currentBank); ?></option><?php endif; ?><?php if(!$bankAccounts): ?><option value="">Kasa/Banka bölümünde aktif banka yok</option><?php endif; ?></select><small>Havale/EFT için bizim banka hesabımızı, çek için çek bankasını seç.</small></label>
          <label class="payment-extra extra-cek extra-senet extra-havale_eft extra-kredi_karti"><span>Belge / İşlem no</span><input name="document_no" value="<?php echo tahsilat_field($edit, 'document_no'); ?>" placeholder="Çek no / Senet no / Dekont no"></label>
          <label class="payment-extra extra-cek extra-senet"><span>Vade tarihi</span><input type="date" name="due_date" value="<?php echo tahsilat_field($edit, 'due_date'); ?>"></label>
          <label class="payment-extra extra-cek extra-senet"><span>Keşideci / Borçlu</span><input name="debtor_name" value="<?php echo tahsilat_field($edit, 'debtor_name'); ?>"></label>
          <label class="payment-extra extra-cek extra-senet wide"><span>Çek/Senet görseli</span><input name="check_document" type="file" accept="image/*,application/pdf"><?php if(!empty($edit['check_document_name'])): ?><small>Mevcut görsel: <?php echo e($edit['check_document_name']); ?>. Yeni dosya seçersen değişir.</small><?php else: ?><small>Çek veya senet görselini buradan ekleyebilirsin.</small><?php endif; ?></label>

          <label class="full"><span>Açıklama</span><textarea name="description" rows="2" placeholder="Cari hesabına mahsuben tahsil edilmiştir."><?php echo e($edit['description'] ?? 'Cari hesabına mahsuben tahsil edilmiştir.'); ?></textarea></label>
          <label class="wide"><span>Tahsil eden</span><input name="collected_by" value="<?php echo tahsilat_field($edit, 'collected_by', 'Dumanlar A.Ş.'); ?>"></label>
          <label class="wide"><span>Ödeme yapan</span><input name="paid_by" value="<?php echo tahsilat_field($edit, 'paid_by'); ?>" placeholder="Firma yetkilisi"></label>
        </div>
        <div class="receipt-actions">
          <button class="primary" type="submit"><?php echo $edit ? 'Makbuzu Güncelle' : 'Makbuzu Kaydet'; ?></button>
          <?php if($edit): ?><a class="secondary" target="_blank" href="tahsilat-yazdir.php?id=<?php echo e($edit['id']); ?>">PDF / Yazdır</a><?php endif; ?>
        </div>
        <p class="mini-help">Nakit, çek, senet, havale/EFT ve kredi kartı seçenekleri hazır. Çek/senet seçince vade ve görsel alanları görünür.</p>
      </form>
      <?php else: ?>
        <p class="muted">Görüntüleme yetkisindesiniz. Makbuz oluşturma kapalı.</p>
      <?php endif; ?>
    </div>
  </section>

  <section class="receipt-card">
    <header><div><h3>Kayıtlı tahsilat makbuzları</h3><small>Düzenle, sil veya PDF al.</small></div><strong><?php echo e(count($list)); ?> makbuz</strong></header>
    <div class="receipt-body">
      <div class="receipt-table-wrap">
        <table class="receipt-table">
          <thead><tr><th>Tarih / No</th><th>Firma</th><th>Tahsilat</th><th>Açıklama</th><th>İşlem</th></tr></thead>
          <tbody>
            <?php if(!$list): ?><tr><td colspan="5">Kayıtlı tahsilat makbuzu yok.</td></tr><?php endif; ?>
            <?php foreach($list as $r): ?>
              <tr>
                <td><strong><?php echo e(tahsilat_tr_date($r['receipt_date'])); ?></strong><small><?php echo e($r['receipt_no']); ?></small></td>
                <td><strong><?php echo e($r['customer_name']); ?></strong><small><?php echo e($r['customer_city'] ?: '-'); ?></small></td>
                <td><strong><?php echo e(tahsilat_money((float)$r['amount']) . ' ' . $r['currency']); ?></strong><small><span class="pill"><?php echo e(tahsilat_payment_label((string)$r['payment_type'])); ?></span></small></td>
                <td><?php echo e($r['description'] ?: '-'); ?><?php if(!empty($r['due_date'])): ?><small>Vade: <?php echo e(tahsilat_tr_date($r['due_date'])); ?></small><?php endif; ?><?php if(!empty($r['check_record_id'])): ?><small>Çek/Senet kaydı: #<?php echo e($r['check_record_id']); ?></small><?php endif; ?></td>
                <td><div class="saved-actions"><a href="tahsilat-makbuzu.php?edit=<?php echo e($r['id']); ?>">Düzenle</a><a target="_blank" href="tahsilat-yazdir.php?id=<?php echo e($r['id']); ?>">PDF</a><button type="button" class="whatsapp-receipt-link" data-url="tahsilat-yazdir.php?id=<?php echo e($r['id']); ?>" data-no="<?php echo e($r['receipt_no']); ?>">WhatsApp</button><?php if(can_write()): ?><form method="post" onsubmit="return confirm('Bu makbuz silinsin mi?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>"><button type="submit">Sil</button></form><?php endif; ?></div></td>
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
  const cariSelect = document.getElementById('cariSelect');
  const paymentType = document.getElementById('paymentType');
  const note = document.getElementById('cariNote');
  const map = {
    customerName: document.getElementById('customerName'),
    customerCity: document.getElementById('customerCity'),
    customerAddress: document.getElementById('customerAddress'),
    customerTaxOffice: document.getElementById('customerTaxOffice'),
    customerTaxNo: document.getElementById('customerTaxNo'),
    customerPhone: document.getElementById('customerPhone')
  };
  cariSelect?.addEventListener('change', () => {
    const id = Number(cariSelect.value || 0);
    const cari = cariler.find(item => Number(item.id) === id);
    if (!cari) { if(note){note.classList.remove('active');note.textContent='';} return; }
    map.customerName.value = cari.name || '';
    map.customerCity.value = cari.city || '';
    map.customerAddress.value = cari.address || '';
    map.customerTaxOffice.value = cari.tax_office || '';
    map.customerTaxNo.value = cari.tax_no || '';
    map.customerPhone.value = cari.phone || '';
    const details = [];
    if (cari.phone) details.push('Telefon: ' + cari.phone);
    if (cari.tax_office || cari.tax_no) details.push('Vergi: ' + [cari.tax_office, cari.tax_no].filter(Boolean).join(' / '));
    if (cari.address) details.push('Adres aktarıldı');
    if(note){note.textContent = details.length ? details.join(' · ') : 'Cari bilgisi forma aktarıldı.';note.classList.add('active');}
  });
  function syncPayment(){
    const val = paymentType?.value || 'nakit';
    document.querySelectorAll('.payment-extra').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.extra-' + val).forEach(el => el.classList.add('active'));
  }
  paymentType?.addEventListener('change', syncPayment);
  syncPayment();

  function loadHtml2Canvas(){
    return new Promise((resolve, reject) => {
      if (window.html2canvas) return resolve(window.html2canvas);
      const s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js';
      s.onload = () => resolve(window.html2canvas);
      s.onerror = () => reject(new Error('html2canvas yüklenemedi'));
      document.head.appendChild(s);
    });
  }
  function slug(text){
    return String(text || 'belge').toLowerCase().replace(/[^a-z0-9ğüşöçıİĞÜŞÖÇ]+/gi,'-').replace(/^-+|-+$/g,'').slice(0,60) || 'belge';
  }
  async function renderReceiptFromUrl(url, receiptNo){
    const html2canvas = await loadHtml2Canvas();
    const iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.left = '-9999px';
    iframe.style.top = '0';
    iframe.style.width = '220mm';
    iframe.style.height = '310mm';
    iframe.style.opacity = '0';
    iframe.src = url;
    document.body.appendChild(iframe);
    await new Promise((resolve, reject) => {
      iframe.onload = resolve;
      iframe.onerror = reject;
      setTimeout(resolve, 3500);
    });
    const page = iframe.contentDocument?.querySelector('.page');
    if (!page) {
      iframe.remove();
      throw new Error('Makbuz sayfası bulunamadı');
    }
    const canvas = await html2canvas(page, {scale:2.2, backgroundColor:'#ffffff', useCORS:true, allowTaint:true, logging:false});
    iframe.remove();
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.96));
    if (!blob) throw new Error('JPEG oluşturulamadı');
    const fileName = 'tahsilat-makbuzu-' + slug(receiptNo) + '.jpg';
    return {file:new File([blob], fileName, {type:'image/jpeg'}), blob, fileName};
  }
  async function shareReceipt(button){
    const oldText = button.textContent;
    button.disabled = true;
    button.textContent = 'Hazırlanıyor...';
    try {
      const {file, blob, fileName} = await renderReceiptFromUrl(button.dataset.url, button.dataset.no || 'makbuz');
      const text = 'Tahsilat makbuzu ektedir.';
      if (navigator.canShare && navigator.canShare({files:[file]}) && navigator.share) {
        await navigator.share({files:[file], title:'Tahsilat Makbuzu', text});
      } else {
        const downloadUrl = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = downloadUrl;
        a.download = fileName;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(downloadUrl), 3000);
        window.open('https://wa.me/?text=' + encodeURIComponent('Tahsilat makbuzu JPEG olarak indirildi. İndirilen görseli WhatsApp üzerinden ekleyebilirsin.'), '_blank');
      }
    } catch (err) {
      alert('Makbuz JPEG formatına çevrilemedi. Lütfen PDF ekranından tekrar deneyin.');
    } finally {
      button.disabled = false;
      button.textContent = oldText;
    }
  }
  document.addEventListener('click', function(e){
    const btn = e.target.closest('.whatsapp-receipt-link');
    if (!btn) return;
    shareReceipt(btn);
  });
})();
</script>
<?php page_footer(); ?>
