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

  function addWarehouseDeleteButtons(){
    if (!/depo-cikis\.php/i.test(location.pathname)) return;
    var csrf = document.querySelector('input[name="csrf_token"], input[name="csrf"]');
    document.querySelectorAll('a[href^="depo-cikis.php?edit="]').forEach(function(editLink){
      var actions = editLink.closest('.wd-actions');
      if (!actions || actions.querySelector('.wd-delete-form')) return;
      var match = String(editLink.getAttribute('href') || '').match(/edit=(\d+)/);
      if (!match) return;
      var form = document.createElement('form');
      form.method = 'post';
      form.action = 'depo-cikis-sil.php';
      form.className = 'wd-delete-form';
      form.onsubmit = function(){ return window.confirm('Bu depo çıkış fişi silinsin mi? Bu işlem geri alınamaz.'); };
      if (csrf) {
        var token = document.createElement('input');
        token.type = 'hidden';
        token.name = csrf.name;
        token.value = csrf.value;
        form.appendChild(token);
      }
      var id = document.createElement('input');
      id.type = 'hidden';
      id.name = 'id';
      id.value = match[1];
      form.appendChild(id);
      var button = document.createElement('button');
      button.type = 'submit';
      button.textContent = 'Sil';
      button.style.color = '#b42318';
      button.style.borderColor = '#efb7b2';
      form.appendChild(button);
      actions.appendChild(form);
    });
  }

  function clearRestoredNewOffer(){
    if (!/teklif-ver\.php/i.test(location.pathname)) return;
    if (/[?&]edit=\d+/i.test(location.search)) return;
    var form = document.getElementById('offerForm');
    if (!form) return;

    var cari = form.querySelector('[name="cari_id"]');
    var fields = ['customer_name','customer_city','customer_phone','customer_tax_office','customer_tax_no','customer_address','note','term_text'];
    if (cari) cari.value = '';
    fields.forEach(function(name){
      var el = form.querySelector('[name="' + name + '"]');
      if (el) el.value = '';
    });
    form.querySelectorAll('[name="product_barcode[]"],[name="product_name[]"],[name="product_type[]"],[name="quantity[]"],[name="unit_price[]"]').forEach(function(el){ el.value = ''; });
    var discount = form.querySelector('[name="discount_enabled"]');
    var vat = form.querySelector('[name="vat_enabled"]');
    var discountRate = form.querySelector('[name="discount_rate"]');
    var vatRate = form.querySelector('[name="vat_rate"]');
    if (discount) discount.checked = false;
    if (vat) vat.checked = false;
    if (discountRate) discountRate.value = '0';
    if (vatRate) vatRate.value = '10';
    ['input','change'].forEach(function(type){ form.dispatchEvent(new Event(type, {bubbles:true})); });
  }

  function init(){
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
    addWarehouseDeleteButtons();
    clearRestoredNewOffer();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
  else init();
})();
