// Only product reads use this timeout; sale/payment writes are never retried here.
window.posReadJson = function (url, controller) {
  controller = controller || new AbortController();
  var timedOut = false;
  var timer = setTimeout(function () { timedOut = true; controller.abort(); }, 8000);
  return fetch(url, {credentials:'same-origin', cache:'no-store', signal:controller.signal})
    .then(function (response) {
      if (!response.ok) throw new Error('Ürün bilgisi alınamadı. Tekrar deneyin.');
      return response.json();
    })
    .catch(function (error) {
      if (timedOut) throw new Error('Sunucu yanıtı gecikti. Barkodu tekrar okutun veya Ara’ya basın.');
      throw error;
    })
    .finally(function () { clearTimeout(timer); });
};
