(function(){
  if(!/\/vergi-odemeleri\.php$/i.test(location.pathname)) return;

  var form=document.getElementById('taxPaymentForm');
  var fileInput=form?form.querySelector('[data-vergi-document]'):null;
  var status=form?form.querySelector('[data-vergi-read-status]'):null;

  function fold(value){
    return String(value||'')
      .toLocaleUpperCase('tr-TR')
      .replace(/İ/g,'I').replace(/Ş/g,'S').replace(/Ğ/g,'G')
      .replace(/Ü/g,'U').replace(/Ö/g,'O').replace(/Ç/g,'C');
  }

  function norm(value){
    return fold(value).replace(/[^A-Z0-9]+/g,' ').replace(/\s+/g,' ').trim();
  }

  function setStatus(text,tone){
    if(!status) return;
    status.textContent=text||'';
    status.className='vergi-read-status'+(tone?' is-'+tone:'');
  }

  function loadScript(src,marker){
    return new Promise(function(resolve,reject){
      var existing=document.querySelector('script['+marker+']');
      if(existing){
        if(existing.getAttribute('data-loaded')==='1'){resolve();return;}
        existing.addEventListener('load',resolve,{once:true});
        existing.addEventListener('error',reject,{once:true});
        return;
      }
      var script=document.createElement('script');
      script.src=src;
      script.setAttribute(marker,'1');
      script.onload=function(){script.setAttribute('data-loaded','1');resolve();};
      script.onerror=function(){reject(new Error('Belge okuma kütüphanesi yüklenemedi.'));};
      document.head.appendChild(script);
    });
  }

  function loadPdfJs(){
    if(window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    return loadScript('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js','data-vergi-pdfjs')
      .then(function(){
        if(!window.pdfjsLib) throw new Error('PDF okuma kütüphanesi yüklenemedi.');
        window.pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        return window.pdfjsLib;
      });
  }

  function loadTesseract(){
    if(window.Tesseract) return Promise.resolve(window.Tesseract);
    return loadScript('https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js','data-vergi-tesseract')
      .then(function(){
        if(!window.Tesseract) throw new Error('Görsel okuma kütüphanesi yüklenemedi.');
        return window.Tesseract;
      });
  }

  function pdfPageLines(content){
    var rows=[];
    (content.items||[]).forEach(function(item){
      var text=String(item.str||'').trim();
      if(!text) return;
      var x=item.transform&&item.transform.length>4?Number(item.transform[4]||0):0;
      var y=item.transform&&item.transform.length>5?Number(item.transform[5]||0):0;
      var row=null;
      for(var i=0;i<rows.length;i++){
        if(Math.abs(rows[i].y-y)<=2.8){row=rows[i];break;}
      }
      if(!row){row={y:y,items:[]};rows.push(row);}
      row.items.push({x:x,text:text});
    });
    rows.sort(function(a,b){return b.y-a.y;});
    return rows.map(function(row){
      row.items.sort(function(a,b){return a.x-b.x;});
      return row.items.map(function(item){return item.text;}).join(' ').replace(/\s+/g,' ').trim();
    }).filter(Boolean);
  }

  function readPdfText(file){
    return loadPdfJs().then(function(pdfjs){
      return file.arrayBuffer().then(function(buffer){return pdfjs.getDocument({data:buffer}).promise;});
    }).then(function(pdf){
      var pageCount=Math.min(pdf.numPages,6);
      var jobs=[];
      for(var pageNo=1;pageNo<=pageCount;pageNo++){
        jobs.push(pdf.getPage(pageNo).then(function(page){
          return page.getTextContent().then(pdfPageLines);
        }));
      }
      return Promise.all(jobs).then(function(pages){
        var lines=[];
        pages.forEach(function(pageLines){lines=lines.concat(pageLines);});
        return {text:lines.join('\n'),pdf:pdf};
      });
    });
  }

  function renderPdfPage(pdf,pageNo){
    return pdf.getPage(pageNo).then(function(page){
      var viewport=page.getViewport({scale:2});
      var canvas=document.createElement('canvas');
      canvas.width=Math.ceil(viewport.width);
      canvas.height=Math.ceil(viewport.height);
      var ctx=canvas.getContext('2d',{willReadFrequently:true});
      return page.render({canvasContext:ctx,viewport:viewport}).promise.then(function(){return canvas;});
    });
  }

  function ocrSource(source,label){
    setStatus((label||'Belge')+' görsel olarak okunuyor. Bu işlem biraz sürebilir...','loading');
    return loadTesseract().then(function(Tesseract){
      return Tesseract.recognize(source,'tur+eng',{
        logger:function(message){
          if(message.status==='recognizing text'){
            setStatus((label||'Belge')+' okunuyor: %'+Math.round(Number(message.progress||0)*100),'loading');
          }
        }
      });
    }).then(function(result){return String(result&&result.data&&result.data.text||'');});
  }

  function readImage(file){
    return ocrSource(file,'Vergi belgesi');
  }

  function readFile(file){
    if(file.type==='application/pdf'||/\.pdf$/i.test(file.name)){
      setStatus('PDF metni okunuyor...','loading');
      return readPdfText(file).then(function(result){
        var compact=norm(result.text);
        if(compact.length>=120) return result.text;
        var pages=Math.min(result.pdf.numPages,2);
        var chain=Promise.resolve([]);
        for(var i=1;i<=pages;i++){
          (function(pageNo){
            chain=chain.then(function(texts){
              return renderPdfPage(result.pdf,pageNo).then(function(canvas){
                return ocrSource(canvas,'PDF sayfa '+pageNo).then(function(text){texts.push(text);return texts;});
              });
            });
          })(i);
        }
        return chain.then(function(texts){return (result.text+'\n'+texts.join('\n')).trim();});
      });
    }
    if(/^image\//i.test(file.type)||/\.(jpe?g|png|webp|heic|heif)$/i.test(file.name)) return readImage(file);
    return Promise.reject(new Error('PDF veya görsel dosya seçmelisin.'));
  }

  function parseMoney(raw){
    var value=String(raw||'').replace(/\s/g,'').replace(/[^0-9,.-]/g,'');
    if(!value) return null;
    var comma=value.lastIndexOf(',');
    var dot=value.lastIndexOf('.');
    if(comma>-1&&dot>-1){
      if(comma>dot) value=value.replace(/\./g,'').replace(',','.');
      else value=value.replace(/,/g,'');
    }else if(comma>-1){
      value=value.replace(/\./g,'').replace(',','.');
    }else if(dot>-1){
      var decimals=value.length-dot-1;
      if(decimals!==2) value=value.replace(/\./g,'');
    }
    var number=parseFloat(value);
    return Number.isFinite(number)?Math.round(number*100)/100:null;
  }

  function moneyMatches(text){
    var source=String(text||'');
    var regex=/(?:\d{1,3}(?:[.\s]\d{3})+|\d+)[,]\d{2}|(?:\d{1,3}(?:[,]\d{3})+|\d+)[.]\d{2}/g;
    var rows=[];
    var match;
    while((match=regex.exec(source))!==null){
      var value=parseMoney(match[0]);
      if(value!==null) rows.push({raw:match[0],value:value,index:match.index});
      if(match.index===regex.lastIndex) regex.lastIndex++;
    }
    return rows;
  }

  function isoDate(value){
    var match=String(value||'').match(/\b(\d{1,2})[.\/-](\d{1,2})[.\/-](20\d{2})\b/);
    if(!match) return '';
    return match[3]+'-'+String(match[2]).padStart(2,'0')+'-'+String(match[1]).padStart(2,'0');
  }

  function linesOf(text){
    return String(text||'').split(/\r?\n/).map(function(line){return line.replace(/\s+/g,' ').trim();}).filter(Boolean);
  }

  function valueNearLabel(lines,labels,extractor){
    var labelKeys=labels.map(norm);
    for(var i=0;i<lines.length;i++){
      var line=lines[i];
      var lineKey=norm(line);
      var matched=labelKeys.some(function(label){return lineKey.indexOf(label)!==-1;});
      if(!matched) continue;
      var candidates=[line,lines[i+1]||'',lines[i+2]||''];
      for(var j=0;j<candidates.length;j++){
        var value=extractor(candidates[j],lineKey,j);
        if(value!==null&&value!==undefined&&value!=='') return value;
      }
    }
    return '';
  }

  function detectTaxType(text,lines){
    var direct=valueNearLabel(lines,['Vergi Türü','Vergi Turu','Beyanname Türü','Beyanname Turu','Tahakkukun Cinsi','Vergi Cinsi'],function(line){
      var match=String(line).match(/(?:VERG[Iİ]\s*T[ÜU]R[ÜU]|BEYANNAME\s*T[ÜU]R[ÜU]|TAHAKKUKUN\s*C[Iİ]NS[Iİ]|VERG[Iİ]\s*C[Iİ]NS[Iİ])\s*[:\-]?\s*(.{3,80})/i);
      return match?match[1].trim():'';
    });
    if(direct&&norm(direct).length>=3&&!/^\d+$/.test(norm(direct))) return direct.replace(/\s{2,}.*/,'').trim();

    var key=norm(text);
    var types=[
      ['MUHTASAR VE PRIM HIZMET','Muhtasar ve Prim Hizmet Beyannamesi'],
      ['KATMA DEGER VERGISI','Katma Değer Vergisi (KDV)'],
      ['KDV','Katma Değer Vergisi (KDV)'],
      ['KURUMLAR VERGISI','Kurumlar Vergisi'],
      ['GECICI VERGI','Geçici Vergi'],
      ['GELIR VERGISI STOPAJ','Gelir Vergisi Stopajı'],
      ['DAMGA VERGISI','Damga Vergisi'],
      ['MOTORLU TASITLAR VERGISI','Motorlu Taşıtlar Vergisi'],
      ['EMLAK VERGISI','Emlak Vergisi'],
      ['SGK','SGK Primi'],
      ['SOSYAL GUVENLIK','SGK Primi'],
      ['BAG KUR','Bağ-Kur Primi'],
      ['KONAKLAMA VERGISI','Konaklama Vergisi'],
      ['BANKA VE SIGORTA MUAMELELERI','BSMV'],
      ['OZEL TUKETIM VERGISI','Özel Tüketim Vergisi (ÖTV)']
    ];
    for(var i=0;i<types.length;i++) if(key.indexOf(types[i][0])!==-1) return types[i][1];
    return 'Vergi Ödemesi';
  }

  function detectAmount(lines,text){
    var labels=['Ödenecek Toplam Tutar','Toplam Ödenecek','Ödenecek Tutar','Toplam Borç','Tahakkuk Eden Tutar','Tahakkuk Tutarı','Toplam Tutar','Ödeme Tutarı','Tahsil Edilen Tutar'];
    var value=valueNearLabel(lines,labels,function(line){
      var amounts=moneyMatches(line);
      return amounts.length?amounts[amounts.length-1].value:null;
    });
    if(value!=='') return Number(value);

    var all=moneyMatches(text).map(function(item){return item.value;}).filter(function(amount){return amount>0&&amount<1000000000;});
    if(!all.length) return null;
    return Math.max.apply(Math,all);
  }

  function detectDueDate(lines,text){
    var value=valueNearLabel(lines,['Vade Tarihi','Son Ödeme Tarihi','Son Odeme Tarihi','Ödeme Vadesi','Odeme Vadesi'],function(line){return isoDate(line);});
    if(value) return value;
    var key=norm(text);
    var index=Math.max(key.indexOf('VADE TARIHI'),key.indexOf('SON ODEME TARIHI'));
    if(index>=0) return isoDate(String(text).slice(index,index+120));
    return '';
  }

  function detectPeriod(lines,text){
    var direct=valueNearLabel(lines,['Vergilendirme Dönemi','Vergilendirme Donemi','Dönem','Donem','Beyanname Dönemi','Beyanname Donemi'],function(line){
      var range=String(line).match(/\b(\d{1,2}[.\/-]\d{1,2}[.\/-]20\d{2})\s*[-–]\s*(\d{1,2}[.\/-]\d{1,2}[.\/-]20\d{2})\b/);
      if(range) return range[1]+' - '+range[2];
      var ym=String(line).match(/\b(20\d{2})[\/-](0?[1-9]|1[0-2])\b/);
      if(ym) return ym[1]+'/'+String(ym[2]).padStart(2,'0');
      var my=String(line).match(/\b(0?[1-9]|1[0-2])[\/-](20\d{2})\b/);
      if(my) return my[2]+'/'+String(my[1]).padStart(2,'0');
      return '';
    });
    if(direct) return direct;
    var match=String(text).match(/\b(20\d{2})[\/-](0?[1-9]|1[0-2])\b/);
    return match?match[1]+'/'+String(match[2]).padStart(2,'0'):'';
  }

  function detectDocumentNo(lines){
    return valueNearLabel(lines,['Tahakkuk Fiş No','Tahakkuk Fis No','Tahakkuk No','Belge No','Alındı No','Alindi No','Makbuz No','Beyanname No'],function(line){
      var match=String(line).match(/(?:TAHAKKUK\s*F[Iİ]Ş\s*NO|TAHAKKUK\s*NO|BELGE\s*NO|ALINDI\s*NO|MAKBUZ\s*NO|BEYANNAME\s*NO)\s*[:\-]?\s*([A-Z0-9\/-]{5,40})/i);
      return match?match[1].trim():'';
    });
  }

  function formatMoney(value){
    return Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});
  }

  function parseDocument(text){
    var lines=linesOf(text);
    return {
      taxType:detectTaxType(text,lines),
      amount:detectAmount(lines,text),
      dueDate:detectDueDate(lines,text),
      period:detectPeriod(lines,text),
      documentNo:detectDocumentNo(lines),
      textLength:norm(text).length
    };
  }

  function fill(name,value){
    if(!form||value===null||value===undefined||value==='') return false;
    var input=form.querySelector('[name="'+name+'"]');
    if(!input) return false;
    input.value=String(value);
    input.dispatchEvent(new Event('input',{bubbles:true}));
    input.dispatchEvent(new Event('change',{bubbles:true}));
    return true;
  }

  if(fileInput){
    fileInput.addEventListener('change',function(){
      var file=fileInput.files&&fileInput.files[0];
      if(!file){setStatus('Belgeyi seçtiğinde okumaya başlayacağım.','');return;}
      if(file.size>10*1024*1024){setStatus('Dosya 10 MB sınırını aşıyor.','danger');return;}
      setStatus('Belge hazırlanıyor...','loading');
      readFile(file)
        .then(function(text){
          var parsed=parseDocument(text);
          fill('tax_type',parsed.taxType);
          fill('tax_period',parsed.period);
          fill('document_no',parsed.documentNo);
          fill('due_date',parsed.dueDate);
          if(parsed.amount!==null) fill('amount',formatMoney(parsed.amount));

          var found=[];
          if(parsed.taxType) found.push('vergi türü');
          if(parsed.period) found.push('dönem');
          if(parsed.dueDate) found.push('vade');
          if(parsed.amount!==null) found.push('tutar');
          if(parsed.documentNo) found.push('belge no');
          if(parsed.amount===null){
            setStatus('Belge okundu fakat tutar kesin bulunamadı. Tutarı elle yazıp diğer alanları kontrol et.','warning');
          }else{
            setStatus('Belge okundu: '+found.join(', ')+' bulundu. Kaydetmeden önce bilgileri kontrol et.','success');
          }
        })
        .catch(function(error){setStatus(error.message||'Belge okunamadı. Bilgileri elle girebilirsin.','danger');});
    });
  }

  document.querySelectorAll('[data-vergi-paid-open]').forEach(function(button){
    button.addEventListener('click',function(){
      var id=button.getAttribute('data-vergi-paid-open');
      var paidForm=document.querySelector('[data-vergi-paid-form="'+id+'"]');
      if(paidForm){paidForm.hidden=false;var select=paidForm.querySelector('select[name="account_id"]');if(select) select.focus();}
    });
  });
  document.querySelectorAll('[data-vergi-paid-close]').forEach(function(button){
    button.addEventListener('click',function(){var paidForm=button.closest('[data-vergi-paid-form]');if(paidForm) paidForm.hidden=true;});
  });
  document.querySelectorAll('[data-vergi-paid-form]').forEach(function(paidForm){
    paidForm.addEventListener('click',function(event){if(event.target===paidForm) paidForm.hidden=true;});
    paidForm.addEventListener('submit',function(event){
      var account=paidForm.querySelector('select[name="account_id"]');
      if(account&&!account.value){event.preventDefault();window.alert('Paranın düşeceği banka veya kasa hesabını seçmelisin.');account.focus();return;}
      var button=paidForm.querySelector('button[type="submit"]');
      if(button){button.disabled=true;button.textContent='Hesaptan düşülüyor...';}
    });
  });
  document.addEventListener('keydown',function(event){
    if(event.key!=='Escape') return;
    document.querySelectorAll('[data-vergi-paid-form]').forEach(function(paidForm){paidForm.hidden=true;});
  });
})();
