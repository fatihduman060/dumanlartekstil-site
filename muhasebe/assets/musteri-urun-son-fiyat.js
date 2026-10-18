(function(){
  'use strict';
  var cariSelect=document.querySelector('#cariSelect, #wdCari');
  var body=document.querySelector('#offerRows tbody, #wdRows tbody');
  if(!cariSelect||!body) return;
  var form=body.closest('form');
  var currencySelect=form.querySelector('[name="currency"]');
  var products=window.dispatchPriceProducts||[];
  var editing=Number(form.querySelector('input[name="id"]')?.value||0)>0;
  var states=new WeakMap(); // Cloning a row must never clone manual/preserved price flags.
  var defining=new WeakMap();
  var definePending=0;
  var items=[];
  var requestSeq=0;
  var settingPrice=false;
  var pending=null;
  var loadedKey='';
  var status=document.createElement('small');
  status.setAttribute('role','status');
  status.style.cssText='display:block;margin:8px 0;color:#885f0b';
  body.closest('table').parentNode.insertAdjacentElement('afterend',status);

  function norm(v){return String(v||'').trim().replace(/\s+/g,' ').toLocaleUpperCase('tr-TR');}
  function currency(){return currencySelect?currencySelect.value:'TL';}
  function context(){return String(cariSelect.value||0)+'|'+currency();}
  function state(row){if(!states.has(row))states.set(row,{});return states.get(row);}
  function textPrice(v){return String(Number(v)).replace('.',',');}
  function recalc(row){
    settingPrice=true;
    row.querySelector('.price').dispatchEvent(new Event('input',{bubbles:true}));
    settingPrice=false;
  }
  function article(v){
    var value=String(v||''),m=value.match(/86992348([0-9]{4})[0-9]/);
    if(m)return m[1];
    m=value.match(/(?:^|[^0-9])([0-9]{2})\s*[-/.]\s*([0-9]{2})(?:[^0-9]|$)/);
    if(m)return m[1]+m[2];
    m=value.match(/^\s*([0-9]{4})(?:[^0-9]|$)/);return m?m[1]:'';
  }
  function productArticle(p){return article(p.name)||article(p.barcode)||article(p.product_type);}
  function unique(list){return list.length===1?list[0]:null;}
  function match(list,row){
    var barcode=String(row.querySelector('.product-barcode')?.value||'').trim();
    var name=norm(row.querySelector('.product-name')?.value);
    var type=norm(row.querySelector('.product-type')?.value);
    if(barcode){
      var exacts=list.filter(function(p){return String(p.barcode||'').trim()===barcode;});
      if(exacts.length)return unique(exacts);
      if(!/^86992348[0-9]{5}$/.test(barcode)&&!/^\d{4}$/.test(barcode))return null;
    }
    var code=article(name)||article(barcode)||article(type);
    if(code){var coded=list.filter(function(p){return productArticle(p)===code;});if(coded.length)return unique(coded);}
    if(!name)return null;
    var named=list.filter(function(p){return norm(p.name)===name;});
    var typed=unique(named.filter(function(p){return norm(p.product_type)===type;}));
    if(typed)return typed;
    // Do not reuse another variant's price when an explicit variant differs.
    return unique(named.filter(function(p){return !type||!norm(p.product_type);}));
  }
  function customerMatch(row,product){
    var productId=Number(product&&product.id||0);
    if(productId>0){
      return items.find(function(item){return Number(item.product_id||0)===productId;})||null;
    }
    // Serbest/ürün listesinde bulunmayan satırlarda eski davranışı koru.
    return match(items,row);
  }
  function apply(row){
    var price=row.querySelector('.price'),s=state(row);
    if(!price||s.manual||s.preserve)return;
    var product=match(products,row);
    var customer=loadedKey===context()?customerMatch(row,product):null;
    var amount=customer?Number(customer.unit_price):null;
    var source=customer&&customer.source_type==='base'?'Ana fiyat':'Bu müşteriye en son kullanılan fiyat';
    if(amount===null&&currency()==='TL'&&product&&product.list_unit_price!==null&&product.list_unit_price!==undefined){
      amount=Number(product.list_unit_price);source='Ana fiyat';
    }
    price.value=amount!==null&&Number.isFinite(amount)&&amount>0?textPrice(amount):'';
    price.title=price.value!==''?source+': '+price.value+' '+currency():'Bu ürün için fiyat tanımlanmamış.';
    recalc(row);
  }
  function applyAll(){body.querySelectorAll('tr').forEach(apply);}
  function load(){
    var key=context(),seq=++requestSeq,cari=Number(cariSelect.value||0);
    items=[];loadedKey='';status.textContent='';
    if(cari<=0){loadedKey=key;applyAll();pending=null;return Promise.resolve();}

    applyAll();
    status.textContent='Müşteriye özel fiyatlar kontrol ediliyor…';
    pending=fetch('musteri-urun-son-fiyat.php?cari_id='+encodeURIComponent(cari)+'&currency='+encodeURIComponent(currency()),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}})
      .then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
      .then(function(data){
        if(seq!==requestSeq||key!==context())return;
        if(!data||!data.ok)throw new Error('Fiyat geçmişi okunamadı');
        items=Array.isArray(data.items)?data.items:[];loadedKey=key;status.textContent='';applyAll();
      }).catch(function(){if(seq===requestSeq){status.textContent='Müşteriye özel fiyatlar alınamadı. Fiyatları kontrol edin; müşteri seçimini yenileyerek tekrar deneyebilirsiniz.';}})
      .finally(function(){if(seq===requestSeq)pending=null;});
    return pending;
  }
  function chooseProduct(row,source,allowPartial){
    var name=row.querySelector('.product-name'),barcode=row.querySelector('.product-barcode'),type=row.querySelector('.product-type');
    var value=source==='barcode'?String(barcode.value||'').trim():norm(name.value);
    var code=article(value);
    var product=unique(products.filter(function(p){return source==='barcode'?String(p.barcode||'').trim()===value:norm(p.name)===value;}));
    if(!product&&code)product=unique(products.filter(function(p){return productArticle(p)===code;}));
    if(!product&&allowPartial&&source==='name'&&value.length>=3){
      var words=value.split(' ');
      var matches=products.filter(function(p){var label=norm(p.name);return words.every(function(w){return label.includes(w);});});
      if(matches.length===1)product=matches[0];
    }
    if(product){name.value=product.name||'';barcode.value=product.barcode||'';}
    else if(source==='name'){barcode.value='';}
    else if(source==='barcode'){name.value=value;}
    return product;
  }
  function defineProduct(row){
    var name=row.querySelector('.product-name'),barcode=row.querySelector('.product-barcode'),type=row.querySelector('.product-type');
    if(!name.value.trim()||match(products,row))return;
    var signature=[name.value,barcode.value,type.value].join('|');
    if(defining.get(row)===signature)return;
    defining.set(row,signature);definePending++;
    var data=new URLSearchParams({name:name.value,barcode:barcode.value,product_type:type.value,csrf_token:form.querySelector('[name="csrf_token"]').value});
    fetch('urun-tanimla.php',{method:'POST',credentials:'same-origin',body:data})
      .then(function(r){return r.json();}).then(function(result){
        if(!result.ok)throw new Error(result.error||'Ürün tanımlanamadı.');
        var p=result.product;
        if(!products.some(function(x){return Number(x.id)===Number(p.id);}))products.push(p);
        if(signature===[name.value,barcode.value,type.value].join('|')){
          name.value=p.name;barcode.value=p.barcode||'';type.value=p.product_type||'';
          load();
        }
      }).catch(function(error){status.textContent=error.message;})
      .finally(function(){definePending--;defining.delete(row);});
  }
  if(editing)body.querySelectorAll('tr').forEach(function(row){state(row).preserve=true;});
  body.addEventListener('input',function(e){
    var row=e.target.closest('tr');if(!row)return;
    if(e.target.classList.contains('price')&&!settingPrice){state(row).manual=true;state(row).preserve=false;}
  },true);
  body.addEventListener('change',function(e){
    var source=e.target.classList.contains('product-name')?'name':e.target.classList.contains('product-barcode')?'barcode':e.target.classList.contains('product-type')?'type':'';
    if(!source)return;
    var row=e.target.closest('tr');state(row).manual=false;state(row).preserve=false;
    if(source!=='type')chooseProduct(row,source,true);
    // Run after the existing article/barcode normalization listener.
    setTimeout(function(){apply(row);defineProduct(row);if(loadedKey!==context()&&!pending)load();},0);
  });
  function changeCustomerContext(){
    // Cari değişince kullanıcının elle girdiği mevcut fiyatları koru.
    // Otomatik/listeden gelen fiyatlar ise yeni müşterinin geçmiş fiyatına göre güncellenebilir.
    load();
  }
  function changeCurrencyContext(){
    // Para birimi değiştiğinde eski para birimine ait fiyat bayraklarını sıfırla.
    body.querySelectorAll('tr').forEach(function(row){state(row).manual=false;state(row).preserve=false;});
    load();
  }
  cariSelect.addEventListener('change',changeCustomerContext);
  if(currencySelect)currencySelect.addEventListener('change',changeCurrencyContext);
  form.addEventListener('submit',function(e){
    applyAll();
    if(pending||definePending){e.preventDefault();status.textContent='Müşteri fiyatları yükleniyor. Tamamlanınca tekrar Kaydet’e basın.';}
  });
  load();
})();
