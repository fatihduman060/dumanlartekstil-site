(function () {
  var title = document.querySelector('.topbar h1');
  if (!title || title.textContent.trim() !== 'Kullanıcılar') return;

  var ids = Array.isArray(window.BITKE_SUPER_ADMIN_IDS) ? window.BITKE_SUPER_ADMIN_IDS.map(Number) : [];
  document.querySelectorAll('select[name="role"]').forEach(function (select) {
    var row = select.closest('tr');
    var form = select.closest('form') || (row ? row.querySelector('form') : null);
    var idInput = (row ? row.querySelector('input[name="id"]') : null) || (form ? form.querySelector('input[name="id"]') : null);
    var userId = Number(idInput ? idInput.value : 0);
    if (!userId) return;
    if (!select.querySelector('option[value="super_admin"]')) {
      var option = document.createElement('option');
      option.value = 'super_admin';
      option.textContent = '⭐ Süper Yönetici';
      select.appendChild(option);
    }
    if (ids.indexOf(userId) !== -1) {
      select.value = 'super_admin';
    }
  });
})();
