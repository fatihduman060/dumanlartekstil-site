(function(){
  'use strict';

  if(!/cekler\.php$/i.test(location.pathname)) return;

  function esc(value){
    return String(value==null?'':value).replace(/[&<>\"]/g,function(ch){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[ch];
    });
  }

  function sourceCariSelect(){
    return document.querySelector('#cek-form select[name="cari_id"]');
  }

  function checkId(form){
    var input=form.querySelector('input[name="id"]');
    return Number(input?input.value:0)||0;
  }

  function csrf(form){
    var input=form.querySelector('input[name="csrf_token"]');
    return input?String(input.value||''):'';
  }

  function buildCariSelect(form,row){
    var source=sourceCariSelect();
    if(!source) return null;

    var select=document.createElement('select');
    select.className='ciro-cari-select';
    select.setAttribute('aria-label','Ciro edilecek cari');
    select.innerHTML='<option value="">Ciro edilecek cariyi seç</option>';

    var sourceCariId='';
    var sourceLink=row?row.querySelector('td:first-child a[href*="cari-detay.php?id="]'):null;
    if(sourceLink){
      try{sourceCariId=new URL(sourceLink.getAttribute('href'),location.href).searchParams.get('id')||'';}catch(e){}
    }

    Array.prototype.forEach.call(source.options,function(option){
      if(!option.value) return;
      var clone=option.cloneNode(true);
      clone.selected=false;
      if(sourceCariId&&String(clone.value)===String(sourceCariId)){
        clone.disabled=true;
        clone.textContent=String(clone.textContent||'')+' — çeki aldığımız cari';
      }
      select.appendChild(clone);
    });

    select.hidden=true;
    var statusSelect=form.querySelector('select[name="status"]');
    if(statusSelect) statusSelect.insertAdjacentElement('afterend',select);
    return select;
  }

  function setNote(row,text,tone){
    if(!row) return;
    var cell=row.children[4];
    if(!cell) return;
    var note=cell.querySelector('.ciro-cari-note');
    if(!note){
      note=document.createElement('small');
      note.className='ciro-cari-note';
      cell.appendChild(note);
    }
    note.textContent=text||'';
    note.className='ciro-cari-note'+(tone?' is-'+tone:'');
    note.hidden=!text;
  }

  function loadInfo(form,cariSelect,row){
    var id=checkId(form);
    if(!id) return;
    fetch('cek-ciro.php?check_id='+encodeURIComponent(id)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}})
      .then(function(response){return response.json().catch(function(){return null;});})
      .then(function(data){
        if(!data||!data.ok) return;
        if(Number(data.endorsed_to_cari_id||0)>0){
          cariSelect.value=String(data.endorsed_to_cari_id);
          setNote(row,'Ciro: '+String(data.endorsed_cari_name||'')+(data.endorsed_at?' · '+String(data.endorsed_at).split('-').reverse().join('.'):'') ,'success');
        }
      })
      .catch(function(){});
  }

  function post(form,payload){
    var body=new URLSearchParams();
    Object.keys(payload).forEach(function(key){body.set(key,String(payload[key]));});
    body.set('csrf_token',csrf(form));
    return fetch('cek-ciro.php',{
      method:'POST',
      credentials:'same-origin',
      cache:'no-store',
      headers:{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
      body:body.toString()
    }).then(function(response){
      return response.json().catch(function(){return {ok:false,error:'Sunucu cevabı okunamadı.'};}).then(function(data){
        if(!response.ok||!data||!data.ok) throw new Error((data&&data.error)||'Ciro işlemi yapılamadı.');
        return data;
      });
    });
  }

  function initForm(form){
    if(form.dataset.ciroReady==='1') return;
    var row=form.closest('tr');
    if(!row) return;
    var firstCell=row.children[0];
    if(!firstCell||String(firstCell.textContent||'').indexOf('Alınan')===-1) return;

    var statusSelect=form.querySelector('select[name="status"]');
    var submit=form.querySelector('button[type="submit"]');
    if(!statusSelect||!submit) return;

    form.dataset.ciroReady='1';
    form.dataset.initialStatus=String(statusSelect.value||'');
    var cariSelect=buildCariSelect(form,row);
    if(!cariSelect) return;

    function sync(){
      var isCiro=String(statusSelect.value||'')==='ciro_edildi';
      cariSelect.hidden=!isCiro;
      cariSelect.required=isCiro;
      form.classList.toggle('is-ciro-mode',isCiro);
      if(isCiro&&String(form.dataset.ciroInfoLoaded||'')!=='1'){
        form.dataset.ciroInfoLoaded='1';
        loadInfo(form,cariSelect,row);
      }
    }

    statusSelect.addEventListener('change',sync);
    sync();

    form.addEventListener('submit',function(event){
      var nextStatus=String(statusSelect.value||'');
      var initialStatus=String(form.dataset.initialStatus||'');
      var id=checkId(form);

      if(nextStatus==='ciro_edildi'){
        event.preventDefault();
        var targetCariId=String(cariSelect.value||'');
        if(!targetCariId){
          window.alert('Ciro edeceğin cariyi seçmelisin.');
          cariSelect.focus();
          return;
        }
        var oldText=submit.textContent;
        submit.disabled=true;
        submit.textContent='Ciro ediliyor...';
        post(form,{action:'endorse',check_id:id,cari_id:targetCariId,endorsement_date:new Date().toISOString().slice(0,10)})
          .then(function(data){window.alert(data.message||'Çek ciro edildi.');location.reload();})
          .catch(function(error){window.alert(error.message||'Ciro işlemi yapılamadı.');submit.disabled=false;submit.textContent=oldText;});
        return;
      }

      if(initialStatus==='ciro_edildi'&&nextStatus!=='ciro_edildi'){
        event.preventDefault();
        if(!window.confirm('Ciro geri alınacak ve ciro edilen carideki ödeme hareketi iptal edilecek. Devam edilsin mi?')) return;
        var oldButtonText=submit.textContent;
        submit.disabled=true;
        submit.textContent='Ciro geri alınıyor...';
        post(form,{action:'reverse',check_id:id,new_status:nextStatus})
          .then(function(data){window.alert(data.message||'Ciro geri alındı.');location.reload();})
          .catch(function(error){window.alert(error.message||'Ciro geri alınamadı.');submit.disabled=false;submit.textContent=oldButtonText;});
      }
    });
  }

  function init(){
    document.querySelectorAll('.check-table .status-form').forEach(initForm);
    if(!document.getElementById('cekCiroStyle')){
      var style=document.createElement('style');
      style.id='cekCiroStyle';
      style.textContent='.status-form.is-ciro-mode{grid-template-columns:minmax(0,1fr) auto}.status-form .ciro-cari-select{grid-column:1/-1;min-height:34px;border-radius:10px!important;border:1px solid #d6b15d!important;background:#fffaf0!important;color:#173f29!important;font-weight:850!important;padding:6px 9px!important}.ciro-cari-note{display:block!important;margin-top:6px!important;padding:6px 8px;border-radius:9px;background:#fff4d6;color:#7d5b13!important;font-weight:900!important}.ciro-cari-note.is-success{background:#e9f8ef;color:#167243!important}@media(max-width:760px){.status-form .ciro-cari-select{font-size:13px}}';
      document.head.appendChild(style);
    }
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
