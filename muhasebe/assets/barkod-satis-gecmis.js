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
  var sales=[];

  var style=document.createElement('style');
  style.textContent=''
    +'.pos-history-tabs{display:flex;gap:7px;flex-wrap:wrap;align-items:center}.pos-history-tabs button{border:1px solid #e1d6c8;background:#fff;color:#16482e;border-radius:999px;padding:8px 11px;font-size:11px;font-weight:950;cursor:pointer}.pos-history-tabs button.active{background:#16482e;color:#fff;border-color:#16482e}'
    +'.pos-history-item[data-pos-history-hidden="1"]{display:none!important}.pos-history-method{display:inline-flex!important;width:max-content;margin-top:4px!important;padding:3px 7px;border-radius:999px;background:#f7f1e7;color:#725b32!important;font-size:10px!important;font-weight:900}'
    +'.pos-history-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding-right:6px}.pos-history-actions button{min-height:30px;border-radius:999px;padding:5px 9px;border:1px solid #dfd4c7;background:#fff;color:#16482e;font-size:10px;font-weight:900;cursor:pointer}.pos-history-actions button.danger{color:#b64242}.pos-history-actions button:disabled{opacity:.55;cursor:wait}'
    +'.pos-history-item{display:flex;align-items:center;gap:8px}.pos-history-row{flex:1;min-width:0}'
    +'@media(max-width:680px){.pos-history-tabs{width:100%}.pos-history-tabs button{flex:1 1 30%;padding:8px 6px}.pos-history-item{align-items:stretch;flex-direction:column}.pos-history-actions{padding:0 10px 10px}.pos-history-actions button{flex:1}}';
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
  function counts(){
    var out={cash:0,card:0,credit:0};
    sales.forEach(function(s){if(Object.prototype.hasOwnProperty.call(out,s.payment_method))out[s.payment_method]++;});
    return out;
  }
  function ensureActive(){
    var c=counts();
    if(c[active]) return;
    if(c.cash) active='cash';
    else if(c.card) active='card';
    else if(c.credit) active='credit';
  }
  function render(){
    ensureActive();
    var c=counts();
    var tabs=''
      +'<button type="button" data-history-tab="cash" class="'+(active==='cash'?'active':'')+'">💵 Nakit ('+c.cash+')</button>'
      +'<button type="button" data-history-tab="card" class="'+(active==='card'?'active':'')+'">💳 Kredi Kartı ('+c.card+')</button>'
      +'<button type="button" data-history-tab="credit" class="'+(active==='credit'?'active':'')+'">🧾 Veresiye ('+c.credit+')</button>';

    var rows=sales.map(function(s){
      var hidden=s.payment_method===active?'0':'1';
      var receipt=esc(s.receipt_no||('POS #'+s.id));
      var customer=esc(s.customer_name||s.credit_person_name||'Perakende Müşteri');
      var actions='';
      if(canManage&&(s.payment_method==='cash'||s.payment_method==='card')){
        var target=s.payment_method==='cash'?'card':'cash';
        actions+='<button type="button" data-payment-fix="'+esc(s.id)+'" data-target="'+target+'">'+(target==='card'?'→ Karta çevir':'→ Nakite çevir')+'</button>';
      }
      if(canManage){
        actions+='<button type="button" class="danger" data-history-delete="'+esc(s.id)+'" data-receipt="'+receipt+'">Sil</button>';
      }
      return '<div class="pos-history-item" data-history-payment="'+esc(s.payment_method)+'" data-pos-history-hidden="'+hidden+'">'
        +'<a href="barkod-fis.php?id='+encodeURIComponent(s.id)+'" target="_blank" class="pos-history-row"><span><strong>'+receipt+'</strong><small>'+dateTr(s.sale_date)+' '+esc(String(s.sale_time||'').slice(0,5))+' · '+customer+'</small><small class="pos-history-method">'+esc(methodLabel(s.payment_method))+'</small></span><strong>'+money(s.grand_total)+'</strong></a>'
        +(actions?'<div class="pos-history-actions">'+actions+'</div>':'')
        +'</div>';
    }).join('');

    section.innerHTML='<div class="card-head"><div><h3>Satış Geçmişi</h3><span>Nakit, kredi kartı ve veresiye ayrı görüntülenir.</span></div><div class="pos-history-tabs">'+tabs+'</div></div>'
      +'<div class="pos-history-list">'+(rows||'<p class="muted">Henüz barkodlu satış yok.</p>')+'</div>';
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
        // Mevcut PHP listesini bozma; sunucu geçici cevap vermezse eski liste kalsın.
      });
  }

  section.addEventListener('click',function(event){
    var tab=event.target.closest('[data-history-tab]');
    if(tab){
      active=tab.getAttribute('data-history-tab')||'cash';
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
