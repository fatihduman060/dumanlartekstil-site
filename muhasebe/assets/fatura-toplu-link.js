(function(){
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  var params=new URLSearchParams(location.search);
  var editId=Number(params.get('edit')||0);
  var formGrid=document.querySelector('.form-grid');

  // Yeni fatura kaydedilince tekrar aratmadan doğrudan ilgili aya/yöne ve satıra götür.
  var newInvoiceStorageKey='bitkeYeniFaturaOdak';
  function readNewInvoiceFocus(){
    try{
      var data=JSON.parse(sessionStorage.getItem(newInvoiceStorageKey)||'null');
      if(!data||!data.savedAt||Date.now()-Number(data.savedAt)>5*60*1000) return null;
      return data;
    }catch(error){return null;}
  }
  function clearNewInvoiceFocus(){
    try{sessionStorage.removeItem(newInvoiceStorageKey);}catch(error){}
  }
  function rememberNewInvoice(){
    var form=document.getElementById('invoiceForm');
    if(!form) return;
    var idInput=form.querySelector('input[name="id"]');
    if(Number(idInput?idInput.value:0)>0) return;
    form.addEventListener('submit',function(){
      var dateInput=form.querySelector('[name="invoice_date"]');
      var directionInput=form.querySelector('[name="direction"]');
      var numberInput=form.querySelector('[name="invoice_no"]');
      var totalInput=form.querySelector('[name="total_amount"]');
      var date=String(dateInput?dateInput.value:'');
      var direction=String(directionInput?directionInput.value:'gelen');
      var invoiceNo=String(numberInput?numberInput.value:'').trim();
      if(!/^\d{4}-\d{2}-\d{2}$/.test(date)||!invoiceNo) return;
      try{
        sessionStorage.setItem(newInvoiceStorageKey,JSON.stringify({
          savedAt:Date.now(),
          period:date.slice(0,7),
          date:date,
          direction:direction==='giden'?'giden':'gelen',
          invoiceNo:invoiceNo,
          total:String(totalInput?totalInput.value:'').trim()
        }));
      }catch(error){}
    });
  }
  function successfulNewInvoiceSave(){
    return Array.prototype.some.call(document.querySelectorAll('.alert-success'),function(alert){
      return /Fatura arşive eklendi/i.test(String(alert.textContent||''));
    });
  }
  function rowMatchesNewInvoice(row,data){
    if(!row||!data) return false;
    var cells=row.querySelectorAll('td');
    if(!cells.length) return false;
    var first=cells[0];
    var dateText=String((first.querySelector('strong')||first).textContent||'').trim();
    var numberText=String((first.querySelector('small')||first).textContent||'').trim();
    var expectedDate=String(data.date||'').split('-').reverse().join('.');
    if(expectedDate&&dateText!==expectedDate) return false;
    return numberText.indexOf(String(data.invoiceNo||''))!==-1;
  }
  function focusNewInvoiceRow(data){
    var rows=Array.prototype.slice.call(document.querySelectorAll('.table-wrap table tbody tr'));
    var row=rows.find(function(item){return rowMatchesNewInvoice(item,data);});
    if(!row) return false;
    row.classList.add('bitke-new-invoice-focus');
    var cariButton=Array.prototype.slice.call(row.querySelectorAll('button')).find(function(button){
      return /Cariye işle|Cariyi güncelle/i.test(String(button.textContent||''));
    });
    if(cariButton) cariButton.classList.add('bitke-new-invoice-cari-focus');
    window.setTimeout(function(){
      row.scrollIntoView({behavior:'smooth',block:'center'});
      if(cariButton) cariButton.focus({preventScroll:true});
    },120);
    window.setTimeout(function(){row.classList.remove('bitke-new-invoice-focus');},7000);
    clearNewInvoiceFocus();
    return true;
  }
  function continueNewInvoiceFocus(){
    var data=readNewInvoiceFocus();
    if(!data) return;
    var focusRequested=params.get('focus_new_invoice')==='1';
    if(!focusRequested){
      if(!successfulNewInvoiceSave()) return;
      var target=new URL(location.href);
      target.search='';
      target.searchParams.set('period',String(data.period||''));
      target.searchParams.set('direction',String(data.direction||'gelen'));
      target.searchParams.set('focus_new_invoice','1');
      target.hash='fatura-listesi';
      location.replace(target.pathname+'?'+target.searchParams.toString()+target.hash);
      return;
    }
    var attempts=0;
    (function tryFocus(){
      attempts++;
      if(focusNewInvoiceRow(data)) return;
      if(attempts<20) window.setTimeout(tryFocus,100);
      else clearNewInvoiceFocus();
    })();
  }
  rememberNewInvoice();

  // Liste/form yerleşimi ilk PHP yanıtında hazırlanır; burada sonradan değiştirilmez.

  var section=document.querySelector('.dashboard-section');
  if(!section) return;
  var filter=section.querySelector('.filterbar');

  if(!document.querySelector('[data-toplu-fatura-link]')){
    var link=document.createElement('a');
    link.href='fatura-toplu-yukle.php';
    link.className='btn btn-primary';
    link.setAttribute('data-toplu-fatura-link','1');
    link.textContent='Toplu PDF yükle';
    if(filter) filter.appendChild(link); else section.appendChild(link);
  }

  var panel=document.querySelector('[data-toplu-yon-panel]');
  if(!panel){
    panel=document.createElement('div');
    panel.className='toplu-yon-duzelt-panel';
    panel.setAttribute('data-toplu-yon-panel','1');
    panel.hidden=true;
    panel.innerHTML='<div><strong>Son toplu yükleme yön kontrolü</strong><small data-toplu-yon-ozet>Kontrol ediliyor...</small></div>'
      +'<div class="toplu-yon-actions"><button type="button" class="btn btn-secondary" data-toplu-yon="giden">Tamamını giden yap</button><button type="button" class="btn btn-secondary" data-toplu-yon="gelen">Tamamını gelen yap</button></div>';
    if(filter) filter.insertAdjacentElement('afterend',panel); else section.appendChild(panel);
  }

  var state={batch:'',csrf:'',count:0};
  var summary=panel.querySelector('[data-toplu-yon-ozet]');

  function render(data){
    if(!data||!data.ok||!Number(data.count||0)){
      panel.hidden=true;
      return;
    }
    state.batch=String(data.batch||'');
    state.csrf=String(data.csrf_token||'');
    state.count=Number(data.count||0);
    summary.textContent=state.count+' fatura · '+Number(data.outgoing||0)+' giden · '+Number(data.incoming||0)+' gelen'
      +(Number(data.posted||0)>0?' · '+Number(data.posted||0)+' cariye işlenmiş':' · cari hareketi oluşturulmamış');
    panel.hidden=false;
  }

  function load(){
    fetch('fatura-toplu-yon-duzelt.php?_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(render)
      .catch(function(){panel.hidden=true;});
  }

  panel.addEventListener('click',function(event){
    var button=event.target.closest('[data-toplu-yon]');
    if(!button||!state.batch) return;
    var direction=button.getAttribute('data-toplu-yon');
    var label=direction==='giden'?'giden (bizim kestiğimiz)':'gelen (bize kesilen)';
    if(!window.confirm('Son toplu yüklemedeki '+state.count+' faturanın tamamı '+label+' olarak düzeltilecek. Devam edilsin mi?')) return;

    var buttons=panel.querySelectorAll('button');
    buttons.forEach(function(btn){btn.disabled=true;});
    var body=new FormData();
    body.set('csrf_token',state.csrf);
    body.set('batch',state.batch);
    body.set('direction',direction);

    fetch('fatura-toplu-yon-duzelt.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(!data.ok) throw new Error(data.error||'Yön düzeltilemedi.');
        render(data);
        window.alert('Son toplu yüklemedeki faturaların yönü düzeltildi. KDV özeti yeni yöne göre güncellenecek.');
        window.location.reload();
      })
      .catch(function(error){window.alert(error.message||'Yön düzeltilemedi.');})
      .finally(function(){buttons.forEach(function(btn){btn.disabled=false;});});
  });

  function loadSplitView(){
    if(document.querySelector('script[data-fatura-iki-kolon-loader]')) return;
    var script=document.createElement('script');
    script.src='assets/fatura-iki-kolon.js?v=5';
    script.setAttribute('data-fatura-iki-kolon-loader','1');
    document.body.appendChild(script);
  }

  var style=document.createElement('style');
  style.textContent=''
    +'.form-grid.fatura-list-only{display:block!important;grid-template-columns:minmax(0,1fr)!important;width:100%}'
    +'.fatura-list-only>.panel-card{width:100%;max-width:none;margin:0}'
    +'.fatura-entry-source[hidden]{display:none!important}'
    +'.fatura-list-only .table-wrap{width:100%;overflow:auto}'
    +'.fatura-list-only table{width:100%;min-width:1120px}'
    +'.fatura-list-only table th,.fatura-list-only table td{font-size:calc(1em - .5px)}'
    +'.fatura-list-only table select,.fatura-list-only table button{font-size:calc(1em - .25px)}'
    +'.toplu-yon-duzelt-panel{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;margin:10px 0 14px;padding:12px 14px;border:1px solid #efcf95;background:#fff7e5;border-radius:14px}'
    +'.toplu-yon-duzelt-panel[hidden]{display:none}'
    +'.toplu-yon-duzelt-panel>div:first-child{display:grid;gap:3px}'
    +'.toplu-yon-duzelt-panel strong{font-size:13px}'
    +'.toplu-yon-duzelt-panel small{font-size:11px;color:var(--muted)}'
    +'.toplu-yon-actions{display:flex;gap:8px;flex-wrap:wrap}'
    +'.toplu-yon-actions .btn{padding:8px 10px;font-size:11px}'
    +'.bitke-new-invoice-focus td{background:#fff6cf!important;box-shadow:inset 0 2px #d7aa3b,inset 0 -2px #d7aa3b}'
    +'.bitke-new-invoice-focus td:first-child{box-shadow:inset 2px 0 #d7aa3b,inset 0 2px #d7aa3b,inset 0 -2px #d7aa3b}'
    +'.bitke-new-invoice-focus td:last-child{box-shadow:inset -2px 0 #d7aa3b,inset 0 2px #d7aa3b,inset 0 -2px #d7aa3b}'
    +'.bitke-new-invoice-cari-focus{background:#16482e!important;color:#fff!important;border-radius:8px!important;padding:7px 10px!important;box-shadow:0 0 0 4px rgba(196,154,79,.22)!important}'
    +'@media(max-width:720px){.toplu-yon-duzelt-panel{grid-template-columns:1fr}.toplu-yon-actions{justify-content:flex-start}.fatura-list-only table{min-width:980px}}';
  document.head.appendChild(style);

  continueNewInvoiceFocus();
  load();
  loadSplitView();
})();
