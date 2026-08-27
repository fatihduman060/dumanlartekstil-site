(function () {
  'use strict';

  var ZXing = window.ZXingBrowser;
  if (!ZXing || !ZXing.BrowserMultiFormatReader) return;

  var active = null;
  var locked = false;

  function addStyle() {
    var style = document.createElement('style');
    style.textContent = [
      '.bitke-camera-button{width:100%;min-height:48px;margin-top:8px;display:flex;align-items:center;justify-content:center;gap:8px}',
      '.bitke-camera-overlay{position:fixed;inset:0;z-index:10050;background:rgba(7,18,15,.96);display:flex;align-items:center;justify-content:center;padding:16px}',
      '.bitke-camera-overlay[hidden]{display:none}',
      '.bitke-camera-sheet{width:min(100%,520px);background:#fff;border-radius:22px;padding:16px;box-shadow:0 24px 70px rgba(0,0,0,.45)}',
      '.bitke-camera-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}',
      '.bitke-camera-head h3{margin:0;font-size:20px}',
      '.bitke-camera-close{min-width:48px;min-height:48px;border:0;border-radius:14px;background:#eee;font-size:25px}',
      '.bitke-camera-stage{position:relative;overflow:hidden;background:#08110e;border-radius:16px;aspect-ratio:3/4;max-height:62vh}',
      '.bitke-camera-video{width:100%;height:100%;object-fit:cover}',
      '.bitke-camera-line{position:absolute;left:8%;right:8%;top:50%;height:3px;background:#efc76e;box-shadow:0 0 14px #efc76e}',
      '.bitke-camera-status{margin:12px 0 0;min-height:24px;color:#4d5551}',
      '.bitke-camera-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}',
      '.bitke-camera-actions .btn{min-height:48px}',
      '@media(min-width:800px){.bitke-camera-button{width:auto}}'
    ].join('');
    document.head.appendChild(style);
  }

  function buildOverlay() {
    var el = document.createElement('div');
    el.className = 'bitke-camera-overlay';
    el.hidden = true;
    el.innerHTML = '<div class="bitke-camera-sheet" role="dialog" aria-modal="true" aria-labelledby="bitke-camera-title">' +
      '<div class="bitke-camera-head"><h3 id="bitke-camera-title">Barkodu kameraya göster</h3><button type="button" class="bitke-camera-close" aria-label="Kapat">×</button></div>' +
      '<div class="bitke-camera-stage"><video class="bitke-camera-video" playsinline muted></video><span class="bitke-camera-line"></span></div>' +
      '<p class="bitke-camera-status" role="status">Arka kamera hazırlanıyor…</p>' +
      '<div class="bitke-camera-actions"><button type="button" class="btn btn-secondary bitke-camera-photo">Fotoğraftan oku</button><button type="button" class="btn btn-secondary bitke-camera-switch">Kamerayı değiştir</button></div>' +
      '<input class="bitke-camera-file" type="file" accept="image/*" capture="environment" hidden>' +
      '</div>';
    document.body.appendChild(el);
    return el;
  }

  function stop() {
    if (!active) return;
    if (active.controls && active.controls.stop) active.controls.stop();
    var stream = active.video.srcObject;
    if (stream && stream.getTracks) stream.getTracks().forEach(function (track) { track.stop(); });
    active.video.srcObject = null;
    active.overlay.hidden = true;
    document.body.style.overflow = active.oldOverflow || '';
    active = null;
    locked = false;
  }

  function applyCode(input, code) {
    code = String(code || '').trim();
    if (!code || locked) return;
    locked = true;
    input.value = code;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    stop();
    input.focus();
    if (input.hasAttribute('data-pos-scan')) {
      input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', code: 'Enter', bubbles: true }));
    }
  }

  function messageFor(error) {
    var name = error && error.name ? error.name : '';
    if (name === 'NotAllowedError') return 'Kamera izni kapalı. Tarayıcı ayarlarından kamera iznini açın veya fotoğraftan okutun.';
    if (name === 'NotFoundError') return 'Bu cihazda kullanılabilir kamera bulunamadı. Fotoğraftan okutmayı deneyin.';
    if (name === 'NotReadableError') return 'Kamera başka bir uygulama tarafından kullanılıyor. Diğer uygulamayı kapatıp tekrar deneyin.';
    return 'Kamera açılamadı. Fotoğraftan okutmayı deneyin.';
  }

  async function start(input, overlay, facingMode) {
    stop();
    overlay.hidden = false;
    document.body.style.overflow = 'hidden';
    var video = overlay.querySelector('.bitke-camera-video');
    var status = overlay.querySelector('.bitke-camera-status');
    active = { input: input, overlay: overlay, video: video, controls: null, facing: facingMode || 'environment', oldOverflow: '' };
    status.textContent = 'Arka kamera hazırlanıyor…';
    try {
      var reader = new ZXing.BrowserMultiFormatReader();
      var controls = await reader.decodeFromConstraints({
        audio: false,
        video: { facingMode: { ideal: active.facing }, width: { ideal: 1280 }, height: { ideal: 720 } }
      }, video, function (result) {
        if (result) applyCode(input, result.getText ? result.getText() : result.text);
      });
      if (active) {
        active.controls = controls;
        status.textContent = 'Barkodu çizginin ortasında sabit tutun.';
      } else if (controls && controls.stop) controls.stop();
    } catch (error) {
      if (active) status.textContent = messageFor(error);
    }
  }

  async function decodePhoto(input, overlay, file) {
    if (!file) return;
    var status = overlay.querySelector('.bitke-camera-status');
    status.textContent = 'Fotoğraftaki barkod aranıyor…';
    var url = URL.createObjectURL(file);
    try {
      var reader = new ZXing.BrowserMultiFormatReader();
      var result = await reader.decodeFromImageUrl(url);
      applyCode(input, result.getText ? result.getText() : result.text);
    } catch (error) {
      status.textContent = 'Barkod bulunamadı. Barkodu yakından ve net çekip tekrar deneyin.';
    } finally {
      URL.revokeObjectURL(url);
    }
  }

  function init() {
    var inputs = Array.prototype.slice.call(document.querySelectorAll('[data-barcode-input]'));
    if (!inputs.length) return;
    addStyle();
    var overlay = buildOverlay();
    var selected = null;
    inputs.forEach(function (input) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn-secondary bitke-camera-button';
      button.innerHTML = '<span aria-hidden="true">📷</span> Kamerayla barkod okut';
      input.parentNode.appendChild(button);
      button.addEventListener('click', function () { selected = input; start(input, overlay, 'environment'); });
    });
    document.querySelectorAll('.mobile-barcode-button').forEach(function (button) { button.hidden = true; });
    overlay.querySelector('.bitke-camera-close').addEventListener('click', stop);
    overlay.addEventListener('click', function (event) { if (event.target === overlay) stop(); });
    overlay.querySelector('.bitke-camera-photo').addEventListener('click', function () { overlay.querySelector('.bitke-camera-file').click(); });
    overlay.querySelector('.bitke-camera-file').addEventListener('change', function () { decodePhoto(selected, overlay, this.files && this.files[0]); this.value = ''; });
    overlay.querySelector('.bitke-camera-switch').addEventListener('click', function () {
      if (!selected || !active) return;
      start(selected, overlay, active.facing === 'environment' ? 'user' : 'environment');
    });
    window.addEventListener('pagehide', stop);
    document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
