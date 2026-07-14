(function(){
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  var activeInvoiceId=0;
  var activeInvoiceInfo=null;
  var readRun=0;
  var companyTax=canonicalTax(String(window.BITKE_COMPANY_TAX_NO||'3140036788'));
  var taxAnchorPattern=/\b(VKN|TCKN|TCK NO|TC KIMLIK NO|T C KIMLIK NO|VERGI NO|VERGI NUMARASI|VERGI KIMLIK NO|VERGI KIMLIK NUMARASI|TAX NO)\b/;
  var taxStopPattern=/\b(MERSIS|TICARET SICIL|IBAN|FATURA NO|BELGE NO|ETTN|UUID|TELEFON|TARIH|SAAT|SIPARIS|IRSALIYE)\b/;

  function norm(value){
    return String(value||'')
      .toLocaleUpperCase('tr-TR')
      .replace(/İ/g,'I').replace(/Ş/g,'S').replace(/Ğ/g,'G').replace(/Ü/g,'U').replace(/Ö/g,'O').replace(/Ç/g,'C')
      .replace(/[^A-Z0-9]+/g,' ')
      .replace(/\s+/g,' ')
      .trim();
  }

  function canonicalTax(value){
    var digits=String(value||'').replace(/\D/g,'');
    if(digits.length===11&&digits.charAt(0)==='0') digits=digits.slice(1);
    return digits;
  }

  function setStatus(text,tone){
    var el=document.querySelector('#faturaCariModal .fatura-cari-status');
    if(!el) return;
    el.textContent=text||'';
    el.className='fatura-cari-status'+(tone?' is-'+tone:'');
  }

  function loadPdfJs(){
    if(window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    return new Promise(function(resolve,reject){
      var script=document.createElement('script');
      script.src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
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
      var row=rows.find(function(candidate){return Math.abs(candidate.y-y)<=2.5;});
      if(!row){row={y:y,items:[]};rows.push(row);}
      row.items.push({x:x,width:Number(item.width||0),text:text});
    });
    rows.sort(function(a,b){return b.y-a.y;});
    return rows.reduce(function(lines,row){
      row.items.sort(function(a,b){return a.x-b.x;});
      var chunks=[];
      var current='';
      var rightEdge=-Infinity;
      row.items.forEach(function(item){
        var gap=item.x-rightEdge;
        if(current&&gap>64){chunks.push(current);current='';}
        current+=(current?' ':'')+item.text;
        rightEdge=Math.max(item.x+Math.max(0,item.width),item.x);
      });
      if(current) chunks.push(current);
      return lines.concat(chunks.map(function(line){return line.replace(/\s+/g,' ').trim();}).filter(Boolean));
    },[]);
  }

  function isAddressLine(line){
    var key=norm(line);
    return /\b(MAH|MAHALLESI|CAD|CADDESI|SOK|SOKAK|BULVAR|BLV|KAT|DAIRE|MEVKII|KOYU|ILCE|POSTA KODU)\b/.test(key)
      || /\b(NO|NO SU|NUMARA)\b/.test(key)
      || /ORGANIZE SANAYI BOLGESI|\bOSB\b/.test(key);
  }

  function isBadCompanyLine(line){
    var raw=String(line||'').trim();
    var key=norm(raw);
    if(raw.length<4||raw.length>120) return true;
    if(isAddressLine(raw)) return true;
    if(/DUMANLAR|BITKE|MOFIY|BAFIY/.test(key)) return true;
    if(/FATURA|ETTN|UUID|SENARYO|TARIH|SAAT|IRSALIYE|VERGI|VKN|TCKN|MERSIS|TICARET SICIL|IBAN|BANKA|TOPLAM|KDV|MATRAH|TELEFON|E POSTA|EMAIL/.test(key)) return true;
    if(/^\d+$/.test(raw.replace(/\D/g,''))&&raw.replace(/\D/g,'').length>=8) return true;
    return false;
  }

  function companyScore(line){
    if(isBadCompanyLine(line)) return -999;
    var raw=String(line||'').replace(/^\s*(SAYIN|ALICI|SATICI|MUSTERI|BUYER|SELLER|TEDARIKCI)\s*[:\-]?\s*/i,'').trim();
    var key=norm(raw);
    var score=Math.min(raw.length,50);
    if(/\b(SAN|SANAYI|TIC|TICARET|LTD|LIMITED|AS|ANONIM|STI|SIRKETI|KOOP|PAZARLAMA|GIDA|TEKSTIL|INSAAT|ELEKTRIK|OTOMOTIV|TURIZM)\b/.test(key)) score+=100;
    if(/[&]/.test(raw)) score+=8;
    return score;
  }

  function numberParts(line){
    var rows=[];
    var regex=/(^|[^\d])((?:\d[\s.\/-]*){10,11})(?![\s.\/-]*\d)/g;
    var match;
    while((match=regex.exec(String(line||'')))!==null){
      rows.push(match[2]);
      if(match.index===regex.lastIndex) regex.lastIndex++;
    }
    return rows;
  }

  function validTckn(value){
    if(!/^\d{11}$/.test(value)||value.charAt(0)==='0') return false;
    var digits=value.split('').map(Number);
    var odd=digits[0]+digits[2]+digits[4]+digits[6]+digits[8];
    var even=digits[1]+digits[3]+digits[5]+digits[7];
    var tenth=((odd*7-even)%10+10)%10;
    var eleventh=digits.slice(0,10).reduce(function(sum,digit){return sum+digit;},0)%10;
    return tenth===digits[9]&&eleventh===digits[10];
  }

  function validVkn(value){
    if(!/^\d{10}$/.test(value)) return false;
    var digits=value.split('').map(Number);
    var sum=0;
    for(var i=0;i<9;i++){
      var shifted=(digits[i]+9-i)%10;
      var weighted=(shifted*Math.pow(2,9-i))%9;
      if(shifted!==0&&weighted===0) weighted=9;
      sum+=weighted;
    }
    return (10-(sum%10))%10===digits[9];
  }

  function validTax(value,label){
    var tcknLabel=/\b(TCKN|TCK NO|TC KIMLIK NO|T C KIMLIK NO)\b/.test(label);
    var vknLabel=/\b(VKN|VERGI NO|VERGI NUMARASI|VERGI KIMLIK NO|VERGI KIMLIK NUMARASI|TAX NO)\b/.test(label);
    if(tcknLabel&&vknLabel) return validVkn(value)||validTckn(value);
    if(tcknLabel) return validTckn(value);
    if(vknLabel) return validVkn(value);
    return validVkn(value)||validTckn(value);
  }

  function isNumericFragment(value){
    return /^[\s.:\/-]*\d(?:[\d\s.\/-]*\d)?[\s.:\/-]*$/.test(String(value||''));
  }

  function labeledTaxCandidates(lines,direction){
    var rows=[];
    var expected=direction==='giden'?['ALICI','SAYIN','MUSTERI','BUYER']:['SATICI','TEDARIKCI','SELLER'];
    var ownMarkers=['DUMANLAR','BITKE','MOFIY','BAFIY'];

    lines.forEach(function(line,index){
      var label=norm(line);
      if(!taxAnchorPattern.test(label)) return;
      var combined=String(line);
      for(var offset=1;offset<=2&&lines[index+offset];offset++){
        var next=String(lines[index+offset]).trim();
        var nextKey=norm(next);
        if(taxStopPattern.test(nextKey)||taxAnchorPattern.test(nextKey)||!isNumericFragment(next)) break;
        combined+=' '+next;
      }
      numberParts(combined).forEach(function(match){
        var tax=canonicalTax(match);
        if(tax===companyTax||!validTax(tax,label)) return;
        var around=norm(lines.slice(Math.max(0,index-6),Math.min(lines.length,index+7)).join(' '));
        var score=160;
        if(expected.some(function(marker){return around.indexOf(marker)!==-1;})) score+=60;
        if(ownMarkers.some(function(marker){return around.indexOf(marker)!==-1;})) score-=75;
        rows.push({tax:tax,index:index,score:score,anchored:true});
      });
    });
    return rows;
  }

  function extractTaxCandidates(lines,direction){
    var candidates=labeledTaxCandidates(lines,direction);
    var expected=direction==='giden'?['ALICI','SAYIN','MUSTERI','BUYER']:['SATICI','TEDARIKCI','SELLER'];
    var ownMarkers=['DUMANLAR','BITKE','MOFIY','BAFIY'];

    lines.forEach(function(line,index){
      numberParts(line).forEach(function(match){
        var tax=canonicalTax(match);
        if(tax.length!==10&&tax.length!==11) return;
        if(tax===companyTax) return;
        var lineKey=norm(line);
        var around=norm(lines.slice(Math.max(0,index-6),Math.min(lines.length,index+7)).join(' '));
        if(!validTax(tax,lineKey)) return;
        var score=20;
        if(taxAnchorPattern.test(lineKey)) score+=80;
        if(expected.some(function(marker){return around.indexOf(marker)!==-1;})) score+=55;
        if(ownMarkers.some(function(marker){return around.indexOf(marker)!==-1;})) score-=90;
        if(/FATURA NO|BELGE NO|ETTN|UUID/.test(around)) score-=100;
        if(/TICARET SICIL|MERSIS|IBAN|SIPARIS|IRSALIYE/.test(around)) score-=150;
        if(!taxAnchorPattern.test(lineKey)&&!expected.some(function(marker){return around.indexOf(marker)!==-1;})) return;
        candidates.push({tax:tax,index:index,score:score});
      });
    });

    var unique={};
    candidates.forEach(function(item){
      if(!unique[item.tax]||unique[item.tax].score<item.score) unique[item.tax]=item;
    });
    return Object.keys(unique)
      .map(function(key){return unique[key];})
      .filter(function(item){return item.score>=45;})
      .sort(function(a,b){return b.score-a.score;});
  }

  function stripRolePrefix(value){
    return String(value||'').replace(/^\s*(SAYIN|ALICI|SATICI|MUSTERI|BUYER|SELLER|TEDARIKCI)\s*[:\-]?\s*/i,'').trim();
  }

  function findJoinedCompanyName(lines,taxIndex,direction){
    var best={text:'',score:-999};
    var start=Math.max(0,taxIndex-9);
    for(var index=start;index<taxIndex;index++){
      for(var length=1;length<=3&&index+length<=taxIndex;length++){
        var originals=lines.slice(index,index+length);
        var parts=originals.map(stripRolePrefix).filter(Boolean);
        if(!parts.length||parts.some(isBadCompanyLine)) continue;
        var text=parts.join(' ').replace(/\s+/g,' ').trim();
        var key=norm(text);
        var words=key.split(' ').filter(Boolean);
        if(words.length<2||/DUMANLAR|BITKE|MOFIY|BAFIY/.test(key)) continue;
        var score=Math.min(text.length,80)-Math.abs(taxIndex-(index+length-1))*3;
        if(/\b(SAN|SANAYI|TIC|TICARET|LTD|LIMITED|AS|ANONIM|STI|SIRKETI|KOOP|PAZARLAMA|GIDA|TEKSTIL)\b/.test(key)) score+=100;
        var originalKey=norm(originals.join(' '));
        var markers=direction==='giden'?/\b(ALICI|SAYIN|MUSTERI|BUYER)\b/:/\b(SATICI|TEDARIKCI|SELLER)\b/;
        if(markers.test(originalKey)) score+=35;
        if(score>best.score) best={text:text,score:score};
      }
    }
    return best.score>=70?best.text:'';
  }

  function findMarkerCompany(lines,direction){
    var markerPattern=direction==='giden'?/\b(ALICI|SAYIN|MUSTERI|BUYER)\b/:/\b(SATICI|TEDARIKCI|SELLER)\b/;
    var best={name:'',index:-1,score:-999};
    lines.forEach(function(line,index){
      if(!markerPattern.test(norm(line))) return;
      for(var offset=0;offset<=3;offset++){
        for(var length=1;length<=3&&index+offset+length<=lines.length;length++){
          var parts=lines.slice(index+offset,index+offset+length).map(stripRolePrefix).filter(Boolean);
          if(!parts.length||parts.some(isBadCompanyLine)) continue;
          var text=parts.join(' ').replace(/\s+/g,' ').trim();
          var key=norm(text);
          if(key.split(' ').filter(Boolean).length<2) continue;
          var score=Math.min(text.length,80)-offset*5;
          if(/\b(SAN|SANAYI|TIC|TICARET|LTD|LIMITED|AS|ANONIM|STI|SIRKETI|KOOP|PAZARLAMA|GIDA|TEKSTIL)\b/.test(key)) score+=100;
          if(score>best.score) best={name:text,index:index+offset,score:score};
        }
      }
    });
    return best.score>=70?best:{name:'',index:-1,score:best.score};
  }

  function findCompanyName(lines,taxIndex,direction){
    var best={text:'',score:-999};
    var start=Math.max(0,taxIndex-8);
    var end=Math.min(lines.length,taxIndex+5);
    for(var i=start;i<end;i++){
      var score=companyScore(lines[i]);
      if(i<taxIndex) score+=18;
      score-=Math.abs(i-taxIndex)*2;
      if(score>best.score) best={text:String(lines[i]).replace(/^\s*(SAYIN|ALICI|SATICI|MUSTERI|BUYER|SELLER|TEDARIKCI)\s*[:\-]?\s*/i,'').trim(),score:score};
    }

    if(best.score<35){
      var markers=direction==='giden'?['ALICI','SAYIN','MUSTERI','BUYER']:['SATICI','TEDARIKCI','SELLER'];
      lines.forEach(function(line,index){
        var key=norm(line);
        if(!markers.some(function(marker){return key.indexOf(marker)!==-1;})) return;
        for(var offset=0;offset<=4;offset++){
          var candidate=lines[index+offset];
          if(!candidate) continue;
          var score=companyScore(candidate)-offset*3;
          if(score>best.score) best={text:String(candidate).replace(/^\s*(SAYIN|ALICI|SATICI|MUSTERI|BUYER|SELLER|TEDARIKCI)\s*[:\-]?\s*/i,'').trim(),score:score};
        }
      });
    }
    var joined=findJoinedCompanyName(lines,taxIndex,direction);
    if(joined) return joined;
    return best.score>=35?best.text:'';
  }

  function nearbyLines(lines,index,radius){
    return lines.slice(Math.max(0,index-radius),Math.min(lines.length,index+radius+1));
  }

  function findTaxOffice(lines){
    for(var i=0;i<lines.length;i++){
      var key=norm(lines[i]);
      if(key.indexOf('VERGI DAIRESI')===-1&&key.indexOf('VERGI D')===-1) continue;
      var raw=String(lines[i]);
      var after=raw.split(/[:\-]/).slice(1).join(' ').replace(/\b(VKN|TCKN|VERGI NO).*$/i,'').trim();
      if(after.length>=2&&after.length<=50&&!isAddressLine(after)) return after;
    }
    return '';
  }

  function findEmail(text){
    var matches=String(text||'').match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/ig)||[];
    return matches.find(function(mail){return !/DUMANLAR|BITKE|MOFIY|BAFIY/i.test(mail);})||'';
  }

  function findPhone(text,taxNo){
    var matches=String(text||'').match(/(?:\+?90[\s().-]*)?(?:0\s*)?\(?\d{3}\)?[\s().-]+\d{3}[\s.-]+\d{2}[\s.-]+\d{2}/g)||[];
    for(var i=0;i<matches.length;i++){
      var digits=canonicalTax(matches[i]);
      if(digits===taxNo||digits===companyTax) continue;
      return matches[i].replace(/\s+/g,' ').trim();
    }
    return '';
  }

  function findCity(lines){
    var cities=['ADANA','ADIYAMAN','AFYONKARAHISAR','AGRI','AMASYA','ANKARA','ANTALYA','ARTVIN','AYDIN','BALIKESIR','BILECIK','BINGOL','BITLIS','BOLU','BURDUR','BURSA','CANAKKALE','CANKIRI','CORUM','DENIZLI','DIYARBAKIR','EDIRNE','ELAZIG','ERZINCAN','ERZURUM','ESKISEHIR','GAZIANTEP','GIRESUN','GUMUSHANE','HAKKARI','HATAY','ISPARTA','MERSIN','ISTANBUL','IZMIR','KARS','KASTAMONU','KAYSERI','KIRKLARELI','KIRSEHIR','KOCAELI','KONYA','KUTAHYA','MALATYA','MANISA','KAHRAMANMARAS','MARDIN','MUGLA','MUS','NEVSEHIR','NIGDE','ORDU','RIZE','SAKARYA','SAMSUN','SIIRT','SINOP','SIVAS','TEKIRDAG','TOKAT','TRABZON','TUNCELI','SANLIURFA','USAK','VAN','YOZGAT','ZONGULDAK','AKSARAY','BAYBURT','KARAMAN','KIRIKKALE','BATMAN','SIRNAK','BARTIN','ARDAHAN','IGDIR','YALOVA','KARABUK','KILIS','OSMANIYE','DUZCE'];
    var text=norm(lines.join(' '));
    return cities.find(function(city){return new RegExp('(^| )'+city+'( |$)').test(text);})||'';
  }

  function findAddress(lines,name,taxOffice){
    var parts=[];
    lines.forEach(function(line){
      var raw=String(line||'').trim();
      var key=norm(raw);
      if(!raw||raw===name||raw===taxOffice) return;
      if(/DUMANLAR|BITKE|MOFIY|BAFIY|VKN|TCKN|VERGI|EMAIL|E POSTA|TELEFON|FATURA|ETTN|MERSIS|TICARET SICIL|IBAN|BANKA|TOPLAM|KDV/.test(key)) return;
      if(isAddressLine(raw)&&parts.length<3) parts.push(raw);
    });
    return parts.join(' ');
  }

  function extractCari(lines,direction){
    var candidates=extractTaxCandidates(lines,direction);
    if(!candidates.length){
      var markerCompany=findMarkerCompany(lines,direction);
      return {
        name:markerCompany.name,
        tax_no:'',tax_office:'',city:'',phone:'',email:'',address:'',
        warning:markerCompany.name?'Firma ünvanı bulundu; vergi numarası güvenilir biçimde okunamadı. Vergi numarasını faturadan kontrol ederek tamamla.':'Karşı firmaya ait güvenilir ünvan ve vergi numarası bulunamadı. Sistem yanlış bilgi doldurmadı.'
      };
    }
    if(candidates[1]&&candidates[0].tax!==candidates[1].tax&&candidates[0].score-candidates[1].score<25){
      return {name:'',tax_no:'',tax_office:'',city:'',phone:'',email:'',address:'',warning:'Faturada birden fazla olası vergi numarası bulundu. Yanlış cari açmamak için otomatik seçim yapılmadı.'};
    }
    var chosen=candidates[0];
    var nearby=nearbyLines(lines,chosen.index,10);
    var name=findCompanyName(lines,chosen.index,direction);
    var taxOffice=findTaxOffice(nearby);
    var nearbyText=nearby.join('\n');
    return {
      name:name,
      tax_no:chosen.tax,
      tax_office:taxOffice,
      city:findCity(nearby),
      phone:findPhone(nearbyText,chosen.tax),
      email:findEmail(nearbyText),
      address:findAddress(nearby,name,taxOffice),
      warning:name?'':'Firma ünvanı güvenli biçimde bulunamadı. Sistem adres satırını ünvan olarak yazmadı.'
    };
  }

  function fillResult(result){
    var modal=document.getElementById('faturaCariModal');
    if(!modal) return;
    var fields=modal.querySelector('[data-auto-fields]');
    if(fields) fields.hidden=false;
    ['name','tax_no','tax_office','city','phone','email','address'].forEach(function(key){
      var input=modal.querySelector('[name="auto_'+key+'"]');
      if(input) input.value=result[key]||'';
    });
    if(result.name&&result.tax_no){
      setStatus('Karşı firma bilgileri güvenli alandan okundu. Kaydetmeden önce son kez kontrol et.','success');
    }else{
      setStatus(result.warning||'Karşı firma bilgileri kesin bulunamadı. Eksik alanları elle tamamla.','warning');
    }
  }

  async function readInvoice(button,readyInvoice,requestedId){
    if(!activeInvoiceId){setStatus('Fatura seçimi bulunamadı. Pencereyi kapatıp tekrar aç.','danger');return;}
    var invoiceId=Number(requestedId||activeInvoiceId);
    var run=++readRun;
    var oldText=button.textContent;
    button.disabled=true;
    button.textContent='Fatura okunuyor...';
    setStatus('Karşı firma bölümü okunuyor; Dumanlar bilgileri ayıklanıyor...','loading');
    try{
      var invoice=readyInvoice||activeInvoiceInfo;
      if(!invoice){
        var info=await fetch('fatura-cari-sec.php?invoice_id='+encodeURIComponent(invoiceId)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'}).then(function(response){return response.json();});
        if(!info.ok||!info.invoice) throw new Error(info.error||'Fatura bilgisi alınamadı.');
        invoice=info.invoice;
      }
      if(!invoice.has_document) throw new Error('Bu faturada okunabilecek PDF bulunmuyor.');
      if(!/\.pdf$/i.test(invoice.document_name||'')) throw new Error('Otomatik cari okuma PDF faturalarında çalışır.');
      var values=await Promise.all([
        loadPdfJs(),
        fetch(invoice.document_url,{credentials:'same-origin',cache:'no-store'}).then(function(response){if(!response.ok) throw new Error('Fatura dosyası açılamadı.');return response.arrayBuffer();})
      ]);
      var pdf=await values[0].getDocument({data:values[1]}).promise;
      var lines=[];
      for(var pageNo=1;pageNo<=Math.min(pdf.numPages,5);pageNo++){
        var page=await pdf.getPage(pageNo);
        lines=lines.concat(pageLines(await page.getTextContent()));
      }
      var modal=document.getElementById('faturaCariModal');
      if(run!==readRun||activeInvoiceId!==invoiceId||!modal||modal.hidden) return;
      fillResult(extractCari(lines,invoice.direction||'gelen'));
    }catch(error){
      if(run===readRun&&activeInvoiceId===invoiceId) setStatus(error.message||'PDF’den firma bilgileri okunamadı.','danger');
    }finally{
      if(run===readRun){button.disabled=false;button.textContent=oldText;}
    }
  }

  document.addEventListener('click',function(event){
    var opener=event.target.closest('[data-fatura-cari-sec]');
    if(opener){
      activeInvoiceId=Number(opener.getAttribute('data-fatura-cari-sec')||0);
      activeInvoiceInfo=null;
      readRun++;
      var modal=document.getElementById('faturaCariModal');
      var readButton=modal&&modal.querySelector('[data-auto-read]');
      if(readButton) readButton.removeAttribute('data-auto-read-for');
      return;
    }

    var closer=event.target.closest('#faturaCariModal [data-cari-cancel], #faturaCariModal .fatura-cari-close');
    if(closer){activeInvoiceId=0;activeInvoiceInfo=null;readRun++;return;}

    var autoRead=event.target.closest('#faturaCariModal [data-auto-read]');
    if(!autoRead) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    readInvoice(autoRead,activeInvoiceInfo,activeInvoiceId);
  },true);

  document.addEventListener('bitke:fatura-cari-ready',function(event){
    var detail=event.detail||{};
    var invoiceId=Number(detail.invoiceId||0);
    var invoice=detail.invoice||null;
    var modal=document.getElementById('faturaCariModal');
    if(!invoiceId||invoiceId!==activeInvoiceId||!invoice||!modal||modal.hidden) return;
    activeInvoiceInfo=invoice;
    var button=modal.querySelector('[data-auto-read]');
    if(!button||!invoice.has_document||!/\.pdf$/i.test(invoice.document_name||'')) return;
    if(button.getAttribute('data-auto-read-for')===String(invoiceId)) return;
    button.setAttribute('data-auto-read-for',String(invoiceId));
    readInvoice(button,invoice,invoiceId);
  });
})();
