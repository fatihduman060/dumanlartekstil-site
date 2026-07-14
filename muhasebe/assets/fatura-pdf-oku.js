(function(){
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  var form=document.getElementById('invoiceForm');
  if(!form) return;
  var fileInput=form.querySelector('input[name="document"]');
  if(!fileInput) return;

  var direction=form.querySelector('[name="direction"]');
  var invoiceNo=form.querySelector('[name="invoice_no"]');
  var invoiceDate=form.querySelector('[name="invoice_date"]');
  var subtotal=form.querySelector('[name="subtotal"]');
  var vat=form.querySelector('[name="vat_amount"]');
  var total=form.querySelector('[name="total_amount"]');
  var currency=form.querySelector('[name="currency"]');
  var cari=form.querySelector('[name="cari_id"]');
  var companyTaxNo=String(window.BITKE_COMPANY_TAX_NO||'3140036788').replace(/\D/g,'');

  function norm(value){
    return String(value||'')
      .toLocaleUpperCase('tr-TR')
      .replace(/İ/g,'I').replace(/Ş/g,'S').replace(/Ğ/g,'G').replace(/Ü/g,'U').replace(/Ö/g,'O').replace(/Ç/g,'C')
      .replace(/[^A-Z0-9]+/g,' ')
      .replace(/\s+/g,' ')
      .trim();
  }

  function esc(value){
    return String(value==null?'':value).replace(/[&<>\"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[c];});
  }

  function loadPdfJs(){
    if(window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    return new Promise(function(resolve,reject){
      var old=document.querySelector('script[data-fatura-pdfjs]');
      if(old){
        old.addEventListener('load',function(){resolve(window.pdfjsLib);},{once:true});
        old.addEventListener('error',reject,{once:true});
        return;
      }
      var script=document.createElement('script');
      script.src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
      script.setAttribute('data-fatura-pdfjs','1');
      script.onload=function(){
        if(!window.pdfjsLib){reject(new Error('PDF okuma kütüphanesi yüklenemedi.'));return;}
        window.pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        resolve(window.pdfjsLib);
      };
      script.onerror=function(){reject(new Error('PDF okuma kütüphanesi yüklenemedi.'));};
      document.head.appendChild(script);
    });
  }

  function pageLines(content){
    var rows=[];
    (content.items||[]).forEach(function(item){
      var text=String(item.str||'').trim();
      if(!text) return;
      var x=item.transform&&item.transform.length>4?Number(item.transform[4]||0):0;
      var y=item.transform&&item.transform.length>5?Number(item.transform[5]||0):0;
      var row=null;
      for(var i=0;i<rows.length;i++){
        if(Math.abs(rows[i].y-y)<=2.5){row=rows[i];break;}
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

  function moneyCandidates(text){
    var matches=String(text||'').match(/-?\d[\d.\s]*(?:,\d{2})|-?\d[\d,\s]*(?:\.\d{2})/g)||[];
    return matches.map(function(raw){
      var value=raw.replace(/\s/g,'');
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
      return Number.isFinite(number)?number:null;
    }).filter(function(v){return v!==null;});
  }

  function findAmount(lines,labels){
    var labelNorms=labels.map(norm);
    for(var i=0;i<lines.length;i++){
      var lineNorm=norm(lines[i]);
      var matched=labelNorms.some(function(label){return lineNorm.indexOf(label)!==-1;});
      if(!matched) continue;
      for(var offset=0;offset<=2;offset++){
        if(!lines[i+offset]) continue;
        var values=moneyCandidates(lines[i+offset]);
        if(values.length) return values[values.length-1];
      }
    }
    return null;
  }

  function findTextAfter(lines,labels,pattern){
    var labelNorms=labels.map(norm);
    for(var i=0;i<lines.length;i++){
      var line=lines[i];
      var lineNorm=norm(line);
      if(!labelNorms.some(function(label){return lineNorm.indexOf(label)!==-1;})) continue;
      var candidates=[line,lines[i+1]||'',lines[i+2]||''];
      for(var j=0;j<candidates.length;j++){
        var match=String(candidates[j]).match(pattern);
        if(match) return match[1]||match[0];
      }
    }
    return '';
  }

  function isoDate(value){
    var match=String(value||'').match(/(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})/);
    if(!match) return '';
    return match[3]+'-'+String(match[2]).padStart(2,'0')+'-'+String(match[1]).padStart(2,'0');
  }

  function formatMoney(value){
    return Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});
  }

  function taxNoOccurrences(fullText){
    var text=String(fullText||'');
    var regex=/(?:\d[\s.\/-]*){10,11}/g;
    var rows=[];
    var match;
    while((match=regex.exec(text))!==null){
      var digits=match[0].replace(/\D/g,'');
      if(digits.length===10||digits.length===11) rows.push({value:digits,index:match.index});
      if(match.index===regex.lastIndex) regex.lastIndex++;
    }
    return rows;
  }

  function hasNear(text,index,words,radius){
    if(index<0) return false;
    var start=Math.max(0,index-radius);
    var end=Math.min(text.length,index+radius);
    var context=text.slice(start,end);
    return words.some(function(word){return context.indexOf(word)!==-1;});
  }

  function firstIndex(text,values){
    var indexes=values.map(function(value){return text.indexOf(value);}).filter(function(index){return index>=0;});
    return indexes.length?Math.min.apply(Math,indexes):-1;
  }

  function detectDirection(fullText){
    var upper=norm(fullText);
    var occurrences=taxNoOccurrences(upper);
    var companyRows=occurrences.filter(function(row){return row.value===companyTaxNo;});
    var otherRows=occurrences.filter(function(row){return row.value!==companyTaxNo;});
    var companyIndex=companyRows.length?companyRows[0].index:-1;
    var otherIndex=otherRows.length?otherRows[0].index:-1;

    if(companyIndex>=0){
      if(hasNear(upper,companyIndex,['SATICI','SELLER','TEDARIKCI'],260)) return 'giden';
      if(hasNear(upper,companyIndex,['ALICI','SAYIN','MUSTERI','BUYER'],260)) return 'gelen';
      if(otherIndex>=0){
        if(companyIndex<otherIndex) return 'giden';
        if(companyIndex>otherIndex) return 'gelen';
      }
      if(companyIndex<upper.length*0.45) return 'giden';
    }

    var companyNameIndex=firstIndex(upper,[
      'DUMANLAR KONFEKSIYON',
      'DUMANLAR A S',
      'DUMANLAR TEKSTIL',
      'DUMANLAR'
    ]);
    if(companyNameIndex>=0){
      if(hasNear(upper,companyNameIndex,['SATICI','SELLER','TEDARIKCI'],300)) return 'giden';
      if(hasNear(upper,companyNameIndex,['ALICI','SAYIN','MUSTERI','BUYER'],220)) return 'gelen';
      var buyerIndex=firstIndex(upper,['ALICI','SAYIN','MUSTERI','BUYER']);
      if(buyerIndex>=0&&companyNameIndex<buyerIndex) return 'giden';
      if(companyNameIndex<upper.length*0.45) return 'giden';
    }

    return '';
  }

  function findCari(fullText){
    if(!cari) return false;
    var haystack=norm(fullText);
    var options=Array.from(cari.options).filter(function(option){return option.value;}).map(function(option){
      var name=option.textContent.split('—')[0].trim();
      return {option:option,name:name,key:norm(name)};
    }).filter(function(item){return item.key.length>=6;});
    options.sort(function(a,b){return b.key.length-a.key.length;});
    for(var i=0;i<options.length;i++){
      if(haystack.indexOf(options[i].key)!==-1){
        cari.value=options[i].option.value;
        return options[i].name;
      }
    }
    return false;
  }

  function extractInvoice(lines,fileName){
    if(window.FaturaOkumaCore){
      var safeFullText=lines.join('\n');
      var safe=window.FaturaOkumaCore.extractInvoice(lines,{
        fileName:fileName||'',
        companyTaxNo:companyTaxNo
      });
      return {
        direction:safe.direction,
        invoiceNo:safe.invoiceNo,
        invoiceDate:safe.invoiceDate,
        subtotal:safe.subtotal,
        vat:safe.vat,
        total:safe.total,
        currency:safe.currency,
        cariName:findCari(safeFullText),
        criticalIssues:safe.criticalIssues||[],
        warnings:safe.warnings||[],
        needsOcr:!!safe.needsOcr,
        canAutoSave:!!safe.canAutoSave,
        meta:safe.meta||{}
      };
    }

    var fullText=lines.join('\n');
    var no=findTextAfter(lines,
      ['Fatura No','Fatura Numarası','Belge No','E-Arşiv Fatura No','E-Fatura No'],
      /\b([A-Z0-9]{8,30})\b/i
    );
    if(no&&/^(ETTN|VKN|TCKN)$/i.test(no)) no='';

    var dateText=findTextAfter(lines,
      ['Fatura Tarihi','Düzenleme Tarihi','Belge Tarihi','Fatura Düzenleme Tarihi'],
      /(\d{1,2}[.\/-]\d{1,2}[.\/-]\d{4})/
    );
    if(!dateText){
      var generalDate=fullText.match(/\b(\d{1,2}[.\/-]\d{1,2}[.\/-]\d{4})\b/);
      dateText=generalDate?generalDate[1]:'';
    }

    var totalValue=findAmount(lines,['Ödenecek Tutar','Vergiler Dahil Toplam Tutar','Genel Toplam','Fatura Toplamı','Ödenecek Toplam']);
    var vatValue=findAmount(lines,['Hesaplanan KDV','KDV Toplamı','Toplam KDV','Hesaplanan Katma Değer Vergisi']);
    var subtotalValue=findAmount(lines,['Mal Hizmet Toplam Tutarı','Vergiler Hariç Toplam Tutar','KDV Matrahı','Ara Toplam','Matrah']);

    if(totalValue===null&&subtotalValue!==null&&vatValue!==null) totalValue=subtotalValue+vatValue;
    if(subtotalValue===null&&totalValue!==null&&vatValue!==null) subtotalValue=totalValue-vatValue;

    var upper=norm(fullText);
    var curr=upper.indexOf(' EUR')!==-1||upper.indexOf(' EURO')!==-1?'EUR':(upper.indexOf(' USD')!==-1||upper.indexOf(' DOLAR')!==-1?'USD':'TL');
    var cariName=findCari(fullText);

    return {
      direction:detectDirection(fullText),
      invoiceNo:no,
      invoiceDate:isoDate(dateText),
      subtotal:subtotalValue,
      vat:vatValue,
      total:totalValue,
      currency:curr,
      cariName:cariName
    };
  }

  var fileLabel=fileInput.closest('label');
  var box=document.createElement('div');
  box.className='fatura-pdf-okuma';
  box.innerHTML='<div class="fatura-pdf-baslik"><span>📄</span><div><strong>Fatura PDF’sini yükle</strong><small>PDF seçildiğinde yön, fatura no, tarih, matrah, KDV ve toplam otomatik okunmaya çalışılır.</small></div></div>'
    +'<div class="fatura-pdf-actions"><button type="button" class="btn btn-secondary" data-fatura-pdf-oku disabled>PDF’den bilgileri oku</button><a class="btn btn-secondary" data-fatura-pdf-onizle target="_blank" hidden>PDF’yi önizle</a></div>'
    +'<p class="fatura-pdf-status" data-fatura-pdf-status>Önce bilgisayarından PDF faturayı seç.</p>'
    +'<p class="fatura-pdf-uyari">Otomatik bulunan bilgileri kaydetmeden önce kontrol et. Görüntü olarak taranmış PDF’lerde alanlar elle tamamlanabilir.</p>';
  if(fileLabel) fileLabel.insertAdjacentElement('beforebegin',box);

  var readButton=box.querySelector('[data-fatura-pdf-oku]');
  var preview=box.querySelector('[data-fatura-pdf-onizle]');
  var status=box.querySelector('[data-fatura-pdf-status]');
  var previewUrl='';
  var lastReadResult=null;

  function setStatus(text,tone){
    status.textContent=text;
    status.className='fatura-pdf-status '+(tone?'is-'+tone:'');
  }

  function applyResult(result){
    lastReadResult=result;
    var found=[];
    if(result.direction&&direction){
      direction.value=result.direction;
      found.push(result.direction==='giden'?'giden yönü':'gelen yönü');
    }
    if(result.invoiceNo&&invoiceNo){invoiceNo.value=result.invoiceNo;found.push('fatura no');}
    if(result.invoiceDate&&invoiceDate){invoiceDate.value=result.invoiceDate;found.push('tarih');}
    if(result.subtotal!==null&&subtotal){subtotal.value=formatMoney(result.subtotal);found.push('matrah');}
    if(result.vat!==null&&vat){vat.value=formatMoney(result.vat);found.push('KDV');}
    [subtotal,vat].forEach(function(input){if(input) input.dispatchEvent(new Event('input',{bubbles:true}));});
    if(result.total!==null&&total){
      total.value=formatMoney(result.total);
      total.dataset.preserveTotal='1';
      total.dispatchEvent(new Event('input',{bubbles:true}));
      found.push('genel toplam');
    }
    if(result.currency&&currency){currency.value=result.currency;}
    if(result.cariName) found.push('cari eşleşmesi');

    var critical=Array.isArray(result.criticalIssues)?result.criticalIssues:[];
    var warnings=Array.isArray(result.warnings)?result.warnings:[];
    if(critical.length){
      setStatus('PDF güvenli okunamadı: '+critical.join(' '),'danger');
      return;
    }
    if(warnings.length){
      setStatus('PDF okundu; kaydetmeden önce kontrol et: '+warnings.join(' '),'warning');
      return;
    }
    if(found.length){
      var directionText=result.direction?(result.direction==='giden'?' Bizim kestiğimiz fatura olarak Giden seçildi.':' Bize kesilen fatura olarak Gelen seçildi.'):' Fatura yönünü ayrıca kontrol et.';
      setStatus('PDF güvenli okundu: '+found.join(', ')+' bulundu.'+directionText,'success');
    }else{
      setStatus('PDF açıldı ancak alanlar otomatik bulunamadı. Gerekli alanları elle tamamla.','warning');
    }
  }

  function readSelectedPdf(){
    var file=fileInput.files&&fileInput.files[0];
    if(!file){setStatus('Önce PDF dosyasını seç.','warning');return;}
    if(file.type!=='application/pdf'&&!/\.pdf$/i.test(file.name)){
      setStatus('Otomatik okuma yalnızca PDF dosyalarında çalışır.','warning');return;
    }
    readButton.disabled=true;
    readButton.textContent='PDF okunuyor...';
    setStatus('PDF içindeki fatura bilgileri ve yönü aranıyor...','loading');
    Promise.all([loadPdfJs(),file.arrayBuffer()])
      .then(function(values){
        var pdfjs=values[0];
        return pdfjs.getDocument({data:values[1]}).promise;
      })
      .then(async function(pdf){
        var lines=[];
        var pageCount=Math.min(pdf.numPages,5);
        for(var pageNo=1;pageNo<=pageCount;pageNo++){
          var page=await pdf.getPage(pageNo);
          var content=await page.getTextContent();
          lines=lines.concat(pageLines(content));
        }
        applyResult(extractInvoice(lines,file.name));
      })
      .catch(function(error){
        setStatus('PDF okunamadı: '+(error&&error.message?error.message:'Bilinmeyen hata')+'. Dosyayı yine yükleyebilir, alanları elle tamamlayabilirsin.','danger');
      })
      .finally(function(){
        readButton.disabled=false;
        readButton.textContent='PDF’den bilgileri tekrar oku';
      });
  }

  fileInput.addEventListener('change',function(){
    var file=fileInput.files&&fileInput.files[0];
    if(previewUrl){URL.revokeObjectURL(previewUrl);previewUrl='';}
    if(!file){
      readButton.disabled=true;
      preview.hidden=true;
      setStatus('Önce bilgisayarından PDF faturayı seç.');
      return;
    }
    var isPdf=file.type==='application/pdf'||/\.pdf$/i.test(file.name);
    readButton.disabled=!isPdf;
    if(isPdf){
      previewUrl=URL.createObjectURL(file);
      preview.href=previewUrl;
      preview.hidden=false;
      setStatus(file.name+' seçildi. Bilgiler ve fatura yönü otomatik okunuyor...','loading');
      readSelectedPdf();
    }else{
      preview.hidden=true;
      setStatus(file.name+' seçildi. Görsel faturalar arşive yüklenir; otomatik alan okuma PDF için çalışır.','warning');
    }
  });

  readButton.addEventListener('click',readSelectedPdf);

  form.addEventListener('submit',function(event){
    if(!lastReadResult) return;
    var core=window.FaturaOkumaCore;
    var noValue=invoiceNo?invoiceNo.value.trim():'';
    var noKey=norm(noValue).replace(/\s/g,'');
    var subtotalValue=core?core.parseMoney(subtotal&&subtotal.value):moneyCandidates(subtotal&&subtotal.value)[0];
    var vatValue=core?core.parseMoney(vat&&vat.value):moneyCandidates(vat&&vat.value)[0];
    var totalValue=core?core.parseMoney(total&&total.value):moneyCandidates(total&&total.value)[0];
    var invalidNo=!noValue||/^(TICARETSICILNO|TICARETSICILNUMARASI|ETTN|UUID|VKN|TCKN|MERSISNO)$/.test(noKey);
    if(invalidNo||!invoiceDate||!invoiceDate.value||subtotalValue===null||vatValue===null||totalValue===null||totalValue<=0){
      event.preventDefault();
      setStatus('Fatura numarası, tarih, matrah, KDV ve genel toplam kontrol edilmeden kayıt yapılamaz.','danger');
      return;
    }
    var needsReview=(lastReadResult.criticalIssues&&lastReadResult.criticalIssues.length)
      ||(lastReadResult.warnings&&lastReadResult.warnings.length);
    if(needsReview&&!window.confirm('Otomatik okumada kontrol uyarısı var. Belgedeki bilgileri tek tek doğruladın mı?')){
      event.preventDefault();
    }
  });

  window.addEventListener('beforeunload',function(){if(previewUrl) URL.revokeObjectURL(previewUrl);});

  var style=document.createElement('style');
  style.textContent='.fatura-pdf-okuma{border:1px solid #d8c6a5;background:linear-gradient(135deg,#fff7e8,#fff);border-radius:16px;padding:14px;display:grid;gap:11px}.fatura-pdf-baslik{display:grid;grid-template-columns:auto 1fr;gap:10px;align-items:center}.fatura-pdf-baslik>span{width:38px;height:38px;display:grid;place-items:center;border-radius:11px;background:#efe3cc;font-size:19px}.fatura-pdf-baslik>div{display:grid;gap:3px}.fatura-pdf-baslik strong{font-size:14px}.fatura-pdf-baslik small,.fatura-pdf-uyari{font-size:11px;color:var(--muted)}.fatura-pdf-actions{display:flex;gap:8px;flex-wrap:wrap}.fatura-pdf-actions .btn{padding:9px 12px;font-size:12px}.fatura-pdf-status{margin:0;padding:9px 11px;border-radius:10px;background:#f3efe7;color:#655e53;font-size:12px;font-weight:800}.fatura-pdf-status.is-success{background:#e8f5ed;color:#1f6b3d}.fatura-pdf-status.is-warning{background:#fff4dc;color:#835710}.fatura-pdf-status.is-danger{background:#fff0ef;color:#96352f}.fatura-pdf-status.is-loading{background:#eef5ff;color:#234f84}.fatura-pdf-uyari{margin:0}';
  document.head.appendChild(style);
})();
