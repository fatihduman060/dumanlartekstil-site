(function(){
  'use strict';

  // Özel fatura sıralaması devre dışı kalır.
  // Bu dosya mevcut sayfa yükleme zincirinde bulunduğu için Hareketler ekranındaki
  // satış iadesi ve cari detayına geri dönüş akışını geriye uyumlu biçimde yönetir.
  if(!/\/hareketler\.php$/i.test(location.pathname)) return;

  var UI_RETURN_TYPE='iade_ui';
  var STORED_RETURN_TYPE='tahsilat';
  var RETURN_TEXT='İade';
  var CARI_RETURN_KEY='bitke_hareket_cariye_don';

  function readPendingCariReturn(){
    try{
      var raw=sessionStorage.getItem(CARI_RETURN_KEY);
      if(!raw) return null;
      var data=JSON.parse(raw);
      if(!data||Number(data.cari_id||0)<=0) return null;
      if(Date.now()-Number(data.created_at||0)>10*60*1000){
        sessionStorage.removeItem(CARI_RETURN_KEY);
        return null;
      }
      return data;
    }catch(error){
      try{sessionStorage.removeItem(CARI_RETURN_KEY);}catch(ignore){}
      return null;
    }
  }

  function clearPendingCariReturn(){
    try{sessionStorage.removeItem(CARI_RETURN_KEY);}catch(error){}
  }

  function handleCompletedCariReturn(){
    var pending=readPendingCariReturn();
    if(!pending) return false;

    var success=document.querySelector('.alert-success');
    var error=document.querySelector('.alert-error,.alert-danger');
    if(error){
      clearPendingCariReturn();
      return false;
    }
    if(!success) return false;

    clearPendingCariReturn();
    location.replace('cari-detay.php?id='+encodeURIComponent(String(pending.cari_id))+'#hareketler');
    return true;
  }

  if(handleCompletedCariReturn()) return;

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
      account:form.querySelector('select[name="account_id"]'),
      cari:form.querySelector('select[name="cari_id"]'),
      id:form.querySelector('input[name="id"]')
    };
  }

  function prepareCariReturn(controls){
    if(!controls||!controls.form||!controls.cari) return;
    var params=new URL(location.href).searchParams;
    var originCariId=Number(params.get('cari_id')||0);
    if(originCariId<=0) return;

    controls.form.setAttribute('data-return-cari-id',String(originCariId));

    var label=controls.cari.closest('label');
    if(label&&!label.querySelector('[data-cari-return-help]')){
      var help=document.createElement('small');
      help.setAttribute('data-cari-return-help','1');
      help.textContent='Kaydettikten sonra seçilen carinin detay sayfasına geri dönülür.';
      label.appendChild(help);
    }

    controls.form.addEventListener('submit',function(){
      if(controls.type&&controls.type.value==='ozel_alacak'){
        clearPendingCariReturn();
        return;
      }
      var selectedCariId=Number(controls.cari.value||originCariId||0);
      if(selectedCariId<=0){
        clearPendingCariReturn();
        return;
      }
      try{
        sessionStorage.setItem(CARI_RETURN_KEY,JSON.stringify({
          cari_id:selectedCariId,
          created_at:Date.now()
        }));
      }catch(error){}
    },true);
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
    prepareCariReturn(controls);

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
