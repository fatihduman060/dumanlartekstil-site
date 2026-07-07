(function(){
  function norm(s){return String(s||'').toLocaleLowerCase('tr-TR').replace(/ı/g,'i').replace(/ç/g,'c').replace(/ğ/g,'g').replace(/ö/g,'o').replace(/ş/g,'s').replace(/ü/g,'u');}
  function esc(s){return String(s==null?'':s).replace(/[&<>\"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[c];});}
  function findCard(){
    return Array.from(document.querySelectorAll('.panel-card,.card,.dashboard-card,section,article,div')).find(function(el){
      var t=norm(el.textContent||'');
      return t.indexOf('cek vadesi yaklasanlar')>-1 && t.indexOf('ceklerin gor')>-1;
    });
  }
  function itemHtml(ch){
    var isOut=(ch.direction||'')==='verilecek';
    var tone=isOut?'text-danger':'text-success';
    var meta=[ch.due_text, ch.bank_name, ch.check_no].filter(Boolean).join(' · ');
    return '<a class="dashboard-open-check" href="'+esc(ch.url||'cekler.php')+'"><div><strong>'+esc(ch.cari_name||'-')+'</strong><small>'+esc(meta||'-')+'</small></div><b class="'+tone+'">'+esc(ch.amount_text||'0,00 TL')+'</b></a>';
  }
  function render(card,data){
    var checks=(data&&data.checks)||[];
    var list=card.querySelector('.mini-list,.signal-list,.quick-list,.list') || card.querySelector('ul') || card.querySelector('div:last-child');
    if(!list) return;
    if(!checks.length){
      list.innerHTML='<p class="muted dashboard-open-check-empty">Yaklaşan açık çek yok. Tahsil edilmiş ve ödenmiş çekler bu kutuda gösterilmez.</p>';
    }else{
      list.innerHTML=checks.map(itemHtml).join('');
    }
    var countText=card.querySelector('small, .card-head span, .panel-head span');
    if(countText && norm(countText.textContent).indexOf('sinyal')>-1){
      countText.textContent=checks.length+' açık çek sinyali';
    }
  }
  function styles(){
    if(document.getElementById('dashboardAcikCekStyle')) return;
    var s=document.createElement('style');
    s.id='dashboardAcikCekStyle';
    s.textContent='.dashboard-open-check{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;padding:12px 0;border-bottom:1px solid rgba(16,40,24,.08);text-decoration:none;color:#102818}.dashboard-open-check:last-child{border-bottom:0}.dashboard-open-check strong{display:block;color:#102818}.dashboard-open-check small{display:block;color:#776b5c;margin-top:3px}.dashboard-open-check b{font-size:15px}.dashboard-open-check-empty{padding:12px 0;margin:0;color:#776b5c}';
    document.head.appendChild(s);
  }
  function init(){
    if(!/dashboard\.php/i.test(location.pathname)) return;
    styles();
    var card=findCard(); if(!card) return;
    fetch('dashboard-acik-cekler.php?_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(data){if(data&&data.ok) render(card,data);})
      .catch(function(){});
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
