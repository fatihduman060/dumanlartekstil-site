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
    '.toplu-evrak-btn{display:none;margin-top:8px;border:1px solid #c89a2f;background:#fff8e7;color:#745000;border-radius:9px;padding:8px 11px;font-weight:850;cursor:pointer}',
    '.toplu-evrak-panel{display:none;margin-top:12px;padding:14px;border:1px solid #ead7a3;background:#fffdf7;border-radius:14px}',
    '.toplu-evrak-panel.open{display:block}',
    '.toplu-evrak-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}',
    '.toplu-evrak-grid{display:grid;gap:7px}',
    '.toplu-evrak-row{display:grid;grid-template-columns:44px 1fr 1fr 1fr;gap:7px;align-items:center}',
    '.toplu-evrak-row input{min-width:0}',
    '.toplu-evrak-actions{display:flex;gap:8px;margin-top:12px}',
    '@media(max-width:720px){.toplu-evrak-row{grid-template-columns:34px 1fr}.toplu-evrak-row input{grid-column:2}}'
  ].join('');
  document.head.appendChild(style);

  var button = document.createElement('button');
  button.type = 'button';
  button.className = 'toplu-evrak-btn';
  button.textContent = 'Toplu çek / senet girişi';

  var panel = document.createElement('div');
  panel.className = 'toplu-evrak-panel';
  panel.innerHTML = '<div class="toplu-evrak-head"><strong>Toplu çek / senet girişi</strong><label>Adet <input type="number" min="1" max="24" value="3" data-count style="width:72px"></label></div><form method="post" action="hareket-toplu-cek-senet.php" data-bulk-form><div data-hidden></div><div class="toplu-evrak-grid" data-rows></div><div class="toplu-evrak-actions"><button class="btn btn-primary" type="submit">Hepsini kaydet</button><button class="btn btn-secondary" type="button" data-close>Vazgeç</button></div></form>';

  var anchor = documentSelect.closest('label') || documentSelect;
  anchor.parentNode.insertBefore(button, anchor.nextSibling);
  button.parentNode.insertBefore(panel, button.nextSibling);

  var countInput = panel.querySelector('[data-count]');
  var rows = panel.querySelector('[data-rows]');
  var bulkForm = panel.querySelector('[data-bulk-form]');
  var hidden = panel.querySelector('[data-hidden]');

  function selectedInstrument() {
    var categoryText = categorySelect.options[categorySelect.selectedIndex] ? categorySelect.options[categorySelect.selectedIndex].textContent.trim() : '';
    return categoryText === 'Senet' || documentSelect.value === 'senet_gorseli' ? 'senet' : 'cek';
  }

  function eligible() {
    var categoryText = categorySelect.options[categorySelect.selectedIndex] ? categorySelect.options[categorySelect.selectedIndex].textContent.trim() : '';
    return (typeSelect.value === 'tahsilat' || typeSelect.value === 'odeme') && (categoryText === 'Çek' || categoryText === 'Senet' || documentSelect.value === 'cek_gorseli' || documentSelect.value === 'senet_gorseli');
  }

  function renderRows() {
    var count = Math.max(1, Math.min(24, parseInt(countInput.value || '1', 10)));
    countInput.value = count;
    var instrument = selectedInstrument();
    rows.innerHTML = '';
    for (var i = 0; i < count; i++) {
      var row = document.createElement('div');
      row.className = 'toplu-evrak-row';
      row.innerHTML = '<strong>' + (i + 1) + '</strong><input name="document_no[]" placeholder="' + (instrument === 'senet' ? 'Senet no' : 'Çek no') + '"><input name="item_amount[]" inputmode="decimal" placeholder="Tutar" required><input name="item_due_date[]" type="date" required>';
      rows.appendChild(row);
    }
  }

  function syncButton() {
    button.style.display = eligible() ? 'inline-flex' : 'none';
    if (!eligible()) panel.classList.remove('open');
  }

  [typeSelect, categorySelect, documentSelect].forEach(function (el) { el.addEventListener('change', syncButton); });
  countInput.addEventListener('input', renderRows);
  button.addEventListener('click', function () {
    panel.classList.toggle('open');
    renderRows();
  });
  panel.querySelector('[data-close]').addEventListener('click', function () { panel.classList.remove('open'); });

  bulkForm.addEventListener('submit', function (event) {
    if (!cariSelect.value) {
      event.preventDefault();
      alert('Önce cari seçmelisin.');
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
