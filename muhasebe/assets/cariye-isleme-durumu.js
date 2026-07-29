(function () {
  var title = document.querySelector('.topbar h1');
  if (!title || title.textContent.trim() !== 'Teklif Ver') return;

  function csrfToken() {
    var input = document.querySelector('input[name="csrf_token"]');
    return input ? input.value : '';
  }

  function backUrl() {
    return location.pathname.split('/').pop() + location.search;
  }

  function offerIdFromActions(actions) {
    var pdf = actions && actions.querySelector('a[href*="teklif-yazdir.php?id="]');
    if (!pdf) return '';
    try {
      return new URL(pdf.href, location.href).searchParams.get('id') || '';
    } catch (e) {
      return '';
    }
  }

  function submitCari(id, isSync) {
    if (!id || !csrfToken()) return;
    var message = isSync
      ? 'Bağlı cari hareket bulunamadı veya iptal edilmiş. Teklif yeniden cariyle eşitlenecek. Devam edilsin mi?'
      : 'Bu teklif cariye alacak olarak işlenecek. Devam edilsin mi?';
    if (!confirm(message)) return;

    var form = document.createElement('form');
    form.method = 'post';
    form.action = 'cariye-isle.php';
    form.style.display = 'none';
    var values = { csrf_token: csrfToken(), source_type: 'offer', id: id, back: backUrl() };
    Object.keys(values).forEach(function (key) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = key;
      input.value = values[key];
      form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
  }

  function addStyles() {
    if (document.getElementById('cariye-isleme-durumu-style')) return;
    var style = document.createElement('style');
    style.id = 'cariye-isleme-durumu-style';
    style.textContent = [
      '.cari-status-wrap{display:inline-flex;flex-direction:column;align-items:flex-start;gap:3px}',
      '.cari-status-badge{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:7px 10px;font-weight:900;font-size:13px;white-space:nowrap}',
      '.cari-status-badge.posted{border:1px solid #83c59b;background:#edf9f1;color:#176536}',
      '.cari-status-note{font-size:10px;color:#667085;font-weight:750;padding-left:4px;white-space:nowrap}',
      '.cariye-isle-btn.cari-needs-sync{border-color:#e2a23b!important;background:#fff4dc!important;color:#8a5a00!important}',
      '.cariye-isle-btn.cari-needs-sync:hover{background:#ffe9b8!important}',
      '.offer-actions .cari-status-wrap{min-height:42px;justify-content:center}',
      '@media(max-width:640px){.cari-status-wrap{width:100%}.cari-status-badge{justify-content:center;width:100%}}'
    ].join('');
    document.head.appendChild(style);
  }

  function fetchStatus(id) {
    return fetch('cariye-durum.php?source_type=offer&id=' + encodeURIComponent(id), {
      credentials: 'same-origin',
      cache: 'no-store'
    }).then(function (response) {
      if (!response.ok) throw new Error('Durum okunamadı');
      return response.json();
    });
  }

  function makePostedStatus(movementId) {
    var wrap = document.createElement('span');
    wrap.className = 'cari-status-wrap';
    var badge = document.createElement('span');
    badge.className = 'cari-status-badge posted';
    badge.textContent = '✓ Cariye işlendi';
    wrap.appendChild(badge);
    if (movementId) {
      var note = document.createElement('small');
      note.className = 'cari-status-note';
      note.textContent = 'Cari hareket #' + movementId;
      wrap.appendChild(note);
    }
    return wrap;
  }

  function applyStatus(actions, id, status) {
    if (!actions || !status) return;
    var oldButton = actions.querySelector('.cariye-isle-btn');
    var oldStatus = actions.querySelector('.cari-status-wrap');
    if (oldStatus) oldStatus.remove();

    if (status.posted) {
      if (oldButton) oldButton.remove();
      actions.appendChild(makePostedStatus(status.movement_id || 0));
      actions.dataset.cariPosted = '1';
      actions.dataset.cariMovementId = String(status.movement_id || 0);
      return;
    }

    actions.dataset.cariPosted = '0';
    if (!oldButton) return;

    var fresh = oldButton.cloneNode(true);
    oldButton.replaceWith(fresh);
    if (status.movement_id) {
      fresh.textContent = '⚠ Cariyle eşitle';
      fresh.classList.add('cari-needs-sync');
      fresh.title = 'Bağlı cari hareket bulunamadı veya iptal edilmiş. Yeniden eşitlemek için tıkla.';
      fresh.addEventListener('click', function () { submitCari(id, true); });
    } else {
      fresh.textContent = 'Cariye İşle';
      fresh.classList.remove('cari-needs-sync');
      fresh.addEventListener('click', function () { submitCari(id, false); });
    }
  }

  function enhanceList() {
    document.querySelectorAll('.saved-actions').forEach(function (actions) {
      var id = offerIdFromActions(actions);
      if (!id) return;
      fetchStatus(id).then(function (status) {
        applyStatus(actions, id, status);
      }).catch(function () {});
    });
  }

  function enhanceEditForm() {
    var params = new URLSearchParams(location.search);
    var editId = params.get('edit');
    if (!editId) return;

    fetchStatus(editId).then(function (status) {
      var actions = document.querySelector('.offer-actions');
      if (actions) applyStatus(actions, editId, status);

      var form = document.getElementById('offerForm');
      if (!form || !status.posted || form.dataset.cariSyncWarning === '1') return;
      form.dataset.cariSyncWarning = '1';
      form.addEventListener('submit', function (event) {
        if (form.dataset.cariSyncApproved === '1') return;
        var ok = confirm('Bu teklif daha önce cariye işlendi. Kaydettiğin değişiklikler bağlı cari hareketine de otomatik yansıtılacak. Devam edilsin mi?');
        if (!ok) {
          event.preventDefault();
          return;
        }
        form.dataset.cariSyncApproved = '1';
      });
    }).catch(function () {});
  }

  addStyles();
  window.setTimeout(function () {
    enhanceList();
    enhanceEditForm();
  }, 0);
})();
