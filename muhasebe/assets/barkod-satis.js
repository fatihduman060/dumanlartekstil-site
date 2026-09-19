(function(){
  var root=document.querySelector('[data-pos-root]');if(!root)return;
  var api=root.dataset.api,csrf=root.dataset.csrf,cart=[],scan=root.querySelector('[data-pos-scan]'),cartBox=root.querySelector('[data-pos-cart]'),results=root.querySelector('[data-pos-results]'),discount=root.querySelector('[data-pos-discount]'),status=root.querySelector('[data-pos-status]'),noteInput=root.querySelector('[data-pos-note]');
  var lookupBusy=false,lastLookup='',lastAddedProductId=null,quantityShortcutTimer=null,lookupRevision=0;
  var lastNotFoundAnnouncement=0,saleRequestToken='';
  function speakStatus(text){
    if(!('speechSynthesis' in window)||typeof SpeechSynthesisUtterance==='undefined')return;
    try{
      window.speechSynthesis.cancel();
      var message=new SpeechSynthesisUtterance(text);
      message.lang='tr-TR';message.rate=1;message.volume=1;
      window.speechSynthesis.speak(message);
    }catch(ignore){}
  }
  function announceProductNotFound(){
    if(/^\+/.test(scan.value.trim()))return;
    var now=Date.now();if(now-lastNotFoundAnnouncement<1200)return;lastNotFoundAnnouncement=now;
    speakStatus('Ürün bulunamadı');
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

  var priceCheck=document.querySelector('[data-price-check]'),priceCheckInput=priceCheck&&priceCheck.querySelector('[data-price-check-input]'),priceCheckResults=priceCheck&&priceCheck.querySelector('[data-pos-price-check-results]'),priceCheckStatus=priceCheck&&priceCheck.querySelector('[data-price-check-status]'),priceCheckItems=[];
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
  function esc(s){return String(s||'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot',"'":'&#039;'}[c];});}
  function productName(p){var name=String((p&&p.name)||'').trim(),variant=String((p&&p.variant_name)||'').trim();return variant?name+' - '+variant:name;}
  var cartStorageKey='dumanlar-pos-cart-v1';
  function persistedItem(item){
    if(!item)return null;
    var id=Number(item.id),quantity=Number(item.quantity),price=Number(item.sale_price);
    if(!Number.isFinite(id)||id<=0||!Number.isFinite(quantity)||quantity<=0||!Number.isFinite(price)||price<0)return null;
    return {
      id:id,
      barcode:String(item.barcode||''),
      name:String(item.name||''),
      variant_name:String(item.variant_name||''),
      sale_price:price,
      vat_rate:Number(item.vat_rate||0),
      stock_quantity:Number(item.stock_quantity||0),
      track_stock:Number(item.track_stock||0),
      quantity:quantity
    };
  }
  function persistCart(){
    try{
      if(!window.sessionStorage)return;
      if(!cart.length){sessionStorage.removeItem(cartStorageKey);return;}
      sessionStorage.setItem(cartStorageKey,JSON.stringify({
        items:cart.map(persistedItem).filter(Boolean),
        discount_amount:Number(discount.value||0),
        note:noteInput?String(noteInput.value||''):'',
        source_token:saleRequestToken||'',
        saved_at:Date.now()
      }));
    }catch(ignore){}
  }
  function restorePersistedCart(){
    try{
      if(!window.sessionStorage)return;
      var raw=sessionStorage.getItem(cartStorageKey);if(!raw)return;
      var data=JSON.parse(raw),items=Array.isArray(data.items)?data.items.map(persistedItem).filter(Boolean):[];
      if(!items.length){sessionStorage.removeItem(cartStorageKey);return;}
      cart=items;
      if(discount)discount.value=String(Math.max(0,Number(data.discount_amount||0)));
      if(noteInput)noteInput.value=String(data.note||'').slice(0,160);
      saleRequestToken=/^[a-zA-Z0-9-]{20,100}$/.test(String(data.source_token||''))?String(data.source_token):'';
      status.textContent='Sayfa yenilendi; önceki sepet geri yüklendi.';
    }catch(ignore){try{sessionStorage.removeItem(cartStorageKey);}catch(ignore2){}}
  }
  function invalidateSaleRequest(){saleRequestToken='';persistCart();}
  function ensureSaleRequestToken(){
    if(/^[a-zA-Z0-9-]{20,100}$/.test(saleRequestToken))return saleRequestToken;
    if(window.crypto&&typeof window.crypto.randomUUID==='function')saleRequestToken=window.crypto.randomUUID();
    else saleRequestToken='pos-'+Date.now()+'-'+Math.random().toString(16).slice(2)+Math.random().toString(16).slice(2);
    persistCart();
    return saleRequestToken;
  }
  function total(){var raw=cart.reduce(function(s,x){return s+x.quantity*Number(x.sale_price);},0);return Math.max(0,raw-Number(discount.value||0));}
  function render(){
    root.dispatchEvent(new CustomEvent('pos:cart-changed',{detail:{items:cart.map(function(x){return {product_id:x.id,quantity:x.quantity};}),discount_amount:Number(discount.value||0)}}));
    if(!cart.length)cartBox.innerHTML='<div class="pos-empty">Henüz ürün okutulmadı.</div>';
    else cartBox.innerHTML=cart.map(function(x,i){var latest=Number(x.id)===Number(lastAddedProductId);return '<div class="pos-cart-row'+(latest?' pos-cart-row-latest':'')+'"'+(latest?' data-pos-last-added="true"':'')+'><div class="pos-cart-product"><strong>'+esc(productName(x))+'</strong><small>'+esc(x.barcode)+'</small></div><div class="pos-qty"><button type="button" data-minus="'+i+'">−</button><input type="number" min="0.01" step="1" value="'+x.quantity+'" data-qty="'+i+'"><button type="button" data-plus="'+i+'">+</button></div><span class="pos-unit">Adet</span><span class="pos-unit-price">'+money(x.sale_price)+'</span><span class="pos-line-discount">—</span><span class="pos-line-tax">%'+Number(x.vat_rate||0)+'</span><strong class="pos-line-total">'+money(x.quantity*Number(x.sale_price))+'</strong><button type="button" class="pos-remove" data-remove="'+i+'">×</button></div>';}).join('');
    root.querySelector('[data-pos-total]').textContent=money(total());root.querySelector('[data-pos-count]').textContent=cart.reduce(function(s,x){return s+Number(x.quantity);},0)+' ürün';persistCart();
  }
  function revealLastAdded(){setTimeout(function(){var row=cartBox.querySelector('[data-pos-last-added]');if(row)row.scrollIntoView({behavior:'smooth',block:'center'});},80);}
  function add(p){invalidateSaleRequest();var old=cart.find(function(x){return Number(x.id)===Number(p.id);});if(old)old.quantity+=1;else{p.quantity=1;cart.push(p);}lastAddedProductId=Number(p.id);render();scan.value='';lastLookup='';scan.focus({preventScroll:true});status.textContent=productName(p)+' sepete eklendi.';revealLastAdded();}
  function addToLastProduct(amount){
    if(quantityShortcutTimer)clearTimeout(quantityShortcutTimer);
    quantityShortcutTimer=null;lookupRevision++;
    root.dispatchEvent(new CustomEvent('pos:quantity-shortcut'));
    if(!Number.isSafeInteger(amount)||amount<1){scan.value='';status.textContent='Eklenecek adet pozitif bir tam sayı olmalı.';return;}
    var item=cart.find(function(x){return Number(x.id)===Number(lastAddedProductId);});
    if(!item){status.textContent='Önce bir ürün okutun.';speakStatus(status.textContent);scan.value='';return;}
    invalidateSaleRequest();item.quantity+=amount;scan.value='';lastLookup='';results.hidden=true;results.innerHTML='';render();scan.focus({preventScroll:true});var message=amount+' adet eklendi. Sepette bu üründen '+new Intl.NumberFormat('tr-TR').format(item.quantity)+' adet oldu.';status.textContent=productName(item)+': '+message;speakStatus(amount+' tane eklendi.');revealLastAdded();
  }
  root.addEventListener('pos:add-product',function(event){if(event.detail)add(event.detail);});
  function showResults(items){results.hidden=false;if(!items.length){results.innerHTML='<div class="pos-result-empty">Ürün bulunamadı. Aşağıdan yeni ürün tanımlayabilirsiniz.</div>';root.dispatchEvent(new CustomEvent('pos:product-not-found'));return;}results.innerHTML=items.map(function(p){return '<button type="button" data-result-id="'+p.id+'"><span><strong>'+esc(productName(p))+'</strong><small>'+esc(p.matched_barcode||p.barcode)+' · Stok: '+Number(p.stock_quantity)+'</small></span><strong>'+money(p.sale_price)+'</strong></button>';}).join('');results._items=items;}
  function lookup(){var q=scan.value.trim();if(/^\+/.test(q)){var shortcut=q.match(/^\+(\d+)$/);if(shortcut)addToLastProduct(Number(shortcut[1]));return;}if(!q||lookupBusy||q===lastLookup)return;var revision=++lookupRevision;lookupBusy=true;lastLookup=q;status.textContent='Barkod aranıyor…';fetch(api+'?action=barcode&barcode='+encodeURIComponent(q)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(d){if(revision!==lookupRevision||scan.value.trim()!==q)return null;if(d.product){add(d.product);results.hidden=true;return null;}return fetch(api+'?action=products&q='+encodeURIComponent(q)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(x){if(revision!==lookupRevision||scan.value.trim()!==q)return;showResults(x.products||[]);status.textContent='';});}).catch(function(){if(revision!==lookupRevision||scan.value.trim()!==q)return;status.textContent='Ürün aranamadı.';lastLookup='';}).finally(function(){lookupBusy=false;});}
  scan.addEventListener('input',function(){
    lookupRevision++;
    if(quantityShortcutTimer)clearTimeout(quantityShortcutTimer);
    var shortcut=scan.value.trim().match(/^\+(\d+)$/);if(!shortcut)return;
    quantityShortcutTimer=setTimeout(function(){var current=scan.value.trim().match(/^\+(\d+)$/);if(current)addToLastProduct(Number(current[1]));},350);
  });
  scan.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();var shortcut=scan.value.trim().match(/^\+(\d+)$/);if(shortcut){if(quantityShortcutTimer)clearTimeout(quantityShortcutTimer);addToLastProduct(Number(shortcut[1]));return;}lastLookup='';lookup();}});
  scan.addEventListener('change',function(){if(scan.value.trim()){lastLookup='';lookup();}});
  root.querySelector('[data-pos-search]').onclick=function(){lastLookup='';lookup();};
  results.addEventListener('click',function(e){var b=e.target.closest('[data-result-id]');if(!b)return;var p=(results._items||[]).find(function(x){return Number(x.id)===Number(b.dataset.resultId);});if(p){add(p);results.hidden=true;}});
  function cartEventPayload(eventType,items,reason){
    var body=new FormData();body.set('action','cart_event');body.set('csrf_token',csrf);body.set('event_type',eventType);if(reason)body.set('removal_reason',reason);body.set('items_json',JSON.stringify(items.map(function(x){return {product_id:x.id,quantity:x.quantity};})));body.set('discount_amount',discount.value||0);var payment=root.querySelector('input[name="pos_payment"]:checked');body.set('payment_method',payment?payment.value:'cash');return body;
  }
  function logCartEvent(eventType,items,reason){
    return fetch(api,{method:'POST',body:cartEventPayload(eventType,items,reason),credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(d){if(!d.ok)throw new Error(d.error||'Sepet işlemi kaydedilemedi.');return d;});
  }
  var removalModal=root.querySelector('[data-pos-removal-modal]'),removalReason=root.querySelector('[data-pos-removal-reason]'),removalStatus=root.querySelector('[data-pos-removal-status]'),removalConfirm=root.querySelector('[data-pos-removal-confirm]'),removalCancel=root.querySelector('[data-pos-removal-cancel]'),removalItem=null,removalMode='item',removalNewQuantity=null;
  function openRemoval(mode,item,newQuantity){
    removalMode=mode;removalItem=item||null;removalNewQuantity=typeof newQuantity==='number'?newQuantity:null;removalReason.value='';removalStatus.textContent='';
    removalConfirm.disabled=false;
    if(mode==='cart'){
      root.querySelector('#posRemovalTitle').textContent='Sepeti temizle';
      root.querySelector('[data-pos-removal-product]').textContent='Sepetteki tüm ürünler kaldırılacak. Açıklama zorunludur.';
      removalConfirm.textContent='Sepeti Temizle';
    }else if(mode==='quantity'){
      root.querySelector('#posRemovalTitle').textContent='Ürün adedini azalt';
      root.querySelector('[data-pos-removal-product]').textContent=productName(removalItem)+' · '+removalItem.quantity+' → '+removalNewQuantity;
      removalConfirm.textContent='Adedi Azalt';
    }else{
      root.querySelector('#posRemovalTitle').textContent='Ürünü sepetten sil';
      root.querySelector('[data-pos-removal-product]').textContent=productName(removalItem);
      removalConfirm.textContent='Ürünü Sil';
    }
    removalModal.hidden=false;removalReason.focus();
  }
  function closeRemoval(){removalModal.hidden=true;removalItem=null;removalMode='item';removalNewQuantity=null;removalConfirm.disabled=false;removalConfirm.textContent='Ürünü Sil';scan.focus();}
  removalCancel.onclick=closeRemoval;
  removalModal.addEventListener('click',function(e){if(e.target===removalModal)closeRemoval();});
  document.addEventListener('keydown',function(e){
    if(removalModal.hidden)return;
    if(e.key==='Escape'){e.preventDefault();e.stopImmediatePropagation();closeRemoval();}
    if(/^F[0-9]+$/.test(e.key)){e.preventDefault();e.stopImmediatePropagation();}
    if(e.key==='Tab'){
      if(e.shiftKey&&document.activeElement===removalReason){e.preventDefault();removalCancel.focus();}
      else if(!e.shiftKey&&document.activeElement===removalCancel){e.preventDefault();removalReason.focus();}
    }
  },true);
  removalConfirm.onclick=function(){
    var reason=removalReason.value.trim();
    if(Array.from(reason).length<6){removalStatus.textContent='Silme nedeni en az 6 karakter olmalıdır.';removalReason.focus();return;}
    removalConfirm.disabled=true;removalStatus.textContent='Kaydediliyor…';
    if(removalMode==='cart'){
      var snapshot=cart.slice();
      if(!snapshot.length){closeRemoval();return;}
      logCartEvent('cart_cleared',snapshot,reason).then(function(d){
        cart=[];discount.value=0;if(noteInput)noteInput.value='';render();status.textContent=d.message;closeRemoval();
      }).catch(function(error){
        removalStatus.textContent='Sepet temizlenmedi: '+error.message;removalConfirm.disabled=false;
      });
      return;
    }
    var i=cart.indexOf(removalItem);if(i<0){closeRemoval();return;}
    var item=removalItem;
    if(removalMode==='quantity'){
      var target=Math.max(.01,Number(removalNewQuantity||0)),removedQuantity=Math.round((Number(item.quantity)-target)*1000)/1000;
      if(removedQuantity<=0){closeRemoval();return;}
      var removedItem=Object.assign({},item,{quantity:removedQuantity});
      logCartEvent('item_removed',[removedItem],reason).then(function(d){
        var currentIndex=cart.indexOf(item);if(currentIndex>=0){invalidateSaleRequest();cart[currentIndex].quantity=target;}render();status.textContent=d.message;closeRemoval();
      }).catch(function(error){
        removalStatus.textContent='Adet azaltılmadı: '+error.message;removalConfirm.disabled=false;
      });
      return;
    }
    logCartEvent('item_removed',[item],reason).then(function(d){
      var currentIndex=cart.indexOf(item);if(currentIndex>=0){invalidateSaleRequest();cart.splice(currentIndex,1);}render();status.textContent=d.message;closeRemoval();
    }).catch(function(error){
      removalStatus.textContent='Ürün silinmedi: '+error.message;removalConfirm.disabled=false;
    });
  };
  cartBox.addEventListener('click',function(e){
    var b=e.target.closest('button');if(!b)return;
    var i=Number(b.dataset.minus||b.dataset.plus||b.dataset.remove);
    if(b.hasAttribute('data-minus')){
      if(Number(cart[i].quantity)>1){openRemoval('quantity',cart[i],Math.max(1,Number(cart[i].quantity)-1));return;}
      return;
    }
    if(b.hasAttribute('data-plus')){invalidateSaleRequest();cart[i].quantity+=1;}
    if(b.hasAttribute('data-remove')){openRemoval('item',cart[i]);return;}
    render();
  });
  cartBox.addEventListener('change',function(e){
    if(!e.target.hasAttribute('data-qty'))return;
    var i=Number(e.target.dataset.qty),oldQuantity=Number(cart[i].quantity),newQuantity=Math.max(.01,Number(e.target.value||1));
    if(newQuantity<oldQuantity){e.target.value=oldQuantity;openRemoval('quantity',cart[i],newQuantity);return;}
    if(newQuantity!==oldQuantity){invalidateSaleRequest();cart[i].quantity=newQuantity;render();}
  });
  discount.addEventListener('input',function(){invalidateSaleRequest();render();syncSplitAmounts();});if(noteInput)noteInput.addEventListener('input',function(){invalidateSaleRequest();persistCart();});root.querySelector('[data-pos-clear]').onclick=function(){if(!cart.length)return;openRemoval('cart',null);};
  root.querySelectorAll('input[name="pos_payment"]').forEach(function(r){r.addEventListener('change',function(){root.querySelector('[data-pos-person-wrap]').hidden=this.value!=='credit';if(splitWrap)splitWrap.hidden=this.value!=='mixed';if(this.value==='mixed')syncSplitAmounts();var customer=root.querySelector('[data-pos-customer-name]');if(customer)customer.textContent=this.value==='credit'?'Veresiye Müşterisi':'Perakende Müşteri';var paymentMessage=root.querySelector('[data-pos-payment-status]');if(paymentMessage)paymentMessage.textContent='';});});
  var personSelect=root.querySelector('[data-pos-person]');if(personSelect)personSelect.addEventListener('change',function(){var customer=root.querySelector('[data-pos-customer-name]');if(customer&&this.value)customer.textContent=this.options[this.selectedIndex].text;});
  var paymentModal=root.querySelector('[data-pos-payment-modal]'),completeButton=root.querySelector('[data-pos-complete]'),paymentConfirm=root.querySelector('[data-pos-payment-confirm]'),paymentStatus=root.querySelector('[data-pos-payment-status]'),splitWrap=root.querySelector('[data-pos-split-wrap]'),splitCash=root.querySelector('[data-pos-split-cash]'),splitCard=root.querySelector('[data-pos-split-card]'),splitTotal=root.querySelector('[data-pos-split-total]');
  function syncSplitAmounts(){
    if(!splitWrap)return;
    var grand=Math.round(total()*100)/100,cash=Number(splitCash&&splitCash.value||0);
    if(!Number.isFinite(cash)||cash<0)cash=0;
    var card=Math.max(0,Math.round((grand-cash)*100)/100);
    if(splitCard)splitCard.value=card.toFixed(2);
    if(splitTotal)splitTotal.textContent=money(grand);
  }
  if(splitCash)splitCash.addEventListener('input',syncSplitAmounts);
  function closePaymentModal(){paymentModal.hidden=true;}
  completeButton.onclick=function(){
    if(!cart.length){status.textContent='Önce sepete ürün ekleyin.';return;}
    root.querySelectorAll('input[name="pos_payment"]').forEach(function(input){input.checked=false;});
    personSelect.value='';root.querySelector('[data-pos-person-wrap]').hidden=true;if(splitWrap)splitWrap.hidden=true;if(splitCash)splitCash.value='';if(splitCard)splitCard.value='';root.querySelector('[data-pos-customer-name]').textContent='Perakende Müşteri';paymentModal.hidden=false;status.textContent='';paymentStatus.textContent='';syncSplitAmounts();
  };
  root.querySelector('[data-pos-payment-close]').onclick=closePaymentModal;
  paymentModal.addEventListener('click',function(event){if(event.target===paymentModal)closePaymentModal();});
  paymentConfirm.onclick=function(){
    var selected=root.querySelector('input[name="pos_payment"]:checked');
    if(!selected){paymentStatus.textContent='Ödeme şeklini seçin.';return;}
    var pay=selected.value,person=personSelect.value;if(pay==='credit'&&!person){paymentStatus.textContent='Veresiye için personel seçin.';personSelect.focus();return;}if(pay==='mixed'){syncSplitAmounts();var grand=Math.round(total()*100)/100,cashPart=Number(splitCash&&splitCash.value||0),cardPart=Number(splitCard&&splitCard.value||0);if(!Number.isFinite(cashPart)||cashPart<=0){paymentStatus.textContent='Nakit tutarını yazın.';if(splitCash)splitCash.focus();return;}if(cashPart>=grand||cardPart<=0){paymentStatus.textContent='Bölünmüş ödemede hem nakit hem kredi kartı tutarı sıfırdan büyük olmalıdır.';if(splitCash)splitCash.focus();return;}}
    var receiptWindow=window.open('about:blank','pos_receipt','width=430,height=760');var btn=this;btn.disabled=true;btn.textContent='Satış kaydediliyor…';status.textContent='';var body=new FormData();body.set('action','complete_sale');body.set('csrf_token',csrf);body.set('source_token',ensureSaleRequestToken());body.set('items_json',JSON.stringify(cart.map(function(x){return {product_id:x.id,quantity:x.quantity};})));body.set('payment_method',pay);body.set('person_id',pay==='credit'?person:'');if(pay==='mixed'){body.set('cash_amount',String(cashPart));body.set('card_amount',String(cardPart));}body.set('discount_amount',discount.value||0);body.set('note',root.querySelector('[data-pos-note]').value||'');
    fetch(api,{method:'POST',body:body,credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(d){if(!d.ok)throw new Error(d.error||'Satış kaydedilemedi.');status.textContent=d.message;cart=[];discount.value=0;lastAddedProductId=null;saleRequestToken='';render();closePaymentModal();btn.disabled=false;btn.textContent='Seçimi Onayla ve Satışı Tamamla';var noteInput=root.querySelector('[data-pos-note]');if(noteInput)noteInput.value='';if(receiptWindow){var printUrl=d.receipt_url+'&print=1&autoclose=1';try{receiptWindow.location.replace(printUrl);}catch(ignore){receiptWindow.location.href=printUrl;}}else{window.open(d.receipt_url+'&print=1&autoclose=1','_blank');}root.dispatchEvent(new CustomEvent('pos:sale-completed',{detail:d}));scan.value='';scan.focus({preventScroll:true});}).catch(function(e){if(receiptWindow)receiptWindow.close();paymentStatus.textContent=e.message;btn.disabled=false;btn.textContent='Seçimi Onayla ve Satışı Tamamla';});
  };
  var historyBox=root.querySelector('.pos-history-list');
  if(historyBox)historyBox.addEventListener('click',function(e){var button=e.target.closest('[data-sale-delete]');if(!button)return;e.preventDefault();e.stopPropagation();var receipt=button.dataset.receiptNo||'',saleId=button.dataset.saleDelete,cancelReason=prompt(receipt+' numaralı satış neden iptal ediliyor? (En az 6 karakter)');if(cancelReason===null)return;cancelReason=cancelReason.trim();if(Array.from(cancelReason).length<6){status.textContent='Satış iptal nedeni en az 6 karakter olmalıdır.';return;}button.disabled=true;button.textContent='İptal ediliyor…';var body=new FormData();body.set('action','delete_sale');body.set('csrf_token',csrf);body.set('sale_id',saleId);body.set('cancel_reason',cancelReason);fetch(api,{method:'POST',body:body,credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(d){if(!d.ok)throw new Error(d.error||'Satış silinemedi.');status.textContent=d.message;location.reload();}).catch(function(error){status.textContent=error.message;button.disabled=false;button.textContent='Sil';});});
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
  function updateClock(){var d=new Date(),el=root.querySelector('[data-pos-clock]');if(el)el.textContent=d.toLocaleTimeString('tr-TR',{hour:'2-digit',minute:'2-digit'});}
  updateClock();setInterval(updateClock,1000);setExtraBarcodes('');bindProductRows();restorePersistedCart();render();setTimeout(function(){scan.focus();},250);
})();
