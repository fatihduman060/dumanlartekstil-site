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
    }).filter(function(value){return value!==null;});
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

  function matchCari(fullText){
    var nums=taxNumbers(fullText).filter(function(value){return value!==companyTaxNo;});
    for(var i=0;i<nums.length;i++){
      var found=cariler.find(function(cari){return String(cari.tax_no||'').replace(/\D/g,'')===nums[i];});
      if(found) return {id:String(found.id),confidence:'Vergi numarası eşleşti',tone:'success'};
    }

    var haystack=norm(fullText);
    var options=cariler.map(function(cari){
      return {cari:cari,key:norm(cari.name||'')};
    }).filter(function(item){return item.key.length>=6;});
    options.sort(function(a,b){return b.key.length-a.key.length;});
    for(var j=0;j<options.length;j++){
      if(haystack.indexOf(options[j].key)!==-1){
        return {id:String(options[j].cari.id),confidence:'Firma adı eşleşti',tone:'warning'};
      }
    }
    return {id:'',confidence:'Cari bulunamadı; listeden seç',tone:'danger'};
  }

  function extractInvoice(lines,fileName){
    if(window.FaturaOkumaCore){
      var safeFullText=lines.join('\n');
      var safe=window.FaturaOkumaCore.extractInvoice(lines,{
        fileName:fileName||'',
        companyTaxNo:companyTaxNo
      });
      var safeCari=matchCari(safeFullText);
      return {
        direction:selectedDirection(safe.direction||'gelen'),
        detected_direction:safe.direction||'',
        cari_id:safeCari.id,
        match_text:safeCari.confidence,
        match_tone:safeCari.tone,
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
        parse_meta:safe.meta||{}
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
    var cariMatch=matchCari(fullText);
    var detectedDirection=detectDirection(fullText);

    return {
      direction:selectedDirection(detectedDirection),
      detected_direction:detectedDirection,
      cari_id:cariMatch.id,
      match_text:cariMatch.confidence,
      match_tone:cariMatch.tone,
      invoice_no:invoiceNo,
      invoice_date:isoDate(dateText),
      due_date:'',
      subtotal:subtotalValue,
      vat_amount:vatValue,
      total_amount:totalValue,
      currency:currency,
      description:'Toplu PDF fatura yüklemesi'
    };
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
      match_text:'Cari kontrol edilecek',
      match_tone:'warning',
      invoice_no:'',
      invoice_date:'',
      due_date:'',
      subtotal:null,
      vat_amount:null,
      total_amount:null,
      currency:'TL',
      description:'Toplu PDF fatura yüklemesi'
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
      var criticalCount=(parsed.critical_issues||[]).length;
      var warningCount=(parsed.warnings||[]).length;
      records[index]=Object.assign(base,parsed,{
        status:criticalCount?'Eksik bilgi — kontrol gerekli':(warningCount?'Okundu — kontrol gerekli':'Bilgiler güvenli okundu'),
        status_tone:criticalCount?'danger':(warningCount?'warning':'success')
      });
    }catch(error){
      records[index]=Object.assign(base,{
        status:'PDF okunamadı; alanları elle tamamla',
        status_tone:'danger',
        match_text:error&&error.message?error.message:'PDF okunamadı'
      });
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
    return '<article class="bulk-invoice-row" data-index="'+record.index+'">'
      +'<div class="bulk-row-head"><div><strong>'+esc(record.file_name)+'</strong><small class="bulk-status is-'+esc(record.status_tone)+'">'+esc(record.status)+'</small></div><span>'+(record.index+1)+'. PDF</span></div>'
      +'<div class="bulk-row-grid">'
      +'<label>Yön<select data-field="direction"><option value="gelen" '+(record.direction==='gelen'?'selected':'')+'>Gelen fatura</option><option value="giden" '+(record.direction==='giden'?'selected':'')+'>Giden fatura</option></select></label>'
      +'<label class="bulk-cari-field">Cari<select data-field="cari_id">'+cariOptions(record.cari_id)+'</select><small class="is-'+esc(record.match_tone)+'">'+esc(record.match_text)+'</small></label>'
      +'<label>Fatura no<input data-field="invoice_no" value="'+esc(record.invoice_no)+'" placeholder="Fatura no"></label>'
      +'<label>Fatura tarihi<input type="date" data-field="invoice_date" value="'+esc(record.invoice_date)+'"></label>'
      +'<label>Vade tarihi<input type="date" data-field="due_date" value="'+esc(record.due_date)+'"></label>'
      +'<label>Matrah<input data-field="subtotal" inputmode="decimal" value="'+esc(formatMoney(record.subtotal))+'" placeholder="0,00"></label>'
      +'<label>KDV<input data-field="vat_amount" inputmode="decimal" value="'+esc(formatMoney(record.vat_amount))+'" placeholder="0,00"></label>'
      +'<label>Genel toplam<input data-field="total_amount" inputmode="decimal" value="'+esc(formatMoney(record.total_amount))+'" placeholder="0,00"></label>'
      +'<label>Para birimi<select data-field="currency"><option value="TL" '+(record.currency==='TL'?'selected':'')+'>TL</option><option value="USD" '+(record.currency==='USD'?'selected':'')+'>USD</option><option value="EUR" '+(record.currency==='EUR'?'selected':'')+'>EUR</option></select></label>'
      +'</div>'
      +'<p class="bulk-row-foot"><strong>'+esc(directionNote)+'.</strong> Bu kayıt yalnızca fatura arşivine kaydedilecek; cariye otomatik işlenmeyecek.</p>'
      +'</article>';
  }

  function render(){
    rowsEl.innerHTML=records.filter(Boolean).map(rowHtml).join('');
    var completed=records.filter(Boolean).filter(function(record){return record.status!=='Okunuyor';}).length;
    var total=records.filter(Boolean).length;
    var outgoing=records.filter(Boolean).filter(function(record){return record.direction==='giden';}).length;
    var incoming=total-outgoing;
    summaryEl.textContent=total?completed+' / '+total+' PDF hazır · '+outgoing+' giden · '+incoming+' gelen. Yönleri ve sarı/kırmızı kayıtları kontrol et.':'Henüz PDF seçilmedi.';
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
    render();
  });

  form.addEventListener('submit',function(event){
    if(parsing){event.preventDefault();window.alert('PDF okuma işlemi henüz tamamlanmadı.');return;}
    var payload=records.map(function(record){
      return {
        direction:record.direction||'gelen',
        cari_id:record.cari_id||'',
        invoice_no:record.invoice_no||'',
        invoice_date:record.invoice_date||'',
        due_date:record.due_date||'',
        subtotal:record.subtotal==null?'':record.subtotal,
        vat_amount:record.vat_amount==null?'':record.vat_amount,
        total_amount:record.total_amount==null?'':record.total_amount,
        currency:record.currency||'TL',
        description:record.description||'Toplu PDF fatura yüklemesi'
      };
    });

    var parseMoney=window.FaturaOkumaCore
      ?window.FaturaOkumaCore.parseMoney
      :function(value){var number=Number(value);return Number.isFinite(number)?number:null;};
    var missing=payload.filter(function(item){
      var no=String(item.invoice_no||'').trim();
      var subtotalValue=parseMoney(item.subtotal);
      var vatValue=parseMoney(item.vat_amount);
      var totalValue=parseMoney(item.total_amount);
      return !no
        ||/^(TICARETSICILNO|TICARETSICILNUMARASI|ETTN|UUID|VKN|TCKN|MERSISNO)$/i.test(no.replace(/[^A-Z0-9]/gi,''))
        ||!item.invoice_date
        ||subtotalValue===null
        ||vatValue===null
        ||totalValue===null
        ||totalValue<=0;
    });
    if(missing.length){
      event.preventDefault();
      window.alert(missing.length+' faturada numara, tarih, matrah, KDV veya genel toplam eksik/hatalı. Bu kayıtlar düzeltilmeden kaydedilemez.');
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
    +'.bulk-invoice-panel{margin-bottom:20px}.bulk-file-picker{border:2px dashed #d8c6a5;background:#fffaf2;border-radius:18px;padding:18px;display:grid;gap:6px;cursor:pointer}.bulk-file-picker strong{font-size:16px}.bulk-file-picker small{color:var(--muted)}.bulk-file-picker input{margin-top:8px}.bulk-direction-mode{display:grid;grid-template-columns:1fr minmax(260px,420px);gap:14px;align-items:center;border:1px solid #d8c6a5;background:#fff;border-radius:15px;padding:13px 14px}.bulk-direction-mode>div{display:grid;gap:4px}.bulk-direction-mode strong{font-size:13px}.bulk-direction-mode small{font-size:11px;color:var(--muted)}.bulk-direction-mode select{border:1px solid var(--border);border-radius:11px;padding:10px 11px;background:#fff}.bulk-upload-note{border:1px solid #f1d59b;background:#fff4dc;color:#714e15;border-radius:13px;padding:12px 14px;font-size:12px}.bulk-summary{font-weight:800;color:#514a40}.bulk-invoice-rows{display:grid;gap:12px}.bulk-invoice-row{border:1px solid var(--border);border-radius:17px;background:#fff;overflow:hidden}.bulk-row-head{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;padding:12px 14px;background:#faf7f1;border-bottom:1px solid var(--border)}.bulk-row-head>div{display:grid;gap:4px}.bulk-row-head strong{font-size:13px;overflow-wrap:anywhere}.bulk-row-head>span{font-size:11px;font-weight:900;color:var(--muted)}.bulk-status{font-size:10px;font-weight:900}.bulk-status.is-success,.bulk-cari-field small.is-success{color:var(--success)}.bulk-status.is-warning,.bulk-cari-field small.is-warning{color:#8a5b0a}.bulk-status.is-danger,.bulk-cari-field small.is-danger{color:var(--danger)}.bulk-status.is-loading{color:#23598b}.bulk-row-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:14px}.bulk-row-grid label{display:grid;gap:5px;font-size:11px;font-weight:850;color:#554d42}.bulk-row-grid input,.bulk-row-grid select{width:100%;border:1px solid var(--border);background:#fff;border-radius:11px;padding:10px 11px}.bulk-cari-field{grid-column:span 2}.bulk-cari-field small{font-size:10px}.bulk-row-foot{margin:0;padding:0 14px 13px;color:var(--muted);font-size:10px}.bulk-row-foot strong{color:#514a40}.bulk-actions{position:sticky;bottom:0;background:rgba(255,255,255,.94);backdrop-filter:blur(8px);padding:12px 0 2px;z-index:2}@media(max-width:1050px){.bulk-row-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:720px){.bulk-direction-mode{grid-template-columns:1fr}}@media(max-width:640px){.bulk-row-grid{grid-template-columns:1fr}.bulk-cari-field{grid-column:auto}.bulk-row-head{grid-template-columns:1fr}}';
  document.head.appendChild(style);

  addDirectionMode();
})();
