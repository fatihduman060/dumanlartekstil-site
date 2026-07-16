(function(){
  'use strict';

  if(!/maaslar\.php/i.test(location.pathname)) return;

  var monthlyData=null;

  function num(value){
    var text=String(value==null?'':value).trim().replace(/\s/g,'').replace(/TL/gi,'');
    if(text.indexOf(',')>-1&&text.indexOf('.')>-1){
      if(text.lastIndexOf(',')>text.lastIndexOf('.')) text=text.replace(/\./g,'').replace(',','.');
      else text=text.replace(/,/g,'');
    }else if(text.indexOf(',')>-1){
      text=text.replace(/\./g,'').replace(',','.');
    }
    return Number(text)||0;
  }

  function fmt(value){
    return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(value||0))+' TL';
  }

  function csrf(){
    var input=document.querySelector('input[name="csrf_token"]');
    return input?input.value:'';
  }

  function getJson(url,params){
    return fetch(url+'?'+new URLSearchParams(params).toString()+'&_='+Date.now(),{
      credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}
    }).then(function(response){
      return response.json().then(function(data){
        if(!response.ok||!data.ok) throw new Error(data.error||'Haciz bilgisi yüklenemedi.');
        return data;
      });
    });
  }

  function monthlyForm(){
    var action=document.querySelector('form input[name="action"][value="save_salary"]');
    return action&&action.closest('form');
  }

  function ensureMonthlyField(){
    var form=monthlyForm();
    if(!form||form.querySelector('[name="garnishment_amount"]')) return;

    var grid=form.querySelector('.monthly-attendance-grid');
    if(!grid) return;

    var label=document.createElement('label');
    label.className='garnishment-field';
    label.innerHTML='Haciz kesintisi <input name="garnishment_amount" inputmode="decimal" min="0" placeholder="0,00"><small>Gün sayısını ve puantajı etkilemez.</small>';
    var missing=grid.querySelector('[name="missing_hours"]');
    var missingLabel=missing&&missing.closest('label');
    if(missingLabel) missingLabel.insertAdjacentElement('afterend',label); else grid.appendChild(label);

    var calculation=form.querySelector('.monthly-calculation');
    if(calculation&&!calculation.querySelector('[data-haciz-preview]')){
      var box=document.createElement('div');
      box.className='monthly-garnishment-preview';
      box.innerHTML='<span>Haciz kesintisi</span><strong data-haciz-preview>0,00 TL</strong>';
      var net=calculation.querySelector('.monthly-net');
      if(net) net.insertAdjacentElement('beforebegin',box); else calculation.appendChild(box);
    }

    var note=form.querySelector('.monthly-attendance-block>p');
    if(note) note.insertAdjacentHTML('beforeend',' <strong>Haciz kesintisi yalnızca net maaştan düşer; bordro günü 30 ise 30 kalır.</strong>');

    form.addEventListener('input',function(event){
      if(event.target.matches('[name="salary_amount"],[name="advance_amount"],[name="deduction_amount"],[name="absent_days"],[name="missing_hours"],[name="garnishment_amount"]')){
        setTimeout(recalculateMonthly,0);
      }
    });
    form.addEventListener('change',function(event){
      if(event.target.matches('[name="employee_id"],[name="period"]')) setTimeout(loadMonthlyGarnishment,80);
    });

    loadMonthlyGarnishment();
  }

  function recalculateMonthly(){
    var form=monthlyForm();
    if(!form) return;
    var base=num(form.querySelector('[name="salary_amount"]')&&form.querySelector('[name="salary_amount"]').value);
    var absent=Math.max(0,Math.min(30,num(form.querySelector('[name="absent_days"]')&&form.querySelector('[name="absent_days"]').value)));
    var missing=Math.max(0,num(form.querySelector('[name="missing_hours"]')&&form.querySelector('[name="missing_hours"]').value));
    var garnishment=Math.max(0,num(form.querySelector('[name="garnishment_amount"]')&&form.querySelector('[name="garnishment_amount"]').value));
    var manual=Math.max(0,num(form.querySelector('[name="deduction_amount"]')&&form.querySelector('[name="deduction_amount"]').value));
    var advance=Math.max(0,num(form.querySelector('[name="advance_amount"]')&&form.querySelector('[name="advance_amount"]').value));
    var additions=0;
    if(monthlyData&&monthlyData.payroll){
      additions=num(monthlyData.payroll.overtime_amount)+num(monthlyData.payroll.bonus_amount)+num(monthlyData.payroll.other_addition_amount);
    }
    var daily=base/30;
    var hourly=daily/9;
    var attendanceCut=absent*daily+missing*hourly;
    var net=Math.max(0,base+additions-attendanceCut-manual-garnishment-advance);
    var preview=form.querySelector('[data-haciz-preview]');
    var netEl=form.querySelector('[data-monthly-net]');
    if(preview) preview.textContent=fmt(garnishment);
    if(netEl) netEl.textContent=fmt(net);
  }

  function loadMonthlyGarnishment(){
    var form=monthlyForm();
    if(!form) return;
    var employee=form.querySelector('[name="employee_id"]');
    var period=form.querySelector('[name="period"]');
    if(!employee||!period||!employee.value) return;
    getJson('maas-aylik-kayit.php',{employee_id:employee.value,period:period.value}).then(function(data){
      monthlyData=data;
      var input=form.querySelector('[name="garnishment_amount"]');
      if(input) input.value=Number(data.garnishment_amount||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});
      recalculateMonthly();
    }).catch(function(){
      recalculateMonthly();
    });
  }

  function payrollForm(){return document.getElementById('payrollForm');}

  function ensurePayrollField(){
    var form=payrollForm();
    if(!form||form.querySelector('[name="garnishment_amount"]')) return;
    var other=form.querySelector('[name="other_deduction_amount"]');
    var otherLabel=other&&other.closest('label');
    var label=document.createElement('label');
    label.className='garnishment-field';
    label.innerHTML='Haciz kesintisi<input name="garnishment_amount" inputmode="decimal" min="0" placeholder="0,00"><small>Net maaştan düşer, bordro gününü değiştirmez.</small>';
    if(otherLabel) otherLabel.insertAdjacentElement('beforebegin',label);
    else {
      var box=form.querySelector('.payroll-box:nth-child(2)');
      if(box) box.appendChild(label);
    }
    form.addEventListener('input',function(){setTimeout(recalculatePayroll,0);});
  }

  function recalculatePayroll(){
    var form=payrollForm();
    if(!form) return;
    var base=num(document.getElementById('payrollBase')&&document.getElementById('payrollBase').value);
    var overtime=num(form.querySelector('[name="overtime_amount"]')&&form.querySelector('[name="overtime_amount"]').value);
    var bonus=num(form.querySelector('[name="bonus_amount"]')&&form.querySelector('[name="bonus_amount"]').value);
    var addition=num(form.querySelector('[name="other_addition_amount"]')&&form.querySelector('[name="other_addition_amount"]').value);
    var absence=num(document.getElementById('payrollAbsence')&&document.getElementById('payrollAbsence').value);
    var missing=num(document.getElementById('payrollMissingHour')&&document.getElementById('payrollMissingHour').value);
    var garnishment=num(form.querySelector('[name="garnishment_amount"]')&&form.querySelector('[name="garnishment_amount"]').value);
    var other=num(form.querySelector('[name="other_deduction_amount"]')&&form.querySelector('[name="other_deduction_amount"]').value);
    var advance=num(form.querySelector('[name="advance_amount"]')&&form.querySelector('[name="advance_amount"]').value);
    var net=Math.max(0,base+overtime+bonus+addition-absence-missing-garnishment-other-advance);
    var netEl=document.getElementById('payrollNet');
    if(netEl) netEl.textContent=fmt(net);
  }

  function loadPayrollGarnishment(){
    ensurePayrollField();
    var form=payrollForm();
    var employee=document.getElementById('payrollEmployee');
    var period=document.getElementById('payrollPeriod');
    if(!form||!employee||!period||!employee.value||!period.value) return;
    getJson('maas-puantaj.php',{employee_id:employee.value,period:period.value}).then(function(data){
      var payroll=data.payroll||{};
      var input=form.querySelector('[name="garnishment_amount"]');
      if(input) input.value=Number(payroll.garnishment_amount||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});
      setTimeout(recalculatePayroll,0);
    }).catch(function(){setTimeout(recalculatePayroll,0);});
  }

  function addStyles(){
    if(document.getElementById('salaryGarnishmentStyles')) return;
    var style=document.createElement('style');
    style.id='salaryGarnishmentStyles';
    style.textContent='.garnishment-field{padding:10px;border:1px solid #d8b866;border-radius:12px;background:#fff8e7!important}.garnishment-field small{display:block;margin-top:2px;color:#765715;font-size:10px;font-weight:700}.monthly-attendance-grid{grid-template-columns:repeat(5,minmax(0,1fr))!important}.monthly-calculation{grid-template-columns:140px 1fr 1fr 1.35fr!important}.monthly-garnishment-preview{background:#fff8e7!important;border-color:#d8b866!important}.monthly-garnishment-preview strong{color:#8a6114!important}@media(max-width:1180px){.monthly-attendance-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}.monthly-calculation{grid-template-columns:repeat(2,minmax(0,1fr))!important}.monthly-net{grid-column:1/-1}}@media(max-width:720px){.monthly-attendance-grid,.monthly-calculation{grid-template-columns:1fr!important}.monthly-net{grid-column:auto}}';
    document.head.appendChild(style);
  }

  function init(){
    addStyles();
    ensureMonthlyField();
    ensurePayrollField();

    document.addEventListener('click',function(event){
      if(event.target.closest('[data-salary-tab="bordro"]')||event.target.id==='payrollReload'){
        setTimeout(loadPayrollGarnishment,250);
        setTimeout(loadPayrollGarnishment,700);
      }
    });
    document.addEventListener('change',function(event){
      if(event.target.id==='payrollEmployee'||event.target.id==='payrollPeriod') setTimeout(loadPayrollGarnishment,120);
    });
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
