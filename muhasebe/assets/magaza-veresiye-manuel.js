(function(){
  'use strict';

  function ensureCompactStyle(){
    if(document.getElementById('magazaCompactStoreStyle')) return;
    var style=document.createElement('style');
    style.id='magazaCompactStoreStyle';
    style.textContent=''
      +'.magaza-page-shell,[data-magaza-odeme-dagilimi-body],.magaza-odeme-panel{min-width:0!important;max-width:100%!important;overflow-x:hidden!important}'
      +'.magaza-odeme-form{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:10px 12px!important;align-items:end!important}'
      +'.magaza-odeme-form>label{min-width:0!important;margin:0!important}'
      +'.magaza-odeme-form>label input{width:100%!important;min-width:0!important}'
      +'.magaza-auto-credit-note{grid-column:1/-1!important;display:flex!important;align-items:center!important;gap:8px!important;padding:7px 10px!important;border:1px solid #cfe1d4!important;border-radius:10px!important;background:#f5faf6!important;min-height:0!important}'
      +'.magaza-auto-credit-note strong{font-size:11px!important;white-space:nowrap!important;color:#173f29!important}'
      +'.magaza-auto-credit-note small{font-size:10px!important;line-height:1.25!important;color:#627568!important}'
      +'.magaza-odeme-preview{grid-column:1/-1!important;display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:8px!important;min-width:0!important}'
      +'.magaza-odeme-preview>span{min-width:0!important;padding:8px 10px!important}'
      +'.magaza-odeme-preview>span small{white-space:normal!important;line-height:1.2!important}'
      +'.magaza-odeme-preview>em{grid-column:1/-1!important;font-size:10px!important;line-height:1.3!important;margin:0!important}'
      +'.magaza-odeme-form>[data-magaza-odeme-save]{grid-column:1/-1!important;width:100%!important;min-height:42px!important}'
      +'body.store-sales-user .magaza-odeme-head small{font-size:10px!important;line-height:1.3!important}'
      +'body.store-sales-user .magaza-odeme-list .table-wrap{overflow:visible!important;max-width:100%!important}'
      +'body.store-sales-user .magaza-odeme-list table{display:block!important;width:100%!important;min-width:0!important;border:0!important}'
      +'body.store-sales-user .magaza-odeme-list thead,body.store-sales-user .magaza-odeme-list tfoot{display:none!important}'
      +'body.store-sales-user .magaza-odeme-list tbody{display:grid!important;gap:10px!important;width:100%!important}'
      +'body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row]{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:7px!important;width:100%!important;padding:11px!important;border:1px solid #dcd5c7!important;border-radius:14px!important;background:#fff!important;box-shadow:0 5px 14px rgba(23,63,41,.05)!important}'
      +'body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row] td{display:grid!important;align-content:start!important;gap:3px!important;min-width:0!important;padding:7px 8px!important;border:0!important;border-radius:9px!important;background:#f8f6f1!important;font-size:11px!important;white-space:normal!important;overflow-wrap:anywhere!important}'
      +'body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row] td:before{display:block!important;font-size:9px!important;font-weight:900!important;letter-spacing:.03em!important;text-transform:uppercase!important;color:#8b7d68!important}'
      +'body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row] td:nth-child(1){grid-column:1/-1!important;display:flex!important;align-items:center!important;justify-content:space-between!important;background:#173f29!important;color:#fff!important;padding:9px 11px!important}'
      +'body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row] td:nth-child(1):before{content:"Tarih"!important;color:#e7c980!important}'
      +'body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row] td:nth-child(2):before{content:"Mağaza Kasa"!important}'
      +'body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row] td:nth-child(3):before{content:"Garanti Dumanlar"!important}'
      +'body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row] td:nth-child(4):before{content:"Veresiye satış"!important}'
      +'body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row] td:nth-child(5):before{content:"Nakit tahsilat"!important}'
      +'body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row] td:nth-child(6):before{content:"Kart tahsilat"!important}'
      +'body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row] td:nth-child(7):before{content:"Kasada bozuk para"!important}'
      +'body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row] td:nth-child(8):before{content:"Günlük satış"!important}'
      +'body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row] td:nth-child(9){grid-column:1/-1!important;display:flex!important;justify-content:flex-end!important;gap:8px!important;background:transparent!important;padding:2px 0 0!important}'
      +'body.store-sales-user .magaza-odeme-list td small{font-size:9px!important;line-height:1.25!important}'
      +'body.store-sales-user .magaza-odeme-list td strong{font-size:12px!important}'
      +'body.store-sales-user .main,body.store-sales-user .dashboard-section{min-width:0!important;max-width:100%!important;overflow-x:hidden!important}'
      +'@media(max-width:900px){.magaza-odeme-form{grid-template-columns:repeat(2,minmax(0,1fr))!important}.magaza-odeme-preview{grid-template-columns:repeat(2,minmax(0,1fr))!important}body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row]{grid-template-columns:repeat(2,minmax(0,1fr))!important}}'
      +'@media(max-width:560px){.magaza-odeme-form{grid-template-columns:1fr!important}.magaza-odeme-preview{grid-template-columns:1fr!important}.magaza-auto-credit-note{align-items:flex-start!important;flex-direction:column!important;gap:2px!important}body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row]{grid-template-columns:1fr!important}}';
    document.head.appendChild(style);
  }

  function init(){
    if(!/\/magaza\.php$/i.test(location.pathname)) return;

    ensureCompactStyle();

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
    note.innerHTML='<strong>Veresiye otomatik</strong><small>Satış ve tahsilatlar Personel Veresiye sisteminden ilgili güne otomatik gelir.</small>';
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
  document.addEventListener('bitke:magaza-odeme-updated',init);
})();
