(function(){
  'use strict';
  if(!/\/cekler\.php$/i.test(location.pathname)) return;

  function parseMoney(text){
    var raw=String(text||'').replace(/[^0-9,.-]/g,'').replace(/\./g,'').replace(',','.');
    var n=parseFloat(raw);
    return isFinite(n)?n:0;
  }
  function trDateToIso(text){
    var m=String(text||'').match(/(\d{2})\.(\d{2})\.(\d{4})/);
    return m ? m[3]+'-'+m[2]+'-'+m[1] : '';
  }
  function queryParam(href,name){
    try{return new URL(href,location.href).searchParams.get(name)||'';}catch(e){return '';}
  }
  function csrf(){
    var el=document.querySelector('input[name="csrf_token"]');
    return el ? el.value : '';
  }
  function addStyle(){
    if(document.getElementById('checkRestoreUiStyle')) return;
    var s=document.createElement('style');
    s.id='checkRestoreUiStyle';
    s.textContent='.check-restore-btn{display:inline-flex;align-items:center;justify-content:center;min-height:34px;border:1px solid #9bc7aa;border-radius:999px;padding:6px 11px;background:#eaf7ee;color:#155b34;font-size:11px;font-weight:950;cursor:pointer}.check-restore-btn:hover{background:#dff1e5}.check-restore-btn:disabled{opacity:.55;cursor:wait}.check-restore-note{display:block;margin-top:6px!important;color:#776b5c!important;font-size:10px!important;line-height:1.35}';
    document.head.appendChild(s);
  }
  function enhance(){
    var table=document.querySelector('.check-table');
    if(!table) return;
    addStyle();
    table.querySelectorAll('tbody tr').forEach(function(row){
      if(row.dataset.restoreReady==='1') return;
      var badge=row.querySelector('.status-badge');
      if(!badge || String(badge.textContent||'').trim()!=='İptal') return;
      if(row.children.length<7) return;
      row.dataset.restoreReady='1';

      var actionCell=row.children[6];
      var holder=actionCell.querySelector('.row-control')||actionCell;
      var cariLink=row.children[0].querySelector('a[href*="cari-detay.php"]');
      var cariId=cariLink?queryParam(cariLink.getAttribute('href'),'id'):'';
      var directionText=(row.children[0].querySelector('b')||{}).textContent||'';
      var direction=String(directionText).toLocaleLowerCase('tr-TR').indexOf('verilen')!==-1?'verilecek':'alinacak';
      var checkNoEl=row.children[1].querySelector('span');
      var checkNo=checkNoEl?String(checkNoEl.textContent||'').trim():'';
      if(checkNo==='-') checkNo='';
      var dueEl=row.children[2].querySelector('b');
      var dueDate=trDateToIso(dueEl?dueEl.textContent:'');
      var amountEl=row.children[3].querySelector('b');
      var amount=parseMoney(amountEl?amountEl.textContent:'');

      var btn=document.createElement('button');
      btn.type='button';
      btn.className='check-restore-btn';
      btn.textContent='İptali geri al';
      holder.appendChild(btn);

      var note=document.createElement('small');
      note.className='check-restore-note';
      note.textContent='Çeki ve bağlı cari hareketini yeniden aktif eder.';
      holder.appendChild(note);

      btn.addEventListener('click',function(){
        if(!cariId||!dueDate||amount<=0){alert('Çek bilgileri okunamadı. Sayfayı yenileyip tekrar deneyin.');return;}
        if(!confirm('Bu çek iptallerden çıkarılıp normal çek listesine geri alınsın mı? Bağlı cari hareketi de yeniden aktif olacak.')) return;
        var token=csrf();
        if(!token){alert('Oturum doğrulaması bulunamadı. Sayfayı yenileyip tekrar deneyin.');return;}
        btn.disabled=true;btn.textContent='Geri alınıyor…';
        var body=new URLSearchParams();
        body.set('csrf_token',token);
        body.set('cari_id',cariId);
        body.set('direction',direction);
        body.set('check_no',checkNo);
        body.set('due_date',dueDate);
        body.set('amount',String(amount));
        fetch('cek-iptal-geri-al.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()})
          .then(function(r){return r.json().catch(function(){return {ok:false,error:'Sunucu cevabı okunamadı.'};});})
          .then(function(d){if(!d.ok) throw new Error(d.error||'Çek geri alınamadı.');alert(d.message||'Çek geri alındı.');location.href='cekler.php?direction='+(d.direction||direction);})
          .catch(function(e){alert(e.message||'Çek geri alınamadı.');btn.disabled=false;btn.textContent='İptali geri al';});
      });
    });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',enhance); else enhance();
})();
