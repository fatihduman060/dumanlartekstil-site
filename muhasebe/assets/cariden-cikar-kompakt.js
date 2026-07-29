(function () {
  var path = (location.pathname || '').split('/').pop();
  if (path !== 'cari-detay.php') return;

  var section = document.getElementById('hareketler');
  if (!section) return;

  function important(el, name, value) {
    if (el) el.style.setProperty(name, value, 'important');
  }

  function forceCompact(form) {
    if (!form) return;

    var wrap = form.parentElement && form.parentElement.classList.contains('cariden-cikar-wrap')
      ? form.parentElement
      : null;
    var note = null;

    if (wrap) {
      note = wrap.querySelector('.cariden-cikar-kaynak');
    } else {
      note = form.nextElementSibling;
      if (!note || !note.classList.contains('cariden-cikar-kaynak')) note = null;

      wrap = document.createElement('span');
      wrap.className = 'cariden-cikar-wrap';
      form.parentNode.insertBefore(wrap, form);
      wrap.appendChild(form);
      if (note) wrap.appendChild(note);
    }

    var actions = wrap.closest('.row-actions');
    if (actions) {
      important(actions, 'align-items', 'flex-start');
      important(actions, 'gap', '5px');
    }

    important(wrap, 'display', 'inline-flex');
    important(wrap, 'flex-direction', 'column');
    important(wrap, 'align-items', 'flex-start');
    important(wrap, 'gap', '1px');
    important(wrap, 'width', '102px');
    important(wrap, 'max-width', '102px');
    important(wrap, 'vertical-align', 'top');
    important(wrap, 'margin', '0');
    important(wrap, 'padding', '0');

    important(form, 'display', 'block');
    important(form, 'margin', '0');
    important(form, 'padding', '0');
    important(form, 'line-height', '1');
    important(form, 'width', 'auto');

    var button = form.querySelector('.cariden-cikar-btn');
    if (button) {
      important(button, 'min-height', '0');
      important(button, 'height', '18px');
      important(button, 'width', 'auto');
      important(button, 'padding', '1px 5px');
      important(button, 'margin', '0');
      important(button, 'border-radius', '5px');
      important(button, 'font-size', '8px');
      important(button, 'line-height', '1');
      important(button, 'font-weight', '800');
      important(button, 'letter-spacing', '0');
      important(button, 'box-shadow', 'none');
      important(button, 'white-space', 'nowrap');
    }

    note = note || wrap.querySelector('.cariden-cikar-kaynak');
    if (note) {
      important(note, 'display', 'block');
      important(note, 'position', 'static');
      important(note, 'width', '100px');
      important(note, 'max-width', '100px');
      important(note, 'margin', '1px 0 0 0');
      important(note, 'padding', '0');
      important(note, 'font-size', '7px');
      important(note, 'line-height', '1.08');
      important(note, 'font-weight', '600');
      important(note, 'color', '#8b7774');
      important(note, 'white-space', 'normal');
      important(note, 'overflow-wrap', 'anywhere');
      important(note, 'text-align', 'left');
    }
  }

  function compactAll() {
    section.querySelectorAll('.cariden-cikar-form').forEach(forceCompact);
  }

  compactAll();
  [0, 50, 150, 400, 900, 1600].forEach(function (delay) {
    window.setTimeout(compactAll, delay);
  });

  var observer = new MutationObserver(function () { compactAll(); });
  observer.observe(section, { childList: true, subtree: true });
})();
