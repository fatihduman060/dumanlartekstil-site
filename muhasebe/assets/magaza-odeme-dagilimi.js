(function(){
  if(!/\/magaza\.php$/i.test(location.pathname)) return;

  var state={period:'',csrf:'',canWrite:false,previousRequest:0};
  function qs(selector,root){return (root||document).querySelector(selector);}
  function esc(value){return String(value==null?'':value).replace(/[&<>\"]/g,function(char){return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[char];});}
  function numberValue(value){var text=String(value||'').trim().replace(/\s/g,'');if(text.indexOf(',')!==-1) text=text.replace(/\./g,'').replace(',','.');var number=parseFloat(text);return Number.isFinite(number)?number:0;}
  function money(value){return Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' TL';}
  function displayDate(value){var parts=String(value||'').split('-');return parts.length===3?parts.reverse().join('.'):String(value||'');}
  function settlementDate(value){
    if(!/^\d{4}-\d{2}-\d{2}$/.test(String(value||''))) return '';
    var date=new Date(String(value)+'T12:00:00');
    if(Number.isNaN(date.getTime())) return '';
    date.setDate(date.getDate()+13);
    return date.getFullYear()+'-'+String(date.getMonth()+1).padStart(2,'0')+'-'+String(date.getDate()).padStart(2,'0');
  }
  function periodValue(){var input=qs('input[type="month"][name="period"]');var value=input?String(input.value||''):'';if(!/^\d{4}-\d{2}$/.test(value)) value=new URLSearchParams(location.search).get('period')||'';return /^\d{4}-\d{2}$/.test(value)?value:new Date().toISOString().slice(0,7);}
  function defaultDate(period){var today=new Date().toISOString().slice(0,10);return today.slice(0,7)===period?today:period+'-01';}
  function setStatus(text,tone){var element=qs('[data-magaza-odeme-status]');if(!element) return;element.textContent=text||'';element.className=tone?'is-'+tone:'';}
  function fieldValue(form,name){var input=qs('[name="'+name+'"]',form);return numberValue(input?input.value:0);}
  function formAmounts(form){
    var cash=fieldValue(form,'cash_amount'),card=fieldValue(form,'card_amount'),credit=fieldValue(form,'credit_amount');
    var cashCollection=fieldValue(form,'cash_credit_collection_amount'),cardCollection=fieldValue(form,'card_credit_collection_amount');
    return {cash:cash,card:card,credit:credit,cashCollection:cashCollection,cardCollection:cardCollection,cashChangeLeft:fieldValue(form,'cash_change_left_amount'),cashTotal:cash+cashCollection,cardTotal:card+cardCollection,dailyTotal:cash+card+credit};
  }
  function updatePreview(){
    var form=qs('[data-magaza-odeme-form]'),preview=qs('[data-magaza-odeme-preview]');
    if(!form||!preview) return;
    var amounts=formAmounts(form),saleDate=qs('[name="sale_date"]',form).value,cardDate=settlementDate(saleDate);
    preview.innerHTML=''
      +'<span><small>Ana Kasa · aynı gün</small><strong>'+money(amounts.cashTotal)+'</strong></span>'
      +'<span><small>Garanti Dumanlar · '+esc(displayDate(cardDate))+'</small><strong>'+money(amounts.cardTotal)+'</strong></span>'
      +'<span><small>Günlük satış toplamı</small><strong>'+money(amounts.dailyTotal)+'</strong></span>'
      +'<span><small>Kasada bırakılacak bozuk para</small><strong>'+money(amounts.cashChangeLeft)+'</strong></span>'
      +'<em>Nakit aynı gün Ana Kasa’ya girer. Kart/POS tutarı satıştan 13 gün sonra Garanti Dumanlar’a işlenir. Veresiye satış ve bozuk para hesaplara aktarılmaz.</em>';
  }
  function loadPreviousCashChange(saleDate){
    var box=qs('[data-magaza-onceki-bozuk]');
    if(!box||!/^\d{4}-\d{2}-\d{2}$/.test(String(saleDate||''))) return;
    var requestId=++state.previousRequest;
    box.className='magaza-onceki-bozuk is-loading';
    box.innerHTML='<span>Önceki kasa bilgisi</span><strong>Kontrol ediliyor...</strong>';
    fetch('magaza-odeme-dagilimi.php?action=previous_cash_change&sale_date='+encodeURIComponent(saleDate)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(requestId!==state.previousRequest) return;
        if(!data||!data.ok) throw new Error(data&&data.error?data.error:'Önceki kasa bilgisi okunamadı.');
        var previous=data.previous||{};box.className='magaza-onceki-bozuk';
        if(previous.sale_date) box.innerHTML='<span>Bir önceki kayıt · '+esc(displayDate(previous.sale_date))+'</span><strong>Kasada kalan bozuk para: '+money(previous.amount)+'</strong><small>Bugünkü akşam hesabında karşılaştırma için gösterilir; hesaplara dahil değildir.</small>';
        else box.innerHTML='<span>Önceki kasa bilgisi</span><strong>Bu tarihten önce kayıtlı bozuk para bilgisi yok.</strong><small>Bu alan yalnızca bilgi amaçlıdır.</small>';
      })
      .catch(function(error){if(requestId!==state.previousRequest) return;box.className='magaza-onceki-bozuk is-error';box.innerHTML='<span>Önceki kasa bilgisi</span><strong>'+esc(error.message||'Bilgi okunamadı.')+'</strong>';});
  }
  function buildPanel(){
    var body=qs('[data-magaza-odeme-dagilimi-body]');if(!body) return null;
    var existing=qs('[data-magaza-odeme-dagilimi]');if(existing) return existing;
    var panel=document.createElement('section');panel.className='magaza-odeme-panel';panel.setAttribute('data-magaza-odeme-dagilimi','1');
    panel.innerHTML=''
      +'<div class="magaza-odeme-head"><div><strong>Günlük Satış Dağılımı</strong><small>Nakit girişler aynı gün Ana Kasa’ya; kart/POS girişleri 13 gün sonra Garanti Dumanlar hesabına otomatik işlenir. Veresiye satış ve kasada bırakılan bozuk para aktarılmaz.</small></div><span data-magaza-odeme-status></span></div>'
      +'<div class="magaza-odeme-summary"><article><span>Nakit kasa</span><strong data-magaza-odeme-cash>0,00 TL</strong><small>Satış + nakit tahsilat</small></article><article><span>Kredi kartı / POS</span><strong data-magaza-odeme-card>0,00 TL</strong><small>Satış + kart tahsilat</small></article><article><span>Veresiye satış</span><strong data-magaza-odeme-credit>0,00 TL</strong></article><article><span>Nakit veresiye tahsilatı</span><strong data-magaza-odeme-cash-collection>0,00 TL</strong></article><article><span>Kart veresiye tahsilatı</span><strong data-magaza-odeme-card-collection>0,00 TL</strong></article><article><span>Günlük satış toplamları</span><strong data-magaza-odeme-total>0,00 TL</strong></article></div>'
      +'<div class="magaza-onceki-bozuk" data-magaza-onceki-bozuk><span>Önceki kasa bilgisi</span><strong>Kontrol ediliyor...</strong></div>'
      +'<form class="magaza-odeme-form" data-magaza-odeme-form autocomplete="off"><label>Tarih<input type="date" name="sale_date" required></label><label>Nakit satış<input type="text" inputmode="decimal" name="cash_amount" placeholder="0,00"><small>Aynı gün Ana Kasa</small></label><label>Kredi kartı satış<input type="text" inputmode="decimal" name="card_amount" placeholder="0,00"><small>13 gün sonra Garanti</small></label><label>Veresiye satış<input type="text" inputmode="decimal" name="credit_amount" placeholder="0,00"></label><label>Nakit veresiye tahsilatı<input type="text" inputmode="decimal" name="cash_credit_collection_amount" placeholder="0,00"></label><label>Kart veresiye tahsilatı<input type="text" inputmode="decimal" name="card_credit_collection_amount" placeholder="0,00"></label><label>Kasada bırakılan bozuk para<input type="text" inputmode="decimal" name="cash_change_left_amount" placeholder="0,00"><small>Hesaplara katılmaz</small></label><div class="magaza-odeme-preview" data-magaza-odeme-preview></div><button type="submit" class="btn btn-primary" data-magaza-odeme-save>Günü kaydet</button></form>'
      +'<div class="magaza-odeme-list" data-magaza-odeme-list><p class="muted">Günlük satış dağılımı yükleniyor...</p></div>';
    body.appendChild(panel);
    var form=qs('[data-magaza-odeme-form]',panel),dateInput=qs('[name="sale_date"]',form);dateInput.value=defaultDate(state.period);
    form.addEventListener('input',updatePreview);form.addEventListener('change',function(event){updatePreview();if(event.target&&event.target.name==='sale_date') loadPreviousCashChange(event.target.value);});form.addEventListener('submit',saveEntry);
    panel.addEventListener('click',function(event){var editButton=event.target.closest('[data-magaza-odeme-edit]');if(editButton){editEntry(editButton);return;}var deleteButton=event.target.closest('[data-magaza-odeme-delete]');if(deleteButton) deleteEntry(Number(deleteButton.getAttribute('data-magaza-odeme-delete')||0));});
    updatePreview();loadPreviousCashChange(dateInput.value);return panel;
  }
  function render(data){
    state.csrf=String(data.csrf_token||state.csrf);state.canWrite=!!data.can_write;
    var panel=buildPanel();if(!panel) return;var form=qs('[data-magaza-odeme-form]',panel);if(form) form.hidden=!state.canWrite;
    var summary=data.summary||{};qs('[data-magaza-odeme-cash]',panel).textContent=money(summary.cash);qs('[data-magaza-odeme-card]',panel).textContent=money(summary.card);qs('[data-magaza-odeme-credit]',panel).textContent=money(summary.credit);qs('[data-magaza-odeme-cash-collection]',panel).textContent=money(summary.cash_credit_collection);qs('[data-magaza-odeme-card-collection]',panel).textContent=money(summary.card_credit_collection);qs('[data-magaza-odeme-total]',panel).textContent=money(summary.daily_total);
    var list=qs('[data-magaza-odeme-list]',panel),items=Array.isArray(data.items)?data.items:[];
    if(!items.length) list.innerHTML='<p class="muted">Bu dönemde henüz günlük satış dağılımı kaydı yok.</p>';
    else list.innerHTML='<div class="table-wrap"><table><thead><tr><th>Tarih</th><th>Ana Kasa</th><th>Garanti Dumanlar</th><th>Veresiye satış</th><th>Nakit veresiye tahsilatı</th><th>Kart veresiye tahsilatı</th><th>Kasada bırakılan bozuk para</th><th>Günlük satış toplamı</th><th></th></tr></thead><tbody>'
      +items.map(function(item){
        var cashStatus=item.cash_total_amount>0?(item.cash_posted?'Ana Kasa’ya işlendi':'İşlem gününü bekliyor'):'Nakit giriş yok';
        var cardStatus=item.card_total_amount>0?(item.card_posted?'Garanti hesabına işlendi':displayDate(item.card_settlement_date)+' tarihinde geçecek'):'Kart girişi yok';
        return '<tr data-magaza-odeme-row data-id="'+item.id+'" data-date="'+esc(item.sale_date)+'" data-cash="'+Number(item.cash_amount||0)+'" data-card="'+Number(item.card_amount||0)+'" data-credit="'+Number(item.credit_amount||0)+'" data-cash-collection="'+Number(item.cash_credit_collection_amount||0)+'" data-card-collection="'+Number(item.card_credit_collection_amount||0)+'" data-cash-change-left="'+Number(item.cash_change_left_amount||0)+'"><td><strong>'+esc(displayDate(item.sale_date))+'</strong></td><td><strong>'+money(item.cash_total_amount)+'</strong><small>Nakit satış '+money(item.cash_amount)+' + tahsilat '+money(item.cash_credit_collection_amount)+'</small><small class="magaza-flow-status">'+esc(cashStatus)+'</small></td><td><strong>'+money(item.card_total_amount)+'</strong><small>Kart satış '+money(item.card_amount)+' + tahsilat '+money(item.card_credit_collection_amount)+'</small><small class="magaza-flow-status">'+esc(cardStatus)+'</small></td><td>'+money(item.credit_amount)+'</td><td>'+money(item.cash_credit_collection_amount)+'</td><td>'+money(item.card_credit_collection_amount)+'</td><td class="magaza-bozuk-cell"><strong>'+money(item.cash_change_left_amount)+'</strong><small>Bilgi amaçlı</small></td><td><strong>'+money(item.daily_total)+'</strong></td><td class="magaza-odeme-actions">'+(state.canWrite?'<button type="button" data-magaza-odeme-edit>Düzenle</button><button type="button" data-magaza-odeme-delete="'+item.id+'">Sil</button>':'')+'</td></tr>';
      }).join('')+'</tbody><tfoot><tr class="magaza-odeme-total-row"><td><strong>GENEL TOPLAM</strong></td><td><strong>'+money(summary.cash)+'</strong></td><td><strong>'+money(summary.card)+'</strong></td><td><strong>'+money(summary.credit)+'</strong></td><td><strong>'+money(summary.cash_credit_collection)+'</strong></td><td><strong>'+money(summary.card_credit_collection)+'</strong></td><td><small>Toplanmaz</small></td><td><strong>'+money(summary.daily_total)+'</strong></td><td></td></tr></tfoot></table></div>';
    setStatus((summary.count||0)+' gün · nakit Ana Kasa’ya aynı gün · kart Garanti’ye 13 gün sonra','success');updatePreview();var activeDate=form?qs('[name="sale_date"]',form):null;if(activeDate) loadPreviousCashChange(activeDate.value);
    document.dispatchEvent(new CustomEvent('bitke:magaza-odeme-updated'));
  }
  function editEntry(button){var row=button.closest('[data-magaza-odeme-row]'),form=qs('[data-magaza-odeme-form]');if(!row||!form) return;['cash','card','credit'].forEach(function(name){qs('[name="'+name+'_amount"]',form).value=Number(row.getAttribute('data-'+name)||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});});qs('[name="sale_date"]',form).value=row.getAttribute('data-date')||defaultDate(state.period);qs('[name="cash_credit_collection_amount"]',form).value=Number(row.getAttribute('data-cash-collection')||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});qs('[name="card_credit_collection_amount"]',form).value=Number(row.getAttribute('data-card-collection')||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});qs('[name="cash_change_left_amount"]',form).value=Number(row.getAttribute('data-cash-change-left')||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});qs('[data-magaza-odeme-save]',form).textContent='Günü güncelle';updatePreview();loadPreviousCashChange(qs('[name="sale_date"]',form).value);form.scrollIntoView({behavior:'smooth',block:'center'});}
  function load(){fetch('magaza-odeme-dagilimi.php?period='+encodeURIComponent(state.period)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'}).then(function(response){return response.json();}).then(function(data){if(!data.ok) throw new Error(data.error||'Günlük satış dağılımı yüklenemedi.');render(data);}).catch(function(error){setStatus(error.message||'Günlük satış dağılımı yüklenemedi.','danger');});}
  function saveEntry(event){event.preventDefault();var form=event.target,button=qs('[data-magaza-odeme-save]',form);button.disabled=true;button.textContent='Kaydediliyor...';var body=new FormData(form);body.set('action','save');body.set('period',state.period);body.set('csrf_token',state.csrf);fetch('magaza-odeme-dagilimi.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'}).then(function(response){return response.json();}).then(function(data){if(!data.ok) throw new Error(data.error||'Günlük satış dağılımı kaydedilemedi.');form.reset();var dateInput=qs('[name="sale_date"]',form);dateInput.value=defaultDate(state.period);render(data);setStatus('Kayıt tamamlandı. Nakit aynı gün Ana Kasa’ya işlendi; kart tutarı 13 günlük Garanti geçiş planına alındı.','success');}).catch(function(error){setStatus(error.message||'Günlük satış dağılımı kaydedilemedi.','danger');}).finally(function(){button.disabled=false;button.textContent='Günü kaydet';});}
  function deleteEntry(id){if(!id||!window.confirm('Bu günlük satış dağılımı ve bağlı otomatik kasa/banka hareketleri silinsin mi?')) return;var body=new FormData();body.set('action','delete');body.set('id',String(id));body.set('period',state.period);body.set('csrf_token',state.csrf);fetch('magaza-odeme-dagilimi.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'}).then(function(response){return response.json();}).then(function(data){if(!data.ok) throw new Error(data.error||'Kayıt silinemedi.');render(data);setStatus('Günlük satış dağılımı ve bağlı otomatik hareketler silindi.','success');}).catch(function(error){setStatus(error.message||'Kayıt silinemedi.','danger');});}

  var style=document.createElement('style');style.textContent=''
    +'.magaza-odeme-panel{display:grid;gap:14px;padding:15px;border:1px solid #cfd8d2;background:linear-gradient(135deg,#f5fbf7,#fff);border-radius:16px;position:relative;z-index:35}'
    +'.magaza-odeme-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.magaza-odeme-head>div{display:grid;gap:4px}.magaza-odeme-head strong{font-size:15px}.magaza-odeme-head small{font-size:11px;color:var(--muted);max-width:950px}.magaza-odeme-head>span{font-size:10px;font-weight:850;color:var(--muted)}.magaza-odeme-head>span.is-success{color:#1f6b3d}.magaza-odeme-head>span.is-danger{color:#96352f}'
    +'.magaza-odeme-summary{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px}.magaza-odeme-summary article{display:grid;gap:5px;padding:10px 11px;border:1px solid #dce5df;border-radius:12px;background:#fff}.magaza-odeme-summary span{font-size:9px;color:var(--muted);font-weight:800}.magaza-odeme-summary strong{font-size:13px}.magaza-odeme-summary small{font-size:8px;color:var(--muted)}'
    +'.magaza-onceki-bozuk{display:grid;grid-template-columns:auto 1fr;gap:4px 12px;align-items:center;padding:10px 12px;border:1px dashed #d5b879;border-radius:12px;background:#fffaf0}.magaza-onceki-bozuk span{font-size:9px;font-weight:850;color:#80642d}.magaza-onceki-bozuk strong{font-size:12px}.magaza-onceki-bozuk small{grid-column:2;font-size:8px;color:var(--muted)}'
    +'.magaza-odeme-form{display:grid;grid-template-columns:140px repeat(6,minmax(115px,1fr));gap:9px;align-items:end;padding:12px;border:1px solid #dce5df;border-radius:13px;background:#fff}.magaza-odeme-form label{display:grid;gap:5px;font-size:10px;font-weight:850}.magaza-odeme-form label small{font-size:8px;color:var(--muted);font-weight:700}.magaza-odeme-form input{width:100%}.magaza-odeme-preview{grid-column:1/-2;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;padding:9px 11px;border-radius:10px;background:#f1f6f3}.magaza-odeme-preview span{display:grid;gap:2px}.magaza-odeme-preview small{font-size:8px;color:var(--muted)}.magaza-odeme-preview strong{font-size:12px}.magaza-odeme-preview em{grid-column:1/-1;font-size:9px;color:var(--muted);font-style:normal}'
    +'.magaza-odeme-list .table-wrap{margin:0}.magaza-odeme-list table{width:100%;min-width:1280px}.magaza-odeme-list th,.magaza-odeme-list td{font-size:10px}.magaza-odeme-list td small{display:block;margin-top:3px;font-size:8px;color:var(--muted);white-space:nowrap}.magaza-odeme-list .magaza-flow-status{color:#1f6b3d;font-weight:850}.magaza-bozuk-cell{background:#fffaf0}.magaza-odeme-actions{white-space:nowrap}.magaza-odeme-actions button{border:0;background:transparent;padding:3px 5px;font-size:9px;font-weight:800;color:#745f3e;text-decoration:underline;cursor:pointer}.magaza-odeme-total-row td{background:#f3f7f4;border-top:2px solid #cad8ce}'
    +'@media(max-width:1350px){.magaza-odeme-summary{grid-template-columns:repeat(3,minmax(0,1fr))}.magaza-odeme-form{grid-template-columns:repeat(3,minmax(0,1fr))}.magaza-odeme-preview{grid-column:1/-1}}@media(max-width:650px){.magaza-odeme-head{display:grid}.magaza-odeme-summary,.magaza-odeme-form,.magaza-odeme-preview{grid-template-columns:1fr}.magaza-odeme-preview em{grid-column:1}.magaza-odeme-form .btn{width:100%}.magaza-onceki-bozuk{grid-template-columns:1fr}.magaza-onceki-bozuk small{grid-column:1}}';document.head.appendChild(style);
  state.period=periodValue();buildPanel();load();
})();
