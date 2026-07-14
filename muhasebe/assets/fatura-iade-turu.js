(function(){
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  var iadeIds={};
  var sortScheduled=false;

  function periodValue(){
    var input=document.querySelector('input[type="month"][name="period"]');
    var value=input?String(input.value||''):'';
    if(!/^\d{4}-\d{2}$/.test(value)) value=new URLSearchParams(location.search).get('period')||'';
    return /^\d{4}-\d{2}$/.test(value)?value:new Date().toISOString().slice(0,7);
  }

  function ensureOption(select){
    if(!select||select.querySelector('option[value="iade"]')) return;
    var option=document.createElement('option');
    option.value='iade';
    option.textContent='İade Faturası';
    var first=select.querySelector('option');
    if(first) first.insertAdjacentElement('afterend',option); else select.appendChild(option);
  }

  function applySelect(select){
    ensureOption(select);
    var id=Number(select.getAttribute('data-fatura-tur-auto-select')||0);
    if(id&&iadeIds[id]) select.value='iade';
  }

  function applyAll(){
    document.querySelectorAll('select[data-fatura-tur-auto-select]').forEach(applySelect);
  }

  function applyPayload(data){
    var items=Array.isArray(data&&data.items)?data.items:[];
    iadeIds={};
    items.forEach(function(item){
      if(String(item.category||'')==='iade') iadeIds[Number(item.id||0)]=true;
    });
    applyAll();
    scheduleSort();
  }

  function rowDateValue(row){
    var cell=row&&row.cells?row.cells[0]:null;
    if(!cell) return 0;
    var strong=cell.querySelector('strong');
    var text=String(strong?strong.textContent:cell.textContent||'').trim();
    var match=text.match(/(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})/);
    if(match) return new Date(Number(match[3]),Number(match[2])-1,Number(match[1])).getTime();
    match=text.match(/(\d{4})-(\d{1,2})-(\d{1,2})/);
    if(match) return new Date(Number(match[1]),Number(match[2])-1,Number(match[3])).getTime();
    return 0;
  }

  function rowInvoiceId(row){
    var input=row.querySelector('form input[name="id"]');
    if(input) return Number(input.value||0);
    var edit=row.querySelector('a[href*="edit="]');
    if(edit){
      try{return Number(new URL(edit.href,location.href).searchParams.get('edit')||0);}catch(error){}
    }
    return 0;
  }

  function sortRows(){
    sortScheduled=false;
    var body=document.querySelector('.table-wrap table tbody');
    if(!body) return;
    var rows=Array.prototype.slice.call(body.querySelectorAll(':scope > tr'));
    var dated=rows.filter(function(row){return rowDateValue(row)>0;});
    if(dated.length<2) return;
    var sorted=dated.slice().sort(function(a,b){
      var dateDiff=rowDateValue(b)-rowDateValue(a);
      if(dateDiff!==0) return dateDiff;
      return rowInvoiceId(b)-rowInvoiceId(a);
    });
    var current=dated;
    var changed=sorted.some(function(row,index){return row!==current[index];});
    if(!changed) return;
    sorted.forEach(function(row){body.appendChild(row);});
  }

  function scheduleSort(){
    if(sortScheduled) return;
    sortScheduled=true;
    window.requestAnimationFrame(sortRows);
  }

  function ensureBottomArea(listSection){
    var area=document.querySelector('[data-fatura-alt-kontroller]');
    if(area) return area;

    area=document.createElement('section');
    area.className='panel-card fatura-alt-kontroller';
    area.setAttribute('data-fatura-alt-kontroller','1');
    area.innerHTML='<div class="card-head"><div><h3>Fatura araçları</h3><small>Seyrek kullanılan dönem, toplu yükleme ve KDV devir işlemleri</small></div></div><div class="fatura-alt-kontrol-body" data-fatura-alt-kontrol-body></div>';
    listSection.insertAdjacentElement('afterend',area);
    return area;
  }

  function moveAuxiliaryControls(){
    var dashboard=document.querySelector('.dashboard-section');
    var listSection=document.querySelector('.form-grid');
    if(!dashboard||!listSection) return;

    var area=ensureBottomArea(listSection);
    var body=area.querySelector('[data-fatura-alt-kontrol-body]');
    if(!body) return;

    var periodForm=dashboard.querySelector('form.filterbar');
    if(periodForm&&periodForm.parentElement!==body){
      periodForm.classList.add('fatura-alt-period-form');
      body.appendChild(periodForm);
    }

    var bulkPanel=document.querySelector('.toplu-yon-duzelt-panel');
    if(bulkPanel&&bulkPanel.parentElement!==body) body.appendChild(bulkPanel);

    var carryPanel=document.getElementById('kdvDevirPanel');
    if(carryPanel&&carryPanel.parentElement!==body) body.appendChild(carryPanel);
  }

  document.addEventListener('bitke:fatura-meta-updated',function(event){
    applyPayload(event.detail||{});
  });

  var style=document.createElement('style');
  style.textContent=''
    +'.fatura-alt-kontroller{display:grid;gap:12px;margin-top:18px;padding:16px}'
    +'.fatura-alt-kontroller>.card-head{margin:0;padding-bottom:4px}'
    +'.fatura-alt-kontroller>.card-head small{display:block;margin-top:3px;color:var(--muted);font-size:10px}'
    +'.fatura-alt-kontrol-body{display:grid;gap:12px}'
    +'.fatura-alt-period-form{margin:0!important;padding:12px!important;border:1px solid var(--border);border-radius:14px;background:#faf9f6}'
    +'.fatura-alt-kontroller .toplu-yon-duzelt-panel{margin:0}'
    +'.fatura-alt-kontroller .kdv-devir-panel{margin:0}'
    +'@media(max-width:720px){.fatura-alt-kontroller{padding:12px}.fatura-alt-period-form{align-items:stretch}}';
  document.head.appendChild(style);

  var observer=new MutationObserver(function(){
    applyAll();
    scheduleSort();
    moveAuxiliaryControls();
  });
  observer.observe(document.documentElement,{childList:true,subtree:true});

  applyAll();
  scheduleSort();
  moveAuxiliaryControls();

  fetch('fatura-tur.php?period='+encodeURIComponent(periodValue())+'&_='+Date.now(),{
    credentials:'same-origin',cache:'no-store'
  })
    .then(function(response){return response.json();})
    .then(function(data){if(data&&data.ok) applyPayload(data);})
    .catch(function(){applyAll();scheduleSort();moveAuxiliaryControls();});
})();
