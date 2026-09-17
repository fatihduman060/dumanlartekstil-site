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

(function(){
  'use strict';
  if(!/\/teklif-ver\.php$/i.test(location.pathname)) return;

  var table=document.querySelector('.saved-offers');
  var tbody=table&&table.tBodies?table.tBodies[0]:null;
  if(!tbody||tbody.dataset.firmaGrouped==='1') return;

  var rows=Array.prototype.slice.call(tbody.querySelectorAll(':scope > tr')).filter(function(row){
    return !row.querySelector('td.empty') && row.cells && row.cells.length>=5;
  });
  if(!rows.length) return;

  var norm=function(value){
    return String(value||'').trim().replace(/\s+/g,' ').toLocaleUpperCase('tr-TR');
  };
  var groups=[];
  var byKey={};

  rows.forEach(function(row){
    var firmCell=row.cells[1];
    var firmStrong=firmCell?firmCell.querySelector('strong'):null;
    var firm=(firmStrong?firmStrong.textContent:firmCell?firmCell.textContent:'').replace(/\s+/g,' ').trim()||'Firma belirtilmemiş';
    var key=norm(firm);
    if(!byKey[key]){
      byKey[key]={name:firm,rows:[]};
      groups.push(byKey[key]);
    }
    byKey[key].rows.push(row);
  });

  var style=document.getElementById('teklif-firma-group-style');
  if(!style){
    style=document.createElement('style');
    style.id='teklif-firma-group-style';
    style.textContent=''
      +'.offer-firm-group td{padding:0!important;background:#fbf6ed!important;border-bottom:1px solid #e5dccf!important}'
      +'.offer-firm-toggle{width:100%;display:flex;align-items:center;justify-content:space-between;gap:12px;border:0;background:transparent;padding:13px 15px;color:#102818;cursor:pointer;text-align:left;font:inherit}'
      +'.offer-firm-toggle strong{font-size:14px}.offer-firm-toggle small{display:block!important;margin-top:3px!important;color:#776b5c!important;font-size:11px!important}'
      +'.offer-firm-toggle .offer-firm-arrow{width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:#16482e;color:#fff;font-weight:900;transition:transform .18s ease}'
      +'.offer-firm-group.open .offer-firm-arrow{transform:rotate(90deg)}'
      +'.saved-offers tr.offer-firm-item[hidden]{display:none!important}'
      +'.saved-offers tr.offer-firm-item td:first-child{padding-left:22px}'
      +'@media(max-width:640px){.offer-firm-toggle{padding:12px}.saved-offers tr.offer-firm-item td:first-child{padding-left:12px}}';
    document.head.appendChild(style);
  }

  tbody.innerHTML='';
  groups.forEach(function(group,index){
    var header=document.createElement('tr');
    header.className='offer-firm-group';
    var td=document.createElement('td');
    td.colSpan=5;
    var button=document.createElement('button');
    button.type='button';
    button.className='offer-firm-toggle';
    button.setAttribute('aria-expanded','false');
    button.innerHTML='<span><strong></strong><small></small></span><span class="offer-firm-arrow">›</span>';
    button.querySelector('strong').textContent=group.name;
    button.querySelector('small').textContent=group.rows.length+' teklif';
    td.appendChild(button);
    header.appendChild(td);
    tbody.appendChild(header);

    group.rows.forEach(function(row){
      row.classList.add('offer-firm-item');
      row.dataset.firmGroup=String(index);
      row.hidden=true;
      tbody.appendChild(row);
    });

    button.addEventListener('click',function(){
      var open=button.getAttribute('aria-expanded')!=='true';
      button.setAttribute('aria-expanded',open?'true':'false');
      header.classList.toggle('open',open);
      group.rows.forEach(function(row){row.hidden=!open;});
    });
  });

  tbody.dataset.firmaGrouped='1';
})();
