(function(){
  function shouldRun(){ return /teklif-ver\.php/i.test(location.pathname); }
  function parseOfferNumber(value){
    var v = String(value || '').replace(/\s/g, '');
    if (!v) return 0;
    var hasComma = v.indexOf(',') !== -1;
    var hasDot = v.indexOf('.') !== -1;
    if (hasComma) {
      v = v.replace(/\./g, '').replace(',', '.');
    } else if (hasDot) {
      var parts = v.split('.');
      var last = parts[parts.length - 1] || '';
      if (parts.length > 2 || last.length === 3) v = v.replace(/\./g, '');
    }
    var n = parseFloat(v);
    return Number.isFinite(n) ? n : 0;
  }
  function fmt(n){
    try { return new Intl.NumberFormat('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}).format(n || 0); }
    catch(e){ return (Math.round((n || 0) * 100) / 100).toFixed(2).replace('.', ','); }
  }
  function recalc(){
    var table = document.getElementById('offerRows');
    if (!table) return;
    var sum = 0;
    table.querySelectorAll('tbody tr').forEach(function(row){
      var q = parseOfferNumber(row.querySelector('.qty') && row.querySelector('.qty').value);
      var p = parseOfferNumber(row.querySelector('.price') && row.querySelector('.price').value);
      var total = q * p;
      sum += total;
      var out = row.querySelector('.line-total');
      if (out) out.textContent = fmt(total);
    });
    var vatEnabled = document.getElementById('vatEnabled');
    var vatRate = document.getElementById('vatRate');
    var rate = vatEnabled && vatEnabled.checked ? parseOfferNumber(vatRate && vatRate.value ? vatRate.value : '10') : 0;
    var vat = sum * rate / 100;
    var subtotalEl = document.getElementById('subtotalTotal');
    var vatEl = document.getElementById('vatTotal');
    var grandEl = document.getElementById('grandTotal');
    if (subtotalEl) subtotalEl.textContent = fmt(sum);
    if (vatEl) vatEl.textContent = fmt(vat);
    if (grandEl) grandEl.textContent = fmt(sum + vat);
  }
  function init(){
    if (!shouldRun()) return;
    recalc();
    document.addEventListener('input', function(e){
      if (e.target && (e.target.classList.contains('calc') || e.target.id === 'vatRate')) setTimeout(recalc, 0);
    });
    document.addEventListener('change', function(e){
      if (e.target && (e.target.classList.contains('calc') || e.target.id === 'vatEnabled' || e.target.id === 'vatRate')) setTimeout(recalc, 0);
    });
    document.addEventListener('click', function(e){
      if (e.target && (e.target.id === 'addRow' || e.target.classList.contains('row-remove'))) setTimeout(recalc, 0);
    });
    var table = document.getElementById('offerRows');
    if (table && window.MutationObserver) new MutationObserver(function(){ recalc(); }).observe(table.querySelector('tbody') || table, {childList:true, subtree:true});
    setTimeout(recalc, 100);
    setTimeout(recalc, 500);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
