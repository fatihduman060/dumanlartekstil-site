(function(){
  function norm(value){
    return String(value||'')
      .toLocaleUpperCase('tr-TR')
      .replace(/İ/g,'I').replace(/Ş/g,'S').replace(/Ğ/g,'G').replace(/Ü/g,'U').replace(/Ö/g,'O').replace(/Ç/g,'C')
      .replace(/[^A-Z0-9]+/g,' ')
      .replace(/\s+/g,' ')
      .trim();
  }

  function money(value){
    return Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' TL';
  }

  function trDate(value){
    var match=String(value||'').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    return match?match[3]+'.'+match[2]+'.'+match[1]:'-';
  }

  function jsonFetch(url,options){
    return fetch(url,options||{}).then(function(response){
      return response.json().then(function(data){
        if(!response.ok||!data.ok) throw new Error(data.error||'İşlem tamamlanamadı.');
        return data;
      });
    });
  }

  function detailPage(){
    if(!/\/cari-detay\.php$/i.test(location.pathname)) return;
    var title=document.querySelector('.detail-hero h2');
    if(!title||norm(title.textContent)!=='NAKLIYE') return;

    var params=new URLSearchParams(location.search);
    var cariId=Number(params.get('id')||0);
    if(!cariId) return;

    var csrfInput=document.querySelector('input[name="csrf_token"]');
    var csrf=csrfInput?String(csrfInput.value||''):'';
    var hero=document.querySelector('.detail-hero');
    var summarySection=document.getElementById('ozet');
    var privateSection=document.getElementById('ozel-alacak');
    var checksSection=document.getElementById('cekler');
    var movementSection=document.getElementById('hareketler');
    var tabs=document.querySelector('.detail-tabs');

    if(hero){
      var desc=hero.querySelector('p');
      if(desc) desc.textContent='Satın alınan ürün ve malzemelere ait nakliye giderleri. Bu cari alacak veya borç oluşturmaz; seçilen kasa/banka hesabından ödeme düşer.';
      hero.querySelectorAll('.hero-actions a').forEach(function(link){
        var href=String(link.getAttribute('href')||'');
        if(href.indexOf('hareketler.php?cari_id=')===0||href.indexOf('cekler.php?cari_id=')===0) link.remove();
      });
      var actions=hero.querySelector('.hero-actions');
      if(actions&&!actions.querySelector('[data-nakliye-gider-scroll]')){
        var button=document.createElement('button');
        button.type='button';
        button.className='btn btn-primary';
        button.setAttribute('data-nakliye-gider-scroll','1');
        button.textContent='Nakliye gideri ekle';
        button.addEventListener('click',function(){
          var form=document.querySelector('[data-nakliye-gider-form]');
          if(form) form.scrollIntoView({behavior:'smooth',block:'center'});
        });
        actions.insertAdjacentElement('afterbegin',button);
      }
    }

    if(tabs){
      tabs.querySelectorAll('a').forEach(function(link){
        var href=link.getAttribute('href');
        if(href==='#ozel-alacak'||href==='#cekler') link.remove();
        if(href==='#hareketler') link.textContent='Nakliye Giderleri';
      });
    }
    if(privateSection) privateSection.hidden=true;
    if(checksSection) checksSection.hidden=true;

    var quickForm=document.querySelector('form input[name="action"][value="quick_movement"]');
    var quickPanel=quickForm?quickForm.closest('.panel-card'):null;
    var accountOptions='';
    var documentOptions='';
    if(quickPanel){
      var oldForm=quickForm.closest('form');
      var oldAccount=oldForm?oldForm.querySelector('select[name="account_id"]'):null;
      var oldDocument=oldForm?oldForm.querySelector('select[name="document_type"]'):null;
      accountOptions=oldAccount?oldAccount.innerHTML:'<option value="">Kasa/banka seç</option>';
      documentOptions=oldDocument?oldDocument.innerHTML:'<option value="">Belge türü</option>';
      quickPanel.innerHTML=''
        +'<div class="card-head"><h3>Nakliye gideri ekle</h3><span>Seçilen hesaptan para çıkışı yapılır; cari borç/alacak oluşmaz.</span></div>'
        +'<form class="nakliye-gider-form" data-nakliye-gider-form enctype="multipart/form-data">'
        +'<div class="nakliye-gider-grid">'
        +'<label>Tutar<input name="amount" type="text" inputmode="decimal" placeholder="0,00" required></label>'
        +'<label>İşlem tarihi<input name="movement_date" type="date" required value="'+new Date().toISOString().slice(0,10)+'"></label>'
        +'<label>Ödenecek hesap<select name="account_id" required>'+accountOptions+'</select><small>Bu tutar seçilen kasa veya banka hesabından düşer.</small></label>'
        +'<label>Ödeme yöntemi<input name="payment_method" placeholder="Nakit, EFT, kart..."></label>'
        +'<label>Belge türü<select name="document_type">'+documentOptions+'</select></label>'
        +'<label>Belge / dekont<input name="document" type="file" accept="image/*,application/pdf"></label>'
        +'<label class="wide">Açıklama<textarea name="description" rows="2" placeholder="Örn: Hammadde nakliye bedeli"></textarea></label>'
        +'</div>'
        +'<p class="nakliye-gider-status" data-nakliye-gider-status></p>'
        +'<div class="form-actions"><button class="btn btn-primary" type="submit">Nakliye giderini kaydet</button></div>'
        +'</form>';

      var specialForm=quickPanel.querySelector('[data-nakliye-gider-form]');
      var status=specialForm.querySelector('[data-nakliye-gider-status]');
      specialForm.addEventListener('submit',function(event){
        event.preventDefault();
        var submit=specialForm.querySelector('button[type="submit"]');
        var account=specialForm.querySelector('[name="account_id"]');
        if(!account.value){
          status.textContent='Paranın düşeceği kasa veya banka hesabını seçmelisin.';
          status.className='nakliye-gider-status is-danger';
          account.focus();
          return;
        }
        var oldText=submit.textContent;
        submit.disabled=true;
        submit.textContent='Kaydediliyor...';
        status.textContent='Nakliye gideri kaydediliyor ve hesap bakiyesi güncelleniyor...';
        status.className='nakliye-gider-status is-loading';
        var body=new FormData(specialForm);
        body.set('action','add_expense');
        body.set('cari_id',String(cariId));
        body.set('csrf_token',csrf);
        jsonFetch('nakliye-gider-modu.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
          .then(function(data){
            csrf=String(data.csrf_token||csrf);
            if(csrfInput) csrfInput.value=csrf;
            status.textContent=data.message||'Nakliye gideri kaydedildi.';
            status.className='nakliye-gider-status is-success';
            window.setTimeout(function(){location.reload();},650);
          })
          .catch(function(error){
            status.textContent=error.message||'Nakliye gideri kaydedilemedi.';
            status.className='nakliye-gider-status is-danger';
          })
          .finally(function(){submit.disabled=false;submit.textContent=oldText;});
      });
    }

    if(movementSection){
      var movementTitle=movementSection.querySelector('.card-head h3');
      if(movementTitle) movementTitle.textContent='Nakliye gider geçmişi';
    }

    function renderSummary(summary){
      if(!summarySection) return;
      var last=summary.last||null;
      var missing=Number(summary.missing_account||0);
      summarySection.innerHTML=''
        +'<div class="dashboard-section-head detail-summary-head">'
        +'<div><span>Gider Takibi</span><h3>Nakliye gider özeti</h3></div>'
        +'<p class="text-success">Bu cari alacak veya borç bakiyesi oluşturmaz.</p>'
        +'</div>'
        +'<div class="stats-grid four nakliye-gider-summary">'
        +'<article class="stat-card status"><span>Toplam nakliye gideri</span><strong class="text-danger">'+money(summary.total)+'</strong><small>'+Number(summary.movement_count||0)+' gider kaydı</small></article>'
        +'<article class="stat-card soft"><span>Bu ay</span><strong>'+money(summary.month_total)+'</strong><small>İçinde bulunduğumuz ay</small></article>'
        +'<article class="stat-card cash"><span>Son nakliye ödemesi</span><strong>'+(last?money(last.amount):'-')+'</strong><small>'+(last?trDate(last.movement_date)+(last.account_name?' · '+last.account_name:''):'Henüz kayıt yok')+'</small></article>'
        +'<article class="stat-card '+(missing?'special':'soft')+'"><span>Hesap seçilmemiş eski kayıt</span><strong>'+missing+'</strong><small>'+(missing?'Bu kayıtları düzeltip kasa/banka seç.':'Tüm giderler hesaba bağlı')+'</small></article>'
        +'</div>'
        +(missing?'<p class="nakliye-gider-warning"><strong>Dikkat:</strong> Eski kayıtlardan '+missing+' tanesinde kasa/banka seçilmemiş. Nakliye gider geçmişindeki “İncele / Düzelt” bağlantısından ödeme hesabını seçebilirsin.</p>':'');
    }

    if(summarySection){
      summarySection.innerHTML='<div class="panel-card"><p class="muted">Nakliye gider düzeni hazırlanıyor...</p></div>';
    }

    var normalizeBody=new FormData();
    normalizeBody.set('action','normalize');
    normalizeBody.set('cari_id',String(cariId));
    normalizeBody.set('csrf_token',csrf);
    jsonFetch('nakliye-gider-modu.php',{method:'POST',body:normalizeBody,credentials:'same-origin',cache:'no-store'})
      .then(function(data){
        csrf=String(data.csrf_token||csrf);
        if(csrfInput) csrfInput.value=csrf;
        if(Number(data.changed||0)>0){
          location.reload();
          return;
        }
        renderSummary(data.summary||{});
      })
      .catch(function(error){
        if(summarySection) summarySection.innerHTML='<div class="alert alert-error">'+String(error.message||'Nakliye gider özeti yüklenemedi.')+'</div>';
      });
  }

  function movementsPage(){
    if(!/\/hareketler\.php$/i.test(location.pathname)) return;
    var form=document.querySelector('.form-card form');
    if(!form) return;
    var cari=form.querySelector('select[name="cari_id"]');
    var type=form.querySelector('select[name="movement_type"]');
    var category=form.querySelector('select[name="category_id"]');
    var account=form.querySelector('select[name="account_id"]');
    var currency=form.querySelector('select[name="currency"]');
    var due=form.querySelector('input[name="due_date"]');
    if(!cari||!type||!account) return;

    var note=document.createElement('div');
    note.className='nakliye-gider-form-note';
    note.hidden=true;
    note.innerHTML='<strong>Nakliye gider modu</strong><span>Bu caride yalnızca gider kaydı oluşturulur. Tutar seçilen kasa/banka hesabından düşer; alacak veya borç oluşmaz.</span>';
    form.insertAdjacentElement('afterbegin',note);

    function apply(){
      var selected=cari.options[cari.selectedIndex];
      var active=selected&&norm(selected.textContent.split('—')[0])==='NAKLIYE';
      note.hidden=!active;
      Array.from(type.options).forEach(function(option){option.hidden=active&&option.value!=='gider';});
      if(active){
        type.value='gider';
        if(currency) currency.value='TL';
        if(due){due.value='';due.disabled=true;}
        account.required=true;
        if(category){
          var nakliye=Array.from(category.options).find(function(option){return norm(option.textContent)==='NAKLIYE';});
          if(nakliye) category.value=nakliye.value;
        }
      }else{
        if(due) due.disabled=false;
        account.required=false;
      }
    }

    cari.addEventListener('change',apply);
    form.addEventListener('submit',function(event){
      var selected=cari.options[cari.selectedIndex];
      var active=selected&&norm(selected.textContent.split('—')[0])==='NAKLIYE';
      if(!active) return;
      type.value='gider';
      if(currency&&currency.value!=='TL') currency.value='TL';
      if(!account.value){
        event.preventDefault();
        window.alert('Nakliye giderinde paranın düşeceği kasa veya banka hesabını seçmelisin.');
        account.focus();
      }
    });
    apply();
  }

  var style=document.createElement('style');
  style.textContent=''
    +'.nakliye-gider-form{display:grid;gap:12px}.nakliye-gider-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.nakliye-gider-grid label{display:grid;gap:6px;font-size:11px;font-weight:850;color:#554d42}.nakliye-gider-grid .wide{grid-column:1/-1}.nakliye-gider-grid input,.nakliye-gider-grid select,.nakliye-gider-grid textarea{width:100%;border:1px solid var(--border);border-radius:12px;background:#fff;padding:10px 11px}.nakliye-gider-grid small{font-size:9px;color:var(--muted)}.nakliye-gider-status{min-height:16px;margin:0;font-size:11px;color:var(--muted)}.nakliye-gider-status.is-loading{color:#23598b}.nakliye-gider-status.is-success{color:var(--success)}.nakliye-gider-status.is-danger{color:var(--danger)}.nakliye-gider-warning{margin:10px 0 0;padding:11px 13px;border:1px solid #efcf95;border-radius:12px;background:#fff7e5;color:#7b540d;font-size:11px}.nakliye-gider-form-note{display:grid;gap:3px;padding:11px 13px;border:1px solid #d8c6a5;border-radius:13px;background:#fff7e8}.nakliye-gider-form-note[hidden]{display:none}.nakliye-gider-form-note strong{font-size:12px}.nakliye-gider-form-note span{font-size:10px;color:var(--muted)}'
    +'@media(max-width:650px){.nakliye-gider-grid{grid-template-columns:1fr}.nakliye-gider-grid .wide{grid-column:auto}.nakliye-gider-summary{grid-template-columns:1fr!important}}';
  document.head.appendChild(style);

  function start(){detailPage();movementsPage();}
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',start);
  else start();
})();
