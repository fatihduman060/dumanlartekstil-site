(function(){
  function init(){
    if (!/cari-detay\.php/i.test(location.pathname)) return;
    var params = new URLSearchParams(location.search);
    var cariId = params.get('id') || '';
    document.querySelectorAll('#hareketler .row-actions a[href^="hareketler.php?edit="]').forEach(function(link){
      try {
        var url = new URL(link.getAttribute('href'), location.href);
        var movementId = url.searchParams.get('edit') || '';
        if (!movementId) return;
        link.href = 'belge-yonlendir.php?movement_id=' + encodeURIComponent(movementId) + (cariId ? '&cari_id=' + encodeURIComponent(cariId) : '');
        link.textContent = 'Kaynak Belgeyi İncele';
        link.title = 'Sipariş fişi, tahsilat makbuzu, çek/senet veya manuel hareketi açar';
      } catch(e) {}
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
