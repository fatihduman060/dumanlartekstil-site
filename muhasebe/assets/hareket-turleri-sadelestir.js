(function(){
  'use strict';

  function init(){
    if(!/hareketler\.php$/i.test(location.pathname)) return;

    var select=document.querySelector('select[name="movement_type"][data-cash-type]');
    if(!select||select.dataset.fiveTypesReady==='1') return;
    select.dataset.fiveTypesReady='1';

    var current=String(select.value||'');
    var idInput=select.closest('form')?select.closest('form').querySelector('input[name="id"]'):null;
    var editing=Number(idInput?idInput.value:0)>0;

    var items=[
      ['alacak','Satış Faturası'],
      ['verecek','Alış Faturası'],
      ['tahsilat','Tahsilat'],
      ['odeme','Ödeme'],
      ['gider','İade']
    ];

    select.innerHTML='';
    items.forEach(function(item){
      var option=document.createElement('option');
      option.value=item[0];
      option.textContent=item[1];
      select.appendChild(option);
    });

    var allowed=items.some(function(item){return item[0]===current;});
    if(allowed){
      select.value=current;
    }else if(editing&&current){
      // Eski kayıtlarda artık yeni girişte gösterilmeyen bir hareket tipi varsa
      // kaydı yanlış tipe çevirmemek için değer korunur; seçenek listesinde gösterilmez.
      var legacy=document.createElement('option');
      legacy.value=current;
      legacy.textContent=current==='gelir'?'Eski kayıt':(current==='ozel_alacak'?'Eski özel alacak':'Eski kayıt');
      legacy.hidden=true;
      legacy.selected=true;
      select.appendChild(legacy);
    }else{
      select.value='alacak';
    }

    // Kasa/banka görünürlük mantığının yeni seçime göre tekrar hesaplanmasını sağla.
    try{select.dispatchEvent(new Event('change',{bubbles:true}));}catch(e){}
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init);
  else init();
})();
