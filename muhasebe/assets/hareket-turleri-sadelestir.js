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
      ['iade','İade'],
      ['gider','Diğer Gider'],
      ['ciro_primi','Ciro Primi']
    ];
    if(editing && current==='iade_borc_azalt') items.splice(5,0,['iade_borc_azalt','İade (borcu azaltır)']);

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

    var form = select.closest('form');
    var category = form.querySelector('select[name="category_id"]');
    function isReturnOption(option) {
      return option && String(option.textContent || '').trim().toLocaleLowerCase('tr-TR') === 'iade';
    }
    function syncReturnFields() {
      if (!['iade', 'iade_borc_azalt'].includes(select.value)) return;
      var account = form.querySelector('[name="account_id"]');
      if (account) account.value = '';
      var due = form.querySelector('[name="due_date"]');
      if (due) due.value = '';
      var method = form.querySelector('[name="payment_method"]');
      if (method) method.value = '';
    }
    if (category) {
      // Capture before the sales-detail listener can replace the chosen category.
      category.addEventListener('change', function () {
        if (isReturnOption(category.options[category.selectedIndex])) {
          select.value = 'iade';
          select.dispatchEvent(new Event('change', {bubbles:true}));
        }
      }, true);
      select.addEventListener('change', function () {
        if (select.value === 'iade') {
          Array.prototype.some.call(category.options, function (option) {
            if (!isReturnOption(option)) return false;
            category.value = option.value;
            return true;
          });
        } else if (isReturnOption(category.options[category.selectedIndex])) {
          category.value = '';
        }
        syncReturnFields();
      }, true);
    }
    syncReturnFields();
    // Kasa/banka görünürlük mantığının yeni seçime göre tekrar hesaplanmasını sağla.
    if (!editing) { try{select.dispatchEvent(new Event('change',{bubbles:true}));}catch(e){} }
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init);
  else init();
})();
