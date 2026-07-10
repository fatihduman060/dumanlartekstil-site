(function(){
  var PREFIX = '86992348';

  function onlyDigits(value){
    return String(value || '').replace(/\D+/g, '');
  }

  function ean13CheckDigit(first12){
    var digits = onlyDigits(first12);
    if (digits.length !== 12) return '';
    var sum = 0;
    for (var i = 0; i < 12; i++) {
      sum += Number(digits.charAt(i)) * (i % 2 === 0 ? 1 : 3);
    }
    return String((10 - (sum % 10)) % 10);
  }

  function barcodeFromArticle(article){
    var digits = onlyDigits(article);
    if (digits.length !== 4) return '';
    var first12 = PREFIX + digits;
    return first12 + ean13CheckDigit(first12);
  }

  function articleFromText(text){
    var value = String(text || '');
    if (!value.trim()) return '';

    var fullBarcode = value.match(/86992348\s*([0-9]{2})[\s\-\/.]*([0-9]{2})\s*[0-9]/);
    if (fullBarcode) return fullBarcode[1] + fullBarcode[2];

    var dashed = value.match(/(?:^|[^0-9])([0-9]{2})\s*[\-\/.]\s*([0-9]{2})(?:[^0-9]|$)/);
    if (dashed) return dashed[1] + dashed[2];

    var start = value.match(/^\s*([0-9]{4})(?:[^0-9]|$)/);
    if (start) return start[1];

    return '';
  }

  function normalizeBarcode(raw, name, type){
    var text = String(raw || '').trim();
    var digits = onlyDigits(text);
    if (digits.length === 13) return digits;
    if (digits.length === 12 && digits.indexOf(PREFIX) === 0) return digits + ean13CheckDigit(digits);
    if (digits.length === 4) return barcodeFromArticle(digits);

    var article = articleFromText(text) || articleFromText(name) || articleFromText(type);
    return article ? barcodeFromArticle(article) : text;
  }

  function rowOf(el){ return el && el.closest ? el.closest('tr') : null; }

  function fillRow(row, forceNormalizeBarcode){
    if (!row) return;
    var barcode = row.querySelector('.product-barcode');
    var name = row.querySelector('.product-name');
    var type = row.querySelector('.product-type');
    if (!barcode) return;

    var current = String(barcode.value || '').trim();
    var productName = name ? name.value : '';
    var productType = type ? type.value : '';
    var next = '';

    if (current || forceNormalizeBarcode) {
      next = normalizeBarcode(current, productName, productType);
    } else {
      next = normalizeBarcode('', productName, productType);
    }

    if (next && next !== current) {
      barcode.value = next;
      barcode.classList.add('barcode-auto-filled');
      setTimeout(function(){ barcode.classList.remove('barcode-auto-filled'); }, 1200);
    }
  }

  function fillAll(){
    document.querySelectorAll('#offerRows tbody tr').forEach(function(row){ fillRow(row, false); });
  }

  function init(){
    if (!/teklif-ver\.php/i.test(location.pathname)) return;
    var table = document.getElementById('offerRows');
    if (!table) return;

    var style = document.createElement('style');
    style.textContent = '.barcode-auto-filled{background:#eef8f1!important;border-color:#49a568!important;transition:background .2s ease,border-color .2s ease}';
    document.head.appendChild(style);

    table.addEventListener('input', function(e){
      if (!e.target) return;
      if (e.target.classList.contains('product-name') || e.target.classList.contains('product-type')) {
        fillRow(rowOf(e.target), false);
      }
    });

    table.addEventListener('change', function(e){
      if (!e.target) return;
      if (e.target.classList.contains('product-name') || e.target.classList.contains('product-type')) fillRow(rowOf(e.target), false);
      if (e.target.classList.contains('product-barcode')) fillRow(rowOf(e.target), true);
    });

    table.addEventListener('blur', function(e){
      if (e.target && e.target.classList.contains('product-barcode')) fillRow(rowOf(e.target), true);
    }, true);

    var form = document.getElementById('offerForm');
    if (form) form.addEventListener('submit', fillAll);

    setTimeout(fillAll, 100);
    setTimeout(fillAll, 700);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
