<?php
require_once __DIR__.'/layout.php';
require_once __DIR__.'/depo-cikis-paylas-lib.php';
require_login();
if(!can_access_warehouse_dispatch()){flash('error','Depo çıkış bölümüne erişim yetkiniz yok.');redirect('dashboard.php');}
depo_cikis_db_ensure();
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf(); $action=(string)($_POST['action']??''); $id=(int)($_POST['id']??0);
    try{
        if($action==='save'){$id=depo_cikis_save($id);flash('success','Depo çıkış fişi kaydedildi.');redirect('depo-cikis.php?edit='.$id);}
        if($action==='processed'){depo_cikis_mark_processed($id);flash('success','Fiş işlendi olarak işaretlendi.');}
        if($action==='post_cari'){$mid=depo_cikis_post_to_cari($id);flash('success','Fiş cariye işlendi ve işlendi olarak işaretlendi. Hareket #'.$mid);}
    }catch(Throwable $e){flash('error',$e->getMessage());}
    redirect('depo-cikis.php'.($id>0?'?edit='.$id:''));
}
$editId=(int)($_GET['edit']??0);$edit=$editId?depo_cikis_load($editId):null;
if($edit && !depo_cikis_can_edit($edit) && is_warehouse_dispatch_operator()){$edit=null;flash('error','Bu fişi düzenleme yetkiniz yok.');}
$cariHasCity=depo_cikis_table_has_column(db(),'cariler','city');
$cariler=db()->query('SELECT id,name,'.($cariHasCity?'city':'NULL AS city').',address FROM cariler ORDER BY name')->fetchAll();
$productRows=teklif_products_for_select();
$productJson=json_encode(array_map(function($p){return [
    'barcode'=>(string)($p['barcode']??''),
    'name'=>(string)($p['name']??''),
    'product_type'=>(string)($p['product_type']??''),
    'list_unit_price'=>isset($p['list_unit_price']) ? (float)$p['list_unit_price'] : null,
];},$productRows),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
$listSql='SELECT w.*,u.display_name AS creator_name FROM warehouse_dispatches w LEFT JOIN users u ON u.id=w.created_by';$params=[];
$warehouseHasCancelled=depo_cikis_table_has_column(db(),'warehouse_dispatches','is_cancelled');
$listWhere=[$warehouseHasCancelled?'COALESCE(w.is_cancelled,0)=0':'1=1'];
$listSql.=' WHERE '.implode(' AND ',$listWhere).' ORDER BY w.dispatch_date DESC,w.id DESC';
$s=db()->prepare($listSql);$s->execute($params);$list=$s->fetchAll();$items=$edit['items']??[];$rows=max(6,count($items)+2);
$cariJson=json_encode($cariler,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
page_header('Depo Çıkış','depo_cikis');
?>
<style>
.wd{display:grid;gap:16px;max-width:1500px;margin:auto}.wd-hero{padding:20px 22px;border-radius:22px;background:linear-gradient(135deg,#102818,#23613c);color:#fff}.wd-hero h2{margin:4px 0}.wd-hero p{margin:0;color:#e4f0e8}.wd-card{padding:18px;border:1px solid #e5dccf;border-radius:20px;background:#fff}.wd-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px}.wd-grid label{display:grid;gap:5px;font-size:12px;font-weight:850}.wd-grid .wide{grid-column:span 2}.wd-grid input,.wd-grid select,.wd-grid textarea{width:100%;min-height:42px;padding:9px;border:1px solid #ddd2c1;border-radius:11px}.wd-check{display:flex!important;align-items:center;gap:9px;min-height:42px;padding:9px 11px;border:1px solid #ddd2c1;border-radius:11px;background:#fff}.wd-check input{width:auto!important;min-height:auto!important}.wd-table{overflow:auto;margin-top:15px;border:1px solid #e5dccf;border-radius:14px}.wd table{width:100%;border-collapse:collapse;min-width:980px;table-layout:auto}.wd th{padding:10px;background:#16482e;color:#fff;text-align:left;font-size:11px}.wd td{padding:8px;border-bottom:1px solid #eee5d9;vertical-align:top}.wd-table input{width:100%;min-height:38px;padding:7px;border:1px solid #ddd2c1;border-radius:9px}.wd-col-name{min-width:280px}.wd-col-desc{width:190px;min-width:190px;max-width:190px}.wd-col-qty{width:110px;min-width:110px}.wd-col-price{width:130px;min-width:130px}.wd-col-total{width:120px;min-width:120px}.wd-col-remove{width:42px;min-width:42px;text-align:center}.wd-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:14px}.wd-actions a,.wd-actions button{display:inline-flex;align-items:center;border:1px solid #d9cfbf;border-radius:999px;padding:8px 12px;background:#fff;color:#173f29;font-weight:850;text-decoration:none;cursor:pointer}.wd-actions .primary{background:#16482e;color:#fff}.wd-actions form{display:inline}.status{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:900;background:#fff2d8;color:#885f0b}.status.done{background:#e7f6eb;color:#216b39}.wd-totals{display:grid;justify-content:end;gap:5px;margin-top:12px;color:#173f29}.wd-total-line{display:grid;grid-template-columns:160px 160px;gap:10px;text-align:right;align-items:center}.wd-total-line strong{font-size:16px}.wd-total-line.discount strong{color:#b64242}.wd-total-line.grand strong{font-size:22px}.muted{color:#776f64}.wd-product-help{display:block;margin-top:8px;color:#776f64;font-size:11px;font-weight:700}@media(max-width:800px){.wd-grid{grid-template-columns:1fr 1fr}.wd-grid .wide{grid-column:1/-1}}@media(max-width:520px){.wd-grid{grid-template-columns:1fr}.wd-grid .wide{grid-column:1}.wd-card{padding:12px}.wd-hero{padding:17px}.wd-total-line{grid-template-columns:1fr 1fr}}
</style>
<div class="wd" style="display:grid!important">
 <section class="wd-hero"><small>MAĞAZA / DEPO ÇIKIŞ</small><h2>Sipariş fişi hazırla</h2><p>Depodan çıkan ürünleri kaydet, düzenle ve yazdır.</p></section>
 <section class="wd-card">
  <h3><?php echo $edit?'Fişi düzenle':'Yeni depo çıkış fişi'; ?></h3>
  <div class="wd-actions"><a href="urun-fiyat-listesi.php" target="_blank" rel="noopener">Ürün Fiyat Listesi</a></div>
  <form method="post" id="wdForm"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo e($edit['id']??0); ?>">
   <div class="wd-grid">
    <label>Fiş başlığı<input value="SİPARİŞ FİŞİ" disabled></label><label>Fiş no<input name="dispatch_no" required value="<?php echo e($edit['dispatch_no']??depo_cikis_next_no()); ?>"></label><label>Tarih<input type="date" name="dispatch_date" required value="<?php echo e($edit['dispatch_date']??date('Y-m-d')); ?>"></label>
    <label class="wide">Cari<select name="cari_id" id="wdCari"><option value="">Cari seçmeden elle yaz</option><?php foreach($cariler as $c): ?><option value="<?php echo e($c['id']); ?>" <?php echo (int)($edit['cari_id']??0)===(int)$c['id']?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select></label>
    <label class="wide">Firma / Müşteri<input name="customer_name" id="wdCustomer" required value="<?php echo e($edit['customer_name']??''); ?>"></label><label class="wide">Şehir<input name="customer_city" id="wdCity" value="<?php echo e($edit['customer_city']??''); ?>"></label><label class="wide">Adres<textarea name="customer_address" id="wdAddress"><?php echo e($edit['customer_address']??''); ?></textarea></label><label class="wide">Not<textarea name="note"><?php echo e($edit['note']??''); ?></textarea></label>
    <label><span>İskonto uygulansın mı?</span><span class="wd-check"><input type="checkbox" id="wdDiscountEnabled" name="discount_enabled" value="1" <?php echo (int)($edit['discount_enabled']??0)===1?'checked':''; ?>><strong>Evet, iskonto uygula</strong></span></label>
    <label><span>İskonto oranı (%)</span><input id="wdDiscountRate" name="discount_rate" inputmode="decimal" value="<?php echo e((string)($edit['discount_rate']??'0')); ?>" placeholder="Örn. 10"></label>
    <label><span>İskonto tutarı (TL)</span><input id="wdDiscountAmountInput" name="discount_amount" inputmode="decimal" value="<?php echo e((string)($edit['discount_amount']??'0')); ?>" placeholder="Örn. 1.500"></label>
    <input type="hidden" id="wdDiscountInputMode" name="discount_input_mode" value="<?php echo $edit && (float)($edit['discount_amount']??0)>0 ? 'amount' : 'rate'; ?>">
    <label><span>KDV uygulansın mı?</span><span class="wd-check"><input type="checkbox" id="wdVatEnabled" name="vat_enabled" value="1" <?php echo (int)($edit['vat_enabled']??0)===1?'checked':''; ?>><strong>Evet, KDV ekle</strong></span></label>
    <label><span>KDV oranı (%)</span><input id="wdVatRate" name="vat_rate" inputmode="decimal" value="<?php echo e((string)($edit['vat_rate']??'10')); ?>" placeholder="10"></label>
   </div>
   <datalist id="wdProductOptions"><?php foreach($productRows as $p): ?><option value="<?php echo e($p['name']); ?>"<?php if(trim((string)($p['barcode']??''))!==''): ?> label="<?php echo e((string)$p['barcode']); ?>"<?php endif; ?>></option><?php endforeach; ?></datalist>
   <small class="wd-product-help">Ürün adını yazmaya başlayınca Sipariş Ver bölümünde kayıtlı ürünler hatırlatma olarak çıkar. Örn: 6000 → 6000 BİTKE ERKEK MODAL ÇORAP.</small>
   <div class="wd-table"><table id="wdRows"><thead><tr><th>Barkod</th><th class="wd-col-name">Ürün adı</th><th class="wd-col-desc">Açıklama</th><th class="wd-col-qty">Miktar</th><th class="wd-col-price">Birim fiyat</th><th class="wd-col-total">Tutar</th><th class="wd-col-remove"></th></tr></thead><tbody><?php for($i=0;$i<$rows;$i++): ?><tr><td><input name="product_barcode[]" class="product-barcode" value="<?php echo e($items[$i]['product_barcode']??''); ?>"></td><td class="wd-col-name"><input name="product_name[]" list="wdProductOptions" class="product-name" autocomplete="off" value="<?php echo e($items[$i]['product_name']??''); ?>"></td><td class="wd-col-desc"><input name="product_type[]" class="product-type" autocomplete="off" value="<?php echo e($items[$i]['product_type']??''); ?>" placeholder="Ürün açıklaması"></td><td class="wd-col-qty"><input class="calc qty" name="quantity[]" inputmode="decimal" value="<?php echo e($items[$i]['quantity']??''); ?>"></td><td class="wd-col-price"><input class="calc price" name="unit_price[]" inputmode="decimal" value="<?php echo e($items[$i]['unit_price']??''); ?>"></td><td class="line wd-col-total">0,00</td><td class="wd-col-remove"><button type="button" class="remove">×</button></td></tr><?php endfor; ?></tbody></table></div>
   <div class="wd-totals">
    <div class="wd-total-line"><span>Ara toplam:</span><strong id="wdSubtotal">0,00 TL</strong></div>
    <div class="wd-total-line discount"><span>İskonto:</span><strong id="wdDiscountTotal">-0,00 TL</strong></div>
    <div class="wd-total-line"><span>KDV:</span><strong id="wdVatTotal">0,00 TL</strong></div>
    <div class="wd-total-line grand"><span>Genel toplam:</span><strong id="wdTotal">0,00 TL</strong></div>
   </div>
   <div class="wd-actions"><button class="primary">Kaydet</button><button type="button" id="wdAdd">Satır ekle</button><?php if($edit): ?><a target="_blank" rel="noopener noreferrer" href="depo-cikis-yazdir.php?id=<?php echo e($edit['id']); ?>">Yazdır</a><a target="_blank" rel="noopener noreferrer" href="depo-cikis-yazdir.php?id=<?php echo e($edit['id']); ?>&pdf=1">PDF görüntüle</a><?php depo_cikis_share_button((int)$edit['id']); ?><a href="depo-cikis.php">Yeni fiş</a><?php endif; ?></div>
  </form>
 </section>
 <section class="wd-card"><h3>Kayıtlı depo çıkışları</h3><div class="wd-table"><table><thead><tr><th>Tarih / No</th><th>Firma</th><th>Toplam</th><th>Durum</th><th>Oluşturan</th><th>İşlemler</th></tr></thead><tbody><?php if(!$list): ?><tr><td colspan="6">Henüz kayıt yok.</td></tr><?php endif; ?><?php foreach($list as $r): ?><tr><td><?php echo e(tr_date($r['dispatch_date'])); ?><br><small>#<?php echo e($r['dispatch_no']); ?></small></td><td><?php echo e($r['customer_name']); ?></td><td><strong><?php echo e(money((float)$r['total'])); ?> TL</strong><?php if((int)($r['discount_enabled']??0)===1): ?><br><small>%<?php echo e((string)$r['discount_rate']); ?> iskonto</small><?php endif; ?><?php if((int)($r['vat_enabled']??0)===1): ?><br><small>%<?php echo e((string)$r['vat_rate']); ?> KDV</small><?php endif; ?></td><td><span class="status <?php echo (int)$r['processed']?'done':''; ?>"><?php echo (int)$r['posted_to_cari']?'Cariye işlendi':((int)$r['processed']?'İşlendi':'Bekliyor'); ?></span></td><td><?php echo e($r['creator_name']?:'-'); ?></td><td><div class="wd-actions"><?php if(depo_cikis_can_edit($r)): ?><a href="depo-cikis.php?edit=<?php echo e($r['id']); ?>">Düzenle</a><?php endif; ?><a target="_blank" rel="noopener noreferrer" href="depo-cikis-yazdir.php?id=<?php echo e($r['id']); ?>">Yazdır</a><a target="_blank" rel="noopener noreferrer" href="depo-cikis-yazdir.php?id=<?php echo e($r['id']); ?>&pdf=1">PDF görüntüle</a><?php depo_cikis_share_button((int)$r['id']); ?><?php if(can_process_warehouse_dispatch()): ?><?php if(!(int)$r['processed']): ?><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="processed"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>"><button>İşlendi</button></form><?php endif; ?><form method="post" onsubmit="return confirm('Bu fiş cariye işlensin mi?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="post_cari"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>"><button><?php echo (int)$r['posted_to_cari']?'Cariyi Güncelle':'Cariye İşle'; ?></button></form><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section>
</div>
<?php
$shareIds=array_map('intval',array_column($list,'id'));
if($edit) $shareIds[]=(int)$edit['id'];
foreach(array_unique($shareIds) as $shareId) depo_cikis_share_form($shareId);
?>
<script>
(function(){
  const cariler=<?php echo $cariJson?:'[]'; ?>;
  window.dispatchPriceProducts=<?php echo $productJson?:'[]'; ?>;
  const sel=document.querySelector('#wdCari');
  sel?.addEventListener('change',()=>{
    const cari=cariler.find(x=>String(x.id)===sel.value);
    if(!cari)return;
    document.querySelector('#wdCustomer').value=cari.name||'';
    document.querySelector('#wdCity').value=cari.city||'';
    document.querySelector('#wdAddress').value=cari.address||'';
  });

  const body=document.querySelector('#wdRows tbody');
  const fmt=new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});
  const discountEnabled=document.querySelector('#wdDiscountEnabled');
  const discountRate=document.querySelector('#wdDiscountRate');
  const discountAmountInput=document.querySelector('#wdDiscountAmountInput');
  const discountInputMode=document.querySelector('#wdDiscountInputMode');
  const vatEnabled=document.querySelector('#wdVatEnabled');
  const vatRate=document.querySelector('#wdVatRate');

  function num(v){
    v=String(v||'').replace(/\s/g,'');
    if(!v)return 0;
    const hasComma=v.includes(','),hasDot=v.includes('.');
    if(hasComma)v=v.replace(/\./g,'').replace(',','.');
    else if(hasDot){
      const parts=v.split('.'),last=parts[parts.length-1]||'';
      if(parts.length>2||last.length===3)v=v.replace(/\./g,'');
    }
    const n=parseFloat(v);
    return Number.isFinite(n)?n:0;
  }
  function inputNumber(v,decimals){
    return Number(v||0).toFixed(decimals).replace(/0+$/,'').replace(/\.$/,'').replace('.',',');
  }
  function calc(){
    let subtotal=0;
    body.querySelectorAll('tr').forEach(row=>{
      const line=num(row.querySelector('.qty')?.value)*num(row.querySelector('.price')?.value);
      subtotal+=line;
      const out=row.querySelector('.line');
      if(out)out.textContent=fmt.format(line);
    });

    let dr=Math.max(0,Math.min(100,num(discountRate?.value)));
    let discount=0;
    if(discountEnabled?.checked){
      if((discountInputMode?.value||'rate')==='amount'){
        discount=Math.max(0,Math.min(subtotal,num(discountAmountInput?.value)));
        dr=subtotal>0?(discount/subtotal)*100:0;
        if(discountRate)discountRate.value=inputNumber(dr,4);
      }else{
        discount=Math.max(0,Math.min(subtotal,subtotal*dr/100));
        if(discountAmountInput)discountAmountInput.value=inputNumber(discount,2);
      }
    }

    const afterDiscount=Math.max(0,subtotal-discount);
    const vr=vatEnabled?.checked?Math.max(0,num(vatRate?.value)):0;
    const vat=afterDiscount*vr/100;
    const total=afterDiscount+vat;
    document.querySelector('#wdSubtotal').textContent=fmt.format(subtotal)+' TL';
    document.querySelector('#wdDiscountTotal').textContent='-'+fmt.format(discount)+' TL';
    document.querySelector('#wdVatTotal').textContent=fmt.format(vat)+' TL';
    document.querySelector('#wdTotal').textContent=fmt.format(total)+' TL';
  }
  body.addEventListener('input',e=>{if(e.target.classList.contains('calc'))calc()});
  discountEnabled?.addEventListener('change',calc);
  discountRate?.addEventListener('input',()=>{if(discountInputMode)discountInputMode.value='rate';calc()});
  discountAmountInput?.addEventListener('input',()=>{if(discountInputMode)discountInputMode.value='amount';calc()});
  vatEnabled?.addEventListener('input',calc);
  vatEnabled?.addEventListener('change',calc);
  vatRate?.addEventListener('input',calc);
  vatRate?.addEventListener('change',calc);

  body.addEventListener('click',e=>{
    if(e.target.classList.contains('remove')){
      e.target.closest('tr').remove();
      calc();
    }
  });
  document.querySelector('#wdAdd').addEventListener('click',()=>{
    const row=body.rows[0].cloneNode(true);
    row.querySelectorAll('input').forEach(i=>i.value='');
    row.querySelector('.line').textContent='0,00';
    body.appendChild(row);
  });
  calc();
})();
</script>
<script src="assets/musteri-urun-son-fiyat.js?v=5"></script>
<?php page_footer(); ?>