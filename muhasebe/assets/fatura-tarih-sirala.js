(function(){
  'use strict';

  function faturaTarihAnahtari(row){
    var firstCell=row&&row.cells&&row.cells[0]?row.cells[0]:null;
    var match=String(firstCell?firstCell.textContent:'').match(/(\d{2})\.(\d{2})\.(\d{4})/);
    if(!match) return 0;
    return Number(match[3]+match[2]+match[1]);
  }

  function faturaSatirId(row){
    var input=row?row.querySelector('input[name="id"]'):null;
    return input?Number(input.value||0):0;
  }

  function faturalariYenidenEskiyeSirala(){
    var tbody=document.querySelector('.table-wrap table tbody');
    if(!tbody) return;
    var rows=Array.from(tbody.children).filter(function(row){
      return row.tagName==='TR'
        && !row.classList.contains('empty')
        && !row.hasAttribute('data-fatura-tab-empty');
    });
    var sorted=rows.slice().sort(function(a,b){
      return faturaTarihAnahtari(b)-faturaTarihAnahtari(a)
        || faturaSatirId(b)-faturaSatirId(a);
    });
    var changed=sorted.some(function(row,index){return row!==rows[index];});
    if(changed) sorted.forEach(function(row){tbody.appendChild(row);});
  }

  function faturaSiralamayiBaslat(){
    faturalariYenidenEskiyeSirala();
    var tbody=document.querySelector('.table-wrap table tbody');
    if(!tbody) return;
    var scheduled=false;
    new MutationObserver(function(){
      if(scheduled) return;
      scheduled=true;
      window.setTimeout(function(){
        scheduled=false;
        faturalariYenidenEskiyeSirala();
      },60);
    }).observe(tbody,{childList:true});
  }

  if(/\/faturalar\.php$/i.test(location.pathname)){
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',faturaSiralamayiBaslat);
    else faturaSiralamayiBaslat();
    return;
  }

  // Hareketler ekranındaki satış iadesi ve cari detayına geri dönüş akışını yönetir.
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

  function writePendingCariReturn(cariId){
    cariId=Number(cariId||0);
    if(cariId<=0) return false;
    try{
      sessionStorage.setItem(CARI_RETURN_KEY,JSON.stringify({
        cari_id:cariId,
        created_at:Date.now()
      }));
      return true;
    }catch(error){
      return false;
    }
  }

  function clearPendingCariReturn(){
    try{sessionStorage.removeItem(CARI_RETURN_KEY);}catch(error){}
  }

  function cariDetailUrl(cariId){
    return 'cari-detay.php?id='+encodeURIComponent(String(Number(cariId||0)))+'#hareketler';
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
    location.replace(cariDetailUrl(pending.cari_id));
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

  function originCariId(){
    return Number(new URL(location.href).searchParams.get('cari_id')||0);
  }

  function prepareCariReturn(controls){
    if(!controls||!controls.form||!controls.cari) return;
    var sourceCariId=originCariId();
    if(sourceCariId<=0) return;

    controls.form.setAttribute('data-return-cari-id',String(sourceCariId));

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
      var selectedCariId=Number(controls.cari.value||sourceCariId||0);
      if(selectedCariId<=0){
        clearPendingCariReturn();
        return;
      }
      writePendingCariReturn(selectedCariId);
    },true);
  }

  function prepareCariCancelReturn(){
    var sourceCariId=originCariId();
    if(sourceCariId<=0) return;

    document.querySelectorAll('form').forEach(function(form){
      var action=form.querySelector('input[name="action"][value="cancel"]');
      if(!action||form.getAttribute('data-cari-cancel-return')==='1') return;
      form.setAttribute('data-cari-cancel-return','1');

      form.addEventListener('submit',function(event){
        // Sayfadaki mevcut onay kutusunda kullanıcı vazgeçtiyse hiçbir işlem yapma.
        if(event.defaultPrevented||form.getAttribute('data-cari-cancel-sending')==='1') return;
        event.preventDefault();
        form.setAttribute('data-cari-cancel-sending','1');
        writePendingCariReturn(sourceCariId);

        var button=form.querySelector('button[type="submit"],button:not([type])');
        if(button) button.disabled=true;

        fetch(form.action||location.href,{
          method:'POST',
          body:new FormData(form),
          credentials:'same-origin',
          cache:'no-store',
          redirect:'follow'
        }).then(function(response){
          if(!response.ok) throw new Error('İptal isteği tamamlanamadı.');
          return response.text();
        }).then(function(html){
          var parsed=new DOMParser().parseFromString(html,'text/html');
          var error=parsed.querySelector('.alert-error,.alert-danger');
          if(error){
            clearPendingCariReturn();
            form.removeAttribute('data-cari-cancel-sending');
            if(button) button.disabled=false;
            window.alert(String(error.textContent||'İşlem iptal edilemedi.').trim());
            return;
          }
          clearPendingCariReturn();
          location.replace(cariDetailUrl(sourceCariId));
        }).catch(function(){
          // Ağ sorunu olursa klasik gönderime dön; bekleyen cari bilgisi dönüşü yine sağlar.
          form.submit();
        });
      });
    });
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
  prepareCariCancelReturn();
  prepareFilter();
  relabelReturnRows();
})();