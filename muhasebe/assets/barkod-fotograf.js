(function(){
  'use strict';

  function init(){
    var root=document.querySelector('[data-pos-root]');
    if(!root) return;

    var fileInput=document.createElement('input');
    fileInput.type='file';
    fileInput.accept='image/*';
    fileInput.setAttribute('capture','environment');
    fileInput.style.position='fixed';
    fileInput.style.left='-9999px';
    fileInput.style.width='1px';
    fileInput.style.height='1px';
    document.body.appendChild(fileInput);

    var activeInput=null;
    var activeStatus=null;

    function ensureStyle(){
      if(document.getElementById('bitkeBarcodePhotoStyle')) return;
      var style=document.createElement('style');
      style.id='bitkeBarcodePhotoStyle';
      style.textContent=''
        +'.pos-shell .mobile-barcode-button{display:none!important}'
        +'.bitke-barcode-photo-wrap{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:end;width:100%}'
        +'.bitke-barcode-photo-wrap>input{min-width:0;width:100%}'
        +'.bitke-barcode-photo-btn{min-height:46px;border:1px solid #a8c3b0;border-radius:12px;background:#eef7f1;color:#173f29;padding:9px 13px;font:inherit;font-weight:900;white-space:nowrap;cursor:pointer}'
        +'.bitke-barcode-photo-btn:disabled{opacity:.55;cursor:wait}'
        +'.bitke-barcode-photo-status{grid-column:1/-1;min-height:18px;margin-top:2px;font-size:11px;line-height:1.3;color:#657168}'
        +'@media(max-width:700px){.bitke-barcode-photo-wrap{grid-template-columns:1fr}.bitke-barcode-photo-btn{width:100%;min-height:50px;font-size:14px}.bitke-barcode-photo-status{font-size:12px}}';
      document.head.appendChild(style);
    }

    function wrapInput(input,label){
      if(!input||input.dataset.photoBarcodeReady==='1') return;
      input.dataset.photoBarcodeReady='1';
      var parent=input.parentElement;
      if(!parent) return;

      var wrap=document.createElement('span');
      wrap.className='bitke-barcode-photo-wrap';
      parent.insertBefore(wrap,input);
      wrap.appendChild(input);

      var button=document.createElement('button');
      button.type='button';
      button.className='bitke-barcode-photo-btn';
      button.textContent='📷 Barkodu fotoğrafla okut';
      button.addEventListener('click',function(){
        activeInput=input;
        activeStatus=status;
        status.textContent='iPhone kamerası açılıyor… Barkodu yakın ve net çek.';
        fileInput.value='';
        fileInput.click();
      });
      wrap.appendChild(button);

      var status=document.createElement('small');
      status.className='bitke-barcode-photo-status';
      status.textContent=label||'Canlı kamera yerine iPhone kamerasıyla fotoğraf çekerek okur.';
      wrap.appendChild(status);
    }

    function applyCode(code){
      code=String(code||'').replace(/\s+/g,'').trim();
      if(!activeInput||!code) return;
      var target=activeInput;
      target.value=code;
      try{target.dispatchEvent(new Event('input',{bubbles:true}));}catch(e){}
      try{target.dispatchEvent(new Event('change',{bubbles:true}));}catch(e){}
      if(activeStatus) activeStatus.textContent='Barkod okundu: '+code;

      if(target.hasAttribute('data-pos-scan')){
        window.setTimeout(function(){
          try{target.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',code:'Enter',bubbles:true}));}
          catch(e){
            var search=root.querySelector('[data-pos-search]');
            if(search) search.click();
          }
        },80);
      }
    }

    function readers(){
      return [
        'ean_reader','ean_8_reader','upc_reader','upc_e_reader',
        'code_128_reader','code_39_reader','i2of5_reader'
      ];
    }

    function decodeAttempt(src,options,done){
      try{
        window.Quagga.decodeSingle({
          src:src,
          numOfWorkers:0,
          locate:true,
          inputStream:{size:options.size||1600,singleChannel:false},
          locator:{patchSize:options.patchSize||'medium',halfSample:options.halfSample!==false},
          decoder:{readers:readers(),multiple:false}
        },function(result){
          var code=result&&result.codeResult&&result.codeResult.code?String(result.codeResult.code):'';
          done(code);
        });
      }catch(error){done('');}
    }

    function decodePhoto(file){
      if(!window.Quagga||typeof window.Quagga.decodeSingle!=='function'){
        if(activeStatus) activeStatus.textContent='Fotoğraf okuyucu yüklenemedi. İnternet bağlantısını kontrol edip sayfayı yeniden aç.';
        return;
      }
      var url=URL.createObjectURL(file);
      if(activeStatus) activeStatus.textContent='Fotoğraftaki barkod okunuyor…';

      decodeAttempt(url,{size:1600,patchSize:'medium',halfSample:true},function(code){
        if(code){URL.revokeObjectURL(url);applyCode(code);return;}
        decodeAttempt(url,{size:2200,patchSize:'large',halfSample:false},function(code2){
          URL.revokeObjectURL(url);
          if(code2){applyCode(code2);return;}
          if(activeStatus) activeStatus.textContent='Barkod okunamadı. Barkodu kadraja yakın, düz ve net alıp tekrar fotoğraf çek.';
        });
      });
    }

    fileInput.addEventListener('change',function(){
      if(!fileInput.files||!fileInput.files[0]||!activeInput) return;
      decodePhoto(fileInput.files[0]);
    });

    ensureStyle();
    wrapInput(root.querySelector('[data-pos-scan]'),'Barkodu fotoğraflayınca ürün otomatik aranır ve sepete eklenir.');
    var productForm=root.querySelector('[data-product-form]');
    if(productForm) wrapInput(productForm.querySelector('input[name="barcode"]'),'Yeni ürünün barkodunu iPhone kamerasıyla fotoğraftan okur.');
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
