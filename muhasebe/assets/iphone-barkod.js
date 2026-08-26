(function(){
  'use strict';

  if(!/\/barkod-satis\.php\/?$/i.test(location.pathname)) return;

  var overlay=null,video=null,controls=null,reader=null,activeInput=null,reading=false,loadingLibrary=false,pendingInput=null;
  var ZXING_URL='https://unpkg.com/@zxing/browser@0.2.1/umd/zxing-browser.min.js';

  function ensureStyle(){
    if(document.getElementById('iphoneBarcodeFallbackStyle')) return;
    var style=document.createElement('style');
    style.id='iphoneBarcodeFallbackStyle';
    style.textContent=''
      +'.iphone-barcode-scanner{position:fixed;inset:0;z-index:12000;display:none;grid-template-rows:auto 1fr;background:#0b0f0c;color:#fff}'
      +'.iphone-barcode-scanner.open{display:grid}'
      +'.iphone-barcode-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:calc(12px + env(safe-area-inset-top,0px)) 16px 12px;background:#122219}'
      +'.iphone-barcode-head strong{font-size:16px}.iphone-barcode-close{width:44px;height:44px;border:0;border-radius:13px;background:#fff;color:#14241b;font-size:25px;font-weight:800}'
      +'.iphone-barcode-body{position:relative;display:grid;place-items:center;overflow:hidden;background:#000}'
      +'.iphone-barcode-body video{width:100%;height:100%;object-fit:cover;background:#000}'
      +'.iphone-barcode-guide{position:absolute;left:6%;right:6%;top:48%;height:155px;transform:translateY(-50%);border:3px solid #f0cf7c;border-radius:18px;box-shadow:0 0 0 9999px rgba(0,0,0,.30);pointer-events:none}'
      +'.iphone-barcode-status{position:absolute;left:16px;right:16px;bottom:calc(28px + env(safe-area-inset-bottom,0px));padding:11px 14px;border-radius:14px;background:rgba(15,38,27,.92);font-size:13px;font-weight:800;text-align:center;color:#fff}'
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
      +'<div class="iphone-barcode-body"><video playsinline muted autoplay></video><div class="iphone-barcode-guide" aria-hidden="true"></div><div class="iphone-barcode-status">Barkodu sarı alanın içine getir</div></div>';
    document.body.appendChild(overlay);
    video=overlay.querySelector('video');
    overlay.querySelector('.iphone-barcode-close').addEventListener('click',closeScanner);
    return overlay;
  }

  function statusText(text){
    ensureOverlay();
    var el=overlay.querySelector('.iphone-barcode-status');
    if(el) el.textContent=text||'';
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
      try{video.srcObject=null;}catch(e){}
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
    code=String(code||'').replace(/\s+/g,'').trim();
    if(!activeInput||!code) return;
    var target=activeInput;
    target.value=code;
    try{target.dispatchEvent(new Event('input',{bubbles:true}));}catch(e){}
    try{target.dispatchEvent(new Event('change',{bubbles:true}));}catch(e){}
    if(navigator.vibrate){try{navigator.vibrate(80);}catch(e){}}
    statusText('Barkod okundu: '+code);
    window.setTimeout(function(){
      closeScanner();
      if(target.hasAttribute('data-pos-scan')){
        try{target.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',code:'Enter',keyCode:13,which:13,bubbles:true}));}
        catch(e){var ev=document.createEvent('Event');ev.initEvent('keydown',true,true);ev.key='Enter';target.dispatchEvent(ev);}
      }
    },180);
  }

  function loadLibrary(callback){
    if(window.ZXingBrowser){callback(true);return;}
    if(loadingLibrary){
      var tries=0;
      var timer=setInterval(function(){
        tries++;
        if(window.ZXingBrowser){clearInterval(timer);callback(true);}
        else if(tries>40){clearInterval(timer);callback(false);}
      },100);
      return;
    }
    loadingLibrary=true;
    var old=document.querySelector('script[data-bitke-zxing]');
    if(old) old.remove();
    var script=document.createElement('script');
    script.src=ZXING_URL+'?v=2';
    script.async=true;
    script.setAttribute('data-bitke-zxing','1');
    script.onload=function(){loadingLibrary=false;callback(!!window.ZXingBrowser);};
    script.onerror=function(){loadingLibrary=false;callback(false);};
    document.head.appendChild(script);
  }

  function startReader(input){
    if(reading) return;
    if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){
      alert('Kamera erişimi açılamıyor. iPhone Ayarlar > Safari > Kamera bölümünü kontrol et.');
      return;
    }
    if(!window.ZXingBrowser){
      pendingInput=input;
      ensureOverlay();
      overlay.classList.add('open');
      overlay.setAttribute('aria-hidden','false');
      document.body.classList.add('iphone-barcode-open');
      statusText('Barkod okuyucu hazırlanıyor…');
      loadLibrary(function(ok){
        var next=pendingInput;pendingInput=null;
        if(!ok){closeScanner();alert('Barkod okuyucu yüklenemedi. İnternet bağlantısını kontrol edip tekrar dene.');return;}
        startReader(next||input);
      });
      return;
    }

    activeInput=input;
    ensureOverlay();
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden','false');
    document.body.classList.add('iphone-barcode-open');
    reading=true;
    statusText('Kamera açılıyor…');

    var Reader=window.ZXingBrowser.BrowserMultiFormatOneDReader || window.ZXingBrowser.BrowserMultiFormatReader;
    reader=new Reader();
    var constraints={audio:false,video:{facingMode:{ideal:'environment'},width:{ideal:1280},height:{ideal:720}}};

    reader.decodeFromConstraints(constraints,video,function(result,error,scannerControls){
      if(scannerControls&&!controls) controls=scannerControls;
      if(video&&video.readyState>=2) statusText('Barkodu sarı alanın içine getir');
      var code=resultText(result);
      if(code) applyCode(code);
    }).then(function(scannerControls){
      controls=scannerControls||controls;
      statusText('Barkodu sarı alanın içine getir');
    }).catch(function(error){
      closeScanner();
      var message=String(error&&error.message?error.message:error||'');
      if(/permission|denied|notallowed/i.test(message)) alert('Kamera izni verilmedi. iPhone Ayarlar > Safari > Kamera bölümünden izin verip tekrar dene.');
      else if(/notfound|device/i.test(message)) alert('Arka kamera bulunamadı. Kamera izinlerini kontrol et.');
      else alert('Kamera açılamadı. Barkodlu Satış sayfasını kapatıp yeniden aç ve tekrar dene.');
    });
  }

  function openScanner(input){
    startReader(input);
  }

  window.BITKE_IPHONE_BARCODE_OPEN=openScanner;

  document.addEventListener('click',function(event){
    var button=event.target.closest&&event.target.closest('.mobile-barcode-button');
    if(!button) return;
    var field=button.closest('.mobile-barcode-field');
    var input=field&&field.querySelector('input');
    if(!input||!input.closest('[data-pos-root]')) return;
    if(window.BarcodeDetector) return;

    event.preventDefault();
    event.stopPropagation();
    if(event.stopImmediatePropagation) event.stopImmediatePropagation();
    openScanner(input);
  },true);

  window.addEventListener('pagehide',stopReader);
})();
