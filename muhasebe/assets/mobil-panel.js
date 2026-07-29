(function () {
  'use strict';

  var mobileMedia = window.matchMedia ? window.matchMedia('(max-width: 760px)') : null;
  var isMobile = function () {
    return mobileMedia ? mobileMedia.matches : window.innerWidth <= 760;
  };

  function currentPath() {
    var path = (window.location.pathname || '').split('/').pop();
    return path || 'dashboard.php';
  }

  function setOverlayState(open) {
    document.body.classList.toggle('mobile-overlay-open', !!open);
  }

  function closeSidebar() {
    document.body.classList.remove('sidebar-open');
    var menu = document.querySelector('.mobile-bottom-nav [data-mobile-menu]');
    if (menu) menu.classList.remove('is-open');
  }

  function buildBottomNavigation() {
    if (document.querySelector('.mobile-bottom-nav')) return;

    var sideNav = document.querySelector('.side-nav');
    if (!sideNav) return;

    var findLink = function (href) {
      return sideNav.querySelector('a[href="' + href + '"]');
    };

    var items = [
      { href: 'dashboard.php', label: 'Genel', icon: '⌂' },
      { href: 'cariler.php', label: 'Cariler', icon: '◎' },
      { href: 'hareketler.php', label: 'Hareketler', icon: '↕' }
    ];

    var fourth = findLink('faturalar.php')
      ? { href: 'faturalar.php', label: 'Faturalar', icon: '▤' }
      : (findLink('teklif-ver.php')
        ? { href: 'teklif-ver.php', label: 'Teklif', icon: '✎' }
        : (findLink('hesaplar.php')
          ? { href: 'hesaplar.php', label: 'Kasa', icon: '▣' }
          : { href: 'magaza.php', label: 'Mağaza', icon: '▥' }));
    items.push(fourth);

    var nav = document.createElement('nav');
    nav.className = 'mobile-bottom-nav';
    nav.setAttribute('aria-label', 'Mobil hızlı menü');

    var activePath = currentPath();
    items.forEach(function (item) {
      var source = findLink(item.href);
      if (!source && item.href !== 'dashboard.php') return;

      var link = document.createElement('a');
      link.href = item.href;
      if (activePath === item.href || (item.href === 'cariler.php' && activePath === 'cari-detay.php')) {
        link.className = 'active';
      }
      link.innerHTML = '<span class="mobile-nav-icon" aria-hidden="true">' + item.icon + '</span><span>' + item.label + '</span>';
      nav.appendChild(link);
    });

    var menu = document.createElement('button');
    menu.type = 'button';
    menu.setAttribute('data-mobile-menu', '1');
    menu.setAttribute('aria-label', 'Tüm menüyü aç');
    menu.innerHTML = '<span class="mobile-nav-icon" aria-hidden="true">☰</span><span>Menü</span>';
    menu.addEventListener('click', function () {
      document.body.classList.toggle('sidebar-open');
      menu.classList.toggle('is-open', document.body.classList.contains('sidebar-open'));
    });
    nav.appendChild(menu);
    document.body.appendChild(nav);

    var backdrop = document.createElement('button');
    backdrop.type = 'button';
    backdrop.className = 'mobile-sidebar-backdrop';
    backdrop.setAttribute('aria-label', 'Menüyü kapat');
    backdrop.addEventListener('click', closeSidebar);
    document.body.appendChild(backdrop);

    sideNav.addEventListener('click', function (event) {
      if (event.target.closest('a')) closeSidebar();
    });
  }

  function prepareCardTable(table) {
    if (!table || table.classList.contains('mobile-card-table')) return;
    if (table.closest('.audit-table,.perm-matrix,.satis-modal,.toplu-evrak-panel,.magaza-satis-panel')) return;
    if (table.getAttribute('data-mobile-table') === 'scroll') return;

    var body = table.tBodies && table.tBodies[0];
    if (!body) return;
    if (body.querySelector('input,select,textarea,[contenteditable="true"]')) return;

    var headers = [];
    var headRow = table.tHead ? table.tHead.querySelector('tr') : null;
    if (headRow) {
      Array.prototype.forEach.call(headRow.children, function (cell) {
        headers.push((cell.textContent || '').replace(/\s+/g, ' ').trim());
      });
    }
    if (!headers.length) return;

    Array.prototype.forEach.call(body.rows, function (row) {
      Array.prototype.forEach.call(row.cells, function (cell, index) {
        cell.setAttribute('data-mobile-label', headers[index] || '');
      });
    });

    table.classList.add('mobile-card-table');
    var wrap = table.closest('.table-wrap');
    if (wrap) wrap.classList.add('mobile-card-wrap');
  }

  function prepareTables(root) {
    var scope = root && root.querySelectorAll ? root : document;
    Array.prototype.forEach.call(scope.querySelectorAll('.table-wrap table'), prepareCardTable);
  }

  function prepareStickyForms(root) {
    var scope = root && root.querySelectorAll ? root : document;
    Array.prototype.forEach.call(scope.querySelectorAll('form'), function (form) {
      if (form.classList.contains('mobile-sticky-form')) return;
      if (form.closest('tr,.filterbar,.quick-status-form,.mobile-cari-picker,.mobile-barcode-scanner')) return;

      var controls = form.querySelectorAll('input:not([type="hidden"]),select,textarea');
      if (controls.length < 2) return;

      var actions = form.querySelector(':scope > .form-actions') || form.querySelector('.form-actions');
      if (!actions) return;

      var primary = actions.querySelector('.btn-primary,button[type="submit"],input[type="submit"]');
      if (!primary) return;

      var text = String(primary.textContent || primary.value || '').toLocaleLowerCase('tr-TR');
      if (!/(kaydet|ekle|güncelle|uygula|oluştur|işle|onayla)/.test(text)) return;

      form.classList.add('mobile-sticky-form');
      actions.classList.add('mobile-sticky-actions');
    });
  }

  var picker = null;
  var pickerList = null;
  var pickerSearch = null;
  var activeCariSelect = null;

  function ensureCariPicker() {
    if (picker) return picker;

    picker = document.createElement('section');
    picker.className = 'mobile-cari-picker';
    picker.setAttribute('aria-hidden', 'true');
    picker.innerHTML =
      '<header class="mobile-picker-head">' +
        '<button type="button" data-picker-close aria-label="Kapat">‹</button>' +
        '<strong>Cari seç</strong>' +
      '</header>' +
      '<div class="mobile-picker-body">' +
        '<input class="mobile-picker-search" type="search" placeholder="Cari adına göre ara" autocomplete="off">' +
        '<div class="mobile-picker-list"></div>' +
      '</div>';
    document.body.appendChild(picker);

    pickerList = picker.querySelector('.mobile-picker-list');
    pickerSearch = picker.querySelector('.mobile-picker-search');

    picker.querySelector('[data-picker-close]').addEventListener('click', closeCariPicker);
    pickerSearch.addEventListener('input', renderCariOptions);
    return picker;
  }

  function closeCariPicker() {
    if (!picker) return;
    picker.classList.remove('open');
    picker.setAttribute('aria-hidden', 'true');
    setOverlayState(false);
    activeCariSelect = null;
  }

  function optionText(option) {
    return String(option ? option.textContent || '' : '').replace(/\s+/g, ' ').trim();
  }

  function renderCariOptions() {
    if (!activeCariSelect || !pickerList) return;
    var query = String(pickerSearch.value || '').trim().toLocaleLowerCase('tr-TR');
    var found = 0;
    pickerList.innerHTML = '';

    Array.prototype.forEach.call(activeCariSelect.options, function (option) {
      if (!option.value || option.disabled) return;
      var label = optionText(option);
      if (query && label.toLocaleLowerCase('tr-TR').indexOf(query) === -1) return;

      found += 1;
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'mobile-picker-option' + (option.selected ? ' active' : '');
      button.innerHTML = '<span>' + escapeHtml(label) + '</span><span>' + (option.selected ? '✓' : '›') + '</span>';
      button.addEventListener('click', function () {
        activeCariSelect.value = option.value;
        activeCariSelect.dispatchEvent(new Event('change', { bubbles: true }));
        syncCariTrigger(activeCariSelect);
        closeCariPicker();
      });
      pickerList.appendChild(button);
    });

    if (!found) {
      pickerList.innerHTML = '<div class="mobile-picker-empty">Aramana uygun cari bulunamadı.</div>';
    }
  }

  function openCariPicker(select) {
    ensureCariPicker();
    activeCariSelect = select;
    pickerSearch.value = '';
    renderCariOptions();
    picker.classList.add('open');
    picker.setAttribute('aria-hidden', 'false');
    setOverlayState(true);
    window.setTimeout(function () { pickerSearch.focus(); }, 60);
  }

  function syncCariTrigger(select) {
    var trigger = select.parentElement ? select.parentElement.querySelector('.mobile-cari-trigger') : null;
    if (!trigger) return;
    var selected = select.options[select.selectedIndex];
    var label = selected && selected.value ? optionText(selected) : 'Cari seç';
    trigger.querySelector('strong').textContent = label;
    trigger.querySelector('small').textContent = selected && selected.value ? 'Değiştirmek için dokun' : 'Arayarak hızlıca seç';
  }

  function prepareCariSelect(select) {
    if (!select || select.classList.contains('mobile-cari-ready')) return;
    if (select.closest('.mobile-cari-picker')) return;

    select.classList.add('mobile-cari-ready', 'mobile-native-cari-select');

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'mobile-cari-trigger';
    trigger.innerHTML = '<span><strong>Cari seç</strong><small>Arayarak hızlıca seç</small></span><span aria-hidden="true">⌕</span>';
    trigger.addEventListener('click', function () { openCariPicker(select); });

    select.insertAdjacentElement('afterend', trigger);
    select.addEventListener('change', function () { syncCariTrigger(select); });
    syncCariTrigger(select);
  }

  function prepareCariSelects(root) {
    var scope = root && root.querySelectorAll ? root : document;
    Array.prototype.forEach.call(scope.querySelectorAll('select[name="cari_id"]'), prepareCariSelect);
  }

  var scanner = null;
  var scannerVideo = null;
  var scannerStream = null;
  var scannerTimer = null;
  var scannerInput = null;

  function ensureScanner() {
    if (scanner) return scanner;
    scanner = document.createElement('section');
    scanner.className = 'mobile-barcode-scanner';
    scanner.setAttribute('aria-hidden', 'true');
    scanner.innerHTML =
      '<header class="mobile-scanner-head">' +
        '<button type="button" data-scanner-close aria-label="Kapat">×</button>' +
        '<strong>Barkodu kameraya göster</strong>' +
      '</header>' +
      '<div class="mobile-scanner-body">' +
        '<video playsinline muted></video>' +
        '<div class="mobile-scanner-guide" aria-hidden="true"></div>' +
        '<div class="mobile-scanner-note">Barkodu çerçevenin içine getir.</div>' +
      '</div>';
    document.body.appendChild(scanner);
    scannerVideo = scanner.querySelector('video');
    scanner.querySelector('[data-scanner-close]').addEventListener('click', closeScanner);
    return scanner;
  }

  function stopScannerStream() {
    if (scannerTimer) {
      window.clearTimeout(scannerTimer);
      scannerTimer = null;
    }
    if (scannerStream) {
      scannerStream.getTracks().forEach(function (track) { track.stop(); });
      scannerStream = null;
    }
    if (scannerVideo) {
      scannerVideo.srcObject = null;
    }
  }

  function closeScanner() {
    stopScannerStream();
    if (scanner) {
      scanner.classList.remove('open');
      scanner.setAttribute('aria-hidden', 'true');
    }
    setOverlayState(false);
    scannerInput = null;
  }

  function applyBarcode(value) {
    if (!scannerInput || !value) return;
    var target = scannerInput;
    target.value = value;
    target.dispatchEvent(new Event('input', { bubbles: true }));
    target.dispatchEvent(new Event('change', { bubbles: true }));
    closeScanner();
  }

  function scanFrame(detector) {
    if (!scannerStream || !scannerVideo || scannerVideo.readyState < 2) {
      scannerTimer = window.setTimeout(function () { scanFrame(detector); }, 220);
      return;
    }
    detector.detect(scannerVideo).then(function (codes) {
      if (codes && codes.length && codes[0].rawValue) {
        applyBarcode(codes[0].rawValue);
        return;
      }
      scannerTimer = window.setTimeout(function () { scanFrame(detector); }, 220);
    }).catch(function () {
      scannerTimer = window.setTimeout(function () { scanFrame(detector); }, 350);
    });
  }

  function openScanner(input) {
    if (!window.BarcodeDetector || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      window.alert('Bu telefondaki tarayıcı kamera ile barkod okumayı desteklemiyor. Barkodu elle yazabilirsin.');
      input.focus();
      return;
    }

    ensureScanner();
    scannerInput = input;
    scanner.classList.add('open');
    scanner.setAttribute('aria-hidden', 'false');
    setOverlayState(true);

    navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: 'environment' } },
      audio: false
    }).then(function (stream) {
      scannerStream = stream;
      scannerVideo.srcObject = stream;
      return scannerVideo.play();
    }).then(function () {
      var detector;
      try {
        detector = new window.BarcodeDetector({ formats: ['ean_13', 'ean_8', 'code_128', 'code_39', 'upc_a', 'upc_e'] });
      } catch (error) {
        detector = new window.BarcodeDetector();
      }
      scanFrame(detector);
    }).catch(function () {
      closeScanner();
      window.alert('Kamera açılamadı. Tarayıcı ayarlarından kamera izni verip tekrar deneyebilirsin.');
      input.focus();
    });
  }

  function prepareBarcodeInput(input) {
    if (!input || input.classList.contains('mobile-barcode-ready') || input.readOnly || input.disabled) return;
    if (input.closest('.mobile-barcode-scanner')) return;

    input.classList.add('mobile-barcode-ready');
    var parent = input.parentElement;
    if (!parent) return;

    var wrapper = document.createElement('span');
    wrapper.className = 'mobile-barcode-field';
    parent.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'mobile-barcode-button';
    button.setAttribute('aria-label', 'Kamerayla barkod oku');
    button.textContent = '▦';
    button.addEventListener('click', function () { openScanner(input); });
    wrapper.appendChild(button);
  }

  function prepareBarcodeInputs(root) {
    var scope = root && root.querySelectorAll ? root : document;
    var selector = 'input[name*="barcode" i],input[name*="barkod" i],input[id*="barcode" i],input[id*="barkod" i],[data-barcode-input]';
    try {
      Array.prototype.forEach.call(scope.querySelectorAll(selector), prepareBarcodeInput);
    } catch (error) {
      Array.prototype.forEach.call(scope.querySelectorAll('input'), function (input) {
        var key = ((input.name || '') + ' ' + (input.id || '')).toLocaleLowerCase('tr-TR');
        if (key.indexOf('barcode') !== -1 || key.indexOf('barkod') !== -1) prepareBarcodeInput(input);
      });
    }
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function prepareAll(root) {
    if (!isMobile()) return;
    prepareTables(root);
    prepareStickyForms(root);
    prepareCariSelects(root);
    prepareBarcodeInputs(root);
  }

  function start() {
    buildBottomNavigation();
    prepareAll(document);

    var observer = new MutationObserver(function (mutations) {
      if (!isMobile()) return;
      mutations.forEach(function (mutation) {
        Array.prototype.forEach.call(mutation.addedNodes || [], function (node) {
          if (node.nodeType === 1) prepareAll(node);
        });
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });

    document.addEventListener('click', function (event) {
      if (!isMobile()) return;
      if (document.body.classList.contains('sidebar-open') && !event.target.closest('.sidebar,.menu-toggle,[data-mobile-menu]')) {
        closeSidebar();
      }
    });

    if (mobileMedia && mobileMedia.addEventListener) {
      mobileMedia.addEventListener('change', function (event) {
        if (event.matches) prepareAll(document);
        else closeSidebar();
      });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
