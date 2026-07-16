(function(){
  'use strict';
  if(!/maaslar\.php/i.test(location.pathname)) return;

  function currentPeriod(){
    var input=document.getElementById('attendancePeriod');
    if(input&&/^\d{4}-\d{2}$/.test(input.value||'')) return input.value;
    var query=new URL(location.href).searchParams.get('period');
    if(/^\d{4}-\d{2}$/.test(query||'')) return query;
    var date=new Date();
    return date.getFullYear()+'-'+String(date.getMonth()+1).padStart(2,'0');
  }

  function key(value){
    return String(value||'').toLocaleLowerCase('tr-TR')
      .replace(/ı/g,'i').replace(/ğ/g,'g').replace(/ü/g,'u')
      .replace(/ş/g,'s').replace(/ö/g,'o').replace(/ç/g,'c')
      .replace(/[^a-z0-9]+/g,'');
  }

  function paymentDateForPeriod(period){
    if(!/^\d{4}-\d{2}$/.test(period||'')) period=currentPeriod();
    var parts=period.split('-');
    var date=new Date(Number(parts[0]),Number(parts[1]),5);
    return date.getFullYear()+'-'+String(date.getMonth()+1).padStart(2,'0')+'-05';
  }

  function garantiDumanlarOption(select){
    if(!select) return null;
    var options=Array.prototype.slice.call(select.options||[]);
    return options.find(function(option){
      var optionKey=key(option.textContent);
      return optionKey.indexOf('garanti')>-1&&optionKey.indexOf('dumanlar')>-1;
    })||null;
  }

  function applyMonthlyPaymentDefaults(force){
    var action=document.querySelector('form input[name="action"][value="save_salary"]');
    var form=action&&action.closest('form');
    if(!form) return;

    var idField=form.querySelector('input[name="id"]');
    var editing=Number(idField&&idField.value||0)>0;
    if(editing&&!force) return;

    var period=form.querySelector('[name="period"]');
    var paymentDate=form.querySelector('[name="payment_date"]');
    var account=form.querySelector('[name="account_id"]');
    var selectedPeriod=period&&period.value||currentPeriod();

    if(paymentDate&&(force||!paymentDate.value||!editing)){
      paymentDate.value=paymentDateForPeriod(selectedPeriod);
    }

    if(account&&(force||!account.value||!editing)){
      var garanti=garantiDumanlarOption(account);
      if(garanti) account.value=garanti.value;
    }
  }

  function updateLink(){
    var link=document.getElementById('attendanceBulkExcel');
    if(!link) return;
    var period=currentPeriod();
    link.href='maas-puantaj-toplu-excel.php?period='+encodeURIComponent(period);
    link.title=period+' dönemindeki bütün aktif personelin puantajını Excel olarak indir';
  }

  function ensureExcelButton(){
    var actions=document.querySelector('.attendance-actions');
    if(!actions) return false;
    var link=document.getElementById('attendanceBulkExcel');
    if(!link){
      link=document.createElement('a');
      link.id='attendanceBulkExcel';
      link.className='attendance-bulk-excel';
      link.textContent='Toplu Excel indir';
      link.setAttribute('download','');
      actions.appendChild(link);
    }
    updateLink();
    return true;
  }

  function ensureSaturdayButton(){
    var actions=document.querySelector('.attendance-actions');
    if(!actions) return false;

    var button=document.getElementById('fillSaturdays');
    if(!button){
      button=document.createElement('button');
      button.type='button';
      button.id='fillSaturdays';
      button.textContent='Cumartesileri Hafta tatili yap';

      var sundayButton=document.getElementById('fillSundays');
      if(sundayButton) sundayButton.insertAdjacentElement('afterend',button);
      else actions.appendChild(button);
    }
    return true;
  }

  function ensureButtons(){
    var excelReady=ensureExcelButton();
    var saturdayReady=ensureSaturdayButton();
    return excelReady||saturdayReady;
  }

  function markSaturdays(){
    var period=currentPeriod();
    var parts=period.split('-');
    var year=Number(parts[0]);
    var month=Number(parts[1]);
    var daysInMonth=new Date(year,month,0).getDate();
    var changed=0;

    for(var day=1;day<=daysInMonth;day++){
      if(new Date(year,month-1,day).getDay()!==6) continue;

      var date=period+'-'+String(day).padStart(2,'0');
      var dayButton=document.querySelector('.attendance-day[data-date="'+date+'"]');
      if(!dayButton) continue;

      dayButton.click();
      var status=document.getElementById('attendanceStatus');
      if(!status) continue;

      status.value='hafta_tatili';
      status.dispatchEvent(new Event('change',{bubbles:true}));
      changed++;
    }

    var message=document.getElementById('salaryModuleMessage');
    if(message){
      message.hidden=false;
      message.className='salary-module-message success';
      message.textContent=changed+' cumartesi Hafta tatili olarak işaretlendi. Kalıcı olması için Puantajı kaydet düğmesine basın.';
    }
  }

  function loadCollectiveListScript(){
    if(document.getElementById('salaryCollectiveListScript')) return;
    var script=document.createElement('script');
    script.id='salaryCollectiveListScript';
    script.src='assets/maas-puantaj-toplu-liste.js?v=1';
    script.defer=true;
    document.head.appendChild(script);
  }

  function init(){
    ensureButtons();
    applyMonthlyPaymentDefaults(false);
    loadCollectiveListScript();

    document.addEventListener('click',function(event){
      if(event.target.id==='fillSaturdays'){
        event.preventDefault();
        markSaturdays();
        return;
      }
      if(event.target.closest('[data-salary-tab="puantaj"]')) setTimeout(ensureButtons,80);
      if(event.target.id==='attendanceReload') setTimeout(function(){updateLink();ensureSaturdayButton();},80);
    });

    document.addEventListener('change',function(event){
      if(event.target.id==='attendancePeriod') updateLink();
      if(event.target.matches('form [name="period"]')&&event.target.closest('form')&&event.target.closest('form').querySelector('input[name="action"][value="save_salary"]')){
        applyMonthlyPaymentDefaults(true);
      }
    });

    var observer=new MutationObserver(function(){ensureButtons();});
    observer.observe(document.body,{childList:true,subtree:true});
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
