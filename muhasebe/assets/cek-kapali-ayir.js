(function(){
  function qs(sel,root){return (root||document).querySelector(sel);}
  function qsa(sel,root){return Array.from((root||document).querySelectorAll(sel));}
  function norm(s){return String(s||'').toLocaleLowerCase('tr-TR').replace(/ı/g,'i').replace(/ç/g,'c').replace(/ğ/g,'g').replace(/ö/g,'o').replace(/ş/g,'s').replace(/ü/g,'u');}
  function parseMoney(text){
    var m=String(text||'').match(/-?[0-9.]+,[0-9]{2}/); if(!m) return 0;
    var n=parseFloat(m[0].replace(/\./g,'').replace(',','.'));
    return Number.isFinite(n)?n:0;
  }
  function fmt(n){try{return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(n)+' TL';}catch(e){return n.toFixed(2).replace('.',',')+' TL';}}
  function params(){return new URLSearchParams(location.search);}
  function direction(){return params().get('direction')==='verilecek'?'verilecek':'alinacak';}
  function status(){return params().get('status')||'';}
  function statusText(row){var b=qs('.status-badge',row); return norm(b?b.textContent:'');}
  function isClosed(row){var t=statusText(row); return t.indexOf('tahsil edildi')>-1 || t.indexOf('odendi')>-1;}
  function buildUrl(extra){
    var p=params();
    Object.keys(extra).forEach(function(k){ if(extra[k]===null || extra[k]==='') p.delete(k); else p.set(k, extra[k]); });
    var s=p.toString(); return 'cekler.php'+(s?'?'+s:'');
  }
  function addTabs(){
    if(qs('#cekDurumHizliSekmeler')) return;
    var list=qs('.check-list-card'); if(!list) return;
    var dir=direction(), st=status();
    var closedStatus=dir==='verilecek'?'odendi':'tahsil_edildi';
    var closedLabel=dir==='verilecek'?'Ödenenler':'Tahsil edilenler';
    var wrap=document.createElement('nav');
    wrap.id='cekDurumHizliSekmeler';
    wrap.className='cek-durum-tabs';
    wrap.innerHTML='<a class="'+(st===''?'active':'')+'" href="'+buildUrl({status:''})+'">Açık çekler<small>Ana listede bekleyenler</small></a><a class="'+(st===closedStatus?'active':'')+'" href="'+buildUrl({status:closedStatus})+'">'+closedLabel+'<small>Kapalı kayıtlar</small></a><a class="'+(st==='iptal'?'active':'')+'" href="'+buildUrl({status:'iptal',include_cancelled:'1'})+'">İptaller<small>İptal edilen çekler</small></a>';
    list.parentNode.insertBefore(wrap,list);
  }
  function hideClosedOnMain(){
    if(status()!=='') return;
    var hidden=0, visible=0;
    qsa('.check-table tbody tr').forEach(function(row){
      if(row.classList.contains('empty')) return;
      if(isClosed(row)){ row.style.display='none'; row.setAttribute('data-kapali-gizli','1'); hidden++; }
      else { visible++; }
    });
    var headSmall=qs('.check-list-head small');
    if(headSmall){
      headSmall.textContent=visible+' açık kayıt';
      if(hidden>0) headSmall.textContent+=' · '+hidden+' kapalı kayıt ayrıldı';
    }
    var tbody=qs('.check-table tbody');
    if(tbody && visible===0 && hidden>0 && !qs('.cek-kapali-bilgi',tbody)){
      var tr=document.createElement('tr'); tr.className='cek-kapali-bilgi';
      tr.innerHTML='<td colspan="7" class="empty">Açık çek kaydı yok. Tahsil edilen/ödenen çekler üstteki kapalı kayıt sekmesinde.</td>';
      tbody.appendChild(tr);
    }
  }
  function updateTotal(){
    var table=qs('.check-table'); if(!table) return;
    var rows=qsa('tbody tr',table).filter(function(row){return row.style.display!=='none' && !row.classList.contains('empty') && !row.classList.contains('cek-kapali-bilgi') && row.children.length>=4;});
    var total=0,count=0;
    rows.forEach(function(row){var n=parseMoney(row.children[3]?row.children[3].textContent:''); if(n>0){total+=n;count++;}});
    var foot=qs('tfoot.cek-liste-toplam-foot',table);
    if(foot){
      var label=direction()==='verilecek'?'Verilen çek toplamı':'Alınan çek toplamı';
      foot.innerHTML='<tr class="cek-liste-toplam-row"><td colspan="3"><strong>'+label+'</strong><small>Ekranda görünen '+count+' çek</small></td><td><strong>'+fmt(total)+'</strong></td><td colspan="3"></td></tr>';
    }
  }
  function style(){
    if(qs('#cekKapaliAyirStyle')) return;
    var s=document.createElement('style'); s.id='cekKapaliAyirStyle';
    s.textContent='.cek-durum-tabs{display:flex;gap:10px;flex-wrap:wrap;background:#fff;border:1px solid #e5dccf;border-radius:18px;padding:8px;box-shadow:0 10px 26px rgba(7,27,63,.05)}.cek-durum-tabs a{flex:1 1 180px;text-align:center;text-decoration:none;border-radius:14px;padding:11px 14px;font-weight:950;color:#16482e;background:#fbf6ed;border:1px solid transparent}.cek-durum-tabs a.active{background:#c49a4f;color:#102818}.cek-durum-tabs small{display:block;margin-top:3px;font-weight:700;opacity:.72}';
    document.head.appendChild(s);
  }
  function init(){ if(!/cekler\.php/i.test(location.pathname)) return; style(); addTabs(); hideClosedOnMain(); setTimeout(updateTotal,50); }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
