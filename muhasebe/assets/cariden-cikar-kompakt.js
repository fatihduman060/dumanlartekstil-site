(function () {
  var path = (location.pathname || '').split('/').pop();
  if (path !== 'cari-detay.php') return;

  var section = document.getElementById('hareketler');
  if (!section) return;

  function important(el, name, value) {
    if (el) el.style.setProperty(name, value, 'important');
  }

  function forceCompact(form) {
    if (!form) return;

    var wrap = form.parentElement && form.parentElement.classList.contains('cariden-cikar-wrap')
      ? form.parentElement
      : null;
    var note = null;

    if (wrap) {
      note = wrap.querySelector('.cariden-cikar-kaynak');
    } else {
      note = form.nextElementSibling;
      if (!note || !note.classList.contains('cariden-cikar-kaynak')) note = null;

      wrap = document.createElement('span');
      wrap.className = 'cariden-cikar-wrap';
      form.parentNode.insertBefore(wrap, form);
      wrap.appendChild(form);
      if (note) wrap.appendChild(note);
    }

    var actions = wrap.closest('.row-actions');
    if (actions) {
      important(actions, 'align-items', 'flex-start');
      important(actions, 'gap', '5px');
    }

    important(wrap, 'display', 'inline-flex');
    important(wrap, 'flex-direction', 'column');
    important(wrap, 'align-items', 'flex-start');
    important(wrap, 'gap', '1px');
    important(wrap, 'width', '102px');
    important(wrap, 'max-width', '102px');
    important(wrap, 'vertical-align', 'top');
    important(wrap, 'margin', '0');
    important(wrap, 'padding', '0');

    important(form, 'display', 'block');
    important(form, 'margin', '0');
    important(form, 'padding', '0');
    important(form, 'line-height', '1');
    important(form, 'width', 'auto');

    var button = form.querySelector('.cariden-cikar-btn');
    if (button) {
      important(button, 'min-height', '0');
      important(button, 'height', '18px');
      important(button, 'width', 'auto');
      important(button, 'padding', '1px 5px');
      important(button, 'margin', '0');
      important(button, 'border-radius', '5px');
      important(button, 'font-size', '8px');
      important(button, 'line-height', '1');
      important(button, 'font-weight', '800');
      important(button, 'letter-spacing', '0');
      important(button, 'box-shadow', 'none');
      important(button, 'white-space', 'nowrap');
    }

    note = note || wrap.querySelector('.cariden-cikar-kaynak');
    if (note) {
      important(note, 'display', 'block');
      important(note, 'position', 'static');
      important(note, 'width', '100px');
      important(note, 'max-width', '100px');
      important(note, 'margin', '1px 0 0 0');
      important(note, 'padding', '0');
      important(note, 'font-size', '7px');
      important(note, 'line-height', '1.08');
      important(note, 'font-weight', '600');
      important(note, 'color', '#8b7774');
      important(note, 'white-space', 'normal');
      important(note, 'overflow-wrap', 'anywhere');
      important(note, 'text-align', 'left');
    }
  }

  function compactAll() {
    section.querySelectorAll('.cariden-cikar-form').forEach(forceCompact);
  }

  compactAll();
  [0, 50, 150, 400, 900, 1600].forEach(function (delay) {
    window.setTimeout(compactAll, delay);
  });

  var observer = new MutationObserver(function () { compactAll(); });
  observer.observe(section, { childList: true, subtree: true });
})();

