(function(){
  function fmt(v,c){var n=Number(v||0);try{return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(n)+' '+c;}catch(e){return n.toFixed(2).replace('.',',')+' '+c;}}
  function esc(s){return String(s||'').replace(/[&<>]/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[ch];});}
  function card(key){return Array.from(document.querySelectorAll('.stat-card')).find(function(el){return (el.textContent||'').toLowerCase().indexOf(key)>-1;});}
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
    p.querySelector('h3').textContent=type==='alacak'?'Kimden ne kadar alacağımız var?':'Kime ne kadar borcumuz var?';
    if(!rows.length){b.innerHTML='<p class="muted">Açık kayıt yok.</p>';}else{
      b.innerHTML=rows.map(function(r){return '<div style="display:grid;grid-template-columns:1fr auto;gap:12px;padding:10px;border:1px solid #eee;border-radius:12px;margin:7px 0;background:#fff"><a href="cari-detay.php?id='+r.id+'"><strong>'+esc(r.name)+'</strong><small style="display:block">'+esc(r.city||'-')+'</small></a><strong>'+fmt(r.amount,r.currency)+'</strong></div>';}).join('');
    }
    p.style.display='block';p.scrollIntoView({behavior:'smooth',block:'nearest'});
  }
  function load(type){
    var p=panel();p.style.display='block';document.getElementById('cariPozisyonIcerik').innerHTML='<p class="muted">Yükleniyor...</p>';
    fetch('dashboard-cari-pozisyon.php?type='+type,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){show(type,(d&&d.rows)||[]);}).catch(function(){document.getElementById('cariPozisyonIcerik').innerHTML='<p class="text-danger">Liste yüklenemedi.</p>';});
  }
  function init(){
    if(!/dashboard\.php|\/muhasebe\/?$/i.test(location.pathname))return;
    var a=card('alacak'), v=card('verecek');
    [a,v].forEach(function(x){if(x){x.style.cursor='pointer';x.title='Cari listesini aç';}});
    if(a)a.onclick=function(){load('alacak');};
    if(v)v.onclick=function(){load('verecek');};
  }
  document.readyState==='loading'?document.addEventListener('DOMContentLoaded',init):init();
})();
