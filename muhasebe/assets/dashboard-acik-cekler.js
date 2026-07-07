(function(){
  function norm(s){return String(s||'').toLocaleLowerCase('tr-TR').replace(/ı/g,'i').replace(/ç/g,'c').replace(/ğ/g,'g').replace(/ö/g,'o').replace(/ş/g,'s').replace(/ü/g,'u');}
  function esc(s){return String(s==null?'':s).replace(/[&<>\"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[c];});}
  function closestPanel(el){
    while(el && el !== document.body){
      if(el.classList && (el.classList.contains('panel-card') || el.classList.contains('dashboard-card') || el.tagName === 'ARTICLE')) return el;
      el = el.parentElement;
    }
    return null;
  }
  function findCard(){
    var headings = Array.from(document.querySelectorAll('h1,h2,h3,h4,strong'));
    for(var i=0;i<headings.length;i++){
      if(norm(headings[i].textContent).indexOf('cek vadesi yaklasanlar') !== -1){
        var panel = closestPanel(headings[i]);
        if(panel) return panel;
      }
    }
    return null;
  }
  function itemHtml(ch){
    var isOut=(ch.direction||'')==='verilecek';
    var tone=isOut?'text-danger':'text-success';
    var meta=[ch.due_text, ch.bank_name, ch.check_no].filter(Boolean).join(' · ');
    return '<a class="mini-row alert-row dashboard-open-check" href="'+esc(ch.url||'cekler.php')+'"><span>'+esc(ch.cari_name||'-')+'<small>'+esc(meta||'-')+'</small></span><strong class="'+tone+'">'+esc(ch.amount_text||'0,00 TL')+'</strong></a>';
  }
  function render(card,data){
    var checks=(data&&data.checks)||[];
    var list=card.querySelector('.cari-mini-list') || card.querySelector('.mini-list') || card.querySelector('.signal-list') || card.querySelector('ul');
    if(!list){
      list=document.createElement('div');
      list.className='cari-mini-list';
      card.appendChild(list);
    }
    if(!checks.length){
      list.innerHTML='<p class="muted dashboard-open-check-empty">Yaklaşan açık çek yok. Tahsil edilmiş ve ödenmiş çekler bu kutuda gösterilmez.</p>';
    }else{
      list.innerHTML=checks.map(itemHtml).join('');
    }
    var link=card.querySelector('.card-head a, a[href*="cekler.php"]');
    if(link) link.href='cekler.php?status=bekliyor';
  }
  function styles(){
    if(document.getElementById('dashboardAcikCekStyle')) return;
    var s=document.createElement('style');
    s.id='dashboardAcikCekStyle';
    s.textContent='.dashboard-open-check{display:grid!important;grid-template-columns:1fr auto!important;gap:12px!important;align-items:center!important}.dashboard-open-check small{display:block;color:#776b5c;margin-top:3px}.dashboard-open-check-empty{padding:12px 0;margin:0;color:#776b5c}';
    document.head.appendChild(s);
  }
  function run(){
    if(!/dashboard\.php/i.test(location.pathname)) return;
    styles();
    var card=findCard();
    if(!card) return;
    fetch('dashboard-acik-cekler.php?_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(data){if(data&&data.ok) render(card,data);})
      .catch(function(){});
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',run); else run();
  setTimeout(run,500);
  setTimeout(run,1500);
})();
