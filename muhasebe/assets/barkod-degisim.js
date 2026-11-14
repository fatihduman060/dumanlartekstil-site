(function(){
  'use strict';
  var root=document.querySelector('[data-exchange-root]');
  if(!root)return;
  var lists={returned:[],issued:[]},step='returned',busy=false,sourceToken='';
  var key='dumanlar-pos-exchange-v1';
  var status=root.querySelector('[data-exchange-status]');
  var complete=root.querySelector('[data-exchange-complete]');
  var payment=root.querySelector('[data-exchange-payment]');
  var next=root.querySelector('[data-exchange-next]');
  var removalModal=root.querySelector('[data-exchange-removal-modal]');
  var removalProduct=root.querySelector('[data-exchange-removal-product]');
  var removalReason=root.querySelector('[data-exchange-removal-reason]');
  var removalStatus=root.querySelector('[data-exchange-removal-status]');
  var removalConfirm=root.querySelector('[data-exchange-removal-confirm]');
  var removalCancel=root.querySelector('[data-exchange-removal-cancel]');
  var pendingRemoval=null,removeBusy=false;
  function money(amount){return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(amount||0))+' TL';}
  function esc(value){return String(value==null?'':value).replace(/[&<>"']/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot',"'":'&#039;'}[ch];});}
  function name(product){return String(product.name||'')+(product.variant_name?' - '+product.variant_name:'');}
  function sum(role){return Math.round(lists[role].reduce(function(total,row){return total+Number(row.sale_price)*row.quantity;},0)*100)/100;}
  function token(){if(sourceToken)return sourceToken;sourceToken=window.crypto&&crypto.randomUUID?crypto.randomUUID():'exchange-'+Date.now()+'-'+Math.random().toString(16).slice(2)+Math.random().toString(16).slice(2);save();return sourceToken;}
  function save(){try{sessionStorage.setItem(key,JSON.stringify({lists:lists,step:step,sourceToken:sourceToken}));}catch(ignore){}}
  function restore(){try{var data=JSON.parse(sessionStorage.getItem(key)||'null');if(!data||!data.lists)return;['returned','issued'].forEach(function(role){lists[role]=(Array.isArray(data.lists[role])?data.lists[role]:[]).filter(function(row){return Number.isInteger(Number(row.id))&&Number.isInteger(Number(row.quantity))&&row.quantity>0&&Number(row.sale_price)>0;}).map(function(row){return {id:Number(row.id),name:String(row.name||''),variant_name:String(row.variant_name||''),barcode:String(row.barcode||''),sale_price:Number(row.sale_price),quantity:Number(row.quantity)};});});step=data.step==='issued'&&lists.returned.length?'issued':'returned';sourceToken=/^[a-zA-Z0-9-]{20,100}$/.test(data.sourceToken||'')?data.sourceToken:'';}catch(ignore){sessionStorage.removeItem(key);}}
  function invalidate(){sourceToken='';root.querySelectorAll('input[name="exchange_payment"]').forEach(function(input){input.checked=false;});save();}
  function renderLines(role){
    var box=root.querySelector('[data-exchange-lines="'+role+'"]');
    if(!lists[role].length){box.innerHTML='<p class="exchange-empty">'+(role==='issued'&&step==='returned'?'Önce getirilen ürünleri okutun.':'Henüz ürün okutulmadı.')+'</p>';return;}
    box.innerHTML=lists[role].map(function(row,index){return '<div class="exchange-line"><div class="exchange-line-name"><strong>'+esc(name(row))+'</strong><small>'+esc(row.barcode)+' · '+money(row.sale_price)+'</small></div><div class="exchange-line-qty"><button type="button" data-change="-1" data-role="'+role+'" data-index="'+index+'" aria-label="Adedi azalt">−</button><span>'+row.quantity+'</span><button type="button" data-change="1" data-role="'+role+'" data-index="'+index+'" aria-label="Adedi artır">+</button></div><div class="exchange-line-total">'+money(row.sale_price*row.quantity)+'</div><button type="button" class="exchange-line-remove" data-remove="'+role+'" data-index="'+index+'">Kaldır</button></div>';}).join('');
  }
  function render(){
    if(!lists.returned.length)step='returned';
    ['returned','issued'].forEach(function(role){renderLines(role);root.querySelector('[data-exchange-total="'+role+'"]').textContent=money(sum(role));});
    var issuedEnabled=step==='issued';
    root.querySelector('[data-exchange-section="issued"]').dataset.locked=issuedEnabled?'false':'true';
    root.querySelector('[data-exchange-scan="issued"]').disabled=!issuedEnabled;
    root.querySelector('[data-exchange-search="issued"]').disabled=!issuedEnabled;
    next.disabled=!lists.returned.length||issuedEnabled;
    next.textContent=issuedEnabled?'Yeni ürünleri okutuyorsunuz':'Yeni ürünlere geç →';
    var difference=Math.round((sum('issued')-sum('returned'))*100)/100;
    root.querySelector('[data-exchange-difference]').textContent=money(difference);
    root.querySelector('.exchange-difference').dataset.shortfall=difference<0?'true':'false';
    payment.hidden=difference<=0;
    complete.disabled=busy||!lists.returned.length||!lists.issued.length||difference<0||(difference>0&&!root.querySelector('input[name="exchange_payment"]:checked'));
    if(difference<0&&lists.issued.length)status.textContent='Yeni ürünlere en az '+money(-difference)+' tutarında ürün ekleyin. Para iadesi yapılmaz.';
    else if(status.textContent.indexOf('Para iadesi yapılmaz.')!==-1)status.textContent='';
    save();
  }
  function add(role,product){
    var id=Number(product.id),price=Number(product.sale_price);
    if(!Number.isInteger(id)||id<1||!Number.isFinite(price)||price<=0){status.textContent='Bu ürünün geçerli satış fiyatı yok.';return;}
    var existing=lists[role].find(function(row){return row.id===id;});
    if(existing){if(existing.quantity>=9999){status.textContent='Bir üründen en çok 9999 adet eklenebilir.';return;}existing.quantity++;}
    else lists[role].push({id:id,name:String(product.name||''),variant_name:String(product.variant_name||''),barcode:String(product.matched_barcode||product.barcode||''),sale_price:price,quantity:1});
    invalidate();status.textContent=name(product)+' eklendi.';
    var input=root.querySelector('[data-exchange-scan="'+role+'"]');input.value='';
    root.querySelector('[data-exchange-results="'+role+'"]').hidden=true;
    render();input.focus();
  }
  function readJson(url){return fetch(url,{credentials:'same-origin',cache:'no-store'}).then(function(response){return response.json().then(function(data){if(!response.ok||!data.ok)throw new Error(data.error||'Ürün alınamadı.');return data;});});}
  function openRemoval(role,index){
    var row=lists[role]&&lists[role][index];
    if(!row||!removalModal)return;
    pendingRemoval={role:role,index:index,productId:row.id,quantity:row.quantity};
    removalProduct.textContent=name(row)+' · '+row.quantity+' adet';
    removalReason.value='';
    removalStatus.textContent='';
    removalConfirm.disabled=false;
    removalModal.hidden=false;
    setTimeout(function(){removalReason.focus();},30);
  }
  function closeRemoval(){
    if(!removalModal)return;
    removalModal.hidden=true;
    pendingRemoval=null;
    removeBusy=false;
    removalConfirm.disabled=false;
    removalStatus.textContent='';
    var focusRole=step==='issued'?'issued':'returned';
    var scan=root.querySelector('[data-exchange-scan="'+focusRole+'"]');
    if(scan&&!scan.disabled)scan.focus();
  }
  function saveRemoval(){
    if(removeBusy||!pendingRemoval)return;
    var reason=removalReason.value.trim();
    if(Array.from(reason).length<6){removalStatus.textContent='Silme nedeni en az 6 karakter olmalıdır.';removalReason.focus();return;}
    var row=lists[pendingRemoval.role]&&lists[pendingRemoval.role][pendingRemoval.index];
    if(!row||Number(row.id)!==Number(pendingRemoval.productId)){removalStatus.textContent='Ürün listesi değişti. Pencereyi kapatıp tekrar deneyin.';return;}
    removeBusy=true;removalConfirm.disabled=true;removalStatus.textContent='Kaydediliyor…';
    var body=new FormData();
    body.set('action','log_exchange_remove');
    body.set('csrf_token',root.dataset.csrf);
    body.set('product_id',String(row.id));
    body.set('quantity',String(row.quantity));
    body.set('exchange_role',pendingRemoval.role);
    body.set('removal_reason',reason);
    fetch(root.dataset.api,{method:'POST',body:body,credentials:'same-origin',cache:'no-store'}).then(function(response){
      return response.json().then(function(data){if(!response.ok||!data.ok)throw new Error(data.error||'Silme işlemi kaydedilemedi.');return data;});
    }).then(function(data){
      var current=lists[pendingRemoval.role]&&lists[pendingRemoval.role][pendingRemoval.index];
      if(current&&Number(current.id)===Number(pendingRemoval.productId))lists[pendingRemoval.role].splice(pendingRemoval.index,1);
      invalidate();
      status.textContent=data.message||'Ürün değişim sepetinden silindi.';
      render();
      closeRemoval();
    }).catch(function(error){
      removeBusy=false;removalConfirm.disabled=false;removalStatus.textContent=error.message||'Ürün silinemedi.';
    });
  }
  function search(role){
    if(role==='issued'&&step!=='issued')return;
    var input=root.querySelector('[data-exchange-scan="'+role+'"]'),query=input.value.trim();if(!query)return;
    var results=root.querySelector('[data-exchange-results="'+role+'"]');results.hidden=true;status.textContent='Ürün aranıyor…';
    var api=root.dataset.productApi;
    readJson(api+'?action=barcode&barcode='+encodeURIComponent(query)).then(function(data){
      if(data.product){add(role,data.product);return null;}
      return readJson(api+'?action=products&q='+encodeURIComponent(query)).then(function(found){
        var items=found.products||[];
        if(!items.length){status.textContent='Ürün bulunamadı.';return;}
        results._items=items;
        results.innerHTML=items.map(function(product,index){return '<button type="button" data-result="'+index+'"><span><b>'+esc(name(product))+'</b><small>'+esc(product.matched_barcode||product.barcode)+'</small></span><strong>'+money(product.sale_price)+'</strong></button>';}).join('');
        results.hidden=false;status.textContent='Ürünü seçin.';
      });
    }).catch(function(error){status.textContent=error.message||'Ürün aranamadı.';});
  }
  ['returned','issued'].forEach(function(role){
    var input=root.querySelector('[data-exchange-scan="'+role+'"]');
    input.addEventListener('keydown',function(event){if(event.key==='Enter'){event.preventDefault();search(role);}});
    root.querySelector('[data-exchange-search="'+role+'"]').addEventListener('click',function(){search(role);});
    root.querySelector('[data-exchange-results="'+role+'"]').addEventListener('click',function(event){var button=event.target.closest('[data-result]');if(!button)return;var item=this._items[Number(button.dataset.result)];if(item)add(role,item);});
  });
  root.addEventListener('click',function(event){
    var remove=event.target.closest('[data-remove]');
    if(remove){openRemoval(remove.dataset.remove,Number(remove.dataset.index));return;}
    var change=event.target.closest('[data-change]');
    if(change){var row=lists[change.dataset.role][Number(change.dataset.index)];if(!row)return;row.quantity=Math.max(1,Math.min(9999,row.quantity+Number(change.dataset.change)));invalidate();status.textContent='';render();}
  });
  if(removalConfirm)removalConfirm.addEventListener('click',saveRemoval);
  if(removalCancel)removalCancel.addEventListener('click',closeRemoval);
  if(removalModal)removalModal.addEventListener('click',function(event){if(event.target===removalModal)closeRemoval();});
  document.addEventListener('keydown',function(event){if(!removalModal||removalModal.hidden)return;if(event.key==='Escape'){event.preventDefault();closeRemoval();}});
  next.addEventListener('click',function(){if(!lists.returned.length)return;step='issued';status.textContent='Yeni verilecek ürünleri okutun.';render();root.querySelector('[data-exchange-scan="issued"]').focus();});
  root.querySelectorAll('input[name="exchange_payment"]').forEach(function(input){input.addEventListener('change',render);});
  complete.addEventListener('click',function(){
    if(complete.disabled||busy)return;
    var difference=Math.round((sum('issued')-sum('returned'))*100)/100;
    var selected=root.querySelector('input[name="exchange_payment"]:checked');
    if(difference<0||difference>0&&!selected)return;
    busy=true;render();complete.textContent='Değişim kaydediliyor…';status.textContent='';
    var receipt=window.open('about:blank','_blank','width=430,height=760');
    var body=new FormData();body.set('action','complete_exchange');body.set('csrf_token',root.dataset.csrf);body.set('source_token',token());body.set('payment_method',difference===0?'none':selected.value);
    ['returned','issued'].forEach(function(role){body.set(role+'_json',JSON.stringify(lists[role].map(function(row){return {product_id:row.id,quantity:row.quantity,unit_price:row.sale_price};})));});
    fetch(root.dataset.api,{method:'POST',body:body,credentials:'same-origin',cache:'no-store'}).then(function(response){return response.json().then(function(data){if(!response.ok||!data.ok)throw new Error(data.error||'Değişim kaydedilemedi.');return data;});}).then(function(data){
      var url=data.receipt_url+'&print=1&autoclose=1';
      if(receipt)receipt.location.replace(url);
      status.textContent=data.message+' '+data.receipt_no+' · ';
      var link=document.createElement('a');link.href=data.receipt_url;link.target='_blank';link.rel='noopener';link.textContent='Fişi yeniden aç';status.appendChild(link);
      lists={returned:[],issued:[]};step='returned';sourceToken='';try{sessionStorage.removeItem(key);}catch(ignore){}
      root.querySelectorAll('input[name="exchange_payment"]').forEach(function(input){input.checked=false;});
      root.querySelector('[data-exchange-scan="returned"]').value='';
      root.querySelector('[data-exchange-scan="issued"]').value='';
      var history=root.querySelector('.exchange-history');
      if(history){var link2=document.createElement('a');link2.href=data.receipt_url;link2.target='_blank';link2.rel='noopener';link2.textContent=data.receipt_no+' · Fişi aç ↗';history.insertBefore(link2,history.children[1]||null);}
    }).catch(function(error){
      if(receipt)receipt.close();
      if(error.message&&error.message.indexOf('fiyatı değişmiş')!==-1){lists={returned:[],issued:[]};step='returned';sourceToken='';try{sessionStorage.removeItem(key);}catch(ignore){}}
      if(error.message&&error.message.indexOf('kaydedilip iptal edilmiş')!==-1)sourceToken='';
      status.textContent=error.message||'Değişim kaydedilemedi.';
    }).finally(function(){busy=false;complete.textContent='Değişimi Tamamla ve Fiş Yazdır';render();});
  });
  restore();render();
})();
