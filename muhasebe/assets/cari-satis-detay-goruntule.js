(function () {
  'use strict';

  var path = (window.location.pathname || '').split('/').pop();
  if (path !== 'cari-detay.php') return;

  var modal = null;
  var modalBody = null;
  var modalTitle = null;
  var activeRequest = 0;
  var moneyFormat = new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  var quantityFormat = new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatMoney(value) {
    return moneyFormat.format(Number(value || 0)) + ' TL';
  }

  function formatQuantity(value) {
    return quantityFormat.format(Number(value || 0)) + ' DZ';
  }

  function ensureStyle() {
    if (document.getElementById('cari-satis-detay-goruntule-style')) return;
    var style = document.createElement('style');
    style.id = 'cari-satis-detay-goruntule-style';
    style.textContent = [
      '.cari-satis-detay-button{display:inline-flex;align-items:center;gap:7px;border:1px solid #9fc9ad;border-radius:10px;background:#edf8f0;color:#15562f;padding:8px 11px;font:inherit;font-size:12px;font-weight:900;cursor:pointer;text-align:left}',
      '.cari-satis-detay-button:hover,.cari-satis-detay-button:focus{background:#dff2e4;border-color:#5e9e72;outline:2px solid rgba(55,130,80,.15);outline-offset:1px}',
      '.cari-satis-detay-button small{display:inline;color:#39704c;font-size:10px;font-weight:800}',
      '.cari-satis-view-backdrop{display:none;position:fixed;inset:0;z-index:10150;background:rgba(10,22,15,.66);padding:20px;overflow:auto}',
      '.cari-satis-view-backdrop.open{display:flex;align-items:flex-start;justify-content:center}',
      '.cari-satis-view-modal{width:min(1080px,100%);margin:auto;background:#fff;border-radius:20px;box-shadow:0 28px 90px rgba(0,0,0,.32);overflow:hidden}',
      '.cari-satis-view-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:19px 22px;background:linear-gradient(135deg,#102818,#23613c);color:#fff}',
      '.cari-satis-view-head h2{margin:0;color:#fff;font-size:22px}.cari-satis-view-head p{margin:5px 0 0;color:#dceee2;font-size:12px}',
      '.cari-satis-view-close{width:40px;height:40px;border:1px solid rgba(255,255,255,.38);border-radius:50%;background:rgba(255,255,255,.12);color:#fff;font-size:24px;cursor:pointer}',
      '.cari-satis-view-body{padding:20px 22px 22px}',
      '.cari-satis-view-loading,.cari-satis-view-error{padding:28px;text-align:center;font-weight:850;color:#526159}',
      '.cari-satis-view-error{color:#a53d35}',
      '.cari-satis-table-wrap{overflow:auto;border:1px solid #e0e8e2;border-radius:14px}',
      '.cari-satis-table{width:100%;border-collapse:collapse;min-width:760px}',
      '.cari-satis-table th{padding:10px 12px;background:#f1f6f2;color:#55665b;font-size:10px;text-transform:uppercase;letter-spacing:.04em;text-align:left}',
      '.cari-satis-table td{padding:12px;border-top:1px solid #e8eee9;vertical-align:top;font-size:12px}',
      '.cari-satis-table td.num,.cari-satis-table th.num{text-align:right;white-space:nowrap}',
      '.cari-satis-product{font-weight:900;color:#173c27}.cari-satis-barcode,.cari-satis-note{display:block;margin-top:3px;color:#718077;font-size:10px}',
      '.cari-satis-summary{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:10px;margin-top:14px}',
      '.cari-satis-summary article{padding:12px 14px;border:1px solid #dfe8e1;border-radius:12px;background:#f8fbf9}',
      '.cari-satis-summary span{display:block;color:#708077;font-size:10px;font-weight:800;text-transform:uppercase}.cari-satis-summary strong{display:block;margin-top:4px;color:#173c27;font-size:16px}',
      '.cari-satis-summary article.grand{background:#eaf6ed;border-color:#acd1b7}.cari-satis-summary article.grand strong{font-size:19px;color:#145c31}',
      '.cari-satis-view-actions{display:flex;justify-content:flex-end;margin-top:16px}.cari-satis-view-actions button{border:0;border-radius:10px;padding:10px 16px;background:#16482e;color:#fff;font-weight:900;cursor:pointer}',
      '@media(max-width:760px){.cari-satis-view-backdrop{padding:0}.cari-satis-view-modal{min-height:100%;border-radius:0;margin:0}.cari-satis-view-body{padding:14px}.cari-satis-summary{grid-template-columns:1fr 1fr}.cari-satis-detay-button{width:100%;justify-content:space-between;padding:10px 12px}.cari-satis-table{min-width:680px}}'
    ].join('');
    document.head.appendChild(style);
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  function ensureModal() {
    if (modal) return modal;
    ensureStyle();
    modal = document.createElement('section');
    modal.className = 'cari-satis-view-backdrop';
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = '' +
      '<article class="cari-satis-view-modal" role="dialog" aria-modal="true" aria-labelledby="cariSatisViewTitle">' +
        '<header class="cari-satis-view-head"><div><h2 id="cariSatisViewTitle">Satış ayrıntısı</h2><p>Ürün, düzine, birim fiyat ve satır toplamları</p></div><button type="button" class="cari-satis-view-close" aria-label="Kapat">×</button></header>' +
        '<div class="cari-satis-view-body"></div>' +
      '</article>';
    document.body.appendChild(modal);
    modalBody = modal.querySelector('.cari-satis-view-body');
    modalTitle = modal.querySelector('h2');
    modal.querySelector('.cari-satis-view-close').addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) { if (event.target === modal) closeModal(); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && modal.classList.contains('open')) closeModal(); });
    return modal;
  }

  function openModal(title) {
    ensureModal();
    modalTitle.textContent = title || 'Satış ayrıntısı';
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function renderSale(sale) {
    sale = sale || {};
    var items = Array.isArray(sale.items) ? sale.items : [];
    if (!items.length) {
      modalBody.innerHTML = '<div class="cari-satis-view-error">Bu satışa ait ürün kalemi bulunamadı.</div>';
      return;
    }

    var rows = items.map(function (item, index) {
      var lineTotal = item.line_total != null ? item.line_total : Number(item.quantity || 0) * Number(item.unit_price || 0);
      return '<tr>' +
        '<td>' + (index + 1) + '</td>' +
        '<td><span class="cari-satis-product">' + escapeHtml(item.name || item.product_name || '-') + '</span>' +
          '<span class="cari-satis-barcode">Barkod: ' + escapeHtml(item.barcode || item.product_barcode || '-') + '</span>' +
          (item.note || item.line_note ? '<span class="cari-satis-note">Not: ' + escapeHtml(item.note || item.line_note) + '</span>' : '') + '</td>' +
        '<td class="num"><strong>' + formatQuantity(item.quantity) + '</strong></td>' +
        '<td class="num">' + formatMoney(item.unit_price) + '</td>' +
        '<td class="num"><strong>' + formatMoney(lineTotal) + '</strong></td>' +
      '</tr>';
    }).join('');

    var discountText = Number(sale.discount_enabled || 0) === 1
      ? '−' + formatMoney(sale.discount_amount || 0) + ' (%' + moneyFormat.format(Number(sale.discount_rate || 0)) + ')'
      : '0,00 TL';
    var vatText = Number(sale.vat_enabled || 0) === 1
      ? formatMoney(sale.vat_amount || 0) + ' (%' + moneyFormat.format(Number(sale.vat_rate || 0)) + ')'
      : '0,00 TL';

    modalBody.innerHTML = '' +
      '<div class="cari-satis-table-wrap"><table class="cari-satis-table">' +
        '<thead><tr><th>No</th><th>Ürün</th><th class="num">Miktar (DZ)</th><th class="num">Birim fiyat / DZ</th><th class="num">Satır toplamı</th></tr></thead>' +
        '<tbody>' + rows + '</tbody>' +
      '</table></div>' +
      '<section class="cari-satis-summary">' +
        '<article><span>Ara toplam</span><strong>' + formatMoney(sale.subtotal || 0) + '</strong></article>' +
        '<article><span>İskonto</span><strong>' + discountText + '</strong></article>' +
        '<article><span>KDV</span><strong>' + vatText + '</strong></article>' +
        '<article class="grand"><span>Genel toplam</span><strong>' + formatMoney(sale.grand_total || 0) + '</strong></article>' +
      '</section>' +
      '<div class="cari-satis-view-actions"><button type="button" data-close-sale-view>Kapat</button></div>';
    modalBody.querySelector('[data-close-sale-view]').addEventListener('click', closeModal);
  }

  function fetchJson(url) {
    return fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(function (response) {
        if (!response.ok) throw new Error('Satış ayrıntısı alınamadı.');
        return response.json();
      });
  }

  function openMovementSale(movementId, title) {
    var requestId = ++activeRequest;
    openModal(title || 'Satış ayrıntısı');
    modalBody.innerHTML = '<div class="cari-satis-view-loading">Satış kalemleri yükleniyor…</div>';
    fetchJson('hareket-satis-veri.php?id=' + encodeURIComponent(movementId))
      .then(function (data) {
        if (requestId !== activeRequest) return;
        if (!data || !data.ok || !data.sale) throw new Error('Bu hareketin satış ayrıntısı bulunamadı.');
        renderSale(data.sale);
      })
      .catch(function (error) {
        if (requestId !== activeRequest) return;
        modalBody.innerHTML = '<div class="cari-satis-view-error">' + escapeHtml(error.message || 'Satış ayrıntısı açılamadı.') + '</div>';
      });
  }

  function movementRowsById() {
    var rows = {};
    var section = document.getElementById('hareketler');
    if (!section) return rows;
    section.querySelectorAll('tbody tr').forEach(function (row) {
      var edit = row.querySelector('a[href*="hareketler.php?edit="]');
      if (!edit) return;
      try {
        var id = new URL(edit.href, window.location.href).searchParams.get('edit');
        if (id) rows[id] = row;
      } catch (error) {}
    });
    return rows;
  }

  function decorate() {
    ensureStyle();
    var rowMap = movementRowsById();
    var ids = Object.keys(rowMap);
    if (!ids.length) return;

    fetchJson('hareket-satis-veri.php?ids=' + encodeURIComponent(ids.join(',')))
      .then(function (data) {
        if (!data || !data.ok) return;
        var summaries = data.summaries || {};
        Object.keys(summaries).forEach(function (id) {
          var row = rowMap[id];
          if (!row || row.getAttribute('data-sale-view-ready') === '1') return;
          var cell = row.cells && row.cells.length > 4 ? row.cells[4] : null;
          if (!cell) return;

          var summary = summaries[id] || {};
          var originalText = (cell.childNodes[0] && cell.childNodes[0].nodeType === 3 ? cell.childNodes[0].nodeValue : cell.textContent || '').trim();
          var button = document.createElement('button');
          button.type = 'button';
          button.className = 'cari-satis-detay-button';
          button.innerHTML = '<span>' + escapeHtml(originalText || 'Detaylı satış') + '</span><small>' + escapeHtml(String(summary.item_count || 0)) + ' kalem · ayrıntıyı aç ›</small>';
          button.addEventListener('click', function () {
            openMovementSale(id, 'Satış ayrıntısı · ' + String(summary.item_count || 0) + ' kalem');
          });

          while (cell.firstChild) cell.removeChild(cell.firstChild);
          cell.appendChild(button);
          row.setAttribute('data-sale-view-ready', '1');
        });
      })
      .catch(function () {});
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', decorate);
  else decorate();
})();