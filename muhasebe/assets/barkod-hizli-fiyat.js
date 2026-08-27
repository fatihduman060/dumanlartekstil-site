(function(){
  'use strict';
  var root=document.querySelector('[data-pos-root]');
  if(!root) return;
  var csrf=root.dataset.csrf||'';
  var scanRow=root.querySelector('.pos-scan-row');
  var productPanel=root.querySelector('.pos-products-panel');
  if(!scanRow||!productPanel) return;

  function money(v){
    return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v||0))+' TL';
  }
  function esc(s){
    return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});
  }

  var style=document.createElement('style');
  style.id='posQuickPriceStyle';
  style.textContent=''
    +'.pos-quick-price-launch{min-height:44px;white-space:nowrap}'
    +'.pos-quick-price{grid-column:1/-1;border:1px solid #d9c79e;background:#fffaf0;border-radius:16px;padding:12px 14px;margin-top:10px;display:none}'
    +'.pos-quick-price.open{display:block}'
    +'.pos-quick-price-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}'
    +'.pos-quick-price-head strong{color:#102818;font-size:15px}.pos-quick-price-head small{display:block;color:#78684d;margin-top:2px}'
    +'.pos-quick-price-close{border:0;background:#eee8dc;border-radius:999px;width:34px;height:34px;font-size:18px;cursor:pointer}'
    +'.pos-quick-price-grid{display:grid;grid-template-columns:minmax(210px,1.5fr) minmax(150px,.8fr) auto;gap:9px;align-items:end}'
    +'.pos-quick-price-grid label{display:grid;gap:5px;font-size:11px;font-weight:900;color:#102818}'
    +'.pos-quick-price-grid input{min-height:44px;border:1px solid #d9c79e;border-radius:12px;padding:8px 10px;background:#fff;font-size:16px}'
    +'.pos-quick-price-save{min-height:44px}'
    +'.pos-quick-price-product{margin-top:9px;padding:9px 11px;border-radius:11px;background:#edf7ef;color:#184b30;font-size:12px;font-weight:800;display:none}'
    +'.pos-quick-price-product.show{display:block}'
    +'.pos-quick-price-status{min-height:20px;margin:7px 0 0;font-size:12px;font-weight:800;color:#6f5b32}'
    +'.pos-price-action{display:inline-flex!important;align-items:center;justify-content:center;min-height:30px!important;padding:5px 9px!important;border-radius:999px!important;border:1px solid #d8b96c!important;background:#fff8df!important;color:#75530b!important;font-size:11px!important;font-weight:950!important;cursor:pointer!important;margin-left:8px!important;white-space:nowrap!important}'
    +'.pos-product-price-wrap{display:flex;align-items:center;justify-content:flex-end;gap:3px}'
    +'@media(max-width:720px){.pos-quick-price-grid{grid-template-columns:1fr}.pos-quick-price-launch{width:100%}.pos-price-action{margin-left:0!important;margin-top:5px!important}}';
  document.head.appendChild(style);

  var panel=document.createElement('section');
  panel.className='pos-quick-price';
  panel.innerHTML=''
    +'<div class="pos-quick-price-head"><div><strong>⚡ Hızlı fiyat değiştir</strong><small>Barkodu okut, yeni fiyatı yaz, kaydet.</small></div><button type="button" class="pos-quick-price-close" aria-label="Kapat">×</button></div>'
    +'<div class="pos-quick-price-grid">'
      +'<label><span>Barkod</span><input type="text" inputmode="none" autocomplete="off" placeholder="Barkodu okutun" data-quick-price-barcode data-barcode-input></label>'
      +'<label><span>Yeni satış fiyatı</span><input type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="0,00" data-quick-price-value disabled></label>'
      +'<button type="button" class="btn btn-primary pos-quick-price-save" data-quick-price-save disabled>Fiyatı Kaydet</button>'
    +'</div>'
    +'<div class="pos-quick-price-product" data-quick-price-product></div>'
    +'<p class="pos-quick-price-status" data-quick-price-status></p>';
  scanRow.insertAdjacentElement('afterend',panel);

  var barcode=panel.querySelector('[data-quick-price-barcode]');
  var price=panel.querySelector('[data-quick-price-value]');
  var save=panel.querySelector('[data-quick-price-save]');
  var productBox=panel.querySelector('[data-quick-price-product]');
  var stateText=panel.querySelector('[data-quick-price-status]');
  var current=null;
  var lookupTimer=null;

  function openPanel(){
    panel.classList.add('open');
    window.setTimeout(function(){barcode.focus();},80);
  }
  function resetSelection(keepBarcode){
    current=null;
    if(!keepBarcode) barcode.value='';
    price.value='';price.disabled=true;save.disabled=true;
    productBox.classList.remove('show');productBox.innerHTML='';stateText.textContent='';
  }
  function selectProduct(p){
    current=p;
    barcode.value=p.barcode||'';
    price.disabled=false;price.value=Number(p.sale_price||0).toFixed(2);
    save.disabled=false;
    productBox.innerHTML='<strong>'+esc(p.name||'Ürün')+'</strong> · Mevcut fiyat: '+esc(money(p.sale_price))+(p.stock_quantity!=null?' · Stok: '+esc(p.stock_quantity):'');
    productBox.classList.add('show');
    stateText.textContent='';
    window.setTimeout(function(){price.focus();price.select();},70);
  }
  function lookup(){
    var code=String(barcode.value||'').trim();
    if(!code){resetSelection(true);return;}
    stateText.textContent='Ürün bulunuyor…';
    fetch('barkod-satis-fiyat.php?barcode='+encodeURIComponent(code)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(r){return r.json().catch(function(){return {ok:false,error:'Sunucu cevabı okunamadı.'};});})
      .then(function(d){if(!d.ok)throw new Error(d.error||'Ürün bulunamadı.');selectProduct(d.product);})
      .catch(function(e){resetSelection(true);stateText.textContent=e.message||'Ürün bulunamadı.';});
  }
  function scheduleLookup(){
    if(lookupTimer) clearTimeout(lookupTimer);
    lookupTimer=setTimeout(lookup,120);
  }
  function savePrice(){
    if(!current||!current.id) return;
    var newPrice=Number(price.value||0);
    if(!(newPrice>0)){stateText.textContent='Yeni fiyatı girin.';price.focus();return;}
    save.disabled=true;save.textContent='Kaydediliyor…';stateText.textContent='';
    var body=new URLSearchParams();
    body.set('csrf_token',csrf);body.set('product_id',current.id);body.set('sale_price',price.value);
    fetch('barkod-satis-fiyat.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body:body.toString()})
      .then(function(r){return r.json().catch(function(){return {ok:false,error:'Sunucu cevabı okunamadı.'};});})
      .then(function(d){
        if(!d.ok) throw new Error(d.error||'Fiyat güncellenemedi.');
        stateText.textContent=d.message+' Yeni fiyat: '+money(d.sale_price);
        current.sale_price=d.sale_price;
        save.textContent='Kaydedildi ✓';
        window.setTimeout(function(){location.reload();},650);
      })
      .catch(function(e){stateText.textContent=e.message||'Fiyat güncellenemedi.';save.disabled=false;save.textContent='Fiyatı Kaydet';});
  }

  panel.querySelector('.pos-quick-price-close').addEventListener('click',function(){panel.classList.remove('open');resetSelection(false);});
  barcode.addEventListener('change',scheduleLookup);
  barcode.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();lookup();}});
  price.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();savePrice();}});
  save.addEventListener('click',savePrice);

  // Ürün listesindeki her ürüne ayrıca tek dokunuşluk fiyat düğmesi ekle.
  productPanel.querySelectorAll('[data-product-edit]').forEach(function(row){
    var p=null;
    try{p=JSON.parse(row.dataset.productEdit||'{}');}catch(e){return;}
    if(!p||!p.id) return;
    var priceSide=row.lastElementChild;
    if(!priceSide||priceSide.querySelector('.pos-price-action')) return;
    priceSide.classList.add('pos-product-price-wrap');
    var btn=document.createElement('span');
    btn.className='pos-price-action';
    btn.setAttribute('role','button');
    btn.setAttribute('tabindex','0');
    btn.textContent='₺ Fiyat';
    function quick(event){
      event.preventDefault();event.stopPropagation();
      openPanel();
      selectProduct({id:p.id,barcode:p.barcode,name:(String(p.name||'')+(p.variant_name?' - '+p.variant_name:'')),sale_price:p.sale_price,stock_quantity:p.stock_quantity});
    }
    btn.addEventListener('click',quick);
    btn.addEventListener('keydown',function(event){if(event.key==='Enter'||event.key===' '){quick(event);}});
    priceSide.appendChild(btn);
  });
})();
