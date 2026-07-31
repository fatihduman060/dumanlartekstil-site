(function(){
  if (!/uretim-takibi\.php/i.test(location.pathname)) return;

  var groups = ['A','B','C','D','E'];
  var months = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];

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
    var section = document.querySelector('[data-shift-production-entry]');
    if (!section || section.getAttribute('data-ready') === '1') return;
    var form = section.querySelector('[data-shift-form]');
    if (!form) return;
    section.setAttribute('data-ready','1');
    form.addEventListener('input', function(){ recalc(form); });
    loadData(section);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build);
  else build();
})();
