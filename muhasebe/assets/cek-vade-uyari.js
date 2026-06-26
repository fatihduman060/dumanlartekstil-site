(function () {
  var title = document.querySelector('.topbar h1')?.textContent.trim() || '';
  if (title !== 'Çekler') return;

  var table = document.querySelector('.check-table');
  var summary = document.querySelector('.checks-summary');
  var tabs = document.querySelector('.check-direction-tabs');
  if (!table || !summary) return;

  var style = document.createElement('style');
  style.textContent = `
    .due-warning-panel{background:#fff;border:1px solid #ead7a7;border-radius:20px;box-shadow:0 14px 34px rgba(126,84,11,.08);overflow:hidden}
    .due-warning-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 16px;background:linear-gradient(135deg,#fff8e8,#fff)}
    .due-warning-head h3{margin:0;color:#102818;font-size:18px}.due-warning-head small{display:block;color:#8a6a26;font-weight:800;margin-top:3px}
    .due-warning-chips{display:flex;gap:8px;flex-wrap:wrap}.due-warning-chips a{border-radius:999px;padding:8px 11px;border:1px solid #e5dccf;background:#fff;color:#16482e;text-decoration:none;font-size:12px;font-weight:900}
    .due-warning-list{display:grid;gap:8px;padding:12px 14px}.due-warning-item{display:grid;grid-template-columns:120px 1fr auto;gap:10px;align-items:center;border:1px solid #efe5d6;border-radius:15px;padding:10px 12px;background:#fff}
    .due-warning-item strong{display:block;color:#102818}.due-warning-item span{display:block;color:#776b5c;font-size:12px;margin-top:2px}.due-warning-tag{display:inline-flex!important;width:max-content;border-radius:999px;padding:5px 9px;font-size:11px!important;font-weight:950;background:#fff3d5;color:#925900}
    .due-warning-tag.danger{background:#ffe7e2;color:#b64242}.due-warning-tag.today{background:#e8f2ff;color:#2459c7}.due-warning-go{border:0;border-radius:999px;padding:8px 12px;background:#16482e;color:#fff;font-weight:900;cursor:pointer}.due-row-focus td{outline:2px solid #d6a940;outline-offset:-3px;animation:duePulse 1.4s ease-in-out 2}@keyframes duePulse{0%{filter:brightness(1)}50%{filter:brightness(.93)}100%{filter:brightness(1)}}
    .checks-summary article.due-clickable{cursor:pointer;transition:transform .15s ease, box-shadow .15s ease}.checks-summary article.due-clickable:hover{transform:translateY(-2px);box-shadow:0 16px 34px rgba(126,84,11,.12)}
    .checks-summary article.due-clickable::after{content:'Tıkla · Detayları göster';display:block;margin-top:7px;color:#8a6a26;font-size:11px;font-weight:900}
    @media(max-width:760px){.due-warning-head{display:block}.due-warning-chips{margin-top:10px}.due-warning-item{grid-template-columns:1fr}.due-warning-go{width:100%}}
  `;
  document.head.appendChild(style);

  function currentDirection() {
    var params = new URLSearchParams(window.location.search);
    return params.get('direction') === 'verilecek' ? 'verilecek' : 'alinacak';
  }

  function urlWithDueFilter(filter) {
    var next = new URL(window.location.href);
    next.searchParams.set('direction', currentDirection());
    next.searchParams.set('due_filter', filter);
    next.searchParams.delete('edit');
    return next.pathname + '?' + next.searchParams.toString();
  }

  function classifyDue(text) {
    if (/gecikti/i.test(text)) return 'overdue';
    if (/Bugün vade/i.test(text)) return 'today';
    var m = text.match(/(\d+)\s+gün kaldı/i);
    if (m && Number(m[1]) <= 7) return 'week';
    if (/Yarın vade/i.test(text)) return 'week';
    return '';
  }

  function labelFor(type, dueText) {
    if (type === 'overdue') return dueText || 'Geciken';
    if (type === 'today') return 'Bugün vade';
    return dueText || '7 gün içinde';
  }

  var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
  var warnings = [];
  rows.forEach(function (row, index) {
    var cells = row.querySelectorAll('td');
    if (cells.length < 7) return;
    var dueCell = cells[2];
    var statusCell = cells[4];
    var dueText = dueCell.textContent.replace(/\s+/g, ' ').trim();
    var statusText = statusCell.textContent.replace(/\s+/g, ' ').trim();
    if (/Tahsil edildi|Ödendi|Ciro edildi|İade|Karşılıksız|Protestolu|İptal/i.test(statusText)) return;
    var type = classifyDue(dueText);
    if (!type) return;
    var rowId = 'cek-vade-row-' + index;
    row.id = rowId;
    row.classList.add('due-warning-row');
    warnings.push({
      type: type,
      rowId: rowId,
      cari: (cells[0].querySelector('a') || cells[0].querySelector('span') || cells[0]).textContent.replace(/\s+/g, ' ').trim(),
      bank: (cells[1].querySelector('b') || cells[1]).textContent.replace(/\s+/g, ' ').trim(),
      due: (dueCell.querySelector('b') || dueCell).textContent.replace(/\s+/g, ' ').trim(),
      dueText: labelFor(type, dueText),
      amount: (cells[3].querySelector('b') || cells[3]).textContent.replace(/\s+/g, ' ').trim(),
      collection: (statusCell.querySelector('.collection-bank') || statusCell.querySelector('small') || statusCell).textContent.replace(/\s+/g, ' ').trim()
    });
  });

  var dueCard = Array.prototype.slice.call(summary.querySelectorAll('article')).find(function (card) {
    return /Vade uyarısı/i.test(card.textContent);
  });
  if (dueCard) {
    dueCard.classList.add('due-clickable');
    dueCard.setAttribute('title', 'Vade uyarı detaylarını göster');
    dueCard.addEventListener('click', function () {
      var panel = document.querySelector('.due-warning-panel');
      if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      else window.location.href = urlWithDueFilter('week');
    });
  }

  if (!warnings.length) return;

  var counts = warnings.reduce(function (acc, item) {
    acc[item.type] = (acc[item.type] || 0) + 1;
    return acc;
  }, {});

  var panel = document.createElement('section');
  panel.className = 'due-warning-panel';
  panel.innerHTML = `
    <div class="due-warning-head">
      <div>
        <h3>Vade uyarıları</h3>
        <small>${warnings.length} çek dikkat istiyor · geciken, bugün vadeli ve 7 gün içindeki çekler</small>
      </div>
      <div class="due-warning-chips">
        <a href="${urlWithDueFilter('overdue')}">Geciken: ${counts.overdue || 0}</a>
        <a href="${urlWithDueFilter('today')}">Bugün: ${counts.today || 0}</a>
        <a href="${urlWithDueFilter('week')}">7 gün: ${counts.week || 0}</a>
      </div>
    </div>
    <div class="due-warning-list"></div>
  `;

  var list = panel.querySelector('.due-warning-list');
  warnings.slice(0, 8).forEach(function (item) {
    var tagClass = item.type === 'overdue' ? 'danger' : (item.type === 'today' ? 'today' : '');
    var div = document.createElement('div');
    div.className = 'due-warning-item';
    div.innerHTML = `
      <span class="due-warning-tag ${tagClass}">${item.dueText}</span>
      <div><strong>${item.cari || '-'}</strong><span>${item.bank || '-'} · ${item.due || '-'} · ${item.amount || '-'}</span><span>${item.collection || ''}</span></div>
      <button type="button" class="due-warning-go" data-row="${item.rowId}">Göster</button>
    `;
    list.appendChild(div);
  });

  list.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-row]');
    if (!btn) return;
    var row = document.getElementById(btn.getAttribute('data-row'));
    if (!row) return;
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    row.classList.add('due-row-focus');
    setTimeout(function () { row.classList.remove('due-row-focus'); }, 3200);
  });

  (tabs || summary).insertAdjacentElement('afterend', panel);
})();
