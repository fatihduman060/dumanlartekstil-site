(function () {
  'use strict';

  var table = document.querySelector('#offerRows, #wdRows');
  var products = Array.isArray(window.dispatchPriceProducts) ? window.dispatchPriceProducts : [];
  if (!table || !products.length) return;

  function normalize(value) {
    return String(value || '').toLocaleLowerCase('tr-TR').normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '').replace(/ı/g, 'i')
      .replace(/[^a-z0-9]+/g, ' ').replace(/\b(\d+)\s+(lu|li)\b/g, '$1$2')
      .trim().replace(/\s+/g, ' ');
  }

  function article(product) {
    var name = String(product.name || '');
    var fromName = name.match(/^\s*(\d{4})(?=\D|$)/);
    if (fromName) return fromName[1];
    var fromBarcode = String(product.barcode || '').match(/^86992348(\d{4})\d$/);
    return fromBarcode ? fromBarcode[1] : '';
  }

  var catalog = products.map(function (product) {
    return {product: product, name: normalize(product.name), barcode: String(product.barcode || '').trim(), article: article(product)};
  });
  var style = document.createElement('style');
  style.textContent = '.document-product-options{position:fixed;z-index:10000;max-height:300px;overflow:auto;padding:5px;border:1px solid #cbd9cf;border-radius:12px;background:#fff;box-shadow:0 12px 30px rgba(7,27,30,.18)}' +
    '.document-product-options[hidden]{display:none!important}.document-product-option{display:block;width:100%;padding:9px 11px;border:0;border-radius:8px;background:#fff;color:#173f29;text-align:left;cursor:pointer}' +
    '.document-product-option:hover,.document-product-option[aria-selected="true"]{background:#eaf5ed}.document-product-option strong{display:block;font-size:13px;line-height:1.3}' +
    '.document-product-option small{display:block;margin-top:3px;color:#657367;font-size:11px}';
  document.head.appendChild(style);

  var popup = document.createElement('div');
  popup.id = 'document-product-options';
  popup.className = 'document-product-options';
  popup.setAttribute('role', 'listbox');
  popup.hidden = true;
  document.body.appendChild(popup);
  var current = null;
  var matches = [];
  var selected = -1;

  function close() {
    popup.hidden = true;
    popup.replaceChildren();
    if (current) {
      current.setAttribute('aria-expanded', 'false');
      current.removeAttribute('aria-activedescendant');
    }
    current = null;
    matches = [];
    selected = -1;
  }

  function position() {
    if (!current || popup.hidden) return;
    var rect = current.getBoundingClientRect();
    if (rect.bottom < 0 || rect.top > innerHeight) { close(); return; }
    var width = Math.min(440, Math.max(280, rect.width), innerWidth - 16);
    popup.style.width = width + 'px';
    popup.style.left = Math.max(8, Math.min(rect.left, innerWidth - width - 8)) + 'px';
    var above = innerHeight - rect.bottom < 180 && rect.top > innerHeight - rect.bottom;
    popup.style.maxHeight = Math.min(300, Math.max(120, above ? rect.top - 12 : innerHeight - rect.bottom - 12)) + 'px';
    popup.style.top = above ? Math.max(8, rect.top - popup.offsetHeight - 4) + 'px' : rect.bottom + 4 + 'px';
  }

  function rank(entry, query, words) {
    if (entry.barcode === query || entry.article === query) return 1000;
    if (entry.name === query) return 900;
    var haystack = entry.name + ' ' + entry.barcode + ' ' + entry.article;
    var found = words.filter(function (word) { return haystack.includes(word); }).length;
    if (!found || (words.length > 2 && found < words.length - 1)) return 0;
    var score = found === words.length ? 500 : 100;
    if (entry.name.startsWith(query)) score += 90;
    words.forEach(function (word) {
      if (entry.name.split(' ').includes(word)) score += 12;
      else if (entry.name.includes(word)) score += 5;
    });
    return score;
  }

  function highlight() {
    Array.prototype.forEach.call(popup.children, function (option, index) {
      var active = index === selected;
      option.setAttribute('aria-selected', active ? 'true' : 'false');
      if (active) option.scrollIntoView({block: 'nearest'});
    });
    if (current) {
      if (selected >= 0) current.setAttribute('aria-activedescendant', 'document-product-option-' + selected);
      else current.removeAttribute('aria-activedescendant');
    }
  }

  function choose(index) {
    var entry = matches[index];
    if (!entry || !current) return;
    var input = current;
    var row = input.closest('tr');
    var barcode = row && row.querySelector('.product-barcode');
    input.value = String(entry.product.name || '');
    if (barcode) barcode.value = entry.barcode;
    input.dispatchEvent(new Event('input', {bubbles: true}));
    (barcode && entry.barcode ? barcode : input).dispatchEvent(new Event('change', {bubbles: true}));
    input.focus();
    close();
  }

  function show(input) {
    var query = normalize(input.value);
    if (query.length < 2) { close(); return; }
    var words = query.split(' ');
    matches = catalog.map(function (entry) { return {entry: entry, score: rank(entry, query, words)}; })
      .filter(function (item) { return item.score > 0; })
      .sort(function (a, b) { return b.score - a.score || a.entry.name.localeCompare(b.entry.name, 'tr'); })
      .slice(0, 8).map(function (item) { return item.entry; });
    if (!matches.length) { close(); return; }
    if (current && current !== input) current.setAttribute('aria-expanded', 'false');
    current = input;
    current.setAttribute('role', 'combobox');
    current.setAttribute('aria-autocomplete', 'list');
    current.setAttribute('aria-controls', popup.id);
    current.setAttribute('aria-expanded', 'true');
    popup.replaceChildren();
    selected = -1;
    matches.forEach(function (entry, index) {
      var option = document.createElement('button');
      option.type = 'button';
      option.className = 'document-product-option';
      option.id = 'document-product-option-' + index;
      option.setAttribute('role', 'option');
      option.setAttribute('aria-selected', 'false');
      var title = document.createElement('strong');
      title.textContent = entry.product.name;
      var detail = document.createElement('small');
      detail.textContent = [entry.article ? 'Artikel: ' + entry.article : '', entry.barcode ? 'Barkod: ' + entry.barcode : ''].filter(Boolean).join(' · ');
      option.appendChild(title);
      if (detail.textContent) option.appendChild(detail);
      option.addEventListener('pointerdown', function (event) { event.preventDefault(); choose(index); });
      popup.appendChild(option);
    });
    popup.hidden = false;
    position();
  }

  table.querySelectorAll('.product-name').forEach(function (input) { input.removeAttribute('list'); });
  table.addEventListener('focusin', function (event) {
    if (!event.target.classList.contains('product-name')) return;
    event.target.removeAttribute('list');
    show(event.target);
  });
  table.addEventListener('input', function (event) {
    if (event.target.classList.contains('product-name')) show(event.target);
  });
  table.addEventListener('keydown', function (event) {
    if (event.target !== current || popup.hidden) return;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      selected = (selected + (event.key === 'ArrowDown' ? 1 : matches.length - 1)) % matches.length;
      highlight();
    } else if (event.key === 'Enter' && selected >= 0) {
      event.preventDefault();
      choose(selected);
    } else if (event.key === 'Escape') {
      event.preventDefault();
      close();
    }
  });
  document.addEventListener('pointerdown', function (event) {
    if (current && event.target !== current && !popup.contains(event.target)) close();
  });
  table.addEventListener('focusout', function (event) {
    if (event.target === current) setTimeout(function () {
      if (current && document.activeElement !== current && !popup.contains(document.activeElement)) close();
    }, 0);
  });
  window.addEventListener('scroll', position, true);
  window.addEventListener('resize', position);
})();
