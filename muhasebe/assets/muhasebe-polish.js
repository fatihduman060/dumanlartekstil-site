(function () {
  var titleEl = document.querySelector('.topbar h1');
  var title = titleEl ? titleEl.textContent.trim() : '';
  var map = {
    'Genel Bakış': 'genel-bakis',
    'Cariler': 'cariler',
    'Özel Alacak': 'ozel-alacak',
    'Hareketler': 'hareketler',
    'Kasa / Banka': 'kasa-banka',
    'Hesap Dökümleri': 'hesap-dokumleri',
    'Çekler': 'cekler',
    'Belgeler': 'belgeler',
    'Şirket Evrakları': 'sirket-evraklari',
    'Teklif Ver': 'teklif-ver',
    'Kategoriler': 'kategoriler',
    'Raporlar': 'raporlar',
    'Hesabım': 'hesabim',
    'Yedekleme': 'yedekleme',
    'Kullanıcılar': 'kullanicilar',
    'Loglar': 'loglar'
  };
  var slug = map[title] || title.toLowerCase().replace(/[^a-z0-9ğüşöçıİĞÜŞÖÇ]+/g, '-').replace(/^-+|-+$/g, '');
  if (slug) document.body.classList.add('panel-' + slug);

  var nav = document.querySelector('.side-nav');
  if (nav && !nav.querySelector('a[href="sirket-evraklari.php"]')) {
    var docsLink = nav.querySelector('a[href="belgeler.php"]');
    var link = document.createElement('a');
    link.href = 'sirket-evraklari.php';
    if (slug === 'sirket-evraklari') link.className = 'active';
    link.innerHTML = '<span class="nav-ico">▧</span><span>Şirket Evrakları</span>';
    if (docsLink) docsLink.insertAdjacentElement('afterend', link);
    else nav.appendChild(link);
  }

  if (slug && slug !== 'genel-bakis') {
    document.querySelectorAll('.table-wrap table').forEach(function (table) {
      var headers = Array.prototype.slice.call(table.querySelectorAll('thead th')).map(function (th) {
        return th.textContent.replace(/\s+/g, ' ').trim();
      });
      table.querySelectorAll('tbody tr').forEach(function (row) {
        Array.prototype.slice.call(row.children).forEach(function (cell, index) {
          if (cell.tagName !== 'TD') return;
          if (!cell.getAttribute('data-label') && headers[index]) cell.setAttribute('data-label', headers[index]);
        });
      });
    });
  }

  if (slug === 'cekler') {
    document.querySelectorAll('.row-actions a[href^="cekler.php?edit="]').forEach(function (editLink) {
      var match = editLink.getAttribute('href').match(/edit=([0-9]+)/);
      if (!match) return;
      var actions = editLink.closest('.row-actions');
      if (!actions || actions.querySelector('.check-extra-doc-link')) return;
      var docLink = document.createElement('a');
      docLink.href = 'cek-ek-belge.php?id=' + match[1];
      docLink.className = 'check-extra-doc-link';
      docLink.textContent = 'Ek belge';
      editLink.insertAdjacentElement('afterend', docLink);
    });
  }

  if (slug === 'teklif-ver') {
    document.querySelectorAll('.saved-actions a[href^="teklif-yazdir.php?id="]').forEach(function (pdfLink) {
      var actions = pdfLink.closest('.saved-actions');
      if (!actions || actions.querySelector('.whatsapp-offer-link')) return;
      var row = pdfLink.closest('tr');
      var cells = row ? row.children : [];
      var dateNoCell = cells && cells[0] ? cells[0] : null;
      var customerCell = cells && cells[1] ? cells[1] : null;
      var offerNoEl = dateNoCell ? dateNoCell.querySelector('small') : null;
      var customerEl = customerCell ? customerCell.querySelector('strong') : null;
      var offerNo = offerNoEl ? offerNoEl.textContent.trim() : '';
      var customer = customerEl ? customerEl.textContent.trim() : '';
      var pdfUrl = new URL(pdfLink.getAttribute('href'), window.location.href).href;
      var message = 'Merhaba, ' + (customer ? customer + ' için ' : '') + (offerNo ? offerNo + ' numaralı ' : '') + 'teklif/sipariş belgesini iletiyorum: ' + pdfUrl;
      var wa = document.createElement('a');
      wa.href = 'https://wa.me/?text=' + encodeURIComponent(message);
      wa.target = '_blank';
      wa.rel = 'noopener';
      wa.className = 'whatsapp-offer-link';
      wa.textContent = 'WhatsApp ile ilet';
      pdfLink.insertAdjacentElement('afterend', wa);
    });
  }

  document.querySelectorAll('form.filterbar').forEach(function (form) {
    if (form.querySelector('.filter-reset-link')) return;
    var hasQuery = new URLSearchParams(window.location.search).toString().length > 0;
    if (!hasQuery) return;
    var a = document.createElement('a');
    a.href = window.location.pathname.split('/').pop() || window.location.pathname;
    a.className = 'filter-reset-link';
    a.textContent = 'Filtreyi temizle';
    form.appendChild(a);
  });

  if (slug === 'kasa-banka') {
    document.querySelectorAll('.table-wrap td small').forEach(function (small) {
      var iban = small.textContent.replace(/\s+/g, ' ').trim();
      if (!/^TR[0-9A-Z ]{10,}$/i.test(iban)) return;
      if (small.parentElement && small.parentElement.querySelector('.iban-copy-btn')) return;
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'iban-copy-btn';
      btn.textContent = 'IBAN kopyala';
      btn.addEventListener('click', function () {
        var plain = iban.replace(/\s+/g, '');
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(plain).then(function () {
            btn.textContent = 'Kopyalandı';
            setTimeout(function () { btn.textContent = 'IBAN kopyala'; }, 1400);
          });
        }
      });
      small.insertAdjacentElement('afterend', btn);
    });
  }
})();
