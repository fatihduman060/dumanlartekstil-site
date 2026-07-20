(function(){
  'use strict';

  // Özel fatura sıralaması devre dışı kalır.
  // Bu dosya mevcut sayfa yükleme zincirinde bulunduğu için Hareketler ekranındaki
  // satış iadesi seçeneğini geriye uyumlu biçimde eklemek için kullanılır.
  if(!/\/hareketler\.php$/i.test(location.pathname)) return;

  var UI_RETURN_TYPE='iade_ui';
  var STORED_RETURN_TYPE='tahsilat';
  var RETURN_TEXT='İade';

  function startsWithReturn(value){
    return /^\s*[İI]ade(?:\s*-\s*|\s*$)/i.test(String(value||''));
  }

  function stripReturnPrefix(value){
    return String(value||'').replace(/^\s*[İI]ade\s*(?:-\s*)?/i,'').trim();
  }

  function addReturnOption(select){
    if(!select||select.querySelector('option[value="'+UI_RETURN_TYPE+'"]')) return;
    var option=document.createElement('option');
    option.value=UI_RETURN_TYPE;
    option.textContent=RETURN_TEXT;
    var payment=select.querySelector('option[value="odeme"]');
    if(payment) payment.insertAdjacentElement('afterend',option);
    else select.appendChild(option);
  }

  function formControls(){
    var form=document.querySelector('.form-card form');
    if(!form) return null;
    return {
      form:form,
      type:form.querySelector('select[name="movement_type"]'),
      payment:form.querySelector('input[name="payment_method"]'),
      account:form.querySelector('select[name="account_id"]')
    };
  }

  function ensureReturnHelp(controls){
    if(!controls||!controls.type) return;
    var label=controls.type.closest('label');
    if(!label||label.querySelector('[data-iade-help]')) return;
    var help=document.createElement('small');
    help.setAttribute('data-iade-help','1');
    help.hidden=true;
    help.textContent='İade, müşterinin cari borcunu azaltır; kasa/banka hareketi oluşturmaz.';
    label.appendChild(help);
  }

  function syncReturnForm(){
    var controls=formControls();
    if(!controls||!controls.type) return;
    ensureReturnHelp(controls);
    var isReturn=controls.type.value===UI_RETURN_TYPE;
    var help=controls.type.closest('label').querySelector('[data-iade-help]');
    if(help) help.hidden=!isReturn;
    if(controls.account){
      if(isReturn) controls.account.value='';
      var accountLabel=controls.account.closest('label');
      if(accountLabel&&isReturn) accountLabel.style.opacity='.55';
    }
  }

  function prepareMovementForm(){
    var controls=formControls();
    if(!controls||!controls.type) return;
    addReturnOption(controls.type);

    if(controls.type.value===STORED_RETURN_TYPE&&controls.payment&&startsWithReturn(controls.payment.value)){
      controls.type.value=UI_RETURN_TYPE;
    }

    ensureReturnHelp(controls);
    syncReturnForm();

    controls.type.addEventListener('change',function(){
      if(controls.type.value!==UI_RETURN_TYPE&&controls.payment&&startsWithReturn(controls.payment.value)){
        controls.payment.value=stripReturnPrefix(controls.payment.value);
      }
      syncReturnForm();
    });

    controls.form.addEventListener('submit',function(){
      if(controls.type.value===UI_RETURN_TYPE){
        controls.type.value=STORED_RETURN_TYPE;
        if(controls.account) controls.account.value='';
        if(controls.payment){
          var method=stripReturnPrefix(controls.payment.value);
          controls.payment.value=method?RETURN_TEXT+' - '+method:RETURN_TEXT;
        }
      }else if(controls.payment&&startsWithReturn(controls.payment.value)){
        controls.payment.value=stripReturnPrefix(controls.payment.value);
      }
    },true);
  }

  function prepareFilter(){
    var form=document.querySelector('.filterbar');
    if(!form) return;
    var type=form.querySelector('select[name="movement_type"]');
    var search=form.querySelector('input[name="q"]');
    if(!type) return;
    addReturnOption(type);

    var params=new URL(location.href).searchParams;
    if(params.get('movement_type')===STORED_RETURN_TYPE&&/^\s*[İI]ade\s*$/i.test(params.get('q')||'')){
      type.value=UI_RETURN_TYPE;
    }

    form.addEventListener('submit',function(){
      if(type.value!==UI_RETURN_TYPE) return;
      type.value=STORED_RETURN_TYPE;
      if(search) search.value=RETURN_TEXT;
    },true);
  }

  function relabelReturnRows(){
    document.querySelectorAll('.table-wrap tbody tr').forEach(function(row){
      var cells=row.querySelectorAll('td');
      if(cells.length<5) return;
      var badge=cells[1].querySelector('.badge');
      var details=String(cells[4].textContent||'');
      if(!badge||!/Tahsilat/i.test(badge.textContent||'')||!/(^|\s)[İI]ade(?:\s*-|\s|$)/i.test(details)) return;
      badge.textContent=RETURN_TEXT;
      badge.className='badge badge-warning';
    });
  }

  prepareMovementForm();
  prepareFilter();
  relabelReturnRows();
})();
