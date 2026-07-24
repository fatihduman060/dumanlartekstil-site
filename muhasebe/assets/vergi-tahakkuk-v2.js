(function(){
  'use strict';
  if(!/\/vergi-odemeleri\.php$/i.test(location.pathname)) return;

  var form=document.getElementById('taxPaymentForm');
  var fileInput=form?form.querySelector('[data-vergi-document]'):null;
  var status=form?form.querySelector('[data-vergi-read-status]'):null;
  if(!form||!fileInput) return;

  function fold(value){
    return String(value||'').toLocaleUpperCase('tr-TR')
      .replace(/İ/g,'I').replace(/Ş/g,'S').replace(/Ğ/g,'G')
      .replace(/Ü/g,'U').replace(/Ö/g,'O').replace(/Ç/g,'C');
  }

  function norm(value){
    return fold(value).replace(/[^A-Z0-9]+/g,' ').replace(/\s+/g,' ').trim();
  }

  function loadPdfJs(){
    if(window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    return new Promise(function(resolve,reject){
      var old=document.querySelector('script[data-vergi-pdfjs-v2]');
      if(old){
        old.addEventListener('load',function(){resolve(window.pdfjsLib);},{once:true});
        old.addEventListener('error',reject,{once:true});
        return;
      }
      var script=document.createElement('script');
      script.src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
      script.setAttribute('data-vergi-pdfjs-v2','1');
      script.onload=function(){
        if(!window.pdfjsLib){reject(new Error('PDF okuma kütüphanesi yüklenemedi.'));return;}
        window.pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        resolve(window.pdfjsLib);
      };
      script.onerror=function(){reject(new Error('PDF okuma kütüphanesi yüklenemedi.'));};
      document.head.appendChild(script);
    });
  }

  function groupRows(items){
    var rows=[];
    (items||[]).forEach(function(item){
      var text=String(item.str||'').trim();
      if(!text) return;
      var x=Number(item.transform&&item.transform[4]||0);
      var y=Number(item.transform&&item.transform[5]||0);
      var width=Number(item.width||0);
      var row=null;
      for(var i=0;i<rows.length;i++){
        if(Math.abs(rows[i].y-y)<=3){row=rows[i];break;}
      }
      if(!row){row={y:y,items:[]};rows.push(row);}
      row.items.push({x:x,y:y,width:width,text:text});
    });
    rows.sort(function(a,b){return b.y-a.y;});
    rows.forEach(function(row){
      row.items.sort(function(a,b){return a.x-b.x;});
      row.text=row.items.map(function(item){return item.text;}).join(' ').replace(/\s+/g,' ').trim();
      row.key=norm(row.text);
    });
    return rows;
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
    }else if(dot>-1&&value.length-dot-1!==2){
      value=value.replace(/\./g,'');
    }
    var number=parseFloat(value);
    return Number.isFinite(number)?Math.round(number*100)/100:null;
  }

  function moneyItems(row){
    var result=[];
    (row.items||[]).forEach(function(item){
      var matches=String(item.text).match(/(?:\d{1,3}(?:[.\s]\d{3})+|\d+),\d{2}|(?:\d{1,3}(?:,\d{3})+|\d+)\.\d{2}/g)||[];
      matches.forEach(function(raw){
        var value=parseMoney(raw);
        if(value!==null) result.push({value:value,x:item.x,width:item.width,raw:raw});
      });
    });
    if(!result.length){
      var rowMatches=String(row.text||'').match(/(?:\d{1,3}(?:[.\s]\d{3})+|\d+),\d{2}|(?:\d{1,3}(?:,\d{3})+|\d+)\.\d{2}/g)||[];
      rowMatches.forEach(function(raw,index){
        var value=parseMoney(raw);
        if(value!==null) result.push({value:value,x:index,width:0,raw:raw});
      });
    }
    return result;
  }

  function isoDates(text){
    var result=[];
    var regex=/(\d{1,2})[.\/-](\d{1,2})[.\/-](20\d{2})/g;
    var match;
    while((match=regex.exec(String(text||'')))!==null){
      result.push(match[3]+'-'+String(match[2]).padStart(2,'0')+'-'+String(match[1]).padStart(2,'0'));
      if(match.index===regex.lastIndex) regex.lastIndex++;
    }
    return result;
  }

  function chooseFrequentDate(values){
    if(!values.length) return '';
    var counts={};
    values.forEach(function(value){counts[value]=(counts[value]||0)+1;});
    return Object.keys(counts).sort(function(a,b){
      if(counts[b]!==counts[a]) return counts[b]-counts[a];
      return b.localeCompare(a);
    })[0]||'';
  }

  function detectDueDate(rows){
    var headerIndex=-1;
    for(var i=0;i<rows.length;i++){
      if(/\b(VADESI|VADE TARIHI|SON ODEME TARIHI|ODEME VADESI)\b/.test(rows[i].key)){
        var same=isoDates(rows[i].text);
        if(same.length) return same[same.length-1];
        if(headerIndex<0) headerIndex=i;
      }
    }

    if(headerIndex>=0){
      var nearby=[];
      for(var j=headerIndex+1;j<Math.min(rows.length,headerIndex+18);j++){
        if(rows[j].key.indexOf('TOPLAM')!==-1) break;
        nearby=nearby.concat(isoDates(rows[j].text));
      }
      var chosen=chooseFrequentDate(nearby);
      if(chosen) return chosen;
    }

    var safe=[];
    rows.forEach(function(row){
      if(/KABUL TARIHI|ONAY TARIHI|DUZENLEME TARIHI|BEYANNAME TARIHI/.test(row.key)) return;
      safe=safe.concat(isoDates(row.text));
    });
    return chooseFrequentDate(safe);
  }

  function cleanCode(value){
    return String(value||'').replace(/^[\s:;-]+|[\s:;,.-]+$/g,'').toUpperCase();
  }

  function codeCandidates(text){
    var source=fold(text);
    var regexes=[
      /\b20\d{2}(?:[-\/][A-Z0-9]{1,16}){2,}\b/g,
      /\b[A-Z0-9]{2,}(?:[-\/][A-Z0-9]{2,}){2,}\b/g,
      /\b20\d{2}[A-Z]{2,}[A-Z0-9-]{5,}\b/g
    ];
    var result=[];
    regexes.forEach(function(regex){
      var matches=source.match(regex)||[];
      matches.forEach(function(value){
        value=cleanCode(value);
        if(value.length<8||/^\d+$/.test(value)) return;
        if(/^20\d{2}[-\/]\d{1,2}[-\/]\d{1,2}$/.test(value)) return;
        if(result.indexOf(value)===-1) result.push(value);
      });
    });
    return result;
  }

  function detectDocumentNo(rows,fileName){
    var labels=/TAHAKKUK (FIS )?(NO|NUMARASI)|TAHAKKUK REFERANS|BELGE (NO|NUMARASI)|BEYANNAME (NO|NUMARASI|TAKIP NO)|BARKOD (NO|NUMARASI)|REFERANS (NO|NUMARASI)|ISLEM (NO|NUMARASI)|SERI SIRA/;
    for(var i=0;i<rows.length;i++){
      if(!labels.test(rows[i].key)) continue;
      for(var offset=0;offset<=4;offset++){
        var row=rows[i+offset];
        if(!row) continue;
        var candidates=codeCandidates(row.text);
        if(candidates.length) return candidates[0];
      }
    }

    for(var top=0;top<Math.min(rows.length,45);top++){
      var topCandidates=codeCandidates(rows[top].text);
      if(topCandidates.length) return topCandidates[0];
    }

    var fileCandidates=codeCandidates(String(fileName||'').replace(/\.[^.]+$/,''));
    return fileCandidates[0]||'';
  }

  function detectAmount(rows){
    for(var i=rows.length-1;i>=0;i--){
      if(rows[i].key==='TOPLAM'||/TOPLAM ODENECEK|ODENECEK TOPLAM/.test(rows[i].key)){
        var totals=moneyItems(rows[i]).filter(function(item){return item.value>0;});
        if(totals.length) return totals[totals.length-1].value;
      }
    }

    var headerIndex=-1;
    var payableX=null;
    for(var h=0;h<rows.length;h++){
      if(rows[h].key.indexOf('ODENECEK OLAN')===-1) continue;
      headerIndex=h;
      for(var p=0;p<rows[h].items.length;p++){
        if(norm(rows[h].items[p].text).indexOf('ODENECEK')!==-1){
          payableX=rows[h].items[p].x;
          break;
        }
      }
      break;
    }

    if(headerIndex>=0){
      var values=[];
      for(var r=headerIndex+1;r<Math.min(rows.length,headerIndex+18);r++){
        if(rows[r].key.indexOf('TOPLAM')!==-1) break;
        var amounts=moneyItems(rows[r]).filter(function(item){return item.value>0;});
        if(!amounts.length) continue;
        var selected=null;
        if(payableX!==null){
          var candidates=amounts.filter(function(item){return item.x>=payableX-90;});
          if(candidates.length) selected=candidates[candidates.length-1];
        }
        if(!selected&&amounts.length>=3) selected=amounts[amounts.length-1];
        if(selected) values.push(selected.value);
      }
      if(values.length>=2){
        return Math.round(values.reduce(function(total,value){return total+value;},0)*100)/100;
      }
    }
    return null;
  }

  function formatMoney(value){
    return Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});
  }

  function setField(name,value){
    if(value===null||value===undefined||value==='') return;
    var input=form.querySelector('[name="'+name+'"]');
    if(!input) return;
    input.value=String(value);
    input.dispatchEvent(new Event('input',{bubbles:true}));
    input.dispatchEvent(new Event('change',{bubbles:true}));
  }

  function applyResult(result){
    if(result.amount!==null) setField('amount',formatMoney(result.amount));
    if(result.dueDate) setField('due_date',result.dueDate);
    if(result.documentNo) setField('document_no',result.documentNo);

    var found=[];
    if(result.amount!==null) found.push('toplam '+formatMoney(result.amount)+' TL');
    if(result.dueDate) found.push('vade');
    if(result.documentNo) found.push('belge no');
    if(status&&found.length){
      status.textContent='GİB tahakkuk kontrolü tamamlandı: '+found.join(', ')+' bulundu. Kaydetmeden önce kontrol et.';
      status.className='vergi-read-status is-success';
    }
  }

  function inspectPdf(file){
    return loadPdfJs().then(function(pdfjs){
      return file.arrayBuffer().then(function(buffer){return pdfjs.getDocument({data:buffer}).promise;});
    }).then(function(pdf){
      return pdf.getPage(1);
    }).then(function(page){
      return page.getTextContent();
    }).then(function(content){
      var rows=groupRows(content.items||[]);
      return {
        amount:detectAmount(rows),
        dueDate:detectDueDate(rows),
        documentNo:detectDocumentNo(rows,file.name||'')
      };
    });
  }

  fileInput.addEventListener('change',function(){
    var file=fileInput.files&&fileInput.files[0];
    if(!file||!(file.type==='application/pdf'||/\.pdf$/i.test(file.name))) return;
    window.setTimeout(function(){
      inspectPdf(file).then(function(result){
        applyResult(result);
        window.setTimeout(function(){applyResult(result);},900);
      }).catch(function(){/* Eski okuyucu elle girişe izin vermeye devam eder. */});
    },250);
  });
})();
