(function () {
  var path = (location.pathname || '').split('/').pop();
  if (path !== 'cari-detay.php') return;

  var section = document.getElementById('hareketler');
  if (!section) return;

  var tokenInput = document.querySelector('input[name="csrf_token"]');
  var cariId = new URLSearchParams(location.search).get('id') || '';
  if (!tokenInput || !cariId) return;

  var rowsById = {};
  section.querySelectorAll('tbody tr').forEach(function (row) {
    var editLink = row.querySelector('a[href*="hareketler.php?edit="]');
    if (!editLink) return;
    try {
      var url = new URL(editLink.getAttribute('href'), location.href);
      var movementId = url.searchParams.get('edit');
      if (movementId && /^\d+$/.test(movementId)) rowsById[movementId] = row;
    } catch (e) {}
  });

  var ids = Object.keys(rowsById);
  if (!ids.length) return;

  function addStyles() {
    if (document.getElementById('cariden-cikar-style')) return;
    var style = document.createElement('style');
    style.id = 'cariden-cikar-style';
    style.textContent = [
      '.cariden-cikar-form{display:inline-flex!important;margin:0!important}',
      '.cariden-cikar-btn{border:1px solid #d66b62!important;background:#fff5f3!important;color:#a6352d!important;border-radius:999px!important;padding:7px 10px!important;font-weight:900!important;cursor:pointer!important;white-space:nowrap!important}',
      '.cariden-cikar-btn:hover{background:#ffe2de!important}',
      '.cariden-cikar-kaynak{display:block;color:#8a6c68;font-size:10px;font-weight:750;margin-top:4px;max-width:180px}'
    ].join('');
    document.head.appendChild(style);
  }

  function buildButton(movementId, info, row) {
    var actions = row.querySelector('.row-actions') || row.lastElementChild;
    if (!actions || actions.querySelector('.cariden-cikar-form')) return;

    var form = document.createElement('form');
    form.method = 'post';
    form.action = 'cari-hareket-cariden-cikar.php';
    form.className = 'cariden-cikar-form';
    form.addEventListener('submit', function (event) {
      var message = 'Bu kayıt cari bakiyeden çıkarılacak. Belge silinmeyecek; hareket iptal geçmişinde saklanacak. Devam edilsin mi?';
      if (!confirm(message)) event.preventDefault();
    });

    var fields = {
      csrf_token: tokenInput.value,
      movement_id: movementId,
      cari_id: cariId,
      reason: 'Mükerrer kayıt nedeniyle cariden çıkarıldı'
    };
    Object.keys(fields).forEach(function (name) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = fields[name];
      form.appendChild(input);
    });

    var button = document.createElement('button');
    button.type = 'submit';
    button.className = 'cariden-cikar-btn';
    button.textContent = 'Cariden çıkar';
    form.appendChild(button);
    actions.appendChild(form);

    if (info.source_label) {
      var note = document.createElement('small');
      note.className = 'cariden-cikar-kaynak';
      note.textContent = info.source_label;
      actions.appendChild(note);
    }
  }

  addStyles();
  fetch('cari-hareket-cariden-cikar.php?ids=' + encodeURIComponent(ids.join(',')), {
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json' }
  })
    .then(function (response) {
      if (!response.ok) throw new Error('Durum bilgisi alınamadı.');
      return response.json();
    })
    .then(function (data) {
      var items = data && data.items ? data.items : {};
      Object.keys(items).forEach(function (movementId) {
        var info = items[movementId] || {};
        if (info.eligible && rowsById[movementId]) buildButton(movementId, info, rowsById[movementId]);
      });
    })
    .catch(function () {
      // Durum bilgisi alınamazsa güvenlik gereği düğme gösterilmez.
    });
})();
