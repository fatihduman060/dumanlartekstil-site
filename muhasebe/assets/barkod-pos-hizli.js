(function(){
  var root=document.querySelector('[data-pos-root]');
  if(!root) return;
  var checkout=root.querySelector('.pos-checkout');
  var modal=root.querySelector('[data-pos-payment-modal]');
  var payments=modal&&modal.querySelector('.pos-payments');
  var personWrap=modal&&modal.querySelector('[data-pos-person-wrap]');
  var confirmBtn=modal&&modal.querySelector('[data-pos-payment-confirm]');
  var completeBtn=root.querySelector('[data-pos-complete]');
  var scan=root.querySelector('[data-pos-scan]');
  var status=root.querySelector('[data-pos-status]');
  if(!checkout||!payments||!confirmBtn||!completeBtn) return;

  payments.classList.add('pos-quick-payments');
  payments.querySelectorAll('label').forEach(function(label){
    var input=label.querySelector('input');
    var span=label.querySelector('span');
    if(!input||!span) return;
    if(input.value==='cash') span.textContent='💵 NAKİT (F6)';
    if(input.value==='card') span.textContent='💳 KREDİ KARTI (F7)';
    if(input.value==='credit') span.textContent='👤 MÜŞTERİ / VERESİYE (F8)';
  });
  var legend=payments.querySelector('legend');if(legend)legend.remove();

  var customer=root.querySelector('.pos-customer-summary');
  if(customer) customer.insertAdjacentElement('afterend',payments); else checkout.prepend(payments);
  if(personWrap){personWrap.classList.add('pos-quick-person');payments.insertAdjacentElement('afterend',personWrap);}

  var helper=document.createElement('div');
  helper.className='pos-shortcut-help';
  helper.innerHTML='<span><b>F6</b> Nakit</span><span><b>F7</b> Kart</span><span><b>F8</b> Müşteri</span><span><b>F9</b> Tamamla</span>';
  completeBtn.insertAdjacentElement('afterend',helper);

  if(modal) modal.hidden=true;

  function selectPayment(value,focusPerson){
    var input=root.querySelector('input[name="pos_payment"][value="'+value+'"]');
    if(!input) return;
    input.checked=true;
    input.dispatchEvent(new Event('change',{bubbles:true}));
    if(focusPerson&&personWrap){
      var select=personWrap.querySelector('select');
      if(select) setTimeout(function(){select.focus();},30);
    } else if(scan){scan.focus({preventScroll:true});}
  }

  completeBtn.onclick=function(){
    var selected=root.querySelector('input[name="pos_payment"]:checked');
    if(!selected){if(status)status.textContent='Ödeme şeklini seçin: F6 Nakit, F7 Kart veya F8 Müşteri.';return;}
    confirmBtn.click();
  };

  document.addEventListener('keydown',function(event){
    if(event.altKey||event.ctrlKey||event.metaKey) return;
    var tag=(event.target&&event.target.tagName||'').toLowerCase();
    var editing=tag==='input'||tag==='textarea'||tag==='select';
    if(event.key==='F6'){event.preventDefault();selectPayment('cash',false);return;}
    if(event.key==='F7'){event.preventDefault();selectPayment('card',false);return;}
    if(event.key==='F8'){event.preventDefault();selectPayment('credit',true);return;}
    if(event.key==='F9'){event.preventDefault();completeBtn.click();return;}
    if(!editing&&event.key==='Escape'&&scan){scan.focus({preventScroll:true});}
  });

  var cash=root.querySelector('input[name="pos_payment"][value="cash"]');
  if(cash){cash.checked=true;cash.dispatchEvent(new Event('change',{bubbles:true}));}
})();
