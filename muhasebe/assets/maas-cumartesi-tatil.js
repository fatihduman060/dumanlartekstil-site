(function(){
  'use strict';

  if(!/maaslar\.php/i.test(location.pathname)) return;

  function selectedPeriod(){
    var input=document.getElementById('attendancePeriod');
    if(input&&/^\d{4}-\d{2}$/.test(input.value||'')) return input.value;
    var query=new URL(location.href).searchParams.get('period');
    if(/^\d{4}-\d{2}$/.test(query||'')) return query;
    var now=new Date();
    return now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0');
  }

  function ensureButton(){
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

  function markSaturdaysAsWeeklyOff(){
    var period=selectedPeriod();
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

  function init(){
    ensureButton();

    document.addEventListener('click',function(event){
      if(event.target.id==='fillSaturdays'){
        event.preventDefault();
        markSaturdaysAsWeeklyOff();
        return;
      }
      if(event.target.closest('[data-salary-tab="puantaj"]')){
        setTimeout(ensureButton,80);
      }
    });

    var observer=new MutationObserver(function(){ensureButton();});
    observer.observe(document.body,{childList:true,subtree:true});
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init);
  else init();
})();
