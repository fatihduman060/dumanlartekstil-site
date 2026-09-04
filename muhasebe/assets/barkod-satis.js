(function(){
  var root=document.querySelector('[data-pos-root]');if(!root)return;
  var api=root.dataset.api,csrf=root.dataset.csrf,cart=[],scan=root.querySelector('[data-pos-scan]'),cartBox=root.querySelector('[data-pos-cart]'),results=root.querySelector('[data-pos-results]'),discount=root.querySelector('[data-pos-discount]'),status=root.querySelector('[data-pos-status]');
  var lookupBusy=false,lastLookup='',lastAddedProductId=null,quantityShortcutTimer=null;
  var lastNotFoundAnnouncement=0;
  function announceProductNotFound(){
    var now=Date.now();if(now-lastNotFoundAnnouncement<1200)return;lastNotFoundAnnouncement=now;
    if(!('speechSynthesis' in window)||typeof SpeechSynthesisUtterance==='undefined')return;
    window.speechSynthesis.cancel();var message=new SpeechSynthesisUtterance('Ürün bulunamadı');message.lang='tr-TR';message.rate=1;message.volume=1;window.speechSynthesis.speak(message);
  }
  root.addEventListener('pos:product-not-found',announceProductNotFound);
  var launcherLink=root.querySelector('[data-windows-launcher]');
  if(launcherLink)launcherLink.addEventListener('click',function(e){
    e.preventDefault();
    var lines=[
      '@echo off',
      'setlocal',
      'set "KASA_URL=https://bitke.com.tr/muhasebe/barkod-satis.php"',
      'set "KASA_PROFILE=%LOCALAPPDATA%\\DumanlarMagazaKasa"',
      'set "CHROME=%ProgramFiles%\\Google\\Chrome\\Application\\chrome.exe"',
      'if exist "%CHROME%" goto chrome',
      'set "CHROME=%ProgramFiles(x86)%\\Google\\Chrome\\Application\\chrome.exe"',
      'if exist "%CHROME%" goto chrome',
      'set "EDGE=%ProgramFiles(x86)%\\Microsoft\\Edge\\Application\\msedge.exe"',
      'if exist "%EDGE%" goto edge',
      'echo Chrome veya Microsoft Edge bulunamadi.',
      'pause',
      'exit /b 1',
      ':chrome',
      'start "" "%CHROME%" --app="%KASA_URL%" --kiosk-printing --user-data-dir="%KASA_PROFILE%"',
      'exit /b 0',
      ':edge',
      'start "" "%EDGE%" --app="%KASA_URL%" --kiosk-printing --user-data-dir="%KASA_PROFILE%"',
      'exit /b 0'
    ];
    var blob=new Blob([lines.join('\r\n')+'\r\n'],{type:'application/octet-stream'});
    var url=URL.createObjectURL(blob),a=document.createElement('a');
    a.href=url;a.download='Dumanlar-Magaza-Kasa.cmd';document.body.appendChild(a);a.click();a.remove();setTimeout(function(){URL.revokeObjectURL(url);},1000);
    status.textContent='Windows mağaza kasa başlatıcısı indirildi.';
  });

  var priceCheck=document.querySelector('[data-price-check]'),priceCheckInput=priceCheck&&priceCheck.querySelector('[data-price-check-input]'),priceCheckResults=priceCheck&&priceCheck.querySelector('[data-price-check-results]'),priceCheckStatus=priceCheck&&priceCheck.querySelector('[data-price-check-status]'),priceCheckItems=[];
  function renderPriceProduct(p){priceCheckResults.innerHTML='<article class="pos-price-check-product"><span>'+esc(productName(p))+'</span><strong>'+money(p.sale_price)+'</strong><small>Barkod: '+esc(p.barcode)+' · Stok: '+Number(p.stock_quantity||0)+'</small></article>';priceCheckStatus.textContent='';}
  function renderPriceChoices(items){priceCheckItems=items;if(!items.length){priceCheckResults.innerHTML='';priceCheckStatus.textContent='Ürün bulunamadı.';return;}priceCheckStatus.textContent=items.length+' ürün bulundu. Ürünü seçin.';priceCheckResults.innerHTML=items.map(function(p){return '<button type="button" data-price-check-id="'+p.id+'"><span><b>'+esc(productName(p))+'</b><small>'+esc(p.barcode)+'</small></span><strong>'+money(p.sale_price)+'</strong></button>';}).join('');}
  function lookupPrice(){var q=String(priceCheckInput.value||'').trim();if(!q){priceCheckStatus.textContent='Barkod veya ürün adı girin.';priceCheckInput.focus();return;}priceCheckStatus.textContent='Fiyat aranıyor…';priceCheckResults.innerHTML='';fetch(api+'?action=barcode&barcode='+encodeURIComponent(q)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(d){if(d.product){renderPriceProduct(d.product);return null;}return fetch(api+'?action=products&q='+encodeURIComponent(q)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(x){renderPriceChoices(x.products||[]);});}).catch(function(){priceCheckStatus.textContent='Fiyat bilgisi alınamadı.';});}
  if(priceCheck){
    root.querySelector('[data-price-check-open]').onclick=function(){priceCheck.hidden=false;priceCheckInput.value='';priceCheckResults.innerHTML='';priceCheckStatus.textContent='';setTimeout(function(){priceCheckInput.focus();},80);};
    priceCheck.querySelector('[data-price-check-close]').onclick=function(){priceCheck.hidden=true;scan.focus();};
    priceCheck.addEventListener('click',function(e){if(e.target===priceCheck){priceCheck.hidden=true;scan.focus();}});
    priceCheck.querySelector('[data-price-check-search]').onclick=lookupPrice;
    priceCheckInput.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();lookupPrice();}});
    priceCheckInput.addEventListener('change',function(){if(priceCheckInput.value.trim())lookupPrice();});
    priceCheckResults.addEventListener('click',function(e){var button=e.target.closest('[data-price-check-id]');if(!button)return;var p=priceCheckItems.find(function(item){return Number(item.id)===Number(button.dataset.priceCheckId);});if(p)renderPriceProduct(p);});
  }

  function money(v){return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v||0))+' TL';}
  function esc(s){return String(s||'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
  function productName(p){var name=String((p&&p.name)||'').trim(),variant=String((p&&p.variant_name)||'').trim();return variant?name+' - '+variant:name;}
  function total(){var raw=cart.reduce(function(s,x){return s+x.quantity*Number(x.sale_price);},0);return Math.max(0,raw-Number(discount.value||0));}
  function render(){
    if(!cart.length)cartBox.innerHTML='<div class="pos-empty">Henüz ürün okutulmadı.</div>';
    else cartBox.innerHTML=cart.map(function(x,i){return '<div class="pos-cart-row"><div class="pos-cart-product"><strong>'+esc(productName(x))+'</strong><small>'+esc(x.barcode)+'</small></div><div class="pos-qty"><button type="button" data-minus="'+i+'">−</button><input type="number" min="0.01" step="1" value="'+x.quantity+'" data-qty="'+i+'"><button type="button" data-plus="'+i+'">+</button></div><span class="pos-unit">Adet</span><span class="pos-unit-price">'+money(x.sale_price)+'</span><span class="pos-line-discount">—</span><span class="pos-line-tax">%'+Number(x.vat_rate||0)+'</span><strong class="pos-line-total">'+money(x.quantity*Number(x.sale_price))+'</strong><button type="button" class="pos-remove" data-remove="'+i+'">×</button></div>';}).join('');
    root.querySelector('[data-pos-total]').textContent=money(total());root.querySelector('[data-pos-count]').textContent=cart.reduce(function(s,x){return s+Number(x.quantity);},0)+' ürün';
  }
  function add(p){var old=cart.find(function(x){return Number(x.id)===Number(p.id);});if(old)old.quantity+=1;else{p.quantity=1;cart.push(p);}lastAddedProductId=Number(p.id);render();scan.value='';lastLookup='';scan.focus();status.textContent='';}
  function addToLastProduct(amount){
    var item=cart.find(function(x){return Number(x.id)===Number(lastAddedProductId);});
    if(!item){status.textContent='Önce bir ürün okutun.';scan.value='';return;}
    item.quantity+=amount;scan.value='';lastLookup='';results.hidden=true;results.innerHTML='';render();scan.focus();status.textContent=productName(item)+' adedi +'+amount+' artırıldı.';
  }
  root.addEventListener('pos:add-product',function(event){if(event.detail)add(event.detail);});
  function showResults(items){results.hidden=false;if(!items.length){results.innerHTML='<div class="pos-result-empty">Ürün bulunamadı. Aşağıdan yeni ürün tanımlayabilirsiniz.</div>';root.dispatchEvent(new CustomEvent('pos:product-not-found'));return;}results.innerHTML=items.map(function(p){return '<button type="button" data-result-id="'+p.id+'"><span><strong>'+esc(productName(p))+'</strong><small>'+esc(p.matched_barcode||p.barcode)+' · Stok: '+Number(p.stock_quantity)+'</small></span><strong>'+money(p.sale_price)+'</strong></button>';}).join('');results._items=items;}
  function lookup(){var q=scan.value.trim();if(!q||lookupBusy||q===lastLookup)return;lookupBusy=true;lastLookup=q;status.textContent='Barkod aranıyor…';fetch(api+'?action=barcode&barcode='+encodeURIComponent(q)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(d){if(d.product){add(d.product);results.hidden=true;return null;}return fetch(api+'?action=products&q='+encodeURIComponent(q)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(x){showResults(x.products||[]);status.textContent='';});}).catch(function(){status.textContent='Ürün aranamadı.';lastLookup='';}).finally(function(){lookupBusy=false;});}
  scan.addEventListener('input',function(){
    if(quantityShortcutTimer)clearTimeout(quantityShortcutTimer);
    var shortcut=scan.value.trim().match(/^\+(\d+)$/);if(!shortcut)return;
    quantityShortcutTimer=setTimeout(function(){var current=scan.value.trim().match(/^\+(\d+)$/);if(current)addToLastProduct(Number(current[1]));},350);
  });
  scan.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();var shortcut=scan.value.trim().match(/^\+(\d+)$/);if(shortcut){if(quantityShortcutTimer)clearTimeout(quantityShortcutTimer);addToLastProduct(Number(shortcut[1]));return;}lastLookup='';lookup();}});
  scan.addEventListener('change',function(){if(scan.value.trim()){lastLookup='';lookup();}});
  root.querySelector('[data-pos-search]').onclick=function(){lastLookup='';lookup();};
  results.addEventListener('click',function(e){var b=e.target.closest('[data-result-id]');if(!b)return;var p=(results._items||[]).find(function(x){return Number(x.id)===Number(b.dataset.resultId);});if(p){add(p);results.hidden=true;}});
  function cartEventPayload(eventType,items){
    var body=new FormData();body.set('action','cart_event');body.set('csrf_token',csrf);body.set('event_type',eventType);body.set('items_json',JSON.stringify(items.map(function(x){return {product_id:x.id,quantity:x.quantity};})));body.set('discount_amount',discount.value||0);var payment=root.querySelector('input[name="pos_payment"]:checked');body.set('payment_method',payment?payment.value:'cash');return body;
  }
  function logCartEvent(eventType,items){
    return fetch(api,{method:'POST',body:cartEventPayload(eventType,items),credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(d){if(!d.ok)throw new Error(d.error||'Sepet işlemi kaydedilemedi.');return d;});
  }
  cartBox.addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;var i=Number(b.dataset.minus||b.dataset.plus||b.dataset.remove);if(b.hasAttribute('data-minus'))cart[i].quantity=Math.max(1,cart[i].quantity-1);if(b.hasAttribute('data-plus'))cart[i].quantity+=1;if(b.hasAttribute('data-remove')){var snapshot=[cart[i]];logCartEvent('item_removed',snapshot).catch(function(error){status.textContent='Ürün çıkarıldı; denetim kaydı alınamadı: '+error.message;});cart.splice(i,1);}render();});
  cartBox.addEventListener('change',function(e){if(!e.target.hasAttribute('data-qty'))return;var i=Number(e.target.dataset.qty);cart[i].quantity=Math.max(.01,Number(e.target.value||1));render();});
  discount.addEventListener('input',render);root.querySelector('[data-pos-clear]').onclick=function(){if(!cart.length||!confirm('Sepet temizlensin mi? Bu işlem tarih, saat, kullanıcı, ürünler ve toplam tutarla denetim kaydına yazılacaktır.'))return;var snapshot=cart.slice(),button=this;button.disabled=true;status.textContent='Sepet temizliği kaydediliyor…';logCartEvent('cart_cleared',snapshot).then(function(d){status.textContent=d.message;}).catch(function(error){status.textContent='Sepet temizlendi; denetim kaydı alınamadı: '+error.message;}).finally(function(){cart=[];discount.value=0;render();button.disabled=false;scan.focus();});};
  root.querySelectorAll('input[name="pos_payment"]').forEach(function(r){r.addEventListener('change',function(){root.querySelector('[data-pos-person-wrap]').hidden=this.value!=='credit';var customer=root.querySelector('[data-pos-customer-name]');if(customer)customer.textContent=this.value==='credit'?'Veresiye Müşterisi':'Perakende Müşteri';var paymentMessage=root.querySelector('[data-pos-payment-status]');if(paymentMessage)paymentMessage.textContent='';});});
  var personSelect=root.querySelector('[data-pos-person]');if(personSelect)personSelect.addEventListener('change',function(){var customer=root.querySelector('[data-pos-customer-name]');if(customer&&this.value)customer.textContent=this.options[this.selectedIndex].text;});
  var paymentModal=root.querySelector('[data-pos-payment-modal]'),completeButton=root.querySelector('[data-pos-complete]'),paymentConfirm=root.querySelector('[data-pos-payment-confirm]'),paymentStatus=root.querySelector('[data-pos-payment-status]');
  function closePaymentModal(){paymentModal.hidden=true;}
  completeButton.onclick=function(){
    if(!cart.length){status.textContent='Önce sepete ürün ekleyin.';return;}
    root.querySelectorAll('input[name="pos_payment"]').forEach(function(input){input.checked=false;});
    personSelect.value='';root.querySelector('[data-pos-person-wrap]').hidden=true;root.querySelector('[data-pos-customer-name]').textContent='Perakende Müşteri';paymentModal.hidden=false;status.textContent='';paymentStatus.textContent='';
  };
  root.querySelector('[data-pos-payment-close]').onclick=closePaymentModal;
  paymentModal.addEventListener('click',function(event){if(event.target===paymentModal)closePaymentModal();});
  paymentConfirm.onclick=function(){
    var selected=root.querySelector('input[name="pos_payment"]:checked');
    if(!selected){paymentStatus.textContent='Ödeme şeklini seçin.';return;}
    var pay=selected.value,person=personSelect.value;if(pay==='credit'&&!person){paymentStatus.textContent='Veresiye için personel seçin.';personSelect.focus();return;}
    var receiptWindow=window.open('about:blank','pos_receipt','width=430,height=760');var btn=this;btn.disabled=true;btn.textContent='Satış kaydediliyor…';status.textContent='';var body=new FormData();body.set('action','complete_sale');body.set('csrf_token',csrf);body.set('items_json',JSON.stringify(cart.map(function(x){return {product_id:x.id,quantity:x.quantity};})));body.set('payment_method',pay);body.set('person_id',pay==='credit'?person:'');body.set('discount_amount',discount.value||0);body.set('note',root.querySelector('[data-pos-note]').value||'');
    fetch(api,{method:'POST',body:body,credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(d){if(!d.ok)throw new Error(d.error||'Satış kaydedilemedi.');status.textContent=d.message;cart=[];discount.value=0;render();if(receiptWindow)receiptWindow.location.href=d.receipt_url+'&print=1&autoclose=1';else location.href=d.receipt_url+'&print=1&autoclose=1';setTimeout(function(){location.reload();},900);}).catch(function(e){if(receiptWindow)receiptWindow.close();paymentStatus.textContent=e.message;btn.disabled=false;btn.textContent='Seçimi Onayla ve Satışı Tamamla';});
  };
  var historyBox=root.querySelector('.pos-history-list');
  if(historyBox)historyBox.addEventListener('click',function(e){var button=e.target.closest('[data-sale-delete]');if(!button)return;e.preventDefault();e.stopPropagation();var receipt=button.dataset.receiptNo||'',saleId=button.dataset.saleDelete;if(!confirm(receipt+' numaralı satış silinsin mi? Stok ve mağaza toplamları geri alınacak.'))return;button.disabled=true;button.textContent='Siliniyor…';var body=new FormData();body.set('action','delete_sale');body.set('csrf_token',csrf);body.set('sale_id',saleId);fetch(api,{method:'POST',body:body,credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(d){if(!d.ok)throw new Error(d.error||'Satış silinemedi.');status.textContent=d.message;location.reload();}).catch(function(error){status.textContent=error.message;button.disabled=false;button.textContent='Sil';});});
  var form=root.querySelector('[data-product-form]'),extraInput=root.querySelector('[data-extra-barcode-input]'),extraList=root.querySelector('[data-extra-barcode-list]'),extraBarcodes=[];
  function normalizeBarcode(value){return String(value||'').replace(/\s+/g,'').trim();}
  function renderExtraBarcodes(){form.elements.extra_barcodes.value=extraBarcodes.join('\n');extraList.innerHTML=extraBarcodes.length?extraBarcodes.map(function(code,i){return '<span class="pos-barcode-chip"><b>'+esc(code)+'</b><button type="button" data-extra-remove="'+i+'" aria-label="Barkodu kaldır">×</button></span>';}).join(''):'<small>Henüz ek barkod yok.</small>';}
  function setExtraBarcodes(value){extraBarcodes=String(value||'').split(/[\r\n,;]+/).map(normalizeBarcode).filter(function(code,i,list){return code&&list.indexOf(code)===i;});renderExtraBarcodes();}
  function addExtraBarcode(){var code=normalizeBarcode(extraInput.value),primary=normalizeBarcode(form.elements.barcode.value);if(!code)return;if(code===primary){status.textContent='Bu barkod zaten ana barkod olarak kayıtlı.';extraInput.value='';return;}if(extraBarcodes.indexOf(code)!==-1){status.textContent='Bu ek barkod zaten listede.';extraInput.value='';return;}extraBarcodes.push(code);extraInput.value='';renderExtraBarcodes();status.textContent='';extraInput.focus();}
  root.querySelector('[data-extra-barcode-add]').onclick=addExtraBarcode;
  extraInput.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();addExtraBarcode();}});
  extraInput.addEventListener('change',function(){if(extraInput.value.trim())addExtraBarcode();});
  extraList.addEventListener('click',function(e){var button=e.target.closest('[data-extra-remove]');if(!button)return;extraBarcodes.splice(Number(button.dataset.extraRemove),1);renderExtraBarcodes();});
  function bindProductRows(){root.querySelectorAll('[data-product-edit]').forEach(function(b){b.onclick=function(){var p=JSON.parse(this.dataset.productEdit);Object.keys(p).forEach(function(k){var el=form.elements[k];if(!el||k==='extra_barcodes')return;if(el.type==='checkbox')el.checked=Number(p[k])===1;else el.value=p[k] == null?'':p[k];});setExtraBarcodes(p.extra_barcodes||'');form.scrollIntoView({behavior:'smooth',block:'center'});form.elements.barcode.focus();};});}
  root.querySelector('[data-product-new]').onclick=function(){form.reset();form.elements.id.value='';form.elements.vat_rate.value='10';form.elements.track_stock.checked=true;if(form.elements.variant_name)form.elements.variant_name.value='';setExtraBarcodes('');form.elements.barcode.focus();};
  form.addEventListener('submit',function(e){e.preventDefault();var body=new FormData(form);body.set('action','save_product');body.set('csrf_token',csrf);fetch(api,{method:'POST',body:body,credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(d){if(!d.ok)throw new Error(d.error||'Ürün kaydedilemedi.');status.textContent=d.message;location.reload();}).catch(function(e){status.textContent=e.message;});});
  var productManager=root.querySelector('[data-product-manager]'),productListToggle=root.querySelector('[data-product-list-toggle]'),productListSearch=root.querySelector('[data-product-list-search]'),productManagerStatus=root.querySelector('[data-product-manager-status]');
  if(productManager&&productListToggle){
    productListToggle.addEventListener('click',function(){
      var opening=productManager.hidden;
      productManager.hidden=!opening;
      productListToggle.setAttribute('aria-expanded',opening?'true':'false');
      productListToggle.textContent=opening?'Ürün Listesini Kapat':'Ürün Listesi ('+productManager.querySelectorAll('[data-bulk-product]').length+')';
      if(opening)setTimeout(function(){if(productListSearch)productListSearch.focus();},60);
    });
    if(productListSearch)productListSearch.addEventListener('input',function(){
      var q=String(this.value||'').toLocaleLowerCase('tr-TR').trim();
      productManager.querySelectorAll('[data-bulk-product]').forEach(function(row){row.hidden=q!==''&&String(row.dataset.productSearch||'').indexOf(q)===-1;});
    });
    productManager.querySelectorAll('[data-product-bulk-save]').forEach(function(button){button.addEventListener('click',function(){
      var updates=[];
      productManager.querySelectorAll('[data-bulk-product]').forEach(function(row){updates.push({id:Number(row.dataset.bulkProduct),sale_price:row.querySelector('[data-bulk-price]').value,stock_quantity:row.querySelector('[data-bulk-stock]').value});});
      if(!updates.length){productManagerStatus.textContent='Güncellenecek ürün bulunamadı.';return;}
      if(!confirm(updates.length+' ürünün fiyat ve stok bilgileri kaydedilsin mi?'))return;
      productManager.querySelectorAll('[data-product-bulk-save]').forEach(function(btn){btn.disabled=true;btn.textContent='Kaydediliyor…';});
      productManagerStatus.textContent='';
      var body=new FormData();body.set('action','bulk_update_products');body.set('csrf_token',csrf);body.set('updates_json',JSON.stringify(updates));
      fetch(api,{method:'POST',body:body,credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(d){if(!d.ok)throw new Error(d.error||'Ürünler güncellenemedi.');productManagerStatus.textContent=d.message;setTimeout(function(){location.reload();},700);}).catch(function(error){productManagerStatus.textContent=error.message;productManager.querySelectorAll('[data-product-bulk-save]').forEach(function(btn){btn.disabled=false;btn.textContent='Tüm Değişiklikleri Kaydet';});});
    });});
  }

  setInterval(function(){var d=new Date(),el=root.querySelector('[data-pos-clock]');if(el)el.textContent=d.toLocaleTimeString('tr-TR',{hour:'2-digit',minute:'2-digit'});},1000);setExtraBarcodes('');bindProductRows();render();setTimeout(function(){scan.focus();},250);
})();
