(function(){
  function fmt(v,c){var n=Number(v||0);try{return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(n)+' '+c;}catch(e){return n.toFixed(2).replace('.',',')+' '+c;}}
  function esc(s){return String(s||'').replace(/[&<>"']/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];});}
  function norm(s){return String(s||'').toLowerCase().replace(/ı/g,'i').replace(/ç/g,'c').replace(/ğ/g,'g').replace(/ö/g,'o').replace(/ş/g,'s').replace(/ü/g,'u');}
  function trDate(value){var parts=String(value||'').split('-');return parts.length===3?parts.reverse().join('.'):String(value||'');}
  function cardAny(words, exclude){return Array.from(document.querySelectorAll('.stat-card')).find(function(el){var t=norm(el.textContent||'');if(exclude&&exclude.some(function(x){return t.indexOf(norm(x))>-1;}))return false;return words.some(function(w){return t.indexOf(norm(w))>-1;});});}
  function cariSection(){return Array.from(document.querySelectorAll('.dashboard-section')).find(function(x){return norm(x.textContent||'').indexOf('genel cari pozisyon')>-1;})||null;}

  function panel(){
    var p=document.getElementById('cariPozisyonPanel');
    if(p) return p;
    p=document.createElement('section');p.id='cariPozisyonPanel';p.className='panel-card';p.style.display='none';
    p.innerHTML='<div class="card-head"><h3>Cari döküm</h3><button type="button" id="cariPozisyonKapat" class="btn btn-secondary">Kapat</button></div><div id="cariPozisyonIcerik"></div>';
    var s=cariSection();
    if(s)s.insertAdjacentElement('afterend',p);else(document.querySelector('.main')||document.body).appendChild(p);
    document.getElementById('cariPozisyonKapat').onclick=function(){p.style.display='none';};
    return p;
  }
  function show(type,rows){
    var p=panel(), b=document.getElementById('cariPozisyonIcerik');
    p.querySelector('h3').textContent=type==='alacak'?'Kimden net ne kadar alacağımız var?':'Kime net ne kadar borcumuz var?';
    if(!rows.length){b.innerHTML='<p class="muted">Açık kayıt yok.</p>';}else{
      b.innerHTML=rows.map(function(r){return '<div style="display:grid;grid-template-columns:1fr auto;gap:12px;padding:10px;border:1px solid #eee;border-radius:12px;margin:7px 0;background:#fff"><a href="cari-detay.php?id='+Number(r.id||0)+'"><strong>'+esc(r.name)+'</strong><small style="display:block">'+esc(r.city||'-')+'</small></a><strong>'+fmt(r.amount,r.currency)+'</strong></div>';}).join('');
    }
    p.style.display='block';p.scrollIntoView({behavior:'smooth',block:'nearest'});
  }
  function load(type){
    var p=panel();p.style.display='block';document.getElementById('cariPozisyonIcerik').innerHTML='<p class="muted">Yükleniyor...</p>';
    fetch('dashboard-cari-pozisyon.php?type='+type+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(d){show(type,(d&&d.rows)||[]);}).catch(function(){document.getElementById('cariPozisyonIcerik').innerHTML='<p class="text-danger">Liste yüklenemedi.</p>';});
  }
  function initCards(){
    var a=cardAny(['net alacak','durum alacak','alacak'],['verecek','borc','borç','alis','alış','genel durum','cek','çek']);
    var v=cardAny(['net verecek','durum alis','durum alış','borc','borç','verecek'],['alacak','genel durum','cek','çek']);
    [a,v].forEach(function(x){if(x){x.style.cursor='pointer';x.title='Cari listesini aç';}});
    if(a)a.onclick=function(){load('alacak');};
    if(v)v.onclick=function(){load('verecek');};
  }

  function scanStyle(){
    if(document.getElementById('cari-net-scan-style'))return;
    var style=document.createElement('style');style.id='cari-net-scan-style';
    style.textContent=[
      '.cari-net-scan{margin-top:16px;padding:18px;border:1px solid #d9e7dd;border-radius:18px;background:#fbfdfb}',
      '.cari-net-scan-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:12px}',
      '.cari-net-scan-head h3{margin:3px 0 4px;color:#173c27}.cari-net-scan-head p{margin:0;color:#66736b;font-size:13px}',
      '.cari-net-scan-count{display:inline-flex;padding:6px 9px;border-radius:999px;background:#fff1d5;color:#805500;font-size:11px;font-weight:900;white-space:nowrap}',
      '.cari-net-scan-ok{padding:12px;border-radius:12px;background:#eef8f1;color:#176536;font-weight:800}',
      '.cari-net-scan-list{display:grid;gap:8px}',
      '.cari-net-scan-row{display:grid;grid-template-columns:minmax(190px,1.25fr) repeat(3,minmax(135px,.8fr));gap:10px;align-items:center;padding:11px 12px;border:1px solid #e2e9e4;border-radius:13px;background:#fff;text-decoration:none;color:#26342b}',
      '.cari-net-scan-row:hover{border-color:#8bb99a}.cari-net-scan-row small{display:block;color:#748078;margin-top:2px}',
      '.cari-net-scan-cell span{display:block;color:#758078;font-size:9px;font-weight:900;text-transform:uppercase}.cari-net-scan-cell strong{font-size:12px}',
      '.cari-net-scan-result.positive strong{color:#176536}.cari-net-scan-result.negative strong{color:#b64242}.cari-net-scan-result.closed strong{color:#59655e}',
      '.cari-net-currency-note{display:block;margin-top:4px;color:#6d786f;font-size:10px;font-weight:700}',
      '@media(max-width:820px){.cari-net-scan-head{display:block}.cari-net-scan-count{margin-top:8px}.cari-net-scan-row{grid-template-columns:1fr 1fr}.cari-net-scan-row>a{grid-column:1/-1}}',
      '@media(max-width:520px){.cari-net-scan-row{grid-template-columns:1fr}.cari-net-scan-cell{display:flex;justify-content:space-between;gap:8px}}'
    ].join('');document.head.appendChild(style);
  }

  function currencyNote(totals){
    var parts=[];['USD','EUR'].forEach(function(cur){var t=totals&&totals[cur];if(!t)return;if(Math.abs(Number(t.receivable||0))>.004||Math.abs(Number(t.payable||0))>.004)parts.push(cur+': '+fmt(t.receivable,cur)+' alacak / '+fmt(t.payable,cur)+' borç');});
    return parts.join(' · ');
  }

  function setCard(card,value,label,small,className){
    if(!card)return;
    var span=card.querySelector('span'),strong=card.querySelector('strong'),smallEl=card.querySelector('small');
    if(span&&label)span.textContent=label;
    if(strong){strong.textContent=fmt(value,'TL');strong.classList.remove('text-success','text-danger');if(className)strong.classList.add(className);}
    if(smallEl)smallEl.textContent=small;
  }

  function renderNetCards(data){
    var totals=data&&data.totals?data.totals:{};
    var tl=totals.TL||{receivable:0,payable:0,net:0};
    var a=cardAny(['net alacak','durum alacak','alacak'],['verecek','borc','borç','alis','alış','genel durum','cek','çek']);
    var v=cardAny(['net verecek','durum alis','durum alış','borc','borç','verecek'],['alacak','genel durum','cek','çek']);
    var g=cardAny(['genel durum'],[]);
    setCard(a,tl.receivable,'Net cari alacağı','Her cari kendi içinde mahsup edildi','text-success');
    setCard(v,tl.payable,'Net cari borcu','Her cari kendi içinde mahsup edildi','text-danger');
    setCard(g,tl.net,'Genel cari durum',Number(tl.net||0)>=0?'Toplam net alacak':'Toplam net borç',Number(tl.net||0)>=0?'text-success':'text-danger');
    var note=currencyNote(totals);
    [a,v,g].forEach(function(card){if(!card||!note)return;var old=card.querySelector('.cari-net-currency-note');if(!old){old=document.createElement('em');old.className='cari-net-currency-note';card.appendChild(old);}old.textContent=note;});
  }

  function scanPanel(){
    var old=document.getElementById('cariNetTaramaPanel');if(old)return old;
    scanStyle();
    var section=cariSection();if(!section)return null;
    var box=document.createElement('div');box.id='cariNetTaramaPanel';box.className='cari-net-scan';
    box.innerHTML='<div class="cari-net-scan-head"><div><small>CANLI CARİ TARAMASI</small><h3>Karşılıklı alacak ve borcu bulunan cariler</h3><p>Satış ve alış hareketleri silinmez; aynı caride birbirinden mahsup edilerek gerçek net durum gösterilir.</p></div><span class="cari-net-scan-count" data-count>Taranıyor…</span></div><div data-content><p class="muted">Canlı kayıtlar taranıyor…</p></div>';
    section.appendChild(box);return box;
  }

  function renderScan(data){
    var box=scanPanel();if(!box)return;
    var mixed=(data&&data.mixed)||[];
    var count=Number(data&&data.mixed_count||mixed.length||0);
    box.querySelector('[data-count]').textContent=count+' cari bulundu';
    var content=box.querySelector('[data-content]');
    if(!mixed.length){content.innerHTML='<div class="cari-net-scan-ok">Karşılıklı açık alacak ve borcu bulunan başka cari yok.</div>';return;}
    content.innerHTML='<div class="cari-net-scan-list">'+mixed.map(function(r){
      var net=Number(r.net||0),resultClass=Math.abs(net)<.005?'closed':(net>0?'positive':'negative');
      var resultLabel=Math.abs(net)<.005?'Hesap kapalı':(net>0?'Net alacak':'Net borç');
      return '<a class="cari-net-scan-row" href="cari-detay.php?id='+Number(r.id||0)+'">'+
        '<div><strong>'+esc(r.name)+'</strong><small>'+esc(r.city||'-')+' · '+esc(r.currency||'TL')+' · Mahsup: '+fmt(r.offset,r.currency||'TL')+'</small></div>'+
        '<div class="cari-net-scan-cell"><span>Açık alacak</span><strong>'+fmt(r.net_alacak,r.currency||'TL')+'</strong></div>'+
        '<div class="cari-net-scan-cell"><span>Açık borç</span><strong>'+fmt(r.net_verecek,r.currency||'TL')+'</strong></div>'+
        '<div class="cari-net-scan-cell cari-net-scan-result '+resultClass+'"><span>'+resultLabel+'</span><strong>'+fmt(Math.abs(net),r.currency||'TL')+'</strong></div>'+
      '</a>';
    }).join('')+'</div>';
  }

  function filterFalseCollectionAlerts(data){
    var positions=(data&&data.positions)||[],byId={};
    positions.forEach(function(r){if(String(r.currency||'TL')==='TL')byId[String(r.id)]=r;});
    var card=Array.from(document.querySelectorAll('.panel-card')).find(function(el){return norm(el.textContent||'').indexOf('30 gundur tahsilat gorunmeyenler')>-1;});
    if(!card)return;
    card.querySelectorAll('a.mini-row[href*="cari-detay.php?id="]').forEach(function(link){
      try{var id=new URL(link.href,location.href).searchParams.get('id'),p=byId[String(id)];if(p&&Number(p.net||0)<=.004)link.remove();}catch(e){}
    });
    var list=card.querySelector('.cari-mini-list');
    if(list&&!list.querySelector('a.mini-row')&&!list.querySelector('.cari-net-empty')){var empty=document.createElement('p');empty.className='muted cari-net-empty';empty.textContent='Net alacağı olup 30 gündür tahsilat görünmeyen cari yok.';list.appendChild(empty);}
  }

  function loadNetScan(){
    scanPanel();
    fetch('dashboard-cari-net-tarama.php?_='+Date.now(),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}})
      .then(function(response){if(!response.ok)throw new Error('Cari taraması yüklenemedi.');return response.json();})
      .then(function(data){if(!data||!data.ok)throw new Error(data&&data.error?data.error:'Cari taraması yüklenemedi.');renderNetCards(data);renderScan(data);filterFalseCollectionAlerts(data);})
      .catch(function(error){var box=scanPanel();if(!box)return;box.querySelector('[data-count]').textContent='Tarama hatası';box.querySelector('[data-content]').innerHTML='<p class="text-danger">'+esc(error.message||'Cari taraması yapılamadı.')+'</p>';});
  }

  function cashFlowStats(){
    var section=Array.from(document.querySelectorAll('.dashboard-section')).find(function(item){return norm(item.textContent||'').indexOf('para akisi ve hesaplar')!==-1;});
    return section?section.querySelector('.stats-grid'):null;
  }
  function ensureStoreCards(){
    var stats=cashFlowStats();if(!stats)return null;
    var cash=document.getElementById('dashboardMagazaNakitCard');
    if(!cash){cash=Array.from(stats.querySelectorAll('.stat-card')).find(function(card){var label=card.querySelector('span');return label&&norm(label.textContent)==='aktif hesap';})||document.createElement('article');cash.id='dashboardMagazaNakitCard';cash.className='stat-card soft';cash.innerHTML='<span>Mağazadan gelen nakit</span><strong>Yükleniyor...</strong><small>Bu ayın mağaza nakit toplamı</small>';if(!cash.parentNode)stats.appendChild(cash);}
    var pos=document.getElementById('dashboardMagazaPosCard');
    if(!pos){pos=document.createElement('article');pos.id='dashboardMagazaPosCard';pos.className='stat-card soft';pos.innerHTML='<span>Mağazadan gelen POS / kart</span><strong>Yükleniyor...</strong><small>Bu ayın mağaza kart toplamı</small>';cash.insertAdjacentElement('afterend',pos);}
    return {cash:cash,pos:pos};
  }
  function renderStoreCards(data){
    var cards=ensureStoreCards();if(!cards)return;
    var count=Number(data&&data.day_count||0),latest=String(data&&data.latest_sale_date||data&&data.cutoff_date||''),dateText=latest?trDate(latest)+' tarihine kadar':'Bu ay',countText=count>0?' · '+count+' günlük kayıt':'',settled=Number(data&&data.settled_pos_total||0);
    cards.cash.querySelector('strong').textContent=fmt(data&&data.cash_total||0,'TL');cards.cash.querySelector('small').textContent=dateText+countText+' · Ana Kasa’ya işlendi';cards.cash.title='Nakit satış + nakit veresiye tahsilatı';
    cards.pos.querySelector('strong').textContent=fmt(data&&data.card_total||0,'TL');cards.pos.querySelector('small').textContent=dateText+countText+' · Garanti’ye geçen: '+fmt(settled,'TL');cards.pos.title='Kart satış + kart veresiye tahsilatı. Banka girişi satıştan 13 gün sonra Garanti Dumanlar hesabına işlenir.';
  }
  function loadStoreMonthlyCards(){
    ensureStoreCards();
    fetch('dashboard-magaza-tahsilat.php?_='+Date.now(),{credentials:'same-origin',cache:'no-store'}).then(function(response){return response.json();}).then(function(data){
      if(!data||!data.ok)throw new Error(data&&data.error?data.error:'Mağaza özeti yüklenemedi.');
      var processed=Number(data.processed_due_count||0);if(processed>0){var fingerprint=String(data.cutoff_date||'')+'|'+String(data.latest_settlement_date||'')+'|'+String(data.settled_pos_total||0),key='dashboard-magaza-pos-yenileme';try{if(sessionStorage.getItem(key)!==fingerprint){sessionStorage.setItem(key,fingerprint);location.reload();return;}}catch(error){}}
      renderStoreCards(data);
    }).catch(function(error){var cards=ensureStoreCards();if(!cards)return;cards.cash.querySelector('strong').textContent='—';cards.pos.querySelector('strong').textContent='—';cards.cash.querySelector('small').textContent=error.message||'Mağaza özeti yüklenemedi';cards.pos.querySelector('small').textContent=error.message||'Mağaza özeti yüklenemedi';});
  }
  function init(){if(!/dashboard\.php|\/muhasebe\/?$/i.test(location.pathname))return;initCards();loadNetScan();loadStoreMonthlyCards();}
  document.readyState==='loading'?document.addEventListener('DOMContentLoaded',init):init();
})();
