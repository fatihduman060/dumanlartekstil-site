(function () {
  var path = (location.pathname || '').split('/').pop();
  if (path !== 'hareketler.php') return;

  var saveInput = document.querySelector('form input[name="action"][value="save"]');
  var mainForm = saveInput ? saveInput.closest('form') : null;
  if (!mainForm) return;

  var typeSelect = mainForm.querySelector('[name="movement_type"]');
  var categorySelect = mainForm.querySelector('[name="category_id"]');
  var cariSelect = mainForm.querySelector('[name="cari_id"]');
  var amountInput = mainForm.querySelector('[name="amount"]');
  var descriptionInput = mainForm.querySelector('[name="description"]');
  var idInput = mainForm.querySelector('[name="id"]');
  if (!typeSelect || !categorySelect || !cariSelect || !amountInput) return;

  var products = [];
  var detailActive = false;
  var currentViewOnly = false;
  var salesCategoryRequesting = false;
  var editId = parseInt((idInput && idInput.value) || '0', 10) || 0;
  var fmt = new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  function numberValue(value) {
    var text = String(value == null ? '' : value).trim().replace(/\s/g, '');
    if (!text) return 0;
    var comma = text.lastIndexOf(',');
    var dot = text.lastIndexOf('.');
    if (comma !== -1 && dot !== -1) {
      if (comma > dot) text = text.replace(/\./g, '').replace(',', '.');
      else text = text.replace(/,/g, '');
    } else if (comma !== -1) {
      text = text.replace(/\./g, '').replace(',', '.');
    } else if ((text.match(/\./g) || []).length > 1) {
      text = text.replace(/\./g, '');
    }
    var valueNumber = parseFloat(text);
    return Number.isFinite(valueNumber) ? valueNumber : 0;
  }

  function selectedCategoryText() {
    var option = categorySelect.options[categorySelect.selectedIndex];
    return option ? option.textContent.trim() : '';
  }

  function selectedTypeText() {
    var option = typeSelect.options[typeSelect.selectedIndex];
    return option ? option.textContent.trim() : '';
  }

  function isSalesInvoiceSelection() {
    return selectedTypeText().toLocaleLowerCase('tr-TR') === 'satış faturası';
  }

  function findSalesCategoryOption() {
    var salesOption = null;
    Array.prototype.some.call(categorySelect.options, function (option) {
      if (String(option.textContent || '').trim().toLocaleLowerCase('tr-TR') === 'satış') {
        salesOption = option;
        return true;
      }
      return false;
    });
    return salesOption;
  }

  function requestSalesCategory() {
    if (salesCategoryRequesting) return;
    salesCategoryRequesting = true;
    fetch('hareket-satis-kategori.php?_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.id) return;
        var option = findSalesCategoryOption();
        if (!option) {
          option = document.createElement('option');
          option.value = String(data.id);
          option.textContent = data.label || 'Satış';
          categorySelect.appendChild(option);
        }
        if (isSalesInvoiceSelection()) categorySelect.value = String(option.value);
      })
      .catch(function () {})
      .then(function () {
        salesCategoryRequesting = false;
        syncEntry();
      });
  }

  function ensureSalesCategory() {
    if (!isSalesInvoiceSelection()) return false;
    var salesOption = findSalesCategoryOption();
    if (!salesOption) {
      requestSalesCategory();
      return false;
    }
    if (String(categorySelect.value) !== String(salesOption.value)) categorySelect.value = salesOption.value;
    return true;
  }

  function eligible() {
    return typeSelect.value === 'alacak' && selectedCategoryText().toLocaleLowerCase('tr-TR') === 'satış';
  }

  var style = document.createElement('style');
  style.id = 'hareket-satis-detay-style';
  style.textContent = [
    '.satis-detay-entry{display:none;margin:10px 0 4px;padding:12px 14px;border:1px solid #b9d9c3;background:#f2faf4;border-radius:14px}',
    '.satis-detay-entry.open{display:flex;align-items:center;justify-content:space-between;gap:12px}',
    '.satis-detay-entry strong{display:block;color:#16482e}.satis-detay-entry small{display:block;margin-top:3px;color:#617066}',
    '.satis-detay-open{border:0;border-radius:10px;padding:9px 13px;background:#16482e;color:#fff;font-weight:900;cursor:pointer;white-space:nowrap}',
    '.satis-detay-status{margin-top:7px;font-size:12px;font-weight:800;color:#176536}',
    '.satis-modal-backdrop{display:none;position:fixed;inset:0;z-index:10030;background:rgba(12,23,17,.62);padding:20px;overflow:auto}',
    '.satis-modal-backdrop.open{display:flex;align-items:flex-start;justify-content:center}',
    '.satis-modal{width:min(1380px,100%);margin:auto;background:#fff;border-radius:22px;box-shadow:0 28px 90px rgba(0,0,0,.30);overflow:hidden}',
    '.satis-modal-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:20px 24px;background:linear-gradient(135deg,#102818,#23613c);color:#fff}',
    '.satis-modal-head h2{margin:0;color:#fff;font-size:23px}.satis-modal-head p{margin:5px 0 0;color:#dceee2;font-size:13px}',
    '.satis-modal-close{width:38px;height:38px;border:1px solid rgba(255,255,255,.35);border-radius:50%;background:rgba(255,255,255,.12);color:#fff;font-size:22px;cursor:pointer}',
    '.satis-modal-body{padding:20px 24px 24px}',
    '.satis-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px}',
    '.satis-toolbar button{border:1px solid #cfdad2;background:#fff;border-radius:9px;padding:8px 11px;font-weight:850;cursor:pointer}',
    '.satis-grid{display:grid;gap:9px}',
    '.satis-head-row,.satis-item-row{display:grid;grid-template-columns:42px minmax(145px,1fr) minmax(220px,1.7fr) minmax(92px,.65fr) minmax(120px,.8fr) minmax(120px,.8fr) minmax(170px,1fr) 38px;gap:8px;align-items:center}',
    '.satis-head-row{padding:0 9px;color:#66706a;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.045em}',
    '.satis-item-row{padding:10px 9px;border:1px solid #e1e8e3;border-radius:13px;background:#fff}',
    '.satis-item-row:focus-within{border-color:#5e9f74;box-shadow:0 0 0 3px rgba(54,128,79,.12)}',
    '.satis-row-no{display:flex;width:28px;height:28px;align-items:center;justify-content:center;border-radius:50%;background:#eef5f0;color:#225a36;font-weight:900}',
    '.satis-field{display:flex;flex-direction:column;gap:3px}.satis-field label{display:none;font-size:10px;color:#66706a;font-weight:850}',
    '.satis-field input{width:100%;min-width:0;height:42px;border:1px solid #dce4de;border-radius:9px;padding:8px 9px;font-size:13px}',
    '.satis-field input[readonly]{background:#f5f7f5;font-weight:850;text-align:right}',
    '.satis-remove{width:34px;height:34px;border:0;border-radius:9px;background:#fff0ed;color:#b64242;font-size:18px;font-weight:900;cursor:pointer}',
    '.satis-calculation{display:grid;grid-template-columns:minmax(300px,1fr) minmax(330px,430px);gap:18px;margin-top:18px;padding-top:18px;border-top:1px solid #e5ebe6}',
    '.satis-options{display:grid;grid-template-columns:1fr 1fr;gap:10px}',
    '.satis-option{display:grid;grid-template-columns:auto 1fr 100px;align-items:center;gap:9px;padding:12px;border:1px solid #dde7df;border-radius:13px;background:#fafcfb}',
    '.satis-option input[type="checkbox"]{width:19px;height:19px}.satis-option input[type="text"]{height:38px;border:1px solid #d9e2db;border-radius:8px;padding:7px;text-align:right;font-weight:850}',
    '.satis-option strong{display:block;color:#173c27}.satis-option small{display:block;color:#718077;margin-top:2px}',
    '.satis-totals{display:grid;gap:7px;padding:14px 16px;border-radius:15px;background:#f4f8f5}',
    '.satis-total-line{display:flex;justify-content:space-between;gap:16px;color:#516058}.satis-total-line strong{color:#173c27}',
    '.satis-total-line.discount strong{color:#b64242}.satis-total-line.grand{padding-top:8px;border-top:1px solid #d9e4dc;font-size:18px;font-weight:900}',
    '.satis-modal-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:18px}',
    '.satis-modal-actions button{border:0;border-radius:10px;padding:10px 15px;font-weight:900;cursor:pointer}',
    '.satis-cancel{background:#edf1ee;color:#33463a}.satis-apply{background:#16482e;color:#fff}',
    '.satis-list-chip{display:inline-flex;margin-top:6px;padding:4px 7px;border-radius:999px;background:#eef8f1;color:#176536;font-size:10px;font-weight:900}',
    '.satis-list-view{margin-left:6px;border:0;background:transparent;color:#176536;text-decoration:underline;font-size:10px;font-weight:850;cursor:pointer}',
    '.satis-view-only .satis-toolbar button,.satis-view-only .satis-remove,.satis-view-only .satis-apply{display:none!important}',
    '.satis-view-only input{pointer-events:none}',
    '@media(max-width:1050px){.satis-head-row{display:none}.satis-item-row{grid-template-columns:38px 1fr 1fr}.satis-row-no{grid-row:1/4}.satis-field{grid-column:auto}.satis-field label{display:block}.satis-remove{grid-column:3;justify-self:end}.satis-calculation{grid-template-columns:1fr}}',
    '@media(max-width:680px){.satis-modal-backdrop{padding:7px}.satis-modal-body{padding:14px}.satis-item-row{grid-template-columns:34px 1fr}.satis-row-no{grid-row:1/7}.satis-field,.satis-remove{grid-column:2}.satis-remove{justify-self:end}.satis-options{grid-template-columns:1fr}.satis-option{grid-template-columns:auto 1fr 86px}.satis-detay-entry.open{align-items:flex-start;flex-direction:column}}'
  ].join('');
  document.head.appendChild(style);

  var entry = document.createElement('div');
  entry.className = 'satis-detay-entry';
  entry.innerHTML = '<div><strong>Ürünlü satış detayı</strong><small>Barkod, ürün, miktar ve fiyatları ayrı satırlarda kaydet.</small><div class="satis-detay-status" data-status></div></div><button type="button" class="satis-detay-open" data-open>Satış detayını gir</button>';
  var firstTwoCol = mainForm.querySelector('.two-col');
  if (firstTwoCol && firstTwoCol.parentNode) firstTwoCol.parentNode.insertBefore(entry, firstTwoCol.nextSibling);
  else mainForm.insertBefore(entry, mainForm.firstChild);

  var backdrop = document.createElement('div');
  backdrop.className = 'satis-modal-backdrop';
  backdrop.innerHTML = '' +
    '<section class="satis-modal" role="dialog" aria-modal="true">' +
      '<header class="satis-modal-head"><div><h2 data-title>Detaylı satış</h2><p>Barkodu okut veya ürün adını seç; toplamlar otomatik hesaplansın.</p></div><button type="button" class="satis-modal-close" data-close aria-label="Kapat">×</button></header>' +
      '<div class="satis-modal-body">' +
        '<div class="satis-toolbar"><strong data-toolbar-text>Satış kalemleri</strong><button type="button" data-add>+ Ürün satırı ekle</button></div>' +
        '<datalist id="hareketSatisProducts"></datalist>' +
        '<div class="satis-grid"><div class="satis-head-row"><span>No</span><span>Barkod</span><span>Ürün adı</span><span>Miktar</span><span>Birim fiyat</span><span>Satır toplamı</span><span>Satır notu</span><span></span></div><div data-rows></div></div>' +
        '<div class="satis-calculation">' +
          '<div class="satis-options">' +
            '<label class="satis-option"><input type="checkbox" data-discount-enabled><span><strong>İskonto uygula</strong><small>Oranı manuel gir.</small></span><input type="text" inputmode="decimal" value="0" data-discount-rate aria-label="İskonto oranı"></label>' +
            '<label class="satis-option"><input type="checkbox" data-vat-enabled><span><strong>Artı KDV uygula</strong><small>Varsayılan oran %10.</small></span><input type="text" inputmode="decimal" value="10" data-vat-rate aria-label="KDV oranı"></label>' +
          '</div>' +
          '<div class="satis-totals">' +
            '<div class="satis-total-line"><span>Ara toplam</span><strong data-subtotal>0,00</strong></div>' +
            '<div class="satis-total-line discount"><span>İskonto</span><strong data-discount>−0,00</strong></div>' +
            '<div class="satis-total-line"><span>KDV</span><strong data-vat>0,00</strong></div>' +
            '<div class="satis-total-line grand"><span>Genel toplam</span><strong data-grand>0,00</strong></div>' +
          '</div>' +
        '</div>' +
        '<div class="satis-modal-actions"><button type="button" class="satis-cancel" data-close>Vazgeç</button><button type="button" class="satis-apply" data-apply>Satışa uygula</button></div>' +
      '</div>' +
    '</section>';
  document.body.appendChild(backdrop);

  var modal = backdrop.querySelector('.satis-modal');
  var rowsBox = backdrop.querySelector('[data-rows]');
  var dataList = backdrop.querySelector('#hareketSatisProducts');
  var discountEnabled = backdrop.querySelector('[data-discount-enabled]');
  var discountRate = backdrop.querySelector('[data-discount-rate]');
  var vatEnabled = backdrop.querySelector('[data-vat-enabled]');
  var vatRate = backdrop.querySelector('[data-vat-rate]');
  var subtotalOut = backdrop.querySelector('[data-subtotal]');
  var discountOut = backdrop.querySelector('[data-discount]');
  var vatOut = backdrop.querySelector('[data-vat]');
  var grandOut = backdrop.querySelector('[data-grand]');
  var statusOut = entry.querySelector('[data-status]');
  var trigger = entry.querySelector('[data-open]');
  var titleOut = backdrop.querySelector('[data-title]');

  function productByBarcode(value) {
    var key = String(value || '').replace(/\s/g, '');
    return products.find(function (item) { return String(item.barcode || '').replace(/\s/g, '') === key; });
  }

  function productByName(value) {
    var key = String(value || '').trim().toLocaleLowerCase('tr-TR');
    return products.find(function (item) { return String(item.name || '').trim().toLocaleLowerCase('tr-TR') === key; });
  }

  function populateProducts() {
    dataList.innerHTML = '';
    products.forEach(function (item) {
      var option = document.createElement('option');
      option.value = item.name || '';
      if (item.barcode) option.label = item.barcode;
      dataList.appendChild(option);
    });
  }

  function currentRowsData() {
    return [].map.call(rowsBox.querySelectorAll('.satis-item-row'), function (row) {
      return {
        barcode: (row.querySelector('[data-barcode]') || {}).value || '',
        name: (row.querySelector('[data-name]') || {}).value || '',
        quantity: (row.querySelector('[data-qty]') || {}).value || '',
        unit_price: (row.querySelector('[data-price]') || {}).value || '',
        note: (row.querySelector('[data-note]') || {}).value || ''
      };
    });
  }

  function createRow(data) {
    data = data || {};
    var row = document.createElement('div');
    row.className = 'satis-item-row';
    row.innerHTML = '' +
      '<strong class="satis-row-no"></strong>' +
      '<span class="satis-field"><label>Barkod</label><input data-barcode placeholder="Barkod okut / yaz"></span>' +
      '<span class="satis-field"><label>Ürün adı</label><input data-name list="hareketSatisProducts" placeholder="Ürün seç veya yaz"></span>' +
      '<span class="satis-field"><label>Miktar</label><input data-qty inputmode="decimal" placeholder="0"></span>' +
      '<span class="satis-field"><label>Birim fiyat</label><input data-price inputmode="decimal" placeholder="0,00"></span>' +
      '<span class="satis-field"><label>Satır toplamı</label><input data-line-total readonly value="0,00"></span>' +
      '<span class="satis-field"><label>Satır notu</label><input data-note placeholder="İsteğe bağlı"></span>' +
      '<button type="button" class="satis-remove" aria-label="Satırı sil">×</button>';

    row.querySelector('[data-barcode]').value = data.barcode || data.product_barcode || '';
    row.querySelector('[data-name]').value = data.name || data.product_name || '';
    row.querySelector('[data-qty]').value = data.quantity == null ? '' : String(data.quantity).replace('.', ',');
    row.querySelector('[data-price]').value = data.unit_price == null ? '' : String(data.unit_price).replace('.', ',');
    row.querySelector('[data-note]').value = data.note || data.line_note || '';

    row.querySelector('[data-barcode]').addEventListener('change', function () {
      var product = productByBarcode(this.value);
      if (!product) return;
      row.querySelector('[data-name]').value = product.name || '';
      if (numberValue(row.querySelector('[data-price]').value) <= 0 && Number(product.unit_price || 0) > 0) {
        row.querySelector('[data-price]').value = String(product.unit_price).replace('.', ',');
      }
      recalc();
    });
    row.querySelector('[data-name]').addEventListener('change', function () {
      var product = productByName(this.value);
      if (!product) return;
      if (!row.querySelector('[data-barcode]').value && product.barcode) row.querySelector('[data-barcode]').value = product.barcode;
      if (numberValue(row.querySelector('[data-price]').value) <= 0 && Number(product.unit_price || 0) > 0) {
        row.querySelector('[data-price]').value = String(product.unit_price).replace('.', ',');
      }
      recalc();
    });
    row.querySelectorAll('[data-qty],[data-price]').forEach(function (input) { input.addEventListener('input', recalc); });
    row.querySelector('.satis-remove').addEventListener('click', function () {
      row.remove();
      if (!rowsBox.querySelector('.satis-item-row')) createRow({});
      renumber();
      recalc();
    });
    rowsBox.appendChild(row);
    renumber();
    recalc();
  }

  function renumber() {
    rowsBox.querySelectorAll('.satis-item-row').forEach(function (row, index) {
      row.querySelector('.satis-row-no').textContent = String(index + 1);
    });
  }

  function recalc() {
    var subtotal = 0;
    rowsBox.querySelectorAll('.satis-item-row').forEach(function (row) {
      var line = numberValue(row.querySelector('[data-qty]').value) * numberValue(row.querySelector('[data-price]').value);
      line = Math.round(line * 100) / 100;
      subtotal += line;
      row.querySelector('[data-line-total]').value = fmt.format(line);
    });
    var dRate = discountEnabled.checked ? Math.max(0, Math.min(100, numberValue(discountRate.value))) : 0;
    var discount = Math.round(subtotal * dRate) / 100;
    var vatBase = Math.max(0, subtotal - discount);
    var vRate = vatEnabled.checked ? Math.max(0, Math.min(100, numberValue(vatRate.value))) : 0;
    var vat = Math.round(vatBase * vRate) / 100;
    var grand = Math.round((vatBase + vat) * 100) / 100;
    subtotalOut.textContent = fmt.format(subtotal);
    discountOut.textContent = '−' + fmt.format(discount);
    vatOut.textContent = fmt.format(vat);
    grandOut.textContent = fmt.format(grand);
    return { subtotal: subtotal, discount: discount, vat: vat, grand: grand, discount_rate: dRate, vat_rate: vRate };
  }

  function collectPayload(showErrors) {
    var items = [];
    var firstInvalid = null;
    rowsBox.querySelectorAll('.satis-item-row').forEach(function (row) {
      var barcode = row.querySelector('[data-barcode]').value.trim();
      var name = row.querySelector('[data-name]').value.trim();
      var qty = numberValue(row.querySelector('[data-qty]').value);
      var price = numberValue(row.querySelector('[data-price]').value);
      var note = row.querySelector('[data-note]').value.trim();
      var completelyEmpty = !barcode && !name && qty <= 0 && price <= 0 && !note;
      row.querySelectorAll('input').forEach(function (input) { input.style.borderColor = ''; });
      if (completelyEmpty) return;
      if (!name) {
        row.querySelector('[data-name]').style.borderColor = '#c84f45';
        firstInvalid = firstInvalid || row.querySelector('[data-name]');
        return;
      }
      if (qty <= 0) {
        row.querySelector('[data-qty]').style.borderColor = '#c84f45';
        firstInvalid = firstInvalid || row.querySelector('[data-qty]');
        return;
      }
      if (price < 0) {
        row.querySelector('[data-price]').style.borderColor = '#c84f45';
        firstInvalid = firstInvalid || row.querySelector('[data-price]');
        return;
      }
      items.push({ barcode: barcode, name: name, quantity: qty, unit_price: price, note: note });
    });

    var totals = recalc();
    if (!items.length && !firstInvalid) firstInvalid = rowsBox.querySelector('[data-name]');
    if (totals.grand <= 0 && !firstInvalid) firstInvalid = rowsBox.querySelector('[data-price]');
    if (firstInvalid) {
      if (showErrors) {
        alert('Ürün adı, miktar ve fiyatları kontrol et. Genel toplam sıfırdan büyük olmalı.');
        firstInvalid.focus();
      }
      return null;
    }

    return {
      items: items,
      discount_enabled: discountEnabled.checked ? 1 : 0,
      discount_rate: totals.discount_rate,
      vat_enabled: vatEnabled.checked ? 1 : 0,
      vat_rate: totals.vat_rate
    };
  }

  function setRows(items) {
    rowsBox.innerHTML = '';
    (items && items.length ? items : [{}, {}, {}]).forEach(createRow);
    renumber();
    recalc();
  }

  function loadSaleIntoModal(sale) {
    sale = sale || {};
    setRows(sale.items || []);
    discountEnabled.checked = Number(sale.discount_enabled || 0) === 1;
    discountRate.value = String(sale.discount_rate == null ? 0 : sale.discount_rate).replace('.', ',');
    vatEnabled.checked = Number(sale.vat_enabled || 0) === 1;
    vatRate.value = String(sale.vat_rate == null ? 10 : sale.vat_rate).replace('.', ',');
    recalc();
  }

  function setViewOnly(viewOnly) {
    currentViewOnly = !!viewOnly;
    modal.classList.toggle('satis-view-only', currentViewOnly);
    backdrop.querySelectorAll('input').forEach(function (input) { input.disabled = currentViewOnly; });
  }

  function openModal(viewOnly) {
    setViewOnly(viewOnly);
    titleOut.textContent = viewOnly ? 'Satış detayını görüntüle' : 'Detaylı satış';
    backdrop.classList.add('open');
    document.body.style.overflow = 'hidden';
    if (!viewOnly) {
      var first = rowsBox.querySelector('[data-barcode]');
      if (first) window.setTimeout(function () { first.focus(); }, 50);
    }
  }

  function closeModal() {
    backdrop.classList.remove('open');
    document.body.style.overflow = '';
  }

  function applyToMainForm(showErrors) {
    var payload = collectPayload(showErrors);
    if (!payload) return false;
    var totals = recalc();
    detailActive = true;
    amountInput.value = fmt.format(totals.grand);
    amountInput.readOnly = true;
    if (descriptionInput && !descriptionInput.value.trim()) descriptionInput.value = 'Detaylı satış · ' + payload.items.length + ' kalem';
    statusOut.textContent = payload.items.length + ' kalem · Genel toplam ' + fmt.format(totals.grand) + ' ' + ((mainForm.querySelector('[name="currency"]') || {}).value || 'TL');
    trigger.textContent = 'Satış detayını düzenle';
    mainForm.dataset.saleDetailJson = JSON.stringify(payload);
    return true;
  }

  function syncEntry() {
    if (isSalesInvoiceSelection()) ensureSalesCategory();
    var canShow = eligible();
    entry.classList.toggle('open', canShow);
    if (!canShow && detailActive) {
      statusOut.textContent = 'Satış detayı var; kaydetmek için Satış Faturası ve Satış kategorisi seçili kalmalı.';
    }
  }

  trigger.addEventListener('click', function () {
    if (isSalesInvoiceSelection()) ensureSalesCategory();
    if (!eligible()) return;
    if (!detailActive && !rowsBox.querySelector('.satis-item-row')) setRows([]);
    openModal(false);
  });

  backdrop.querySelector('[data-add]').addEventListener('click', function () { createRow({}); });
  backdrop.querySelector('[data-apply]').addEventListener('click', function () {
    if (!applyToMainForm(true)) return;
    closeModal();
  });
  backdrop.querySelectorAll('[data-close]').forEach(function (button) { button.addEventListener('click', closeModal); });
  backdrop.addEventListener('click', function (event) { if (event.target === backdrop) closeModal(); });
  document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && backdrop.classList.contains('open')) closeModal(); });
  [discountEnabled, discountRate, vatEnabled, vatRate].forEach(function (input) { input.addEventListener('input', recalc); input.addEventListener('change', recalc); });
  [typeSelect, categorySelect].forEach(function (select) { select.addEventListener('change', syncEntry); });

  mainForm.addEventListener('submit', function (event) {
    if (!detailActive) return;
    event.preventDefault();
    if (isSalesInvoiceSelection()) ensureSalesCategory();
    if (!eligible()) {
      alert('Detaylı satışı kaydetmek için Satış Faturası ve Satış kategorisi seçili olmalı.');
      return;
    }
    if (!cariSelect.value) {
      alert('Detaylı satış için cari seçmelisin.');
      cariSelect.focus();
      return;
    }
    if (!applyToMainForm(true)) {
      openModal(false);
      return;
    }
    var hidden = mainForm.querySelector('[name="sale_detail_json"]');
    if (!hidden) {
      hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'sale_detail_json';
      mainForm.appendChild(hidden);
    }
    hidden.value = mainForm.dataset.saleDetailJson || '';
    mainForm.action = 'hareket-satis-kaydet.php';
    HTMLFormElement.prototype.submit.call(mainForm);
  });

  function fetchJson(url) {
    return fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(function (response) {
        if (!response.ok) throw new Error('Satış bilgileri alınamadı.');
        return response.json();
      });
  }

  function movementRowsById() {
    var out = {};
    document.querySelectorAll('.table-wrap tbody tr').forEach(function (row) {
      var edit = row.querySelector('a[href*="hareketler.php?edit="]');
      if (!edit) return;
      try {
        var id = new URL(edit.href, location.href).searchParams.get('edit');
        if (id) out[id] = row;
      } catch (e) {}
    });
    return out;
  }

  function decorateList() {
    var rowMap = movementRowsById();
    var ids = Object.keys(rowMap);
    if (!ids.length) return;
    fetchJson('hareket-satis-veri.php?ids=' + encodeURIComponent(ids.join(','))).then(function (data) {
      var summaries = data.summaries || {};
      Object.keys(summaries).forEach(function (id) {
        var row = rowMap[id];
        if (!row || row.querySelector('.satis-list-chip')) return;
        var summary = summaries[id];
        var cell = row.cells && row.cells.length > 4 ? row.cells[4] : null;
        if (!cell) return;
        var chip = document.createElement('span');
        chip.className = 'satis-list-chip';
        chip.textContent = (summary.item_count || 0) + ' kalem satış detayı';
        var view = document.createElement('button');
        view.type = 'button';
        view.className = 'satis-list-view';
        view.textContent = 'Detayı gör';
        view.addEventListener('click', function () {
          fetchJson('hareket-satis-veri.php?id=' + encodeURIComponent(id)).then(function (detail) {
            loadSaleIntoModal(detail.sale || {});
            openModal(true);
          }).catch(function (error) { alert(error.message); });
        });
        cell.appendChild(document.createElement('br'));
        cell.appendChild(chip);
        cell.appendChild(view);
      });
    }).catch(function () {});
  }

  fetchJson('hareket-satis-veri.php?products=1' + (editId > 0 ? '&id=' + encodeURIComponent(editId) : '')).then(function (data) {
    products = data.products || [];
    populateProducts();
    if (data.sale) {
      loadSaleIntoModal(data.sale);
      detailActive = true;
      applyToMainForm(false);
    } else {
      setRows([]);
    }
  }).catch(function () { setRows([]); });

  syncEntry();
  window.setTimeout(syncEntry, 50);
  window.setTimeout(syncEntry, 300);
  decorateList();
})();
