(function () {
  var title = document.querySelector('.topbar h1');
  if (!title || title.textContent.trim() !== 'Kullanıcılar') return;

  function key(value) {
    return String(value || '')
      .toLowerCase()
      .replace(/ı/g, 'i')
      .replace(/ç/g, 'c')
      .replace(/ğ/g, 'g')
      .replace(/ö/g, 'o')
      .replace(/ş/g, 's')
      .replace(/ü/g, 'u')
      .replace(/[^a-z0-9]+/g, '');
  }

  var ids = Array.isArray(window.BITKE_SUPER_ADMIN_IDS) ? window.BITKE_SUPER_ADMIN_IDS.map(Number) : [];
  document.querySelectorAll('select[name="role"]').forEach(function (select) {
    var row = select.closest('tr');
    var form = select.closest('form') || (row ? row.querySelector('form') : null);
    var idInput = (row ? row.querySelector('input[name="id"]') : null) || (form ? form.querySelector('input[name="id"]') : null);
    var userId = Number(idInput ? idInput.value : 0);
    if (!userId || !row) return;

    var username = key((row.querySelector('td strong') || {}).textContent || '');
    var displayInput = row.querySelector('input[name="display_name"]');
    var displayName = key(displayInput ? displayInput.value : '');
    var isFatih = username === 'fatih' || username === 'fatihduman' || displayName === 'fatih' || displayName === 'fatihduman';
    var superOption = select.querySelector('option[value="super_admin"]');

    if (!isFatih) {
      if (superOption) superOption.remove();
      if (select.value === 'super_admin') select.value = 'admin';
      return;
    }

    if (!superOption) {
      superOption = document.createElement('option');
      superOption.value = 'super_admin';
      superOption.textContent = '⭐ Süper Yönetici';
      select.appendChild(superOption);
    }

    if (ids.indexOf(userId) !== -1) {
      select.value = 'super_admin';
    }
  });
})();
