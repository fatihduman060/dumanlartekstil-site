(function(){
  var root=document.querySelector('[data-pos-root]');
  if(!root) return;
  var section=root.querySelector('.pos-history');
  if(!section) return;

  var api=root.dataset.api||'barkod-satis-api.php';
  var csrf=root.dataset.csrf||'';
  var status=root.querySelector('[data-pos-status]');
  var canManage=!!section.querySelector('[data-sale-delete]');
  var active='cash';
  var expanded=false;
  var sales=[];

  var style=document.createElement('style');
  style.textContent=''
    +'.pos-history{overflow:hidden}.pos-history-toggle{width:100%;border:0;background:transparent;padding:0;cursor:pointer;text-align:left;color:inherit}.pos-history-toggle-inner{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 18px}.pos-history-toggle-title{display:grid;gap:3px}.pos-history-toggle-title h3{margin:0}.pos-history-toggle-title span{font-size:12px;color:#7d6f61}.pos-history-chevron{font-size:20px;font-weight:950;color:#16482e;transition:transform .2s ease}.pos-history-toggle[aria-expanded="true"] .pos-history-chevron{transform:rotate(180deg)}'
    +'.pos-history-content{border-top:1px solid #eadfd2}.pos-history-content[hidden]{display:none!important}.pos-history-summary{display:flex;gap:7px;flex-wrap:wrap;align-items:center;padding:12px 14px;background:#fbf7f1}.pos-history-summary button{border:1px solid #e1d6c8;background:#fff;color:#16482e;border-radius:14px;padding:9px 11px;font-size:11px;font-weight:950;cursor:pointer;display:grid;gap:2px;min-width:145px;text-align:left}.pos-history-summary button strong{font-size:13px}.pos-history-summary button small{font-size:10px;color:#7d6f61;font-weight:850}.pos-history-summary button.active{background:#16482e;color:#fff;border-color:#16482e}.pos-history-summary button.active small{color:#e8f3ed}'
    +'.pos-history-item[data-pos-history-hidden="1"]{display:none!important}.pos-history-method{display:inline-flex!important;width:max-content;margin-top:4px!important;padding:3px 7px;border-radius:999px;background:#f7f1e7;color:#725b32!important;font-size:10px!important;font-weight:900}'
    +'.pos-history-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding-right:6px}.pos-history-actions button{min-height:30px;border-radius:999px;padding:5px 9px;border:1px solid #dfd4c7;background:#fff;color:#16482e;font-size:10px;font-weight:900;cursor:pointer}.pos-history-actions button.danger{color:#b64242}.pos-history-actions button:disabled{opacity:.55;cursor:wait}'
    +'.pos-history-item{display:flex;align-items:center;gap:8px}.pos-history-row{flex:1;min-width:0}'
    +'@media(max-width:680px){.pos-history-toggle-inner{padding:14px}.pos-history-summary{display:grid;grid-template-columns:1fr}.pos-history-summary button{width:100%;min-width:0}.pos-history-item{align-items:stretch;flex-direction:column}.pos-history-actions{padding:0 10px 10px}.pos-history-actions button{flex:1}}';
  document.head.appendChild(style);

  function esc(value){
    return String(value==null?'':value).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});
  }
  function money(value){
    return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(value||0))+' TL';
  }
  function dateTr(value){
    var p=String(value||'').split('-');
    return p.length===3?p[2]+'.'+p[1]+'.'+p[0]:String(value||'');
  }
  function methodLabel(method){
    if(method==='cash') return 'Nakit';
    if(method==='card') return 'Kredi Kartı';
    if(method==='credit') return 'Veresiye';
    return method||'-';
  }
  function summary(){
    var out={cash:{count:0,total:0},card:{count:0,total:0},credit:{count:0,total:0}};
    sales.forEach(function(s){
      var method=s.payment_method;
      if(!Object.prototype.hasOwnProperty.call(out,method)) return;
      out[method].count++;
      out[method].total+=Number(s.grand_total||0);
    });
    return out;
  }
  function ensureActive(){
    var s=summary();
    if(s[active]&&s[active].count) return;
    if(s.cash.count) active='cash';
    else if(s.card.count) active='card';
    else if(s.credit.count) active='credit';
  }
  function tabHtml(method,icon,label,data){
    return '<button type="button" data-history-tab="'+method+'" class="'+(active===method?'active':'')+'">'
      +'<strong>'+icon+' '+label+' · '+money(data.total)+'</strong>'
      +'<small>'+data.count+' satış</small>'
      +'</button>';
  }
  function render(){
    ensureActive();
    var s=summary();
    var tabs=''
      +tabHtml('cash','💵','Nakit',s.cash)
      +tabHtml('card','💳','Kredi Kartı',s.card)
      +tabHtml('credit','🧾','Veresiye',s.credit);

    var rows=sales.map(function(sale){
      var hidden=sale.payment_method===active?'0':'1';
      var receipt=esc(sale.receipt_no||('POS #'+sale.id));
      var customer=esc(sale.customer_name||sale.credit_person_name||'Perakende Müşteri');
      var actions='';
      if(canManage&&(sale.payment_method==='cash'||sale.payment_method==='card')){
        var target=sale.payment_method==='cash'?'card':'cash';
        actions+='<button type="button" data-payment-fix="'+esc(sale.id)+'" data-target="'+target+'">'+(target==='card'?'→ Karta çevir':'→ Nakite çevir')+'</button>';
      }
      if(canManage){
        actions+='<button type="button" class="danger" data-history-delete="'+esc(sale.id)+'" data-receipt="'+receipt+'">Sil</button>';
      }
      return '<div class="pos-history-item" data-history-payment="'+esc(sale.payment_method)+'" data-pos-history-hidden="'+hidden+'">'
        +'<a href="barkod-fis.php?id='+encodeURIComponent(sale.id)+'" target="_blank" class="pos-history-row"><span><strong>'+receipt+'</strong><small>'+dateTr(sale.sale_date)+' '+esc(String(sale.sale_time||'').slice(0,5))+' · '+customer+'</small><small class="pos-history-method">'+esc(methodLabel(sale.payment_method))+'</small></span><strong>'+money(sale.grand_total)+'</strong></a>'
        +(actions?'<div class="pos-history-actions">'+actions+'</div>':'')
        +'</div>';
    }).join('');

    var allTotal=s.cash.total+s.card.total+s.credit.total;
    section.innerHTML=''
      +'<button type="button" class="pos-history-toggle" data-history-toggle aria-expanded="'+(expanded?'true':'false')+'">'
      +'<span class="pos-history-toggle-inner"><span class="pos-history-toggle-title"><h3>Son Satışlar</h3><span>'+sales.length+' satış · Toplam '+money(allTotal)+' · Tıklayıp aç</span></span><span class="pos-history-chevron">⌄</span></span>'
      +'</button>'
      +'<div class="pos-history-content" data-history-content '+(expanded?'':'hidden')+'>'
      +'<div class="pos-history-summary">'+tabs+'</div>'
      +'<div class="pos-history-list">'+(rows||'<p class="muted">Henüz barkodlu satış yok.</p>')+'</div>'
      +'</div>';
  }

  function load(){
    fetch(api+'?action=sales&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(data){
        if(!data||data.ok===false) throw new Error((data&&data.error)||'Satış geçmişi alınamadı.');
        sales=Array.isArray(data.sales)?data.sales:[];
        render();
      })
      .catch(function(){
        // Sunucu geçici cevap vermezse mevcut PHP listesini bozma.
      });
  }

  section.addEventListener('click',function(event){
    var toggle=event.target.closest('[data-history-toggle]');
    if(toggle){
      expanded=!expanded;
      render();
      return;
    }

    var tab=event.target.closest('[data-history-tab]');
    if(tab){
      active=tab.getAttribute('data-history-tab')||'cash';
      expanded=true;
      render();
      return;
    }

    var fix=event.target.closest('[data-payment-fix]');
    if(fix){
      var saleId=fix.getAttribute('data-payment-fix');
      var target=fix.getAttribute('data-target');
      var targetText=target==='card'?'Kredi Kartı':'Nakit';
      if(!confirm('Bu satışın ödeme şekli '+targetText+' olarak değiştirilsin mi? Satış tutarı değişmeyecek.')) return;
      fix.disabled=true;
      var body=new FormData();
      body.set('csrf_token',csrf);
      body.set('sale_id',saleId);
      body.set('payment_method',target);
      fetch('barkod-satis-odeme-duzelt.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
        .then(function(r){return r.json();})
        .then(function(data){if(!data||!data.ok)throw new Error((data&&data.error)||'Ödeme şekli değiştirilemedi.');if(status)status.textContent=data.message;location.reload();})
        .catch(function(error){if(status)status.textContent=error.message;fix.disabled=false;});
      return;
    }

    var del=event.target.closest('[data-history-delete]');
    if(del){
      var id=del.getAttribute('data-history-delete');
      var receipt=del.getAttribute('data-receipt')||'';
      if(!confirm(receipt+' numaralı satış silinsin mi? Stok ve mağaza toplamları geri alınacak.')) return;
      del.disabled=true;
      var deleteBody=new FormData();
      deleteBody.set('action','delete_sale');
      deleteBody.set('csrf_token',csrf);
      deleteBody.set('sale_id',id);
      fetch(api,{method:'POST',body:deleteBody,credentials:'same-origin',cache:'no-store'})
        .then(function(r){return r.json();})
        .then(function(data){if(!data||!data.ok)throw new Error((data&&data.error)||'Satış silinemedi.');if(status)status.textContent=data.message;location.reload();})
        .catch(function(error){if(status)status.textContent=error.message;del.disabled=false;});
    }
  });

  load();
})();
