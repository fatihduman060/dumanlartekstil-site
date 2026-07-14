(function(){
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  var state={cariler:[],csrf:'',invoiceId:0,cell:null};

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

  function buildModal(){
    if(document.getElementById('faturaCariModal')) return;
    var modal=document.createElement('div');
    modal.id='faturaCariModal';
    modal.className='fatura-cari-modal';
    modal.hidden=true;
    modal.innerHTML=''
      +'<div class="fatura-cari-dialog" role="dialog" aria-modal="true" aria-labelledby="faturaCariTitle">'
      +'<div class="fatura-cari-head"><div><strong id="faturaCariTitle">Faturaya cari seç</strong><small>Arayıp cariyi seç; bu işlem faturayı cariye işlemez.</small></div><button type="button" class="fatura-cari-close" aria-label="Kapat">×</button></div>'
      +'<label class="fatura-cari-search">Cari ara<input type="search" placeholder="Firma veya kişi adı..."></label>'
      +'<label class="fatura-cari-select-label">Cari<select><option value="">Cari seç...</option></select></label>'
      +'<p class="fatura-cari-status"></p>'
      +'<div class="fatura-cari-actions"><button type="button" class="btn btn-secondary" data-cari-cancel>Vazgeç</button><button type="button" class="btn btn-primary" data-cari-save disabled>Cariyi kaydet</button></div>'
      +'</div>';
    document.body.appendChild(modal);

    var close=function(){modal.hidden=true;state.invoiceId=0;state.cell=null;};
    modal.querySelector('.fatura-cari-close').addEventListener('click',close);
    modal.querySelector('[data-cari-cancel]').addEventListener('click',close);
    modal.addEventListener('click',function(event){if(event.target===modal) close();});
    document.addEventListener('keydown',function(event){if(event.key==='Escape'&&!modal.hidden) close();});

    var search=modal.querySelector('input[type="search"]');
    var select=modal.querySelector('select');
    var save=modal.querySelector('[data-cari-save]');
    search.addEventListener('input',function(){fillOptions(search.value);});
    select.addEventListener('change',function(){save.disabled=!select.value;});
    save.addEventListener('click',saveCari);
  }

  function fillOptions(query){
    var modal=document.getElementById('faturaCariModal');
    if(!modal) return;
    var select=modal.querySelector('select');
    var current=select.value;
    var key=norm(query);
    var rows=state.cariler.filter(function(cari){return !key||norm(cari.name).indexOf(key)!==-1;});
    select.innerHTML='<option value="">Cari seç...</option>'+rows.map(function(cari){
      return '<option value="'+esc(cari.id)+'">'+esc(cari.name)+' — '+esc(cari.cari_type||'Firma')+'</option>';
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

  function openModal(invoiceId,cell){
    buildModal();
    var modal=document.getElementById('faturaCariModal');
    state.invoiceId=invoiceId;
    state.cell=cell;
    modal.querySelector('input[type="search"]').value='';
    fillOptions('');
    modal.querySelector('select').value='';
    modal.querySelector('[data-cari-save]').disabled=true;
    setStatus('');
    modal.hidden=false;
    setTimeout(function(){modal.querySelector('input[type="search"]').focus();},30);
  }

  function saveCari(){
    var modal=document.getElementById('faturaCariModal');
    var select=modal.querySelector('select');
    var cariId=Number(select.value||0);
    if(!state.invoiceId||!cariId) return;

    var button=modal.querySelector('[data-cari-save]');
    var oldText=button.textContent;
    button.disabled=true;
    button.textContent='Kaydediliyor...';
    setStatus('Cari faturaya bağlanıyor...','loading');

    var body=new FormData();
    body.set('csrf_token',state.csrf);
    body.set('invoice_id',String(state.invoiceId));
    body.set('cari_id',String(cariId));

    fetch('fatura-cari-sec.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(!data.ok) throw new Error(data.error||'Cari kaydedilemedi.');
        state.csrf=String(data.csrf_token||state.csrf);
        if(state.cell){
          state.cell.innerHTML='<a href="cari-detay.php?id='+encodeURIComponent(data.cari.id)+'">'+esc(data.cari.name)+'</a><small class="fatura-cari-secildi">Manuel seçildi</small>';
        }
        setStatus('Cari faturaya bağlandı.','success');
        setTimeout(function(){modal.hidden=true;state.invoiceId=0;state.cell=null;},500);
      })
      .catch(function(error){setStatus(error.message||'Cari kaydedilemedi.','danger');})
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
      cell.innerHTML='<button type="button" class="fatura-cari-sec-btn" data-fatura-cari-sec="'+invoiceId+'"><strong>Cari yok</strong><small>Buradan cari seç</small></button>';
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
    +'.fatura-cari-sec-btn{border:1px dashed #c9a96e;background:#fff9ee;color:#51452f;border-radius:11px;padding:8px 10px;display:grid;gap:2px;text-align:left;cursor:pointer;min-width:105px}.fatura-cari-sec-btn:hover{background:#fff1d5;border-color:#a77b31}.fatura-cari-sec-btn strong{font-size:12px}.fatura-cari-sec-btn small,.fatura-cari-secildi{display:block;font-size:9px;color:var(--muted);margin-top:2px}.fatura-cari-modal{position:fixed;inset:0;background:rgba(21,25,23,.48);display:grid;place-items:center;padding:18px;z-index:9999}.fatura-cari-modal[hidden]{display:none}.fatura-cari-dialog{width:min(520px,100%);background:#fff;border-radius:20px;box-shadow:0 24px 70px rgba(0,0,0,.25);padding:18px;display:grid;gap:13px}.fatura-cari-head{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:start}.fatura-cari-head>div{display:grid;gap:4px}.fatura-cari-head strong{font-size:18px}.fatura-cari-head small{color:var(--muted);font-size:11px}.fatura-cari-close{border:0;background:#f1eee8;border-radius:10px;width:34px;height:34px;font-size:22px;cursor:pointer}.fatura-cari-search,.fatura-cari-select-label{display:grid;gap:6px;font-size:11px;font-weight:850;color:#554d42}.fatura-cari-search input,.fatura-cari-select-label select{width:100%;border:1px solid var(--border);border-radius:12px;background:#fff;padding:11px 12px}.fatura-cari-status{min-height:16px;margin:0;font-size:11px;color:var(--muted)}.fatura-cari-status.is-success{color:var(--success)}.fatura-cari-status.is-danger{color:var(--danger)}.fatura-cari-status.is-loading{color:#23598b}.fatura-cari-actions{display:flex;justify-content:flex-end;gap:9px;flex-wrap:wrap}';
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
