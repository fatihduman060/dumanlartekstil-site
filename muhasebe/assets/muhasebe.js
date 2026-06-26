document.addEventListener('click', function (event) {
  const toggle = event.target.closest('[data-menu-toggle]');
  if (toggle) document.body.classList.toggle('sidebar-open');
});

// V50: tahsilat/gelir/ödeme/gider dışındaki hareketlerde kasa-banka seçimi bilgi amaçlı kalsın.
document.addEventListener('change', function (event) {
  const select = event.target.closest('[data-cash-type]');
  if (!select) return;
  const form = select.closest('form');
  const account = form ? form.querySelector('select[name="account_id"]') : null;
  if (!account) return;
  const cashTypes = ['tahsilat', 'gelir', 'odeme', 'gider'];
  account.closest('label').style.opacity = cashTypes.includes(select.value) ? '1' : '.55';
});

// Kullanıcıya görünen dili ticari kullanıma göre sadeleştir.
// Veritabanı anahtarlarına dokunmaz; sadece ekrandaki metni düzenler.
(function normalizeBusinessLanguage() {
  const replacements = [
    ['Alınacak Çek', 'Alınan Çek'],
    ['Verilecek Çek', 'Verilen Çek'],
    ['Alınacak çek', 'Alınan çek'],
    ['Verilecek çek', 'Verilen çek'],
    ['alınacak çek', 'alınan çek'],
    ['verilecek çek', 'verilen çek'],
    ['Filtrede alınacak', 'Filtrede alınan çek'],
    ['Filtrede verilecek', 'Filtrede verilen çek'],
    ['Bu ay vadesi gelen alınacak çek', 'Bu ay vadesi gelen alınan çek'],
    ['Bu ay vadesi gelen verilecek çek', 'Bu ay vadesi gelen verilen çek'],
    ['Alınacak çek toplamı', 'Alınan çek toplamı'],
    ['Verilecek çek toplamı', 'Verilen çek toplamı'],
    ['alınacak çek toplamı', 'alınan çek toplamı'],
    ['verilecek çek toplamı', 'verilen çek toplamı'],
    ['Alınacak çekleri', 'Alınan çekleri'],
    ['Verilecek çekleri', 'Verilen çekleri'],
    ['alınacak çekleri', 'alınan çekleri'],
    ['verilecek çekleri', 'verilen çekleri'],
    ['Verecek', 'Alış / Borç'],
    ['verecek', 'alış / borç'],
    ['Alacak', 'Satış / Alacak'],
    ['Özel Satış / Alacak', 'Özel Alacak'],
    ['Tahsilat', 'Tahsilat'],
    ['Gelir', 'Diğer Gelir'],
    ['Gider', 'Diğer Gider'],
    ['Tip', 'İşlem türü'],
    ['Hareket listesi', 'İşlem listesi'],
    ['Hareket bulunamadı.', 'İşlem bulunamadı.'],
    ['Hareket ekle', 'İşlem ekle'],
    ['Yeni hareket', 'Yeni işlem'],
    ['Hareket düzenle', 'İşlem düzenle'],
    ['Hareket eklendi.', 'İşlem eklendi.'],
    ['Hareket güncellendi.', 'İşlem güncellendi.'],
    ['Hareket iptal edildi.', 'İşlem iptal edildi.'],
    ['Ödeme/Kasa hesabı', 'Kasa/Banka hesabı'],
    ['Belge / fatura görseli', 'Belge / Evrak'],
    ['Açıklama/cari/hesap/belge ara', 'Cari, açıklama, hesap veya belge ara'],
    ['Excel CSV indir', 'CSV indir'],
    ['Net', 'Durum']
  ];

  const skipTags = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEXTAREA']);

  function replaceText(text) {
    let next = text;
    for (const [from, to] of replacements) {
      next = next.split(from).join(to);
    }
    return next;
  }

  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
    acceptNode(node) {
      if (!node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
      const parent = node.parentElement;
      if (!parent || skipTags.has(parent.tagName)) return NodeFilter.FILTER_REJECT;
      return NodeFilter.FILTER_ACCEPT;
    }
  });

  const nodes = [];
  while (walker.nextNode()) nodes.push(walker.currentNode);
  nodes.forEach((node) => {
    const next = replaceText(node.nodeValue);
    if (next !== node.nodeValue) node.nodeValue = next;
  });
})();

// Cari listesinde sadece rakam göstermek yerine durum etiketini de ekle.
(function enrichCariStatus() {
  const title = document.querySelector('.topbar h1');
  if (!title || title.textContent.trim() !== 'Cariler') return;

  const rows = document.querySelectorAll('.table-wrap tbody tr');
  rows.forEach((row) => {
    const amountCell = row.querySelector('td.right');
    if (!amountCell || amountCell.querySelector('.cari-status-note')) return;

    const strong = amountCell.querySelector('strong');
    if (!strong) return;

    const text = strong.textContent.replace(/\s+/g, ' ').trim();
    const isNegative = text.startsWith('-');
    const numeric = text.replace(/[^0-9,.-]/g, '').replace(/\./g, '').replace(',', '.');
    const value = Math.abs(parseFloat(numeric || '0'));

    const note = document.createElement('small');
    note.className = 'cari-status-note';
    if (!value) {
      note.textContent = 'Kapalı';
      note.classList.add('muted');
    } else if (isNegative) {
      note.textContent = 'Biz borçluyuz';
      note.classList.add('text-danger');
    } else {
      note.textContent = 'Biz alacaklıyız';
      note.classList.add('text-success');
    }
    amountCell.appendChild(note);
  });
})();

// Kullanıcılar ekranına süper yönetici kutusunu ekle.
(function enhanceSuperAdminUserControls() {
  const title = document.querySelector('.topbar h1');
  if (!title || title.textContent.trim() !== 'Kullanıcılar') return;

  const superIds = Array.isArray(window.BITKE_SUPER_ADMIN_IDS) ? window.BITKE_SUPER_ADMIN_IDS.map(Number) : [];

  document.querySelectorAll('select[name="role"]').forEach((roleSelect) => {
    if (roleSelect.parentElement && roleSelect.parentElement.querySelector('.super-admin-control')) return;

    const row = roleSelect.closest('tr');
    const form = roleSelect.closest('form') || (row ? row.querySelector('form') : null);
    const idInput = (row ? row.querySelector('input[name="id"]') : null) || (form ? form.querySelector('input[name="id"]') : null);
    const userId = Number(idInput ? idInput.value : 0);

    const label = document.createElement('label');
    label.className = 'check tiny super-admin-control';

    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.name = 'is_super_admin';
    checkbox.value = '1';
    checkbox.checked = userId > 0 && superIds.includes(userId);

    label.appendChild(checkbox);
    label.appendChild(document.createTextNode(' ⭐ Süper yönetici'));
    roleSelect.insertAdjacentElement('afterend', label);
  });

  document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    const action = form.querySelector('input[name="action"][value="update"]');
    if (!action) return;
    const row = form.closest('tr') || form.parentElement?.closest('tr');
    const visualCheckbox = row ? row.querySelector('.super-admin-control input[type="checkbox"]') : form.querySelector('.super-admin-control input[type="checkbox"]');
    if (!visualCheckbox) return;

    let hidden = form.querySelector('input[data-super-admin-hidden="1"]');
    if (!hidden) {
      hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'is_super_admin';
      hidden.dataset.superAdminHidden = '1';
      form.appendChild(hidden);
    }
    hidden.value = visualCheckbox.checked ? '1' : '0';
  }, true);
})();
