(function(){
  'use strict';
  var busy=false,library;
  function load(){
    if(window.Tesseract)return Promise.resolve(window.Tesseract);
    if(!library)library=new Promise(function(resolve,reject){var s=document.createElement('script');s.src='https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js';s.onload=function(){resolve(window.Tesseract);};s.onerror=function(){s.remove();library=null;reject(new Error('Okuma bileşeni yüklenemedi. İnternet bağlantısını kontrol edip tekrar deneyin.'));};document.head.appendChild(s);});
    return library;
  }
  function build(){
    var section=document.querySelector('[data-shift-production-entry]');if(!section)return;
    section.querySelectorAll('[data-shift]').forEach(function(card){
      var input=card.querySelector('[data-photo-file]'),button=card.querySelector('[data-photo-read]'),status=card.querySelector('[data-photo-status]'),preview=card.querySelector('[data-photo-preview]'),url;
      button.addEventListener('click',function(){input.click();});
      input.addEventListener('change',async function(){
        var file=input.files[0];input.value='';if(!file||busy)return;
        if(!/^image\/(jpeg|png)$/.test(file.type)||file.size>15*1024*1024){status.textContent='En fazla 15 MB boyutunda JPEG veya PNG seçin.';return;}
        busy=true;var worker,timer,cancelled=false,controls=Array.from(section.querySelectorAll('[data-photo-read], [data-save-one-shift]')),states=controls.map(function(c){return c.disabled;});controls.forEach(function(c){c.disabled=true;});
        var dateInput=document.querySelector('input[name="date"]'),startDate=dateInput.value;
        var snapshot=Array.from(card.querySelectorAll('[data-dozen], [data-defective]')).map(function(c){return c.value;});
        status.textContent='Fotoğraf okunuyor… İlk kullanım biraz sürebilir.';
        try{
          if(url)URL.revokeObjectURL(url);url=URL.createObjectURL(file);preview.src=url;preview.hidden=false;
          var result=await Promise.race([(async function(){
            await preview.decode();if(preview.naturalWidth*preview.naturalHeight>40000000)throw new Error('Fotoğraf çok büyük. Kırpıp yeniden yükleyin.');
            var canvas=document.createElement('canvas'),scale=Math.min(2,2400/Math.max(preview.naturalWidth,preview.naturalHeight));canvas.width=Math.round(preview.naturalWidth*scale);canvas.height=Math.round(preview.naturalHeight*scale);var ctx=canvas.getContext('2d');ctx.fillStyle='white';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.drawImage(preview,0,0,canvas.width,canvas.height);
            var T=await load();worker=await T.createWorker('tur+eng',1,{workerPath:'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/worker.min.js'});
            if(cancelled){await worker.terminate();throw new Error('Okuma süresi doldu.');}
            await worker.setParameters({preserve_interword_spaces:'1',tessedit_pageseg_mode:'6'});return worker.recognize(canvas);
          })(),new Promise(function(_,reject){timer=setTimeout(function(){reject(new Error('Okuma süresi doldu. Daha küçük ve net bir fotoğrafla tekrar deneyin.'));},90000);})]);
          var now=Array.from(card.querySelectorAll('[data-dozen], [data-defective]')).map(function(c){return c.value;});
          if(dateInput.value!==startDate||JSON.stringify(now)!==JSON.stringify(snapshot))throw new Error('Okuma sırasında form veya tarih değişti. Değerleriniz korundu; fotoğrafı yeniden okuyun.');
          var parsed=window.ProductionPhoto.parse(result.data,card.dataset.shift),count=0;
          Object.keys(parsed.rows).forEach(function(g){var row=card.querySelector('[data-shift-row="'+card.dataset.shift+'-'+g+'"]');row.querySelector('[data-dozen]').value=String(parsed.rows[g].produced_dozen).replace('.',',');row.querySelector('[data-defective]').value=parsed.rows[g].defective_qty;count++;});
          if(parsed.date){dateInput.value=parsed.date;section.querySelector('[name="production_date"]').value=parsed.date;}
          card.dataset.edited='1';card.dataset.entryDate=dateInput.value;
          card.dispatchEvent(new Event('input',{bubbles:true}));
          status.textContent=count+' satır forma aktarıldı. Otomatik kayıt yapılmadı. Fotoğrafla karşılaştırıp '+(card.dataset.shift==='gece'?'Gece':'Gündüz')+' Vardiyasını Kaydet düğmesini kullanın. '+parsed.warnings.join(' ')+(parsed.date&&parsed.date!==startDate?' Tarih değişti; diğer vardiya ve raporlar önceki güne ait olabilir.':'');
        }catch(error){status.textContent=error.message||'Fotoğraf okunamadı. Manuel giriş yapabilir veya yeniden deneyebilirsiniz.';}
        finally{cancelled=true;clearTimeout(timer);busy=false;if(worker)worker.terminate().catch(function(){});controls.forEach(function(c,i){c.disabled=states[i];});}
      });
    });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',build);else build();
})();
