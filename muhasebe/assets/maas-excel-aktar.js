(function(){
  var state={data:null,entries:{},selectedDate:'',period:'',employeeId:0};

  function esc(value){return String(value==null?'':value).replace(/[&<>"']/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];});}
  function fmt(value){var n=Number(value||0);try{return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(n)+' TL';}catch(e){return n.toFixed(2).replace('.',',')+' TL';}}
  function num(value){var text=String(value==null?'':value).trim().replace(/\s/g,'');if(text.indexOf(',')>-1&&text.indexOf('.')>-1){if(text.lastIndexOf(',')>text.lastIndexOf('.'))text=text.replace(/\./g,'').replace(',','.');else text=text.replace(/,/g,'');}else if(text.indexOf(',')>-1){text=text.replace(/\./g,'').replace(',','.');}return Number(text)||0;}
  function trDate(value){var p=String(value||'').split('-');return p.length===3?p[2]+'.'+p[1]+'.'+p[0]:String(value||'');}
  function csrf(){var input=document.querySelector('input[name="csrf_token"]');return input?input.value:'';}
  function currentPeriod(){var p=new URL(location.href).searchParams.get('period');if(/^\d{4}-\d{2}$/.test(p||''))return p;var d=new Date();return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');}
  function message(text,tone){var el=document.getElementById('salaryModuleMessage');if(!el)return;el.textContent=text||'';el.className='salary-module-message '+(tone||'');el.hidden=!text;}
  function userKey(value){return String(value||'').toLocaleLowerCase('tr-TR').replace(/ı/g,'i').replace(/ğ/g,'g').replace(/ü/g,'u').replace(/ş/g,'s').replace(/ö/g,'o').replace(/ç/g,'c').replace(/[^a-z0-9]+/g,'');}

  function applySalaryOnlyShell(){
    var footer=document.querySelector('.side-footer');
    var name=footer&&footer.querySelector('strong');
    if(!name||userKey(name.textContent).indexOf('uzeyir')!==0)return;
    document.body.classList.add('salary-only-user');
    var nav=document.querySelector('.side-nav');
    if(nav)nav.innerHTML='<a class="active" href="maaslar.php"><span class="nav-ico">₺</span><span>Maaşlar</span></a>';
    var role=footer.querySelector('span');if(role)role.textContent='Maaş Kullanıcısı';
    var brand=document.querySelector('.sidebar .brand');if(brand)brand.setAttribute('href','maaslar.php');
    var siteLink=document.querySelector('.top-actions .ghost-link');if(siteLink)siteLink.remove();
  }

  function request(method,params){
    var options={method:method,credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}};
    var url='maas-puantaj.php';
    if(method==='GET'){
      url+='?'+new URLSearchParams(params).toString()+'&_='+Date.now();
    }else{
      var body=new URLSearchParams(params);body.set('csrf_token',csrf());options.body=body;options.headers['Content-Type']='application/x-www-form-urlencoded;charset=UTF-8';
    }
    return fetch(url,options).then(function(response){return response.json().catch(function(){return {ok:false,error:'Sunucu cevabı okunamadı.'};}).then(function(data){if(!response.ok||!data.ok)throw new Error(data.error||'İşlem tamamlanamadı.');return data;});});
  }

  function createExcelCard(grid){
    var card=document.getElementById('maasExcelAktarCard');if(card)return card;
    card=document.createElement('section');card.id='maasExcelAktarCard';card.className='salary-card maas-excel-card';
    card.innerHTML='<div class="salary-card-head"><h3>Excel’den personel aktar</h3><span>Ad Soyad + Maaş</span></div><div class="salary-body"><form method="post" action="maas-excel-aktar.php" enctype="multipart/form-data" class="salary-form"><input type="hidden" name="csrf_token" value="'+esc(csrf())+'"><label>Excel / CSV dosyası<input type="file" name="salary_excel" accept=".xlsx,.csv,text/csv" required></label><p class="muted" style="margin:0">Başlıklar: <strong>Ad Soyad</strong> ve <strong>Maaş</strong> olmalı. İsteğe bağlı: Bölüm, Görev, Telefon.</p><button class="btn btn-primary" type="submit">Excel dosyasını aktar</button></form></div>';
    var summary=grid.querySelector('.salary-summary');if(summary)summary.insertAdjacentElement('afterend',card);else grid.appendChild(card);return card;
  }

  function attendanceMarkup(){
    return '<section class="salary-workspace">'+
      '<div class="salary-workspace-head"><div><span>PUANTAJ</span><h2>Günlük puantaj ve otomatik bordro</h2><p>Devamsızlık günü bordro gününü düşürür; eksik saat yalnızca ücret kesintisine yansır.</p></div><div class="salary-toolbar"><input type="month" id="attendancePeriod"><select id="attendanceEmployee"></select><button type="button" class="btn btn-secondary" id="attendanceReload">Getir</button></div></div>'+
      '<div class="salary-rule-note"><b>Hesap:</b> Günlük yevmiye = maaş ÷ 30 · Saatlik ücret = günlük yevmiye ÷ 9</div>'+
      '<div class="attendance-actions"><button type="button" id="fillWorked">Boş günleri Çalıştı yap</button><button type="button" id="fillSundays">Pazarları Hafta tatili yap</button><button type="button" id="clearAttendance">Ayı temizle</button><a id="attendancePrint" target="_blank">Puantajı yazdır</a></div>'+
      '<div class="attendance-summary" id="attendanceSummary"></div>'+
      '<div class="attendance-layout"><div><div class="attendance-weekdays"><span>Pzt</span><span>Sal</span><span>Çar</span><span>Per</span><span>Cum</span><span>Cmt</span><span>Paz</span></div><div class="attendance-calendar" id="attendanceCalendar"></div></div><aside class="attendance-editor" id="attendanceEditor"><p class="muted">Düzenlemek için bir güne dokun.</p></aside></div>'+
      '<div class="salary-save-row"><span class="muted">Kaydedildiğinde bordro ve aylık maaş kaydı otomatik güncellenir.</span><button type="button" class="btn btn-primary" id="saveAttendance">Puantajı kaydet</button></div>'+
    '</section>';
  }

  function payrollMarkup(){
    return '<section class="salary-workspace">'+
      '<div class="salary-workspace-head"><div><span>BORDRO</span><h2>Puantajdan otomatik maaş hesabı</h2><p>Puantaj kaydı bordroyu oluşturur. Burada yalnızca prim, ek ödeme, avans ve ödeme bilgilerini düzenleyebilirsin.</p></div><div class="salary-toolbar"><input type="month" id="payrollPeriod"><select id="payrollEmployee"></select><button type="button" class="btn btn-secondary" id="payrollReload">Getir</button></div></div>'+
      '<div class="payroll-attendance-summary" id="payrollAttendanceSummary"></div>'+
      '<form id="payrollForm" class="payroll-form"><div class="payroll-columns">'+
        '<article class="payroll-box"><h3>Kazançlar ve ücret oranları</h3><label>Aylık maaş<input id="payrollBase" readonly></label><div class="two-rates"><label>Günlük yevmiye<input id="payrollDaily" readonly></label><label>Saatlik ücret<input id="payrollHourly" readonly></label></div><label>Fazla mesai tutarı<input name="overtime_amount" inputmode="decimal" placeholder="0,00"></label><label>Prim<input name="bonus_amount" inputmode="decimal" placeholder="0,00"></label><label>Diğer ek ödeme<input name="other_addition_amount" inputmode="decimal" placeholder="0,00"></label><div class="payroll-total"><span>Brüt hakediş</span><strong id="payrollGross">0,00 TL</strong></div></article>'+
        '<article class="payroll-box"><h3>Otomatik kesinti ve ödeme</h3><label>Devamsızlık kesintisi<input id="payrollAbsence" readonly></label><label>Eksik saat kesintisi<input id="payrollMissingHour" readonly></label><label>Diğer kesinti<input name="other_deduction_amount" inputmode="decimal" placeholder="0,00"></label><label>Avans<input name="advance_amount" inputmode="decimal" placeholder="0,00"></label><div class="payroll-total"><span>Net ödenecek</span><strong id="payrollNet">0,00 TL</strong></div></article>'+
      '</div><div class="payroll-payment"><label>Ödenen<input name="paid_amount" inputmode="decimal" placeholder="0,00"></label><label>Ödeme tarihi<input type="date" name="payment_date"></label><label>Kasa/Banka hesabı<select name="account_id"><option value="">Sadece bordro kaydı</option></select></label><label class="wide">Açıklama<textarea name="note" rows="2"></textarea></label></div><div class="salary-save-row"><button class="btn btn-primary" type="submit">Bordroyu güncelle</button><a id="payrollPrint" target="_blank" class="btn btn-secondary" hidden>Bordroyu yazdır</a></div></form>'+
    '</section>';
  }

  function createTabs(grid,excelCard){
    if(document.getElementById('salaryModuleTabs'))return;
    var hero=grid.querySelector('.salary-hero'),summary=grid.querySelector('.salary-summary'),columns=grid.querySelector('.salary-columns');if(!hero||!summary||!columns)return;
    var tabs=document.createElement('nav');tabs.id='salaryModuleTabs';tabs.className='salary-module-tabs';tabs.innerHTML='<button type="button" data-salary-tab="maas">Maaş Takibi</button><button type="button" data-salary-tab="puantaj">Puantaj</button><button type="button" data-salary-tab="bordro">Bordro</button>';hero.insertAdjacentElement('afterend',tabs);
    var msg=document.createElement('div');msg.id='salaryModuleMessage';msg.hidden=true;tabs.insertAdjacentElement('afterend',msg);
    var salaryPanel=document.createElement('div');salaryPanel.className='salary-tab-panel';salaryPanel.dataset.salaryPanel='maas';msg.insertAdjacentElement('afterend',salaryPanel);salaryPanel.appendChild(summary);salaryPanel.appendChild(excelCard);salaryPanel.appendChild(columns);
    var attendance=document.createElement('div');attendance.className='salary-tab-panel';attendance.dataset.salaryPanel='puantaj';attendance.hidden=true;attendance.innerHTML=attendanceMarkup();salaryPanel.insertAdjacentElement('afterend',attendance);
    var payroll=document.createElement('div');payroll.className='salary-tab-panel';payroll.dataset.salaryPanel='bordro';payroll.hidden=true;payroll.innerHTML=payrollMarkup();attendance.insertAdjacentElement('afterend',payroll);

    function activate(tab,updateUrl){if(['maas','puantaj','bordro'].indexOf(tab)<0)tab='maas';document.querySelectorAll('[data-salary-panel]').forEach(function(panel){panel.hidden=panel.dataset.salaryPanel!==tab;});tabs.querySelectorAll('[data-salary-tab]').forEach(function(button){button.classList.toggle('is-active',button.dataset.salaryTab===tab);});if(updateUrl){var u=new URL(location.href);if(tab==='maas')u.searchParams.delete('salary_tab');else u.searchParams.set('salary_tab',tab);history.replaceState(null,'',u.pathname+u.search+u.hash);}if(tab!=='maas'&&!state.data)loadData(state.period,state.employeeId);}
    tabs.addEventListener('click',function(event){var button=event.target.closest('[data-salary-tab]');if(button)activate(button.dataset.salaryTab,true);});
    activate(new URL(location.href).searchParams.get('salary_tab')||'maas',false);
  }

  function employeeOptions(employees,selected){return (employees||[]).map(function(emp){return '<option value="'+emp.id+'" '+(Number(emp.id)===Number(selected)?'selected':'')+'>'+esc(emp.full_name)+'</option>';}).join('');}
  function accountOptions(accounts,selected){return '<option value="">Sadece bordro kaydı</option>'+(accounts||[]).map(function(acc){var label=acc.name+(acc.bank_name?' / '+acc.bank_name:'');return '<option value="'+acc.id+'" '+(Number(acc.id)===Number(selected)?'selected':'')+'>'+esc(label)+'</option>';}).join('');}

  function loadData(period,employeeId){
    state.period=/^\d{4}-\d{2}$/.test(period||'')?period:currentPeriod();state.employeeId=Number(employeeId||0);message('Veriler yükleniyor…','info');
    return request('GET',{period:state.period,employee_id:state.employeeId}).then(function(data){state.data=data;state.period=data.period;state.employeeId=Number(data.employee_id||0);state.entries=JSON.parse(JSON.stringify(data.entries||{}));state.selectedDate='';renderAll();message('', '');return data;}).catch(function(error){message(error.message,'error');throw error;});
  }

  function renderSelectors(){if(!state.data)return;['attendancePeriod','payrollPeriod'].forEach(function(id){var el=document.getElementById(id);if(el)el.value=state.period;});['attendanceEmployee','payrollEmployee'].forEach(function(id){var el=document.getElementById(id);if(el)el.innerHTML=employeeOptions(state.data.employees,state.employeeId);});var account=document.querySelector('#payrollForm select[name="account_id"]');if(account)account.innerHTML=accountOptions(state.data.accounts,state.data.payroll&&state.data.payroll.account_id);}

  function localSummary(){
    var result={recorded_days:0,paid_days:30,work_days:0,paid_leave_days:0,report_days:0,absent_days:0,weekly_off_days:0,holiday_days:0,overtime_hours:0,missing_hours:0};
    Object.keys(state.entries).forEach(function(date){var e=state.entries[date]||{};if(!e.status)return;result.recorded_days++;if(e.status==='calisti')result.work_days++;if(e.status==='izinli')result.paid_leave_days++;if(e.status==='raporlu')result.report_days++;if(e.status==='gelmedi')result.absent_days++;if(e.status==='hafta_tatili')result.weekly_off_days++;if(e.status==='resmi_tatil')result.holiday_days++;result.overtime_hours+=Number(e.overtime_hours||0);if(e.status!=='gelmedi')result.missing_hours+=Number(e.missing_hours||0);});
    result.paid_days=Math.max(0,30-result.absent_days);return result;
  }

  function summaryCards(summary){
    var holiday=Number(summary.weekly_off_days||0)+Number(summary.holiday_days||0);
    var cards=[['Bordro günü',summary.paid_days],['Devamsızlık',summary.absent_days+' gün'],['Eksik saat',Number(summary.missing_hours||0).toLocaleString('tr-TR',{maximumFractionDigits:2})+' saat'],['Çalıştı',summary.work_days],['İzinli',summary.paid_leave_days],['Raporlu',summary.report_days],['Tatil',holiday],['Fazla mesai',Number(summary.overtime_hours||0).toLocaleString('tr-TR',{maximumFractionDigits:2})+' saat']];
    return cards.map(function(c){return '<div><span>'+c[0]+'</span><strong>'+c[1]+'</strong></div>';}).join('');
  }

  function renderAttendance(){
    if(!state.data)return;var summary=localSummary(),summaryEl=document.getElementById('attendanceSummary');if(summaryEl)summaryEl.innerHTML=summaryCards(summary);
    var calendar=document.getElementById('attendanceCalendar');if(!calendar)return;calendar.innerHTML='';var parts=state.period.split('-'),year=Number(parts[0]),month=Number(parts[1]);var first=new Date(year,month-1,1).getDay();var offset=first===0?6:first-1;for(var i=0;i<offset;i++){calendar.insertAdjacentHTML('beforeend','<span class="attendance-empty"></span>');}
    var statuses=state.data.statuses||{};
    for(var day=1;day<=Number(state.data.days_in_month||30);day++){
      var date=state.period+'-'+String(day).padStart(2,'0'),entry=state.entries[date]||{},meta=statuses[entry.status]||{},weekDay=new Date(year,month-1,day).getDay();
      var details=[];if(Number(entry.missing_hours||0)>0)details.push('-'+Number(entry.missing_hours).toLocaleString('tr-TR',{maximumFractionDigits:2})+'s');if(Number(entry.overtime_hours||0)>0)details.push('+'+Number(entry.overtime_hours).toLocaleString('tr-TR',{maximumFractionDigits:2})+'s');
      var button=document.createElement('button');button.type='button';button.className='attendance-day status-'+(entry.status||'empty')+(weekDay===0||weekDay===6?' weekend':'')+(state.selectedDate===date?' selected':'');button.dataset.date=date;button.innerHTML='<span>'+day+'</span><b>'+(meta.short||'—')+'</b><small>'+(details.length?details.join(' · '):'&nbsp;')+'</small>';button.title=(meta.label||'Boş')+(entry.note?' · '+entry.note:'');calendar.appendChild(button);
    }
    var print=document.getElementById('attendancePrint');if(print)print.href='maas-puantaj-yazdir.php?employee_id='+state.employeeId+'&period='+encodeURIComponent(state.period);renderAttendanceEditor();renderPayrollSummary(summary);
  }

  function renderAttendanceEditor(){
    var editor=document.getElementById('attendanceEditor');if(!editor)return;if(!state.selectedDate){editor.innerHTML='<p class="muted">Düzenlemek için bir güne dokun.</p>';return;}
    var entry=state.entries[state.selectedDate]||{},statuses=state.data.statuses||{};var options='<option value="">Boş / kayıt yok</option>'+Object.keys(statuses).map(function(key){return '<option value="'+key+'" '+(entry.status===key?'selected':'')+'>'+esc(statuses[key].label)+'</option>';}).join('');
    var missingDisabled=entry.status==='gelmedi'?' disabled':'';
    editor.innerHTML='<span>SEÇİLİ GÜN</span><h3>'+trDate(state.selectedDate)+'</h3><label>Durum<select id="attendanceStatus">'+options+'</select></label><label>Eksik / geç giriş saati<input id="attendanceMissing" inputmode="decimal" value="'+esc(entry.missing_hours||'')+'" placeholder="0"'+missingDisabled+'></label><label>Fazla mesai saati<input id="attendanceOvertime" inputmode="decimal" value="'+esc(entry.overtime_hours||'')+'" placeholder="0"></label><label>Açıklama<textarea id="attendanceNote" rows="4">'+esc(entry.note||'')+'</textarea></label><button type="button" class="btn btn-secondary" id="clearSelectedDay">Bu günü temizle</button>';
  }

  function updateSelected(){
    if(!state.selectedDate)return;var status=document.getElementById('attendanceStatus'),missing=document.getElementById('attendanceMissing'),overtime=document.getElementById('attendanceOvertime'),note=document.getElementById('attendanceNote');if(!status)return;
    if(!status.value){delete state.entries[state.selectedDate];}else{state.entries[state.selectedDate]={status:status.value,missing_hours:status.value==='gelmedi'?0:Math.max(0,Math.min(9,num(missing&&missing.value))),overtime_hours:Math.max(0,Math.min(24,num(overtime&&overtime.value))),note:note?note.value.trim():''};}renderAttendance();
  }

  function renderPayrollSummary(summary){var el=document.getElementById('payrollAttendanceSummary');if(el)el.innerHTML=summaryCards(summary);calculatePayroll();}
  function payrollField(name){return document.querySelector('#payrollForm [name="'+name+'"]');}

  function renderPayroll(){
    if(!state.data)return;var payroll=state.data.payroll||{},basis=state.data.salary_basis||{},base=Number(basis.base_salary||payroll.base_salary||0),daily=base/30,hourly=daily/9;
    var baseEl=document.getElementById('payrollBase'),dailyEl=document.getElementById('payrollDaily'),hourlyEl=document.getElementById('payrollHourly');if(baseEl)baseEl.value=fmt(base);if(dailyEl)dailyEl.value=fmt(daily);if(hourlyEl)hourlyEl.value=fmt(hourly);
    ['overtime_amount','bonus_amount','other_addition_amount','other_deduction_amount','advance_amount','paid_amount','payment_date','note'].forEach(function(name){var field=payrollField(name);if(!field)return;var value=payroll[name];if(name==='payment_date')value=payroll.payment_date||new Date().toISOString().slice(0,10);field.value=value==null?'':value;});
    var account=payrollField('account_id');if(account)account.innerHTML=accountOptions(state.data.accounts,payroll.account_id);
    var print=document.getElementById('payrollPrint');if(print){if(payroll.id){print.hidden=false;print.href='maas-bordro-yazdir.php?id='+payroll.id;}else{print.hidden=true;print.removeAttribute('href');}}
    renderPayrollSummary(localSummary());
  }

  function calculatePayroll(){
    if(!state.data)return;var summary=localSummary(),basis=state.data.salary_basis||{},base=Number(basis.base_salary||(state.data.payroll&&state.data.payroll.base_salary)||0),daily=base/30,hourly=daily/9,absence=Math.round(daily*Number(summary.absent_days||0)*100)/100,missing=Math.round(hourly*Number(summary.missing_hours||0)*100)/100,gross=base+num(payrollField('overtime_amount')&&payrollField('overtime_amount').value)+num(payrollField('bonus_amount')&&payrollField('bonus_amount').value)+num(payrollField('other_addition_amount')&&payrollField('other_addition_amount').value),net=Math.max(0,gross-absence-missing-num(payrollField('other_deduction_amount')&&payrollField('other_deduction_amount').value)-num(payrollField('advance_amount')&&payrollField('advance_amount').value));
    var absenceEl=document.getElementById('payrollAbsence'),missingEl=document.getElementById('payrollMissingHour'),grossEl=document.getElementById('payrollGross'),netEl=document.getElementById('payrollNet');if(absenceEl)absenceEl.value=fmt(absence);if(missingEl)missingEl.value=fmt(missing);if(grossEl)grossEl.textContent=fmt(gross);if(netEl)netEl.textContent=fmt(net);
  }

  function renderAll(){renderSelectors();renderAttendance();renderPayroll();}

  function saveAttendance(){
    message('Puantaj, bordro ve maaş kaydı güncelleniyor…','info');
    request('POST',{action:'save_attendance',period:state.period,employee_id:state.employeeId,entries_json:JSON.stringify(state.entries)}).then(function(data){state.data=data;state.entries=JSON.parse(JSON.stringify(data.entries||{}));renderAll();if(data.warning)message(data.warning,'warning');else message('Puantaj kaydedildi; bordro ve aylık maaş kaydı otomatik güncellendi.','success');}).catch(function(error){message(error.message,'error');});
  }

  function savePayroll(form){
    var params={action:'save_payroll',period:state.period,employee_id:state.employeeId};new FormData(form).forEach(function(value,key){params[key]=value;});message('Bordro güncelleniyor…','info');request('POST',params).then(function(data){state.data=data;state.entries=JSON.parse(JSON.stringify(data.entries||{}));renderAll();message('Bordro ve Maaş Takibi kaydı güncellendi.','success');}).catch(function(error){message(error.message,'error');});
  }

  function bindEvents(){
    document.addEventListener('click',function(event){
      var day=event.target.closest('.attendance-day');if(day){state.selectedDate=day.dataset.date;renderAttendance();return;}
      if(event.target.id==='attendanceReload')loadData(document.getElementById('attendancePeriod').value,document.getElementById('attendanceEmployee').value);
      if(event.target.id==='payrollReload')loadData(document.getElementById('payrollPeriod').value,document.getElementById('payrollEmployee').value);
      if(event.target.id==='saveAttendance')saveAttendance();
      if(event.target.id==='clearSelectedDay'){delete state.entries[state.selectedDate];state.selectedDate='';renderAttendance();}
      if(event.target.id==='clearAttendance'){if(confirm('Seçili ayın puantaj kayıtları temizlensin mi?')){state.entries={};state.selectedDate='';renderAttendance();}}
      if(event.target.id==='fillWorked'){for(var d=1;d<=Number(state.data.days_in_month||30);d++){var date=state.period+'-'+String(d).padStart(2,'0');if(!state.entries[date])state.entries[date]={status:'calisti',missing_hours:0,overtime_hours:0,note:''};}renderAttendance();}
      if(event.target.id==='fillSundays'){var p=state.period.split('-'),y=Number(p[0]),m=Number(p[1]);for(var d2=1;d2<=Number(state.data.days_in_month||30);d2++){if(new Date(y,m-1,d2).getDay()===0){var date2=state.period+'-'+String(d2).padStart(2,'0');state.entries[date2]={status:'hafta_tatili',missing_hours:0,overtime_hours:0,note:''};}}renderAttendance();}
    });
    document.addEventListener('change',function(event){if(['attendanceStatus','attendanceMissing','attendanceOvertime','attendanceNote'].indexOf(event.target.id)>-1)updateSelected();if(event.target.id==='attendanceEmployee'||event.target.id==='payrollEmployee'){state.employeeId=Number(event.target.value||0);var other=document.getElementById(event.target.id==='attendanceEmployee'?'payrollEmployee':'attendanceEmployee');if(other)other.value=event.target.value;}if(event.target.id==='attendancePeriod'||event.target.id==='payrollPeriod'){state.period=event.target.value;var otherPeriod=document.getElementById(event.target.id==='attendancePeriod'?'payrollPeriod':'attendancePeriod');if(otherPeriod)otherPeriod.value=event.target.value;}});
    document.addEventListener('input',function(event){if(event.target.closest('#payrollForm'))calculatePayroll();});
    document.addEventListener('submit',function(event){if(event.target.id==='payrollForm'){event.preventDefault();savePayroll(event.target);}});
  }

  function addStyles(){
    if(document.getElementById('salaryModuleTabStyles'))return;var st=document.createElement('style');st.id='salaryModuleTabStyles';st.textContent=''+
      '.maas-excel-card{border:1px dashed #c49a4f!important;background:#fffdf8!important}.maas-excel-card .salary-card-head{background:#fff7e7!important}.maas-excel-card input[type=file]{padding:12px;background:#fff;border-style:dashed}'+
      '.salary-module-tabs{display:flex;gap:8px;padding:7px;background:#eee5d7;border:1px solid #ded1be;border-radius:16px;overflow:auto}.salary-module-tabs button{border:0;border-radius:11px;padding:11px 18px;background:transparent;color:#526257;font-size:13px;font-weight:900;cursor:pointer;white-space:nowrap}.salary-module-tabs button.is-active{background:#16482e;color:#fff;box-shadow:0 8px 18px rgba(22,72,46,.18)}.salary-tab-panel{display:grid;gap:16px}.salary-tab-panel[hidden]{display:none!important}.salary-module-message{padding:11px 14px;border-radius:13px;font-weight:800}.salary-module-message.info{background:#eef4ff;color:#254f85}.salary-module-message.success{background:#e9f6ed;color:#176133}.salary-module-message.warning{background:#fff7df;color:#8a6114}.salary-module-message.error{background:#fff0ef;color:#a53631}'+
      '.salary-workspace{background:#fff;border:1px solid #e5dccf;border-radius:22px;padding:20px;box-shadow:0 12px 34px rgba(7,27,63,.06)}.salary-workspace-head{display:flex;justify-content:space-between;gap:16px;align-items:center;padding-bottom:16px;border-bottom:1px solid #e5dccf}.salary-workspace-head span{color:#8a6a26;font-size:11px;font-weight:950;letter-spacing:.08em}.salary-workspace-head h2{margin:4px 0;color:#102818}.salary-workspace-head p{margin:0;color:#776b5c}.salary-toolbar{display:flex;gap:8px;flex-wrap:wrap}.salary-toolbar input,.salary-toolbar select{min-height:40px;border:1px solid #d9cdbc;border-radius:999px;padding:8px 12px;background:#fff;font-weight:800}.salary-rule-note{margin:14px 0 0;padding:10px 12px;border-radius:12px;background:#edf5ef;color:#16482e;font-size:12px}.attendance-actions{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}.attendance-actions button,.attendance-actions a{border:1px solid #d9cdbc;border-radius:999px;padding:8px 12px;background:#fff;color:#16482e;font-weight:850;text-decoration:none}.attendance-summary,.payroll-attendance-summary{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:8px;margin-bottom:14px}.attendance-summary div,.payroll-attendance-summary div{padding:10px;border:1px solid #e5dccf;border-radius:13px;background:#fbf7ef}.attendance-summary span,.payroll-attendance-summary span{display:block;color:#776b5c;font-size:10px}.attendance-summary strong,.payroll-attendance-summary strong{display:block;margin-top:4px;color:#16482e;font-size:16px}'+
      '.attendance-layout{display:grid;grid-template-columns:minmax(0,1fr) 290px;gap:16px}.attendance-weekdays,.attendance-calendar{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px}.attendance-weekdays span{text-align:center;color:#776b5c;font-size:11px;font-weight:900;padding:5px}.attendance-calendar{margin-top:4px}.attendance-empty{min-height:72px}.attendance-day{min-height:72px;border:1px solid #ded5c7;border-radius:13px;background:#fff;display:grid;grid-template-columns:1fr auto;align-content:space-between;padding:8px;cursor:pointer;text-align:left}.attendance-day>span{font-weight:900;color:#5d685f}.attendance-day>b{font-size:20px;color:#16482e}.attendance-day>small{grid-column:1/3;color:#8a6a26}.attendance-day.weekend{background:#fff9ee}.attendance-day.selected{outline:3px solid rgba(22,72,46,.2);border-color:#16482e}.attendance-day.status-gelmedi{background:#fff0ef}.attendance-day.status-izinli,.attendance-day.status-raporlu{background:#eef4ff}.attendance-day.status-hafta_tatili,.attendance-day.status-resmi_tatil{background:#f4f1ea}.attendance-editor{border:1px solid #e5dccf;border-radius:17px;padding:16px;background:#fbf7ef;display:grid;gap:10px;align-content:start}.attendance-editor>span{font-size:10px;font-weight:950;color:#8a6a26}.attendance-editor h3{margin:0;color:#102818}.attendance-editor label,.payroll-form label{display:grid;gap:6px;color:#102818;font-size:12px;font-weight:850}.attendance-editor input,.attendance-editor select,.attendance-editor textarea,.payroll-form input,.payroll-form select,.payroll-form textarea{width:100%;min-height:41px;border:1px solid #d9cdbc;border-radius:12px;padding:8px 10px;background:#fff}.salary-save-row{display:flex;justify-content:flex-end;gap:12px;align-items:center;margin-top:16px}.salary-save-row>a{text-decoration:none}'+
      '.payroll-columns{display:grid;grid-template-columns:1fr 1fr;gap:16px}.payroll-box{border:1px solid #e5dccf;border-radius:17px;padding:16px;background:#fbf7ef;display:grid;gap:11px}.payroll-box h3{margin:0;color:#16482e}.two-rates{display:grid;grid-template-columns:1fr 1fr;gap:10px}.payroll-total{display:flex;justify-content:space-between;align-items:center;border-top:1px solid #d9cdbc;padding-top:12px}.payroll-total strong{font-size:20px;color:#16482e}.payroll-payment{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:11px;margin-top:16px}.payroll-payment .wide{grid-column:1/-1}'+
      '@media(max-width:1050px){.attendance-summary,.payroll-attendance-summary{grid-template-columns:repeat(4,1fr)}.attendance-layout{grid-template-columns:1fr}.attendance-editor{grid-template-columns:repeat(2,1fr)}.attendance-editor>span,.attendance-editor h3,.attendance-editor button{grid-column:1/-1}}@media(max-width:720px){.salary-workspace-head{display:block}.salary-toolbar{margin-top:12px}.attendance-summary,.payroll-attendance-summary{grid-template-columns:repeat(2,1fr)}.attendance-day{min-height:62px;padding:6px}.attendance-day>b{font-size:17px}.payroll-columns,.payroll-payment,.two-rates{grid-template-columns:1fr}.attendance-editor{grid-template-columns:1fr}.attendance-editor>*{grid-column:auto!important}.salary-save-row{align-items:stretch;flex-direction:column}}';document.head.appendChild(st);
  }

  function init(){if(!/maaslar\.php/i.test(location.pathname))return;applySalaryOnlyShell();var grid=document.querySelector('.salary-grid');if(!grid)return;state.period=currentPeriod();addStyles();createTabs(grid,createExcelCard(grid));bindEvents();}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
