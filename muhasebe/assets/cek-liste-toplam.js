(function(){
  function parseMoney(text){
    text = String(text || '').replace(/\s+/g, ' ');
    var m = text.match(/-?[0-9.]+,[0-9]{2}/);
    if (!m) m = text.match(/-?[0-9]+(?:\.[0-9]{3})*/);
    if (!m) return 0;
    var raw = m[0].replace(/\./g, '').replace(',', '.');
    var n = parseFloat(raw);
    return Number.isFinite(n) ? n : 0;
  }
  function fmt(n){
    try { return new Intl.NumberFormat('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}).format(n) + ' TL'; }
    catch(e){ return n.toFixed(2).replace('.', ',') + ' TL'; }
  }
  function titleText(){
    var active = document.querySelector('.check-direction-tabs a.active');
    var t = active ? (active.textContent || '') : (document.body.textContent || '');
    t = t.toLocaleLowerCase('tr-TR');
    return t.indexOf('verilen') !== -1 ? 'Verilen çek toplamı' : 'Alınan çek toplamı';
  }
  function apply(){
    if (!/cekler\.php/i.test(location.pathname)) return;
    var table = document.querySelector('.check-table');
    if (!table) return;
    var rows = Array.from(table.querySelectorAll('tbody tr')).filter(function(row){
      return !row.classList.contains('empty') && row.children.length >= 4 && (row.textContent || '').indexOf('Çek kaydı yok') === -1;
    });
    var total = 0;
    var count = 0;
    rows.forEach(function(row){
      var amount = parseMoney(row.children[3] ? row.children[3].textContent : '');
      if (amount > 0) { total += amount; count++; }
    });
    var foot = table.querySelector('tfoot.cek-liste-toplam-foot');
    if (!foot) {
      foot = document.createElement('tfoot');
      foot.className = 'cek-liste-toplam-foot';
      table.appendChild(foot);
    }
    foot.innerHTML = '<tr class="cek-liste-toplam-row"><td colspan="3"><strong>'+titleText()+'</strong><small>Filtrelenen / ekranda görünen '+count+' çek</small></td><td><strong>'+fmt(total)+'</strong></td><td colspan="3"></td></tr>';
  }
  function style(){
    if (document.getElementById('cekListeToplamStyle')) return;
    var s = document.createElement('style');
    s.id = 'cekListeToplamStyle';
    s.textContent = '.cek-liste-toplam-foot td{position:sticky;bottom:0;background:#102818!important;color:#fff!important;border-top:2px solid #c49a4f!important;font-size:14px!important}.cek-liste-toplam-foot td strong{color:#fff!important;font-size:16px!important}.cek-liste-toplam-foot td small{display:block!important;color:#f4dfae!important;margin-top:4px!important}.cek-liste-toplam-row td:nth-child(2){text-align:left!important}';
    document.head.appendChild(s);
  }
  function init(){ style(); apply(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

(function(){
  function checkIdFromLink(link){
    if(!link) return '';
    try{return new URL(link.getAttribute('href'), location.href).searchParams.get('edit') || '';}
    catch(e){return '';}
  }

  function imageUrl(id){
    return 'cek-gorsel-ac.php?id=' + encodeURIComponent(id);
  }

  function addImageAction(row, recordLink, id){
    var actions = recordLink.parentElement;
    if(!actions || actions.querySelector('.cari-cek-gorsel-action')) return;
    var link = document.createElement('a');
    link.className = 'cari-cek-gorsel-action';
    link.href = imageUrl(id);
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = 'Çek görseli';
    link.title = 'Çekin yüklenen görüntüsünü aç';
    actions.insertBefore(link, recordLink);
  }

  function makeCellClickable(cell, id, fallbackLabel){
    if(!cell || cell.querySelector('.cari-cek-gorsel-ac')) return;
    var small = cell.querySelector('small');
    var clone = cell.cloneNode(true);
    Array.prototype.forEach.call(clone.querySelectorAll('small'), function(node){ node.remove(); });
    var label = (clone.textContent || '').replace(/\s+/g, ' ').trim() || fallbackLabel;

    Array.prototype.slice.call(cell.childNodes).forEach(function(node){
      if(node.nodeType === 3) node.remove();
    });

    var link = document.createElement('a');
    link.className = 'cari-cek-gorsel-ac';
    link.href = imageUrl(id);
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = label;
    link.title = 'Çekin yüklenen görüntüsünü aç';
    cell.insertBefore(link, small || cell.firstChild);
  }

  function enhanceMovementRows(){
    document.querySelectorAll('#hareketler tbody tr').forEach(function(row){
      var recordLink = row.querySelector('.row-actions a[href*="cekler.php"][href*="edit="]');
      var id = checkIdFromLink(recordLink);
      if(!id) return;
      addImageAction(row, recordLink, id);
      makeCellClickable(row.children[4], id, 'Çek görselini aç');

      var documentCell = row.children[5];
      if(documentCell && documentCell.textContent.trim() === '-'){
        documentCell.innerHTML = '<a class="cari-cek-gorsel-belge" href="' + imageUrl(id) + '" target="_blank" rel="noopener">Çek görseli</a>';
      }
    });
  }

  function enhanceCheckHistory(){
    document.querySelectorAll('#cekler tbody tr').forEach(function(row){
      var recordLink = row.querySelector('.row-actions a[href*="cekler.php"][href*="edit="]');
      var id = checkIdFromLink(recordLink);
      if(!id) return;
      addImageAction(row, recordLink, id);
      makeCellClickable(row.children[3], id, 'Çek görselini aç');
    });
  }

  function styles(){
    if(document.getElementById('cariCekGorselStyle')) return;
    var style = document.createElement('style');
    style.id = 'cariCekGorselStyle';
    style.textContent = '.cari-cek-gorsel-ac,.cari-cek-gorsel-belge,.cari-cek-gorsel-action{color:#16482e;font-weight:900;text-decoration:underline;text-underline-offset:2px;cursor:pointer}.cari-cek-gorsel-ac:hover,.cari-cek-gorsel-belge:hover,.cari-cek-gorsel-action:hover{color:#9a6b16}';
    document.head.appendChild(style);
  }

  function init(){
    if(!/cari-detay\.php/i.test(location.pathname)) return;
    styles();
    enhanceMovementRows();
    enhanceCheckHistory();
  }

  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

(function(){
  function addCompensationEntry(){
    if(!/maaslar\.php/i.test(location.pathname)) return;
    if(document.getElementById('salaryCompensationEntry')) return;
    var grid=document.querySelector('.salary-grid');
    var summary=grid&&grid.querySelector('.salary-summary');
    if(!grid||!summary) return;

    var card=document.createElement('section');
    card.id='salaryCompensationEntry';
    card.className='salary-card salary-compensation-entry';
    card.innerHTML='<div><span>PERSONEL ÇIKIŞ ÖDEMELERİ</span><h3>Tazminat Ödemesi</h3><p>Kıdem, ihbar, kullanılmayan izin ücreti ve diğer tazminatları maaştan ayrı kaydet.</p></div><a href="maas-tazminat.php">Tazminat ödemesi aç</a>';
    summary.insertAdjacentElement('afterend',card);

    var heroTools=grid.querySelector('.salary-hero-tools');
    if(heroTools&&!heroTools.querySelector('.salary-compensation-shortcut')){
      var shortcut=document.createElement('a');
      shortcut.className='salary-excel-link salary-compensation-shortcut';
      shortcut.href='maas-tazminat.php';
      shortcut.textContent='Tazminat Ödemesi';
      heroTools.appendChild(shortcut);
    }

    if(!document.getElementById('salaryCompensationEntryStyle')){
      var style=document.createElement('style');
      style.id='salaryCompensationEntryStyle';
      style.textContent='.salary-compensation-entry{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:17px 19px;border-color:#d8b07a!important;background:linear-gradient(135deg,#fff8ed,#fff)!important}.salary-compensation-entry span{font-size:10px;font-weight:950;letter-spacing:.07em;color:#9a5f24}.salary-compensation-entry h3{margin:4px 0;color:#4d2c19}.salary-compensation-entry p{margin:0;color:#776b5c;font-size:12px}.salary-compensation-entry>a{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:9px 14px;border-radius:999px;background:#6f3e20;color:#fff;text-decoration:none;font-weight:950;white-space:nowrap}.salary-compensation-shortcut{background:#fff3df!important;color:#6f3e20!important}@media(max-width:700px){.salary-compensation-entry{align-items:stretch;flex-direction:column}.salary-compensation-entry>a{width:100%}}';
      document.head.appendChild(style);
    }
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',addCompensationEntry); else addCompensationEntry();
})();

(function(){
  'use strict';

  function addManualEmployeeField(){
    if(!/maas-tazminat\.php/i.test(location.pathname)) return;
    var action=document.querySelector('form.comp-form input[name="action"][value="save"]');
    var form=action&&action.closest('form');
    if(!form||form.dataset.manualEmployeeReady==='1') return;
    form.dataset.manualEmployeeReady='1';

    var select=form.querySelector('select[name="employee_id"]');
    if(!select) return;
    select.required=false;
    var selectLabel=select.closest('label');
    if(selectLabel){
      Array.prototype.slice.call(selectLabel.childNodes).forEach(function(node){
        if(node.nodeType===3&&node.textContent.trim()) node.textContent='Listeden personel seç (isteğe bağlı)';
      });
    }

    var manualLabel=document.createElement('label');
    manualLabel.className='comp-manual-employee';
    manualLabel.innerHTML='Personel adı — manuel giriş<input type="text" name="manual_employee_name" autocomplete="off" placeholder="Çıkış yapan personelin adını yaz"><small>Listede olmayan eski personel için kullan. İsim yazarsan listedeki seçim yerine bu kişi kullanılır.</small>';
    if(selectLabel) selectLabel.insertAdjacentElement('afterend',manualLabel);
    else form.insertBefore(manualLabel,form.firstChild);

    var status=document.createElement('p');
    status.className='comp-manual-status';
    status.hidden=true;
    manualLabel.insertAdjacentElement('afterend',status);

    function showStatus(text,tone){
      status.textContent=text||'';
      status.className='comp-manual-status'+(tone?' is-'+tone:'');
      status.hidden=!text;
    }

    form.addEventListener('submit',function(event){
      var manual=form.querySelector('input[name="manual_employee_name"]');
      var manualName=manual?String(manual.value||'').trim():'';
      var selected=String(select.value||'').trim();

      if(!manualName&&selected) return;
      event.preventDefault();

      if(!manualName&&!selected){
        showStatus('Listeden personel seç veya personel adını elle yaz.','error');
        if(manual) manual.focus();
        return;
      }

      var submit=form.querySelector('button[type="submit"]');
      var oldText=submit?submit.textContent:'';
      if(submit){submit.disabled=true;submit.textContent='Personel hazırlanıyor...';}
      showStatus('Manuel personel kaydı hazırlanıyor...','info');

      var body=new URLSearchParams();
      var csrf=form.querySelector('input[name="csrf_token"]');
      var paymentDate=form.querySelector('input[name="payment_date"]');
      body.set('csrf_token',csrf?csrf.value:'');
      body.set('manual_employee_name',manualName);
      body.set('payment_date',paymentDate?paymentDate.value:'');

      fetch('maas-tazminat-manuel-personel.php',{
        method:'POST',
        credentials:'same-origin',
        cache:'no-store',
        headers:{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body:body.toString()
      }).then(function(response){
        return response.json().catch(function(){return {ok:false,error:'Sunucu cevabı okunamadı.'};}).then(function(data){
          if(!response.ok||!data.ok) throw new Error(data.error||'Personel kaydı oluşturulamadı.');
          return data;
        });
      }).then(function(data){
        var option=null;
        Array.prototype.some.call(select.options,function(item){
          if(String(item.value)===String(data.employee_id)){option=item;return true;}
          return false;
        });
        if(!option){
          option=document.createElement('option');
          option.value=String(data.employee_id);
          option.textContent=String(data.full_name)+(Number(data.is_active)===0?' — Çıkış yaptı':'');
          select.appendChild(option);
        }
        select.value=String(data.employee_id);
        if(manual) manual.value='';
        showStatus(data.created?'Çıkış yapan personel kaydı oluşturuldu. Tazminat kaydediliyor...':'Mevcut personel bulundu. Tazminat kaydediliyor...','success');
        HTMLFormElement.prototype.submit.call(form);
      }).catch(function(error){
        showStatus(error.message||'Manuel personel kaydı oluşturulamadı.','error');
        if(submit){submit.disabled=false;submit.textContent=oldText;}
      });
    });

    if(!document.getElementById('compManualEmployeeStyle')){
      var style=document.createElement('style');
      style.id='compManualEmployeeStyle';
      style.textContent='.comp-manual-employee{padding:11px;border:1px solid #d8b07a;border-radius:13px;background:#fff8ed}.comp-manual-employee small{display:block;color:#7a5a3d;font-size:10px;line-height:1.4}.comp-manual-status{margin:0;padding:9px 11px;border-radius:10px;background:#edf4ff;color:#315c96;font-size:11px;font-weight:850}.comp-manual-status.is-error{background:#fff0ef;color:#9b3832}.comp-manual-status.is-success{background:#eaf6ed;color:#21683c}.comp-manual-status.is-info{background:#fff7e8;color:#7b5727}';
      document.head.appendChild(style);
    }
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',addManualEmployeeField); else addManualEmployeeField();
})();

(function(){
  'use strict';

  function norm(value){
    return String(value||'').toLocaleLowerCase('tr-TR').replace(/\s+/g,' ').trim();
  }

  function initInstrumentForm(){
    if(!/hareketler\.php/i.test(location.pathname)) return;
    var action=document.querySelector('.stack-form input[name="action"][value="save"]');
    var form=action&&action.closest('form');
    if(!form||form.dataset.instrumentFieldsReady==='1') return;
    form.dataset.instrumentFieldsReady='1';

    var type=form.querySelector('select[name="movement_type"]');
    var currency=form.querySelector('select[name="currency"]');
    var dueDate=form.querySelector('input[name="due_date"]');
    var cari=form.querySelector('select[name="cari_id"]');
    var category=form.querySelector('select[name="category_id"]');
    var account=form.querySelector('select[name="account_id"]');
    var method=form.querySelector('input[name="payment_method"]');
    var docType=form.querySelector('select[name="document_type"]');
    var genericDocument=form.querySelector('input[name="document"]');
    var genericDocumentLabel=genericDocument?genericDocument.closest('label'):null;
    if(!type||!currency||!dueDate||!cari||!method||!docType) return;

    if(!Array.prototype.some.call(docType.options,function(option){return option.value==='senet_gorseli';})){
      var senetOption=document.createElement('option');
      senetOption.value='senet_gorseli';
      senetOption.textContent='Senet görseli';
      docType.appendChild(senetOption);
    }

    var panel=document.createElement('div');
    panel.className='hareket-instrument-panel';
    panel.innerHTML=''
      +'<div class="hareket-instrument-head"><strong>Çek / Senet bilgileri</strong><small>Tekli çek veya senet girişinde numara ve görseli burada kaydet.</small></div>'
      +'<div class="hareket-instrument-grid">'
      +'<label>İşlem belgesi<select name="instrument_kind"><option value="">Normal işlem</option><option value="cek">Çek</option><option value="senet">Senet</option></select></label>'
      +'<label data-instrument-no hidden>Çek / Senet numarası<input name="instrument_no" maxlength="120" autocomplete="off" placeholder="Belge numarasını yaz"></label>'
      +'<label data-instrument-document hidden>Çek / Senet görseli <small>JPG, PNG, WEBP, HEIC veya PDF; max 10 MB</small><input name="instrument_document" type="file" accept="image/*,application/pdf"></label>'
      +'</div><p class="hareket-instrument-note" data-instrument-note hidden></p>';

    var docRow=docType.closest('.two-col');
    if(docRow) docRow.insertAdjacentElement('afterend',panel);
    else docType.closest('label').insertAdjacentElement('afterend',panel);

    var kind=panel.querySelector('select[name="instrument_kind"]');
    var noLabel=panel.querySelector('[data-instrument-no]');
    var noInput=panel.querySelector('input[name="instrument_no"]');
    var documentLabel=panel.querySelector('[data-instrument-document]');
    var documentInput=panel.querySelector('input[name="instrument_document"]');
    var note=panel.querySelector('[data-instrument-note]');
    var submit=form.querySelector('button[type="submit"]');

    function checkCategorySelected(){
      if(!category||category.selectedIndex<0) return false;
      var text=norm(category.options[category.selectedIndex].textContent||'');
      return text==='çek'||text==='cek'||text.indexOf('çek')===0||text.indexOf('cek')===0;
    }

    function selectCheckCategory(){
      if(!category||String(category.value||'')!=='') return;
      Array.prototype.some.call(category.options,function(option){
        var text=norm(option.textContent||'');
        if(text==='çek'||text==='cek'){
          category.value=option.value;
          return true;
        }
        return false;
      });
    }

    function showNote(text,tone){
      note.textContent=text||'';
      note.hidden=!text;
      note.className='hareket-instrument-note'+(tone?' is-'+tone:'');
    }

    function sync(forceFromKind){
      var value=String(kind.value||'');
      var active=value==='cek'||value==='senet';
      noLabel.hidden=!active;
      documentLabel.hidden=!active;
      noInput.required=active;
      dueDate.required=active;
      cari.required=active;

      if(genericDocumentLabel) genericDocumentLabel.style.display=active?'none':'';
      if(account){
        account.disabled=active;
        if(active) account.value='';
      }

      if(active){
        var isSenet=value==='senet';
        noLabel.firstChild.nodeValue=isSenet?'Senet numarası':'Çek numarası';
        documentLabel.firstChild.nodeValue=isSenet?'Senet görseli ':'Çek görseli ';
        noInput.placeholder=isSenet?'Senet numarasını yaz':'Çek numarasını yaz';
        method.value=isSenet?'SENET':'ÇEK';
        docType.value=isSenet?'senet_gorseli':'cek_gorseli';
        selectCheckCategory();
        if(type.value!=='tahsilat'&&type.value!=='odeme'){
          showNote('İşlem türünü Tahsilat (aldığımız çek/senet) veya Ödeme (verdiğimiz çek/senet) olarak seç.','warning');
        }else{
          showNote((type.value==='tahsilat'?'Alınan ':'Verilen ')+(isSenet?'senet':'çek')+' kaydı cariye bağlanacak; banka hesabı vade kapatılırken seçilecek.','info');
        }
      }else{
        noInput.required=false;
        dueDate.required=false;
        cari.required=false;
        if(account) account.disabled=false;
        showNote('', '');
      }
    }

    function autoDetect(){
      if(String(kind.value||'')!=='') return;
      var methodText=norm(method.value||'');
      if(methodText.indexOf('senet')!==-1||docType.value==='senet_gorseli') kind.value='senet';
      else if(methodText.indexOf('çek')!==-1||methodText.indexOf('cek')!==-1||docType.value==='cek_gorseli'||checkCategorySelected()) kind.value='cek';
      sync(false);
    }

    kind.addEventListener('change',function(){sync(true);});
    type.addEventListener('change',function(){sync(false);});
    method.addEventListener('change',autoDetect);
    docType.addEventListener('change',autoDetect);
    if(category) category.addEventListener('change',autoDetect);

    var movementIdInput=form.querySelector('input[name="id"]');
    var movementId=Number(movementIdInput?movementIdInput.value:0)||0;
    if(movementId>0){
      fetch('hareket-cek-senet-kaydet.php?movement_id='+encodeURIComponent(movementId)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
        .then(function(response){return response.json();})
        .then(function(data){
          if(!data||!data.ok||!data.instrument){autoDetect();return;}
          kind.value=data.instrument.kind||'cek';
          noInput.value=data.instrument.number||'';
          sync(true);
          if(data.instrument.has_document){
            showNote((kind.value==='senet'?'Senet':'Çek')+' görseli kayıtlı'+(data.instrument.document_name?' · '+data.instrument.document_name:'')+'. Yeni dosya seçersen mevcut görsel değişir.','success');
          }
        })
        .catch(autoDetect);
    }else{
      autoDetect();
    }

    form.addEventListener('submit',function(event){
      var instrumentKind=String(kind.value||'');
      if(instrumentKind!=='cek'&&instrumentKind!=='senet') return;
      event.preventDefault();

      if(type.value!=='tahsilat'&&type.value!=='odeme'){
        showNote('Çek/senet için işlem türünü Tahsilat veya Ödeme seçmelisin.','error');
        type.focus();
        return;
      }
      if(String(currency.value||'TL').toUpperCase()!=='TL'){
        showNote('Çek/senet kaydı bu ekranda TL olmalı.','error');
        currency.focus();
        return;
      }
      if(!String(cari.value||'')){
        showNote('Çek/senet için cari seçmelisin.','error');
        cari.focus();
        return;
      }
      if(!String(dueDate.value||'')){
        showNote('Çek/senet için vade tarihi zorunlu.','error');
        dueDate.focus();
        return;
      }
      if(!String(noInput.value||'').trim()){
        showNote((instrumentKind==='senet'?'Senet':'Çek')+' numarasını yazmalısın.','error');
        noInput.focus();
        return;
      }

      var oldText=submit?submit.textContent:'';
      if(submit){submit.disabled=true;submit.textContent='Çek / senet kaydediliyor...';}
      showNote('Hareket ve çek/senet kaydı birlikte hazırlanıyor...','info');

      var body=new FormData(form);
      body.set('instrument_kind',instrumentKind);
      body.set('instrument_no',String(noInput.value||'').trim());
      if(documentInput&&documentInput.files&&documentInput.files[0]) body.set('instrument_document',documentInput.files[0]);

      fetch('hareket-cek-senet-kaydet.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}})
        .then(function(response){
          return response.json().catch(function(){return {ok:false,error:'Sunucu cevabı okunamadı.'};}).then(function(data){
            if(!response.ok||!data||!data.ok) throw new Error((data&&data.error)||'Çek/senet kaydı oluşturulamadı.');
            return data;
          });
        })
        .then(function(data){
          showNote('Kaydedildi. Çek/senet kaydı ve görseli birbirine bağlandı.','success');
          location.href=data.redirect||'hareketler.php';
        })
        .catch(function(error){
          showNote(error.message||'Çek/senet kaydı oluşturulamadı.','error');
          if(submit){submit.disabled=false;submit.textContent=oldText;}
        });
    });

    if(!document.getElementById('hareketInstrumentStyle')){
      var style=document.createElement('style');
      style.id='hareketInstrumentStyle';
      style.textContent='.hareket-instrument-panel{display:grid;gap:10px;padding:13px;border:1px solid #d7c28f;border-radius:15px;background:linear-gradient(135deg,#fff9ed,#fff)}.hareket-instrument-head{display:grid;gap:3px}.hareket-instrument-head strong{color:#5d3d18;font-size:13px}.hareket-instrument-head small{color:#7c6b55;font-size:10px}.hareket-instrument-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.hareket-instrument-grid label{display:grid;gap:5px;font-size:11px;font-weight:850}.hareket-instrument-grid input,.hareket-instrument-grid select{width:100%;min-height:42px;border:1px solid #d8cbb5;border-radius:10px;padding:8px 9px;background:#fff;box-sizing:border-box}.hareket-instrument-grid input[type=file]{padding:7px}.hareket-instrument-note{margin:0;padding:8px 10px;border-radius:10px;font-size:10px;font-weight:800;background:#eef4ff;color:#315c96}.hareket-instrument-note.is-warning{background:#fff5df;color:#7a581d}.hareket-instrument-note.is-error{background:#fff0ef;color:#9b3832}.hareket-instrument-note.is-success{background:#eaf6ed;color:#21683c}.hareket-instrument-note.is-info{background:#eef4ff;color:#315c96}@media(max-width:760px){.hareket-instrument-grid{grid-template-columns:1fr}}';
      document.head.appendChild(style);
    }
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',initInstrumentForm); else initInstrumentForm();
})();
