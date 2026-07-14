(function(){
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  var state={period:'',csrf:'',categories:{},canWrite:false};

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
        var incoming=findCard('İndirilecek KDV');
        if(incoming){
          var strong=incoming.querySelector('strong');
          var small=incoming.querySelector('small');
          if(strong) strong.textContent=money(data.incoming_vat);
          if(small) small.textContent=Number(data.expense_count||0)>0
            ?'Gelen faturalar + '+Number(data.expense_count||0)+' masraf fişi KDV’si'
            :'Gelen faturaların KDV’si';
        }
        var status=findCard('KDV durumu');
        if(status){
          var statusStrong=status.querySelector('strong');
          var statusSmall=status.querySelector('small');
          if(statusStrong){statusStrong.textContent=money(data.net_abs);statusStrong.className=data.net_tone==='danger'?'text-danger':'text-success';}
          if(statusSmall) statusSmall.textContent=data.net_label||'KDV dengede';
        }
      })
      .catch(function(){});
  }

  function buildPanel(){
    var body=document.querySelector('[data-fatura-alt-kontrol-body]');
    if(!body||document.querySelector('[data-masraf-fisleri]')) return null;

    var panel=document.createElement('section');
    panel.className='masraf-fisi-panel';
    panel.setAttribute('data-masraf-fisleri','1');
    panel.innerHTML=''
      +'<div class="masraf-fisi-head"><div><strong>Masraf Fişleri</strong><small>Yemek, yol ve fabrika alışverişi fişlerinin KDV’sini dönem hesabına ekle.</small></div><span data-masraf-fisi-status></span></div>'
      +'<div class="masraf-fisi-summary"><article><span>Fiş toplamı</span><strong data-masraf-total>0,00 TL</strong></article><article><span>Matrah</span><strong data-masraf-subtotal>0,00 TL</strong></article><article><span>İndirilecek KDV</span><strong data-masraf-vat>0,00 TL</strong></article><article><span>Fiş adedi</span><strong data-masraf-count>0</strong></article></div>'
      +'<form class="masraf-fisi-form" data-masraf-fisi-form>'
      +'<label>Tarih<input type="date" name="receipt_date" required></label>'
      +'<label>Firma / açıklama<input type="text" name="vendor" maxlength="180" placeholder="Örn: Restoran, market, taksi"></label>'
      +'<label>Masraf türü<select name="category" required></select></label>'
      +'<label>KDV dahil toplam<input type="text" inputmode="decimal" name="total_amount" required placeholder="0,00"></label>'
      +'<label>KDV oranı<select name="vat_rate"><option value="20">%20</option><option value="10">%10</option><option value="1">%1</option><option value="0">KDV yok</option></select></label>'
      +'<label class="masraf-fisi-check"><input type="checkbox" name="include_in_vat" value="1" checked> KDV hesabına dahil et</label>'
      +'<label class="masraf-fisi-note">Not<input type="text" name="note" maxlength="250" placeholder="İsteğe bağlı kısa not"></label>'
      +'<div class="masraf-fisi-preview" data-masraf-preview>Matrah: 0,00 TL · KDV: 0,00 TL</div>'
      +'<button type="submit" class="btn btn-primary">Masraf fişini ekle</button>'
      +'</form>'
      +'<div class="masraf-fisi-list" data-masraf-fisi-list><p class="muted">Masraf fişleri yükleniyor...</p></div>';
    body.appendChild(panel);

    var form=panel.querySelector('[data-masraf-fisi-form]');
    form.querySelector('[name="receipt_date"]').value=defaultDate(state.period);
    form.addEventListener('input',updatePreview);
    form.addEventListener('change',updatePreview);
    form.addEventListener('submit',saveReceipt);
    panel.addEventListener('click',function(event){
      var button=event.target.closest('[data-masraf-delete]');
      if(button) deleteReceipt(Number(button.getAttribute('data-masraf-delete')||0));
    });
    return panel;
  }

  function categoryOptions(selected){
    return Object.keys(state.categories).map(function(key){
      return '<option value="'+esc(key)+'"'+(key===selected?' selected':'')+'>'+esc(state.categories[key])+'</option>';
    }).join('');
  }

  function updatePreview(){
    var form=document.querySelector('[data-masraf-fisi-form]');
    var preview=document.querySelector('[data-masraf-preview]');
    if(!form||!preview) return;
    var total=numberValue(form.total_amount.value);
    var rate=Number(form.vat_rate.value||0);
    var subtotal=rate>0?total/(1+(rate/100)):total;
    var vat=total-subtotal;
    preview.textContent='Matrah: '+money(subtotal)+' · KDV: '+money(vat);
  }

  function setStatus(text,tone){
    var el=document.querySelector('[data-masraf-fisi-status]');
    if(!el) return;
    el.textContent=text||'';
    el.className=tone?'is-'+tone:'';
  }

  function render(data){
    state.csrf=String(data.csrf_token||state.csrf);
    state.categories=data.categories||state.categories||{};
    state.canWrite=!!data.can_write;
    var panel=buildPanel();
    if(!panel) panel=document.querySelector('[data-masraf-fisleri]');
    if(!panel) return;

    var category=panel.querySelector('select[name="category"]');
    if(category&&category.options.length===0) category.innerHTML=categoryOptions('yemek');
    var summary=data.summary||{};
    panel.querySelector('[data-masraf-total]').textContent=money(summary.total);
    panel.querySelector('[data-masraf-subtotal]').textContent=money(summary.subtotal);
    panel.querySelector('[data-masraf-vat]').textContent=money(summary.vat);
    panel.querySelector('[data-masraf-count]').textContent=String(summary.count||0);

    var list=panel.querySelector('[data-masraf-fisi-list]');
    var items=Array.isArray(data.items)?data.items:[];
    if(!items.length){
      list.innerHTML='<p class="muted">Bu dönemde henüz masraf fişi yok.</p>';
    }else{
      list.innerHTML='<div class="table-wrap"><table><thead><tr><th>Tarih</th><th>Firma / Tür</th><th>Toplam</th><th>KDV</th><th>Durum</th><th></th></tr></thead><tbody>'
        +items.map(function(item){
          return '<tr><td><strong>'+esc(item.receipt_date.split('-').reverse().join('.'))+'</strong></td>'
            +'<td><strong>'+esc(item.vendor||item.category_label)+'</strong><small>'+esc(item.category_label)+(item.note?' · '+esc(item.note):'')+'</small></td>'
            +'<td>'+money(item.total_amount)+'<small>Matrah: '+money(item.subtotal)+'</small></td>'
            +'<td><strong>'+money(item.vat_amount)+'</strong><small>%'+Number(item.vat_rate||0)+'</small></td>'
            +'<td><span class="badge '+(item.include_in_vat?'badge-success':'badge-neutral')+'">'+(item.include_in_vat?'KDV’ye dahil':'Hariç')+'</span></td>'
            +'<td>'+(state.canWrite?'<button type="button" class="masraf-delete" data-masraf-delete="'+item.id+'">Sil</button>':'')+'</td></tr>';
        }).join('')+'</tbody></table></div>';
    }
    setStatus((summary.count||0)+' fiş · '+money(summary.vat)+' indirilecek KDV','success');
    updatePreview();
    refreshKdvCards();
  }

  function load(){
    fetch('masraf-fisleri.php?period='+encodeURIComponent(state.period)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){if(!data.ok) throw new Error(data.error||'Masraf fişleri yüklenemedi.');render(data);})
      .catch(function(error){setStatus(error.message||'Masraf fişleri yüklenemedi.','danger');});
  }

  function saveReceipt(event){
    event.preventDefault();
    var form=event.target;
    var button=form.querySelector('button[type="submit"]');
    var oldText=button.textContent;
    button.disabled=true;
    button.textContent='Kaydediliyor...';
    var body=new FormData(form);
    body.set('action','save');
    body.set('period',state.period);
    body.set('csrf_token',state.csrf);
    if(!form.include_in_vat.checked) body.set('include_in_vat','0');
    fetch('masraf-fisleri.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(!data.ok) throw new Error(data.error||'Masraf fişi kaydedilemedi.');
        form.reset();
        form.querySelector('[name="receipt_date"]').value=defaultDate(state.period);
        form.querySelector('[name="vat_rate"]').value='20';
        form.querySelector('[name="include_in_vat"]').checked=true;
        render(data);
        setStatus('Masraf fişi kaydedildi ve KDV hesabı güncellendi.','success');
      })
      .catch(function(error){setStatus(error.message||'Masraf fişi kaydedilemedi.','danger');})
      .finally(function(){button.disabled=false;button.textContent=oldText;});
  }

  function deleteReceipt(id){
    if(!id||!window.confirm('Bu masraf fişi silinsin mi?')) return;
    var body=new FormData();
    body.set('action','delete');
    body.set('id',String(id));
    body.set('period',state.period);
    body.set('csrf_token',state.csrf);
    fetch('masraf-fisleri.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){if(!data.ok) throw new Error(data.error||'Masraf fişi silinemedi.');render(data);setStatus('Masraf fişi silindi.','success');})
      .catch(function(error){setStatus(error.message||'Masraf fişi silinemedi.','danger');});
  }

  var style=document.createElement('style');
  style.textContent=''
    +'.masraf-fisi-panel{display:grid;gap:14px;padding:15px;border:1px solid #cbd9ca;background:#f7fbf6;border-radius:16px}'
    +'.masraf-fisi-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.masraf-fisi-head>div{display:grid;gap:4px}.masraf-fisi-head strong{font-size:15px}.masraf-fisi-head small{font-size:10px;color:var(--muted)}.masraf-fisi-head>span{font-size:10px;color:var(--muted)}.masraf-fisi-head>span.is-success{color:var(--success)}.masraf-fisi-head>span.is-danger{color:var(--danger)}'
    +'.masraf-fisi-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}.masraf-fisi-summary article{display:grid;gap:4px;padding:10px 12px;border:1px solid var(--border);background:#fff;border-radius:12px}.masraf-fisi-summary span{font-size:9px;color:var(--muted);font-weight:800}.masraf-fisi-summary strong{font-size:13px}'
    +'.masraf-fisi-form{display:grid;grid-template-columns:130px minmax(180px,1fr) minmax(150px,.8fr) 145px 110px;gap:9px;align-items:end}.masraf-fisi-form label{display:grid;gap:5px;font-size:10px;font-weight:800}.masraf-fisi-form input,.masraf-fisi-form select{width:100%;border:1px solid var(--border);background:#fff;border-radius:10px;padding:9px 10px}.masraf-fisi-check{display:flex!important;align-items:center;gap:7px;padding-bottom:10px}.masraf-fisi-check input{width:auto}.masraf-fisi-note{grid-column:1/4}.masraf-fisi-preview{font-size:10px;color:#355c43;padding:9px 10px;border-radius:10px;background:#edf7ef}.masraf-fisi-form>.btn{white-space:nowrap}'
    +'.masraf-fisi-list table{min-width:760px}.masraf-fisi-list th,.masraf-fisi-list td{font-size:10px;padding:8px}.masraf-fisi-list td small{display:block;margin-top:3px;font-size:8px;color:var(--muted)}.masraf-delete{border:0;background:transparent;color:var(--danger);font-weight:800;cursor:pointer}'
    +'@media(max-width:1050px){.masraf-fisi-form{grid-template-columns:1fr 1fr 1fr}.masraf-fisi-note{grid-column:1/3}.masraf-fisi-summary{grid-template-columns:1fr 1fr}}'
    +'@media(max-width:650px){.masraf-fisi-form{grid-template-columns:1fr}.masraf-fisi-note{grid-column:auto}.masraf-fisi-summary{grid-template-columns:1fr 1fr}.masraf-fisi-head{display:grid}}';
  document.head.appendChild(style);

  state.period=periodValue();
  buildPanel();
  load();
})();
