(function(){
  'use strict';

  function init(){
    if(!/\/magaza\.php$/i.test(location.pathname)) return;

    var panel=document.querySelector('[data-magaza-odeme-dagilimi]');
    var form=panel&&panel.querySelector('[data-magaza-odeme-form]');
    if(!panel||!form) return;

    var manual=form.querySelector('[name="credit_amount"]');
    if(manual){
      var label=manual.closest('label');
      if(label) label.remove();
      else manual.remove();
    }

    var note=form.querySelector('.magaza-auto-credit-note');
    if(!note){
      note=document.createElement('div');
      note.className='magaza-auto-credit-note';
      var cashChange=form.querySelector('[name="cash_change_left_amount"]');
      var target=cashChange&&cashChange.closest('label');
      if(target) target.insertAdjacentElement('beforebegin',note);
      else form.appendChild(note);
    }
    note.innerHTML='<strong>Veresiye otomatik</strong><small>Veresiye satışları Barkodlu Satış / Personel Veresiye bölümünden, tahsilatlar da Personel Veresiye bölümünden ilgili güne otomatik yansır. Manuel giriş kapalıdır.</small>';

    if(!document.getElementById('magazaAutoCreditOnlyStyle')){
      var style=document.createElement('style');
      style.id='magazaAutoCreditOnlyStyle';
      style.textContent='.magaza-auto-credit-note{display:grid;gap:4px;padding:11px 12px;border:1px solid #b8d8c2;border-radius:13px;background:#f1faf4}.magaza-auto-credit-note strong{color:#173f29}.magaza-auto-credit-note small{color:#52705d;line-height:1.4}';
      document.head.appendChild(style);
    }
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
  document.addEventListener('bitke:magaza-odeme-updated',init);
})();
