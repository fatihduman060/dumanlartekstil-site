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
    'Tahsilat Makbuzu': 'tahsilat-makbuzu',
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

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var existing = document.querySelector('script[src="' + src + '"]');
      if (existing) {
        existing.addEventListener('load', resolve, { once: true });
        if (window.html2canvas) resolve();
        return;
      }
      var s = document.createElement('script');
      s.src = src;
      s.onload = resolve;
      s.onerror = reject;
      document.head.appendChild(s);
    });
  }

  function safeFileName(text) {
    return String(text || 'siparis-fisi')
      .toLowerCase()
      .replace(/[ç]/g, 'c').replace(/[ğ]/g, 'g').replace(/[ı]/g, 'i').replace(/[ö]/g, 'o').replace(/[ş]/g, 's').replace(/[ü]/g, 'u')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '') || 'siparis-fisi';
  }

  function canvasToBlob(canvas) {
    return new Promise(function (resolve) {
      canvas.toBlob(function (blob) { resolve(blob); }, 'image/png', 0.95);
    });
  }

  function downloadBlob(blob, fileName) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = fileName;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
  }

  async function shareOfferImage(pdfUrl, offerNo, customer, button) {
    var oldText = button.textContent;
    var iframe;
    try {
      button.disabled = true;
      button.textContent = 'Görüntü hazırlanıyor...';
      if (!window.html2canvas) {
        await loadScript('https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js');
      }
      if (!window.html2canvas) throw new Error('Görüntü oluşturma kütüphanesi yüklenemedi.');

      iframe = document.createElement('iframe');
      iframe.src = pdfUrl;
      iframe.setAttribute('aria-hidden', 'true');
      iframe.style.position = 'fixed';
      iframe.style.left = '-1200px';
      iframe.style.top = '0';
      iframe.style.width = '900px';
      iframe.style.height = '1300px';
      iframe.style.opacity = '0.01';
      iframe.style.pointerEvents = 'none';
      iframe.style.border = '0';
      document.body.appendChild(iframe);

      await new Promise(function (resolve, reject) {
        iframe.onload = resolve;
        iframe.onerror = reject;
        setTimeout(resolve, 4000);
      });

      var doc = iframe.contentDocument || iframe.contentWindow.document;
      if (doc.fonts && doc.fonts.ready) {
        try { await doc.fonts.ready; } catch (e) {}
      }
      await new Promise(function (resolve) { setTimeout(resolve, 600); });
      var page = doc.querySelector('.page');
      if (!page) throw new Error('Belge görüntüsü bulunamadı.');

      button.textContent = 'Paylaşım hazırlanıyor...';
      var canvas = await window.html2canvas(page, {
        backgroundColor: '#ffffff',
        scale: Math.min(2, window.devicePixelRatio || 2),
        useCORS: true,
        logging: false
      });
      var blob = await canvasToBlob(canvas);
      if (!blob) throw new Error('Görüntü dosyası oluşturulamadı.');

      var baseName = safeFileName((offerNo ? offerNo + '-' : '') + (customer || 'siparis-fisi'));
      var fileName = baseName + '.png';
      var file = new File([blob], fileName, { type: 'image/png' });
      var shareText = 'Merhaba, ' + (customer ? customer + ' için ' : '') + (offerNo ? offerNo + ' numaralı ' : '') + 'sipariş/teklif belgesini iletiyorum.';

      if (navigator.canShare && navigator.canShare({ files: [file] }) && navigator.share) {
        button.textContent = 'WhatsApp açılıyor...';
        await navigator.share({ files: [file], title: fileName, text: shareText });
      } else {
        downloadBlob(blob, fileName);
        alert('Cihaz bu ekrandan dosyayı doğrudan WhatsApp’a aktarmayı desteklemedi. Belge görüntüsü indirildi; WhatsApp’ta dosya/resim olarak ekleyebilirsin.');
      }
    } catch (err) {
      alert('PDF görüntüsü hazırlanamadı. PDF butonundan belgeyi açıp paylaşmayı deneyebilirsin. Hata: ' + (err && err.message ? err.message : err));
    } finally {
      if (iframe && iframe.parentNode) iframe.parentNode.removeChild(iframe);
      button.disabled = false;
      button.textContent = oldText;
    }
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
      var wa = document.createElement('button');
      wa.type = 'button';
      wa.className = 'whatsapp-offer-link';
      wa.textContent = 'WhatsApp ile ilet';
      wa.addEventListener('click', function () { shareOfferImage(pdfUrl, offerNo, customer, wa); });
      pdfLink.insertAdjacentElement('afterend', wa);
    });
  }

  function ciCsrfToken(){ return document.querySelector('input[name="csrf_token"]')?.value || ''; }
  function ciBack(){ return location.pathname.split('/').pop() + location.search; }
  function ciSubmit(sourceType, id){
    var token = ciCsrfToken();
    if (!id || !token) return;
    if (!confirm('Bu belge cariye işlenecek. Emin misin?')) return;
    var form = document.createElement('form');
    form.method = 'post';
    form.action = 'cariye-isle.php';
    form.style.display = 'none';
    var data = {csrf_token: token, source_type: sourceType, id: id, back: ciBack()};
    Object.keys(data).forEach(function(key){
      var input = document.createElement('input');
      input.type = 'hidden'; input.name = key; input.value = data[key]; form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
  }
  function ciIdFromHref(href){
    try { var u = new URL(href, location.href); return u.searchParams.get('id') || u.searchParams.get('edit') || ''; }
    catch(e){ return ''; }
  }
  function ciButton(sourceType, id){
    if (!id || !ciCsrfToken()) return null;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'cariye-isle-btn';
    btn.textContent = 'Cariye İşle';
    btn.addEventListener('click', function(){ ciSubmit(sourceType, id); });
    return btn;
  }
  function ciAddStyles(){
    if (document.getElementById('cariye-isle-style')) return;
    var st = document.createElement('style');
    st.id = 'cariye-isle-style';
    st.textContent = '.cariye-isle-btn{border:1px solid #c49a4f!important;background:#fff8e8!important;color:#7a541d!important;border-radius:999px!important;padding:7px 10px!important;font-weight:900!important;cursor:pointer!important}.offer-actions .cariye-isle-btn,.receipt-actions .cariye-isle-btn{min-height:42px!important;padding:9px 16px!important}.cariye-isle-btn:hover{background:#ffe8ae!important}';
    document.head.appendChild(st);
  }
  function ciEnhance(){
    if (slug !== 'teklif-ver' && slug !== 'tahsilat-makbuzu') return;
    ciAddStyles();
    document.querySelectorAll('.saved-actions').forEach(function(actions){
      if (actions.querySelector('.cariye-isle-btn')) return;
      var offerPdf = actions.querySelector('a[href*="teklif-yazdir.php?id="]');
      var receiptPdf = actions.querySelector('a[href*="tahsilat-yazdir.php?id="]');
      var source = offerPdf ? 'offer' : (receiptPdf ? 'tahsilat' : '');
      var id = offerPdf ? ciIdFromHref(offerPdf.href) : (receiptPdf ? ciIdFromHref(receiptPdf.href) : '');
      var btn = ciButton(source, id);
      if (btn) actions.appendChild(btn);
    });
    var editId = new URLSearchParams(location.search).get('edit');
    if (editId) {
      var isOffer = slug === 'teklif-ver';
      var actions = document.querySelector(isOffer ? '.offer-actions' : '.receipt-actions');
      if (actions && !actions.querySelector('.cariye-isle-btn')) {
        var btn = ciButton(isOffer ? 'offer' : 'tahsilat', editId);
        if (btn) actions.appendChild(btn);
      }
    }
  }
  ciEnhance();

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
