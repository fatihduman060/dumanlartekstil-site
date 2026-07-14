(function(){
  if(!/\/fatura-toplu-yukle\.php$/i.test(location.pathname)) return;

  var form=document.getElementById('bulkInvoiceForm');
  if(!form) return;
  var fileInput=document.getElementById('bulkInvoiceFiles');
  var rowsEl=document.getElementById('bulkInvoiceRows');
  var summaryEl=document.getElementById('bulkInvoiceSummary');
  var saveButton=document.getElementById('bulkInvoiceSave');
  var itemsInput=document.getElementById('bulkInvoiceItems');
  var cariler=Array.isArray(window.BITKE_BULK_CARILER)?window.BITKE_BULK_CARILER:[];
  var companyTaxNo=String(window.BITKE_COMPANY_TAX_NO||'').replace(/\D/g,'');
  var records=[];
  var parsing=false;
  var directionMode=null;

  function esc(value){
    return String(value==null?'':value).replace(/[&<>\"]/g,function(char){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[char];
    });
  }

  function norm(value){
    return String(value||'')
      .toLocaleUpperCase('tr-TR')
      .replace(/İ/g,'I').replace(/Ş/g,'S').replace(/Ğ/g,'G').replace(/Ü/g,'U').replace(/Ö/g,'O').replace(/Ç/g,'C')
      .replace(/[^A-Z0-9]+/g,' ')
      .replace(/\s+/g,' ')
      .trim();
  }

  function loadPdfJs(){
    if(window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    return new Promise(function(resolve,reject){
      var old=document.querySelector('script[data-bulk-pdfjs]');
      if(old){
        old.addEventListener('load',function(){resolve(window.pdfjsLib);},{once:true});
        old.addEventListener('error',reject,{once:true});
        return;
      }
      var script=document.createElement('script');
      script.src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
      script.setAttribute('data-bulk-pdfjs','1');
      script.onload=function(){
        if(!window.pdfjsLib){reject(new Error('PDF okuma kütüphanesi yüklenemedi.'));return;}
        window.pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        resolve(window.pdfjsLib);
      };
      script.onerror=function(){reject(new Error('PDF okuma kütüphanesi yüklenemedi.'));};
      document.head.appendChild(script);
    });
  }

  function financialLabelKind(value){
    var key=norm(value);
    var kinds=[];
    if(/(^| )ODENECEK( TUTAR| TOPLAM)?( |$)/.test(key)) kinds.push('payable');
    if(/(^| )(GENEL TOPLAM|FATURA TOPLAMI|VERGILER DAHIL TOPLAM)( |$)/.test(key)) kinds.push('total');
    if(/(^| )(MAL HIZMET TOPLAM|VERGILER HARIC TOPLAM|TOPLAM MATRAH)( |$)/.test(key)) kinds.push('subtotal');
    if(/(^| )KDV( |$)/.test(key)&&!/MATRAH|ORAN|DAHIL|HARIC|TEVKIFAT/.test(key)) kinds.push('vat');
    kinds=kinds.filter(function(kind,index){return kinds.indexOf(kind)===index;});
    return kinds.length===1?kinds[0]:'';
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
      row.items.push({x:x,width:Number(item.width||0),text:text});
    });
    rows.sort(function(a,b){return b.y-a.y;});
    var prepared=rows.map(function(row){
      row.items.sort(function(a,b){return a.x-b.x;});
      var chunks=[];
      var current=[];
      var rightEdge=null;
      row.items.forEach(function(item){
        var gap=rightEdge===null?0:item.x-rightEdge;
        if(current.length&&gap>64){chunks.push(current);current=[];}
        current.push(item);
        rightEdge=Math.max(rightEdge===null?item.x:rightEdge,item.x+Math.max(0,item.width));
      });
      if(current.length) chunks.push(current);
      return {
        y:row.y,
        chunks:chunks.map(function(parts){
          var text=parts.map(function(part){return part.text;}).join(' ').replace(/\s+/g,' ').trim();
          return {text:text,x:parts[0].x,right:Math.max.apply(null,parts.map(function(part){return part.x+Math.max(0,part.width);}))};
        }).filter(function(chunk){return !!chunk.text;})
      };
    });

    var lines=[];
    prepared.forEach(function(row,rowIndex){
      row.chunks.forEach(function(chunk){lines.push(chunk.text);});

      // Bazı telefon faturalarında tablo başlığı ile tutar bir alt satırda,
      // aynı sütunda yer alıyor. Sütun merkezlerini eşleyip okuyucuya
      // "KDV(%20) 51,31" gibi güvenli bir sentetik satır ver.
      var next=prepared[rowIndex+1];
      if(!next||Math.abs(row.y-next.y)>42) return;
      var usedValues={};
      row.chunks.forEach(function(labelChunk){
        if(!financialLabelKind(labelChunk.text)) return;
        if(moneyCandidates(labelChunk.text).length) return;
        var labelCenter=(labelChunk.x+labelChunk.right)/2;
        var best=null;
        next.chunks.forEach(function(valueChunk,valueIndex){
          if(usedValues[valueIndex]||/%/.test(valueChunk.text)) return;
          var amounts=moneyCandidates(valueChunk.text);
          if(amounts.length!==1) return;
          var valueCenter=(valueChunk.x+valueChunk.right)/2;
          var distance=Math.abs(labelCenter-valueCenter);
          var overlap=Math.min(labelChunk.right,valueChunk.right)-Math.max(labelChunk.x,valueChunk.x);
          if(overlap<0) return;
          if(!best||distance<best.distance) best={chunk:valueChunk,index:valueIndex,distance:distance};
        });
        if(best){
          usedValues[best.index]=true;
          lines.push(labelChunk.text+' '+best.chunk.text);
        }
      });
    });
    return lines.filter(Boolean);
  }

  function moneyCandidates(text){
    var source=String(text||'');
    var regex=/-?\d[\d.\s]*(?:,\d{2})|-?\d[\d,\s]*(?:\.\d{2})/g;
    var rows=[];
    var match;
    while((match=regex.exec(source))!==null){
      if(/%\s*$/.test(source.slice(Math.max(0,match.index-4),match.index))) continue;
      var raw=match[0];
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
      if(Number.isFinite(number)) rows.push(number);
      if(match.index===regex.lastIndex) regex.lastIndex++;
    }
    return rows;
  }

  function findAmount(lines,labels){
    var labelNorms=labels.map(norm);
    for(var i=0;i<lines.length;i++){
      var lineNorm=norm(lines[i]);
      if(!labelNorms.some(function(label){return lineNorm.indexOf(label)!==-1;})) continue;
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
    if(value===null||value===undefined||value==='') return '';
    return Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});
  }

  function taxNoOccurrences(fullText){
    var text=String(fullText||'');
    var regex=/(?:\d[\s.\/-]*){10,11}/g;
    var rows=[];
    var match;
    while((match=regex.exec(text))!==null){
      var digits=match[0].replace(/\D/g,'');
      if(digits.length===10||digits.length===11){
        rows.push({value:digits,index:match.index});
      }
      if(match.index===regex.lastIndex) regex.lastIndex++;
    }
    return rows;
  }

  function taxNumbers(fullText){
    return Array.from(new Set(taxNoOccurrences(fullText).map(function(row){return row.value;})));
  }

  function hasNear(text,index,words,radius){
    if(index<0) return false;
    var start=Math.max(0,index-radius);
    var end=Math.min(text.length,index+radius);
    var context=text.slice(start,end);
    return words.some(function(word){return context.indexOf(word)!==-1;});
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
      if(companyIndex<upper.length*0.42) return 'giden';
    }

    var companyNameIndex=Math.max(
      upper.indexOf('DUMANLAR KONFEKSIYON'),
      upper.indexOf('DUMANLAR A S'),
      upper.indexOf('DUMANLAR TEKSTIL')
    );
    if(companyNameIndex>=0&&companyNameIndex<upper.length*0.38) return 'giden';

    return 'gelen';
  }

  function selectedDirection(detected){
    var mode=directionMode?directionMode.value:'auto';
    return mode==='giden'||mode==='gelen'?mode:detected;
  }

  function companyKey(value){
    return norm(value)
      .replace(/\b(ANONIM SIRKETI|LIMITED SIRKETI|LTD STI|A S)\b/g,' ')
      .replace(/\b(ANONIM|LIMITED|SIRKETI|SIRKET|LTD|STI|AS|SANAYI|SAN|TICARET|TIC|VE|HIZMETLERI)\b/g,' ')
      .replace(/\s+/g,' ')
      .trim();
  }

  function isOwnOrGenericName(value){
    var key=norm(value);
    return /(^| )(DUMANLAR|BITKE|MOFIY|BAFIY)( |$)/.test(key)
      ||/(^| )MUHTELIF( FATURA)?( GIRISI)?( |$)/.test(key);
  }

  function isMiscCari(cari){
    return !!cari&&/(^| )MUHTELIF( FATURA)?( GIRISI)?( |$)/.test(norm(cari.name||''));
  }

  function selectedCari(id){
    return cariler.find(function(cari){return String(cari.id)===String(id||'');})||null;
  }

  function matchCari(fullText,issuerName,direction){
    if(direction!=='gelen') return {id:'',confidence:'Giden faturada alıcı carisini seç',tone:'warning'};

    var issuerKey=norm(issuerName);
    var issuerCompanyKey=companyKey(issuerName);
    if(issuerKey&&!isOwnOrGenericName(issuerName)){
      var exact=cariler.filter(function(cari){
        if(isMiscCari(cari)||isOwnOrGenericName(cari.name||'')) return false;
        var cariKey=norm(cari.name||'');
        var cariCompanyKey=companyKey(cari.name||'');
        return cariKey===issuerKey
          ||(issuerCompanyKey.length>=4&&cariCompanyKey===issuerCompanyKey);
      });
      if(exact.length===1){
        return {id:String(exact[0].id),confidence:'Gönderen firma mevcut cariyle eşleşti',tone:'success'};
      }
    }

    // Gönderen adı henüz bulunamadıysa yalnız PDF'de açıkça ve tam olarak
    // geçen tek cari adını öner. Belgedeki ilk 10/11 haneli sayıya göre
    // eşleştirme yapılmaz; bu sayılar alıcıya veya farklı bir kuruma ait olabilir.
    if(!issuerKey){
      var haystack=norm(fullText);
      var named=cariler.map(function(cari){
        return {cari:cari,key:norm(cari.name||'')};
      }).filter(function(item){
        return item.key.length>=8&&!isMiscCari(item.cari)&&!isOwnOrGenericName(item.cari.name||'')&&haystack.indexOf(item.key)!==-1;
      });
      if(named.length===1){
        return {id:String(named[0].cari.id),confidence:'PDF’deki tam firma adı eşleşti',tone:'warning'};
      }
    }

    var misc=cariler.find(isMiscCari);
    if(issuerKey&&misc){
      return {id:String(misc.id),confidence:'Gönderen ayrı kaydedildi; MUHTELİF carisi seçildi',tone:'success'};
    }
    return {id:'',confidence:issuerKey?'Gönderen bulundu; cari seçimi isteğe bağlı':'Gönderen ve cari kontrol edilmeli',tone:'warning'};
  }

  function refreshIssuerForDirection(record){
    if(!record||record.direction!=='gelen'||record.issuer_name||record.issuer_source==='manual') return;
    if(!window.FaturaOkumaCore||!record.raw_text||typeof window.FaturaOkumaCore.extractIssuer!=='function') return;
    var issuer=window.FaturaOkumaCore.extractIssuer(String(record.raw_text).split('\n'),{
      direction:'gelen',
      companyTaxNo:companyTaxNo,
      ownNames:['DUMANLAR','BİTKE','MOFİY','BAFİY']
    });
    if(!issuer||!issuer.name) return;
    record.issuer_name=String(issuer.name).trim();
    record.issuer_source='pdf';
    record.issuer_confidence=Number(issuer.confidence||0);
    record.issuer_parser_version=String(window.FaturaOkumaCore.version||'');
    record.issuer_warnings=issuer.warnings||[];
  }

  function extractInvoice(lines,fileName){
    if(window.FaturaOkumaCore){
      var safeFullText=lines.join('\n');
      var forcedDirection=directionMode&&/^(gelen|giden)$/.test(directionMode.value)?directionMode.value:'';
      var safe=window.FaturaOkumaCore.extractInvoice(lines,{
        fileName:fileName||'',
        companyTaxNo:companyTaxNo,
        direction:forcedDirection
      });
      var safeDirection=selectedDirection(safe.direction||forcedDirection||'gelen');
      var safeIssuer=safeDirection==='gelen'?String(safe.issuerName||'').trim():'';
      var safeCari=matchCari(safeFullText,safeIssuer,safeDirection);
      return {
        direction:safeDirection,
        detected_direction:safe.direction||'',
        cari_id:safeCari.id,
        cari_auto_selected:!!safeCari.id,
        match_text:safeCari.confidence,
        match_tone:safeCari.tone,
        issuer_name:safeIssuer,
        issuer_source:safeIssuer?'pdf':'',
        issuer_confidence:safeIssuer?Number(safe.issuerConfidence||0):0,
        issuer_parser_version:safeIssuer?String(safe.version||window.FaturaOkumaCore.version||''):'',
        issuer_warnings:safe.issuerWarnings||[],
        invoice_no:safe.invoiceNo,
        invoice_date:safe.invoiceDate,
        due_date:'',
        subtotal:safe.subtotal,
        vat_amount:safe.vat,
        total_amount:safe.total,
        currency:safe.currency,
        description:'Toplu PDF fatura yüklemesi',
        critical_issues:safe.criticalIssues||[],
        warnings:safe.warnings||[],
        needs_ocr:!!safe.needsOcr,
        can_auto_save:!!safe.canAutoSave,
        parse_meta:safe.meta||{},
        raw_text:safeFullText
      };
    }

    var fullText=lines.join('\n');
    var invoiceNo=findTextAfter(lines,
      ['Fatura No','Fatura Numarası','Belge No','E-Arşiv Fatura No','E-Fatura No'],
      /\b([A-Z0-9]{8,40})\b/i
    );
    if(invoiceNo&&/^(ETTN|VKN|TCKN)$/i.test(invoiceNo)) invoiceNo='';

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
    var currency=upper.indexOf(' EUR')!==-1||upper.indexOf(' EURO')!==-1?'EUR':(upper.indexOf(' USD')!==-1||upper.indexOf(' DOLAR')!==-1?'USD':'TL');
    var detectedDirection=detectDirection(fullText);
    var fallbackDirection=selectedDirection(detectedDirection);
    var cariMatch=matchCari(fullText,'',fallbackDirection);

    return {
      direction:fallbackDirection,
      detected_direction:detectedDirection,
      cari_id:cariMatch.id,
      cari_auto_selected:!!cariMatch.id,
      match_text:cariMatch.confidence,
      match_tone:cariMatch.tone,
      issuer_name:'',
      issuer_source:'',
      issuer_confidence:0,
      issuer_parser_version:'',
      issuer_warnings:[],
      invoice_no:invoiceNo,
      invoice_date:isoDate(dateText),
      due_date:'',
      subtotal:subtotalValue,
      vat_amount:vatValue,
      total_amount:totalValue,
      currency:currency,
      description:'Toplu PDF fatura yüklemesi',
      raw_text:fullText,
      critical_issues:[],
      warnings:['Güvenli PDF okuyucu yüklenemedi; alanları kontrol et.']
    };
  }

  function parsedMoney(value){
    if(window.FaturaOkumaCore) return window.FaturaOkumaCore.parseMoney(value);
    if(value===null||value===undefined||value==='') return null;
    var number=Number(value);
    return Number.isFinite(number)?number:null;
  }

  function invalidInvoiceNo(value){
    var no=String(value||'').trim();
    var compact=no.replace(/[^A-Z0-9]/gi,'').toUpperCase();
    return !no
      ||/^(TICARETSICILNO|TICARETSICILNUMARASI|ETTN|UUID|VKN|TCKN|MERSISNO|\d{10,11})$/.test(compact);
  }

  function recordMissingFields(record){
    var missing=[];
    if(invalidInvoiceNo(record.invoice_no)) missing.push('Fatura no');
    if(!record.invoice_date) missing.push('Tarih');
    if(parsedMoney(record.subtotal)===null) missing.push('Matrah');
    if(parsedMoney(record.vat_amount)===null) missing.push('KDV');
    var total=parsedMoney(record.total_amount);
    if(total===null||total<=0) missing.push('Genel toplam');
    if(record.direction==='gelen'&&!String(record.issuer_name||'').trim()){
      var cari=selectedCari(record.cari_id);
      if(!cari||isMiscCari(cari)) missing.push('Gönderen firma');
    }
    return missing;
  }

  function recordWarnings(record){
    var warnings=[];
    (record.warnings||[]).concat(record.issuer_warnings||[]).forEach(function(message){
      if(message&&warnings.indexOf(message)===-1) warnings.push(message);
    });
    if(record.direction==='gelen'&&record.issuer_name&&Number(record.issuer_confidence||0)>0&&Number(record.issuer_confidence||0)<75){
      warnings.push('Gönderen firma düşük güvenle okundu.');
    }
    return warnings;
  }

  function updateRecordStatus(record){
    if(!record||record.status==='Okunuyor') return;
    var missing=recordMissingFields(record);
    var warnings=recordWarnings(record);
    record.missing_fields=missing;
    if(missing.length){
      record.status='Eksik: '+missing.join(', ');
      record.status_tone='danger';
    }else if(warnings.length){
      record.status='Bilgiler okundu — kontrol önerilir';
      record.status_tone='warning';
    }else{
      record.status='Bilgiler hazır';
      record.status_tone='success';
    }
  }

  async function parseFile(file,index){
    var defaultDirection=selectedDirection('gelen');
    var base={
      index:index,
      file:file,
      file_name:file.name,
      status:'Okunuyor',
      status_tone:'loading',
      direction:defaultDirection,
      detected_direction:'gelen',
      cari_id:'',
      cari_auto_selected:false,
      match_text:'Cari kontrol edilecek',
      match_tone:'warning',
      issuer_name:'',
      issuer_source:'',
      issuer_confidence:0,
      issuer_parser_version:'',
      issuer_warnings:[],
      invoice_no:'',
      invoice_date:'',
      due_date:'',
      subtotal:null,
      vat_amount:null,
      total_amount:null,
      currency:'TL',
      description:'Toplu PDF fatura yüklemesi',
      critical_issues:[],
      warnings:[],
      missing_fields:[],
      raw_text:''
    };
    records[index]=base;
    render();

    try{
      var pdfjs=await loadPdfJs();
      var buffer=await file.arrayBuffer();
      var pdf=await pdfjs.getDocument({data:buffer}).promise;
      var lines=[];
      var pageCount=Math.min(pdf.numPages,5);
      for(var pageNo=1;pageNo<=pageCount;pageNo++){
        var page=await pdf.getPage(pageNo);
        var content=await page.getTextContent();
        lines=lines.concat(pageLines(content));
      }
      var parsed=extractInvoice(lines,file.name);
      records[index]=Object.assign(base,parsed,{
        status:'Okuma tamamlandı',
        status_tone:'success'
      });
      updateRecordStatus(records[index]);
    }catch(error){
      records[index]=Object.assign(base,{
        status:'PDF okunamadı — alanları elle tamamla',
        status_tone:'danger',
        match_text:error&&error.message?error.message:'PDF okunamadı',
        warnings:['PDF otomatik okunamadı.']
      });
      updateRecordStatus(records[index]);
    }
    render();
  }

  function cariOptions(selected){
    var html='<option value="">Cari seçilmedi</option>';
    cariler.forEach(function(cari){
      html+='<option value="'+esc(cari.id)+'" '+(String(selected)===String(cari.id)?'selected':'')+'>'+esc(cari.name)+' — '+esc(cari.cari_type||'Firma')+'</option>';
    });
    return html;
  }

  function rowHtml(record){
    var directionNote=record.direction==='giden'?'Bizim kestiğimiz fatura':'Bize kesilen fatura';
    var issuerHint='Gelen faturada gönderen firma zorunludur.';
    var issuerTone='warning';
    if(record.direction==='giden'){
      issuerHint='Giden faturada gönderen firmamızdır.';
      issuerTone='success';
    }else if(record.issuer_name&&record.issuer_source==='manual'){
      issuerHint='Kullanıcı tarafından düzeltildi.';
      issuerTone='success';
    }else if(record.issuer_name){
      issuerHint='PDF’den okundu'+(Number(record.issuer_confidence||0)>0?' · %'+Math.round(Number(record.issuer_confidence||0))+' güven':'');
      issuerTone=Number(record.issuer_confidence||0)>=75?'success':'warning';
    }
    var missing=record.missing_fields||recordMissingFields(record);
    return '<article class="bulk-invoice-row" data-index="'+record.index+'">'
      +'<div class="bulk-row-head"><div><strong>'+esc(record.file_name)+'</strong><small class="bulk-status is-'+esc(record.status_tone)+'">'+esc(record.status)+'</small></div><span>'+(record.index+1)+'. PDF</span></div>'
      +'<div class="bulk-row-grid">'
      +'<label>Yön<select data-field="direction"><option value="gelen" '+(record.direction==='gelen'?'selected':'')+'>Gelen fatura</option><option value="giden" '+(record.direction==='giden'?'selected':'')+'>Giden fatura</option></select></label>'
      +'<label class="bulk-cari-field">Cari<select data-field="cari_id">'+cariOptions(record.cari_id)+'</select><small class="is-'+esc(record.match_tone)+'">'+esc(record.match_text)+'</small></label>'
      +'<label class="bulk-issuer-field">Gönderen firma<input data-field="issuer_name" maxlength="180" value="'+esc(record.issuer_name)+'" placeholder="PDF’den otomatik okunur; gerekirse yaz" '+(record.direction==='giden'?'disabled':'')+'><small class="is-'+esc(issuerTone)+'">'+esc(issuerHint)+'</small></label>'
      +'<label>Fatura no<input data-field="invoice_no" value="'+esc(record.invoice_no)+'" placeholder="Fatura no"></label>'
      +'<label>Fatura tarihi<input type="date" data-field="invoice_date" value="'+esc(record.invoice_date)+'"></label>'
      +'<label>Vade tarihi<input type="date" data-field="due_date" value="'+esc(record.due_date)+'"></label>'
      +'<label>Matrah<input data-field="subtotal" inputmode="decimal" value="'+esc(formatMoney(record.subtotal))+'" placeholder="0,00"></label>'
      +'<label>KDV<input data-field="vat_amount" inputmode="decimal" value="'+esc(formatMoney(record.vat_amount))+'" placeholder="0,00"></label>'
      +'<label>Genel toplam<input data-field="total_amount" inputmode="decimal" value="'+esc(formatMoney(record.total_amount))+'" placeholder="0,00"></label>'
      +'<label>Para birimi<select data-field="currency"><option value="TL" '+(record.currency==='TL'?'selected':'')+'>TL</option><option value="USD" '+(record.currency==='USD'?'selected':'')+'>USD</option><option value="EUR" '+(record.currency==='EUR'?'selected':'')+'>EUR</option></select></label>'
      +'</div>'
      +(missing.length?'<p class="bulk-row-issues"><strong>Tamamlanması gerekenler:</strong> '+esc(missing.join(', '))+'</p>':'')
      +'<p class="bulk-row-foot"><strong>'+esc(directionNote)+'.</strong> Bu kayıt yalnızca fatura arşivine kaydedilecek; cariye otomatik işlenmeyecek.</p>'
      +'</article>';
  }

  function render(){
    rowsEl.innerHTML=records.filter(Boolean).map(rowHtml).join('');
    var completed=records.filter(Boolean).filter(function(record){return record.status!=='Okunuyor';}).length;
    var total=records.filter(Boolean).length;
    var outgoing=records.filter(Boolean).filter(function(record){return record.direction==='giden';}).length;
    var incoming=total-outgoing;
    var missingCount=records.filter(Boolean).filter(function(record){return record.status!=='Okunuyor'&&recordMissingFields(record).length>0;}).length;
    summaryEl.textContent=total?completed+' / '+total+' PDF hazır · '+outgoing+' giden · '+incoming+' gelen · '+missingCount+' eksik kayıt. Kırmızı satırlarda eksik alanlar açıkça gösterilir.':'Henüz PDF seçilmedi.';
    saveButton.disabled=parsing||total===0||completed!==total;
  }

  function addDirectionMode(){
    var picker=form.querySelector('.bulk-file-picker');
    if(!picker||document.getElementById('bulkDirectionMode')) return;
    var box=document.createElement('div');
    box.className='bulk-direction-mode';
    box.innerHTML='<div><strong>Bu yüklemedeki faturaların yönü</strong><small>Kestiğimiz faturalar için “Giden”, bize kesilenler için “Gelen” seç. Karışık dosyalarda otomatik kullan.</small></div>'
      +'<select id="bulkDirectionMode"><option value="auto">Otomatik tespit et</option><option value="giden">Hepsi bizim kestiğimiz — Giden</option><option value="gelen">Hepsi bize kesilen — Gelen</option></select>';
    picker.insertAdjacentElement('afterend',box);
    directionMode=box.querySelector('select');
    directionMode.addEventListener('change',function(){
      records.forEach(function(record){
        if(!record) return;
        record.direction=directionMode.value==='auto'?(record.detected_direction||record.direction):directionMode.value;
        refreshIssuerForDirection(record);
        if(record.direction==='giden'&&isMiscCari(selectedCari(record.cari_id))){
          record.cari_id='';
          record.cari_auto_selected=false;
          record.match_text='Giden faturada alıcı carisini seç';
          record.match_tone='warning';
        }else if(record.direction==='gelen'&&!record.cari_id){
          var match=matchCari(record.raw_text||'',record.issuer_name||'',record.direction);
          record.cari_id=match.id;
          record.cari_auto_selected=!!match.id;
          record.match_text=match.confidence;
          record.match_tone=match.tone;
        }
        updateRecordStatus(record);
      });
      render();
    });
  }

  fileInput.addEventListener('change',function(){
    var files=Array.from(fileInput.files||[]);
    if(files.length>50){
      window.alert('Tek seferde en fazla 50 PDF seçebilirsin.');
      fileInput.value='';
      records=[];
      render();
      return;
    }
    records=[];
    parsing=true;
    saveButton.disabled=true;
    var cursor=0;
    async function worker(){
      while(cursor<files.length){
        var index=cursor++;
        var file=files[index];
        if(file.type!=='application/pdf'&&!/\.pdf$/i.test(file.name)){
          var dir=selectedDirection('gelen');
          records[index]={index:index,file:file,file_name:file.name,status:'PDF değil',status_tone:'danger',direction:dir,detected_direction:'',cari_id:'',match_text:'Yalnızca PDF seç',match_tone:'danger',invoice_no:'',invoice_date:'',due_date:'',subtotal:null,vat_amount:null,total_amount:null,currency:'TL',description:'Toplu PDF fatura yüklemesi',critical_issues:['Yalnızca PDF dosyası seçilebilir.'],warnings:[]};
          render();
          continue;
        }
        await parseFile(file,index);
      }
    }
    var workerCount=Math.min(3,Math.max(1,files.length));
    Promise.all(Array.from({length:workerCount},worker)).finally(function(){parsing=false;render();});
  });

  rowsEl.addEventListener('input',function(event){
    var field=event.target.getAttribute('data-field');
    if(!field) return;
    var row=event.target.closest('[data-index]');
    if(!row) return;
    var index=Number(row.getAttribute('data-index'));
    if(!records[index]) return;
    var value=event.target.value;
    if(window.FaturaOkumaCore&&/^(subtotal|vat_amount|total_amount)$/.test(field)){
      value=window.FaturaOkumaCore.parseMoney(value);
    }
    records[index][field]=value;
    if(field==='issuer_name'){
      records[index].issuer_source='manual';
      records[index].issuer_confidence=100;
      records[index].issuer_parser_version='';
      records[index].issuer_warnings=[];
    }
  });
  rowsEl.addEventListener('change',function(event){
    var field=event.target.getAttribute('data-field');
    if(!field) return;
    var row=event.target.closest('[data-index]');
    if(!row) return;
    var index=Number(row.getAttribute('data-index'));
    if(!records[index]) return;
    var value=event.target.value;
    if(window.FaturaOkumaCore&&/^(subtotal|vat_amount|total_amount)$/.test(field)){
      value=window.FaturaOkumaCore.parseMoney(value);
    }
    records[index][field]=value;
    if(field==='issuer_name'){
      records[index].issuer_name=String(value||'').replace(/\s+/g,' ').trim();
      records[index].issuer_source=records[index].issuer_name?'manual':'';
      records[index].issuer_confidence=records[index].issuer_name?100:0;
      records[index].issuer_parser_version='';
      records[index].issuer_warnings=[];
      if(!records[index].cari_id||records[index].cari_auto_selected){
        var issuerMatch=matchCari(records[index].raw_text||'',records[index].issuer_name,records[index].direction);
        records[index].cari_id=issuerMatch.id;
        records[index].cari_auto_selected=!!issuerMatch.id;
        records[index].match_text=issuerMatch.confidence;
        records[index].match_tone=issuerMatch.tone;
      }
    }
    if(field==='cari_id'){
      var chosen=selectedCari(value);
      records[index].cari_auto_selected=false;
      records[index].match_text=chosen?'Cari kullanıcı tarafından seçildi':'Cari seçimi kaldırıldı';
      records[index].match_tone=chosen?'success':'warning';
    }
    if(field==='direction'){
      refreshIssuerForDirection(records[index]);
      if(value==='giden'&&isMiscCari(selectedCari(records[index].cari_id))){
        records[index].cari_id='';
        records[index].cari_auto_selected=false;
        records[index].match_text='Giden faturada alıcı carisini seç';
        records[index].match_tone='warning';
      }else if(value==='gelen'&&!records[index].cari_id){
        var directionMatch=matchCari(records[index].raw_text||'',records[index].issuer_name||'',value);
        records[index].cari_id=directionMatch.id;
        records[index].cari_auto_selected=!!directionMatch.id;
        records[index].match_text=directionMatch.confidence;
        records[index].match_tone=directionMatch.tone;
      }
    }
    updateRecordStatus(records[index]);
    render();
  });

  form.addEventListener('submit',function(event){
    if(parsing){event.preventDefault();window.alert('PDF okuma işlemi henüz tamamlanmadı.');return;}
    var payload=records.map(function(record){
      return {
        client_version:4,
        direction:record.direction||'gelen',
        cari_id:record.cari_id||'',
        invoice_no:record.invoice_no||'',
        invoice_date:record.invoice_date||'',
        due_date:record.due_date||'',
        subtotal:record.subtotal==null?'':record.subtotal,
        vat_amount:record.vat_amount==null?'':record.vat_amount,
        total_amount:record.total_amount==null?'':record.total_amount,
        currency:record.currency||'TL',
        description:record.description||'Toplu PDF fatura yüklemesi',
        issuer_name:record.direction==='gelen'?String(record.issuer_name||'').trim():'',
        issuer_source:record.direction==='gelen'?(record.issuer_source||''):'',
        issuer_confidence:record.direction==='gelen'?Number(record.issuer_confidence||0):0,
        issuer_parser_version:record.direction==='gelen'?(record.issuer_parser_version||''):''
      };
    });

    var parseMoney=window.FaturaOkumaCore
      ?window.FaturaOkumaCore.parseMoney
      :function(value){var number=Number(value);return Number.isFinite(number)?number:null;};
    var missing=records.map(function(record,index){
      return {index:index,fields:recordMissingFields(record)};
    }).filter(function(item){return item.fields.length>0;});
    if(missing.length){
      event.preventDefault();
      var examples=missing.slice(0,3).map(function(item){
        return (item.index+1)+'. PDF: '+item.fields.join(', ');
      }).join(' | ');
      window.alert(missing.length+' faturada eksik bilgi var. '+examples+' Bu alanlar tamamlanmadan kayıt yapılmayacak.');
      return;
    }

    var invalidMoney=payload.filter(function(item){
      var subtotalValue=parseMoney(item.subtotal);
      var vatValue=parseMoney(item.vat_amount);
      var totalValue=parseMoney(item.total_amount);
      return subtotalValue===null||vatValue===null||totalValue===null||totalValue<=0;
    });
    if(invalidMoney.length){
      event.preventDefault();
      window.alert(invalidMoney.length+' faturada tutar alanları geçersiz. Matrah, KDV ve genel toplamı kontrol et.');
      return;
    }
    var outgoing=payload.filter(function(item){return item.direction==='giden';}).length;
    var incoming=payload.length-outgoing;
    if(!window.confirm(payload.length+' fatura arşive kaydedilecek: '+outgoing+' giden, '+incoming+' gelen. Cari hareket oluşturulmayacak. Devam edilsin mi?')){
      event.preventDefault();
      return;
    }
    itemsInput.value=JSON.stringify(payload);
    saveButton.disabled=true;
    saveButton.textContent='Faturalar kaydediliyor...';
  });

  var style=document.createElement('style');
  style.textContent=''
    +'.bulk-invoice-panel{margin-bottom:20px}.bulk-file-picker{border:2px dashed #d8c6a5;background:#fffaf2;border-radius:18px;padding:18px;display:grid;gap:6px;cursor:pointer}.bulk-file-picker strong{font-size:16px}.bulk-file-picker small{color:var(--muted)}.bulk-file-picker input{margin-top:8px}.bulk-direction-mode{display:grid;grid-template-columns:1fr minmax(260px,420px);gap:14px;align-items:center;border:1px solid #d8c6a5;background:#fff;border-radius:15px;padding:13px 14px}.bulk-direction-mode>div{display:grid;gap:4px}.bulk-direction-mode strong{font-size:13px}.bulk-direction-mode small{font-size:11px;color:var(--muted)}.bulk-direction-mode select{border:1px solid var(--border);border-radius:11px;padding:10px 11px;background:#fff}.bulk-upload-note{border:1px solid #f1d59b;background:#fff4dc;color:#714e15;border-radius:13px;padding:12px 14px;font-size:12px}.bulk-summary{font-weight:800;color:#514a40}.bulk-invoice-rows{display:grid;gap:12px}.bulk-invoice-row{border:1px solid var(--border);border-radius:17px;background:#fff;overflow:hidden}.bulk-row-head{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;padding:12px 14px;background:#faf7f1;border-bottom:1px solid var(--border)}.bulk-row-head>div{display:grid;gap:4px}.bulk-row-head strong{font-size:13px;overflow-wrap:anywhere}.bulk-row-head>span{font-size:11px;font-weight:900;color:var(--muted)}.bulk-status{font-size:10px;font-weight:900}.bulk-status.is-success,.bulk-cari-field small.is-success,.bulk-issuer-field small.is-success{color:var(--success)}.bulk-status.is-warning,.bulk-cari-field small.is-warning,.bulk-issuer-field small.is-warning{color:#8a5b0a}.bulk-status.is-danger,.bulk-cari-field small.is-danger,.bulk-issuer-field small.is-danger{color:var(--danger)}.bulk-status.is-loading{color:#23598b}.bulk-row-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:14px}.bulk-row-grid label{display:grid;gap:5px;font-size:11px;font-weight:850;color:#554d42}.bulk-row-grid input,.bulk-row-grid select{width:100%;border:1px solid var(--border);background:#fff;border-radius:11px;padding:10px 11px}.bulk-row-grid input:disabled{background:#f4f1ec;color:#81786c}.bulk-cari-field,.bulk-issuer-field{grid-column:span 2}.bulk-cari-field small,.bulk-issuer-field small{font-size:10px}.bulk-row-issues{margin:0 14px 10px;padding:9px 11px;border-radius:10px;background:#fff0ef;color:#96352f;font-size:10px}.bulk-row-foot{margin:0;padding:0 14px 13px;color:var(--muted);font-size:10px}.bulk-row-foot strong{color:#514a40}.bulk-actions{position:sticky;bottom:0;background:rgba(255,255,255,.94);backdrop-filter:blur(8px);padding:12px 0 2px;z-index:2}@media(max-width:1050px){.bulk-row-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:720px){.bulk-direction-mode{grid-template-columns:1fr}}@media(max-width:640px){.bulk-row-grid{grid-template-columns:1fr}.bulk-cari-field,.bulk-issuer-field{grid-column:auto}.bulk-row-head{grid-template-columns:1fr}}';
  document.head.appendChild(style);

  addDirectionMode();
})();
