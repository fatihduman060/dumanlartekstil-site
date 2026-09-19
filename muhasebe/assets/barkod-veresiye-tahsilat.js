(function(){
  'use strict';

  var root=document.querySelector('[data-pos-root]');
  if(!root) return;
  var modal=root.querySelector('[data-credit-collection-modal]');
  if(!modal) return;

  var api=root.dataset.api||'barkod-satis-api.php';
  var csrf=root.dataset.csrf||'';
  var person=modal.querySelector('[data-credit-collection-person]');
  var balanceText=modal.querySelector('[data-credit-collection-balance]');
  var amount=modal.querySelector('[data-credit-collection-amount]');
  var status=modal.querySelector('[data-credit-collection-status]');
  var confirmButton=modal.querySelector('[data-credit-collection-confirm]');
  var closeButton=modal.querySelector('[data-credit-collection-close]');
  var sourceToken='';

  function money(value){
    return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(value||0))+' TL';
  }

  function makeToken(){
    if(window.crypto&&typeof window.crypto.randomUUID==='function') return 'pos-credit-'+window.crypto.randomUUID();
    return 'pos-credit-'+Date.now()+'-'+Math.random().toString(36).slice(2,12);
  }

  function selectedBalance(){
    if(!person||!person.value) return 0;
    var option=person.options[person.selectedIndex];
    return Number(option&&option.dataset.balance||0);
  }

  function syncBalance(){
    if(!balanceText) return;
    var balance=selectedBalance();
    balanceText.textContent=person&&person.value?'Kalan borç: '+money(balance):'Kalan borç: —';
    if(amount){
      amount.max=balance>0?String(balance):'';
      if(Number(amount.value||0)>balance&&balance>0) amount.value=String(balance);
    }
  }

  function resetForm(){
    sourceToken=makeToken();
    if(person) person.value='';
    if(amount) amount.value='';
    modal.querySelectorAll('input[name="pos_credit_collection_method"]').forEach(function(input){input.checked=false;});
    if(status) status.textContent='';
    if(confirmButton){confirmButton.disabled=false;confirmButton.textContent='Tahsilatı Kaydet';}
    syncBalance();
  }

  function openModal(){
    resetForm();
    modal.hidden=false;
    window.setTimeout(function(){
      if(person) person.focus();
      else if(closeButton) closeButton.focus();
    },50);
  }

  function closeModal(){
    modal.hidden=true;
  }

  root.addEventListener('pos:credit-collection-open',openModal);
  if(closeButton) closeButton.addEventListener('click',closeModal);
  modal.addEventListener('click',function(event){if(event.target===modal) closeModal();});
  document.addEventListener('keydown',function(event){
    if(modal.hidden) return;
    if(event.key==='Escape'){event.preventDefault();closeModal();}
  });
  if(person) person.addEventListener('change',syncBalance);

  if(!confirmButton) return;
  confirmButton.addEventListener('click',function(){
    var personId=Number(person&&person.value||0);
    var balance=selectedBalance();
    var value=Number(amount&&amount.value||0);
    var method=modal.querySelector('input[name="pos_credit_collection_method"]:checked');

    if(!personId){status.textContent='Tahsilat yapılacak kişiyi seçin.';if(person)person.focus();return;}
    if(!Number.isFinite(value)||value<=0){status.textContent='Tahsilat tutarını yazın.';if(amount)amount.focus();return;}
    if(value>balance+0.004){status.textContent='Tahsilat kalan borçtan fazla olamaz. Kalan borç: '+money(balance);if(amount)amount.focus();return;}
    if(!method){status.textContent='Nakit veya Kredi Kartı seçin.';return;}

    confirmButton.disabled=true;
    confirmButton.textContent='Tahsilat kaydediliyor…';
    status.textContent='';

    var body=new FormData();
    body.set('action','credit_collection');
    body.set('csrf_token',csrf);
    body.set('person_id',String(personId));
    body.set('amount',String(value));
    body.set('payment_method',method.value);
    body.set('source_token',sourceToken);

    fetch(api,{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(!data||!data.ok) throw new Error((data&&data.error)||'Tahsilat kaydedilemedi.');
        status.textContent=data.message||'Tahsilat kaydedildi.';
        confirmButton.textContent='Kaydedildi';
        window.setTimeout(function(){window.location.reload();},650);
      })
      .catch(function(error){
        status.textContent=error.message||'Tahsilat kaydedilemedi.';
        confirmButton.disabled=false;
        confirmButton.textContent='Tahsilatı Kaydet';
      });
  });
})();