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

  function updateLink(){
    var link=document.getElementById('attendanceBulkExcel');
    if(!link) return;
    var period=currentPeriod();
    link.href='maas-puantaj-toplu-excel.php?period='+encodeURIComponent(period);
    link.title=period+' dönemindeki bütün aktif personelin puantajını Excel olarak indir';
  }

  function ensureButton(){
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

  function init(){
    ensureButton();
    document.addEventListener('click',function(event){
      if(event.target.closest('[data-salary-tab="puantaj"]')) setTimeout(ensureButton,80);
      if(event.target.id==='attendanceReload') setTimeout(updateLink,80);
    });
    document.addEventListener('change',function(event){
      if(event.target.id==='attendancePeriod') updateLink();
    });
    var observer=new MutationObserver(function(){ensureButton();});
    observer.observe(document.body,{childList:true,subtree:true});
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
