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
  function init(){
    if (!/cariler\.php/i.test(location.pathname)) return;
    fetch('cari-doviz-bakiye.php', {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data || !data.ok || !data.balances) return;
        document.querySelectorAll('a[href^="cari-detay.php?id="]').forEach(function(link){
          var match = String(link.getAttribute('href') || '').match(/id=(\d+)/);
          if (!match) return;
          var tr = link.closest('tr');
          if (!tr) return;
          var cell = tr.querySelector('td.right');
          updateCell(cell, data.balances[match[1]] || [{currency:'TL', net:0}]);
        });
      })
      .catch(function(){});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
