(function(){
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  var form=document.getElementById('invoiceForm');
  if(!form||form.getAttribute('data-fatura-yeni-cari-ready')==='1') return;
  var cariSelect=form.querySelector('select[name="cari_id"]');
  if(!cariSelect) return;
  form.setAttribute('data-fatura-yeni-cari-ready','1');

  function norm(value){
    return String(value||'')
      .toLocaleUpperCase('tr-TR')
      .replace(/İ/g,'I').replace(/Ş/g,'S').replace(/Ğ/g,'G').replace(/Ü/g,'U').replace(/Ö/g,'O').replace(/Ç/g,'C')
      .replace(/[^A-Z0-9]+/g,' ')
      .replace(/\s+/g,' ')
      .trim();
  }

  var cariLabel=cariSelect.closest('label');
  var tools=document.createElement('div');
  tools.className='fatura-yeni-cari-tools';
  tools.innerHTML=''
    +'<div class="fatura-yeni-cari-buttons">'
    +'<button type="button" class="btn btn-secondary" data-fatura-yeni-cari>+ Yeni cari oluştur</button>'
    +'<button type="button" class="btn btn-secondary" data-fatura-muhtelif-sec>Muhtelif seç</button>'
    +'</div>'
    +'<p class="fatura-yeni-cari-inline-status" data-fatura-yeni-cari-status></p>';
  if(cariLabel) cariLabel.insertAdjacentElement('afterend',tools);
  else cariSelect.insertAdjacentElement('afterend',tools);

  var inlineStatus=tools.querySelector('[data-fatura-yeni-cari-status]');
  function setInlineStatus(text,tone){
    inlineStatus.textContent=text||'';
    inlineStatus.className='fatura-yeni-cari-inline-status'+(tone?' is-'+tone:'');
  }

  var overlay=document.createElement('div');
  overlay.className='fatura-yeni-cari-overlay';
  overlay.hidden=true;
  overlay.innerHTML=''
    +'<div class="fatura-yeni-cari-dialog" role="dialog" aria-modal="true" aria-labelledby="faturaYeniCariBaslik">'
    +'<button type="button" class="fatura-yeni-cari-close" data-yeni-cari-close aria-label="Kapat">×</button>'
    +'<div class="fatura-yeni-cari-head"><strong id="faturaYeniCariBaslik">Yeni cari oluştur</strong><small>Fatura ekranından çıkmadan cariyi açar ve İlgili cari alanında otomatik seçer.</small></div>'
    +'<div class="fatura-yeni-cari-grid">'
    +'<label class="wide">Ad / Ünvan<input name="quick_name" autocomplete="organization"></label>'
    +'<label>Vergi / T.C. No<input name="quick_tax_no" inputmode="numeric"></label>'
    +'<label>Vergi dairesi<input name="quick_tax_office"></label>'
    +'<label>Şehir<input name="quick_city"></label>'
    +'<label>Telefon<input name="quick_phone" inputmode="tel"></label>'
    +'<label class="wide">E-posta<input type="email" name="quick_email" autocomplete="email"></label>'
    +'<label class="wide">Adres<textarea name="quick_address" rows="3"></textarea></label>'
    +'</div>'
    +'<p class="fatura-yeni-cari-modal-status" data-yeni-cari-modal-status></p>'
    +'<div class="fatura-yeni-cari-actions"><button type="button" class="btn btn-secondary" data-yeni-cari-cancel>Vazgeç</button><button type="button" class="btn btn-primary" data-yeni-cari-save>Yeni cariyi oluştur ve seç</button></div>'
    +'</div>';
  document.body.appendChild(overlay);

  var modalStatus=overlay.querySelector('[data-yeni-cari-modal-status]');
  var saveButton=overlay.querySelector('[data-yeni-cari-save]');
  var lastFocus=null;

  function setModalStatus(text,tone){
    modalStatus.textContent=text||'';
    modalStatus.className='fatura-yeni-cari-modal-status'+(tone?' is-'+tone:'');
  }

  function field(name){return overlay.querySelector('[name="quick_'+name+'"]');}

  function closeModal(){
    overlay.hidden=true;
    setModalStatus('');
    if(lastFocus&&typeof lastFocus.focus==='function') lastFocus.focus();
  }

  function openModal(trigger){
    lastFocus=trigger||document.activeElement;
    ['name','tax_no','tax_office','city','phone','email','address'].forEach(function(key){
      var input=field(key);
      if(input) input.value='';
    });

    var direction=form.querySelector('[name="direction"]');
    var issuer=form.querySelector('[name="issuer_name"]');
    if(direction&&direction.value==='gelen'&&issuer&&issuer.value.trim()){
      field('name').value=issuer.value.trim();
      setModalStatus('Gönderen firma adı otomatik getirildi. Bilgileri kontrol edip kaydet.','neutral');
    }else{
      setModalStatus('En az Ad / Ünvan alanını doldurman yeterli.','neutral');
    }

    overlay.hidden=false;
    window.setTimeout(function(){field('name').focus();field('name').select();},40);
  }

  function selectCari(cari){
    var option=Array.from(cariSelect.options).find(function(item){return String(item.value)===String(cari.id);});
    if(!option){
      option=document.createElement('option');
      option.value=String(cari.id);
      cariSelect.appendChild(option);
    }
    option.textContent=String(cari.name||'Cari')+' — '+String(cari.cari_type||'Firma')+(cari.tax_no?' · '+cari.tax_no:'');
    cariSelect.value=String(cari.id);
    cariSelect.dispatchEvent(new Event('change',{bubbles:true}));
  }

  function saveCari(){
    var name=field('name').value.trim();
    if(!name){setModalStatus('Ad / Ünvan alanını doldurmalısın.','danger');field('name').focus();return;}

    var csrf=form.querySelector('input[name="csrf_token"]');
    if(!csrf||!csrf.value){setModalStatus('Güvenlik anahtarı bulunamadı. Sayfayı yenileyip tekrar dene.','danger');return;}

    var oldText=saveButton.textContent;
    saveButton.disabled=true;
    saveButton.textContent='Cari oluşturuluyor...';
    setModalStatus('Mükerrer cari kontrol ediliyor ve kayıt hazırlanıyor...','loading');

    var body=new FormData();
    body.set('csrf_token',csrf.value);
    ['name','tax_no','tax_office','city','phone','email','address'].forEach(function(key){
      body.set(key,field(key).value.trim());
    });

    fetch('fatura-yeni-cari.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(response){
        return response.json().then(function(data){
          if(!response.ok||!data.ok) throw new Error(data.error||'Cari oluşturulamadı.');
          return data;
        });
      })
      .then(function(data){
        if(data.csrf_token) csrf.value=String(data.csrf_token);
        selectCari(data.cari);
        var issuer=form.querySelector('[name="issuer_name"]');
        if(issuer&&!issuer.value.trim()) issuer.value=String(data.cari.name||name);
        setInlineStatus(data.message||'Cari oluşturuldu ve seçildi.','success');
        closeModal();
      })
      .catch(function(error){setModalStatus(error.message||'Cari oluşturulamadı.','danger');})
      .finally(function(){saveButton.disabled=false;saveButton.textContent=oldText;});
  }

  tools.querySelector('[data-fatura-yeni-cari]').addEventListener('click',function(){openModal(this);});
  tools.querySelector('[data-fatura-muhtelif-sec]').addEventListener('click',function(){
    var option=Array.from(cariSelect.options).find(function(item){return norm(item.textContent).indexOf('MUHTELIF FATURA GIRISI')!==-1;});
    if(!option){setInlineStatus('MUHTELİF FATURA GİRİŞİ carisi listede bulunamadı.','danger');return;}
    cariSelect.value=option.value;
    cariSelect.dispatchEvent(new Event('change',{bubbles:true}));
    setInlineStatus('Muhtelif cari seçildi. Gönderen firma bilgisi faturada ayrı olarak korunacak.','success');
  });
  saveButton.addEventListener('click',saveCari);
  overlay.querySelector('[data-yeni-cari-close]').addEventListener('click',closeModal);
  overlay.querySelector('[data-yeni-cari-cancel]').addEventListener('click',closeModal);
  overlay.addEventListener('click',function(event){if(event.target===overlay) closeModal();});
  document.addEventListener('keydown',function(event){
    if(event.key==='Escape'&&!overlay.hidden){
      event.preventDefault();
      event.stopImmediatePropagation();
      closeModal();
    }
  },true);

  var style=document.createElement('style');
  style.textContent=''
    +'.fatura-yeni-cari-tools{display:grid;gap:6px;margin-top:-4px;margin-bottom:2px}.fatura-yeni-cari-buttons{display:flex;gap:8px;flex-wrap:wrap}.fatura-yeni-cari-buttons .btn{padding:8px 11px;font-size:11px}.fatura-yeni-cari-inline-status{min-height:14px;margin:0;font-size:10px;color:var(--muted)}.fatura-yeni-cari-inline-status.is-success{color:var(--success)}.fatura-yeni-cari-inline-status.is-danger{color:var(--danger)}'
    +'.fatura-yeni-cari-overlay[hidden]{display:none!important}.fatura-yeni-cari-overlay{position:fixed;inset:0;z-index:12000;display:grid;place-items:center;padding:18px;background:rgba(14,24,37,.68);backdrop-filter:blur(3px)}.fatura-yeni-cari-dialog{position:relative;width:min(680px,96vw);max-height:92vh;overflow:auto;border-radius:20px;background:#fff;box-shadow:0 30px 90px rgba(0,0,0,.34);padding:20px;display:grid;gap:14px}.fatura-yeni-cari-close{position:absolute;right:12px;top:10px;width:34px;height:34px;border:0;border-radius:50%;background:#eee6d8;color:#503f25;font-size:23px;cursor:pointer}.fatura-yeni-cari-head{display:grid;gap:4px;padding-right:42px}.fatura-yeni-cari-head strong{font-size:18px}.fatura-yeni-cari-head small{font-size:11px;color:var(--muted)}.fatura-yeni-cari-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.fatura-yeni-cari-grid label{display:grid;gap:6px;font-size:11px;font-weight:850;color:#554d42}.fatura-yeni-cari-grid .wide{grid-column:1/-1}.fatura-yeni-cari-grid input,.fatura-yeni-cari-grid textarea{width:100%;border:1px solid var(--border);border-radius:12px;background:#fff;padding:10px 11px}.fatura-yeni-cari-modal-status{min-height:16px;margin:0;font-size:11px;color:var(--muted)}.fatura-yeni-cari-modal-status.is-danger{color:var(--danger)}.fatura-yeni-cari-modal-status.is-loading{color:#23598b}.fatura-yeni-cari-actions{display:flex;justify-content:flex-end;gap:9px;flex-wrap:wrap}'
    +'@media(max-width:650px){.fatura-yeni-cari-grid{grid-template-columns:1fr}.fatura-yeni-cari-grid .wide{grid-column:auto}.fatura-yeni-cari-overlay{padding:8px}.fatura-yeni-cari-dialog{width:100%;max-height:96vh;padding:15px;border-radius:15px}.fatura-yeni-cari-actions .btn{flex:1 1 auto}}';
  document.head.appendChild(style);
})();
