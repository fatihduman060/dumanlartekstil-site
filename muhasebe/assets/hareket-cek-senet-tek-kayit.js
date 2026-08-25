(function(){
  'use strict';
  if(!/\/hareketler\.php$/i.test(location.pathname)) return;

  function init(){
    var form=document.querySelector('.stack-form input[name="action"][value="save"]');
    form=form&&form.closest('form');
    if(!form||form.dataset.instrumentSingleSubmitReady==='1') return;
    form.dataset.instrumentSingleSubmitReady='1';

    form.addEventListener('submit',function(event){
      var kind=form.querySelector('select[name="instrument_kind"]');
      var value=kind?String(kind.value||''):'';
      if(value!=='cek'&&value!=='senet') return;

      event.preventDefault();
      event.stopImmediatePropagation();

      if(form.dataset.instrumentSubmitting==='1') return;
      form.dataset.instrumentSubmitting='1';

      var note=form.querySelector('[data-instrument-note]');
      var button=form.querySelector('button[type="submit"]');
      var oldText=button?button.textContent:'';
      if(button){button.disabled=true;button.textContent='Çek / senet kaydediliyor...';}
      if(note){
        note.hidden=false;
        note.className='hareket-instrument-note is-info';
        note.textContent='Tek kayıt olarak çek/senet hazırlanıyor...';
      }

      var body=new FormData(form);
      body.set('instrument_kind',value);
      var no=form.querySelector('input[name="instrument_no"]');
      if(no) body.set('instrument_no',String(no.value||'').trim());

      fetch('hareket-cek-senet-kaydet.php',{
        method:'POST',
        body:body,
        credentials:'same-origin',
        cache:'no-store',
        headers:{'Accept':'application/json'}
      }).then(function(response){
        return response.json().catch(function(){return {ok:false,error:'Sunucu cevabı okunamadı.'};}).then(function(data){
          if(!response.ok||!data||!data.ok) throw new Error((data&&data.error)||'Çek/senet kaydı oluşturulamadı.');
          return data;
        });
      }).then(function(data){
        if(note){
          note.hidden=false;
          note.className='hareket-instrument-note is-success';
          note.textContent=data.deduplicated?'Aynı çek zaten kaydedilmişti; ikinci kayıt oluşturulmadı.':'Çek/senet tek kayıt olarak kaydedildi.';
        }
        location.href=data.redirect||'hareketler.php';
      }).catch(function(error){
        form.dataset.instrumentSubmitting='0';
        if(button){button.disabled=false;button.textContent=oldText;}
        if(note){
          note.hidden=false;
          note.className='hareket-instrument-note is-error';
          note.textContent=error.message||'Çek/senet kaydı oluşturulamadı.';
        }else{
          alert(error.message||'Çek/senet kaydı oluşturulamadı.');
        }
      });
    },true);
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
