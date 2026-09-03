(function(){
  if(!/\/hareketler\.php$/i.test(location.pathname)) return;

  var form=document.querySelector('.form-card form.stack-form');
  if(!form) return;
  var type=form.querySelector('[name="movement_type"]');
  var account=form.querySelector('[name="account_id"]');
  var payment=form.querySelector('[name="payment_method"]');
  if(!type||!payment) return;

  var cards=[];
  var csrf='';
  var active=false;

  var box=document.createElement('section');
  box.className='card-payment-box';
  box.hidden=true;
  box.innerHTML=''
    +'<div class="card-payment-head"><div><strong>Kredi Kartı ile Ödeme</strong><small>Cari borcunu düşürür; kasa/banka ve raporlara ikinci kez gider yazmaz.</small></div><button type="button" data-card-payment-close>Normal ödemeye dön</button></div>'
    +'<label>Kullanılan kredi kartı<select name="card_key" data-card-payment-select><option value="">Kart seç</option></select></label>'
    +'<p>Gerçek banka çıkışı, Kart Ekstre Takibi bölümünde ekstre “Ödendi” yapıldığında oluşur.</p>';

  var paymentLabel=payment.closest('label');
  if(paymentLabel) paymentLabel.insertAdjacentElement('afterend',box);
  else form.appendChild(box);

  var trigger=document.createElement('button');
  trigger.type='button';
  trigger.className='btn btn-secondary card-payment-trigger';
  trigger.textContent='💳 Kredi Kartı';
  trigger.hidden=true;
  if(paymentLabel) paymentLabel.appendChild(trigger);

  var select=box.querySelector('[data-card-payment-select]');
  var close=box.querySelector('[data-card-payment-close]');

  function esc(value){return String(value==null?'':value).replace(/[&<>\"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[c];});}

  function renderCards(){
    if(!select) return;
    select.innerHTML='<option value="">Kart seç</option>'+cards.map(function(card){
      return '<option value="'+esc(card.key)+'">'+esc(card.name)+'</option>';
    }).join('');
  }

  function syncType(){
    var isPayment=type.value==='odeme';
    trigger.hidden=!isPayment;
    if(!isPayment && active) deactivate();
  }

  function activate(){
    active=true;
    box.hidden=false;
    payment.value='KREDİ KARTI';
    payment.readOnly=true;
    if(account){account.value='';account.disabled=true;}
    trigger.textContent='💳 Kredi Kartı seçildi';
    if(select) select.focus();
  }

  function deactivate(){
    active=false;
    box.hidden=true;
    if(/^KREDİ KARTI/i.test(payment.value||'')) payment.value='';
    payment.readOnly=false;
    if(account) account.disabled=false;
    if(select) select.value='';
    trigger.textContent='💳 Kredi Kartı';
  }

  trigger.addEventListener('click',function(){active?deactivate():activate();});
  close.addEventListener('click',deactivate);
  type.addEventListener('change',syncType);

  fetch('hareket-kredi-karti.php?_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
    .then(function(response){return response.json();})
    .then(function(data){
      if(!data||!data.ok) throw new Error(data&&data.error?data.error:'Kartlar alınamadı.');
      cards=Array.isArray(data.cards)?data.cards:[];
      csrf=String(data.csrf_token||'');
      renderCards();
    })
    .catch(function(){trigger.disabled=true;trigger.title='Kredi kartı listesi yüklenemedi.';});

  form.addEventListener('submit',function(event){
    if(!active) return;
    event.preventDefault();
    var cardKey=select?String(select.value||''):'';
    if(!cardKey){window.alert('Kullanılan kredi kartını seçmelisin.');if(select)select.focus();return;}
    var cari=form.querySelector('[name="cari_id"]');
    if(!cari||!cari.value){window.alert('Kredi kartı ile ödeme için cari seçmelisin.');if(cari)cari.focus();return;}

    var submit=form.querySelector('button[type="submit"]');
    var oldText=submit?submit.textContent:'';
    if(submit){submit.disabled=true;submit.textContent='Kartlı ödeme kaydediliyor...';}

    if(account) account.disabled=false;
    var body=new FormData(form);
    if(account) account.disabled=true;
    body.set('csrf_token',csrf||String((form.querySelector('[name="csrf_token"]')||{}).value||''));
    body.set('card_key',cardKey);
    body.set('movement_type','odeme');
    body.set('currency','TL');
    body.delete('account_id');

    fetch('hareket-kredi-karti.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(!data||!data.ok) throw new Error(data&&data.error?data.error:'Kartlı ödeme kaydedilemedi.');
        window.alert(data.message||'Kartlı ödeme kaydedildi.');
        window.location.href=data.redirect||'hareketler.php';
      })
      .catch(function(error){window.alert(error.message||'Kartlı ödeme kaydedilemedi.');})
      .finally(function(){if(submit){submit.disabled=false;submit.textContent=oldText;}});
  });

  var style=document.createElement('style');
  style.textContent=''
    +'.card-payment-trigger{margin-top:7px;width:max-content}'
    +'.card-payment-box{display:grid;gap:10px;padding:12px 14px;border:1px solid #d7c28f;background:#fff9e9;border-radius:14px}'
    +'.card-payment-box[hidden]{display:none!important}.card-payment-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}'
    +'.card-payment-head>div{display:grid;gap:3px}.card-payment-head small,.card-payment-box p{font-size:10px;color:#776b5c;margin:0}'
    +'.card-payment-head button{border:0;background:transparent;color:#8a6a26;font-weight:850;cursor:pointer}'
    +'.card-payment-box label{display:grid;gap:5px;font-weight:850}.card-payment-box select{width:100%;min-height:42px;border:1px solid #d8cdbb;border-radius:11px;padding:9px 10px;background:#fff}'
    +'@media(max-width:620px){.card-payment-head{display:grid}.card-payment-trigger{width:100%}}';
  document.head.appendChild(style);

  syncType();
})();
