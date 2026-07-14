(function(){
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  var state={cariler:[],csrf:'',invoiceId:0,cell:null,invoice:null};
  var companyTaxNo=String(window.BITKE_COMPANY_TAX_NO||'3140036788').replace(/\D/g,'');

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

  function invoiceIdFromRow(row){
    var action=row.querySelector('input[name="action"][value="post_cari"]');
    var form=action?action.closest('form'):null;
    var input=form?form.querySelector('input[name="id"]'):null;
    return input?Number(input.value||0):0;
  }

  function loadPdfJs(){
    if(window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    return new Promise(function(resolve,reject){
      var old=document.querySelector('script[data-fatura-cari-pdfjs]')||document.querySelector('script[data-fatura-pdfjs]')||document.querySelector('script[data-bulk-pdfjs]');
      if(old){
        if(window.pdfjsLib){resolve(window.pdfjsLib);return;}
        old.addEventListener('load',function(){resolve(window.pdfjsLib);},{once:true});
        old.addEventListener('error',reject,{once:true});
        return;
      }
      var script=document.createElement('script');
      script.src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
      script.setAttribute('data-fatura-cari-pdfjs','1');
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

  function taxNumbers(text){
    var rows=[];
    var regex=/(?:\d[\s.\/-]*){10,11}/g;
    var match;
    while((match=regex.exec(String(text||'')))!==null){
      var digits=match[0].replace(/\D/g,'');
      if((digits.length===10||digits.length===11)&&rows.indexOf(digits)===-1) rows.push(digits);
      if(match.index===regex.lastIndex) regex.lastIndex++;
    }
    return rows;
  }

  function cleanCompanyName(value){
    return String(value||'')
      .replace(/\b(VKN|TCKN|VERGI NO|VERGI NUMARASI|TAX NO)\b\s*[:\-]?\s*\d+/gi,'')
      .replace(/^\s*(SAYIN|ALICI|SATICI|MUSTERI|BUYER|SELLER)\s*[:\-]?\s*/i,'')
      .replace(/\s+/g,' ')
      .trim();
  }

  function companyLineScore(line){
    var raw=cleanCompanyName(line);
    var key=norm(raw);
    if(raw.length<4||raw.length>150) return -100;
    if(!/[A-ZÇĞİÖŞÜ]/i.test(raw)) return -100;
    if(/^\d+$/.test(raw.replace(/\D/g,''))&&raw.replace(/\D/g,'').length>=8) return -100;
    if(/FATURA|ETTN|UUID|SENARYO|TIPI|TARIH|SAAT|IRSALIYE|VERGI DAIRESI|VKN|TCKN|MERSIS|TICARET SICIL|IBAN|BANKA|TOPLAM|KDV|MATRAH/.test(key)) return -80;
    if(/DUMANLAR/.test(key)) return -90;
    var score=Math.min(raw.length,60);
    if(/\b(SAN|SANAYI|TIC|TICARET|LTD|LIMITED|AS|ANONIM|STI|SIRKETI|KOOP|PAZARLAMA|GIDA|TEKSTIL|INSAAT|ELEKTRIK|OTOMOTIV|TURIZM)\b/.test(key)) score+=80;
    if(/[&]/.test(raw)) score+=10;
    return score;
  }

  function findCounterpartyName(lines,taxNo,direction){
    var best={text:'',score:-999};
    var taxIndex=-1;
    if(taxNo){
      for(var i=0;i<lines.length;i++){
        if(String(lines[i]).replace(/\D/g,'').indexOf(taxNo)!==-1){taxIndex=i;break;}
      }
    }

    if(taxIndex>=0){
      for(var offset=-7;offset<=4;offset++){
        var line=lines[taxIndex+offset];
        if(!line) continue;
        var score=companyLineScore(line)+(offset<0?15:0)-Math.abs(offset)*2;
        if(score>best.score) best={text:cleanCompanyName(line),score:score};
      }
    }

    if(best.score<20){
      var markers=direction==='giden'?['SAYIN','ALICI','MUSTERI','BUYER']:['SATICI','SELLER','TEDARIKCI'];
      for(var m=0;m<lines.length;m++){
        var lineKey=norm(lines[m]);
        if(!markers.some(function(marker){return lineKey.indexOf(marker)!==-1;})) continue;
        for(var j=0;j<=5;j++){
          var candidate=lines[m+j];
          if(!candidate) continue;
          var candidateScore=companyLineScore(candidate)-j*2;
          if(candidateScore>best.score) best={text:cleanCompanyName(candidate),score:candidateScore};
        }
      }
    }

    return best.score>=10?best.text:'';
  }

  function nearLines(lines,taxNo,radius){
    var index=-1;
    for(var i=0;i<lines.length;i++){
      if(taxNo&&String(lines[i]).replace(/\D/g,'').indexOf(taxNo)!==-1){index=i;break;}
    }
    if(index<0) return lines;
    return lines.slice(Math.max(0,index-radius),Math.min(lines.length,index+radius+1));
  }

  function findTaxOffice(lines){
    for(var i=0;i<lines.length;i++){
      var key=norm(lines[i]);
      if(key.indexOf('VERGI DAIRESI')===-1&&key.indexOf('VERGI D')===-1) continue;
      var raw=String(lines[i]);
      var after=raw.split(/[:\-]/).slice(1).join(' ').trim();
      after=after.replace(/\b(VKN|TCKN|VERGI NO).*$/i,'').trim();
      if(after.length>=2&&after.length<=80) return after;
      if(lines[i+1]&&companyLineScore(lines[i+1])<0&&String(lines[i+1]).length<=60) return String(lines[i+1]).trim();
    }
    return '';
  }

  function findEmail(text){
    var match=String(text||'').match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i);
    return match?match[0]:'';
  }

  function findPhone(text){
    var matches=String(text||'').match(/(?:\+?90\s*)?(?:\(?0?\d{3}\)?[\s.-]*)\d{3}[\s.-]*\d{2}[\s.-]*\d{2}/g)||[];
    return matches.length?matches[0].replace(/\s+/g,' ').trim():'';
  }

  function findCity(lines){
    var cities=['ADANA','ADIYAMAN','AFYONKARAHISAR','AGRI','AMASYA','ANKARA','ANTALYA','ARTVIN','AYDIN','BALIKESIR','BILECIK','BINGOL','BITLIS','BOLU','BURDUR','BURSA','CANAKKALE','CANKIRI','CORUM','DENIZLI','DIYARBAKIR','EDIRNE','ELAZIG','ERZINCAN','ERZURUM','ESKISEHIR','GAZIANTEP','GIRESUN','GUMUSHANE','HAKKARI','HATAY','ISPARTA','MERSIN','ISTANBUL','IZMIR','KARS','KASTAMONU','KAYSERI','KIRKLARELI','KIRSEHIR','KOCAELI','KONYA','KUTAHYA','MALATYA','MANISA','KAHRAMANMARAS','MARDIN','MUGLA','MUS','NEVSEHIR','NIGDE','ORDU','RIZE','SAKARYA','SAMSUN','SIIRT','SINOP','SIVAS','TEKIRDAG','TOKAT','TRABZON','TUNCELI','SANLIURFA','USAK','VAN','YOZGAT','ZONGULDAK','AKSARAY','BAYBURT','KARAMAN','KIRIKKALE','BATMAN','SIRNAK','BARTIN','ARDAHAN','IGDIR','YALOVA','KARABUK','KILIS','OSMANIYE','DUZCE'];
    var text=norm(lines.join(' '));
    for(var i=0;i<cities.length;i++){
      if(new RegExp('(^| )'+cities[i]+'( |$)').test(text)) return cities[i];
    }
    return '';
  }

  function findAddress(lines,name,taxOffice){
    var parts=[];
    for(var i=0;i<lines.length;i++){
      var raw=String(lines[i]).trim();
      var key=norm(raw);
      if(!raw||raw===name||raw===taxOffice) continue;
      if(/VKN|TCKN|VERGI|EMAIL|E POSTA|TELEFON|TEL |FATURA|ETTN|MERSIS|TICARET SICIL|IBAN|BANKA|TOPLAM|KDV/.test(key)) continue;
      if(/MAH|MAHALLESI|CAD|CADDESI|SOK|SOKAK|BULVAR|BLV|NO |KAT |DAIRE |OSB|ORGANIZE SANAYI|MEVKII|KOYU|ILCE/.test(key)) parts.push(raw);
      if(parts.length>=3) break;
    }
    return parts.join(' ');
  }

  function extractCari(lines,direction){
    var fullText=lines.join('\n');
    var numbers=taxNumbers(fullText).filter(function(value){return value!==companyTaxNo;});
    var taxNo=numbers.length?numbers[0]:'';
    var nearby=nearLines(lines,taxNo,12);
    var name=findCounterpartyName(lines,taxNo,direction);
    var taxOffice=findTaxOffice(nearby);
    var nearbyText=nearby.join('\n');
    return {
      name:name,
      tax_no:taxNo,
      tax_office:taxOffice,
      city:findCity(nearby),
      phone:findPhone(nearbyText),
      email:findEmail(nearbyText),
      address:findAddress(nearby,name,taxOffice)
    };
  }

  function buildModal(){
    if(document.getElementById('faturaCariModal')) return;
    var modal=document.createElement('div');
    modal.id='faturaCariModal';
    modal.className='fatura-cari-modal';
    modal.hidden=true;
    modal.innerHTML=''
      +'<div class="fatura-cari-dialog" role="dialog" aria-modal="true" aria-labelledby="faturaCariTitle">'
      +'<div class="fatura-cari-head"><div><strong id="faturaCariTitle">Faturaya cari seç</strong><small>Mevcut cariyi seçebilir veya PDF’den yeni cariyi otomatik oluşturabilirsin.</small></div><button type="button" class="fatura-cari-close" aria-label="Kapat">×</button></div>'
      +'<section class="fatura-cari-existing"><strong>Mevcut cariden seç</strong><label class="fatura-cari-search">Cari ara<input type="search" placeholder="Firma veya kişi adı..."></label><label class="fatura-cari-select-label">Cari<select><option value="">Cari seç...</option></select></label><button type="button" class="btn btn-primary" data-cari-save disabled>Seçilen cariyi bağla</button></section>'
      +'<div class="fatura-cari-or"><span>veya</span></div>'
      +'<section class="fatura-cari-auto"><div class="fatura-cari-auto-head"><div><strong>Otomatik cari ekleme</strong><small>Faturadaki karşı firma bilgilerini okuyup yeni cari açar. Kaydetmeden önce alanları kontrol et.</small></div><button type="button" class="btn btn-secondary" data-auto-read>Faturadan bilgileri getir</button></div>'
      +'<div class="fatura-cari-auto-fields" data-auto-fields hidden><label class="wide">Ad / Ünvan<input name="auto_name"></label><label>Vergi / T.C. No<input name="auto_tax_no"></label><label>Vergi dairesi<input name="auto_tax_office"></label><label>Şehir<input name="auto_city"></label><label>Telefon<input name="auto_phone"></label><label>E-posta<input type="email" name="auto_email"></label><label class="wide">Adres<textarea name="auto_address" rows="2"></textarea></label><button type="button" class="btn btn-primary wide" data-auto-create>Otomatik cariyi oluştur ve faturaya bağla</button></div></section>'
      +'<p class="fatura-cari-status"></p>'
      +'<div class="fatura-cari-actions"><button type="button" class="btn btn-secondary" data-cari-cancel>Kapat</button></div>'
      +'</div>';
    document.body.appendChild(modal);

    var close=function(){modal.hidden=true;state.invoiceId=0;state.cell=null;state.invoice=null;};
    modal.querySelector('.fatura-cari-close').addEventListener('click',close);
    modal.querySelector('[data-cari-cancel]').addEventListener('click',close);
    modal.addEventListener('click',function(event){if(event.target===modal) close();});
    document.addEventListener('keydown',function(event){if(event.key==='Escape'&&!modal.hidden) close();});

    var search=modal.querySelector('input[type="search"]');
    var select=modal.querySelector('.fatura-cari-select-label select');
    var save=modal.querySelector('[data-cari-save]');
    search.addEventListener('input',function(){fillOptions(search.value);});
    select.addEventListener('change',function(){save.disabled=!select.value;});
    save.addEventListener('click',saveCari);
    modal.querySelector('[data-auto-read]').addEventListener('click',readInvoiceCari);
    modal.querySelector('[data-auto-create]').addEventListener('click',createAutoCari);
  }

  function fillOptions(query){
    var modal=document.getElementById('faturaCariModal');
    if(!modal) return;
    var select=modal.querySelector('.fatura-cari-select-label select');
    var current=select.value;
    var key=norm(query);
    var rows=state.cariler.filter(function(cari){return !key||norm(cari.name).indexOf(key)!==-1||String(cari.tax_no||'').indexOf(query)!==-1;});
    select.innerHTML='<option value="">Cari seç...</option>'+rows.map(function(cari){
      var tax=cari.tax_no?' · '+cari.tax_no:'';
      return '<option value="'+esc(cari.id)+'">'+esc(cari.name)+' — '+esc(cari.cari_type||'Firma')+esc(tax)+'</option>';
    }).join('');
    if(rows.some(function(cari){return String(cari.id)===String(current);})) select.value=current;
    modal.querySelector('[data-cari-save]').disabled=!select.value;
  }

  function setStatus(text,tone){
    var modal=document.getElementById('faturaCariModal');
    if(!modal) return;
    var status=modal.querySelector('.fatura-cari-status');
    status.textContent=text||'';
    status.className='fatura-cari-status'+(tone?' is-'+tone:'');
  }

  function resetAutoFields(){
    var modal=document.getElementById('faturaCariModal');
    if(!modal) return;
    modal.querySelector('[data-auto-fields]').hidden=true;
    ['name','tax_no','tax_office','city','phone','email','address'].forEach(function(field){
      var input=modal.querySelector('[name="auto_'+field+'"]');
      if(input) input.value='';
    });
  }

  function openModal(invoiceId,cell){
    var requestedId=Number(invoiceId||0);
    buildModal();
    var modal=document.getElementById('faturaCariModal');
    state.invoiceId=requestedId;
    state.cell=cell;
    state.invoice=null;
    modal.querySelector('input[type="search"]').value='';
    fillOptions('');
    modal.querySelector('.fatura-cari-select-label select').value='';
    modal.querySelector('[data-cari-save]').disabled=true;
    resetAutoFields();
    setStatus('Fatura bilgileri hazırlanıyor...','loading');
    modal.hidden=false;

    fetch('fatura-cari-sec.php?invoice_id='+encodeURIComponent(requestedId)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(state.invoiceId!==requestedId) return;
        if(!data.ok) throw new Error(data.error||'Fatura bilgisi alınamadı.');
        state.csrf=String(data.csrf_token||state.csrf);
        state.invoice=data.invoice||null;
        if(Array.isArray(data.cariler)&&data.cariler.length){state.cariler=data.cariler;fillOptions('');}
        setStatus(state.invoice&&state.invoice.has_document?'PDF hazır. Mevcut cariyi seçebilir veya otomatik cari ekleyebilirsin.':'Bu faturada okunacak PDF bulunmuyor. Mevcut carilerden seçim yap.','neutral');
        modal.dispatchEvent(new CustomEvent('bitke:fatura-cari-ready',{bubbles:true,detail:{invoiceId:requestedId,invoice:state.invoice}}));
      })
      .catch(function(error){setStatus(error.message||'Fatura bilgisi alınamadı.','danger');});
  }

  function updateCell(data,label){
    if(!state.cell) return;
    state.cell.innerHTML='<a href="cari-detay.php?id='+encodeURIComponent(data.cari.id)+'">'+esc(data.cari.name)+'</a><small class="fatura-cari-secildi">'+esc(label)+'</small>';
  }

  function saveCari(){
    var modal=document.getElementById('faturaCariModal');
    var select=modal.querySelector('.fatura-cari-select-label select');
    var cariId=Number(select.value||0);
    if(!state.invoiceId||!cariId) return;

    var button=modal.querySelector('[data-cari-save]');
    var oldText=button.textContent;
    button.disabled=true;
    button.textContent='Bağlanıyor...';
    setStatus('Cari faturaya bağlanıyor...','loading');

    var body=new FormData();
    body.set('action','select_existing');
    body.set('csrf_token',state.csrf);
    body.set('invoice_id',String(state.invoiceId));
    body.set('cari_id',String(cariId));

    fetch('fatura-cari-sec.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(!data.ok) throw new Error(data.error||'Cari kaydedilemedi.');
        state.csrf=String(data.csrf_token||state.csrf);
        updateCell(data,'Manuel seçildi');
        setStatus(data.message||'Cari faturaya bağlandı.','success');
        setTimeout(function(){modal.hidden=true;state.invoiceId=0;state.cell=null;state.invoice=null;},650);
      })
      .catch(function(error){setStatus(error.message||'Cari kaydedilemedi.','danger');})
      .finally(function(){button.disabled=false;button.textContent=oldText;});
  }

  function readInvoiceCari(){
    var modal=document.getElementById('faturaCariModal');
    var button=modal.querySelector('[data-auto-read]');
    if(!state.invoice||!state.invoice.has_document){setStatus('Bu faturada okunabilecek PDF bulunmuyor.','danger');return;}
    if(!/\.pdf$/i.test(state.invoice.document_name||'')){setStatus('Otomatik cari okuma PDF faturalarında çalışır.','danger');return;}

    var oldText=button.textContent;
    button.disabled=true;
    button.textContent='Fatura okunuyor...';
    setStatus('PDF içinden karşı firma adı, vergi numarası ve adres aranıyor...','loading');

    Promise.all([
      loadPdfJs(),
      fetch(state.invoice.document_url,{credentials:'same-origin',cache:'no-store'}).then(function(response){if(!response.ok) throw new Error('Fatura dosyası açılamadı.');return response.arrayBuffer();})
    ])
      .then(function(values){return values[0].getDocument({data:values[1]}).promise;})
      .then(async function(pdf){
        var lines=[];
        var pageCount=Math.min(pdf.numPages,5);
        for(var pageNo=1;pageNo<=pageCount;pageNo++){
          var page=await pdf.getPage(pageNo);
          var content=await page.getTextContent();
          lines=lines.concat(pageLines(content));
        }
        var result=extractCari(lines,state.invoice.direction||'gelen');
        var fields=modal.querySelector('[data-auto-fields]');
        fields.hidden=false;
        Object.keys(result).forEach(function(key){
          var input=modal.querySelector('[name="auto_'+key+'"]');
          if(input) input.value=result[key]||'';
        });
        if(result.name){
          setStatus('Firma bilgileri bulundu. Alanları kontrol edip otomatik cariyi oluştur.','success');
        }else{
          setStatus('Firma adı kesin bulunamadı. Ad / Ünvan alanını faturaya bakarak tamamlayıp kaydedebilirsin.','warning');
        }
      })
      .catch(function(error){setStatus(error.message||'PDF’den firma bilgileri okunamadı.','danger');})
      .finally(function(){button.disabled=false;button.textContent=oldText;});
  }

  function createAutoCari(){
    var modal=document.getElementById('faturaCariModal');
    var button=modal.querySelector('[data-auto-create]');
    var name=modal.querySelector('[name="auto_name"]').value.trim();
    if(!name){setStatus('Ad / Ünvan alanı boş bırakılamaz.','danger');return;}

    var message='“'+name+'” için cari oluşturulup bu faturaya bağlansın mı?';
    if(!window.confirm(message)) return;

    var oldText=button.textContent;
    button.disabled=true;
    button.textContent='Cari oluşturuluyor...';
    setStatus('Mükerrer cari kontrol ediliyor ve kayıt hazırlanıyor...','loading');

    var body=new FormData();
    body.set('action','create_auto');
    body.set('csrf_token',state.csrf);
    body.set('invoice_id',String(state.invoiceId));
    ['name','tax_no','tax_office','city','phone','email','address'].forEach(function(field){
      var input=modal.querySelector('[name="auto_'+field+'"]');
      body.set(field,input?input.value.trim():'');
    });

    fetch('fatura-cari-sec.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(!data.ok) throw new Error(data.error||'Cari oluşturulamadı.');
        state.csrf=String(data.csrf_token||state.csrf);
        var existing=state.cariler.some(function(cari){return String(cari.id)===String(data.cari.id);});
        if(!existing) state.cariler.push(data.cari);
        updateCell(data,data.created?'PDF’den otomatik oluşturuldu':'Mevcut cari otomatik bulundu');
        setStatus(data.message||'Cari oluşturuldu ve faturaya bağlandı.','success');
        setTimeout(function(){modal.hidden=true;state.invoiceId=0;state.cell=null;state.invoice=null;},850);
      })
      .catch(function(error){setStatus(error.message||'Cari oluşturulamadı.','danger');})
      .finally(function(){button.disabled=false;button.textContent=oldText;});
  }

  function bindRows(){
    document.querySelectorAll('.table-wrap table tbody tr').forEach(function(row){
      var cells=row.children;
      if(!cells||cells.length<3) return;
      var cell=cells[2];
      var muted=cell.querySelector('.muted');
      if(!muted||norm(muted.textContent)!=='CARI YOK') return;
      var invoiceId=invoiceIdFromRow(row);
      if(!invoiceId) return;
      cell.innerHTML='<button type="button" class="fatura-cari-sec-btn" data-fatura-cari-sec="'+invoiceId+'"><strong>Cari yok</strong><small>Seç veya otomatik oluştur</small></button>';
    });
  }

  document.addEventListener('click',function(event){
    var button=event.target.closest('[data-fatura-cari-sec]');
    if(!button) return;
    var row=button.closest('tr');
    var cell=row&&row.children.length>2?row.children[2]:null;
    openModal(Number(button.getAttribute('data-fatura-cari-sec')||0),cell);
  });

  var style=document.createElement('style');
  style.textContent=''
    +'.fatura-cari-sec-btn{border:1px dashed #c9a96e;background:#fff9ee;color:#51452f;border-radius:11px;padding:8px 10px;display:grid;gap:2px;text-align:left;cursor:pointer;min-width:120px}.fatura-cari-sec-btn:hover{background:#fff1d5;border-color:#a77b31}.fatura-cari-sec-btn strong{font-size:12px}.fatura-cari-sec-btn small,.fatura-cari-secildi{display:block;font-size:9px;color:var(--muted);margin-top:2px}.fatura-cari-modal{position:fixed;inset:0;background:rgba(21,25,23,.48);display:grid;place-items:center;padding:18px;z-index:9999}.fatura-cari-modal[hidden]{display:none}.fatura-cari-dialog{width:min(760px,100%);max-height:min(90vh,850px);overflow:auto;background:#fff;border-radius:20px;box-shadow:0 24px 70px rgba(0,0,0,.25);padding:18px;display:grid;gap:13px}.fatura-cari-head{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:start}.fatura-cari-head>div{display:grid;gap:4px}.fatura-cari-head strong{font-size:18px}.fatura-cari-head small{color:var(--muted);font-size:11px}.fatura-cari-close{border:0;background:#f1eee8;border-radius:10px;width:34px;height:34px;font-size:22px;cursor:pointer}.fatura-cari-existing,.fatura-cari-auto{border:1px solid var(--border);border-radius:15px;padding:13px;display:grid;gap:10px}.fatura-cari-existing>strong,.fatura-cari-auto-head strong{font-size:13px}.fatura-cari-search,.fatura-cari-select-label,.fatura-cari-auto-fields label{display:grid;gap:6px;font-size:11px;font-weight:850;color:#554d42}.fatura-cari-search input,.fatura-cari-select-label select,.fatura-cari-auto-fields input,.fatura-cari-auto-fields textarea{width:100%;border:1px solid var(--border);border-radius:12px;background:#fff;padding:10px 11px}.fatura-cari-or{display:grid;place-items:center;position:relative}.fatura-cari-or:before{content:"";height:1px;background:var(--border);position:absolute;left:0;right:0}.fatura-cari-or span{position:relative;background:#fff;padding:0 10px;font-size:10px;color:var(--muted);font-weight:800}.fatura-cari-auto{background:#fffaf0;border-color:#dec9a4}.fatura-cari-auto-head{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}.fatura-cari-auto-head>div{display:grid;gap:3px}.fatura-cari-auto-head small{font-size:10px;color:var(--muted)}.fatura-cari-auto-fields{display:grid;grid-template-columns:1fr 1fr;gap:9px}.fatura-cari-auto-fields .wide{grid-column:1/-1}.fatura-cari-status{min-height:16px;margin:0;font-size:11px;color:var(--muted)}.fatura-cari-status.is-success{color:var(--success)}.fatura-cari-status.is-warning{color:#8a5b0d}.fatura-cari-status.is-danger{color:var(--danger)}.fatura-cari-status.is-loading{color:#23598b}.fatura-cari-actions{display:flex;justify-content:flex-end;gap:9px;flex-wrap:wrap}@media(max-width:650px){.fatura-cari-auto-head,.fatura-cari-auto-fields{grid-template-columns:1fr}.fatura-cari-auto-fields .wide{grid-column:auto}}';
  document.head.appendChild(style);

  fetch('fatura-cari-sec.php?_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
    .then(function(response){return response.json();})
    .then(function(data){
      if(!data.ok||!data.can_write) return;
      state.cariler=Array.isArray(data.cariler)?data.cariler:[];
      state.csrf=String(data.csrf_token||'');
      buildModal();
      bindRows();
    })
    .catch(function(){});
})();
