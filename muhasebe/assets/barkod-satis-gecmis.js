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
  var view='recent';
  var selectedDate=root.dataset.today||new Intl.DateTimeFormat('sv-SE',{timeZone:'Europe/Istanbul'}).format(new Date());
  var requestId=0;
  var loading=false;
  var loadError='';

  var style=document.createElement('style');
  style.textContent=''
    +'.pos-history-cash-grid{grid-column:1/-1;display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:14px;align-items:start}.pos-history-cash-grid>.pos-history{grid-column:auto!important;margin:0}.pos-cash-left-card{display:grid;gap:12px;padding:16px 17px}.pos-cash-left-card h3{margin:0;color:#173c27}.pos-cash-left-card>small{color:#7d6f61;font-size:11px}.pos-cash-left-yesterday{display:grid;gap:3px;padding:12px 13px;border:1px solid #e3d8ca;border-radius:14px;background:#fbf7f1}.pos-cash-left-yesterday span,.pos-cash-left-today label>span{font-size:10px;font-weight:950;letter-spacing:.08em;color:#7d6f61;text-transform:uppercase}.pos-cash-left-yesterday strong{font-size:22px;color:#173c27}.pos-cash-left-yesterday small{color:#8a7b69}.pos-cash-left-today{display:grid;gap:7px}.pos-cash-left-today label{display:grid;gap:5px}.pos-cash-left-entry{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:7px}.pos-cash-left-entry input{min-height:42px;border:1px solid #d9cdbf;border-radius:11px;padding:8px 10px;font-size:16px;font-weight:850}.pos-cash-left-entry button{min-height:42px;border:0;border-radius:11px;padding:8px 13px;background:#16482e;color:#fff;font-weight:900;cursor:pointer}.pos-cash-left-entry button:disabled{opacity:.6;cursor:wait}.pos-cash-left-status{min-height:18px;margin:0;font-size:11px;font-weight:850;color:#167243}'
    +'.pos-history{overflow:hidden}.pos-history-toggle{width:100%;border:0;background:transparent;padding:0;cursor:pointer;text-align:left;color:inherit}.pos-history-toggle-inner{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 18px}.pos-history-toggle-title{display:grid;gap:3px}.pos-history-toggle-title h3{margin:0}.pos-history-toggle-title span{font-size:12px;color:#7d6f61}.pos-history-chevron{font-size:20px;font-weight:950;color:#16482e;transition:transform .2s ease}.pos-history-toggle[aria-expanded="true"] .pos-history-chevron{transform:rotate(180deg)}'
    +'.pos-history-content{border-top:1px solid #eadfd2}.pos-history-content[hidden]{display:none!important}.pos-history-summary{display:flex;gap:7px;flex-wrap:wrap;align-items:center;padding:12px 14px;background:#fbf7f1}.pos-history-summary button{border:1px solid #e1d6c8;background:#fff;color:#16482e;border-radius:14px;padding:9px 11px;font-size:11px;font-weight:950;cursor:pointer;display:grid;gap:2px;min-width:145px;text-align:left}.pos-history-summary button strong{font-size:13px}.pos-history-summary button small{font-size:10px;color:#7d6f61;font-weight:850}.pos-history-summary button.active{background:#16482e;color:#fff;border-color:#16482e}.pos-history-summary button.active small{color:#e8f3ed}'
    +'.pos-history-item[data-pos-history-hidden="1"]{display:none!important}.pos-history-method{display:inline-flex!important;width:max-content;margin-top:4px!important;padding:3px 7px;border-radius:999px;background:#f7f1e7;color:#725b32!important;font-size:10px!important;font-weight:900}'
    +'.pos-history-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding-right:6px}.pos-history-actions button{min-height:30px;border-radius:999px;padding:5px 9px;border:1px solid #dfd4c7;background:#fff;color:#16482e;font-size:10px;font-weight:900;cursor:pointer}.pos-history-actions button.danger{color:#b64242}.pos-history-actions button:disabled{opacity:.55;cursor:wait}'
    +'.pos-history-item{display:flex;align-items:center;gap:8px}.pos-history-row{flex:1;min-width:0}'
    +'@media(max-width:980px){.pos-history-cash-grid{grid-template-columns:1fr}}@media(max-width:680px){.pos-history-toggle-inner{padding:14px}.pos-history-summary{display:grid;grid-template-columns:1fr}.pos-history-summary button{width:100%;min-width:0}.pos-history-item{align-items:stretch;flex-direction:column}.pos-history-actions{padding:0 10px 10px}.pos-history-actions button{flex:1}.pos-cash-left-entry{grid-template-columns:1fr}.pos-cash-left-entry button{width:100%}}';
  style.textContent+='.pos-history-views{display:flex;gap:8px;flex-wrap:wrap;padding:14px}.pos-history-views button{padding:10px 14px;border:1px solid #d9cdbf;border-radius:12px;background:#fff;color:#16482e;cursor:pointer;font-weight:800}.pos-history-views button[aria-pressed="true"]{background:#16482e;color:#fff}.pos-history-date{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:0 14px 14px}.pos-history-date input{padding:8px;border:1px solid #d9cdbf;border-radius:8px}.pos-history-message{padding:14px}';
  style.textContent+='.pos-history-total{display:grid;gap:4px;padding:9px 11px;border:1px solid #e1d6c8;border-radius:14px;background:#fff;color:#16482e;font-size:13px}.pos-history-total small{font-size:10px;color:#7d6f61}';
  document.head.appendChild(style);

  var cashGrid=document.createElement('div');
  cashGrid.className='pos-history-cash-grid';
  section.parentNode.insertBefore(cashGrid,section);
  cashGrid.appendChild(section);

  var cashCard=document.createElement('aside');
  cashCard.className='panel-card pos-cash-left-card';
  cashCard.innerHTML=''
    +'<div><h3>Kasada Bırakılan Para</h3><small>Dünkü tutarı gör, bugün kasada bırakacağın tutarı yaz.</small></div>'
    +'<div class="pos-cash-left-yesterday"><span>Dün</span><strong data-cash-left-yesterday>0,00 TL</strong><small data-cash-left-yesterday-date>—</small></div>'
    +'<div class="pos-cash-left-today"><label><span>Bugün</span><div class="pos-cash-left-entry"><input type="text" inputmode="decimal" autocomplete="off" placeholder="Örn. 2.500" data-cash-left-today><button type="button" data-cash-left-save>Kaydet</button></div></label><p class="pos-cash-left-status" data-cash-left-status></p></div>';
  cashGrid.appendChild(cashCard);

  var cashYesterday=cashCard.querySelector('[data-cash-left-yesterday]');
  var cashYesterdayDate=cashCard.querySelector('[data-cash-left-yesterday-date]');
  var cashToday=cashCard.querySelector('[data-cash-left-today]');
  var cashSave=cashCard.querySelector('[data-cash-left-save]');
  var cashStatus=cashCard.querySelector('[data-cash-left-status]');

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
  function parseAmount(value){
    var text=String(value||'').trim().replace(/\s+/g,'');
    if(!text) return 0;
    if(text.indexOf(',')!==-1&&text.indexOf('.')!==-1) text=text.replace(/\./g,'').replace(',','.');
    else if(text.indexOf(',')!==-1) text=text.replace(',','.');
    var number=Number(text);
    return Number.isFinite(number)?Math.round(number*100)/100:-1;
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
    var tag=view==='past'?'div':'button';
    var attrs=view==='past'?' class="pos-history-total"':' type="button" data-history-tab="'+method+'" class="'+(active===method?'active':'')+'"';
    return '<'+tag+attrs+'>'
      +'<strong>'+icon+' '+label+' · '+money(data.total)+'</strong>'
      +'<small>'+data.count+' satış</small>'
      +'</'+tag+'>';
  }
  function render(){
    if(view==='recent') ensureActive();
    var s=summary();
    var tabs=''
      +tabHtml('cash','💵','Nakit',s.cash)
      +tabHtml('card','💳','Kredi Kartı',s.card)
      +tabHtml('credit','🧾','Veresiye',s.credit);


    var rows=sales.map(function(sale){
      var hidden=view==='past'||sale.payment_method===active?'0':'1';
      var receipt=esc(sale.receipt_no||('POS #'+sale.id));
      var customer=esc(sale.customer_name||sale.credit_person_name||'Perakende Müşteri');
      var actions='';
      if(view==='recent'&&canManage&&(sale.payment_method==='cash'||sale.payment_method==='card')){
        var target=sale.payment_method==='cash'?'card':'cash';
        actions+='<button type="button" data-payment-fix="'+esc(sale.id)+'" data-target="'+target+'">'+(target==='card'?'→ Karta çevir':'→ Nakite çevir')+'</button>';
      }
      if(view==='recent'&&canManage){
        actions+='<button type="button" class="danger" data-history-delete="'+esc(sale.id)+'" data-receipt="'+receipt+'">Sil</button>';
      }
      return '<div class="pos-history-item" data-history-payment="'+esc(sale.payment_method)+'" data-pos-history-hidden="'+hidden+'">'
        +'<a href="barkod-fis.php?id='+encodeURIComponent(sale.id)+'" target="_blank" class="pos-history-row"><span><strong>'+receipt+'</strong><small>'+dateTr(sale.sale_date)+' '+esc(String(sale.sale_time||'').slice(0,5))+' · '+customer+'</small><small class="pos-history-method">'+esc(methodLabel(sale.payment_method))+'</small></span><strong>'+money(sale.grand_total)+'</strong></a>'
        +(actions?'<div class="pos-history-actions">'+actions+'</div>':'')
        +'</div>';
    }).join('');

    var allTotal=s.cash.total+s.card.total+s.credit.total;
    section.innerHTML=''
      +'<div class="pos-history-views" aria-label="Satış geçmişi"><button type="button" data-history-view="recent" aria-pressed="'+(view==='recent')+'">Son Satışlar</button><button type="button" data-history-view="past" aria-pressed="'+(view==='past')+'">Geçmiş Günler</button></div>'
      +(view==='past'?'<label class="pos-history-date">Satış tarihi <input type="date" data-history-date value="'+esc(selectedDate)+'"></label>':'')
      +'<button type="button" class="pos-history-toggle" data-history-toggle aria-expanded="'+(expanded?'true':'false')+'">'
      +'<span class="pos-history-toggle-inner"><span class="pos-history-toggle-title"><h3>'+(view==='past'?dateTr(selectedDate)+' Satışları':'Son Satışlar')+'</h3><span>'+(loading?'Yükleniyor…':loadError?'Satışlar alınamadı':sales.length+' satış · Toplam '+money(allTotal))+' · Tıklayıp aç</span></span><span class="pos-history-chevron">⌄</span></span>'
      +'</button>'
      +'<div class="pos-history-content" data-history-content '+(expanded?'':'hidden')+'>'
      +(loading?'<p class="pos-history-message" role="status">Satışlar yükleniyor…</p>':loadError?'<p class="pos-history-message" role="alert">'+esc(loadError)+' <button type="button" data-history-retry>Tekrar dene</button></p>':'<div class="pos-history-summary">'+tabs+'</div>')
      +'<div class="pos-history-list">'+(loading||loadError?'':rows||'<p class="pos-history-message">'+(view==='past'?'Seçilen gün için satış yok.':'Henüz barkodlu satış yok.')+'</p>')+'</div>'
      +'</div>';
  }

  function loadCashLeft(){
    fetch('barkod-kasa-parasi.php?_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(data){
        if(!data||!data.ok) throw new Error((data&&data.error)||'Kasa bilgisi alınamadı.');
        cashYesterday.textContent=money(data.yesterday_amount||0);
        cashYesterdayDate.textContent=dateTr(data.yesterday_date||'');
        cashToday.value=Number(data.today_amount||0)>0?new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(data.today_amount||0)):'';
        if(data.csrf_token) csrf=data.csrf_token;
      })
      .catch(function(error){cashStatus.textContent=error.message||'Kasa bilgisi alınamadı.';});
  }

  function saveCashLeft(){
    var amount=parseAmount(cashToday.value);
    if(amount<0){cashStatus.textContent='Geçerli bir tutar yaz.';cashToday.focus();return;}
    cashSave.disabled=true;
    cashSave.textContent='Kaydediliyor…';
    cashStatus.textContent='';
    var body=new FormData();
    body.set('csrf_token',csrf);
    body.set('amount',String(amount));
    fetch('barkod-kasa-parasi.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(data){
        if(!data||!data.ok) throw new Error((data&&data.error)||'Kasa tutarı kaydedilemedi.');
        cashYesterday.textContent=money(data.yesterday_amount||0);
        cashYesterdayDate.textContent=dateTr(data.yesterday_date||'');
        cashToday.value=new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(data.today_amount||0));
        cashStatus.textContent=data.message||'Kaydedildi.';
      })
      .catch(function(error){cashStatus.textContent=error.message||'Kasa tutarı kaydedilemedi.';})
      .finally(function(){cashSave.disabled=false;cashSave.textContent='Kaydet';});
  }

  function load(){
    var id=++requestId;
    loading=true;
    loadError='';
    sales=[];
    render();
    var query=view==='past'?'&date='+encodeURIComponent(selectedDate):'';
    fetch(api+'?action=sales'+query+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(r){if(!r.ok) throw new Error('Satış geçmişi alınamadı.');return r.json();})
      .then(function(data){
        if(id!==requestId) return;
        if(!data||data.ok===false||!Array.isArray(data.sales)) throw new Error((data&&data.error)||'Satış geçmişi alınamadı.');
        sales=data.sales;
        loading=false;
        render();
      })
      .catch(function(error){
        if(id!==requestId) return;
        loading=false;
        loadError=error.message||'Satış geçmişi alınamadı.';
        render();
      });
  }

  section.addEventListener('change',function(event){
    if(!event.target.matches('[data-history-date]')) return;
    if(!event.target.value) {event.target.value=selectedDate;return;}
    selectedDate=event.target.value;
    load();
  });

  cashSave.addEventListener('click',saveCashLeft);
  cashToday.addEventListener('keydown',function(event){if(event.key==='Enter'){event.preventDefault();saveCashLeft();}});

  section.addEventListener('click',function(event){
    var viewButton=event.target.closest('[data-history-view]');
    if(viewButton){
      var next=viewButton.getAttribute('data-history-view');
      if(next===view) return;
      view=next;
      expanded=true;
      load();
      return;
    }
    if(event.target.closest('[data-history-retry]')){load();return;}
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
  loadCashLeft();
})();