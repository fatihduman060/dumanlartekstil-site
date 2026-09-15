(function(){
  'use strict';

  var page=document.querySelector('.page');
  var toolbar=document.querySelector('.toolbar');
  if(!page || !toolbar) return;

  var oldShare=toolbar.querySelector('a[href*="share=jpeg"]');
  if(oldShare) oldShare.remove();

  var button=document.createElement('button');
  button.type='button';
  button.setAttribute('data-wd-jpeg-share','1');
  button.disabled=true;
  button.textContent='JPEG hazırlanıyor…';

  var status=document.createElement('span');
  status.setAttribute('data-wd-jpeg-status','1');
  status.style.cssText='display:inline-flex;align-items:center;color:#efd28a;font-size:12px;font-weight:800;padding:0 4px';
  status.textContent='Fiş görüntüsü hazırlanıyor';

  var printButton=toolbar.querySelector('button');
  if(printButton && printButton.nextSibling){
    toolbar.insertBefore(button,printButton.nextSibling);
    toolbar.insertBefore(status,button.nextSibling);
  }else{
    toolbar.appendChild(button);
    toolbar.appendChild(status);
  }

  var blob=null;
  var preparing=null;
  var fileName='Dumanlar-Depo-Cikis.jpg';

  function safeFileName(){
    var title=(document.title||'Dumanlar Depo Cikis').replace(/[^a-zA-Z0-9ÇĞİÖŞÜçğıöşü_-]+/g,'-').replace(/^-+|-+$/g,'');
    return (title||'Dumanlar-Depo-Cikis')+'.jpg';
  }
  fileName=safeFileName();

  function waitForImages(root){
    var images=Array.prototype.slice.call(root.querySelectorAll('img'));
    return Promise.all(images.map(function(img){
      if(img.complete && img.naturalWidth>0) return Promise.resolve();
      return new Promise(function(resolve){
        img.addEventListener('load',resolve,{once:true});
        img.addEventListener('error',resolve,{once:true});
      });
    }));
  }

  function canvasToBlob(canvas){
    return new Promise(function(resolve,reject){
      canvas.toBlob(function(result){
        if(result) resolve(result);
        else reject(new Error('JPEG dosyası oluşturulamadı.'));
      },'image/jpeg',0.93);
    });
  }

  function prepareJpeg(){
    if(blob) return Promise.resolve(blob);
    if(preparing) return preparing;
    if(typeof window.html2canvas!=='function'){
      return Promise.reject(new Error('JPEG dönüştürücü yüklenemedi.'));
    }

    preparing=Promise.resolve()
      .then(function(){
        if(document.fonts && document.fonts.ready) return document.fonts.ready;
      })
      .then(function(){return waitForImages(page);})
      .then(function(){
        status.textContent='JPEG oluşturuluyor…';
        return window.html2canvas(page,{
          backgroundColor:'#ffffff',
          scale:2,
          useCORS:true,
          allowTaint:false,
          logging:false,
          scrollX:0,
          scrollY:0,
          windowWidth:page.scrollWidth,
          windowHeight:page.scrollHeight
        });
      })
      .then(canvasToBlob)
      .then(function(result){
        blob=result;
        button.disabled=false;
        button.textContent='WhatsApp’a Gönder';
        status.textContent='JPEG hazır';
        return blob;
      })
      .catch(function(error){
        preparing=null;
        button.disabled=false;
        button.textContent='JPEG’i tekrar hazırla';
        status.textContent=(error && error.message) ? error.message : 'JPEG hazırlanamadı';
        throw error;
      });
    return preparing;
  }

  function downloadJpeg(fileBlob){
    var url=URL.createObjectURL(fileBlob);
    var link=document.createElement('a');
    link.href=url;
    link.download=fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(function(){URL.revokeObjectURL(url);},15000);
  }

  function whatsappText(){
    return 'Dumanlar Tekstil - '+(document.title||'Depo çıkış fişi');
  }

  function openWhatsapp(){
    var url='https://wa.me/?text='+encodeURIComponent(whatsappText());
    var opened=window.open(url,'_blank','noopener,noreferrer');
    if(!opened) window.location.href=url;
  }

  function shareJpeg(fileBlob){
    var file;
    try{
      file=new File([fileBlob],fileName,{type:'image/jpeg',lastModified:Date.now()});
    }catch(error){
      downloadJpeg(fileBlob);
      openWhatsapp();
      status.textContent='JPEG indirildi; WhatsApp açıldı';
      return Promise.resolve();
    }

    var payload={
      files:[file],
      title:document.title||'Dumanlar Depo Çıkış Fişi',
      text:whatsappText()
    };
    var canFileShare=!!navigator.share;
    if(canFileShare && navigator.canShare){
      try{ canFileShare=navigator.canShare({files:[file]}); }
      catch(error){ canFileShare=false; }
    }

    if(canFileShare){
      return navigator.share(payload).then(function(){
        status.textContent='JPEG paylaşıldı';
      }).catch(function(error){
        if(error && error.name==='AbortError'){
          status.textContent='Paylaşım iptal edildi';
          return;
        }
        downloadJpeg(fileBlob);
        openWhatsapp();
        status.textContent='JPEG indirildi; WhatsApp açıldı';
      });
    }

    downloadJpeg(fileBlob);
    openWhatsapp();
    status.textContent='JPEG indirildi; WhatsApp açıldı';
    return Promise.resolve();
  }

  button.addEventListener('click',function(){
    button.disabled=true;
    if(!blob){
      button.textContent='JPEG hazırlanıyor…';
      prepareJpeg().then(function(result){
        button.disabled=false;
        return shareJpeg(result);
      }).catch(function(){button.disabled=false;});
      return;
    }
    status.textContent='Paylaşım menüsü açılıyor…';
    shareJpeg(blob).finally(function(){button.disabled=false;});
  });

  window.addEventListener('load',function(){
    window.setTimeout(function(){prepareJpeg().catch(function(){});},80);
  },{once:true});

  if(document.readyState==='complete'){
    window.setTimeout(function(){prepareJpeg().catch(function(){});},80);
  }
})();
