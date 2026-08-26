(function(){
  'use strict';

  var overlay=null;
  var scanner=null;
  var activeInput=null;
  var readerId='bitke-iphone-barcode-reader-v3';
  var fileInput=null;
  var statusEl=null;
  var started=false;

  function onPosPage(){
    return !!document.querySelector('[data-pos-root]');
  }

  function addStyle(){
    if(document.getElementById('bitkeIphoneBarcodeV3Style')) return;
    var style=document.createElement('style');
    style.id='bitkeIphoneBarcodeV3Style';
    style.textContent=''
      +'.bitke-iphone-scan-v3{position:fixed;inset:0;z-index:30000;display:none;grid-template-rows:auto 1fr auto;background:#050806;color:#fff}'
      +'.bitke-iphone-scan-v3.open{display:grid!important}'
      +'.bitke-iphone-scan-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:calc(12px + env(safe-area-inset-top,0px)) 14px 12px;background:#102319}'
      +'.bitke-iphone-scan-head strong{font-size:17px}.bitke-iphone-scan-close{width:44px;height:44px;border:0;border-radius:12px;background:#fff;color:#173f29;font-size:26px;font-weight:900}'
      +'.bitke-iphone-scan-body{position:relative;min-height:0;overflow:hidden;background:#000}'
      +'#'+readerId+'{width:100%;height:100%;min-height:360px;background:#000}'
      +'#'+readerId+' video{width:100%!important;height:100%!important;object-fit:cover!important}'
      +'.bitke-iphone-scan-guide{pointer-events:none;position:absolute;z-index:5;left:7%;right:7%;top:50%;height:150px;transform:translateY(-50%);border:3px solid #f0cb70;border-radius:18px;box-shadow:0 0 0 9999px rgba(0,0,0,.22)}'
      +'.bitke-iphone-scan-guide span{position:absolute;left:50%;bottom:-48px;transform:translateX(-50%);width:max-content;max-width:88vw;padding:8px 12px;border-radius:999px;background:rgba(15,45,32,.92);font-size:12px;font-weight:850;color:#fff}'
      +'.bitke-iphone-scan-foot{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;padding:12px 14px calc(12px + env(safe-area-inset-bottom,0px));background:#102319}'
      +'.bitke-iphone-scan-status{font-size:12px;line-height:1.35;color:#d8e4dc}.bitke-photo-btn{min-height:44px;border:1px solid #dfc073;border-radius:12px;background:#f6df9b;color:#173f29;padding:9px 13px;font-weight:900}'
      +'body.bitke-iphone-scanning{overflow:hidden!important}';
    document.head.appendChild(style);
  }

  function ensureOverlay(){
    if(overlay) return overlay;
    addStyle();
    overlay=document.createElement('section');
    overlay.className='bitke-iphone-scan-v3';
    overlay.setAttribute('aria-hidden','true');
    overlay.innerHTML=''
      +'<header class="bitke-iphone-scan-head"><strong>Barkodu kameraya göster</strong><button type="button" class="bitke-iphone-scan-close" aria-label="Kapat">×</button></header>'
      +'<div class="bitke-iphone-scan-body"><div id="'+readerId+'"></div><div class="bitke-iphone-scan-guide"><span>Barkodu sarı alanın içine getir</span></div></div>'
      +'<footer class="bitke-iphone-scan-foot"><div class="bitke-iphone-scan-status">Kamera hazırlanıyor…</div><button type="button" class="bitke-photo-btn">Fotoğrafla okut</button></footer>';
    document.body.appendChild(overlay);
    statusEl=overlay.querySelector('.bitke-iphone-scan-status');
    overlay.querySelector('.bitke-iphone-scan-close').addEventListener('click',closeScanner);
    overlay.querySelector('.bitke-photo-btn').addEventListener('click',openPhotoScanner);

    fileInput=document.createElement('input');
    fileInput.type='file';
    fileInput.accept='image/*';
    fileInput.setAttribute('capture','environment');
    fileInput.style.display='none';
    fileInput.addEventListener('change',scanPhotoFile);
    document.body.appendChild(fileInput);
    return overlay;
  }

  function setStatus(text){
    if(statusEl) statusEl.textContent=text||'';
  }

  function formats(){
    if(!window.Html5QrcodeSupportedFormats) return undefined;
    return [
      Html5QrcodeSupportedFormats.EAN_13,
      Html5QrcodeSupportedFormats.EAN_8,
      Html5QrcodeSupportedFormats.UPC_A,
      Html5QrcodeSupportedFormats.UPC_E,
      Html5QrcodeSupportedFormats.CODE_128,
      Html5QrcodeSupportedFormats.CODE_39,
      Html5QrcodeSupportedFormats.ITF
    ];
  }

  function buildScanner(){
    if(scanner) return scanner;
    if(!window.Html5Qrcode) throw new Error('Barkod kütüphanesi yüklenmedi.');
    var opts={verbose:false};
    var supported=formats();
    if(supported) opts.formatsToSupport=supported;
    scanner=new Html5Qrcode(readerId,opts);
    return scanner;
  }

  function applyCode(code){
    code=String(code||'').replace(/\s+/g,'').trim();
    if(!activeInput||!code) return;
    var target=activeInput;
    target.value=code;
    try{target.dispatchEvent(new Event('input',{bubbles:true}));}catch(e){}
    try{target.dispatchEvent(new Event('change',{bubbles:true}));}catch(e){}
    closeScanner().then(function(){
      if(target.hasAttribute('data-pos-scan')){
        window.setTimeout(function(){
          try{target.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',code:'Enter',bubbles:true}));}
          catch(e){var ev=document.createEvent('Event');ev.initEvent('keydown',true,true);ev.key='Enter';target.dispatchEvent(ev);}
        },80);
      }
    });
  }

  function stopScanner(){
    if(!scanner||!started) return Promise.resolve();
    return scanner.stop().catch(function(){}).then(function(){started=false;});
  }

  function closeScanner(){
    return stopScanner().then(function(){
      if(scanner){
        try{scanner.clear();}catch(e){}
      }
      scanner=null;
      if(overlay){overlay.classList.remove('open');overlay.setAttribute('aria-hidden','true');}
      document.body.classList.remove('bitke-iphone-scanning');
      activeInput=null;
      if(fileInput) fileInput.value='';
    });
  }

  function startLiveCamera(){
    var instance;
    try{instance=buildScanner();}catch(error){
      setStatus('Canlı okuyucu açılamadı. Fotoğrafla okut seçeneğini kullan.');
      return;
    }
    setStatus('Arka kamera açılıyor…');
    var config={
      fps:12,
      qrbox:{width:300,height:150},
      aspectRatio:1.7777778,
      experimentalFeatures:{useBarCodeDetectorIfSupported:false}
    };
    instance.start({facingMode:{exact:'environment'}},config,function(decodedText){
      applyCode(decodedText);
    },function(){}).catch(function(){
      return instance.start({facingMode:'environment'},config,function(decodedText){
        applyCode(decodedText);
      },function(){});
    }).then(function(){
      started=true;
      setStatus('Kamera açık. Barkodu sarı alanın içine getir.');
    }).catch(function(error){
      started=false;
      var message=String(error&&error.message?error.message:error||'');
      if(/notallowed|permission|denied/i.test(message)) setStatus('Kamera izni kapalı. iPhone Ayarlar > Safari > Kamera bölümünden izin ver veya “Fotoğrafla okut”u kullan.');
      else setStatus('Canlı kamera açılamadı. “Fotoğrafla okut” ile devam edebilirsin.');
    });
  }

  function openScanner(input){
    if(!onPosPage()) return;
    activeInput=input;
    ensureOverlay();
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden','false');
    document.body.classList.add('bitke-iphone-scanning');
    startLiveCamera();
  }

  function openPhotoScanner(){
    if(!activeInput||!fileInput) return;
    stopScanner().then(function(){fileInput.click();});
  }

  function scanPhotoFile(){
    if(!fileInput||!fileInput.files||!fileInput.files[0]||!activeInput) return;
    var file=fileInput.files[0];
    setStatus('Fotoğraftaki barkod okunuyor…');
    var instance;
    try{instance=buildScanner();}catch(error){setStatus('Barkod okuyucu yüklenemedi. Sayfayı yenileyip tekrar dene.');return;}
    instance.scanFile(file,true).then(function(decodedText){
      applyCode(decodedText);
    }).catch(function(){
      setStatus('Fotoğrafta barkod bulunamadı. Barkodu yakın ve net çekip tekrar dene.');
      fileInput.value='';
    });
  }

  document.addEventListener('click',function(event){
    var button=event.target&&event.target.closest?event.target.closest('.mobile-barcode-button'):null;
    if(!button) return;
    var field=button.closest('.mobile-barcode-field');
    var input=field&&field.querySelector('input');
    if(!input||!input.closest('[data-pos-root]')) return;
    event.preventDefault();
    event.stopPropagation();
    if(event.stopImmediatePropagation) event.stopImmediatePropagation();
    openScanner(input);
  },true);

  window.BITKE_POS_OPEN_BARCODE_CAMERA=openScanner;
  window.addEventListener('pagehide',function(){stopScanner();});
})();