(function () {
  var path = (location.pathname || '').split('/').pop();
  if (path !== 'hareketler.php') return;

  var form = document.querySelector('form input[name="action"][value="save"]');
  form = form ? form.closest('form') : null;
  if (!form || form.querySelector('input[name="id"][value]:not([value="0"])')) return;

  var typeSelect = form.querySelector('[name="movement_type"]');
  var categorySelect = form.querySelector('[name="category_id"]');
  var documentSelect = form.querySelector('[name="document_type"]');
  var cariSelect = form.querySelector('[name="cari_id"]');
  var csrf = form.querySelector('[name="csrf_token"]');
  if (!typeSelect || !categorySelect || !documentSelect || !cariSelect || !csrf) return;

  function addOption(select, value, label, afterText) {
    if ([].some.call(select.options, function (o) { return o.value === value || o.textContent.trim() === label; })) return;
    var option = document.createElement('option');
    option.value = value;
    option.textContent = label;
    var after = [].find.call(select.options, function (o) { return o.textContent.trim() === afterText; });
    if (after && after.nextSibling) select.insertBefore(option, after.nextSibling);
    else select.appendChild(option);
  }

  addOption(categorySelect, '__senet__', 'Senet', 'Çek');
  addOption(documentSelect, 'senet_gorseli', 'Senet görseli', 'Çek görseli');

  var style = document.createElement('style');
  style.textContent = [
    '.toplu-evrak-btn{display:none;margin-top:8px;border:1px solid #c89a2f;background:#fff8e7;color:#745000;border-radius:10px;padding:9px 13px;font-weight:850;cursor:pointer}',
    '.toplu-evrak-backdrop{display:none;position:fixed;inset:0;z-index:9998;background:rgba(20,27,24,.48);padding:28px;overflow:auto}',
    '.toplu-evrak-backdrop.open{display:flex;align-items:flex-start;justify-content:center}',
    '.toplu-evrak-panel{width:min(1180px,100%);margin:auto;background:#fff;border-radius:20px;box-shadow:0 24px 70px rgba(0,0,0,.24);overflow:hidden}',
    '.toplu-evrak-head{display:flex;justify-content:space-between;gap:20px;align-items:center;padding:20px 24px;border-bottom:1px solid #eceee9;background:#fbfcf9}',
    '.toplu-evrak-head h3{margin:0;font-size:20px}.toplu-evrak-head p{margin:4px 0 0;color:#6b746e;font-size:13px}',
    '.toplu-evrak-count{display:flex;align-items:center;gap:8px;font-weight:800}.toplu-evrak-count input{width:76px}',
    '.toplu-evrak-body{padding:18px 24px 22px}',
    '.toplu-evrak-table{display:grid;gap:9px}',
    '.toplu-evrak-columns,.toplu-evrak-row{display:grid;grid-template-columns:46px minmax(220px,2fr) minmax(150px,1fr) minmax(130px,1fr) minmax(160px,1fr);gap:10px;align-items:center}',
    '.toplu-evrak-columns{padding:0 10px;color:#66706a;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}',
    '.toplu-evrak-row{padding:11px 10px;border:1px solid #e5e9e4;border-radius:13px;background:#fff}',
    '.toplu-evrak-row:focus-within{border-color:#b99237;box-shadow:0 0 0 3px rgba(185,146,55,.12)}',
    '.toplu-evrak-row strong{display:flex;width:28px;height:28px;align-items:center;justify-content:center;border-radius:50%;background:#eff3ee;color:#3f5548;font-size:12px}',
    '.toplu-evrak-field{display:flex;flex-direction:column;gap:4px}.toplu-evrak-field label{font-size:10px;font-weight:850;color:#66706a;display:none}',
    '.toplu-evrak-field input{width:100%;min-width:0;height:42px;border:1px solid #dfe4df;border-radius:9px;padding:9px 10px;font-size:14px;background:#fff}',
    '.toplu-evrak-field input:invalid:not(:placeholder-shown){border-color:#c84f45;background:#fff7f6}',
    '.toplu-evrak-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:18px;padding-top:16px;border-top:1px solid #eceee9}',
    '.toplu-evrak-summary{margin-top:12px;padding:10px 12px;border-radius:10px;background:#f4f7f3;color:#415148;font-size:13px;font-weight:750}',
    '@media(max-width:820px){.toplu-evrak-backdrop{padding:10px}.toplu-evrak-columns{display:none}.toplu-evrak-row{grid-template-columns:36px 1fr}.toplu-evrak-row strong{grid-row:1/5}.toplu-evrak-field{grid-column:2}.toplu-evrak-field label{display:block}.toplu-evrak-head{align-items:flex-start;flex-direction:column}}'
  ].join('');
  document.head.appendChild(style);

  var button = document.createElement('button');
  button.type = 'button';
  button.className = 'toplu-evrak-btn';
  button.textContent = 'Toplu çek / senet girişi';

  var backdrop = document.createElement('div');
  backdrop.className = 'toplu-evrak-backdrop';
  backdrop.innerHTML = '<section class="toplu-evrak-panel" role="dialog" aria-modal="true"><div class="toplu-evrak-head"><div><h3 data-title>Toplu senet girişi</h3><p>Her evrakı ayrı satırda açıkça kontrol ederek kaydet.</p></div><label class="toplu-evrak-count">Adet <input type="number" min="1" max="24" value="3" data-count></label></div><div class="toplu-evrak-body"><form method="post" action="hareket-toplu-cek-senet.php" data-bulk-form><div data-hidden></div><div class="toplu-evrak-table"><div class="toplu-evrak-columns"><span>No</span><span>Kişi / keşideci adı</span><span>Tutar</span><span>İl</span><span>Vade tarihi</span></div><div data-rows></div></div><div class="toplu-evrak-summary" data-summary></div><div class="toplu-evrak-actions"><button class="btn btn-secondary" type="button" data-close>Vazgeç</button><button class="btn btn-primary" type="submit">Hepsini kaydet</button></div></form></div></section>';
  document.body.appendChild(backdrop);

  var anchor = documentSelect.closest('label') || documentSelect;
  anchor.parentNode.insertBefore(button, anchor.nextSibling);

  var countInput = backdrop.querySelector('[data-count]');
  var rows = backdrop.querySelector('[data-rows]');
  var bulkForm = backdrop.querySelector('[data-bulk-form]');
  var hidden = backdrop.querySelector('[data-hidden]');
  var title = backdrop.querySelector('[data-title]');
  var summary = backdrop.querySelector('[data-summary]');

  function selectedInstrument() {
    var categoryText = categorySelect.options[categorySelect.selectedIndex] ? categorySelect.options[categorySelect.selectedIndex].textContent.trim() : '';
    return categoryText === 'Senet' || documentSelect.value === 'senet_gorseli' ? 'senet' : 'cek';
  }

  function eligible() {
    var categoryText = categorySelect.options[categorySelect.selectedIndex] ? categorySelect.options[categorySelect.selectedIndex].textContent.trim() : '';
    return (typeSelect.value === 'tahsilat' || typeSelect.value === 'odeme') && (categoryText === 'Çek' || categoryText === 'Senet' || documentSelect.value === 'cek_gorseli' || documentSelect.value === 'senet_gorseli');
  }

  function updateSummary() {
    var total = 0;
    rows.querySelectorAll('[name="item_amount[]"]').forEach(function (input) {
      var text = String(input.value || '').trim().replace(/\s/g, '');
      if (text.indexOf(',') !== -1) text = text.replace(/\./g, '').replace(',', '.');
      var value = parseFloat(text);
      if (Number.isFinite(value)) total += value;
    });
    summary.textContent = countInput.value + ' adet ' + (selectedInstrument() === 'senet' ? 'senet' : 'çek') + ' · Girilen toplam: ' + total.toLocaleString('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' TL';
  }

  function renderRows() {
    var old = [];
    rows.querySelectorAll('.toplu-evrak-row').forEach(function (row) {
      old.push({
        person:(row.querySelector('[name="item_person[]"]') || {}).value || '',
        amount:(row.querySelector('[name="item_amount[]"]') || {}).value || '',
        city:(row.querySelector('[name="item_city[]"]') || {}).value || '',
        due:(row.querySelector('[name="item_due_date[]"]') || {}).value || ''
      });
    });

    var count = Math.max(1, Math.min(24, parseInt(countInput.value || '1', 10)));
    countInput.value = count;
    rows.innerHTML = '';
    for (var i = 0; i < count; i++) {
      var saved = old[i] || {};
      var row = document.createElement('div');
      row.className = 'toplu-evrak-row';
      row.innerHTML = '<strong>' + (i + 1) + '</strong>' +
        '<span class="toplu-evrak-field"><label>Kişi / keşideci adı</label><input name="item_person[]" placeholder="Ad soyad veya firma adı" required></span>' +
        '<span class="toplu-evrak-field"><label>Tutar</label><input name="item_amount[]" inputmode="decimal" placeholder="0,00 TL" required></span>' +
        '<span class="toplu-evrak-field"><label>İl</label><input name="item_city[]" placeholder="Örn. İstanbul" required></span>' +
        '<span class="toplu-evrak-field"><label>Vade tarihi</label><input name="item_due_date[]" type="date" required></span>';
      row.querySelector('[name="item_person[]"]').value = saved.person || '';
      row.querySelector('[name="item_amount[]"]').value = saved.amount || '';
      row.querySelector('[name="item_city[]"]').value = saved.city || '';
      row.querySelector('[name="item_due_date[]"]').value = saved.due || '';
      rows.appendChild(row);
    }
    rows.querySelectorAll('input').forEach(function (input) { input.addEventListener('input', updateSummary); });
    updateSummary();
  }

  function openPanel() {
    title.textContent = selectedInstrument() === 'senet' ? 'Toplu senet girişi' : 'Toplu çek girişi';
    backdrop.classList.add('open');
    document.body.style.overflow = 'hidden';
    renderRows();
    var first = rows.querySelector('input');
    if (first) first.focus();
  }

  function closePanel() {
    backdrop.classList.remove('open');
    document.body.style.overflow = '';
  }

  function syncButton() {
    button.style.display = eligible() ? 'inline-flex' : 'none';
    if (!eligible()) closePanel();
  }

  [typeSelect, categorySelect, documentSelect].forEach(function (el) { el.addEventListener('change', syncButton); });
  countInput.addEventListener('input', renderRows);
  button.addEventListener('click', openPanel);
  backdrop.querySelector('[data-close]').addEventListener('click', closePanel);
  backdrop.addEventListener('click', function (event) { if (event.target === backdrop) closePanel(); });
  document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && backdrop.classList.contains('open')) closePanel(); });

  bulkForm.addEventListener('submit', function (event) {
    if (!cariSelect.value) {
      event.preventDefault();
      alert('Önce cari seçmelisin.');
      return;
    }
    if (!bulkForm.checkValidity()) {
      event.preventDefault();
      bulkForm.reportValidity();
      return;
    }
    if (!confirm(countInput.value + ' adet ' + (selectedInstrument() === 'senet' ? 'senet' : 'çek') + ' kaydedilsin mi?')) {
      event.preventDefault();
      return;
    }
    hidden.innerHTML = '';
    var values = {
      csrf_token: csrf.value,
      instrument: selectedInstrument(),
      movement_type: typeSelect.value,
      cari_id: cariSelect.value,
      movement_date: (form.querySelector('[name="movement_date"]') || {}).value || '',
      currency: (form.querySelector('[name="currency"]') || {}).value || 'TL',
      description: (form.querySelector('[name="description"]') || {}).value || ''
    };
    Object.keys(values).forEach(function (name) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = values[name];
      hidden.appendChild(input);
    });
  });

  categorySelect.addEventListener('change', function () {
    var text = categorySelect.options[categorySelect.selectedIndex] ? categorySelect.options[categorySelect.selectedIndex].textContent.trim() : '';
    if (text === 'Senet') documentSelect.value = 'senet_gorseli';
    if (text === 'Çek' && !documentSelect.value) documentSelect.value = 'cek_gorseli';
  });

  syncButton();
})();
