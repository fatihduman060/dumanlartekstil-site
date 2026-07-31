(function(){
  if (!/uretim-takibi\.php/i.test(location.pathname)) return;

  var groups = ['A','B','C','D','E'];
  var months = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];

  function esc(value){
    return String(value == null ? '' : value)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
  }

  function csrfToken(){
    var input = document.querySelector('input[name="csrf_token"]');
    return input ? input.value : '';
  }

  function selectedDate(){
    var input = document.querySelector('input[name="date"]');
    if (input && input.value) return input.value;
    var query = new URLSearchParams(location.search).get('date');
    return query || new Date().toISOString().slice(0,10);
  }

  function numberValue(value){
    var text = String(value || '').trim().replace(/\s/g,'');
    if (!text) return 0;
    if (text.indexOf(',') !== -1) text = text.replace(/\./g,'').replace(',','.');
    var number = parseFloat(text);
    return Number.isFinite(number) ? number : 0;
  }

  function formatDozen(value){
    try { return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(value || 0)); }
    catch(e){ return Number(value || 0).toFixed(2).replace('.',','); }
  }

  function shiftRows(shift){
    return groups.map(function(group){
      return '<tr data-shift-row="'+shift+'-'+group+'">' +
        '<th scope="row"><span class="fixed-group">'+group+'</span></th>' +
        '<td><input data-dozen name="shift_rows['+shift+']['+group+'][produced_dozen]" inputmode="decimal" placeholder="0,00" autocomplete="off"></td>' +
        '<td><input data-defective name="shift_rows['+shift+']['+group+'][defective_qty]" inputmode="numeric" placeholder="0" autocomplete="off"></td>' +
      '</tr>';
    }).join('');
  }

  function shiftCard(shift, title){
    return '<section class="shift-card" data-shift="'+shift+'">' +
      '<header><h3>'+title+'</h3><div><strong data-shift-dozen>0,00 DZ</strong><span data-shift-defective>0 defolu</span></div></header>' +
      '<table class="shift-table"><thead><tr><th>Grup</th><th>Üretim (DZ)</th><th>Defolu (Adet)</th></tr></thead><tbody>'+shiftRows(shift)+'</tbody></table>' +
    '</section>';
  }

  function recalc(form){
    var allDozen = 0;
    var allDefective = 0;
    ['gunduz','gece'].forEach(function(shift){
      var card = form.querySelector('[data-shift="'+shift+'"]');
      var shiftDozen = 0;
      var shiftDefective = 0;
      if (!card) return;
      card.querySelectorAll('tbody tr').forEach(function(row){
        shiftDozen += numberValue((row.querySelector('[data-dozen]') || {}).value);
        shiftDefective += parseInt(String((row.querySelector('[data-defective]') || {}).value || '0').replace(/\D+/g,''),10) || 0;
      });
      allDozen += shiftDozen;
      allDefective += shiftDefective;
      var dzOut = card.querySelector('[data-shift-dozen]');
      var defOut = card.querySelector('[data-shift-defective]');
      if (dzOut) dzOut.textContent = formatDozen(shiftDozen) + ' DZ';
      if (defOut) defOut.textContent = shiftDefective + ' defolu';
    });
    var totalDz = form.querySelector('[data-day-total-dz]');
    var totalDef = form.querySelector('[data-day-total-def]');
    if (totalDz) totalDz.textContent = formatDozen(allDozen) + ' DZ';
    if (totalDef) totalDef.textContent = allDefective + ' adet';
  }

  function fillDay(section, day){
    ['gunduz','gece'].forEach(function(shift){
      groups.forEach(function(group){
        var item = day && day[shift] && day[shift][group] ? day[shift][group] : null;
        var row = section.querySelector('[data-shift-row="'+shift+'-'+group+'"]');
        if (!row) return;
        var dz = row.querySelector('[data-dozen]');
        var def = row.querySelector('[data-defective]');
        if (dz) dz.value = item && Number(item.produced_dozen) !== 0 ? String(item.produced_dozen).replace('.',',') : '';
        if (def) def.value = item && Number(item.defective_qty) !== 0 ? String(item.defective_qty) : '';
      });
    });
    recalc(section.querySelector('[data-shift-form]'));
  }

  function renderReports(section, data){
    var monthNo = Number(data.month || 1);
    var monthData = data.months && data.months[monthNo] ? data.months[monthNo] : {produced_dozen:0,defective_qty:0};
    var selectedTitle = section.querySelector('[data-selected-month-title]');
    var selectedDz = section.querySelector('[data-selected-month-dz]');
    var selectedDef = section.querySelector('[data-selected-month-def]');
    if (selectedTitle) selectedTitle.textContent = months[monthNo-1] + ' ' + data.year + ' üretimi';
    if (selectedDz) selectedDz.textContent = formatDozen(monthData.produced_dozen) + ' DZ';
    if (selectedDef) selectedDef.textContent = Number(monthData.defective_qty || 0) + ' defolu';

    var body = section.querySelector('[data-month-body]');
    if (body) {
      body.innerHTML = months.map(function(name, index){
        var no = index + 1;
        var item = data.months && data.months[no] ? data.months[no] : {produced_dozen:0,defective_qty:0};
        return '<tr'+(no === monthNo ? ' class="selected-month"' : '')+'><th>'+name+'</th><td>'+formatDozen(item.produced_dozen)+' DZ</td><td>'+Number(item.defective_qty || 0)+' adet</td></tr>';
      }).join('');
    }

    var yearTitle = section.querySelector('[data-year-title]');
    var yearDz = section.querySelector('[data-year-dz]');
    var yearDef = section.querySelector('[data-year-def]');
    if (yearTitle) yearTitle.textContent = data.year + ' yılı toplam üretimi';
    if (yearDz) yearDz.textContent = formatDozen(data.year_total ? data.year_total.produced_dozen : 0) + ' DZ';
    if (yearDef) yearDef.textContent = Number(data.year_total ? data.year_total.defective_qty : 0) + ' defolu';
  }

  function loadData(section){
    fetch('uretim-ozet.php?date=' + encodeURIComponent(selectedDate()), {credentials:'same-origin'})
      .then(function(response){ return response.json(); })
      .then(function(data){
        if (!data || !data.ok) return;
        fillDay(section, data.day || {});
        renderReports(section, data);
      })
      .catch(function(){});
  }

  function build(){
    if (document.querySelector('[data-shift-production-entry]')) return;

    var oldForm = document.querySelector('form[data-production-form]');
    var machineAdd = document.querySelector('.machine-add');
    var oldSummary = document.querySelector('.production-summary');
    if (oldForm) oldForm.style.display = 'none';
    if (machineAdd) machineAdd.style.display = 'none';
    if (oldSummary) oldSummary.style.display = 'none';

    var head = document.querySelector('.production-head');
    if (!head || !head.parentNode) return;

    var section = document.createElement('div');
    section.setAttribute('data-shift-production-entry','1');
    section.innerHTML = '' +
      '<section class="shift-entry-box">' +
        '<header class="shift-main-head"><div><h2>Günlük üretim girişi</h2><p>Gündüz ve gece vardiyasını ayrı ayrı kaydet.</p></div><div class="day-total"><span>Gün toplamı</span><strong data-day-total-dz>0,00 DZ</strong><small data-day-total-def>0 adet</small></div></header>' +
        '<form method="post" action="uretim-hizli-kaydet.php" data-shift-form>' +
          '<input type="hidden" name="csrf_token" value="'+esc(csrfToken())+'">' +
          '<input type="hidden" name="production_date" value="'+esc(selectedDate())+'">' +
          '<div class="shift-grid">'+shiftCard('gunduz','Gündüz Vardiyası')+shiftCard('gece','Gece Vardiyası')+'</div>' +
          '<div class="shift-save"><button type="submit">Günlük üretimi kaydet</button></div>' +
        '</form>' +
      '</section>' +
      '<section class="production-report">' +
        '<div class="selected-month-card"><span data-selected-month-title>Seçili ay üretimi</span><strong data-selected-month-dz>0,00 DZ</strong><small data-selected-month-def>0 defolu</small></div>' +
        '<div class="month-table-card"><h2>Ay ay üretim</h2><div class="month-table-wrap"><table class="month-table"><thead><tr><th>Ay</th><th>Üretim</th><th>Defolu</th></tr></thead><tbody data-month-body></tbody></table></div></div>' +
        '<div class="year-total-card"><span data-year-title>Yıl toplamı</span><strong data-year-dz>0,00 DZ</strong><small data-year-def>0 defolu</small></div>' +
      '</section>';

    head.insertAdjacentElement('afterend', section);

    var style = document.createElement('style');
    style.textContent = '.shift-entry-box{margin:0 0 20px;border:1px solid #d8e4db;border-radius:18px;background:#fff;overflow:hidden}.shift-main-head{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:17px 18px;background:#eef7f0}.shift-main-head h2{margin:0;color:#173f29}.shift-main-head p{margin:4px 0 0;color:#657168}.day-total{text-align:right}.day-total span,.day-total small{display:block;color:#627067;font-size:12px}.day-total strong{display:block;color:#173f29;font-size:23px}.shift-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:16px}.shift-card{border:1px solid #dde6df;border-radius:15px;overflow:hidden}.shift-card header{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:13px 14px;background:#f7faf8}.shift-card h3{margin:0;color:#173f29}.shift-card header div{text-align:right}.shift-card header strong,.shift-card header span{display:block}.shift-card header span{font-size:12px;color:#6a766e}.shift-table{width:100%;border-collapse:collapse}.shift-table th,.shift-table td{padding:10px;border-bottom:1px solid #edf1ee;text-align:left}.shift-table thead th{font-size:10px;text-transform:uppercase;color:#68766d;background:#fbfcfb}.shift-table tbody th{width:80px}.fixed-group{display:inline-flex;width:36px;height:36px;border-radius:10px;align-items:center;justify-content:center;background:#e7f2ea;color:#173f29;font-weight:950}.shift-table input{width:100%;height:42px;border:1px solid #d7e1d9;border-radius:9px;padding:8px 10px;font-size:16px}.shift-save{display:flex;justify-content:flex-end;padding:0 16px 16px}.shift-save button{border:0;border-radius:11px;background:#173f29;color:#fff;padding:12px 20px;font-weight:900}.production-report{display:grid;grid-template-columns:minmax(220px,.7fr) minmax(420px,1.6fr) minmax(220px,.7fr);gap:16px;margin-bottom:24px}.selected-month-card,.year-total-card,.month-table-card{border:1px solid #dce5de;border-radius:17px;background:#fff;padding:17px}.selected-month-card,.year-total-card{display:flex;flex-direction:column;justify-content:center}.selected-month-card span,.year-total-card span{font-weight:850;color:#647168}.selected-month-card strong,.year-total-card strong{font-size:27px;color:#173f29;margin:7px 0}.selected-month-card small,.year-total-card small{color:#6b776f}.month-table-card h2{margin:0 0 12px;color:#173f29}.month-table-wrap{overflow:auto}.month-table{width:100%;border-collapse:collapse}.month-table th,.month-table td{padding:9px 10px;border-bottom:1px solid #edf1ee;text-align:left}.month-table thead th{font-size:10px;text-transform:uppercase;color:#68766d}.month-table .selected-month{background:#eef7f0}.month-table .selected-month th{color:#173f29}@media(max-width:900px){.shift-grid{grid-template-columns:1fr}.production-report{grid-template-columns:1fr}.day-total{text-align:left}.shift-main-head{align-items:flex-start;flex-direction:column}}@media(max-width:600px){.shift-grid{padding:10px}.shift-main-head{padding:14px}.shift-table th,.shift-table td{padding:8px}.shift-save{padding:0 10px 12px}.shift-save button{width:100%;min-height:48px}.production-report{gap:10px}}';
    document.head.appendChild(style);

    var form = section.querySelector('[data-shift-form]');
    form.addEventListener('input', function(){ recalc(form); });
    loadData(section);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build);
  else build();
})();
