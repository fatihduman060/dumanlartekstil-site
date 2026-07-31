(function(){
  function formatAmount(value, currency){
    var number = Number(value || 0);
    try {
      return new Intl.NumberFormat('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}).format(number) + ' ' + currency;
    } catch(e) {
      return number.toFixed(2).replace('.', ',') + ' ' + currency;
    }
  }

  function updateCell(cell, balances){
    if (!cell || !balances || !balances.length) return;
    var visible = balances.filter(function(item){ return Math.abs(Number(item.net || 0)) >= 0.005 || item.currency === 'TL'; });
    if (!visible.length) visible = [{currency:'TL', net:0}];
    cell.innerHTML = visible.map(function(item){
      var net = Number(item.net || 0);
      var cls = net >= 0 ? 'text-success' : 'text-danger';
      var note = net >= 0 ? 'Alacaklı' : 'Biz borçluyuz';
      return '<strong class="' + cls + '" style="display:block;line-height:1.15">' + formatAmount(net, item.currency || 'TL') + '</strong><small style="display:block;margin-bottom:3px">' + note + '</small>';
    }).join('');
  }

  function parseMoney(text){
    var value = String(text || '').replace(/\s/g, '').replace(/TL/gi, '').replace(/\./g, '').replace(',', '.').replace(/[^0-9.-]/g, '');
    var number = parseFloat(value);
    return Number.isFinite(number) ? number : 0;
  }

  function normalizeCariDetailCards(){
    if (!/cari-detay\.php/i.test(location.pathname)) return;
    var grid = document.querySelector('#ozet .cari-rontgen-grid');
    if (!grid) return;
    var cards = grid.querySelectorAll(':scope > .stat-card');
    if (cards.length < 3) return;
    var balanceStrong = cards[0].querySelector('strong');
    var net = parseMoney(balanceStrong ? balanceStrong.textContent : '0');
    var receivable = Math.max(net, 0);
    var payable = Math.max(-net, 0);
    var receivableLabel = cards[1].querySelector('span');
    var receivableStrong = cards[1].querySelector('strong');
    var receivableNote = cards[1].querySelector('small');
    var payableLabel = cards[2].querySelector('span');
    var payableStrong = cards[2].querySelector('strong');
    var payableNote = cards[2].querySelector('small');
    if (receivableLabel) receivableLabel.textContent = 'Kalan net alacak';
    if (receivableStrong) {
      receivableStrong.textContent = formatAmount(receivable, 'TL');
      receivableStrong.classList.remove('text-danger');
      receivableStrong.classList.add('text-success');
    }
    if (receivableNote) receivableNote.textContent = 'Tüm alacak, tahsilat, borç ve ödemeler mahsup edildi';
    if (payableLabel) payableLabel.textContent = 'Kalan net borç';
    if (payableStrong) {
      payableStrong.textContent = formatAmount(payable, 'TL');
      payableStrong.classList.remove('text-success', 'text-danger');
      payableStrong.classList.add(payable > 0 ? 'text-danger' : 'text-success');
    }
    if (payableNote) payableNote.textContent = 'Tüm alacak, tahsilat, borç ve ödemeler mahsup edildi';
  }

  function loadCariSaleViewer(){
    if (!/cari-detay\.php/i.test(location.pathname)) return;
    if (document.querySelector('script[data-cari-satis-viewer]')) return;
    var script = document.createElement('script');
    script.src = 'assets/cari-satis-detay-goruntule.js?v=1251bd8c';
    script.setAttribute('data-cari-satis-viewer', '1');
    document.head.appendChild(script);
  }

  function addMenuLink(href, label, icon){
    var nav = document.querySelector('.side-nav');
    if (!nav || nav.querySelector('a[href="' + href + '"]')) return;
    var link = document.createElement('a');
    link.href = href;
    link.innerHTML = '<span class="nav-ico">' + icon + '</span><span>' + label + '</span>';
    if (location.pathname.slice(-href.length) === href) link.className = 'active';
    nav.appendChild(link);
  }

  function reorderMenu(){
    var nav = document.querySelector('.side-nav');
    if (!nav) return;
    var order = ['dashboard.php','cariler.php','faturalar.php','hesaplar.php','uretim-takibi.php','magaza.php','vergi-odemeleri.php','kart-ekstre-takibi.php','maaslar.php','cekler.php','hesap-dokumleri.php','teklif-ver.php','tahsilat-makbuzu.php','sirket-evraklari.php','ozel-alacaklar.php','hareketler.php','belgeler.php','kategoriler.php','raporlar.php','kullanicilar.php'];
    var links = Array.prototype.slice.call(nav.querySelectorAll(':scope > a'));
    var used = [];
    order.forEach(function(href){
      var link = links.find(function(item){ return String(item.getAttribute('href') || '').split('?')[0] === href; });
      if (link) { nav.appendChild(link); used.push(link); }
    });
    links.forEach(function(link){ if (used.indexOf(link) === -1) nav.appendChild(link); });
  }

  function loadProductionQuickEntry(){
    if (!/uretim-takibi\.php/i.test(location.pathname)) return;
    if (document.querySelector('script[data-production-quick-entry]')) return;
    var script = document.createElement('script');
    script.src = 'assets/uretim-hizli-giris.js?v=b36cedd5';
    script.setAttribute('data-production-quick-entry', '1');
    script.onload = function(){
      if (document.querySelector('script[data-shift-separate-save]')) return;
      var separate = document.createElement('script');
      separate.src = 'assets/uretim-vardiya-ayri-kaydet.js?v=d1aa9fdf';
      separate.setAttribute('data-shift-separate-save', '1');
      document.head.appendChild(separate);
    };
    document.head.appendChild(script);
  }

  function init(){
    addMenuLink('uretim-takibi.php', 'Üretim Takibi', '⚙');
    addMenuLink('kart-ekstre-takibi.php', 'Kart Ekstre Takibi', '💳');
    reorderMenu();

    if (/cariler\.php/i.test(location.pathname)) {
      fetch('cari-doviz-bakiye.php', {credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(data){
          if (!data || !data.ok || !data.balances) return;
          document.querySelectorAll('a[href^="cari-detay.php?id="]').forEach(function(link){
            var match = String(link.getAttribute('href') || '').match(/id=(\d+)/);
            if (!match) return;
            var tr = link.closest('tr');
            if (!tr) return;
            updateCell(tr.querySelector('td.right'), data.balances[match[1]] || [{currency:'TL', net:0}]);
          });
        })
        .catch(function(){});
    }
    normalizeCariDetailCards();
    loadCariSaleViewer();
    loadProductionQuickEntry();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
  else init();
})();
