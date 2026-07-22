(function(){
  if(!/\/(?:faturalar|magaza)\.php$/i.test(location.pathname)) return;

  var state={period:'',csrf:'',canWrite:false};

  function esc(value){
    return String(value==null?'':value).replace(/[&<>\"]/g,function(char){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[char];
    });
  }

  function numberValue(value){
    var text=String(value||'').trim().replace(/\s/g,'');
    if(text.indexOf(',')!==-1) text=text.replace(/\./g,'').replace(',','.');
    var number=parseFloat(text);
    return Number.isFinite(number)?number:0;
  }

  function money(value){
    return Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' TL';
  }

  function periodValue(){
    var input=document.querySelector('input[type="month"][name="period"]');
    var value=input?String(input.value||''):'';
    if(!/^\d{4}-\d{2}$/.test(value)) value=new URLSearchParams(location.search).get('period')||'';
    return /^\d{4}-\d{2}$/.test(value)?value:new Date().toISOString().slice(0,7);
  }

  function defaultDate(period){
    var today=new Date().toISOString().slice(0,10);
    return today.slice(0,7)===period?today:period+'-01';
  }

  function findCard(title){
    var cards=document.querySelectorAll('.dashboard-section .stats-grid .stat-card');
    for(var i=0;i<cards.length;i++){
      var span=cards[i].querySelector('span');
      if(span&&span.textContent.trim()===title) return cards[i];
    }
    return null;
  }

  function refreshKdvCards(){
    fetch('fatura-kdv-devir.php?period='+encodeURIComponent(state.period)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(!data||!data.ok) return;
        var outgoing=findCard('Hesaplanan KDV');
        if(outgoing){
          var strong=outgoing.querySelector('strong');
          var small=outgoing.querySelector('small');
          if(strong) strong.textContent=money(data.outgoing_vat);
          if(small) small.textContent=Number(data.store_sales_count||0)>0
            ?'Giden faturalar + '+Number(data.store_sales_count||0)+' günlük mağaza satışı'
            :'Giden faturaların KDV’si';
        }
        var status=findCard('KDV durumu');
        if(status){
          var statusStrong=status.querySelector('strong');
          var statusSmall=status.querySelector('small');
          if(statusStrong){
            statusStrong.textContent=money(data.net_abs);
            statusStrong.className=data.net_tone==='danger'?'text-danger':'text-success';
          }
          if(statusSmall) statusSmall.textContent=data.net_label||'KDV dengede';
        }
      })
      .catch(function(){});
  }

  function unlockPanel(panel){
    if(!panel) return;
    panel.removeAttribute('inert');
    panel.style.pointerEvents='auto';
    panel.querySelectorAll('form,input,select,textarea,button,label').forEach(function(element){
      element.removeAttribute('inert');
      element.removeAttribute('aria-disabled');
      element.style.pointerEvents='auto';
      if(element.matches('input,textarea')) element.readOnly=false;
      if(element.matches('input,select,textarea,button')) element.disabled=false;
    });
  }

  function buildPanel(){
    var body=document.querySelector('[data-fatura-alt-kontrol-body]');
    if(!body) return null;
    var existing=document.querySelector('[data-magaza-gunluk-satis]');
    if(existing){unlockPanel(existing);return existing;}

    var panel=document.createElement('section');
    panel.className='magaza-satis-panel';
    panel.setAttribute('data-magaza-gunluk-satis','1');
    panel.innerHTML=''
      +'<div class="magaza-satis-head"><div><strong>Mağaza Günlük Satışları</strong><small>Günlük KDV dahil satış toplamını gir; sistem %10 KDV’yi ve matrahı otomatik ayırsın.</small></div><span data-magaza-status></span></div>'
      +'<article class="magaza-mobile-latest" data-magaza-mobile-latest><div class="magaza-mobile-latest-head"><span>En son günlük satış</span><strong data-magaza-latest-date>Yükleniyor…</strong></div><strong class="magaza-mobile-latest-total" data-magaza-latest-gross>0,00 TL</strong><div class="magaza-mobile-latest-breakdown"><span>Matrah <strong data-magaza-latest-subtotal>0,00 TL</strong></span><span>%10 KDV <strong data-magaza-latest-vat>0,00 TL</strong></span></div></article>'
      +'<div class="magaza-satis-summary"><article><span>Aylık satış</span><strong data-magaza-gross>0,00 TL</strong></article><article><span>Matrah</span><strong data-magaza-subtotal>0,00 TL</strong></article><article><span>%10 hesaplanan KDV</span><strong data-magaza-vat>0,00 TL</strong></article><article><span>Satış günü</span><strong data-magaza-count>0</strong></article></div>'
      +'<form class="magaza-satis-form" data-magaza-form autocomplete="off">'
      +'<label>Tarih<input type="date" name="sale_date" required></label>'
      +'<label>KDV dahil günlük satış<input type="text" inputmode="decimal" name="gross_amount" required placeholder="Örn: 40.000,00"></label>'
      +'<label class="magaza-satis-note">Not<input type="text" name="note" maxlength="250" placeholder="İsteğe bağlı, örn: Gün sonu Z raporu"></label>'
      +'<div class="magaza-satis-preview" data-magaza-preview>Matrah: 0,00 TL · %10 KDV: 0,00 TL</div>'
      +'<button type="submit" class="btn btn-primary" data-magaza-save>Günü kaydet</button>'
      +'</form>'
      +'<div class="magaza-satis-list" data-magaza-list><p class="muted">Mağaza satışları yükleniyor...</p></div>';

    var expensePanel=body.querySelector('[data-masraf-fisleri]');
    if(expensePanel) expensePanel.insertAdjacentElement('afterend',panel); else body.appendChild(panel);
    unlockPanel(panel);

    var form=panel.querySelector('[data-magaza-form]');
    form.querySelector('[name="sale_date"]').value=defaultDate(state.period);
    form.addEventListener('input',updatePreview);
    form.addEventListener('change',updatePreview);
    form.addEventListener('submit',saveSale);
    panel.addEventListener('pointerdown',function(event){
      var control=event.target.closest('input,select,textarea,button');
      if(!control) return;
      control.disabled=false;
      control.removeAttribute('inert');
      if(control.matches('input,textarea')) control.readOnly=false;
    },true);
    panel.addEventListener('click',function(event){
      var editButton=event.target.closest('[data-magaza-edit]');
      if(editButton){editSale(editButton);return;}
      var deleteButton=event.target.closest('[data-magaza-delete]');
      if(deleteButton) deleteSale(Number(deleteButton.getAttribute('data-magaza-delete')||0));
    });
    return panel;
  }

  function updatePreview(){
    var form=document.querySelector('[data-magaza-form]');
    var preview=document.querySelector('[data-magaza-preview]');
    if(!form||!preview) return;
    var input=form.querySelector('[name="gross_amount"]');
    var gross=numberValue(input?input.value:0);
    var subtotal=gross/1.10;
    var vat=gross-subtotal;
    preview.textContent='Matrah: '+money(subtotal)+' · %10 KDV: '+money(vat);
  }

  function setStatus(text,tone){
    var element=document.querySelector('[data-magaza-status]');
    if(!element) return;
    element.textContent=text||'';
    element.className=tone?'is-'+tone:'';
  }

  function render(data){
    state.csrf=String(data.csrf_token||state.csrf);
    state.canWrite=!!data.can_write;
    var panel=buildPanel();
    if(!panel) return;
    unlockPanel(panel);

    var form=panel.querySelector('[data-magaza-form]');
    if(form) form.hidden=!state.canWrite;

    var summary=data.summary||{};
    panel.querySelector('[data-magaza-gross]').textContent=money(summary.gross);
    panel.querySelector('[data-magaza-subtotal]').textContent=money(summary.subtotal);
    panel.querySelector('[data-magaza-vat]').textContent=money(summary.vat);
    panel.querySelector('[data-magaza-count]').textContent=String(summary.count||0);

    var list=panel.querySelector('[data-magaza-list]');
    var items=Array.isArray(data.items)?data.items:[];
    var latest=items.slice().sort(function(a,b){
      return String(b.sale_date||'').localeCompare(String(a.sale_date||''));
    })[0]||null;
    var latestCard=panel.querySelector('[data-magaza-mobile-latest]');
    if(latestCard){
      latestCard.classList.toggle('is-empty',!latest);
      latestCard.querySelector('[data-magaza-latest-date]').textContent=latest
        ?String(latest.sale_date||'').split('-').reverse().join('.')
        :'Bu ay kayıt yok';
      latestCard.querySelector('[data-magaza-latest-gross]').textContent=money(latest?latest.gross_amount:0);
      latestCard.querySelector('[data-magaza-latest-subtotal]').textContent=money(latest?latest.subtotal:0);
      latestCard.querySelector('[data-magaza-latest-vat]').textContent=money(latest?latest.vat_amount:0);
    }
    if(!items.length){
      list.innerHTML='<p class="muted">Bu dönemde henüz mağaza günlük satış kaydı yok.</p>';
    }else{
      list.innerHTML='<div class="table-wrap"><table><thead><tr><th>Tarih</th><th>Günlük satış</th><th>Matrah</th><th>%10 KDV</th><th>Not</th><th></th></tr></thead><tbody>'
        +items.map(function(item){
          return '<tr data-magaza-row data-id="'+item.id+'" data-date="'+esc(item.sale_date)+'" data-gross="'+Number(item.gross_amount||0)+'" data-note="'+esc(item.note||'')+'">'
            +'<td data-label="Tarih"><strong>'+esc(item.sale_date.split('-').reverse().join('.'))+'</strong></td>'
            +'<td data-label="Günlük satış"><strong>'+money(item.gross_amount)+'</strong></td>'
            +'<td data-label="Matrah">'+money(item.subtotal)+'</td>'
            +'<td data-label="%10 KDV"><strong>'+money(item.vat_amount)+'</strong></td>'
            +'<td data-label="Not">'+(item.note?esc(item.note):'<span class="muted">-</span>')+'</td>'
            +'<td class="magaza-row-actions" data-label="İşlemler">'+(state.canWrite?'<button type="button" data-magaza-edit>Düzenle</button><button type="button" data-magaza-delete="'+item.id+'">Sil</button>':'')+'</td>'
            +'</tr>';
        }).join('')+'</tbody><tfoot><tr class="magaza-total-row"><td><strong>GENEL TOPLAM</strong></td><td><strong>'+money(summary.gross)+'</strong></td><td><strong>'+money(summary.subtotal)+'</strong></td><td><strong>'+money(summary.vat)+'</strong></td><td colspan="2"></td></tr></tfoot></table></div>';
    }
    setStatus((summary.count||0)+' gün · '+money(summary.gross)+' satış · '+money(summary.vat)+' KDV','success');
    updatePreview();
    refreshKdvCards();
  }

  function editSale(button){
    var row=button.closest('[data-magaza-row]');
    var form=document.querySelector('[data-magaza-form]');
    if(!row||!form) return;
    form.querySelector('[name="sale_date"]').value=row.getAttribute('data-date')||defaultDate(state.period);
    form.querySelector('[name="gross_amount"]').value=Number(row.getAttribute('data-gross')||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});
    form.querySelector('[name="note"]').value=row.getAttribute('data-note')||'';
    var save=form.querySelector('[data-magaza-save]');
    if(save) save.textContent='Günü güncelle';
    updatePreview();
    form.scrollIntoView({behavior:'smooth',block:'center'});
  }

  function load(){
    fetch('magaza-gunluk-satis.php?period='+encodeURIComponent(state.period)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){if(!data.ok) throw new Error(data.error||'Mağaza satışları yüklenemedi.');render(data);})
      .catch(function(error){setStatus(error.message||'Mağaza satışları yüklenemedi.','danger');});
  }

  function saveSale(event){
    event.preventDefault();
    var form=event.target;
    var button=form.querySelector('[data-magaza-save]');
    var oldText=button.textContent;
    button.disabled=true;
    button.textContent='Kaydediliyor...';
    var body=new FormData(form);
    body.set('action','save');
    body.set('period',state.period);
    body.set('csrf_token',state.csrf);
    fetch('magaza-gunluk-satis.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(!data.ok) throw new Error(data.error||'Mağaza günlük satışı kaydedilemedi.');
        form.reset();
        form.querySelector('[name="sale_date"]').value=defaultDate(state.period);
        render(data);
        setStatus('Günlük mağaza satışı kaydedildi ve hesaplanan KDV güncellendi.','success');
      })
      .catch(function(error){setStatus(error.message||'Mağaza günlük satışı kaydedilemedi.','danger');})
      .finally(function(){button.disabled=false;button.textContent='Günü kaydet';});
  }

  function deleteSale(id){
    if(!id||!window.confirm('Bu günlük mağaza satış kaydı silinsin mi?')) return;
    var body=new FormData();
    body.set('action','delete');
    body.set('id',String(id));
    body.set('period',state.period);
    body.set('csrf_token',state.csrf);
    fetch('magaza-gunluk-satis.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){if(!data.ok) throw new Error(data.error||'Mağaza satış kaydı silinemedi.');render(data);setStatus('Günlük mağaza satış kaydı silindi.','success');})
      .catch(function(error){setStatus(error.message||'Mağaza satış kaydı silinemedi.','danger');});
  }

  var style=document.createElement('style');
  style.textContent=''
    +'.magaza-satis-panel{display:grid;gap:14px;padding:15px;border:1px solid #d8c6a5;background:linear-gradient(135deg,#fff9ed,#fff);border-radius:16px;position:relative;z-index:34;pointer-events:auto!important}'
    +'.magaza-satis-panel *{pointer-events:auto}'
    +'.magaza-satis-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.magaza-satis-head>div{display:grid;gap:4px}.magaza-satis-head strong{font-size:15px}.magaza-satis-head small{font-size:10px;color:var(--muted)}.magaza-satis-head>span{font-size:10px;color:var(--muted)}.magaza-satis-head>span.is-success{color:var(--success)}.magaza-satis-head>span.is-danger{color:var(--danger)}'
    +'.magaza-satis-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}.magaza-satis-summary article{display:grid;gap:4px;padding:10px 12px;border:1px solid var(--border);background:#fff;border-radius:12px}.magaza-satis-summary span{font-size:9px;color:var(--muted);font-weight:800}.magaza-satis-summary strong{font-size:13px}'
    +'.magaza-mobile-latest{display:none}'
    +'.magaza-satis-form{display:grid;grid-template-columns:150px minmax(220px,1fr) minmax(220px,1.2fr) minmax(210px,.9fr) auto;gap:9px;align-items:end}.magaza-satis-form label{display:grid;gap:5px;font-size:10px;font-weight:800}.magaza-satis-form input{width:100%;border:1px solid var(--border);background:#fff;border-radius:10px;padding:9px 10px;user-select:text!important;-webkit-user-select:text!important}.magaza-satis-preview{font-size:10px;color:#6d5018;padding:9px 10px;border-radius:10px;background:#fff2d7}.magaza-satis-form>.btn{white-space:nowrap}'
    +'.magaza-satis-list table{min-width:760px}.magaza-satis-list th,.magaza-satis-list td{font-size:10px;padding:8px}.magaza-row-actions{display:flex;gap:8px}.magaza-row-actions button{border:0;background:transparent;color:var(--accent);font-weight:800;cursor:pointer;padding:0}.magaza-row-actions button:last-child{color:var(--danger)}.magaza-total-row td{border-top:2px solid #d8c6a5;background:#fff6e5;font-size:10px}.magaza-total-row strong{font-size:11px}'
    +'@media(max-width:1100px){.magaza-satis-form{grid-template-columns:1fr 1fr 1fr}.magaza-satis-preview{grid-column:1/3}.magaza-satis-summary{grid-template-columns:1fr 1fr}}'
    +'@media(max-width:650px){.magaza-satis-panel{gap:10px;padding:12px}.magaza-satis-head{display:none}.magaza-mobile-latest{display:grid;order:2;gap:8px;padding:14px;border:1px solid #d7bd83;border-radius:16px;background:linear-gradient(145deg,#173e2b,#0f2d20);color:#fff;box-shadow:0 12px 26px rgba(15,45,32,.18)}.magaza-mobile-latest.is-empty{opacity:.72}.magaza-mobile-latest-head{display:flex;justify-content:space-between;gap:10px;align-items:center}.magaza-mobile-latest-head span{font-size:9px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;color:#e6c782}.magaza-mobile-latest-head strong{font-size:11px}.magaza-mobile-latest-total{font-size:28px;line-height:1.05;color:#fff}.magaza-mobile-latest-breakdown{display:grid;grid-template-columns:1fr 1fr;gap:8px}.magaza-mobile-latest-breakdown span{display:grid;gap:3px;padding:8px 9px;border:1px solid rgba(255,255,255,.12);border-radius:10px;background:rgba(255,255,255,.06);font-size:8px;color:rgba(255,255,255,.7)}.magaza-mobile-latest-breakdown strong{font-size:11px;color:#fff}.magaza-satis-list{order:3}.magaza-satis-summary{order:4;grid-template-columns:1fr 1fr}.magaza-satis-form{order:5;grid-template-columns:1fr}.magaza-satis-preview{grid-column:auto}.magaza-satis-list table{min-width:0;width:100%;border-collapse:separate}.magaza-satis-list thead,.magaza-satis-list tfoot{display:none}.magaza-satis-list tbody{display:grid;gap:9px}.magaza-satis-list tr{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:11px;border:1px solid var(--border);border-radius:13px;background:#fff;box-shadow:0 5px 14px rgba(7,27,63,.05)}.magaza-satis-list td{display:grid;gap:3px;padding:0!important;border:0!important;font-size:11px}.magaza-satis-list td:before{content:attr(data-label);font-size:8px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;color:var(--muted)}.magaza-satis-list td:nth-child(5),.magaza-satis-list td:nth-child(6){grid-column:1/-1}.magaza-row-actions{display:flex!important;flex-direction:row;justify-content:flex-end;padding-top:6px!important;border-top:1px solid var(--border)!important}.magaza-row-actions:before{margin-right:auto}}';
  document.head.appendChild(style);

  state.period=periodValue();
  var attempts=0;
  function start(){
    attempts++;
    var panel=buildPanel();
    if(panel){load();return;}
    if(attempts<40) window.setTimeout(start,100);
  }
  start();
})();
