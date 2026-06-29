(function(){
  function initBankList(){
    if (!/tahsilat-makbuzu\.php/i.test(location.pathname)) return;
    var input = document.querySelector('input[name="bank_name"]');
    if (!input || input.dataset.bankListReady === '1') return;
    input.dataset.bankListReady = '1';
    input.setAttribute('list', 'tahsilatBankaListesi');
    input.setAttribute('autocomplete', 'off');

    var datalist = document.getElementById('tahsilatBankaListesi');
    if (!datalist) {
      datalist = document.createElement('datalist');
      datalist.id = 'tahsilatBankaListesi';
      document.body.appendChild(datalist);
    }

    fetch('banka-listesi.php', {credentials:'same-origin'})
      .then(function(res){ return res.ok ? res.json() : []; })
      .then(function(items){
        datalist.innerHTML = '';
        if (!Array.isArray(items) || !items.length) return;
        items.forEach(function(item){
          var name = item && item.name ? String(item.name) : '';
          if (!name) return;
          var option = document.createElement('option');
          option.value = name;
          if (item.detail) option.label = String(item.detail);
          datalist.appendChild(option);
        });
      })
      .catch(function(){});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initBankList);
  else initBankList();
})();
