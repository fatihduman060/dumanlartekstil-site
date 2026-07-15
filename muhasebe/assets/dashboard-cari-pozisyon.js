(function(){
  function fmt(v,c){var n=Number(v||0);try{return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(n)+' '+c;}catch(e){return n.toFixed(2).replace('.',',')+' '+c;}}
  function esc(s){return String(s||'').replace(/[&<>]/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[ch];});}
  function norm(s){return String(s||'').toLowerCase().replace(/ı/g,'i').replace(/ç/g,'c').replace(/ğ/g,'g').replace(/ö/g,'o').replace(/ş/g,'s').replace(/ü/g,'u');}
  function trDate(value){var parts=String(value||'').split('-');return parts.length===3?parts.reverse().join('.'):String(value||'');}
  function cardAny(words, exclude){return Array.from(document.querySelectorAll('.stat-card')).find(function(el){var t=norm(el.textContent||'');if(exclude&&exclude.some(function(x){return t.indexOf(norm(x))>-1;}))return false;return words.some(function(w){return t.indexOf(norm(w))>-1;});});}
  function panel(){
    var p=document.getElementById('cariPozisyonPanel');
    if(p) return p;
    p=document.createElement('section');p.id='cariPozisyonPanel';p.className='panel-card';p.style.display='none';
    p.innerHTML='<div class="card-head"><h3>Cari döküm</h3><button type="button" id="cariPozisyonKapat" class="btn btn-secondary">Kapat</button></div><div id="cariPozisyonIcerik"></div>';
    var s=Array.from(document.querySelectorAll('.dashboard-section')).find(function(x){return (x.textContent||'').indexOf('Genel cari pozisyon')>-1;});
    (s?s:document.querySelector('.main')).insertAdjacentElement('afterend',p);
    document.getElementById('cariPozisyonKapat').onclick=function(){p.style.display='none';};
    return p;
  }
  function show(type,rows){
    var p=panel(), b=document.getElementById('cariPozisyonIcerik');
    p.querySelector('h3').textContent=type==='alacak'?'Kimden net ne kadar alacağımız var?':'Kime net ne kadar borcumuz var?';
    if(!rows.length){b.innerHTML='<p class="muted">Açık kayıt yok.</p>';}else{
      b.innerHTML=rows.map(function(r){return '<div style="display:grid;grid-template-columns:1fr auto;gap:12px;padding:10px;border:1px solid #eee;border-radius:12px;margin:7px 0;background:#fff"><a href="cari-detay.php?id='+r.id+'"><strong>'+esc(r.name)+'</strong><small style="display:block">'+esc(r.city||'-')+'</small></a><strong>'+fmt(r.amount,r.currency)+'</strong></div>';}).join('');
    }
    p.style.display='block';p.scrollIntoView({behavior:'smooth',block:'nearest'});
  }
  function load(type){
    var p=panel();p.style.display='block';document.getElementById('cariPozisyonIcerik').innerHTML='<p class="muted">Yükleniyor...</p>';
    fetch('dashboard-cari-pozisyon.php?type='+type+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(d){show(type,(d&&d.rows)||[]);}).catch(function(){document.getElementById('cariPozisyonIcerik').innerHTML='<p class="text-danger">Liste yüklenemedi.</p>';});
  }
  function initCards(){
    var a=cardAny(['net alacak','durum alacak','alacak'],['verecek','borc','borç','alis','alış','genel durum']);
    var v=cardAny(['net verecek','durum alis','durum alış','borc','borç','verecek'],['alacak','genel durum']);
    [a,v].forEach(function(x){if(x){x.style.cursor='pointer';x.title='Cari listesini aç';}});
    if(a)a.onclick=function(){load('alacak');};
    if(v)v.onclick=function(){load('verecek');};
  }
  function cashFlowStats(){
    var section=Array.from(document.querySelectorAll('.dashboard-section')).find(function(item){
      return norm(item.textContent||'').indexOf('para akisi ve hesaplar')!==-1;
    });
    return section?section.querySelector('.stats-grid'):null;
  }
  function ensureStoreCards(){
    var stats=cashFlowStats();
    if(!stats) return null;

    var cash=document.getElementById('dashboardMagazaNakitCard');
    if(!cash){
      cash=Array.from(stats.querySelectorAll('.stat-card')).find(function(card){
        var label=card.querySelector('span');
        return label&&norm(label.textContent)==='aktif hesap';
      })||document.createElement('article');
      cash.id='dashboardMagazaNakitCard';
      cash.className='stat-card soft';
      cash.innerHTML='<span>Mağazadan gelen nakit</span><strong>Yükleniyor...</strong><small>Bu ayın mağaza nakit toplamı</small>';
      if(!cash.parentNode) stats.appendChild(cash);
    }

    var pos=document.getElementById('dashboardMagazaPosCard');
    if(!pos){
      pos=document.createElement('article');
      pos.id='dashboardMagazaPosCard';
      pos.className='stat-card soft';
      pos.innerHTML='<span>Mağazadan gelen POS / kart</span><strong>Yükleniyor...</strong><small>Bu ayın mağaza kart toplamı</small>';
      cash.insertAdjacentElement('afterend',pos);
    }
    return {cash:cash,pos:pos};
  }
  function renderStoreCards(data){
    var cards=ensureStoreCards();
    if(!cards) return;
    var count=Number(data&&data.day_count||0);
    var latest=String(data&&data.latest_sale_date||data&&data.cutoff_date||'');
    var dateText=latest?trDate(latest)+' tarihine kadar':'Bu ay';
    var countText=count>0?' · '+count+' günlük kayıt':'';
    var settled=Number(data&&data.settled_pos_total||0);

    cards.cash.querySelector('strong').textContent=fmt(data&&data.cash_total||0,'TL');
    cards.cash.querySelector('small').textContent=dateText+countText+' · Ana Kasa’ya işlendi';
    cards.cash.title='Nakit satış + nakit veresiye tahsilatı';

    cards.pos.querySelector('strong').textContent=fmt(data&&data.card_total||0,'TL');
    cards.pos.querySelector('small').textContent=dateText+countText+' · Garanti’ye geçen: '+fmt(settled,'TL');
    cards.pos.title='Kart satış + kart veresiye tahsilatı. Banka girişi satıştan 13 gün sonra Garanti Dumanlar hesabına işlenir.';
  }
  function loadStoreMonthlyCards(){
    ensureStoreCards();
    fetch('dashboard-magaza-tahsilat.php?_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(!data||!data.ok) throw new Error(data&&data.error?data.error:'Mağaza özeti yüklenemedi.');
        var processed=Number(data.processed_due_count||0);
        if(processed>0){
          var fingerprint=String(data.cutoff_date||'')+'|'+String(data.latest_settlement_date||'')+'|'+String(data.settled_pos_total||0);
          var key='dashboard-magaza-pos-yenileme';
          try{
            if(sessionStorage.getItem(key)!==fingerprint){
              sessionStorage.setItem(key,fingerprint);
              location.reload();
              return;
            }
          }catch(error){}
        }
        renderStoreCards(data);
      })
      .catch(function(error){
        var cards=ensureStoreCards();
        if(!cards) return;
        cards.cash.querySelector('strong').textContent='—';
        cards.pos.querySelector('strong').textContent='—';
        cards.cash.querySelector('small').textContent=error.message||'Mağaza özeti yüklenemedi';
        cards.pos.querySelector('small').textContent=error.message||'Mağaza özeti yüklenemedi';
      });
  }
  function init(){
    if(!/dashboard\.php|\/muhasebe\/?$/i.test(location.pathname))return;
    initCards();
    loadStoreMonthlyCards();
  }
  document.readyState==='loading'?document.addEventListener('DOMContentLoaded',init):init();
})();
