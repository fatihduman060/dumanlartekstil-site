(function(){
  'use strict';

  function ensureLayoutStyle(){
    var old=document.getElementById('magazaCompactStoreStyle');
    if(old) old.remove();
    if(document.getElementById('magazaAlignedStoreStyle')) return;

    var style=document.createElement('style');
    style.id='magazaAlignedStoreStyle';
    style.textContent=''
      +'.magaza-page-shell,[data-magaza-odeme-dagilimi-body],.magaza-odeme-panel{min-width:0!important;max-width:100%!important;overflow-x:hidden!important}'
      +'.magaza-odeme-panel{padding:18px!important}'
      +'.magaza-odeme-form{display:grid!important;grid-template-columns:minmax(170px,.85fr) repeat(3,minmax(210px,1fr))!important;gap:12px 16px!important;align-items:start!important;width:100%!important;max-width:100%!important}'
      +'.magaza-odeme-form>label{display:grid!important;grid-template-rows:22px 46px 30px!important;gap:5px!important;align-content:start!important;min-width:0!important;max-width:100%!important;margin:0!important;font-size:13px!important;font-weight:850!important;color:#26362c!important}'
      +'.magaza-odeme-form>label:nth-of-type(1){grid-column:1!important;grid-row:1!important}'
      +'.magaza-odeme-form>label:nth-of-type(2){grid-column:2!important;grid-row:1!important}'
      +'.magaza-odeme-form>label:nth-of-type(3){grid-column:3!important;grid-row:1!important}'
      +'.magaza-odeme-form>label:nth-of-type(4){grid-column:4!important;grid-row:1!important}'
      +'.magaza-odeme-form>label input{width:100%!important;height:46px!important;min-width:0!important;max-width:100%!important;margin:0!important;padding:0 12px!important;box-sizing:border-box!important;border-radius:10px!important;font-size:14px!important;font-weight:750!important}'
      +'.magaza-odeme-form>label small{display:block!important;min-height:30px!important;margin:0!important;padding-top:1px!important;font-size:10px!important;line-height:1.25!important;color:#6e786f!important}'
      +'.magaza-auto-credit-note{grid-column:1/-1!important;grid-row:2!important;display:flex!important;align-items:center!important;gap:8px!important;min-height:34px!important;margin:0!important;padding:7px 10px!important;border:1px solid #d7e4da!important;border-radius:10px!important;background:#f7faf8!important}'
      +'.magaza-auto-credit-note strong{font-size:11px!important;line-height:1.2!important;white-space:nowrap!important;color:#173f29!important}'
      +'.magaza-auto-credit-note small{font-size:10px!important;line-height:1.25!important;color:#627568!important}'
      +'.magaza-odeme-preview{grid-column:1/-1!important;grid-row:3!important;display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:10px!important;min-width:0!important;margin:0!important}'
      +'.magaza-odeme-preview>span{display:grid!important;align-content:start!important;gap:5px!important;min-width:0!important;min-height:74px!important;padding:10px 12px!important;border-radius:12px!important}'
      +'.magaza-odeme-preview>span small{display:block!important;min-height:27px!important;font-size:10px!important;line-height:1.25!important;white-space:normal!important}'
      +'.magaza-odeme-preview>span strong{font-size:16px!important;line-height:1.2!important}'
      +'.magaza-odeme-preview>em{grid-column:1/-1!important;margin:0!important;font-size:10px!important;line-height:1.3!important}'
      +'.magaza-odeme-form>[data-magaza-odeme-save]{grid-column:1/-1!important;grid-row:4!important;width:100%!important;min-height:46px!important;margin:0!important;font-size:14px!important}'
      +'body.store-sales-user .magaza-odeme-head{margin-bottom:12px!important}'
      +'body.store-sales-user .magaza-odeme-head strong{font-size:19px!important}'
      +'body.store-sales-user .magaza-odeme-head small{font-size:11px!important;line-height:1.35!important}'
      +'body.store-sales-user .magaza-onceki-bozuk{margin:10px 0 14px!important;padding:9px 12px!important}'
      +'body.store-sales-user .magaza-odeme-list .table-wrap{width:100%!important;max-width:100%!important;overflow:hidden!important}'
      +'body.store-sales-user .magaza-odeme-list table{width:100%!important;min-width:0!important;table-layout:fixed!important;border-collapse:collapse!important}'
      +'body.store-sales-user .magaza-odeme-list th,body.store-sales-user .magaza-odeme-list td{white-space:normal!important;overflow-wrap:anywhere!important;vertical-align:top!important;padding:10px 8px!important;font-size:11px!important;line-height:1.3!important}'
      +'body.store-sales-user .magaza-odeme-list th{font-size:9px!important;line-height:1.2!important}'
      +'body.store-sales-user .magaza-odeme-list td strong{font-size:12px!important;line-height:1.25!important}'
      +'body.store-sales-user .magaza-odeme-list td small{display:block!important;margin-top:3px!important;font-size:9px!important;line-height:1.25!important}'
      +'body.store-sales-user .magaza-odeme-list th:nth-child(1),body.store-sales-user .magaza-odeme-list td:nth-child(1){width:8%!important}'
      +'body.store-sales-user .magaza-odeme-list th:nth-child(2),body.store-sales-user .magaza-odeme-list td:nth-child(2){width:15%!important}'
      +'body.store-sales-user .magaza-odeme-list th:nth-child(3),body.store-sales-user .magaza-odeme-list td:nth-child(3){width:15%!important}'
      +'body.store-sales-user .magaza-odeme-list th:nth-child(4),body.store-sales-user .magaza-odeme-list td:nth-child(4){width:10%!important}'
      +'body.store-sales-user .magaza-odeme-list th:nth-child(5),body.store-sales-user .magaza-odeme-list td:nth-child(5){width:11%!important}'
      +'body.store-sales-user .magaza-odeme-list th:nth-child(6),body.store-sales-user .magaza-odeme-list td:nth-child(6){width:11%!important}'
      +'body.store-sales-user .magaza-odeme-list th:nth-child(7),body.store-sales-user .magaza-odeme-list td:nth-child(7){width:11%!important}'
      +'body.store-sales-user .magaza-odeme-list th:nth-child(8),body.store-sales-user .magaza-odeme-list td:nth-child(8){width:11%!important}'
      +'body.store-sales-user .magaza-odeme-list th:nth-child(9),body.store-sales-user .magaza-odeme-list td:nth-child(9){width:8%!important}'
      +'body.store-sales-user .magaza-odeme-actions button{padding:5px 7px!important;font-size:9px!important}'
      +'body.store-sales-user .main,body.store-sales-user .dashboard-section{min-width:0!important;max-width:100%!important;overflow-x:hidden!important}'
      +'@media(max-width:1050px){.magaza-odeme-form{grid-template-columns:repeat(2,minmax(0,1fr))!important}.magaza-odeme-form>label:nth-of-type(1){grid-column:1!important;grid-row:1!important}.magaza-odeme-form>label:nth-of-type(2){grid-column:2!important;grid-row:1!important}.magaza-odeme-form>label:nth-of-type(3){grid-column:1!important;grid-row:2!important}.magaza-odeme-form>label:nth-of-type(4){grid-column:2!important;grid-row:2!important}.magaza-auto-credit-note{grid-row:3!important}.magaza-odeme-preview{grid-row:4!important;grid-template-columns:repeat(2,minmax(0,1fr))!important}.magaza-odeme-form>[data-magaza-odeme-save]{grid-row:5!important}}'
      +'@media(max-width:700px){.magaza-odeme-form{grid-template-columns:1fr!important}.magaza-odeme-form>label:nth-of-type(1){grid-column:1!important;grid-row:1!important}.magaza-odeme-form>label:nth-of-type(2){grid-column:1!important;grid-row:2!important}.magaza-odeme-form>label:nth-of-type(3){grid-column:1!important;grid-row:3!important}.magaza-odeme-form>label:nth-of-type(4){grid-column:1!important;grid-row:4!important}.magaza-auto-credit-note{grid-row:5!important;align-items:flex-start!important;flex-direction:column!important;gap:2px!important}.magaza-odeme-preview{grid-row:6!important;grid-template-columns:1fr!important}.magaza-odeme-form>[data-magaza-odeme-save]{grid-row:7!important}body.store-sales-user .magaza-odeme-list .table-wrap{overflow:visible!important}body.store-sales-user .magaza-odeme-list table,body.store-sales-user .magaza-odeme-list tbody{display:block!important;width:100%!important}body.store-sales-user .magaza-odeme-list thead,body.store-sales-user .magaza-odeme-list tfoot{display:none!important}body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row]{display:grid!important;grid-template-columns:1fr 1fr!important;gap:7px!important;margin-bottom:10px!important;padding:10px!important;border:1px solid #ddd5c7!important;border-radius:14px!important;background:#fff!important}body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row] td{display:grid!important;width:auto!important;padding:7px!important;border:0!important;border-radius:9px!important;background:#f8f6f1!important}body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row] td:nth-child(1),body.store-sales-user .magaza-odeme-list tr[data-magaza-odeme-row] td:nth-child(9){grid-column:1/-1!important;width:auto!important}}';
    document.head.appendChild(style);
  }

  function init(){
    if(!/\/magaza\.php$/i.test(location.pathname)) return;

    ensureLayoutStyle();

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
      if(target) target.insertAdjacentElement('afterend',note);
      else form.appendChild(note);
    }
    note.innerHTML='<strong>Veresiye otomatik</strong><small>Veresiye satış ve tahsilatlar ilgili güne otomatik yansır.</small>';
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
  document.addEventListener('bitke:magaza-odeme-updated',init);
})();
