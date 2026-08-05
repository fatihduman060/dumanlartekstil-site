(function(){
  'use strict';

  function postGuard(form, action){
    var body=new FormData(form);
    body.set('guard_action', action);
    return fetch('mukerrer-koruma-api.php',{
      method:'POST',
      body:body,
      credentials:'same-origin',
      cache:'no-store',
      headers:{'Accept':'application/json'}
    }).then(function(response){
      return response.json().catch(function(){return {ok:false,error:'Sunucu cevabı okunamadı.'};})
        .then(function(data){
          if(!response.ok||!data||!data.ok) throw new Error((data&&data.error)||'Mükerrer kontrolü yapılamadı.');
          return data;
        });
    });
  }

  function nativeSubmit(form){
    HTMLFormElement.prototype.submit.call(form);
  }

  function guardCari(){
    if(!/cariler\.php$/i.test(location.pathname)) return;
    var form=document.getElementById('cariForm');
    if(!form||form.dataset.duplicateGuardReady==='1') return;
    form.dataset.duplicateGuardReady='1';

    form.addEventListener('submit',function(event){
      if(form.dataset.duplicateGuardPassed==='1') return;
      event.preventDefault();

      var button=form.querySelector('button[type="submit"]');
      var oldText=button?button.textContent:'';
      if(button){button.disabled=true;button.textContent='Mükerrer kontrol ediliyor...';}

      postGuard(form,'cari').then(function(data){
        var duplicate=data.duplicate;
        if(duplicate){
          var match=duplicate.match==='vergi_no'?'aynı vergi numarasıyla':'aynı ünvanla';
          window.alert(
            'Bu cari '+match+' zaten kayıtlı.\n\n'
            +'#'+duplicate.id+' · '+duplicate.name
            +(duplicate.city?'\nŞehir: '+duplicate.city:'')
            +'\n\nYeni kart oluşturulmadı. Mevcut kayıt açılacak.'
          );
          location.href='cariler.php?edit='+encodeURIComponent(duplicate.id);
          return;
        }
        form.dataset.duplicateGuardPassed='1';
        nativeSubmit(form);
      }).catch(function(error){
        window.alert(error.message||'Mükerrer kontrolü yapılamadı.');
        if(button){button.disabled=false;button.textContent=oldText;}
      });
    });
  }

  function movementForm(){
    var action=document.querySelector('form input[name="action"][value="save"]');
    return action?action.closest('form'):null;
  }

  function guardMovement(){
    if(!/hareketler\.php$/i.test(location.pathname)) return;
    var form=movementForm();
    if(!form||form.dataset.duplicateGuardReady==='1') return;
    form.dataset.duplicateGuardReady='1';

    form.addEventListener('submit',function(event){
      if(form.dataset.duplicateGuardPassed==='1') return;
      event.preventDefault();

      var button=form.querySelector('button[type="submit"]');
      var oldText=button?button.textContent:'';
      if(button){button.disabled=true;button.textContent='Mükerrer kontrol ediliyor...';}

      postGuard(form,'movement').then(function(data){
        var duplicate=data.duplicate;
        if(duplicate){
          window.alert(
            'Aynı hareket daha önce kaydedilmiş görünüyor.\n\n'
            +'Hareket #'+duplicate.id+'\n'
            +duplicate.movement_date+' · '+Number(duplicate.amount||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' '+duplicate.currency
            +(duplicate.description?'\n'+duplicate.description:'')
            +'\n\nİkinci kayıt oluşturulmadı.'
          );
          location.href='hareketler.php?q='+encodeURIComponent(duplicate.id);
          return;
        }
        form.dataset.duplicateGuardPassed='1';
        nativeSubmit(form);
      }).catch(function(error){
        window.alert(error.message||'Mükerrer kontrolü yapılamadı.');
        if(button){button.disabled=false;button.textContent=oldText;}
      });
    });
  }

  function init(){
    guardCari();
    guardMovement();
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init);
  else init();
})();