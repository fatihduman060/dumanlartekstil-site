(function(){
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  var state={
    period:'', categories:{}, items:[], itemMap:{}, csrf:'', canWrite:false,
    activeId:0, activeSource:'manual'
  };

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

  function money(value){
    return Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' TL';
  }

  function invoiceIdFromRow(row){
    var input=row.querySelector('form input[name="id"]');
    if(input&&Number(input.value||0)>0) return Number(input.value);
    var edit=row.querySelector('a[href*="edit="]');
    if(edit){
      try{return Number(new URL(edit.href,location.href).searchParams.get('edit')||0);}catch(e){}
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

  function periodValue(){
    var input=document.querySelector('.filterbar input[name="period"]')||document.querySelector('input[type="month"][name="period"]');
    var value=input?String(input.value||''):'';
    if(!/^\d{4}-\d{2}$/.test(value)) value=new URLSearchParams(location.search).get('period')||'';
    return /^\d{4}-\d{2}$/.test(value)?value:new Date().toISOString().slice(0,7);
  }

  function categoryOptions(selected){
    var html='<option value="">Tür seçilmedi</option>';
    Object.keys(state.categories).forEach(function(key){
      html+='<option value="'+esc(key)+'"'+(key===selected?' selected':'')+'>'+esc(state.categories[key])+'</option>';
    });
    return html;
  }

  function ensureColumn(){
    var table=document.querySelector('.table-wrap table');
    if(!table) return;
    var headerRow=table.querySelector('thead tr');
    if(headerRow&&!headerRow.querySelector('[data-fatura-tur-head]')){
      var th=document.createElement('th');
      th.setAttribute('data-fatura-tur-head','1');
      th.textContent='Fatura türü';
      var cariHead=headerRow.children[2];
      if(cariHead) cariHead.insertAdjacentElement('afterend',th);
    }

    var rows=rowMap();
    Object.keys(rows).forEach(function(id){
      var row=rows[id];
      if(row.querySelector('[data-fatura-tur-cell]')) return;
      var td=document.createElement('td');
      td.setAttribute('data-fatura-tur-cell',id);
      var cariCell=row.children[2];
      if(cariCell) cariCell.insertAdjacentElement('afterend',td);
    });
  }

  function cellHtml(item){
    var issuer=item.issuer_name
      ?'<small class="fatura-issuer-chip"><strong>Gönderen:</strong> '+esc(item.issuer_name)+'</small>'
      :(item.is_generic_cari&&item.issuer_source==='pdf'?'<small class="fatura-issuer-chip is-muted">Gönderen PDF’den okunamadı</small>':'');
    if(item.direction!=='gelen'){
      return '<span class="fatura-tur-static">Satış</span><small>Giden fatura</small>';
    }
    if(item.category){
      return '<button type="button" class="fatura-tur-button is-confirmed" data-fatura-tur-open="'+item.id+'"><strong>'+esc(item.category_label)+'</strong><small>Seçildi · değiştirmek için tıkla</small></button>'+issuer;
    }
    if(item.suggestion){
      return '<button type="button" class="fatura-tur-button is-suggested" data-fatura-tur-open="'+item.id+'"><strong>'+esc(item.suggestion_label)+'</strong><small>Otomatik öneri · onayla/değiştir</small></button>'+issuer;
    }
    return '<button type="button" class="fatura-tur-button is-empty" data-fatura-tur-open="'+item.id+'"><strong>Tür seç</strong><small>Telefon, iplik, doğalgaz...</small></button>'+issuer;
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

  function renderCells(){
    ensureColumn();
    var rows=rowMap();
    state.items.forEach(function(item){
      var row=rows[item.id];
      if(!row) return;
      row.setAttribute('data-invoice-type',item.effective_category||'');
      renderCariIssuer(row,item);
      var cell=row.querySelector('[data-fatura-tur-cell]');
      if(cell) cell.innerHTML=cellHtml(item);
    });
    applyFilter();
  }

  function ensureFilter(){
    var form=document.querySelector('.panel-card .filterbar');
    if(!form||form.querySelector('[data-fatura-tur-filter]')) return;
    var select=document.createElement('select');
    select.name='invoice_type';
    select.setAttribute('data-fatura-tur-filter','1');
    select.innerHTML='<option value="">Tüm fatura türleri</option>'+
      Object.keys(state.categories).map(function(key){return '<option value="'+esc(key)+'">'+esc(state.categories[key])+'</option>';}).join('')+
      '<option value="satis">Satış faturası</option>'+
      '<option value="belirsiz">Türü belirlenmemiş</option>';
    var fromUrl=new URLSearchParams(location.search).get('invoice_type')||'';
    if(Array.from(select.options).some(function(option){return option.value===fromUrl;})) select.value=fromUrl;
    var direction=form.querySelector('select[name="direction"]');
    if(direction) direction.insertAdjacentElement('afterend',select); else form.appendChild(select);
    select.addEventListener('change',applyFilter);
  }

  function applyFilter(){
    var select=document.querySelector('[data-fatura-tur-filter]');
    var selected=select?select.value:'';
    var visible=0;
    var total=0;
    var rows=rowMap();
    state.items.forEach(function(item){
      var row=rows[item.id];
      if(!row) return;
      total++;
      var effective=item.effective_category||'';
      var match=!selected||(selected==='belirsiz'?!effective:effective===selected);
      row.hidden=!match;
      if(match) visible++;
    });
    var card=document.querySelector('.panel-card .card-head span');
    if(card) card.textContent=selected?(visible+' görünür / '+total+' kayıt'):(total+' kayıt');
  }

  function ensureSummary(){
    var panel=document.querySelector('.table-wrap')&&document.querySelector('.table-wrap').closest('.panel-card');
    if(!panel) return null;
    var summary=panel.querySelector('[data-fatura-tur-summary]');
    if(!summary){
      summary=document.createElement('section');
      summary.setAttribute('data-fatura-tur-summary','1');
      summary.className='fatura-tur-summary';
      var anchor=panel.querySelector('[data-fatura-sira-kontrol]')||panel.querySelector('.filterbar');
      if(anchor) anchor.insertAdjacentElement('afterend',summary); else panel.insertAdjacentElement('afterbegin',summary);
    }
    return summary;
  }

  function renderSummary(rows){
    var summary=ensureSummary();
    if(!summary) return;
    if(!rows||!rows.length){
      summary.innerHTML='<div class="fatura-tur-summary-head"><strong>Aylık gelen fatura dağılımı</strong><small>Henüz türü belirlenen veya otomatik önerilen gelen fatura yok.</small></div>';
      return;
    }
    summary.innerHTML='<div class="fatura-tur-summary-head"><strong>Aylık gelen fatura dağılımı</strong><small>TL faturalar · otomatik öneriler ayrıca belirtilir.</small></div>'+
      '<div class="fatura-tur-summary-grid">'+rows.map(function(row){
        var note=row.suggested_count>0?(row.count+' fatura · '+row.suggested_count+' otomatik öneri'):(row.count+' fatura · onaylı');
        var issuers=Array.isArray(row.issuers)?row.issuers:[];
        var issuerNote=issuers.slice(0,3).map(function(issuer){return issuer.name+' · '+issuer.count+' fatura';}).join(' • ');
        if(issuers.length>3) issuerNote+=' • +'+(issuers.length-3)+' firma';
        if(Number(row.unidentified_count||0)>0) issuerNote+=(issuerNote?' • ':'')+row.unidentified_count+' gönderen okunamadı';
        return '<button type="button" data-fatura-tur-summary-filter="'+esc(row.key)+'"><span>'+esc(row.label)+'</span><strong>'+money(row.total)+'</strong><small>'+esc(note)+'</small>'+(issuerNote?'<small class="fatura-tur-issuer-list">'+esc(issuerNote)+'</small>':'')+'</button>';
      }).join('')+'</div>';
  }

  function buildModal(){
    if(document.getElementById('faturaTurModal')) return;
    var modal=document.createElement('div');
    modal.id='faturaTurModal';
    modal.className='fatura-tur-modal';
    modal.hidden=true;
    modal.innerHTML=''
      +'<div class="fatura-tur-dialog" role="dialog" aria-modal="true" aria-labelledby="faturaTurTitle">'
      +'<div class="fatura-tur-head"><div><strong id="faturaTurTitle">Fatura türünü belirle</strong><small>Gideri telefon, iplik, doğalgaz gibi gruplandır.</small></div><button type="button" data-fatura-tur-close aria-label="Kapat">×</button></div>'
      +'<div class="fatura-tur-info" data-fatura-tur-info></div>'
      +'<label>Fatura türü<select data-fatura-tur-select></select></label>'
      +'<div class="fatura-tur-actions"><button type="button" class="btn btn-secondary" data-fatura-tur-pdf>PDF’den otomatik öner</button><button type="button" class="btn btn-primary" data-fatura-tur-save>Türü kaydet</button></div>'
      +'<button type="button" class="fatura-tur-remove" data-fatura-tur-remove>Tür seçimini kaldır</button>'
      +'<p class="fatura-tur-status" data-fatura-tur-status></p>'
      +'</div>';
    document.body.appendChild(modal);
    var close=function(){modal.hidden=true;state.activeId=0;state.activeSource='manual';};
    modal.querySelector('[data-fatura-tur-close]').addEventListener('click',close);
    modal.addEventListener('click',function(event){if(event.target===modal) close();});
    document.addEventListener('keydown',function(event){if(event.key==='Escape'&&!modal.hidden) close();});
  }

  function setModalStatus(text,tone){
    var el=document.querySelector('[data-fatura-tur-status]');
    if(!el) return;
    el.textContent=text||'';
    el.className='fatura-tur-status'+(tone?' is-'+tone:'');
  }

  function openModal(id){
    var item=state.itemMap[id];
    if(!item||item.direction!=='gelen') return;
    buildModal();
    state.activeId=id;
    state.activeSource='manual';
    var modal=document.getElementById('faturaTurModal');
    var selected=item.category||item.suggestion||'';
    modal.querySelector('[data-fatura-tur-select]').innerHTML=categoryOptions(selected);
    modal.querySelector('[data-fatura-tur-info]').innerHTML='<strong>Gönderen: '+esc(item.issuer_name||'Henüz okunmadı')+'</strong><small>Cari: '+esc(item.cari_name||'Cari seçilmedi')+' · '+esc(item.document_name||'Fatura dosyası yok')+'</small>'+
      (item.suggestion?'<em>Otomatik öneri: '+esc(item.suggestion_label)+(item.suggestion_match?' · '+esc(item.suggestion_match):'')+'</em>':'');
    modal.querySelector('[data-fatura-tur-pdf]').disabled=!item.has_document||!/\.pdf$/i.test(item.document_name||'');
    modal.querySelector('[data-fatura-tur-remove]').hidden=!item.category;
    setModalStatus(item.category?'Mevcut türü değiştirebilir veya kaldırabilirsin.':(item.suggestion?'Önerilen tür seçili geldi; kontrol edip kaydet.':'Türü seç veya PDF içeriğinden öneri al.'),'neutral');
    modal.hidden=false;
  }

  function categoryFromText(text){
    var value=norm(text);
    var rules={
      telefon:['TURK TELEKOM','TTNET','TURKCELL','VODAFONE','SUPERONLINE','TELEFON','INTERNET','GSM'],
      dogalgaz:['DOGALGAZ','DOGAL GAZ','AKSA GAZ','AKSAGAZ','ENERYA','IGDAS','GAZDAS'],
      elektrik:['ELEKTRIK','YEDAS','CEDAS','UEDAS','CK ENERJI','YESILIRMAK ELEKTRIK','ULUDAG ELEKTRIK'],
      iplik:['IPLIK','PAMUK','POLYESTER','ELYAF','LIKRA','BAMBU','MODAL','HAMMADDE'],
      kargo:['KARGO','NAKLIYE','LOJISTIK','SURAT KARGO','YURTICI KARGO','ARAS KARGO','MNG KARGO','PTT KARGO'],
      akaryakit:['AKARYAKIT','BENZIN','MOTORIN','PETROL OFISI','OPET','SHELL','BP PETROL'],
      bakim:['MAKINE','BAKIM','YEDEK PARCA','RULMAN','KOMPRESOR','TEKNIK SERVIS'],
      ambalaj:['AMBALAJ','KOLI','KARTON','POSET','KUTU','ETIKET','PAKETLEME'],
      personel:['PERSONEL','MAAS','SGK','IS SAGLIGI','YEMEK HIZMET','PERSONEL SERVIS'],
      ofis:['KIRTASIYE','OFIS','TEMIZLIK','MUHASEBE','DANISMANLIK','YAZILIM','LISANS','ABONELIK']
    };
    var best='';
    var bestLength=0;
    Object.keys(rules).forEach(function(category){
      rules[category].forEach(function(keyword){
        if(value.indexOf(keyword)!==-1&&keyword.length>bestLength){best=category;bestLength=keyword.length;}
      });
    });
    return best;
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

  async function suggestFromPdf(){
    var item=state.itemMap[state.activeId];
    if(!item||!item.document_url) return;
    var button=document.querySelector('[data-fatura-tur-pdf]');
    var oldText=button.textContent;
    button.disabled=true;
    button.textContent='PDF okunuyor...';
    setModalStatus('Fatura içindeki ürün ve hizmet açıklamaları aranıyor...','loading');
    try{
      var values=await Promise.all([
        loadPdfJs(),
        fetch(item.document_url,{credentials:'same-origin',cache:'no-store'}).then(function(response){if(!response.ok) throw new Error('Fatura dosyası açılamadı.');return response.arrayBuffer();})
      ]);
      var pdf=await values[0].getDocument({data:values[1]}).promise;
      var text='';
      for(var pageNo=1;pageNo<=Math.min(pdf.numPages,5);pageNo++){
        var page=await pdf.getPage(pageNo);
        var content=await page.getTextContent();
        text+=' '+(content.items||[]).map(function(part){return part.str||'';}).join(' ');
      }
      var category=categoryFromText(text);
      if(!category){
        setModalStatus('PDF içinde güvenilir bir tür bulunamadı. Listeden elle seç.','warning');
      }else{
        document.querySelector('[data-fatura-tur-select]').value=category;
        state.activeSource='pdf';
        setModalStatus('PDF içeriğine göre “'+state.categories[category]+'” önerildi. Kontrol edip kaydet.','success');
      }
    }catch(error){
      setModalStatus(error.message||'PDF’den tür önerisi alınamadı.','danger');
    }finally{
      button.disabled=false;
      button.textContent=oldText;
    }
  }

  function saveCategory(category){
    if(!state.activeId) return;
    var button=document.querySelector('[data-fatura-tur-save]');
    var oldText=button?button.textContent:'';
    if(button){button.disabled=true;button.textContent='Kaydediliyor...';}
    var body=new FormData();
    body.set('csrf_token',state.csrf);
    body.set('invoice_id',String(state.activeId));
    body.set('category',category||'');
    body.set('source',state.activeSource);
    body.set('period',state.period);
    fetch('fatura-tur.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(!data.ok) throw new Error(data.error||'Fatura türü kaydedilemedi.');
        applyPayload(data);
        document.getElementById('faturaTurModal').hidden=true;
        state.activeId=0;
        state.activeSource='manual';
      })
      .catch(function(error){setModalStatus(error.message||'Fatura türü kaydedilemedi.','danger');})
      .finally(function(){if(button){button.disabled=false;button.textContent=oldText;}});
  }

  function applyPayload(data){
    state.period=data.period||state.period;
    state.categories=data.categories||state.categories;
    state.items=Array.isArray(data.items)?data.items:[];
    state.itemMap={};
    state.items.forEach(function(item){state.itemMap[item.id]=item;});
    state.csrf=String(data.csrf_token||state.csrf);
    if(typeof data.can_write!=='undefined') state.canWrite=!!data.can_write;
    ensureFilter();
    renderCells();
    renderSummary(data.summary||[]);
  }

  document.addEventListener('click',function(event){
    var open=event.target.closest('[data-fatura-tur-open]');
    if(open){openModal(Number(open.getAttribute('data-fatura-tur-open')||0));return;}
    var summaryFilter=event.target.closest('[data-fatura-tur-summary-filter]');
    if(summaryFilter){
      var select=document.querySelector('[data-fatura-tur-filter]');
      if(select){select.value=summaryFilter.getAttribute('data-fatura-tur-summary-filter')||'';applyFilter();}
      return;
    }
    if(event.target.closest('[data-fatura-tur-pdf]')){suggestFromPdf();return;}
    if(event.target.closest('[data-fatura-tur-save]')){
      var select=document.querySelector('[data-fatura-tur-select]');
      saveCategory(select?select.value:'');
      return;
    }
    if(event.target.closest('[data-fatura-tur-remove]')){
      if(window.confirm('Bu faturanın tür seçimi kaldırılsın mı?')) saveCategory('');
    }
  });

  document.addEventListener('bitke:fatura-meta-updated',function(event){
    if(event.detail&&event.detail.ok) applyPayload(event.detail);
  });

  var style=document.createElement('style');
  style.textContent=''
    +'.fatura-tur-button{border:1px solid #d8c7a9;background:#fffaf0;border-radius:10px;padding:7px 8px;display:grid;gap:2px;text-align:left;cursor:pointer;min-width:120px;max-width:165px}.fatura-tur-button strong{font-size:10px;line-height:1.2}.fatura-tur-button small{font-size:8px;color:#776a57}.fatura-tur-button.is-confirmed{border-color:#a8cdb5;background:#eff9f2;color:#27623d}.fatura-tur-button.is-suggested{border-style:dashed;border-color:#d3a74e;background:#fff8e6;color:#76520d}.fatura-tur-button.is-empty{border-style:dashed;color:#766a58;background:#fff}.fatura-tur-static{display:inline-flex;padding:5px 8px;border-radius:999px;background:#eaf5ee;color:#27623d;font-size:9px;font-weight:900}[data-fatura-tur-cell]>small{display:block;margin-top:3px;font-size:8px;color:var(--muted)}'
    +'.fatura-tur-summary{margin:12px 0 14px;padding:13px 14px;border:1px solid #d9e3dc;background:#f7fbf8;border-radius:15px;display:grid;gap:10px}.fatura-tur-summary-head{display:grid;gap:3px}.fatura-tur-summary-head strong{font-size:13px}.fatura-tur-summary-head small{font-size:10px;color:var(--muted)}.fatura-tur-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:8px}.fatura-tur-summary-grid button{border:1px solid #d8dfda;background:#fff;border-radius:11px;padding:9px 10px;text-align:left;display:grid;gap:3px;cursor:pointer}.fatura-tur-summary-grid button:hover{border-color:#b99b65;background:#fffaf0}.fatura-tur-summary-grid span{font-size:9px;font-weight:850;color:#5c554b}.fatura-tur-summary-grid strong{font-size:13px}.fatura-tur-summary-grid small{font-size:8px;color:var(--muted)}.fatura-tur-summary-grid .fatura-tur-issuer-list{margin-top:3px;padding-top:5px;border-top:1px solid #eee6d7;color:#334e41;font-weight:800;line-height:1.35}.fatura-issuer-chip{display:block;margin-top:5px;padding:5px 7px;border-radius:8px;background:#edf5ff;color:#234f73;font-size:8px;line-height:1.35;max-width:165px}.fatura-issuer-chip.is-muted{background:#f5f2ed;color:#7d7060}'
    +'.fatura-tur-modal{position:fixed;inset:0;background:rgba(20,28,24,.48);z-index:10050;display:grid;place-items:center;padding:20px}.fatura-tur-modal[hidden]{display:none}.fatura-tur-dialog{width:min(560px,100%);max-height:90vh;overflow:auto;background:#fff;border-radius:20px;box-shadow:0 24px 70px rgba(0,0,0,.25);padding:18px;display:grid;gap:14px}.fatura-tur-head{display:flex;justify-content:space-between;gap:12px;align-items:start}.fatura-tur-head>div{display:grid;gap:3px}.fatura-tur-head strong{font-size:18px}.fatura-tur-head small{font-size:11px;color:var(--muted)}.fatura-tur-head>button{border:0;background:#f0ece4;border-radius:50%;width:34px;height:34px;font-size:20px;cursor:pointer}.fatura-tur-info{padding:10px 12px;border-radius:12px;background:#f6f3ed;display:grid;gap:3px}.fatura-tur-info strong{font-size:13px}.fatura-tur-info small{font-size:10px;color:var(--muted)}.fatura-tur-info em{font-style:normal;font-size:10px;color:#8a5f0d;font-weight:800}.fatura-tur-dialog>label{display:grid;gap:6px;font-size:11px;font-weight:850}.fatura-tur-dialog select{width:100%;border:1px solid var(--border);border-radius:12px;padding:11px;background:#fff}.fatura-tur-actions{display:flex;gap:9px;flex-wrap:wrap}.fatura-tur-actions .btn{flex:1}.fatura-tur-remove{border:0;background:transparent;color:#a03a32;text-decoration:underline;font-size:10px;cursor:pointer;justify-self:start}.fatura-tur-status{margin:0;padding:9px 11px;border-radius:10px;background:#f3efe7;color:#655e53;font-size:11px;font-weight:800}.fatura-tur-status.is-success{background:#e8f5ed;color:#1f6b3d}.fatura-tur-status.is-warning{background:#fff4dc;color:#835710}.fatura-tur-status.is-danger{background:#fff0ef;color:#96352f}.fatura-tur-status.is-loading{background:#eef5ff;color:#234f84}@media(max-width:720px){.fatura-tur-actions{display:grid}.fatura-tur-button{min-width:105px}}';
  document.head.appendChild(style);

  state.period=periodValue();
  fetch('fatura-tur.php?period='+encodeURIComponent(state.period)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
    .then(function(response){return response.json();})
    .then(function(data){if(!data.ok) throw new Error(data.error||'Fatura türleri yüklenemedi.');applyPayload(data);})
    .catch(function(error){console.error(error);});
})();
