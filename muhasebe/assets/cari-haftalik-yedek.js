(function(){
  'use strict';
  var path=(location.pathname||'').split('/').pop();
  if(path!=='dashboard.php'&&path!=='yedekler.php') return;

  function getStatus(){
    return fetch('cari-haftalik-yedek-api.php?action=status&_='+Date.now(),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}})
      .then(function(r){if(!r.ok) throw new Error('Cari yedek bilgisi alınamadı.');return r.json();});
  }

  function post(action,csrf){
    var body=new FormData();
    body.set('action',action);
    body.set('csrf_token',csrf||'');
    return fetch('cari-haftalik-yedek-api.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}})
      .then(function(r){return r.json().then(function(d){if(!r.ok||!d.ok) throw new Error(d.error||'İşlem başarısız.');return d;});});
  }

  if(path==='dashboard.php'){
    getStatus().then(function(data){
      if(!data||!data.ok||!data.due) return;
      var key='cariWeeklyBackup:'+String(data.week_key||'');
      if(sessionStorage.getItem(key)==='1') return;
      sessionStorage.setItem(key,'1');
      return post('auto',data.csrf_token).catch(function(){sessionStorage.removeItem(key);});
    }).catch(function(){});
    return;
  }

  var anchor=document.querySelector('.auto-backup-note')||document.querySelector('.hero-card');
  if(!anchor||document.querySelector('.cari-weekly-backup-card')) return;

  var style=document.createElement('style');
  style.textContent=''
    +'.cari-weekly-backup-card{margin:16px 0;background:#fff;border:1px solid #e5dccf;border-radius:22px;box-shadow:0 12px 34px rgba(7,27,63,.06);overflow:hidden}'
    +'.cari-weekly-backup-head{display:flex;justify-content:space-between;gap:14px;align-items:center;padding:17px 18px;background:#f3faf5;border-bottom:1px solid #e5dccf}'
    +'.cari-weekly-backup-head h3{margin:0;color:#102818}.cari-weekly-backup-head p{margin:5px 0 0;color:#657268;font-size:12px;font-weight:700}'
    +'.cari-weekly-backup-head button{border:0;border-radius:999px;padding:10px 14px;background:#16482e;color:#fff;font-weight:900;cursor:pointer;white-space:nowrap}'
    +'.cari-weekly-backup-head button:disabled{opacity:.6;cursor:wait}.cari-weekly-backup-body{padding:16px 18px}'
    +'.cari-weekly-meta{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}.cari-weekly-meta span{display:inline-flex;padding:6px 9px;border-radius:999px;background:#fbf6ed;color:#6d5b42;font-size:11px;font-weight:850}'
    +'.cari-weekly-list{display:grid;gap:7px}.cari-weekly-row{display:grid;grid-template-columns:minmax(220px,1fr) 150px 90px auto;gap:10px;align-items:center;padding:10px 11px;border:1px solid #eee5d9;border-radius:13px}'
    +'.cari-weekly-row strong{color:#102818;font-size:12px}.cari-weekly-row small{color:#776b5c}.cari-weekly-row a{display:inline-flex;justify-content:center;border-radius:999px;padding:7px 11px;background:#fff;border:1px solid #d8cdbb;color:#16482e;text-decoration:none;font-weight:900;font-size:12px}'
    +'.cari-weekly-empty{padding:14px;border-radius:13px;background:#fbf6ed;color:#776b5c;font-weight:750}.cari-weekly-error{color:#b64242;font-weight:800}'
    +'@media(max-width:760px){.cari-weekly-backup-head{display:grid}.cari-weekly-backup-head button{width:100%}.cari-weekly-row{grid-template-columns:1fr 1fr}.cari-weekly-row strong{grid-column:1/-1}.cari-weekly-row a{width:100%}}';
  document.head.appendChild(style);

  var card=document.createElement('section');
  card.className='cari-weekly-backup-card';
  card.innerHTML=''
    +'<div class="cari-weekly-backup-head"><div><h3>Haftalık Cari Bakiye Excel Yedekleri</h3><p>Carilerdeki alacak ve borç durumunu haftalık fotoğraf olarak saklar. Dosyaları bilgisayarına tek tıkla indirebilirsin.</p></div><button type="button" data-cari-backup-create>⇩ Şimdi oluştur ve Excel indir</button></div>'
    +'<div class="cari-weekly-backup-body"><div class="cari-weekly-meta"><span>Her Pazartesi 08:00</span><span>Son 52 yedek saklanır</span><span>XLSX / Excel</span></div><div class="cari-weekly-list"><div class="cari-weekly-empty">Yedek listesi yükleniyor...</div></div></div>';
  anchor.insertAdjacentElement('afterend',card);

  var list=card.querySelector('.cari-weekly-list');
  var createBtn=card.querySelector('[data-cari-backup-create]');
  var currentCsrf='';

  function render(data){
    currentCsrf=String(data.csrf_token||'');
    var files=Array.isArray(data.files)?data.files:[];
    list.innerHTML='';
    if(!files.length){
      list.innerHTML='<div class="cari-weekly-empty">Henüz haftalık cari Excel yedeği yok. Yukarıdaki düğmeyle ilkini oluşturabilirsin.</div>';
      return;
    }
    files.forEach(function(file,index){
      var row=document.createElement('div');
      row.className='cari-weekly-row';
      var strong=document.createElement('strong');
      strong.textContent=file.name+(index===0?' · Son yedek':'');
      var time=document.createElement('small');time.textContent=file.time||'-';
      var size=document.createElement('small');size.textContent=file.size||'-';
      var link=document.createElement('a');link.href=file.download;link.textContent='⇩ Excel indir';link.setAttribute('download','');
      row.appendChild(strong);row.appendChild(time);row.appendChild(size);row.appendChild(link);list.appendChild(row);
    });
  }

  function refresh(){
    return getStatus().then(render).catch(function(error){list.innerHTML='<div class="cari-weekly-empty cari-weekly-error">'+String(error.message||error)+'</div>';});
  }

  createBtn.addEventListener('click',function(){
    var old=createBtn.textContent;
    createBtn.disabled=true;createBtn.textContent='Excel hazırlanıyor...';
    post('create',currentCsrf).then(function(data){
      return refresh().then(function(){
        if(data.download) window.location.href=data.download;
      });
    }).catch(function(error){alert(error.message||'Cari Excel yedeği oluşturulamadı.');})
      .then(function(){createBtn.disabled=false;createBtn.textContent=old;});
  });

  refresh();
})();
