(function(){
  var root=document.querySelector('[data-pos-root]');
  if(!root) return;
  var wrap=root.querySelector('[data-pos-person-wrap]');
  var select=root.querySelector('[data-pos-person]');
  var status=root.querySelector('[data-pos-status]');
  if(!wrap||!select||wrap.querySelector('[data-pos-new-credit-person]')) return;

  var style=document.createElement('style');
  style.textContent=''
    +'.pos-credit-person-tools{display:flex;gap:8px;align-items:center;margin-top:7px;flex-wrap:wrap}'
    +'.pos-credit-person-tools button{min-height:34px;padding:7px 11px}'
    +'.pos-credit-person-new{display:none;grid-template-columns:minmax(150px,1fr) auto auto;gap:7px;align-items:center;margin-top:8px;padding:9px;border:1px solid #ded4c5;border-radius:12px;background:#fffaf2}'
    +'.pos-credit-person-new.is-open{display:grid}.pos-credit-person-new input{min-height:38px;width:100%}'
    +'@media(max-width:620px){.pos-credit-person-new{grid-template-columns:1fr 1fr}.pos-credit-person-new input{grid-column:1/-1}}';
  document.head.appendChild(style);

  var tools=document.createElement('div');
  tools.className='pos-credit-person-tools';
  tools.setAttribute('data-pos-new-credit-person','1');
  tools.innerHTML='<button type="button" class="btn btn-secondary" data-credit-person-open>+ Yeni isim</button>';

  var editor=document.createElement('div');
  editor.className='pos-credit-person-new';
  editor.innerHTML='<input type="text" maxlength="120" autocomplete="off" placeholder="Ad Soyad" data-credit-person-name><button type="button" class="btn btn-primary" data-credit-person-save>Kaydet ve seç</button><button type="button" class="btn btn-secondary" data-credit-person-cancel>Vazgeç</button>';

  wrap.appendChild(tools);
  wrap.appendChild(editor);

  var openButton=tools.querySelector('[data-credit-person-open]');
  var nameInput=editor.querySelector('[data-credit-person-name]');
  var saveButton=editor.querySelector('[data-credit-person-save]');
  var cancelButton=editor.querySelector('[data-credit-person-cancel]');

  function close(){
    editor.classList.remove('is-open');
    nameInput.value='';
  }
  function open(){
    editor.classList.add('is-open');
    setTimeout(function(){nameInput.focus();},50);
  }

  function save(){
    var name=String(nameInput.value||'').replace(/\s+/g,' ').trim();
    if(name.length<3){if(status)status.textContent='Yeni veresiye ismini yaz.';nameInput.focus();return;}
    saveButton.disabled=true;
    saveButton.textContent='Kaydediliyor…';
    if(status)status.textContent='Yeni isim ekleniyor…';
    var body=new FormData();
    body.set('csrf_token',root.dataset.csrf||'');
    body.set('full_name',name);
    fetch('barkod-veresiye-kisi.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(!data||!data.ok||!data.person) throw new Error((data&&data.error)||'İsim eklenemedi.');
        var id=String(data.person.id);
        var option=Array.prototype.slice.call(select.options).find(function(item){return String(item.value)===id;});
        if(!option){
          option=document.createElement('option');
          option.value=id;
          option.textContent=data.person.full_name;
          select.appendChild(option);
        }else{
          option.textContent=data.person.full_name;
        }
        select.value=id;
        select.dispatchEvent(new Event('change',{bubbles:true}));
        close();
        if(status)status.textContent=data.message||'Yeni isim eklendi ve seçildi.';
      })
      .catch(function(error){if(status)status.textContent=error&&error.message?error.message:'İsim eklenemedi.';})
      .finally(function(){saveButton.disabled=false;saveButton.textContent='Kaydet ve seç';});
  }

  openButton.addEventListener('click',open);
  cancelButton.addEventListener('click',close);
  saveButton.addEventListener('click',save);
  nameInput.addEventListener('keydown',function(event){if(event.key==='Enter'){event.preventDefault();save();}else if(event.key==='Escape'){event.preventDefault();close();}});
})();
