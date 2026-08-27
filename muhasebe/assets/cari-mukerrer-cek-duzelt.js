(function(){
  'use strict';
  if(!/cari-detay\.php/i.test(location.pathname)) return;

  function txt(el){return (el&&el.textContent||'').replace(/\s+/g,' ').trim();}
  function moneyNumber(text){
    var m=String(text||'').match(/([0-9.]+,[0-9]{2})/);
    if(!m) return 0;
    return Number(m[1].replace(/\./g,'').replace(',','.'))||0;
  }
  function movementId(row){
    var a=row.querySelector('a[href*="hareketler.php?edit="]');
    if(!a) return '';
    try{return new URL(a.getAttribute('href'),location.href).searchParams.get('edit')||'';}catch(e){return '';}
  }
  function cariId(){return new URLSearchParams(location.search).get('id')||'';}
  function csrf(){var el=document.querySelector('input[name="csrf_token"]');return el?el.value:'';}
  function hasDocument(row){var c=row.children[5];return !!(c&&c.querySelector('a'));}
  function hasCheckLink(row){return !!row.querySelector('a[href*="cekler.php"][href*="edit="]');}
  function isActive(row){return !row.classList.contains('row-cancelled')&&!!movementId(row);}
  function checkLike(row){
    var type=txt(row.children[2]).toLocaleLowerCase('tr-TR');
    var category=txt(row.children[3]).toLocaleLowerCase('tr-TR');
    var desc=txt(row.children[4]).toLocaleLowerCase('tr-TR');
    return type.indexOf('tahsilat')!==-1||type.indexOf('ödeme')!==-1||category.indexOf('çek')!==-1||desc.indexOf('çek')!==-1;
  }
  function sameKey(a,b){
    return txt(a.children[0])===txt(b.children[0])
      && txt(a.children[1])===txt(b.children[1])
      && txt(a.children[2])===txt(b.children[2])
      && Math.abs(moneyNumber(txt(a.children[6]))-moneyNumber(txt(b.children[6])))<0.01;
  }
  function style(){
    if(document.getElementById('cariMukerrerCekStyle')) return;
    var s=document.createElement('style');
    s.id='cariMukerrerCekStyle';
    s.textContent='.cari-mukerrer-cek-btn{border:1px solid #d8a84f;background:#fff7df;color:#6e4b0d;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:900;cursor:pointer}.cari-mukerrer-note{display:block;margin-top:5px;color:#8a6a26;font-size:10px;font-weight:800}.cari-mukerrer-working{opacity:.55;pointer-events:none}';
    document.head.appendChild(s);
  }
  function postFix(row,auto){
    var id=movementId(row),cid=cariId(),token=csrf();
    if(!id||!cid||!token) return Promise.reject(new Error('Düzeltme için oturum bilgisi bulunamadı.'));
    row.classList.add('cari-mukerrer-working');
    var body=new URLSearchParams();
    body.set('movement_id',id);body.set('cari_id',cid);body.set('csrf_token',token);
    return fetch('cari-mukerrer-cek-hareket-duzelt.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body:body.toString()})
      .then(function(r){return r.json().catch(function(){return {ok:false,error:'Sunucu cevabı okunamadı.'};});})
      .then(function(d){if(!d.ok) throw new Error(d.error||'Mükerrer hareket düzeltilemedi.');if(!auto) alert(d.message);location.reload();return d;})
      .catch(function(e){row.classList.remove('cari-mukerrer-working');if(!auto) alert(e.message||'Düzeltme yapılamadı.');throw e;});
  }
  function init(){
    var table=document.querySelector('#hareketler table');
    if(!table) return;
    style();
    var rows=Array.prototype.slice.call(table.querySelectorAll('tbody tr')).filter(isActive);
    var candidates=[];
    rows.forEach(function(row){
      if(hasDocument(row)||hasCheckLink(row)||!checkLike(row)) return;
      var keepers=rows.filter(function(other){return other!==row&&sameKey(row,other)&&(hasDocument(other)||hasCheckLink(other));});
      if(keepers.length!==1) return;
      candidates.push({row:row,keep:keepers[0],amount:moneyNumber(txt(row.children[6]))});

      var actions=row.children[7];
      if(actions&&!actions.querySelector('.cari-mukerrer-cek-btn')){
        var btn=document.createElement('button');
        btn.type='button';btn.className='cari-mukerrer-cek-btn';btn.textContent='Mükerrer çek hareketini iptal et';
        btn.title='Görselli/çek kaydına bağlı gerçek hareket korunur; yalnızca bu görselsiz mükerrer cari hareketi iptal edilir.';
        btn.onclick=function(){if(confirm('Bu görselsiz mükerrer çek hareketi iptal edilsin mi? Görselli gerçek çek kaydı korunacak.'))postFix(row,false).catch(function(){});};
        actions.appendChild(btn);
        var note=document.createElement('small');note.className='cari-mukerrer-note';note.textContent='Aynı tutarda gerçek çek hareketi bulundu';actions.appendChild(note);
      }
    });

    // Kullanıcının tarif ettiği 129.000 TL eski çift kaydı: tek ve güvenli eşleşme varsa otomatik düzelt.
    var exact=candidates.filter(function(x){return Math.abs(x.amount-129000)<0.01;});
    if(exact.length===1 && sessionStorage.getItem('cariMukerrer129Auto')!=='done'){
      sessionStorage.setItem('cariMukerrer129Auto','done');
      postFix(exact[0].row,true).catch(function(){sessionStorage.removeItem('cariMukerrer129Auto');});
    }
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
