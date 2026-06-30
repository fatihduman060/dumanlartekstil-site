(function(){
  function norm(s){return String(s||'').toLowerCase().replace(/ı/g,'i').replace(/ç/g,'c').replace(/ğ/g,'g').replace(/ö/g,'o').replace(/ş/g,'s').replace(/ü/g,'u');}
  function esc(s){return String(s||'').replace(/[&<>]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c];});}
  function card(words, exclude){return Array.from(document.querySelectorAll('.stat-card')).find(function(el){var t=norm(el.textContent||'');if(exclude&&exclude.some(function(x){return t.indexOf(norm(x))>-1;}))return false;return words.every(function(w){return t.indexOf(norm(w))>-1;});});}
  function panel(){
    var p=document.getElementById('nakitCekDetayPanel'); if(p) return p;
    p=document.createElement('section'); p.id='nakitCekDetayPanel'; p.className='panel-card'; p.style.display='none';
    p.innerHTML='<div class="card-head"><h3>Detay</h3><button type="button" id="nakitCekDetayKapat" class="btn btn-secondary">Kapat</button></div><div id="nakitCekDetayIcerik"></div>';
    var sec=Array.from(document.querySelectorAll('.dashboard-section')).find(function(x){return norm(x.textContent||'').indexOf('para akisi')>-1;});
    (sec||document.querySelector('.main')).insertAdjacentElement('afterend',p);
    document.getElementById('nakitCekDetayKapat').onclick=function(){p.style.display='none';};
    return p;
  }
  function render(data){
    var p=panel(), b=document.getElementById('nakitCekDetayIcerik');
    p.querySelector('h3').textContent=data.title||'Detay';
    var rows=data.rows||[];
    if(!rows.length){b.innerHTML='<p class="muted">Kayıt bulunamadı.</p>';}else{
      b.innerHTML=rows.map(function(r){var url=r.url||'#';return '<div style="display:grid;grid-template-columns:1fr auto;gap:12px;padding:10px;border:1px solid #eee;border-radius:12px;margin:7px 0;background:#fff"><a href="'+esc(url)+'"><strong>'+esc(r.label||'-')+'</strong><small style="display:block">'+esc(r.sub||'')+'</small></a><strong>'+esc(r.amount_text||'')+'</strong></div>';}).join('');
    }
    p.style.display='block'; p.scrollIntoView({behavior:'smooth',block:'nearest'});
  }
  function load(kind){
    var p=panel(); p.style.display='block'; document.getElementById('nakitCekDetayIcerik').innerHTML='<p class="muted">Yükleniyor...</p>';
    fetch('dashboard-nakit-cek-detay.php?kind='+encodeURIComponent(kind)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.json();}).then(function(d){if(!d||!d.ok)throw new Error();render(d);}).catch(function(){document.getElementById('nakitCekDetayIcerik').innerHTML='<p class="text-danger">Liste yüklenemedi.</p>';});
  }
  function bind(el,kind){if(!el)return;el.style.cursor='pointer';el.title='Detay listesini aç';el.addEventListener('click',function(){load(kind);});}
  function init(){
    if(!/dashboard\.php|\/muhasebe\/?$/i.test(location.pathname))return;
    bind(card(['bu ay','giren','para']), 'cash_in');
    bind(card(['bu ay','cikan','para']), 'cash_out');
    bind(card(['bu ay','nakit','neti']), 'cash_net');
    bind(card(['genel','kasa/banka']), 'account_total');
    bind(card(['kasa','toplami']), 'kasa_total');
    bind(card(['banka','toplami']), 'banka_total');
    bind(card(['alinan','cek'],['7 gun','vadesi']), 'check_in');
    bind(card(['verilen','cek']), 'check_out');
    bind(card(['7 gun','icinde','alinacak']), 'check_7');
    bind(card(['vadesi','gecen','cek']), 'check_overdue');
  }
  document.readyState==='loading'?document.addEventListener('DOMContentLoaded',init):init();
})();
