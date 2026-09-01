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

  function para(value){
    return Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' TL';
  }

  function yevmiyeEditorKur(){
    var table=document.querySelector('.salary-plan-table');
    if(!table || table.dataset.directEdit==='1') return false;
    var body=table.tBodies&&table.tBodies[0];
    if(!body) return false;

    table.dataset.directEdit='1';
    var form=table.closest('form');
    var periodInput=form&&form.querySelector('input[name="plan_period"]');
    var csrfInput=form&&form.querySelector('input[name="csrf_token"]');
    var period=periodInput?String(periodInput.value||''):'';
    var csrf=csrfInput?String(csrfInput.value||''):'';

    var headRow=table.tHead&&table.tHead.rows&&table.tHead.rows[0];
    if(headRow){
      var th=document.createElement('th');
      th.textContent='İşlem';
      headRow.appendChild(th);
    }

    Array.prototype.forEach.call(body.rows,function(row){
      var daily=row.querySelector('input[name^="daily_wage["]');
      if(!daily) return;
      var bank=row.querySelector('input[name^="bank_amount["]');
      var cash=row.querySelector('input[name^="cash_amount["]');
      var note=row.querySelector('input[name^="plan_note["]');
      [daily,bank,cash,note].forEach(function(input){
        if(!input) return;
        input.readOnly=false;
        input.disabled=false;
        input.style.pointerEvents='auto';
        input.style.cursor='text';
        input.setAttribute('autocomplete','off');
      });

      var match=String(daily.name||'').match(/\[(\d+)\]/);
      if(!match) return;
      var employeeId=match[1];

      var cell=document.createElement('td');
      var button=document.createElement('button');
      button.type='button';
      button.className='salary-plan-row-save';
      button.textContent='Kaydet';
      var status=document.createElement('small');
      status.className='salary-plan-row-status';
      cell.appendChild(button);
      cell.appendChild(status);
      row.appendChild(cell);

      button.addEventListener('click',function(){
        button.disabled=true;
        button.textContent='Kaydediliyor…';
        status.textContent='';

        var params=new URLSearchParams();
        params.set('csrf_token',csrf);
        params.set('employee_id',employeeId);
        params.set('period',period);
        params.set('daily_wage',daily?daily.value:'');
        params.set('bank_amount',bank?bank.value:'0');
        params.set('cash_amount',cash?cash.value:'0');
        params.set('note',note?note.value:'');

        fetch('maas-yevmiye-api.php',{
          method:'POST',
          headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},
          body:params.toString(),
          credentials:'same-origin'
        }).then(function(response){
          return response.text().then(function(text){
            var data;
            try{data=JSON.parse(text);}catch(e){throw new Error('Sunucu cevabı okunamadı.');}
            if(!response.ok || !data.ok) throw new Error(data.message||'Kayıt yapılamadı.');
            return data;
          });
        }).then(function(data){
          status.textContent='Kaydedildi';
          status.classList.add('ok');
          var totalCell=row.querySelector('.salary-plan-row-total');
          if(totalCell) totalCell.textContent=para(data.plan_total);
          window.setTimeout(function(){status.textContent='';status.classList.remove('ok');},2500);
        }).catch(function(error){
          status.textContent=error&&error.message?error.message:'Kayıt yapılamadı.';
          status.classList.remove('ok');
        }).then(function(){
          button.disabled=false;
          button.textContent='Kaydet';
        });
      });
    });

    if(!document.getElementById('salaryPlanDirectEditStyle')){
      var style=document.createElement('style');
      style.id='salaryPlanDirectEditStyle';
      style.textContent=''
        +'.salary-plan-table input{pointer-events:auto!important;user-select:text!important;-webkit-user-select:text!important;background:#fff!important;cursor:text!important}'
        +'.salary-plan-row-save{min-width:78px;min-height:38px;border:0;border-radius:10px;background:#16482e;color:#fff;font-weight:900;cursor:pointer}'
        +'.salary-plan-row-save:disabled{opacity:.6;cursor:wait}'
        +'.salary-plan-row-status{display:block;max-width:130px;margin-top:5px;color:#a33f35;font-size:10px;line-height:1.25}'
        +'.salary-plan-row-status.ok{color:#26744c;font-weight:900}'
        +'@media(max-width:700px){.salary-plan-table input{font-size:16px!important}.salary-plan-row-save{min-height:44px}}';
      document.head.appendChild(style);
    }

    return true;
  }

  function baslat(){
    kur();
    yevmiyeEditorKur();
    var tries=0;
    var timer=setInterval(function(){
      tries++;
      kur();
      yevmiyeEditorKur();
      if(tries>=12) clearInterval(timer);
    },250);
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',baslat); else baslat();
})();
