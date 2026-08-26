(function(){
  'use strict';

  if(!/\/barkod-satis\.php$/i.test(location.pathname)) return;

  var overlay=null,video=null,controls=null,reader=null,activeInput=null,reading=false;

  function ensureStyle(){
    if(document.getElementById('iphoneBarcodeFallbackStyle')) return;
    var style=document.createElement('style');
    style.id='iphoneBarcodeFallbackStyle';
    style.textContent=''
      +'.iphone-barcode-scanner{position:fixed;inset:0;z-index:12000;display:none;grid-template-rows:auto 1fr;background:#0b0f0c;color:#fff}'
      +'.iphone-barcode-scanner.open{display:grid}'
      +'.iphone-barcode-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px calc(10px + env(safe-area-inset-top,0px));background:#122219}'
      +'.iphone-barcode-head strong{font-size:16px}.iphone-barcode-close{width:44px;height:44px;border:0;border-radius:13px;background:#fff;color:#14241b;font-size:25px;font-weight:800}'
      +'.iphone-barcode-body{position:relative;display:grid;place-items:center;overflow:hidden;background:#000}'
      +'.iphone-barcode-body video{width:100%;height:100%;object-fit:cover}'
      +'.iphone-barcode-guide{position:absolute;left:7%;right:7%;top:50%;height:150px;transform:translateY(-50%);border:3px solid #f0cf7c;border-radius:18px;box-shadow:0 0 0 9999px rgba(0,0,0,.33)}'
      +'.iphone-barcode-guide:after{content:"Barkodu kutunun içine getir";position:absolute;left:50%;bottom:-48px;transform:translateX(-50%);width:max-content;max-width:88vw;padding:9px 13px;border-radius:999px;background:rgba(15,38,27,.9);font-size:13px;font-weight:800;color:#fff}'
      +'body.iphone-barcode-open{overflow:hidden!important}';
    document.head.appendChild(style);
  }

  function ensureOverlay(){
    if(overlay) return overlay;
    ensureStyle();
    overlay=document.createElement('section');
    overlay.className='iphone-barcode-scanner';
    overlay.setAttribute('aria-hidden','true');
    overlay.innerHTML=''
      +'<header class="iphone-barcode-head"><strong>Kamera ile barkod oku</strong><button type="button" class="iphone-barcode-close" aria-label="Kapat">×</button></header>'
      +'<div class="iphone-barcode-body"><video playsinline muted></video><div class="iphone-barcode-guide" aria-hidden="true"></div></div>';
    document.body.appendChild(overlay);
    video=overlay.querySelector('video');
    overlay.querySelector('.iphone-barcode-close').addEventListener('click',closeScanner);
    return overlay;
  }

  function stopReader(){
    if(controls&&typeof controls.stop==='function'){
      try{controls.stop();}catch(e){}
    }
    controls=null;
    if(reader&&typeof reader.reset==='function'){
      try{reader.reset();}catch(e){}
    }
    reader=null;
    if(video&&video.srcObject){
      try{video.srcObject.getTracks().forEach(function(track){track.stop();});}catch(e){}
      video.srcObject=null;
    }
    reading=false;
  }

  function closeScanner(){
    stopReader();
    if(overlay){
      overlay.classList.remove('open');
      overlay.setAttribute('aria-hidden','true');
    }
    document.body.classList.remove('iphone-barcode-open');
    activeInput=null;
  }

  function resultText(result){
    if(!result) return '';
    if(typeof result.getText==='function') return String(result.getText()||'').trim();
    if(result.text!=null) return String(result.text||'').trim();
    return '';
  }

  function applyCode(code){
    if(!activeInput||!code) return;
    var target=activeInput;
    target.value=code;
    try{target.dispatchEvent(new Event('input',{bubbles:true}));}catch(e){}
    try{target.dispatchEvent(new Event('change',{bubbles:true}));}catch(e){}
    closeScanner();

    if(target.hasAttribute('data-pos-scan')){
      setTimeout(function(){
        try{target.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',code:'Enter',keyCode:13,which:13,bubbles:true}));}
        catch(e){var ev=document.createEvent('Event');ev.initEvent('keydown',true,true);ev.key='Enter';target.dispatchEvent(ev);}
      },80);
    }
  }

  function openScanner(input){
    if(reading) return;
    if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){
      alert('Kamera erişimi bu tarayıcıda kullanılamıyor. Safari kamera iznini kontrol edin.');
      return;
    }
    if(!window.ZXingBrowser||!window.ZXingBrowser.BrowserMultiFormatReader){
      alert('Barkod okuyucu yüklenemedi. İnternet bağlantısını kontrol edip sayfayı yenileyin.');
      return;
    }

    activeInput=input;
    ensureOverlay();
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden','false');
    document.body.classList.add('iphone-barcode-open');
    reading=true;
    reader=new window.ZXingBrowser.BrowserMultiFormatReader();

    reader.decodeFromVideoDevice(undefined,video,function(result,error,scannerControls){
      if(scannerControls&&!controls) controls=scannerControls;
      var code=resultText(result);
      if(code) applyCode(code);
    }).then(function(scannerControls){
      controls=scannerControls||controls;
    }).catch(function(error){
      closeScanner();
      var message=String(error&&error.message?error.message:error||'');
      if(/permission|denied|notallowed/i.test(message)) alert('Kamera izni verilmedi. iPhone Ayarlar > Safari > Kamera bölümünden izin verip tekrar deneyin.');
      else alert('Kamera açılamadı. Sayfayı yenileyip tekrar deneyin.');
    });
  }

  document.addEventListener('click',function(event){
    var button=event.target.closest&&event.target.closest('.mobile-barcode-button');
    if(!button) return;
    var field=button.closest('.mobile-barcode-field');
    var input=field&&field.querySelector('input');
    if(!input||!input.closest('[data-pos-root]')) return;

    event.preventDefault();
    event.stopPropagation();
    if(event.stopImmediatePropagation) event.stopImmediatePropagation();
    openScanner(input);
  },true);

  window.addEventListener('pagehide',stopReader);
})();
