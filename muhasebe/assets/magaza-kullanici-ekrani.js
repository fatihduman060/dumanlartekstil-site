(function(){
  if(!window.BITKE_STORE_SALES_ONLY) return;

  var path=location.pathname||'';

  // Mağaza kullanıcısında Barkodlu Satış ve Mağaza ekranlarına daha fazla çalışma alanı bırak.
  // Sadece geniş masaüstünde uygulanır; tablet/telefon menü davranışı aynen korunur.
  if(/\/(?:barkod-satis|magaza)\.php$/i.test(path)){
    var compactStoreStyle=document.createElement('style');
    compactStoreStyle.id='bitkeStoreCompactLayout';
    compactStoreStyle.textContent=''
      +'@media(min-width:1101px){'
      +'body.store-sales-user .app-shell{grid-template-columns:205px minmax(0,1fr)!important}'
      +'body.store-sales-user .sidebar{padding:16px 12px!important;gap:16px!important}'
      +'body.store-sales-user .sidebar .brand{gap:8px!important;font-size:16px!important}'
      +'body.store-sales-user .sidebar .brand img{width:36px!important;height:36px!important;border-radius:10px!important;padding:4px!important}'
      +'body.store-sales-user .sidebar .brand span{font-size:16px!important}'
      +'body.store-sales-user .sidebar .brand span small{display:block!important;width:max-content;margin:4px 0 0!important;padding:2px 6px!important;font-size:9px!important}'
      +'body.store-sales-user .side-nav{gap:5px!important}'
      +'body.store-sales-user .side-nav a{gap:8px!important;padding:10px 9px!important;border-radius:11px!important;font-size:14px!important}'
      +'body.store-sales-user .nav-ico{width:19px!important;min-width:19px!important}'
      +'body.store-sales-user .side-footer{padding:11px!important;border-radius:13px!important}'
      +'body.store-sales-user .side-footer span{font-size:10px!important}'
      +'body.store-sales-user .side-footer strong{font-size:12px!important}'
      +'body.store-sales-user .main{padding:20px 16px!important}'
      +'body.store-sales-user .topbar{margin-bottom:16px!important}'
      +'body.store-sales-user .pos-shell{grid-template-columns:minmax(0,2fr) minmax(270px,.68fr)!important;gap:14px!important}'
      +'body.store-sales-user .pos-main{min-width:0!important}'
      +'body.store-sales-user .pos-checkout{min-width:270px!important}'
      +'body.store-sales-user .magaza-page-shell{max-width:none!important;width:100%!important}'
      +'}';
    document.head.appendChild(compactStoreStyle);
  }

  // Eski mobil Z raporu stilinin form/özet/listeyi tekrar gizlemesini engelle.
  // Inline !important kullanıldığı için sonradan yüklenen eski CSS de bu alanları kapatamaz.
  if(/\/magaza\.php$/i.test(path)){
    function keepZReportVisible(){
      var panel=document.querySelector('[data-magaza-gunluk-satis]');
      if(!panel) return;
      var summary=panel.querySelector('.magaza-satis-summary');
      var form=panel.querySelector('.magaza-satis-form');
      var list=panel.querySelector('.magaza-satis-list');
      if(summary) summary.style.setProperty('display','grid','important');
      if(form) form.style.setProperty('display','grid','important');
      if(list) list.style.setProperty('display','block','important');
      panel.style.setProperty('display','grid','important');
    }

    var zReportQueued=false;
    function queueZReportFix(){
      if(zReportQueued) return;
      zReportQueued=true;
      window.setTimeout(function(){
        zReportQueued=false;
        keepZReportVisible();
      },20);
    }

    if(document.body) new MutationObserver(queueZReportFix).observe(document.body,{childList:true,subtree:true});
    [50,150,400,900,1600,3000].forEach(function(delay){window.setTimeout(keepZReportVisible,delay);});
  }

  function storeMoney(value){
    return Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' TL';
  }

  function trDateToIso(value){
    var parts=String(value||'').trim().split('.');
    if(parts.length!==3) return '';
    return parts[2]+'-'+parts[1]+'-'+parts[0];
  }

  function setCashLabel(span){
    if(!span) return;
    var node=span.firstChild;
    if(node&&node.nodeType===3){
      if(String(node.nodeValue||'').trim()!=='Nakit toplam') node.nodeValue='Nakit toplam ';
      return;
    }
    if(span.dataset.cashTotalLabel!=='1'){
      span.insertBefore(document.createTextNode('Nakit toplam '),span.firstChild||null);
      span.dataset.cashTotalLabel='1';
    }
  }

  function syncMobileCashTotals(){
    if(!/\/(?:magaza|faturalar)\.php$/i.test(path)) return;

    var rows={};
    document.querySelectorAll('[data-magaza-odeme-row]').forEach(function(row){
      var date=String(row.getAttribute('data-date')||'');
      if(!date) return;
      rows[date]={
        cash:Number(row.getAttribute('data-cash')||0),
        collection:Number(row.getAttribute('data-cash-collection')||0)
      };
    });

    var latest=document.querySelector('[data-magaza-mobile-latest]');
    if(latest){
      var latestDate=trDateToIso((latest.querySelector('[data-magaza-latest-date]')||{}).textContent||'');
      var latestData=rows[latestDate];
      var latestCash=latest.querySelector('[data-magaza-latest-cash]');
      if(latestData&&latestCash){
        var latestValue=storeMoney(latestData.cash+latestData.collection);
        if(latestCash.textContent!==latestValue) latestCash.textContent=latestValue;
        setCashLabel(latestCash.parentElement);
      }
    }

    document.querySelectorAll('.magaza-mobile-payment-card').forEach(function(card){
      var date=trDateToIso((card.querySelector('.magaza-mobile-payment-head strong')||{}).textContent||'');
      var data=rows[date];
      var cashSpan=card.querySelector('.magaza-mobile-payment-breakdown span:first-child');
      var cashStrong=cashSpan?cashSpan.querySelector('strong'):null;
      if(!data||!cashStrong) return;
      var value=storeMoney(data.cash+data.collection);
      if(cashStrong.textContent!==value) cashStrong.textContent=value;
      setCashLabel(cashSpan);
    });
  }

  if(/\/(?:magaza|faturalar)\.php$/i.test(path)){
    var cashSyncQueued=false;
    function queueCashSync(){
      if(cashSyncQueued) return;
      cashSyncQueued=true;
      window.setTimeout(function(){
        cashSyncQueued=false;
        syncMobileCashTotals();
      },30);
    }
    document.addEventListener('bitke:magaza-odeme-updated',queueCashSync);
    if(document.body){
      new MutationObserver(queueCashSync).observe(document.body,{childList:true,subtree:true});
    }
    window.setTimeout(syncMobileCashTotals,100);
    window.setTimeout(syncMobileCashTotals,500);
  }

  // Mağaza kullanıcısı Personel Veresiye ekranında günlük hareketleri de görebilsin.
  if(/\/magaza-veresiye\.php$/i.test(path)){
    ['.pv-head','.pv-summary','.pv-grid','.pv-daily','.alert'].forEach(function(selector){
      document.querySelectorAll(selector).forEach(function(el){
        el.style.setProperty('display', selector==='.pv-head' ? 'flex' : (selector==='.alert' ? 'block' : 'grid'), 'important');
      });
    });

    var style=document.createElement('style');
    style.textContent=''
      +'body.store-sales-user .main>.pv-daily{display:grid!important}'
      +'.pv-daily{width:100%;max-width:100%;box-sizing:border-box}'
      +'.pv-day{width:100%;box-sizing:border-box}'
      +'@media(max-width:760px){'
      +'.pv-day-head{grid-template-columns:1fr 1fr!important}'
      +'.pv-day-item{grid-template-columns:1fr auto!important}'
      +'.pv-day-item .pv-day-desc{grid-column:1/-1!important}'
      +'}';
    document.head.appendChild(style);
    return;
  }

  if(!/\/faturalar\.php$/i.test(path)) return;

  function periodValue(){
    var value=new URLSearchParams(location.search).get('period')||'';
    return /^\d{4}-\d{2}$/.test(value)?value:new Date().toISOString().slice(0,7);
  }

  var main=document.querySelector('main.main');
  var topbar=main?main.querySelector('.topbar'):null;
  if(!main||!topbar) return;

  Array.prototype.slice.call(main.children).forEach(function(child){
    if(child!==topbar) child.remove();
  });

  var title=topbar.querySelector('h1');
  var eyebrow=topbar.querySelector('p');
  if(title) title.textContent='Mağaza Günlük Satışları';
  if(eyebrow) eyebrow.textContent='Fabrika satış mağazası';

  var section=document.createElement('section');
  section.className='dashboard-section store-sales-shell';
  section.innerHTML=''
    +'<div class="dashboard-section-head store-sales-head">'
    +'<div><span>Günlük satış girişi</span><h3>Mağaza satışlarını kaydet</h3></div>'
    +'<p>Gün sonu raporundaki KDV dahil toplamı yaz. Sistem %10 KDV ve matrahı otomatik hesaplar.</p>'
    +'</div>'
    +'<form class="filterbar store-sales-period" method="get" action="faturalar.php">'
    +'<input type="month" name="period" value="'+periodValue()+'">'
    +'<button class="btn btn-secondary" type="submit">Ayı göster</button>'
    +'</form>'
    +'<div class="store-sales-only-body" data-fatura-alt-kontrol-body></div>';
  main.appendChild(section);

  var brand=document.querySelector('.sidebar .brand');
  if(brand) brand.setAttribute('href','faturalar.php');

  var footerRole=document.querySelector('.side-footer span');
  if(footerRole) footerRole.textContent='Mağaza Kullanıcısı';

  var style=document.createElement('style');
  style.textContent=''
    +'.store-sales-shell{display:grid!important;gap:16px;max-width:1120px}'
    +'.store-sales-head{margin-bottom:0}'
    +'.store-sales-period{margin:0;padding:12px;border:1px solid var(--border);border-radius:14px;background:#fff}'
    +'.store-sales-only-body{display:grid;gap:14px}'
    +'.store-sales-user .top-actions .ghost-link{display:none!important}'
    +'.store-sales-user .main>.store-sales-shell{display:grid!important}'
    +'@media(max-width:700px){.store-sales-period{align-items:stretch}}';
  document.head.appendChild(style);
})();