(function () {
  var path = (location.pathname || '').split('/').pop();
  if (path !== 'cari-detay.php') return;

  var section = document.getElementById('hareketler');
  if (!section) return;

  function addStyles() {
    if (document.getElementById('cariden-cikar-kompakt-style')) return;
    var style = document.createElement('style');
    style.id = 'cariden-cikar-kompakt-style';
    style.textContent = [
      '#hareketler .row-actions{align-items:flex-start!important;gap:5px!important}',
      '#hareketler .cariden-cikar-wrap{display:inline-flex;flex-direction:column;align-items:flex-start;gap:2px;max-width:132px;vertical-align:top}',
      '#hareketler .cariden-cikar-wrap .cariden-cikar-form{display:block!important;margin:0!important;line-height:1!important}',
      '#hareketler .cariden-cikar-wrap .cariden-cikar-btn{min-height:0!important;height:auto!important;padding:3px 6px!important;border-radius:7px!important;font-size:9px!important;line-height:1.05!important;font-weight:800!important;letter-spacing:0!important;box-shadow:none!important}',
      '#hareketler .cariden-cikar-wrap .cariden-cikar-kaynak{display:block!important;margin:0!important;padding:0!important;max-width:126px!important;font-size:8px!important;line-height:1.18!important;font-weight:650!important;color:#8b7774!important;white-space:normal!important;overflow-wrap:anywhere!important}',
      '@media(max-width:700px){#hareketler .cariden-cikar-wrap{max-width:112px}#hareketler .cariden-cikar-wrap .cariden-cikar-kaynak{max-width:108px!important}}'
    ].join('');
    document.head.appendChild(style);
  }

  function compact() {
    section.querySelectorAll('.cariden-cikar-form').forEach(function (form) {
      if (form.parentElement && form.parentElement.classList.contains('cariden-cikar-wrap')) return;

      var note = form.nextElementSibling;
      if (!note || !note.classList.contains('cariden-cikar-kaynak')) note = null;

      var wrap = document.createElement('span');
      wrap.className = 'cariden-cikar-wrap';
      form.parentNode.insertBefore(wrap, form);
      wrap.appendChild(form);
      if (note) wrap.appendChild(note);
    });
  }

  addStyles();
  compact();

  var observer = new MutationObserver(function () { compact(); });
  observer.observe(section, { childList: true, subtree: true });
})();
