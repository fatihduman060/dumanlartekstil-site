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

  function markAutoFilled(input){
    if (!input) return;
    input.classList.add('barcode-auto-filled');
    setTimeout(function(){ input.classList.remove('barcode-auto-filled'); }, 1200);
  }

  function ensureStyle(){
    if (document.getElementById('barcode-auto-style')) return;
    var style = document.createElement('style');
    style.id = 'barcode-auto-style';
    style.textContent = '.barcode-auto-filled{background:#eef8f1!important;border-color:#49a568!important;transition:background .2s ease,border-color .2s ease}';
    document.head.appendChild(style);
  }

  function rowOf(el){ return el && el.closest ? el.closest('tr') : null; }

  function fillOfferRow(row){
    if (!row) return;
    var barcode = row.querySelector('.product-barcode');
    var name = row.querySelector('.product-name');
    var type = row.querySelector('.product-type');
    if (!barcode) return;

    var current = String(barcode.value || '').trim();
    var next = normalizeBarcode(current, name ? name.value : '', type ? type.value : '');
    if (next && next !== current) {
      barcode.value = next;
      markAutoFilled(barcode);
    }
  }

  function fillAllOfferRows(){
    document.querySelectorAll('#offerRows tbody tr').forEach(function(row){ fillOfferRow(row); });
  }

  function initOffer(){
    if (!/teklif-ver\.php/i.test(location.pathname)) return;
    var table = document.getElementById('offerRows');
    if (!table) return;
    ensureStyle();

    table.addEventListener('input', function(e){
      if (!e.target) return;
      if (e.target.classList.contains('product-name') || e.target.classList.contains('product-type')) fillOfferRow(rowOf(e.target));
    });
    table.addEventListener('change', function(e){
      if (!e.target) return;
      if (e.target.classList.contains('product-name') || e.target.classList.contains('product-type') || e.target.classList.contains('product-barcode')) fillOfferRow(rowOf(e.target));
    });
    table.addEventListener('blur', function(e){
      if (e.target && e.target.classList.contains('product-barcode')) fillOfferRow(rowOf(e.target));
    }, true);

    var form = document.getElementById('offerForm');
    if (form) form.addEventListener('submit', fillAllOfferRows);
    setTimeout(fillAllOfferRows, 100);
    setTimeout(fillAllOfferRows, 700);
  }

  function saleRowOf(el){ return el && el.closest ? el.closest('.satis-item-row') : null; }

  function fillSaleRow(row){
    if (!row) return;
    var barcode = row.querySelector('[data-barcode]');
    var name = row.querySelector('[data-name]');
    if (!barcode || !name) return;

    var current = String(barcode.value || '').trim();
    var next = normalizeBarcode(current, name.value || '', '');
    if (next && next !== current) {
      barcode.value = next;
      barcode.setAttribute('data-auto-ean13', '1');
      markAutoFilled(barcode);
    }
  }

  function fillAllSaleRows(){
    document.querySelectorAll('.satis-item-row').forEach(function(row){ fillSaleRow(row); });
  }

  function initMovementSale(){
    if (!/hareketler\.php/i.test(location.pathname)) return;
    ensureStyle();

    document.addEventListener('input', function(e){
      if (!e.target || !e.target.matches) return;
      if (e.target.matches('.satis-item-row [data-barcode]')) {
        e.target.removeAttribute('data-auto-ean13');
      }
    });

    document.addEventListener('change', function(e){
      if (!e.target || !e.target.matches || !e.target.matches('.satis-item-row [data-name]')) return;
      var row = saleRowOf(e.target);
      var barcode = row ? row.querySelector('[data-barcode]') : null;
      if (barcode && barcode.getAttribute('data-auto-ean13') === '1') {
        barcode.value = '';
        barcode.removeAttribute('data-auto-ean13');
      }
    }, true);

    document.addEventListener('change', function(e){
      if (!e.target || !e.target.matches) return;
      if (e.target.matches('.satis-item-row [data-name],.satis-item-row [data-barcode]')) fillSaleRow(saleRowOf(e.target));
    });

    document.addEventListener('blur', function(e){
      if (!e.target || !e.target.matches) return;
      if (e.target.matches('.satis-item-row [data-name],.satis-item-row [data-barcode]')) fillSaleRow(saleRowOf(e.target));
    }, true);

    document.addEventListener('click', function(e){
      if (e.target && e.target.closest && e.target.closest('[data-apply]')) fillAllSaleRows();
    }, true);

    var observer = new MutationObserver(function(mutations){
      mutations.forEach(function(mutation){
        Array.prototype.forEach.call(mutation.addedNodes || [], function(node){
          if (!node || node.nodeType !== 1) return;
          if (node.matches && node.matches('.satis-item-row')) fillSaleRow(node);
          if (node.querySelectorAll) node.querySelectorAll('.satis-item-row').forEach(function(row){ fillSaleRow(row); });
        });
      });
    });
    observer.observe(document.body, {childList:true, subtree:true});

    setTimeout(fillAllSaleRows, 200);
    setTimeout(fillAllSaleRows, 900);
  }

  function init(){
    initOffer();
    initMovementSale();
  }

  window.DUMANLAR_EAN13 = {
    prefix: PREFIX,
    fromArticle: barcodeFromArticle,
    normalize: normalizeBarcode
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

(function(){
  if (!/hareketler\.php/i.test(location.pathname)) return;

  function ensureSaleDetailStyle(){
    if (document.getElementById('satis-list-click-style')) return;
    var style = document.createElement('style');
    style.id = 'satis-list-click-style';
    style.textContent = [
      '.satis-list-clickable{cursor:pointer;position:relative;border-radius:9px;transition:background .15s ease,box-shadow .15s ease}',
      '.satis-list-clickable:hover,.satis-list-clickable:focus{background:#f1f8f3!important;box-shadow:inset 0 0 0 1px #abd0b6;outline:none}',
      '.satis-list-clickable .satis-list-chip{cursor:pointer;border:1px solid #b9d9c3;padding:5px 9px}',
      '.satis-list-clickable .satis-list-view{font-size:11px;font-weight:900}',
      '.satis-view-only .satis-field input:disabled{opacity:1!important;color:#1e2c23!important;-webkit-text-fill-color:#1e2c23!important;background:#f7faf7!important}',
      '.satis-view-only .satis-field [data-qty],.satis-view-only .satis-field [data-price],.satis-view-only .satis-field [data-line-total]{font-weight:900!important}',
      '.satis-view-only .satis-calculation{margin-top:14px}',
      '@media(max-width:760px){.satis-list-clickable{padding-right:8px!important}.satis-list-clickable .satis-list-chip{display:inline-flex;font-size:11px;padding:7px 9px}}'
    ].join('');
    document.head.appendChild(style);
  }

  function labelSaleModal(){
    var modal = document.querySelector('.satis-modal');
    if (!modal) return;

    var head = modal.querySelectorAll('.satis-head-row span');
    if (head.length > 5) {
      head[3].textContent = 'Miktar (DZ)';
      head[4].textContent = 'Birim fiyat / DZ';
      head[5].textContent = 'Satır toplamı';
    }

    modal.querySelectorAll('.satis-item-row').forEach(function(row){
      var labels = row.querySelectorAll('.satis-field label');
      if (labels.length > 4) {
        labels[2].textContent = 'Miktar (DZ)';
        labels[3].textContent = 'Birim fiyat / DZ';
        labels[4].textContent = 'Satır toplamı';
      }
    });

    var toolbar = modal.querySelector('[data-toolbar-text]');
    var closeAction = modal.querySelector('.satis-cancel');
    if (modal.classList.contains('satis-view-only')) {
      if (toolbar) toolbar.textContent = 'Satış kalemleri · Miktarlar düzine (DZ) olarak gösterilir';
      if (closeAction) closeAction.textContent = 'Kapat';
    } else {
      if (toolbar) toolbar.textContent = 'Satış kalemleri';
      if (closeAction) closeAction.textContent = 'Vazgeç';
    }
  }

  function openSaleDetail(view){
    if (!view) return;
    view.click();
    window.setTimeout(labelSaleModal, 30);
    window.setTimeout(labelSaleModal, 180);
  }

  function decorateSaleView(view){
    if (!view || view.getAttribute('data-cell-click-ready') === '1') return;
    var cell = view.closest('td');
    if (!cell) return;

    view.setAttribute('data-cell-click-ready', '1');
    view.textContent = 'Ayrıntıyı aç';
    view.title = 'Ürün, düzine, birim fiyat ve toplamları göster';

    var chip = cell.querySelector('.satis-list-chip');
    if (chip) {
      var countText = String(chip.textContent || '').match(/\d+/);
      chip.textContent = (countText ? countText[0] + ' kalem' : 'Satış detayı') + ' · ürünleri gör';
    }

    cell.classList.add('satis-list-clickable');
    cell.setAttribute('role', 'button');
    cell.setAttribute('tabindex', '0');
    cell.setAttribute('aria-label', 'Detaylı satışın ürünlerini, miktarlarını ve fiyatlarını aç');
    cell.title = 'Tıkla: ürün, düzine, birim fiyat ve toplamları gör';

    cell.addEventListener('click', function(event){
      if (event.target && event.target.closest && event.target.closest('a,button,input,select,textarea')) return;
      openSaleDetail(view);
    });
    cell.addEventListener('keydown', function(event){
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      openSaleDetail(view);
    });
    view.addEventListener('click', function(){
      window.setTimeout(labelSaleModal, 30);
      window.setTimeout(labelSaleModal, 180);
    });
  }

  function enhanceSaleDetails(){
    ensureSaleDetailStyle();
    document.querySelectorAll('.satis-list-view').forEach(decorateSaleView);
    labelSaleModal();
  }

  var observer = new MutationObserver(enhanceSaleDetails);
  observer.observe(document.body, {childList:true, subtree:true, attributes:true, attributeFilter:['class']});
  [0,100,300,700,1400].forEach(function(delay){ window.setTimeout(enhanceSaleDetails, delay); });
})();