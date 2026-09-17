(function(){
  'use strict';
  if(!/\/hareketler\.php$/i.test(location.pathname)) return;

  var form=document.querySelector('form input[name="action"][value="save"]')?.closest('form');
  if(!form) return;
  var cari=form.querySelector('[name="cari_id"]');
  if(!cari) return;

  var cache={};
  var currentItems=[];
  var currentCari=Number(cari.value||0);
  var requestSeq=0;
  var settingPrice=false;
  var editId=Number((form.querySelector('[name="id"]')||{}).value||0);

  function norm(value){
    return String(value||'').trim().replace(/\s+/g,' ').toLocaleUpperCase('tr-TR');
  }

  function priceText(value){
    var n=Number(value||0);
    if(!Number.isFinite(n)||n<=0) return '';
    if(Math.abs(n-Math.round(n))<0.000001) return String(Math.round(n));
    return String(n).replace('.',',');
  }

  function rows(){
    return Array.prototype.slice.call(document.querySelectorAll('.satis-item-row'));
  }

  function matchRow(row){
    var barcode=String((row.querySelector('[data-barcode]')||{}).value||'').trim();
    var name=norm((row.querySelector('[data-name]')||{}).value||'');
    if(!barcode&&!name) return null;

    if(barcode){
      var byBarcode=currentItems.find(function(item){
        return String(item.barcode||'').trim()===barcode;
      });
      if(byBarcode) return byBarcode;
    }
    if(name){
      var byName=currentItems.find(function(item){return norm(item.name)===name;});
      if(byName) return byName;
    }
    return null;
  }

  function recalc(price){
    if(!price) return;
    price.dispatchEvent(new Event('input',{bubbles:true}));
  }

  function applyRow(row,force){
    if(!row) return;
    var price=row.querySelector('[data-price]');
    if(!price) return;
    if(!force&&price.dataset.priceManual==='1') return;

    var match=currentCari>0?matchRow(row):null;
    settingPrice=true;
    if(match&&Number(match.unit_price||0)>0){
      price.value=priceText(match.unit_price);
      price.dataset.customerAuto='1';
      price.title='Bu müşteriye en son kullanılan fiyat: '+price.value;
    }else{
      price.value='';
      delete price.dataset.customerAuto;
      price.title=currentCari>0?'Bu müşteride bu ürün için geçmiş fiyat yok.':'Müşteri seçilince son fiyat otomatik gelir.';
    }
    settingPrice=false;
    recalc(price);
  }

  function applyAll(force){
    rows().forEach(function(row){applyRow(row,!!force);});
  }

  function loadPrices(force){
    var cariId=Number(cari.value||0);
    currentCari=cariId;
    if(cariId<=0){
      currentItems=[];
      if(force) applyAll(true);
      return Promise.resolve([]);
    }
    if(cache[cariId]){
      currentItems=cache[cariId];
      if(force) applyAll(true);
      return Promise.resolve(currentItems);
    }
    var seq=++requestSeq;
    return fetch('musteri-urun-son-fiyat.php?cari_id='+encodeURIComponent(cariId)+'&_='+Date.now(),{
      credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}
    })
      .then(function(response){return response.json();})
      .then(function(data){
        if(seq!==requestSeq) return [];
        if(!data||!data.ok) throw new Error((data&&data.error)||'Müşteri fiyat geçmişi okunamadı.');
        cache[cariId]=Array.isArray(data.items)?data.items:[];
        currentItems=cache[cariId];
        if(force) applyAll(true);
        return currentItems;
      })
      .catch(function(error){
        console.error('Ürünlü satış müşteri fiyatı:',error);
        return [];
      });
  }

  document.addEventListener('input',function(event){
    var price=event.target.closest&&event.target.closest('.satis-item-row [data-price]');
    if(!price||settingPrice) return;
    price.dataset.priceManual='1';
    delete price.dataset.customerAuto;
  },true);

  document.addEventListener('change',function(event){
    var productInput=event.target.closest&&event.target.closest('.satis-item-row [data-barcode], .satis-item-row [data-name]');
    if(!productInput) return;
    var row=productInput.closest('.satis-item-row');
    var price=row?row.querySelector('[data-price]'):null;
    if(price){
      delete price.dataset.priceManual;
      delete price.dataset.customerAuto;
    }
    setTimeout(function(){
      var wanted=Number(cari.value||0);
      if(wanted<=0){applyRow(row,true);return;}
      if(currentCari===wanted&&cache[wanted]) applyRow(row,true);
      else loadPrices(false).then(function(){applyRow(row,true);});
    },0);
  },true);

  cari.addEventListener('change',function(){
    currentCari=Number(cari.value||0);
    rows().forEach(function(row){
      var price=row.querySelector('[data-price]');
      if(price){delete price.dataset.priceManual;delete price.dataset.customerAuto;price.value='';}
    });
    loadPrices(true);
  });

  document.addEventListener('click',function(event){
    var open=event.target.closest&&event.target.closest('.satis-detay-open');
    if(!open) return;
    var wanted=Number(cari.value||0);
    if(wanted<=0||editId>0) return;
    if(currentCari===wanted&&cache[wanted]) applyAll(false);
    else loadPrices(false).then(function(){applyAll(false);});
  },true);

  if(currentCari>0) loadPrices(false);
})();
