(function(){
  var loading=false;
  var csrfToken='';
  var accounts=[];

  function esc(value){
    return String(value==null?'':value).replace(/[&<>"]/g,function(char){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[char];
    });
  }

  function accountLabel(account){
    var detail=account.bank_name||account.account_type||'';
    return account.name+(detail?' — '+detail:'');
  }

  function accountSelectHtml(item){
    var choices=accounts.filter(function(account){
      return item.account_scope!=='bank'||account.account_type==='banka';
    });
    var selected=String(item.default_account_id||'');
    var options=choices.map(function(account){
      return '<option value="'+Number(account.id||0)+'" '+(selected===String(account.id)?'selected':'')+'>'+esc(accountLabel(account))+'</option>';
    }).join('');
    return '<select class="vade-hatirlatma-hesap" aria-label="Ödeme veya tahsilat hesabı"><option value="">Hesap seç</option>'+options+'</select>';
  }

  function actionTitle(item){
    if((item.source||'movement')==='movement'){
      return 'Açık hesapta seçilen hesaba tahsilat/ödeme işler ve cari açık bakiyeyi kapatır.';
    }
    if(item.source==='check'){
      return 'Çek/senette mevcut bağlı hareketi seçilen bankaya yansıtır; cari bakiyeyi ikinci kez değiştirmez.';
    }
    return 'Seçilen banka hesabına ödeme olarak işler.';
  }

  function itemHtml(item){
    var description=item.description
      ? '<span class="vade-hatirlatma-aciklama">'+esc(item.description)+'</span>'
      : '';
    var status='<span class="vade-hatirlatma-durum">'+esc(item.status_text||'Bekliyor')+'</span>';
    var action=item.can_complete
      ? '<div class="vade-hatirlatma-islem">'+accountSelectHtml(item)+'<button type="button" class="vade-hatirlatma-tamamla" data-source="'+esc(item.source||'movement')+'" data-id="'+esc(item.id||'')+'" data-label="'+esc(item.complete_label||'Tamamlandı')+'" title="'+esc(actionTitle(item))+'">✓ '+esc(item.complete_label||'Tamamlandı')+'</button></div>'
      : '';

    return '<div class="vade-hatirlatma-satir">'
      +'<a class="vade-hatirlatma-link" href="'+esc(item.url||'#')+'">'
      +'<span class="vade-hatirlatma-ana"><strong>'+esc(item.cari_name||'-')+'</strong><small>'+esc(item.kind||'Vade')+description+'</small></span>'
      +'<span class="vade-hatirlatma-tutar"><strong class="text-'+esc(item.tone||'success')+'">'+esc(item.amount_text||'0,00 TL')+'</strong><small>'+esc(item.due_text||'')+' · '+esc(item.state_text||'')+'</small></span>'
      +'</a>'
      +'<span class="vade-hatirlatma-kontrol">'+status+action+'</span>'
      +'</div>';
  }

  function groupHtml(group,isOpen){
    var count=Number(group.count||0);
    var pieces=[];
    if(Number(group.incoming_count||0)>0) pieces.push(group.incoming_count+' alacak');
    if(Number(group.outgoing_count||0)>0) pieces.push(group.outgoing_count+' ödeme');
    var rows=count
      ? (group.items||[]).map(itemHtml).join('')
      : '<p class="vade-hatirlatma-bos">Bu başlıkta kayıt yok.</p>';
    return '<details class="vade-hatirlatma-grup tone-'+esc(group.tone||'info')+'" '+(isOpen?'open':'')+'>'
      +'<summary><span><strong>'+esc(group.label||'Vade')+'</strong><small>'+esc(pieces.join(' · ')||'Kayıt yok')+'</small></span><b>'+count+' kayıt</b></summary>'
      +'<div class="vade-hatirlatma-liste">'+rows+'</div>'
      +'</details>';
  }

  function render(data){
    if(!data||!data.ok) return;
    csrfToken=String(data.csrf_token||'');
    accounts=Array.isArray(data.accounts)?data.accounts:[];
    var groups=Array.isArray(data.groups)?data.groups:[];
    var firstOpen=-1;
    groups.some(function(group,index){
      if(Number(group.count||0)>0){firstOpen=index;return true;}
      return false;
    });

    var total=Number(data.count||0);
    var section=document.getElementById('dashboardVadeHatirlatmalari');
    if(!section) return;
    section.innerHTML='<div class="vade-hatirlatma-baslik"><span class="vade-hatirlatma-ikon">🔔</span><div><strong>Vade Hatırlatmaları</strong><small>Açık hesapta seçilen hesaba tahsilat/ödeme işlenir; çek ve senette cari ikinci kez etkilenmeden banka kapanışı yapılır.</small></div><b>'+total+' kayıt</b></div>'
      +(total
        ? '<div class="vade-hatirlatma-gruplar">'+groups.map(function(group,index){return groupHtml(group,index===firstOpen);}).join('')+'</div>'
        : '<div class="vade-hatirlatma-temiz">✅ Yaklaşan veya geciken vade bulunmuyor.</div>');
  }

  function load(){
    if(loading) return;
    loading=true;
    fetch('dashboard-vade-hatirlatmalari.php?_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(render)
      .catch(function(){})
      .finally(function(){loading=false;});
  }

  function completeReminder(button){
    var label=button.getAttribute('data-label')||'Tamamlandı';
    var source=button.getAttribute('data-source')||'movement';
    var row=button.closest('.vade-hatirlatma-satir');
    var accountSelect=row?row.querySelector('.vade-hatirlatma-hesap'):null;
    var accountId=accountSelect?String(accountSelect.value||''):'';
    if(!accountId){
      window.alert('Ödeme veya tahsilatın işleneceği hesabı seçmelisin.');
      if(accountSelect) accountSelect.focus();
      return;
    }
    var accountText=accountSelect.options[accountSelect.selectedIndex]?accountSelect.options[accountSelect.selectedIndex].text:'seçilen hesap';
    var confirmText='';

    if(source==='movement'){
      confirmText='Bu açık hesap kaydı '+label+' olarak işlensin mi?\n\n'
        +accountText+' hesabına tahsilat/ödeme yansıyacak ve cari açık bakiye aynı tutarda kapanacak.\n\n'
        +'Aynı cari, tutar ve hesap için bugün tahsilat/ödeme zaten kayıtlıysa ikinci hareket oluşturulmadan mevcut kayıt kullanılacak.';
    }else if(source==='check'){
      confirmText='Bu çek/senet '+label+' olarak doğrulansın mı?\n\n'
        +'Yeni cari tahsilat/ödeme oluşturulmayacak. Çek/senede bağlı mevcut hareket '+accountText+' hesabına yansıtılacak; cari bakiye ikinci kez değişmeyecek.';
    }else{
      confirmText='Bu kayıt '+label+' olarak doğrulansın mı?\n\nÖdeme '+accountText+' hesabına işlenecek.';
    }

    if(!window.confirm(confirmText)) return;

    var oldText=button.textContent;
    button.disabled=true;
    button.textContent='Kaydediliyor';

    function resetButton(){
      button.disabled=false;
      button.textContent=oldText;
    }

    function showRowError(message){
      var status=row?row.querySelector('.vade-hatirlatma-durum'):null;
      if(!status) return;
      status.classList.add('is-error');
      status.textContent=message;
      status.title=message;
    }

    function submitCompletion(useExisting,existingMovementId){
      var body=new FormData();
      body.append('action','complete');
      body.append('source',source);
      body.append('id',button.getAttribute('data-id')||'');
      body.append('account_id',accountId);
      body.append('csrf_token',csrfToken);
      if(useExisting){
        body.append('use_existing','1');
        body.append('existing_movement_id',String(existingMovementId||''));
      }

      var endpoint=source==='movement'?'dashboard-vade-guvenli.php':'dashboard-vade-hatirlatmalari.php';
      fetch(endpoint,{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
        .then(function(response){
          return response.text().then(function(raw){
            var data=null;
            try{data=JSON.parse(raw);}catch(parseError){
              data={ok:false,error:'Sunucu cevabı JSON değil.',response_body:raw};
            }
            if(!response.ok||!data||!data.ok){
              var detail=(data&&data.error)||'Vade durumu güncellenemedi.';
              if(data&&data.response_body){
                var responseText=String(data.response_body).replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim();
                if(responseText) detail+=' / '+responseText.slice(0,500);
              }
              var error=new Error('HTTP '+response.status+': '+detail);
              error.data=data||{};
              throw error;
            }
            return data;
          });
        })
        .then(function(){
          load();
        })
        .catch(function(error){
          var data=error&&error.data?error.data:{};
          if(source==='movement'&&data.code==='possible_duplicate'&&!useExisting){
            var existingDate=data.existing_date?('\nMevcut hareket tarihi: '+data.existing_date):'';
            var linkExisting=window.confirm(
              'Aynı cari, tutar ve hesap için bugün zaten bir tahsilat/ödeme hareketi var.\n\n'
              +'Yeni kayıt oluşturulmadı.'+existingDate+'\n\n'
              +'Vadeyi mevcut harekete bağlayıp kapatalım mı?'
            );
            if(linkExisting){
              button.textContent='Mevcut harekete bağlanıyor';
              submitCompletion(true,data.existing_movement_id||0);
              return;
            }
          }
          showRowError(error.message||'Vade durumu güncellenemedi.');
          resetButton();
        });
    }

    submitCompletion(false,0);
  }

  function addStyles(){
    if(document.getElementById('vadeHatirlatmaStyle')) return;
    var style=document.createElement('style');
    style.id='vadeHatirlatmaStyle';
    style.textContent=''
      +'.vade-hatirlatma-kutu{margin:0 0 18px;background:linear-gradient(135deg,#fffaf3,#fff);border:1px solid var(--border);box-shadow:var(--shadow);border-radius:20px;padding:16px}'
      +'.vade-hatirlatma-baslik{display:grid;grid-template-columns:auto 1fr auto;gap:12px;align-items:center;margin-bottom:12px}.vade-hatirlatma-baslik>div{display:grid;gap:3px}.vade-hatirlatma-baslik strong{font-size:17px}.vade-hatirlatma-baslik small{color:var(--muted);font-size:12px}.vade-hatirlatma-baslik>b{padding:7px 10px;border-radius:999px;background:#efe8dd;color:#544b3d;font-size:12px}.vade-hatirlatma-ikon{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:#fff4dc;font-size:19px}'
      +'.vade-hatirlatma-gruplar{display:grid;grid-template-columns:minmax(0,1fr);gap:10px}.vade-hatirlatma-grup{border:1px solid var(--border);border-radius:15px;background:#fff;overflow:hidden}.vade-hatirlatma-grup summary{list-style:none;cursor:pointer;padding:13px 14px;display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}.vade-hatirlatma-grup summary::-webkit-details-marker{display:none}.vade-hatirlatma-grup summary span{display:grid;gap:3px}.vade-hatirlatma-grup summary strong{font-size:14px}.vade-hatirlatma-grup summary small{color:var(--muted);font-size:11px}.vade-hatirlatma-grup summary b{font-size:12px;padding:6px 8px;border-radius:999px;background:#f3efe7}.vade-hatirlatma-grup[open] summary{border-bottom:1px solid var(--border)}'
      +'.vade-hatirlatma-grup.tone-danger{border-left:4px solid var(--danger)}.vade-hatirlatma-grup.tone-warning{border-left:4px solid var(--warning)}.vade-hatirlatma-grup.tone-info{border-left:4px solid var(--info)}'
      +'.vade-hatirlatma-liste{display:grid;max-height:340px;overflow:auto}.vade-hatirlatma-satir{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;padding:11px 12px;border-bottom:1px solid var(--border);background:#fff}.vade-hatirlatma-satir:last-child{border-bottom:0}.vade-hatirlatma-satir:hover{background:#fffaf3}.vade-hatirlatma-link{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;min-width:0}.vade-hatirlatma-ana,.vade-hatirlatma-tutar{display:grid;gap:4px}.vade-hatirlatma-ana strong{font-size:13px}.vade-hatirlatma-ana small,.vade-hatirlatma-tutar small{font-size:11px;color:var(--muted)}.vade-hatirlatma-tutar{text-align:right}.vade-hatirlatma-tutar strong{font-size:13px}.vade-hatirlatma-aciklama{display:block;margin-top:2px;white-space:nowrap;max-width:210px;overflow:hidden;text-overflow:ellipsis}.vade-hatirlatma-kontrol{display:grid;gap:5px;justify-items:end;min-width:190px}.vade-hatirlatma-durum{font-size:9px;font-weight:900;color:#776b5c;background:#f2eee6;border:1px solid #e4dccf;border-radius:999px;padding:4px 7px}.vade-hatirlatma-durum.is-error{max-width:360px;white-space:normal;color:#9f2d22;background:#fff1ef;border-color:#efb8b2}.vade-hatirlatma-islem{display:grid;gap:6px;width:100%}.vade-hatirlatma-hesap{width:100%;min-height:34px;border:1px solid var(--border);background:#fff;border-radius:9px;padding:5px 7px;font-size:10px;color:#3f493f}.vade-hatirlatma-tamamla{border:1px solid #bfe3ca;background:#e8f5ed;color:#1f6b3d;border-radius:9px;padding:7px 8px;font-size:10px;font-weight:900;cursor:pointer}.vade-hatirlatma-tamamla:hover{background:#d9efdf}.vade-hatirlatma-tamamla:disabled{opacity:.55;cursor:wait}.vade-hatirlatma-bos,.vade-hatirlatma-temiz{margin:0;padding:14px;color:var(--muted);font-size:12px}.vade-hatirlatma-temiz{border:1px solid #bfe3ca;background:#e8f5ed;color:#1f6b3d;border-radius:14px;font-weight:800}'
      +'@media(max-width:1100px){.vade-hatirlatma-liste{max-height:300px}}@media(max-width:640px){.vade-hatirlatma-baslik{grid-template-columns:auto 1fr}.vade-hatirlatma-baslik>b{grid-column:1/-1;justify-self:start}.vade-hatirlatma-liste{max-height:none;overflow:visible}.vade-hatirlatma-satir{grid-template-columns:minmax(0,1fr)}.vade-hatirlatma-link{grid-template-columns:minmax(0,1fr)}.vade-hatirlatma-kontrol{grid-template-columns:minmax(0,1fr);justify-content:stretch;justify-items:stretch;width:100%;min-width:0}.vade-hatirlatma-durum{justify-self:start;align-self:start}.vade-hatirlatma-islem{width:100%;min-width:0}.vade-hatirlatma-hesap,.vade-hatirlatma-tamamla{width:100%;min-width:0}.vade-hatirlatma-tutar{text-align:left}.vade-hatirlatma-aciklama{max-width:100%;white-space:normal}}';
    document.head.appendChild(style);
  }

  document.addEventListener('click',function(event){
    var button=event.target.closest('.vade-hatirlatma-tamamla');
    if(!button) return;
    event.preventDefault();
    event.stopPropagation();
    completeReminder(button);
  });

  function run(){
    if(!/dashboard\.php/i.test(location.pathname)) return;
    addStyles();
    load();
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',run); else run();
})();
