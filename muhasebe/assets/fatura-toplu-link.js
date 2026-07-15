(function(){
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  var params=new URLSearchParams(location.search);
  var editId=Number(params.get('edit')||0);
  var formGrid=document.querySelector('.form-grid');

  // Normal fatura listesindeyken tekli fatura formunu sayfada yer kaplamayacak şekilde gizle.
  // Ayrı fatura giriş penceresi bu kartı gerektiğinde açar; ?edit=ID görünümünde form normal kalır.
  if(formGrid&&editId<=0){
    var formCard=formGrid.querySelector('.form-card');
    if(formCard){
      formCard.classList.add('fatura-entry-source');
      formCard.hidden=true;
      formCard.setAttribute('aria-hidden','true');
    }
    formGrid.classList.add('fatura-list-only');
  }

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

  var panel=document.createElement('div');
  panel.className='toplu-yon-duzelt-panel';
  panel.hidden=true;
  panel.innerHTML='<div><strong>Son toplu yükleme yön kontrolü</strong><small data-toplu-yon-ozet>Kontrol ediliyor...</small></div>'
    +'<div class="toplu-yon-actions"><button type="button" class="btn btn-secondary" data-toplu-yon="giden">Tamamını giden yap</button><button type="button" class="btn btn-secondary" data-toplu-yon="gelen">Tamamını gelen yap</button></div>';
  if(filter) filter.insertAdjacentElement('afterend',panel); else section.appendChild(panel);

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
    script.src='assets/fatura-iki-kolon.js?v=2';
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
    +'@media(max-width:720px){.toplu-yon-duzelt-panel{grid-template-columns:1fr}.toplu-yon-actions{justify-content:flex-start}.fatura-list-only table{min-width:980px}}';
  document.head.appendChild(style);

  load();
  loadSplitView();
})();
