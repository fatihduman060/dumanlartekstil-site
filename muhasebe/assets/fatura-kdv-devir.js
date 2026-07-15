(function(){
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  var section=document.querySelector('.dashboard-section');
  if(!section) return;

  var periodInput=section.querySelector('input[type="month"][name="period"]');
  var stats=section.querySelector('.stats-grid');
  var note=section.querySelector('.calc-note');
  if(!periodInput||!stats) return;

  var loading=false;
  var csrfToken='';

  function esc(value){
    return String(value==null?'':value).replace(/[&<>\"]/g,function(char){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[char];
    });
  }

  function money(value){
    return Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' TL';
  }

  function trDate(value){
    var parts=String(value||'').split('-');
    return parts.length===3?parts.reverse().join('.'):String(value||'');
  }

  function ensureUi(){
    var panel=document.getElementById('kdvDevirPanel');
    if(!panel){
      panel=document.createElement('div');
      panel.id='kdvDevirPanel';
      panel.className='kdv-devir-panel';
      panel.innerHTML=''
        +'<div class="kdv-devir-copy"><strong>Önceki dönemden devreden KDV</strong><small>Örneğin Haziran’dan Temmuz’a devreden tutarı, Temmuz dönemi açıkken buraya yaz.</small></div>'
        +'<form class="kdv-devir-form" data-kdv-devir-form>'
        +'<input type="hidden" name="period">'
        +'<label>Tutar<input type="text" inputmode="decimal" name="amount" placeholder="0,00"></label>'
        +'<label>Not<input type="text" name="note" placeholder="Örn: Haziran 2026 beyannamesinden devir"></label>'
        +'<button type="submit" class="btn btn-secondary">KDV devrini kaydet</button>'
        +'</form>'
        +'<p class="kdv-devir-status" data-kdv-devir-status></p>';
      stats.insertAdjacentElement('beforebegin',panel);
    }

    var carryCard=document.getElementById('kdvDevirCard');
    if(!carryCard){
      carryCard=document.createElement('article');
      carryCard.id='kdvDevirCard';
      carryCard.className='stat-card soft';
      carryCard.innerHTML='<span>Önceki dönemden devir</span><strong>0,00 TL</strong><small>Manuel girilen KDV devri</small>';
      var statusCard=stats.children[2]||null;
      if(statusCard) stats.insertBefore(carryCard,statusCard); else stats.appendChild(carryCard);
    }

    var storeCard=document.getElementById('magazaGunlukRaporCard');
    if(!storeCard){
      storeCard=document.createElement('article');
      storeCard.id='magazaGunlukRaporCard';
      storeCard.className='stat-card soft';
      storeCard.innerHTML='<span>Mağaza günlük rapor</span><strong>0,00 TL</strong><small>Z raporu KDV toplamı</small>';
      stats.appendChild(storeCard);
    }

    return panel;
  }

  function setStatus(text,tone){
    var el=document.querySelector('[data-kdv-devir-status]');
    if(!el) return;
    el.textContent=text||'';
    el.className='kdv-devir-status'+(tone?' is-'+tone:'');
  }

  function renderStoreCard(data,latestDate){
    var card=document.getElementById('magazaGunlukRaporCard');
    if(!card) return;

    var vat=Number(data&&data.store_sales_vat||0);
    var gross=Number(data&&data.store_sales_gross||0);
    var count=Number(data&&data.store_sales_count||0);
    var strong=card.querySelector('strong');
    var small=card.querySelector('small');

    if(strong) strong.textContent=money(vat);
    if(small){
      if(count>0){
        small.textContent='Z raporu KDV toplamı'+(latestDate?' · '+trDate(latestDate)+' tarihine kadar':'')+' · '+count+' kayıt';
      }else{
        small.textContent='Bu dönemde mağaza Z raporu girilmedi';
      }
    }
    card.title='Mağaza brüt Z raporu toplamı: '+money(gross);
  }

  function loadStoreLatestDate(data){
    renderStoreCard(data,'');
    fetch('magaza-gunluk-satis.php?period='+encodeURIComponent(periodInput.value)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(storeData){
        if(!storeData||!storeData.ok) return;
        var items=Array.isArray(storeData.items)?storeData.items:[];
        renderStoreCard(data,items.length?items[0].sale_date:'');
      })
      .catch(function(){});
  }

  function render(data){
    if(!data||!data.ok) return;
    csrfToken=String(data.csrf_token||'');
    var panel=ensureUi();
    var form=panel.querySelector('[data-kdv-devir-form]');
    form.querySelector('[name="period"]').value=data.period||periodInput.value;
    form.querySelector('[name="amount"]').value=Number(data.carryover||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});
    form.querySelector('[name="note"]').value=data.note||'';

    var carryCard=document.getElementById('kdvDevirCard');
    if(carryCard){
      carryCard.querySelector('strong').textContent=money(data.carryover);
      carryCard.querySelector('small').textContent=data.note ? data.note : 'Manuel girilen KDV devri';
    }

    renderStoreCard(data,'');

    var cards=stats.querySelectorAll('.stat-card');
    var statusCard=Array.prototype.find.call(cards,function(card){
      var span=card.querySelector('span');
      return span&&span.textContent.trim()==='KDV durumu';
    });
    if(statusCard){
      var strong=statusCard.querySelector('strong');
      var small=statusCard.querySelector('small');
      strong.textContent=money(data.net_abs);
      strong.className=data.net_tone==='danger'?'text-danger':'text-success';
      if(small) small.textContent=data.net_label||'KDV dengede';
    }

    if(note){
      note.innerHTML='<strong>KDV durumu</strong> = faturalardaki hesaplanan KDV + mağaza Z raporu KDV - indirilecek KDV - önceki dönemden devreden KDV. Tevkifat, istisna ve iade bu taslakta ayrıca hesaplanmaz.';
    }

    if(data.updated_at){
      setStatus('Bu dönem için KDV devri kaydedildi.','success');
    }else{
      setStatus('Bu dönem için henüz manuel KDV devri girilmedi.','neutral');
    }
  }

  function load(){
    if(loading) return;
    loading=true;
    ensureUi();
    fetch('fatura-kdv-devir.php?period='+encodeURIComponent(periodInput.value)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(!data.ok) throw new Error(data.error||'KDV devri yüklenemedi.');
        render(data);
        loadStoreLatestDate(data);
      })
      .catch(function(error){setStatus(error.message||'KDV devri yüklenemedi.','danger');})
      .finally(function(){loading=false;});
  }

  document.addEventListener('submit',function(event){
    var form=event.target.closest('[data-kdv-devir-form]');
    if(!form) return;
    event.preventDefault();

    var button=form.querySelector('button[type="submit"]');
    var oldText=button.textContent;
    button.disabled=true;
    button.textContent='Kaydediliyor...';

    var body=new FormData(form);
    body.set('period',periodInput.value);
    body.set('csrf_token',csrfToken);

    fetch('fatura-kdv-devir.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(!data.ok) throw new Error(data.error||'KDV devri kaydedilemedi.');
        render(data);
        loadStoreLatestDate(data);
        setStatus('KDV devri kaydedildi ve dönem hesabına eklendi.','success');
      })
      .catch(function(error){setStatus(error.message||'KDV devri kaydedilemedi.','danger');})
      .finally(function(){button.disabled=false;button.textContent=oldText;});
  });

  var style=document.createElement('style');
  style.textContent=''
    +'.kdv-devir-panel{display:grid;grid-template-columns:minmax(220px,.8fr) minmax(420px,1.6fr);gap:14px;align-items:end;margin:14px 0 16px;padding:14px 16px;border:1px solid #d8c6a5;background:linear-gradient(135deg,#fff7e8,#fff);border-radius:16px}.kdv-devir-copy{display:grid;gap:4px}.kdv-devir-copy strong{font-size:14px}.kdv-devir-copy small{font-size:11px;color:var(--muted)}.kdv-devir-form{display:grid;grid-template-columns:150px minmax(220px,1fr) auto;gap:9px;align-items:end}.kdv-devir-form label{display:grid;gap:5px;font-size:11px;font-weight:800;color:#514a40}.kdv-devir-form input{width:100%;border:1px solid var(--border);background:#fff;border-radius:11px;padding:10px 11px}.kdv-devir-form .btn{padding:10px 12px;white-space:nowrap}.kdv-devir-status{grid-column:1/-1;margin:0;font-size:11px;color:var(--muted)}.kdv-devir-status.is-success{color:var(--success)}.kdv-devir-status.is-danger{color:var(--danger)}@media(max-width:980px){.kdv-devir-panel{grid-template-columns:1fr}.kdv-devir-form{grid-template-columns:1fr 1fr}}@media(max-width:640px){.kdv-devir-form{grid-template-columns:1fr}}';
  document.head.appendChild(style);

  ensureUi();
  load();
})();