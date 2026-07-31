(function(){
  if (!/uretim-takibi\.php/i.test(location.pathname)) return;

  var GROUPS = ['A','B','C','D','E'];

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
    return input && input.value ? input.value : new Date().toISOString().slice(0,10);
  }

  function numberValue(value){
    var text = String(value || '').trim().replace(/\s/g,'');
    if (!text) return 0;
    if (text.indexOf(',') !== -1) text = text.replace(/\./g,'').replace(',','.');
    var n = parseFloat(text);
    return Number.isFinite(n) ? n : 0;
  }

  function format(value){
    try { return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(value); }
    catch(e){ return Number(value || 0).toFixed(2).replace('.',','); }
  }

  function rowHtml(group){
    return '<tr data-quick-row data-group="'+group+'">' +
      '<td class="production-fixed-group">'+group+'</td>' +
      '<td><input data-q-dozen name="groups['+group+'][produced_dozen]" inputmode="decimal" placeholder="0,00"></td>' +
      '<td><input data-q-defective name="groups['+group+'][defective_qty]" inputmode="numeric" placeholder="0"></td>' +
    '</tr>';
  }

  function recalc(form){
    var totalDz = 0;
    var totalDef = 0;
    form.querySelectorAll('[data-quick-row]').forEach(function(row){
      totalDz += numberValue((row.querySelector('[data-q-dozen]') || {}).value);
      totalDef += parseInt(String((row.querySelector('[data-q-defective]') || {}).value || '0').replace(/\D+/g,''),10) || 0;
    });
    var dzOut = form.querySelector('[data-q-total-dz]');
    var defOut = form.querySelector('[data-q-total-def]');
    if (dzOut) dzOut.textContent = format(totalDz) + ' DZ';
    if (defOut) defOut.textContent = totalDef + ' adet';
  }

  function loadSaved(form){
    fetch('uretim-hizli-kaydet.php?date=' + encodeURIComponent(selectedDate()), {
      credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'}
    }).then(function(response){
      if (!response.ok) throw new Error('Kayıtlar alınamadı.');
      return response.json();
    }).then(function(data){
      var values = data && data.groups ? data.groups : {};
      GROUPS.forEach(function(group){
        var row = form.querySelector('[data-group="'+group+'"]');
        if (!row) return;
        var item = values[group] || {};
        row.querySelector('[data-q-dozen]').value = Number(item.produced_dozen || 0) > 0 ? String(item.produced_dozen).replace('.',',') : '';
        row.querySelector('[data-q-defective]').value = Number(item.defective_qty || 0) > 0 ? String(item.defective_qty) : '';
      });
      recalc(form);
    }).catch(function(){});
  }

  function build(){
    if (document.querySelector('[data-quick-production-entry]')) return;
    var anchor = document.querySelector('.production-summary') || document.querySelector('.production-head');
    if (!anchor || !anchor.parentNode) return;

    var section = document.createElement('section');
    section.className = 'production-quick-entry';
    section.setAttribute('data-quick-production-entry','1');
    section.innerHTML = '' +
      '<header class="production-quick-head"><div><h2>Günlük üretim girişi</h2><p>A–E grupları sabittir. Yalnız üretim düzinesi ve defolu adedini gir.</p></div></header>' +
      '<form method="post" action="uretim-hizli-kaydet.php" data-quick-form>' +
        '<input type="hidden" name="csrf_token" value="'+esc(csrfToken())+'">' +
        '<input type="hidden" name="production_date" value="'+esc(selectedDate())+'">' +
        '<div class="production-quick-table-wrap"><table class="production-quick-table">' +
          '<thead><tr><th>Grup</th><th>Üretim (DZ)</th><th>Defolu (Adet)</th></tr></thead>' +
          '<tbody>'+GROUPS.map(rowHtml).join('')+'</tbody>' +
        '</table></div>' +
        '<div class="production-quick-footer"><div class="production-quick-totals"><span data-q-total-dz>0,00 DZ</span><span data-q-total-def>0 adet</span></div><button type="submit">Günlük üretimi kaydet</button></div>' +
      '</form>';

    anchor.insertAdjacentElement('afterend', section);

    var style = document.createElement('style');
    style.textContent = '.production-quick-entry{margin:0 0 18px;border:2px solid #b9d9c3;border-radius:18px;background:#fff;overflow:hidden}.production-quick-head{padding:16px 18px;background:#eef7f0}.production-quick-head h2{margin:0;color:#173f29;font-size:20px}.production-quick-head p{margin:4px 0 0;color:#607067;font-size:13px}.production-quick-table{width:100%;border-collapse:collapse}.production-quick-table th,.production-quick-table td{padding:12px 14px;border-bottom:1px solid #edf1ee;text-align:left}.production-quick-table th{font-size:11px;text-transform:uppercase;color:#68766d;background:#fbfcfb}.production-quick-table input{width:100%;height:44px;border:1px solid #d8e1da;border-radius:10px;padding:8px 10px;font-size:16px}.production-fixed-group{width:110px;font-size:20px;font-weight:900;color:#173f29}.production-quick-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;background:#f8fbf9}.production-quick-footer>button{border:0;border-radius:11px;background:#173f29;color:#fff;padding:12px 18px;font-weight:900;cursor:pointer}.production-quick-totals{display:flex;gap:8px;flex-wrap:wrap}.production-quick-totals span{display:inline-flex;padding:6px 9px;border-radius:999px;background:#eaf3ec;color:#214b31;font-size:12px;font-weight:900}@media(max-width:760px){.production-quick-entry{border-radius:14px}.production-quick-table th,.production-quick-table td{padding:10px}.production-fixed-group{width:72px}.production-quick-footer{align-items:stretch;flex-direction:column}.production-quick-footer>button{width:100%;min-height:48px}}';
    document.head.appendChild(style);

    var form = section.querySelector('[data-quick-form]');
    form.addEventListener('input', function(){ recalc(form); });
    loadSaved(form);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build);
  else build();
})();
