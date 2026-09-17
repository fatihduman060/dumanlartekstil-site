(function(){
  'use strict';

  var cariSelect=document.querySelector('#cariSelect, #wdCari');
  var body=document.querySelector('#offerRows tbody, #wdRows tbody');
  if(!cariSelect||!body) return;

  var editing=Number(document.querySelector('input[name="id"]')?.value||0)>0;
  var cache={};
  var currentItems=[];
  var currentCari=0;
  var settingPrice=false;
  var requestSeq=0;

  function norm(value){
    return String(value||'').trim().replace(/\s+/g,' ').toLocaleUpperCase('tr-TR');
  }

  function priceText(value){
    var n=Number(value||0);
    if(!Number.isFinite(n)||n<=0) return '';
    if(Math.abs(n-Math.round(n))<0.000001) return String(Math.round(n));
    return String(n).replace('.',',');
  }

  function triggerRecalc(row){
    var price=row?.querySelector('.price');
    if(!price) return;
    settingPrice=true;
    price.dispatchEvent(new Event('input',{bubbles:true}));
    settingPrice=false;
  }

  function matchForRow(row){
    var barcode=String(row.querySelector('.product-barcode')?.value||'').trim();
    var name=norm(row.querySelector('.product-name')?.value||'');
    var type=norm(row.querySelector('.product-type')?.value||'');
    if(!barcode&&!name) return null;

    if(barcode){
      var byBarcode=currentItems.find(function(item){
        return String(item.barcode||'').trim()===barcode;
      });
      if(byBarcode) return byBarcode;
    }

    if(name&&type){
      var byNameType=currentItems.find(function(item){
        return norm(item.name)===name&&norm(item.product_type)===type;
      });
      if(byNameType) return byNameType;
    }

    if(name){
      var byName=currentItems.find(function(item){return norm(item.name)===name;});
      if(byName) return byName;
    }
    return null;
  }

  function applyRow(row){
    if(!row) return;
    var price=row.querySelector('.price');
    if(!price) return;
    if(price.dataset.priceManual==='1'||price.dataset.pricePreserve==='1') return;

    var match=currentCari>0?matchForRow(row):null;
    settingPrice=true;
    if(match&&Number(match.unit_price||0)>0){
      price.value=priceText(match.unit_price);
      price.dataset.customerAuto='1';
      price.title='Bu müşteriye en son kullanılan fiyat: '+price.value;
    }else{
      // Ürünün genel/default fiyatını kullanma. Bu müşteride geçmiş yoksa boş kalsın.
      price.value='';
      delete price.dataset.customerAuto;
      price.title=currentCari>0?'Bu müşteride bu ürün için geçmiş fiyat yok.':'Müşteri seçilince son fiyat otomatik gelir.';
    }
    settingPrice=false;
    triggerRecalc(row);
  }

  function applyAll(){
    body.querySelectorAll('tr').forEach(applyRow);
  }

  function loadPrices(){
    var cariId=Number(cariSelect.value||0);
    currentCari=cariId;
    if(cariId<=0){
      currentItems=[];
      applyAll();
      return Promise.resolve([]);
    }
    if(cache[cariId]){
      currentItems=cache[cariId];
      applyAll();
      return Promise.resolve(currentItems);
    }

    var seq=++requestSeq;
    return fetch('musteri-urun-son-fiyat.php?cari_id='+encodeURIComponent(cariId)+'&_='+Date.now(),{
      credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}
    })
      .then(function(response){return response.json();})
      .then(function(data){
        if(seq!==requestSeq) return [];
        if(!data||!data.ok) throw new Error((data&&data.error)||'Müşteri fiyat geçmişi okunamadı.');
        cache[cariId]=Array.isArray(data.items)?data.items:[];
        currentItems=cache[cariId];
        applyAll();
        return currentItems;
      })
      .catch(function(error){
        console.error('Müşteri ürün fiyatı:',error);
        return [];
      });
  }

  // Düzenlenen eski belgelerde kayıtlı fiyatı kendiliğinden değiştirme.
  if(editing){
    body.querySelectorAll('.price').forEach(function(price){
      if(String(price.value||'').trim()!=='') price.dataset.pricePreserve='1';
    });
  }

  body.addEventListener('input',function(event){
    if(!event.target.classList.contains('price')||settingPrice) return;
    event.target.dataset.priceManual='1';
    delete event.target.dataset.customerAuto;
    delete event.target.dataset.pricePreserve;
  },true);

  body.addEventListener('change',function(event){
    if(!event.target.classList.contains('product-name')&&!event.target.classList.contains('product-barcode')) return;
    var row=event.target.closest('tr');
    var price=row?.querySelector('.price');
    if(price&&price.dataset.priceManual!=='1'&&price.dataset.pricePreserve!=='1'){
      // Sayfadaki eski genel fiyat otomatiği önce çalışsın; hemen ardından müşteri fiyatı onu düzeltsin.
      setTimeout(function(){
        if(currentCari===Number(cariSelect.value||0)&&cache[currentCari]) applyRow(row);
        else loadPrices().then(function(){applyRow(row);});
      },0);
    }
  });

  cariSelect.addEventListener('change',function(){
    // Yeni müşteri seçildiğinde daha önce otomatik doldurulan değerleri yeniden hesapla.
    body.querySelectorAll('.price[data-customer-auto="1"]').forEach(function(price){
      delete price.dataset.customerAuto;
      if(price.dataset.priceManual!=='1'&&price.dataset.pricePreserve!=='1') price.value='';
    });
    loadPrices();
  });

  if(Number(cariSelect.value||0)>0) loadPrices();
})();
