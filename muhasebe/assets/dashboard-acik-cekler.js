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
  function parseMoney(text){
    var raw=String(text||'').replace(/\s/g,'').replace(/TL/gi,'').replace(/\./g,'').replace(',','.').replace(/[^0-9.\-]/g,'');
    var value=parseFloat(raw);
    return Number.isFinite(value)?value:0;
  }
  function formatMoney(value){
    return Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' TL';
  }
  function updateCheckNetCard(){
    var sections=Array.from(document.querySelectorAll('.dashboard-section'));
    var section=null;
    for(var i=0;i<sections.length;i++){
      if(norm(sections[i].textContent).indexOf('cek vade takibi')!==-1){section=sections[i];break;}
    }
    if(!section) return;

    var cards=Array.from(section.querySelectorAll('.section-stats .stat-card'));
    var incoming=null;
    var outgoing=null;
    var target=null;

    cards.forEach(function(card){
      var label=norm((card.querySelector('span')||{}).textContent||'');
      if(label.indexOf('alinacak cek')!==-1 || label.indexOf('alinan cek')!==-1) incoming=card;
      if(label.indexOf('verilecek cek')!==-1 || label.indexOf('verilen cek')!==-1) outgoing=card;
      if(label.indexOf('vadesi gecen cek')!==-1 || label.indexOf('genel durum')!==-1) target=card;
    });

    if(!incoming || !outgoing || !target) return;
    var incomingStrong=incoming.querySelector('strong');
    var outgoingStrong=outgoing.querySelector('strong');
    if(!incomingStrong || !outgoingStrong) return;

    var net=parseMoney(incomingStrong.textContent)-parseMoney(outgoingStrong.textContent);
    var title=target.querySelector('span');
    var value=target.querySelector('strong');
    var note=target.querySelector('small');
    if(!title || !value || !note) return;

    title.textContent='Genel durum';
    value.textContent=formatMoney(net);
    value.classList.remove('text-success','text-danger');
    value.classList.add(net>=0?'text-success':'text-danger');
    note.textContent='Alınacak çek - verilecek çek';
    target.classList.add('status');
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
    updateCheckNetCard();
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