(function(){
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  var categoryLabels={
    iplik:'İplik / Hammadde',
    telefon:'Telefon / İnternet',
    elektrik:'Elektrik',
    dogalgaz:'Doğalgaz',
    kargo:'Kargo / Nakliye',
    akaryakit:'Akaryakıt',
    bakim:'Makine / Bakım',
    ambalaj:'Ambalaj',
    personel:'Personel Gideri',
    ofis:'Ofis / Genel Gider',
    diger:'Diğer'
  };

  var state={period:'',csrf:'',items:[],itemMap:{},running:false,completed:0,total:0};

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

  function periodValue(){
    var input=document.querySelector('input[type="month"][name="period"]');
    var value=input?String(input.value||''):'';
    if(!/^\d{4}-\d{2}$/.test(value)) value=new URLSearchParams(location.search).get('period')||'';
    return /^\d{4}-\d{2}$/.test(value)?value:new Date().toISOString().slice(0,7);
  }

  function invoiceIdFromRow(row){
    var input=row.querySelector('form input[name="id"]');
    if(input&&Number(input.value||0)>0) return Number(input.value);
    var edit=row.querySelector('a[href*="edit="]');
    if(edit){
      try{return Number(new URL(edit.href,location.href).searchParams.get('edit')||0);}catch(error){}
    }
    return 0;
  }

  function rowMap(){
    var map={};
    document.querySelectorAll('.table-wrap table tbody tr').forEach(function(row){
      var id=invoiceIdFromRow(row);
      if(id) map[id]=row;
    });
    return map;
  }

  function ensureColumn(){
    var table=document.querySelector('.table-wrap table');
    if(!table) return;
    var header=table.querySelector('thead tr');
    if(header&&!header.querySelector('[data-fatura-tur-head]')){
      var th=document.createElement('th');
      th.setAttribute('data-fatura-tur-head','1');
      th.textContent='Fatura türü';
      var cariHead=header.children[2];
      if(cariHead) cariHead.insertAdjacentElement('afterend',th);
    }
    var rows=rowMap();
    Object.keys(rows).forEach(function(id){
      var row=rows[id];
      if(row.querySelector('[data-fatura-tur-cell]')) return;
      var td=document.createElement('td');
      td.setAttribute('data-fatura-tur-cell',id);
      td.innerHTML='<span class="fatura-tur-auto-loading">Otomatik okunuyor…</span>';
      var cariCell=row.children[2];
      if(cariCell) cariCell.insertAdjacentElement('afterend',td);
    });
  }

  function ensureStatus(){
    var card=document.querySelector('.table-wrap')&&document.querySelector('.table-wrap').closest('.panel-card');
    if(!card) return null;
    var bar=card.querySelector('[data-fatura-tur-auto-status]');
    if(!bar){
      bar=document.createElement('div');
      bar.setAttribute('data-fatura-tur-auto-status','1');
      bar.className='fatura-tur-auto-status';
      var anchor=card.querySelector('[data-fatura-sira-kontrol]')||card.querySelector('.filterbar');
      if(anchor) anchor.insertAdjacentElement('afterend',bar); else card.insertAdjacentElement('afterbegin',bar);
    }
    return bar;
  }

  function setStatus(text,tone){
    var bar=ensureStatus();
    if(!bar) return;
    bar.className='fatura-tur-auto-status'+(tone?' is-'+tone:'');
    bar.innerHTML='<strong>Otomatik fatura bilgisi</strong><span>'+esc(text)+'</span>';
  }

  function selectHtml(item){
    if(item.direction!=='gelen'){
      return '<span class="fatura-tur-auto-sale">Satış</span><small>Giden fatura</small>';
    }
    var selected=item.category||'';
    var html='<select data-fatura-tur-auto-select="'+item.id+'" aria-label="Fatura türü">';
    Object.keys(categoryLabels).forEach(function(key){
      html+='<option value="'+key+'"'+(key===selected?' selected':'')+'>'+esc(categoryLabels[key])+'</option>';
    });
    html+='</select><small>'+(item.category_source==='manual'?'Manuel seçildi':'PDF’den otomatik seçildi')+'</small>';
    if(item.issuer_name){
      html+='<small class="fatura-issuer-chip"><strong>Gönderen:</strong> '+esc(item.issuer_name)+'</small>';
    }else if(item.is_generic_cari&&item.issuer_source==='pdf'){
      html+='<small class="fatura-issuer-chip is-muted">Gönderen PDF’den okunamadı</small>';
    }
    return html;
  }

  function renderCariIssuer(row,item){
    var cell=row&&row.children?row.children[2]:null;
    if(!cell) return;
    var old=cell.querySelector('.fatura-issuer-line');
    if(item.direction!=='gelen'||(!item.issuer_is_stored&&!item.is_generic_cari)) return;
    if(!item.issuer_name&&item.issuer_source!=='pdf') return;
    if(old) old.remove();
    var line=document.createElement('small');
    line.className='fatura-issuer-line'+(!item.issuer_name?' muted':'');
    line.textContent=item.issuer_name?'Gönderen: '+item.issuer_name:'Gönderen PDF’den okunamadı';
    cell.appendChild(line);
  }

  function renderItems(items){
    ensureColumn();
    var rows=rowMap();
    items.forEach(function(item){
      state.itemMap[item.id]=item;
      var row=rows[item.id];
      if(!row) return;
      row.setAttribute('data-invoice-type',item.direction==='gelen'?(item.category||'diger'):'satis');
      renderCariIssuer(row,item);
      var cell=row.querySelector('[data-fatura-tur-cell]');
      if(cell) cell.innerHTML=selectHtml(item);
    });
  }

  function scoreCategory(text){
    var value=norm(text);
    var rules={
      telefon:{'TURK TELEKOM':150,'TTNET':150,'TURKCELL':150,'VODAFONE':150,'SUPERONLINE':150,'GSM HIZMET':120,'TELEFON HIZMET':120,'INTERNET HIZMET':120,'HAT BEDELI':90,'ILETISIM HIZMET':90},
      dogalgaz:{'DOGALGAZ':160,'DOGAL GAZ':160,'AKSA GAZ':150,'AKSAGAZ':150,'ENERYA':150,'IGDAS':150,'GAZDAS':150,'GAZ TUKETIM':100,'M3 GAZ':90},
      elektrik:{'ELEKTRIK':130,'YEDAS':160,'CEDAS':160,'UEDAS':160,'CK ENERJI':160,'YESILIRMAK ELEKTRIK':180,'ULUDAG ELEKTRIK':180,'ELEKTRIK DAGITIM':170,'KWH':100,'REAKTIF ENERJI':110,'AKTIF ENERJI':110},
      iplik:{'IPLIK':170,'YARN':150,'PAMUK IPLIGI':180,'POLYESTER IPLIK':180,'LIKRA':130,'ELASTAN':120,'ELYAF':120,'TEKSTIL HAMMADDE':170,'HAMMADDE':100,'NE 30 1':110,'NE 20 1':110,'NE 40 1':110},
      kargo:{'KARGO':160,'NAKLIYE':150,'LOJISTIK':140,'SURAT KARGO':180,'YURTICI KARGO':180,'ARAS KARGO':180,'MNG KARGO':180,'PTT KARGO':180,'TASIMA BEDELI':130,'GONDERI':80,'DESI':90},
      akaryakit:{'AKARYAKIT':170,'MOTORIN':160,'BENZIN':160,'PETROL OFISI':170,'OPET':170,'SHELL':170,'BP PETROL':170,'TOTALENERGIES':170,'LITRE':45},
      bakim:{'MAKINE BAKIM':170,'TEKNIK SERVIS':150,'YEDEK PARCA':150,'RULMAN':130,'KOMPRESOR':130,'ELEKTRONIK KART':120,'SERVIS BEDELI':100,'TAMIR':110},
      ambalaj:{'AMBALAJ':170,'KOLI':130,'KARTON':120,'POSET':120,'PAKETLEME':140,'KUTU':90,'ETIKET':90,'BANT':70},
      personel:{'PERSONEL':110,'MAAS':150,'SGK':160,'IS SAGLIGI':130,'YEMEK HIZMET':120,'PERSONEL SERVIS':150},
      ofis:{'KIRTASIYE':150,'OFIS MALZEME':140,'MUHASEBE HIZMET':130,'DANISMANLIK':110,'YAZILIM':120,'LISANS':100,'TEMIZLIK MALZEME':100,'ABONELIK':45}
    };
    var scores={};
    Object.keys(rules).forEach(function(category){
      scores[category]=0;
      Object.keys(rules[category]).forEach(function(keyword){
        if(value.indexOf(keyword)!==-1) scores[category]+=rules[category][keyword];
      });
    });
    var best='diger';
    var bestScore=0;
    Object.keys(scores).forEach(function(category){
      if(scores[category]>bestScore){best=category;bestScore=scores[category];}
    });
    return {category:best,score:bestScore};
  }

  function loadPdfJs(){
    if(window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    return new Promise(function(resolve,reject){
      var existing=document.querySelector('script[data-fatura-tur-pdfjs]');
      if(existing){
        existing.addEventListener('load',function(){resolve(window.pdfjsLib);},{once:true});
        existing.addEventListener('error',reject,{once:true});
        return;
      }
      var script=document.createElement('script');
      script.src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
      script.setAttribute('data-fatura-tur-pdfjs','1');
      script.onload=function(){
        if(!window.pdfjsLib){reject(new Error('PDF okuma kütüphanesi yüklenemedi.'));return;}
        window.pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        resolve(window.pdfjsLib);
      };
      script.onerror=function(){reject(new Error('PDF okuma kütüphanesi yüklenemedi.'));};
      document.head.appendChild(script);
    });
  }

  function pdfPageLines(content){
    var parts=(content.items||[]).map(function(part){
      var transform=part.transform||[];
      return {text:String(part.str||'').trim(),x:Number(transform[4]||0),y:Number(transform[5]||0),width:Number(part.width||0)};
    }).filter(function(part){return part.text!=='';});
    parts.sort(function(a,b){return Math.abs(a.y-b.y)>2.5?b.y-a.y:a.x-b.x;});
    var rows=[];
    parts.forEach(function(part){
      var row=rows.find(function(candidate){return Math.abs(candidate.y-part.y)<=2.5;});
      if(!row){row={y:part.y,parts:[]};rows.push(row);}
      row.parts.push(part);
    });
    rows.sort(function(a,b){return b.y-a.y;});
    var lines=[];
    rows.forEach(function(row){
      row.parts.sort(function(a,b){return a.x-b.x;});
      var chunks=[];
      var current=[];
      var right=null;
      row.parts.forEach(function(part){
        var gap=right===null?0:part.x-right;
        if(current.length&&gap>64){chunks.push(current);current=[];}
        current.push(part.text);
        right=Math.max(right===null?part.x:right,part.x+Math.max(0,part.width));
      });
      if(current.length) chunks.push(current);
      chunks.forEach(function(chunk){var line=chunk.join(' ').replace(/\s+/g,' ').trim();if(line) lines.push(line);});
    });
    return lines;
  }

  async function readPdfData(item){
    var values=await Promise.all([
      loadPdfJs(),
      fetch(item.document_url,{credentials:'same-origin',cache:'no-store'}).then(function(response){
        if(!response.ok) throw new Error('Fatura dosyası açılamadı.');
        return response.arrayBuffer();
      })
    ]);
    var pdf=await values[0].getDocument({data:values[1]}).promise;
    var lines=[];
    for(var pageNo=1;pageNo<=Math.min(pdf.numPages,5);pageNo++){
      var page=await pdf.getPage(pageNo);
      var content=await page.getTextContent();
      lines=lines.concat(pdfPageLines(content));
    }
    return {lines:lines,text:lines.join(' ')};
  }

  function emitPayload(data){
    document.dispatchEvent(new CustomEvent('bitke:fatura-meta-updated',{detail:data}));
  }

  async function persistCategory(item,category){
    var body=new FormData();
    body.set('csrf_token',state.csrf);
    body.set('invoice_id',String(item.id));
    body.set('category',category);
    body.set('source','pdf');
    body.set('period',state.period);
    var response=await fetch('fatura-tur.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'});
    var data=await response.json();
    if(!data.ok) throw new Error(data.error||'Fatura türü kaydedilemedi.');
    state.csrf=String(data.csrf_token||state.csrf);
    emitPayload(data);
    return data;
  }

  async function persistIssuer(item,result){
    var body=new FormData();
    body.set('csrf_token',state.csrf);
    body.set('action','issuer');
    body.set('invoice_id',String(item.id));
    body.set('issuer_name',result&&result.name?result.name:'');
    body.set('confidence',String(result&&result.confidence?result.confidence:0));
    body.set('source','pdf');
    body.set('period',state.period);
    var response=await fetch('fatura-tur.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'});
    var data=await response.json();
    if(!data.ok) throw new Error(data.error||'Gönderen firma kaydedilemedi.');
    state.csrf=String(data.csrf_token||state.csrf);
    emitPayload(data);
    return data;
  }

  function updatedItem(data,id){
    var rows=Array.isArray(data&&data.items)?data.items:[];
    return rows.find(function(row){return Number(row.id)===Number(id);})||null;
  }

  async function classifyItem(item){
    var needsCategory=!item.category;
    var needsIssuer=!!item.needs_issuer;
    var pdfData=null;
    var canRead=item.has_document&&item.document_url&&/\.pdf$/i.test(item.document_name||'');
    if(canRead&&(needsIssuer||needsCategory)){
      try{pdfData=await readPdfData(item);}catch(error){pdfData=null;}
    }

    if(needsCategory){
      var category='';
      if(item.suggestion&&Number(item.suggestion_confidence||0)>=80){
        category=item.suggestion;
      }else if(pdfData){
        var partyText=item.issuer_name||(!item.is_generic_cari?item.cari_name:'')||'';
        category=scoreCategory(partyText+' '+pdfData.text).category;
      }else{
        category='diger';
      }
      var categoryPayload=await persistCategory(item,category||'diger');
      var categoryItem=updatedItem(categoryPayload,item.id);
      if(categoryItem) item=categoryItem;
    }

    if(needsIssuer){
      var issuer={name:'',confidence:0,source:'none'};
      if(pdfData&&window.FaturaOkumaCore&&typeof window.FaturaOkumaCore.extractIssuer==='function'){
        issuer=window.FaturaOkumaCore.extractIssuer(pdfData.lines,{direction:item.direction,companyTaxNo:window.BITKE_COMPANY_TAX_NO||''});
        if(!issuer||Number(issuer.confidence||0)<75) issuer={name:'',confidence:0,source:'none'};
      }
      var issuerPayload=await persistIssuer(item,issuer);
      var issuerItem=updatedItem(issuerPayload,item.id);
      if(issuerItem) item=issuerItem;
    }
    return item;
  }

  async function runAutomatic(items){
    if(state.running) return;
    var pending=items.filter(function(item){return item.direction==='gelen'&&(!item.category||item.needs_issuer);});
    state.total=pending.length;
    if(!pending.length){
      setStatus('Fatura türleri ve gönderen firma bilgileri tamamlandı. Yanlış olanı düzenleyebilirsin.','success');
      return;
    }
    state.running=true;
    state.completed=0;
    setStatus(pending.length+' gelen faturanın türü ve gönderen firması PDF’den okunuyor…','loading');
    for(var i=0;i<pending.length;i++){
      var item=pending[i];
      try{
        item=await classifyItem(item);
        state.completed++;
        renderItems([item]);
        setStatus(state.completed+' / '+state.total+' fatura işlendi.','loading');
      }catch(error){
        renderItems([item]);
      }
    }
    state.running=false;
    setStatus('Tamamlandı: '+state.completed+' faturanın türü ve gönderen firma bilgisi kontrol edildi. Okunamayan göndereni “Düzenle”den yazabilirsin.','success');
  }

  document.addEventListener('change',function(event){
    var select=event.target.closest('[data-fatura-tur-auto-select]');
    if(!select) return;
    var id=Number(select.getAttribute('data-fatura-tur-auto-select')||0);
    var item=state.itemMap[id];
    if(!item) return;
    var old=select.getAttribute('data-old')||item.category||'diger';
    select.disabled=true;
    var body=new FormData();
    body.set('csrf_token',state.csrf);
    body.set('invoice_id',String(id));
    body.set('category',select.value);
    body.set('source','manual');
    body.set('period',state.period);
    fetch('fatura-tur.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(!data.ok) throw new Error(data.error||'Fatura türü kaydedilemedi.');
        state.csrf=String(data.csrf_token||state.csrf);
        emitPayload(data);
        item.category=select.value;
        item.category_source='manual';
        var cell=select.closest('[data-fatura-tur-cell]');
        if(cell){var small=cell.querySelector('small');if(small) small.textContent='Manuel seçildi';}
        setStatus('Fatura türü değiştirildi.','success');
      })
      .catch(function(error){
        select.value=old;
        window.alert(error.message||'Fatura türü kaydedilemedi.');
      })
      .finally(function(){select.disabled=false;});
  });

  var style=document.createElement('style');
  style.textContent=''
    +'.fatura-tur-auto-status{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0 12px;padding:10px 12px;border:1px solid #d8dfda;background:#f5faf6;border-radius:12px;font-size:10px}.fatura-tur-auto-status strong{font-size:11px}.fatura-tur-auto-status span{color:var(--muted)}.fatura-tur-auto-status.is-loading{border-color:#c8d8ea;background:#f1f7ff}.fatura-tur-auto-status.is-success{border-color:#b8d7c2;background:#eef9f1}.fatura-tur-auto-status.is-danger{border-color:#e2b7b2;background:#fff3f1}'
    +'[data-fatura-tur-cell] select{width:145px;max-width:100%;border:1px solid #b7cebF;background:#f2faf4;color:#245e39;border-radius:9px;padding:7px 8px;font-size:9px;font-weight:850;cursor:pointer}[data-fatura-tur-cell]>small{display:block;margin-top:3px;font-size:8px;color:var(--muted)}.fatura-issuer-chip{display:block;margin-top:5px!important;padding:5px 7px;border-radius:8px;background:#edf5ff;color:#234f73!important;font-size:8px!important;line-height:1.35;max-width:165px}.fatura-issuer-chip.is-muted{background:#f5f2ed;color:#7d7060!important}.fatura-tur-auto-sale{display:inline-flex;padding:5px 8px;border-radius:999px;background:#eaf5ee;color:#27623d;font-size:9px;font-weight:900}.fatura-tur-auto-loading{font-size:9px;color:#7a6b55;font-weight:800}';
  document.head.appendChild(style);

  ensureColumn();
  setStatus('Fatura türü ve gönderen firma bilgileri hazırlanıyor…','loading');
  state.period=periodValue();
  fetch('fatura-tur.php?period='+encodeURIComponent(state.period)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
    .then(function(response){return response.json();})
    .then(function(data){
      if(!data.ok) throw new Error(data.error||'Fatura türleri yüklenemedi.');
      state.csrf=String(data.csrf_token||'');
      state.items=Array.isArray(data.items)?data.items:[];
      state.itemMap={};
      state.items.forEach(function(item){state.itemMap[item.id]=item;});
      renderItems(state.items);
      runAutomatic(state.items);
    })
    .catch(function(error){
      setStatus('Fatura türü sistemi açılamadı: '+(error.message||'Bilinmeyen hata'),'danger');
    });
})();
