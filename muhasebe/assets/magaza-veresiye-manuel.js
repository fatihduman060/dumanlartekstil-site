(function(){
  'use strict';

  function init(){
    if(!/\/magaza\.php$/i.test(location.pathname)) return;

    var panel=document.querySelector('[data-magaza-odeme-dagilimi]');
    var form=panel&&panel.querySelector('[data-magaza-odeme-form]');
    if(!panel||!form||form.dataset.manualCreditReady==='1') return;
    form.dataset.manualCreditReady='1';

    var autoNote=form.querySelector('.magaza-auto-credit-note');
    if(autoNote){
      var label=document.createElement('label');
      label.className='magaza-manual-credit-field';
      label.innerHTML='Veresiye satış<input type="text" inputmode="decimal" name="credit_amount" placeholder="0,00"><small>Geçici manuel giriş açık. Aynı veresiye işlemini Personel Veresiye bölümüne de girersen iki kez toplam oluşur.</small>';
      autoNote.replaceWith(label);
    }

    panel.addEventListener('click',function(event){
      var editButton=event.target.closest('[data-magaza-odeme-edit]');
      if(!editButton) return;
      var row=editButton.closest('[data-magaza-odeme-row]');
      var input=form.querySelector('[name="credit_amount"]');
      if(!row||!input) return;
      var value=Number(row.getAttribute('data-credit')||0);
      input.value=value.toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});
      try{input.dispatchEvent(new Event('input',{bubbles:true}));}catch(e){}
    });

    if(!document.getElementById('magazaManualCreditStyle')){
      var style=document.createElement('style');
      style.id='magazaManualCreditStyle';
      style.textContent='.magaza-manual-credit-field small{color:#9a5b24!important;font-weight:800!important}.magaza-manual-credit-field input{border-color:#d8b07a!important;background:#fffaf2!important}';
      document.head.appendChild(style);
    }
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
