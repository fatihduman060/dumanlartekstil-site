(function(){
  'use strict';

  if(!/maaslar\.php/i.test(location.pathname)) return;

  function personelKartlari(list){
    return Array.prototype.filter.call(list.children,function(node){
      return node && node.nodeType===1 && node.classList.contains('salary-person');
    });
  }

  function kartKimligi(card,index){
    var editLink=card.querySelector('a[href*="edit_employee="]');
    if(editLink){
      try{
        var id=new URL(editLink.getAttribute('href'),location.href).searchParams.get('edit_employee');
        if(id) return 'employee-'+id;
      }catch(e){}
    }
    return 'employee-index-'+index;
  }

  function kartEtiketi(card){
    var name=card.querySelector('strong');
    var detail=card.querySelector('small');
    var label=name?String(name.textContent||'').replace(/\s+/g,' ').trim():'';
    var detailText=detail?String(detail.textContent||'').replace(/\s+/g,' ').trim():'';
    if(detailText && detailText!=='-') label+=' — '+detailText;
    return label || 'Personel';
  }

  function queryEditEmployee(){
    try{return new URL(location.href).searchParams.get('edit_employee')||'';}
    catch(e){return '';}
  }

  function kur(){
    var list=document.querySelector('.salary-person-list');
    if(!list || list.dataset.compactPicker==='1') return false;

    var cards=personelKartlari(list);
    if(!cards.length) return false;

    list.dataset.compactPicker='1';

    var picker=document.createElement('div');
    picker.className='salary-person-picker';
    picker.innerHTML='<label for="salaryPersonPickerSelect">Personel seç</label><select id="salaryPersonPickerSelect"><option value="">İşlem yapılacak personeli seç</option></select><small id="salaryPersonPickerStatus">Listeden bir personel seçtiğinde yalnızca o kişinin kartı açılır.</small>';

    list.parentNode.insertBefore(picker,list);

    var select=picker.querySelector('select');
    var status=picker.querySelector('#salaryPersonPickerStatus');
    var editEmployee=queryEditEmployee();
    var initialValue='';

    cards.forEach(function(card,index){
      var value=kartKimligi(card,index);
      var label=kartEtiketi(card);
      card.dataset.personPickerValue=value;
      card.hidden=true;

      var option=document.createElement('option');
      option.value=value;
      option.textContent=label;
      select.appendChild(option);

      if(editEmployee && value==='employee-'+editEmployee) initialValue=value;
    });

    function goster(value){
      var selectedCard=null;
      cards.forEach(function(card){
        var match=value && card.dataset.personPickerValue===value;
        card.hidden=!match;
        if(match) selectedCard=card;
      });

      if(selectedCard){
        var name=selectedCard.querySelector('strong');
        status.textContent=(name?name.textContent.trim():'Seçilen personel')+' işlemleri gösteriliyor.';
        picker.classList.add('has-selection');
      }else{
        status.textContent='Listeden bir personel seçtiğinde yalnızca o kişinin kartı açılır.';
        picker.classList.remove('has-selection');
      }
    }

    select.addEventListener('change',function(){
      goster(String(select.value||''));
    });

    if(initialValue){
      select.value=initialValue;
      goster(initialValue);
    }else{
      goster('');
    }

    if(!document.getElementById('salaryPersonPickerStyle')){
      var style=document.createElement('style');
      style.id='salaryPersonPickerStyle';
      style.textContent=''
        +'.salary-person-picker{display:grid;gap:7px;margin:10px 0 12px;padding:12px;border:1px solid #d9cfbf;border-radius:15px;background:#fffaf2}'
        +'.salary-person-picker label{font-size:11px;font-weight:950;color:#16482e;text-transform:uppercase;letter-spacing:.05em}'
        +'.salary-person-picker select{width:100%;min-height:44px;border:1px solid #cfc4b4;border-radius:12px;padding:9px 12px;background:#fff;color:#183b29;font-size:13px;font-weight:850}'
        +'.salary-person-picker small{color:#776b5c;font-size:10px;line-height:1.4}'
        +'.salary-person-picker.has-selection{border-color:#9fc3aa;background:#f2f8f3}'
        +'.salary-person-list{display:block!important}'
        +'.salary-person-list>.salary-person[hidden]{display:none!important}'
        +'.salary-person-list>.salary-person:not([hidden]){display:grid!important;margin:0}'
        +'@media(max-width:700px){.salary-person-picker select{font-size:16px}}';
      document.head.appendChild(style);
    }

    return true;
  }

  function baslat(){
    if(kur()) return;
    var tries=0;
    var timer=setInterval(function(){
      tries++;
      if(kur() || tries>=12) clearInterval(timer);
    },250);
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',baslat); else baslat();
})();
