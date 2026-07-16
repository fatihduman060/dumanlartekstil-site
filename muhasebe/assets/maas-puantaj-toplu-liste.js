(function(){
  'use strict';
  if(!/maaslar\.php/i.test(location.pathname)) return;

  var listState={period:'',data:null,loading:false};

  function esc(value){
    return String(value==null?'':value).replace(/[&<>"']/g,function(ch){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
    });
  }

  function fmtMoney(value){
    var number=Number(value||0);
    try{return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(number)+' TL';}
    catch(e){return number.toFixed(2).replace('.',',')+' TL';}
  }

  function fmtNumber(value,digits){
    return Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:0,maximumFractionDigits:digits==null?2:digits});
  }

  function currentPeriod(){
    var activeTab=document.querySelector('[data-salary-tab].is-active');
    var preferred=activeTab&&activeTab.dataset.salaryTab==='bordro'?document.getElementById('payrollPeriod'):document.getElementById('attendancePeriod');
    if(preferred&&/^\d{4}-\d{2}$/.test(preferred.value||'')) return preferred.value;
    var attendance=document.getElementById('attendancePeriod');
    if(attendance&&/^\d{4}-\d{2}$/.test(attendance.value||'')) return attendance.value;
    var query=new URL(location.href).searchParams.get('period');
    if(/^\d{4}-\d{2}$/.test(query||'')) return query;
    var now=new Date();
    return now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0');
  }

  function statusLabel(status){
    return {bekliyor:'Bekliyor',kismi:'Kısmi ödendi',odendi:'Ödendi'}[status]||status||'Bekliyor';
  }

  function statusTone(status){
    return status==='odendi'?'success':(status==='kismi'?'info':'warning');
  }

  function addStyles(){
    if(document.getElementById('salaryCollectiveListStyles')) return;
    var style=document.createElement('style');
    style.id='salaryCollectiveListStyles';
    style.textContent='.salary-collective-list{margin-top:18px;border:1px solid #e2d8ca;border-radius:18px;background:#fff;overflow:hidden}.salary-collective-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:15px 16px;background:#fbf7ef;border-bottom:1px solid #e5dccf}.salary-collective-head h3{margin:2px 0 0;color:#102818}.salary-collective-head span{font-size:10px;font-weight:950;color:#8a6a26;letter-spacing:.06em}.salary-collective-head p{margin:4px 0 0;color:#776b5c;font-size:11px}.salary-collective-search{width:min(290px,100%);min-height:40px;border:1px solid #d9cdbc;border-radius:999px;padding:8px 13px;background:#fff}.salary-collective-scroll{overflow:auto;max-height:560px}.salary-collective-table{width:100%;min-width:1080px;border-collapse:separate;border-spacing:0}.salary-collective-table th{position:sticky;top:0;z-index:2;padding:10px 11px;background:#16482e;color:#fff;text-align:left;font-size:10px;text-transform:uppercase;white-space:nowrap}.salary-collective-table td{padding:11px;border-bottom:1px solid #eee5d7;vertical-align:middle;font-size:12px}.salary-collective-table tbody tr:hover{background:#fffaf0}.salary-collective-table strong{display:block;color:#102818}.salary-collective-table small{display:block;margin-top:3px;color:#776b5c}.salary-collective-table .number{text-align:right;white-space:nowrap}.salary-collective-table .center{text-align:center}.salary-list-badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 8px;font-size:10px;font-weight:900;white-space:nowrap}.salary-list-badge.success{background:#e8f5eb;color:#176133}.salary-list-badge.warning{background:#fff3d8;color:#8a6114}.salary-list-badge.info{background:#eaf2ff;color:#315c96}.salary-list-open{border:1px solid #c9d6cc;border-radius:999px;padding:7px 10px;background:#fff;color:#16482e;font-weight:900;cursor:pointer;white-space:nowrap}.salary-list-empty{padding:24px!important;text-align:center;color:#776b5c}.salary-list-current{background:#edf6ef!important}@media(max-width:760px){.salary-collective-head{align-items:stretch;flex-direction:column}.salary-collective-search{width:100%}}';
    document.head.appendChild(style);
  }

  function ensureAttendanceList(){
    var workspace=document.querySelector('[data-salary-panel="puantaj"] .salary-workspace');
    if(!workspace) return null;
    var section=document.getElementById('attendanceCollectiveList');
    if(section) return section;
    section=document.createElement('section');
    section.id='attendanceCollectiveList';
    section.className='salary-collective-list';
    section.innerHTML='<div class="salary-collective-head"><div><span>AYLIK PERSONEL LİSTESİ</span><h3>Bütün personellerin puantaj durumu</h3><p>Bir satırı açarak üstteki günlük puantajı görüntüleyebilir veya düzenleyebilirsiniz.</p></div><input class="salary-collective-search" id="attendanceCollectiveSearch" placeholder="Personel ara…"></div><div class="salary-collective-scroll"><table class="salary-collective-table"><thead><tr><th>Personel</th><th>Durum</th><th class="center">Bordro günü</th><th class="center">Çalıştı</th><th class="center">Hafta/Tatil</th><th class="center">Devamsızlık</th><th class="center">Eksik saat</th><th class="center">Fazla mesai</th><th>Kaynak</th><th>İşlem</th></tr></thead><tbody id="attendanceCollectiveBody"><tr><td colspan="10" class="salary-list-empty">Liste hazırlanıyor…</td></tr></tbody></table></div>';
    var saveRow=workspace.querySelector('.salary-save-row');
    if(saveRow) saveRow.insertAdjacentElement('afterend',section); else workspace.appendChild(section);
    return section;
  }

  function ensurePayrollList(){
    var workspace=document.querySelector('[data-salary-panel="bordro"] .salary-workspace');
    if(!workspace) return null;
    var section=document.getElementById('payrollCollectiveList');
    if(section) return section;
    section=document.createElement('section');
    section.id='payrollCollectiveList';
    section.className='salary-collective-list';
    section.innerHTML='<div class="salary-collective-head"><div><span>AYLIK BORDRO LİSTESİ</span><h3>Bütün personellerin bordro özeti</h3><p>Net maaş, avans, haciz, ödeme ve kalan tutar tek tabloda görünür.</p></div><input class="salary-collective-search" id="payrollCollectiveSearch" placeholder="Personel ara…"></div><div class="salary-collective-scroll"><table class="salary-collective-table"><thead><tr><th>Personel</th><th>Durum</th><th class="center">Bordro günü</th><th class="number">Brüt</th><th class="number">Toplam kesinti</th><th class="number">Haciz</th><th class="number">Avans</th><th class="number">Net</th><th class="number">Ödenen</th><th class="number">Kalan</th><th>Ödeme</th><th>İşlem</th></tr></thead><tbody id="payrollCollectiveBody"><tr><td colspan="12" class="salary-list-empty">Liste hazırlanıyor…</td></tr></tbody></table></div>';
    workspace.appendChild(section);
    return section;
  }

  function ensureLists(){
    addStyles();
    var attendance=ensureAttendanceList();
    var payroll=ensurePayrollList();
    return !!(attendance||payroll);
  }

  function selectedEmployeeId(type){
    var select=document.getElementById(type==='payroll'?'payrollEmployee':'attendanceEmployee');
    return Number(select&&select.value||0);
  }

  function renderAttendanceRows(rows){
    var body=document.getElementById('attendanceCollectiveBody');
    if(!body) return;
    var selected=selectedEmployeeId('attendance');
    if(!rows.length){body.innerHTML='<tr><td colspan="10" class="salary-list-empty">Aktif personel bulunamadı.</td></tr>';return;}
    body.innerHTML=rows.map(function(row){
      var holidays=Number(row.weekly_off_days||0)+Number(row.holiday_days||0);
      var badge=row.has_attendance?'<span class="salary-list-badge success">Kaydedildi</span>':'<span class="salary-list-badge warning">Bekliyor</span>';
      return '<tr data-name="'+esc(String(row.full_name||'').toLocaleLowerCase('tr-TR'))+'" class="'+(Number(row.employee_id)===selected?'salary-list-current':'')+'">'
        +'<td><strong>'+esc(row.full_name)+'</strong><small>'+esc([row.department,row.position].filter(Boolean).join(' / ')||'-')+'</small></td>'
        +'<td>'+badge+'</td>'
        +'<td class="center"><strong>'+fmtNumber(row.paid_days,1)+' gün</strong></td>'
        +'<td class="center">'+fmtNumber(row.work_days,1)+'</td>'
        +'<td class="center">'+fmtNumber(holidays,1)+'</td>'
        +'<td class="center"><strong>'+fmtNumber(row.absent_days,1)+' gün</strong></td>'
        +'<td class="center">'+fmtNumber(row.missing_hours,2)+' saat</td>'
        +'<td class="center">'+fmtNumber(row.overtime_hours,2)+' saat</td>'
        +'<td>'+esc(row.source)+'</td>'
        +'<td><button type="button" class="salary-list-open" data-open-attendance="'+row.employee_id+'">Puantajı aç</button></td>'
        +'</tr>';
    }).join('');
  }

  function renderPayrollRows(rows){
    var body=document.getElementById('payrollCollectiveBody');
    if(!body) return;
    var selected=selectedEmployeeId('payroll');
    if(!rows.length){body.innerHTML='<tr><td colspan="12" class="salary-list-empty">Aktif personel bulunamadı.</td></tr>';return;}
    body.innerHTML=rows.map(function(row){
      var badge=row.has_payroll?'<span class="salary-list-badge '+statusTone(row.status)+'">'+esc(statusLabel(row.status))+'</span>':'<span class="salary-list-badge warning">Bordro bekliyor</span>';
      var account=[row.account_name,row.bank_name].filter(Boolean).join(' / ');
      return '<tr data-name="'+esc(String(row.full_name||'').toLocaleLowerCase('tr-TR'))+'" class="'+(Number(row.employee_id)===selected?'salary-list-current':'')+'">'
        +'<td><strong>'+esc(row.full_name)+'</strong><small>'+esc([row.department,row.position].filter(Boolean).join(' / ')||'-')+'</small></td>'
        +'<td>'+badge+'</td>'
        +'<td class="center">'+fmtNumber(row.paid_days,1)+' gün</td>'
        +'<td class="number">'+fmtMoney(row.gross_earning)+'</td>'
        +'<td class="number">'+fmtMoney(row.total_deduction_amount)+'</td>'
        +'<td class="number">'+fmtMoney(row.garnishment_amount)+'</td>'
        +'<td class="number">'+fmtMoney(row.advance_amount)+'</td>'
        +'<td class="number"><strong>'+fmtMoney(row.net_payable)+'</strong></td>'
        +'<td class="number">'+fmtMoney(row.paid_amount)+'</td>'
        +'<td class="number"><strong>'+fmtMoney(row.remaining_amount)+'</strong></td>'
        +'<td>'+esc(row.payment_date||'-')+'<small>'+esc(account||'-')+'</small></td>'
        +'<td><button type="button" class="salary-list-open" data-open-payroll="'+row.employee_id+'">Bordroyu aç</button></td>'
        +'</tr>';
    }).join('');
  }

  function render(){
    ensureLists();
    if(!listState.data) return;
    renderAttendanceRows(listState.data.attendance_rows||[]);
    renderPayrollRows(listState.data.payroll_rows||[]);
  }

  function loadLists(period,force){
    period=/^\d{4}-\d{2}$/.test(period||'')?period:currentPeriod();
    if(listState.loading) return;
    if(!force&&listState.data&&listState.period===period){render();return;}
    listState.loading=true;
    ensureLists();
    fetch('maas-puantaj-toplu-liste.php?period='+encodeURIComponent(period)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}})
      .then(function(response){return response.json().then(function(data){if(!response.ok||!data.ok) throw new Error(data.error||'Toplu liste yüklenemedi.');return data;});})
      .then(function(data){listState.data=data;listState.period=data.period;render();})
      .catch(function(error){
        ['attendanceCollectiveBody','payrollCollectiveBody'].forEach(function(id){var body=document.getElementById(id);if(body)body.innerHTML='<tr><td colspan="12" class="salary-list-empty">'+esc(error.message)+'</td></tr>';});
      })
      .finally(function(){listState.loading=false;});
  }

  function openEmployee(type,employeeId){
    var isPayroll=type==='payroll';
    var select=document.getElementById(isPayroll?'payrollEmployee':'attendanceEmployee');
    var periodInput=document.getElementById(isPayroll?'payrollPeriod':'attendancePeriod');
    var reload=document.getElementById(isPayroll?'payrollReload':'attendanceReload');
    if(select) select.value=String(employeeId);
    if(periodInput) periodInput.value=listState.period||currentPeriod();
    if(reload) reload.click();
    setTimeout(function(){
      var workspace=document.querySelector('[data-salary-panel="'+(isPayroll?'bordro':'puantaj')+'"] .salary-workspace-head');
      if(workspace) workspace.scrollIntoView({behavior:'smooth',block:'start'});
      render();
    },120);
  }

  function filterRows(input){
    var section=input.closest('.salary-collective-list');
    if(!section) return;
    var query=String(input.value||'').trim().toLocaleLowerCase('tr-TR');
    section.querySelectorAll('tbody tr[data-name]').forEach(function(row){row.hidden=query&&String(row.dataset.name||'').indexOf(query)===-1;});
  }

  function scheduleRefresh(){
    setTimeout(function(){loadLists(currentPeriod(),true);},850);
    setTimeout(function(){loadLists(currentPeriod(),true);},1900);
  }

  function init(){
    ensureLists();
    setTimeout(function(){loadLists(currentPeriod(),true);},180);

    document.addEventListener('click',function(event){
      var attendance=event.target.closest('[data-open-attendance]');
      if(attendance){openEmployee('attendance',Number(attendance.dataset.openAttendance));return;}
      var payroll=event.target.closest('[data-open-payroll]');
      if(payroll){openEmployee('payroll',Number(payroll.dataset.openPayroll));return;}
      if(event.target.closest('[data-salary-tab="puantaj"],[data-salary-tab="bordro"]')){
        setTimeout(function(){ensureLists();loadLists(currentPeriod(),false);},100);
      }
      if(event.target.id==='attendanceReload'||event.target.id==='payrollReload'){
        setTimeout(function(){loadLists(currentPeriod(),false);},220);
      }
      if(event.target.id==='saveAttendance') scheduleRefresh();
    });

    document.addEventListener('submit',function(event){
      if(event.target.id==='payrollForm') scheduleRefresh();
    });

    document.addEventListener('change',function(event){
      if(event.target.id==='attendancePeriod'||event.target.id==='payrollPeriod'){
        loadLists(event.target.value,true);
      }
      if(event.target.id==='attendanceEmployee'||event.target.id==='payrollEmployee') render();
    });

    document.addEventListener('input',function(event){
      if(event.target.classList.contains('salary-collective-search')) filterRows(event.target);
    });

    var message=document.getElementById('salaryModuleMessage');
    if(message){
      new MutationObserver(function(){
        var text=String(message.textContent||'').toLocaleLowerCase('tr-TR');
        if(text.indexOf('kaydedildi')>-1||text.indexOf('güncellendi')>-1) scheduleRefresh();
      }).observe(message,{childList:true,subtree:true,characterData:true});
    }

    new MutationObserver(function(){ensureLists();}).observe(document.body,{childList:true,subtree:true});
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
