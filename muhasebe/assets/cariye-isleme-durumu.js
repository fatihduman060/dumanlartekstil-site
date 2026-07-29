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

  function duplicateStatus(sourceType, id) {
    return fetch('cari-mukerrer-kontrol.php?source_type=' + encodeURIComponent(sourceType) + '&id=' + encodeURIComponent(id), {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      if (!response.ok) throw new Error('Mükerrer kontrolü yapılamadı.');
      return response.json();
    });
  }

  function duplicateMessage(data) {
    var lines = ['Dikkat: Aynı caride, aynı yönde ve aynı tutarda daha önce cariye işlenmiş belge bulundu.'];
    (data.duplicates || []).forEach(function (item) {
      var detail = '• ' + (item.label || 'Belge');
      if (item.amount) detail += ' — ' + item.amount;
      if (item.date) detail += ' — ' + item.date;
      lines.push(detail);
    });
    lines.push('');
    lines.push((data.source_label || 'Bu belge') + ' (' + (data.source_amount || '') + ') yine de cariye işlensin mi?');
    return lines.join('\n');
  }

  function postOffer(id, duplicateApproved) {
    var form = document.createElement('form');
    form.method = 'post';
    form.action = 'cariye-isle.php';
    form.style.display = 'none';
    var values = {
      csrf_token: csrfToken(),
      source_type: 'offer',
      id: id,
      back: backUrl(),
      confirm_duplicate: duplicateApproved ? '1' : '0'
    };
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

  function submitCari(id, isSync) {
    if (!id || !csrfToken()) return;

    duplicateStatus('offer', id).then(function (data) {
      if (data && data.has_duplicate) {
        if (!confirm(duplicateMessage(data))) return;
        postOffer(id, true);
        return;
      }

      var message = isSync
        ? 'Bağlı cari hareket bulunamadı veya iptal edilmiş. Teklif yeniden cariyle eşitlenecek. Devam edilsin mi?'
        : 'Bu teklif cariye alacak olarak işlenecek. Devam edilsin mi?';
      if (!confirm(message)) return;
      postOffer(id, false);
    }).catch(function () {
      if (!confirm('Aynı tutarlı kayıt kontrolü yapılamadı. Yine de cariye işlemek istiyor musun?')) return;
      postOffer(id, true);
    });
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

  function addRemoveStyles() {
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

  function buildRemoveButton(movementId, info, row) {
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

  addRemoveStyles();
  fetch('cari-hareket-cariden-cikar.php?ids=' + encodeURIComponent(ids.join(',')), {
    credentials: 'same-origin',
    cache: 'no-store',
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
        if (info.eligible && rowsById[movementId]) buildRemoveButton(movementId, info, rowsById[movementId]);
      });
    })
    .catch(function () {
      // Durum bilgisi alınamazsa güvenlik gereği düğme gösterilmez.
    });
})();

(function () {
  var title = document.querySelector('.topbar h1');
  if (!title || title.textContent.trim() !== 'Faturalar') return;

  function duplicateMessage(data) {
    var lines = ['Dikkat: Aynı caride, aynı yönde ve aynı tutarda daha önce cariye işlenmiş belge bulundu.'];
    (data.duplicates || []).forEach(function (item) {
      var detail = '• ' + (item.label || 'Belge');
      if (item.amount) detail += ' — ' + item.amount;
      if (item.date) detail += ' — ' + item.date;
      lines.push(detail);
    });
    lines.push('');
    lines.push((data.source_label || 'Bu fatura') + ' (' + (data.source_amount || '') + ') yine de cariye işlensin mi?');
    return lines.join('\n');
  }

  document.querySelectorAll('form').forEach(function (form) {
    var actionInput = form.querySelector('input[name="action"][value="post_cari"]');
    var idInput = form.querySelector('input[name="id"]');
    if (!actionInput || !idInput) return;

    form.addEventListener('submit', function (event) {
      if (form.dataset.duplicateApproved === '1') return;
      event.preventDefault();

      fetch('cari-mukerrer-kontrol.php?source_type=invoice&id=' + encodeURIComponent(idInput.value), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
      }).then(function (response) {
        if (!response.ok) throw new Error('Mükerrer kontrolü yapılamadı.');
        return response.json();
      }).then(function (data) {
        if (data && data.has_duplicate && !confirm(duplicateMessage(data))) return;

        if (data && data.has_duplicate) {
          var approved = form.querySelector('input[name="confirm_duplicate"]');
          if (!approved) {
            approved = document.createElement('input');
            approved.type = 'hidden';
            approved.name = 'confirm_duplicate';
            form.appendChild(approved);
          }
          approved.value = '1';
        }

        form.dataset.duplicateApproved = '1';
        form.submit();
      }).catch(function () {
        if (!confirm('Aynı tutarlı kayıt kontrolü yapılamadı. Yine de cariye işlemek istiyor musun?')) return;
        form.dataset.duplicateApproved = '1';
        form.submit();
      });
    });
  });
})();
