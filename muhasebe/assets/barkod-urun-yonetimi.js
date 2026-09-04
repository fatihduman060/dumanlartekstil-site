(function(){
  var root=document.querySelector('[data-product-root]');
  if(!root) return;

  var api=root.dataset.api||'barkod-satis-api.php';
  var csrf=root.dataset.csrf||'';
  var form=root.querySelector('[data-product-form]');
  var status=root.querySelector('[data-product-status]');
  var extraInput=root.querySelector('[data-extra-barcode-input]');
  var extraList=root.querySelector('[data-extra-barcode-list]');
  var searchInput=root.querySelector('[data-product-list-search]');
  var extraBarcodes=[];

  function esc(value){
    return String(value==null?'':value).replace(/[&<>"']/g,function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
    });
  }
  function normalizeBarcode(value){return String(value||'').replace(/\s+/g,'').trim();}
  function setStatus(message,isError){
    if(!status) return;
    status.textContent=message||'';
    status.dataset.tone=isError?'error':'success';
  }
  function renderExtra(){
    if(!form||!extraList) return;
    form.elements.extra_barcodes.value=extraBarcodes.join('\n');
    extraList.innerHTML=extraBarcodes.length
      ? extraBarcodes.map(function(code,i){return '<span class="pos-barcode-chip"><b>'+esc(code)+'</b><button type="button" data-extra-remove="'+i+'" aria-label="Barkodu kaldır">×</button></span>';}).join('')
      : '<small>Henüz ek barkod yok.</small>';
  }
  function setExtra(value){
    extraBarcodes=String(value||'').split(/[\r\n,;]+/).map(normalizeBarcode).filter(function(code,i,list){return code&&list.indexOf(code)===i;});
    renderExtra();
  }
  function resetForm(){
    if(!form) return;
    form.reset();
    form.elements.id.value='';
    form.elements.vat_rate.value='10';
    form.elements.stock_quantity.value='0';
    if(form.elements.track_stock) form.elements.track_stock.checked=true;
    setExtra('');
    var title=root.querySelector('[data-form-title]');
    if(title) title.textContent='Yeni Ürün Girişi';
    var save=form.querySelector('[type="submit"]');
    if(save) save.textContent='Ürünü Kaydet';
    setStatus('');
    var barcode=form.elements.barcode;
    if(barcode) setTimeout(function(){barcode.focus();},60);
  }
  function addExtra(){
    if(!extraInput||!form) return;
    var code=normalizeBarcode(extraInput.value);
    var primary=normalizeBarcode(form.elements.barcode.value);
    if(!code) return;
    if(code===primary){setStatus('Bu barkod zaten ana barkod olarak yazıldı.',true);extraInput.value='';return;}
    if(extraBarcodes.indexOf(code)!==-1){setStatus('Bu ek barkod zaten listede.',true);extraInput.value='';return;}
    extraBarcodes.push(code);
    extraInput.value='';
    renderExtra();
    setStatus('');
    extraInput.focus();
  }

  if(root.querySelector('[data-product-new]')) root.querySelector('[data-product-new]').addEventListener('click',resetForm);
  if(root.querySelector('[data-extra-barcode-add]')) root.querySelector('[data-extra-barcode-add]').addEventListener('click',addExtra);
  if(extraInput){
    extraInput.addEventListener('keydown',function(event){if(event.key==='Enter'){event.preventDefault();addExtra();}});
    extraInput.addEventListener('change',function(){if(extraInput.value.trim())addExtra();});
  }
  if(extraList) extraList.addEventListener('click',function(event){
    var button=event.target.closest('[data-extra-remove]');
    if(!button) return;
    extraBarcodes.splice(Number(button.dataset.extraRemove),1);
    renderExtra();
  });

  root.addEventListener('click',function(event){
    var edit=event.target.closest('[data-product-edit]');
    if(edit&&form){
      var p={};
      try{p=JSON.parse(edit.getAttribute('data-product-edit')||'{}');}catch(e){return;}
      form.elements.id.value=p.id||'';
      form.elements.barcode.value=p.barcode||'';
      form.elements.name.value=p.name||'';
      form.elements.variant_name.value=p.variant_name||'';
      form.elements.sale_price.value=p.sale_price||'';
      form.elements.vat_rate.value=p.vat_rate==null?10:p.vat_rate;
      form.elements.stock_quantity.value=p.stock_quantity==null?0:p.stock_quantity;
      if(form.elements.track_stock) form.elements.track_stock.checked=Number(p.track_stock||0)===1;
      setExtra(p.extra_barcodes||'');
      var title=root.querySelector('[data-form-title]');
      if(title) title.textContent='Ürünü Düzenle';
      var save=form.querySelector('[type="submit"]');
      if(save) save.textContent='Değişiklikleri Kaydet';
      form.scrollIntoView({behavior:'smooth',block:'start'});
      setTimeout(function(){form.elements.name.focus();},250);
      return;
    }
  });

  if(form) form.addEventListener('submit',function(event){
    event.preventDefault();
    var save=form.querySelector('[type="submit"]');
    if(save){save.disabled=true;save.textContent='Kaydediliyor…';}
    var body=new FormData(form);
    body.set('action','save_product');
    body.set('csrf_token',csrf);
    body.set('extra_barcodes',extraBarcodes.join('\n'));
    if(form.elements.track_stock&&!form.elements.track_stock.checked) body.delete('track_stock');
    fetch(api,{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(data){
        if(!data||!data.ok) throw new Error((data&&data.error)||'Ürün kaydedilemedi.');
        setStatus(data.message||'Ürün kaydedildi.');
        window.setTimeout(function(){location.reload();},450);
      })
      .catch(function(error){
        setStatus(error.message||'Ürün kaydedilemedi.',true);
        if(save){save.disabled=false;save.textContent=form.elements.id.value?'Değişiklikleri Kaydet':'Ürünü Kaydet';}
      });
  });

  if(searchInput) searchInput.addEventListener('input',function(){
    var query=String(searchInput.value||'').trim().toLocaleLowerCase('tr-TR');
    root.querySelectorAll('[data-product-search]').forEach(function(row){
      var hay=String(row.getAttribute('data-product-search')||'').toLocaleLowerCase('tr-TR');
      row.hidden=!!query&&hay.indexOf(query)===-1;
    });
  });

  root.querySelectorAll('[data-product-bulk-save]').forEach(function(button){
    button.addEventListener('click',function(){
      var updates=[];
      root.querySelectorAll('[data-bulk-product]').forEach(function(row){
        updates.push({
          id:Number(row.getAttribute('data-bulk-product')||0),
          sale_price:row.querySelector('[data-bulk-price]').value,
          stock_quantity:row.querySelector('[data-bulk-stock]').value
        });
      });
      if(!updates.length){setStatus('Güncellenecek ürün yok.',true);return;}
      root.querySelectorAll('[data-product-bulk-save]').forEach(function(b){b.disabled=true;b.textContent='Kaydediliyor…';});
      var body=new FormData();
      body.set('action','bulk_update_products');
      body.set('csrf_token',csrf);
      body.set('updates_json',JSON.stringify(updates));
      fetch(api,{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
        .then(function(r){return r.json();})
        .then(function(data){if(!data||!data.ok)throw new Error((data&&data.error)||'Ürünler güncellenemedi.');setStatus(data.message||'Ürünler güncellendi.');})
        .catch(function(error){setStatus(error.message||'Ürünler güncellenemedi.',true);})
        .finally(function(){root.querySelectorAll('[data-product-bulk-save]').forEach(function(b){b.disabled=false;b.textContent='Tüm Değişiklikleri Kaydet';});});
    });
  });

  resetForm();
})();
