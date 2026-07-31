(function(){
  if (!/uretim-takibi\.php/i.test(location.pathname)) return;

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
    return new Date().toISOString().slice(0,10);
  }

  function rowHtml(index){
    return '<tr data-quick-row>' +
      '<td><select name="quick_rows['+index+'][group_code]">' +
        '<option>A</option><option>B</option><option>C</option><option>D</option><option>E</option>' +
      '</select></td>' +
      '<td><input name="quick_rows['+index+'][machine_no]" placeholder="Örn: 12" autocomplete="off"></td>' +
      '<td><input name="quick_rows['+index+'][article]" placeholder="Örn: 7042" autocomplete="off"></td>' +
      '<td><input data-q-dozen name="quick_rows['+index+'][produced_dozen]" inputmode="decimal" placeholder="0,00"></td>' +
      '<td><input data-q-defective name="quick_rows['+index+'][defective_qty]" inputmode="numeric" placeholder="0"></td>' +
      '<td data-q-net>0,00 DZ</td>' +
      '<td><input name="quick_rows['+index+'][note]" placeholder="İsteğe bağlı"></td>' +
      '<td><button type="button" data-remove-row aria-label="Satırı sil">×</button></td>' +
    '</tr>';
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

  function recalc(form){
    var totalDz = 0;
    var totalDef = 0;
    var filled = 0;
    form.querySelectorAll('[data-quick-row]').forEach(function(row){
      var dz = numberValue((row.querySelector('[data-q-dozen]') || {}).value);
      var def = parseInt(String((row.querySelector('[data-q-defective]') || {}).value || '0').replace(/\D+/g,''),10) || 0;
      var machine = (row.querySelector('input[name*="[machine_no]"]') || {}).value || '';
      if (machine.trim() && (dz > 0 || def > 0)) filled++;
      totalDz += dz;
      totalDef += def;
      var net = Math.max(0, dz - (def / 12));
      var out = row.querySelector('[data-q-net]');
      if (out) out.textContent = format(net) + ' DZ';
    });
    var dzOut = form.querySelector('[data-q-total-dz]');
    var defOut = form.querySelector('[data-q-total-def]');
    var countOut = form.querySelector('[data-q-count]');
    if (dzOut) dzOut.textContent = format(totalDz) + ' DZ';
    if (defOut) defOut.textContent = totalDef + ' adet';
    if (countOut) countOut.textContent = filled + ' makine';
  }

  function build(){
    if (document.querySelector('[data-quick-production-entry]')) return;
    var head = document.querySelector('.production-head');
    var summary = document.querySelector('.production-summary');
    var anchor = summary || head;
    if (!anchor || !anchor.parentNode) return;

    var section = document.createElement('section');
    section.className = 'production-quick-entry';
    section.setAttribute('data-quick-production-entry','1');
    section.innerHTML = '' +
      '<header class="production-quick-head"><div><h2>Günlük üretim girişi</h2><p>Makine tanımlı olmasa bile buradan doğrudan giriş yap. Kayıtta makine otomatik oluşturulur.</p></div><button type="button" data-add-row>+ Satır ekle</button></header>' +
      '<form method="post" action="uretim-hizli-kaydet.php" data-quick-form>' +
        '<input type="hidden" name="csrf_token" value="'+esc(csrfToken())+'">' +
        '<input type="hidden" name="production_date" value="'+esc(selectedDate())+'">' +
        '<div class="production-quick-table-wrap"><table class="production-quick-table" data-mobile-table="scroll">' +
          '<thead><tr><th>Grup</th><th>Makine No</th><th>Artikel / Ürün</th><th>Üretim (DZ)</th><th>Defolu (Adet)</th><th>Net üretim</th><th>Açıklama</th><th></th></tr></thead>' +
          '<tbody data-quick-body></tbody>' +
        '</table></div>' +
        '<div class="production-quick-footer"><div class="production-quick-totals"><span data-q-total-dz>0,00 DZ</span><span data-q-total-def>0 adet</span><span data-q-count>0 makine</span></div><button type="submit">Günlük üretimi kaydet</button></div>' +
      '</form>';

    anchor.insertAdjacentElement('afterend', section);

    var style = document.createElement('style');
    style.textContent = '.production-quick-entry{margin:0 0 18px;border:2px solid #b9d9c3;border-radius:18px;background:#fff;overflow:hidden}.production-quick-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 18px;background:#eef7f0}.production-quick-head h2{margin:0;color:#173f29;font-size:20px}.production-quick-head p{margin:4px 0 0;color:#607067;font-size:13px}.production-quick-head button{border:0;border-radius:10px;background:#dcecdf;color:#17452d;padding:9px 13px;font-weight:900;cursor:pointer}.production-quick-table-wrap{overflow:auto}.production-quick-table{width:100%;min-width:980px;border-collapse:collapse}.production-quick-table th,.production-quick-table td{padding:9px;border-bottom:1px solid #edf1ee;text-align:left}.production-quick-table th{font-size:10px;text-transform:uppercase;color:#68766d;background:#fbfcfb}.production-quick-table input,.production-quick-table select{width:100%;height:40px;border:1px solid #d8e1da;border-radius:9px;padding:7px 9px}.production-quick-table td[data-q-net]{font-weight:900;color:#176536;white-space:nowrap}.production-quick-table [data-remove-row]{width:34px;height:34px;border:0;border-radius:9px;background:#fff0ed;color:#b64242;font-size:18px;font-weight:900;cursor:pointer}.production-quick-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;background:#f8fbf9}.production-quick-footer>button{border:0;border-radius:11px;background:#173f29;color:#fff;padding:11px 18px;font-weight:900;cursor:pointer}.production-quick-totals{display:flex;gap:8px;flex-wrap:wrap}.production-quick-totals span{display:inline-flex;padding:6px 9px;border-radius:999px;background:#eaf3ec;color:#214b31;font-size:12px;font-weight:900}@media(max-width:760px){.production-quick-head{align-items:flex-start;flex-direction:column}.production-quick-head button{width:100%}.production-quick-footer{align-items:stretch;flex-direction:column}.production-quick-footer>button{width:100%;min-height:48px}.production-quick-entry{border-radius:14px}}';
    document.head.appendChild(style);

    var form = section.querySelector('[data-quick-form]');
    var body = section.querySelector('[data-quick-body]');
    var nextIndex = 0;
    function addRow(){
      body.insertAdjacentHTML('beforeend', rowHtml(nextIndex++));
      recalc(form);
    }
    for (var i=0;i<10;i++) addRow();

    section.querySelector('[data-add-row]').addEventListener('click', addRow);
    form.addEventListener('input', function(){ recalc(form); });
    form.addEventListener('click', function(event){
      var remove = event.target.closest('[data-remove-row]');
      if (!remove) return;
      var row = remove.closest('[data-quick-row]');
      if (row) row.remove();
      if (!body.querySelector('[data-quick-row]')) addRow();
      recalc(form);
    });
    form.addEventListener('submit', function(event){
      var hasMachine = false;
      body.querySelectorAll('input[name*="[machine_no]"]').forEach(function(input){ if (input.value.trim()) hasMachine = true; });
      if (!hasMachine) {
        event.preventDefault();
        alert('En az bir satırda makine numarası girmelisin.');
      }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build);
  else build();
})();
