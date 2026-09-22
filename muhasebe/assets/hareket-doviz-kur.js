(function () {
  var wrap = document.querySelector('[data-fx-rate-wrap]');
  if (!wrap) return;
  var form = wrap.closest('form');
  var currency = form.elements.currency, rate = form.elements.exchange_rate;
  var amount = form.elements.amount, account = form.elements.account_id;
  var type = form.elements.movement_type;
  function number(value) {
    var text = String(value || '').trim().replace(/\s/g, '');
    if (text.includes(',')) text = text.replace(/\./g, '').replace(',', '.');
    return Number(text);
  }
  function update() {
    var foreign = currency.value === 'USD' || currency.value === 'EUR';
    wrap.style.display = foreign ? '' : 'none';
    rate.disabled = !foreign;
    rate.required = foreign && !!account.value && ['tahsilat','gelir','odeme','gider'].includes(type.value);
    wrap.querySelector('[data-fx-currency]').textContent = currency.value;
    var value = number(amount.value) * number(rate.value);
    wrap.querySelector('[data-fx-preview]').textContent = number(rate.value) > 0 && Number.isFinite(value) && value > 0
      ? 'TL karşılığı: ' + new Intl.NumberFormat('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}).format(value) + ' TL'
      : 'İşlemde kullanacağınız kuru girin.';
  }
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  update();
})();
