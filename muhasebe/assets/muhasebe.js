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

// Kullanıcıya görünen çek dilini ticari kullanıma göre sadeleştir.
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
    ['verilecek çekleri', 'verilen çekleri']
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
