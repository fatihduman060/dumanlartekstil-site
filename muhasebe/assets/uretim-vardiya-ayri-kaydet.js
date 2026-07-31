(function(){
  if (!/uretim-takibi\.php/i.test(location.pathname)) return;

  function csrf(){
    var input = document.querySelector('input[name="csrf_token"]');
    return input ? input.value : '';
  }

  function dateValue(){
    var input = document.querySelector('input[name="date"]');
    if (input && input.value) return input.value;
    var query = new URLSearchParams(location.search).get('date');
    return query || new Date().toISOString().slice(0,10);
  }

  function addButton(card, shift, title){
    if (!card || card.querySelector('[data-save-one-shift]')) return;
    var wrap = document.createElement('div');
    wrap.className = 'shift-card-save';
    wrap.innerHTML = '<button type="button" data-save-one-shift="'+shift+'">'+title+'</button>';
    card.appendChild(wrap);

    wrap.querySelector('button').addEventListener('click', function(){
      var button = this;
      var body = new URLSearchParams();
      body.set('csrf_token', csrf());
      body.set('production_date', dateValue());
      body.set('shift_code', shift);

      ['A','B','C','D','E'].forEach(function(group){
        var row = card.querySelector('[data-shift-row="'+shift+'-'+group+'"]');
        var dozen = row && row.querySelector('[data-dozen]') ? row.querySelector('[data-dozen]').value : '';
        var defective = row && row.querySelector('[data-defective]') ? row.querySelector('[data-defective]').value : '';
        body.set('shift_rows['+group+'][produced_dozen]', dozen);
        body.set('shift_rows['+group+'][defective_qty]', defective);
      });

      button.disabled = true;
      button.textContent = 'Kaydediliyor...';
      fetch('uretim-hizli-kaydet.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body: body.toString()
      }).then(function(response){
        if (!response.ok) throw new Error('Kayıt başarısız');
        location.href = 'uretim-takibi.php?date=' + encodeURIComponent(dateValue());
      }).catch(function(){
        button.disabled = false;
        button.textContent = title;
        alert('Vardiya kaydedilemedi. Sayfayı yenileyip tekrar deneyin.');
      });
    });
  }

  function build(){
    var section = document.querySelector('[data-shift-production-entry]');
    if (!section) {
      setTimeout(build, 100);
      return;
    }

    var oldSave = section.querySelector('.shift-save');
    if (oldSave) oldSave.style.display = 'none';

    addButton(section.querySelector('[data-shift="gunduz"]'), 'gunduz', 'Gündüz Vardiyasını Kaydet');
    addButton(section.querySelector('[data-shift="gece"]'), 'gece', 'Gece Vardiyasını Kaydet');

    if (!document.querySelector('style[data-shift-save-style]')) {
      var style = document.createElement('style');
      style.setAttribute('data-shift-save-style','1');
      style.textContent = '.shift-card-save{padding:14px}.shift-card-save button{width:100%;border:0;border-radius:11px;background:#173f29;color:#fff;padding:12px 16px;font-weight:900;cursor:pointer}.shift-card-save button:disabled{opacity:.65;cursor:wait}@media(max-width:600px){.shift-card-save{padding:10px}.shift-card-save button{min-height:48px}}';
      document.head.appendChild(style);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build);
  else build();
})();
