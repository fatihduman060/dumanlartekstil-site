(function(){
  var requested=false;

  function esc(value){
    return String(value==null?'':value).replace(/[&<>\"]/g,function(char){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[char];
    });
  }

  function itemHtml(item){
    var description=item.description
      ? '<span class="vade-hatirlatma-aciklama">'+esc(item.description)+'</span>'
      : '';
    return '<a class="vade-hatirlatma-satir" href="'+esc(item.url||'#')+'">'
      +'<span class="vade-hatirlatma-ana"><strong>'+esc(item.cari_name||'-')+'</strong><small>'+esc(item.kind||'Vade')+description+'</small></span>'
      +'<span class="vade-hatirlatma-tutar"><strong class="text-'+esc(item.tone||'success')+'">'+esc(item.amount_text||'0,00 TL')+'</strong><small>'+esc(item.due_text||'')+' · '+esc(item.state_text||'')+'</small></span>'
      +'</a>';
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
    var old=document.getElementById('dashboardVadeHatirlatmalari');
    if(old) old.remove();

    var groups=Array.isArray(data.groups)?data.groups:[];
    var firstOpen=-1;
    groups.some(function(group,index){
      if(Number(group.count||0)>0){firstOpen=index;return true;}
      return false;
    });

    var total=Number(data.count||0);
    var section=document.createElement('section');
    section.id='dashboardVadeHatirlatmalari';
    section.className='vade-hatirlatma-kutu';
    section.innerHTML='<div class="vade-hatirlatma-baslik"><span class="vade-hatirlatma-ikon">🔔</span><div><strong>Vade Hatırlatmaları</strong><small>Çek, vadeli alacak ve ödeme kayıtlarını buradan doğrudan aç.</small></div><b>'+total+' kayıt</b></div>'
      +(total
        ? '<div class="vade-hatirlatma-gruplar">'+groups.map(function(group,index){return groupHtml(group,index===firstOpen);}).join('')+'</div>'
        : '<div class="vade-hatirlatma-temiz">✅ Yaklaşan veya geciken vade bulunmuyor.</div>');

    var anchor=document.querySelector('.backup-mini-strip')||document.querySelector('.hero-card');
    if(anchor) anchor.insertAdjacentElement('afterend',section);
  }

  function addStyles(){
    if(document.getElementById('vadeHatirlatmaStyle')) return;
    var style=document.createElement('style');
    style.id='vadeHatirlatmaStyle';
    style.textContent=''
      +'.vade-hatirlatma-kutu{margin:0 0 18px;background:linear-gradient(135deg,#fffaf3,#fff);border:1px solid var(--border);box-shadow:var(--shadow);border-radius:20px;padding:16px}'
      +'.vade-hatirlatma-baslik{display:grid;grid-template-columns:auto 1fr auto;gap:12px;align-items:center;margin-bottom:12px}.vade-hatirlatma-baslik>div{display:grid;gap:3px}.vade-hatirlatma-baslik strong{font-size:17px}.vade-hatirlatma-baslik small{color:var(--muted);font-size:12px}.vade-hatirlatma-baslik>b{padding:7px 10px;border-radius:999px;background:#efe8dd;color:#544b3d;font-size:12px}.vade-hatirlatma-ikon{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:#fff4dc;font-size:19px}'
      +'.vade-hatirlatma-gruplar{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.vade-hatirlatma-grup{border:1px solid var(--border);border-radius:15px;background:#fff;overflow:hidden}.vade-hatirlatma-grup summary{list-style:none;cursor:pointer;padding:13px 14px;display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}.vade-hatirlatma-grup summary::-webkit-details-marker{display:none}.vade-hatirlatma-grup summary span{display:grid;gap:3px}.vade-hatirlatma-grup summary strong{font-size:14px}.vade-hatirlatma-grup summary small{color:var(--muted);font-size:11px}.vade-hatirlatma-grup summary b{font-size:12px;padding:6px 8px;border-radius:999px;background:#f3efe7}.vade-hatirlatma-grup[open] summary{border-bottom:1px solid var(--border)}'
      +'.vade-hatirlatma-grup.tone-danger{border-left:4px solid var(--danger)}.vade-hatirlatma-grup.tone-warning{border-left:4px solid var(--warning)}.vade-hatirlatma-grup.tone-info{border-left:4px solid var(--info)}'
      +'.vade-hatirlatma-liste{display:grid;max-height:340px;overflow:auto}.vade-hatirlatma-satir{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;padding:12px 13px;border-bottom:1px solid var(--border);background:#fff}.vade-hatirlatma-satir:last-child{border-bottom:0}.vade-hatirlatma-satir:hover{background:#fffaf3}.vade-hatirlatma-ana,.vade-hatirlatma-tutar{display:grid;gap:4px}.vade-hatirlatma-ana strong{font-size:13px}.vade-hatirlatma-ana small,.vade-hatirlatma-tutar small{font-size:11px;color:var(--muted)}.vade-hatirlatma-tutar{text-align:right}.vade-hatirlatma-tutar strong{font-size:13px}.vade-hatirlatma-aciklama{display:block;margin-top:2px;white-space:nowrap;max-width:210px;overflow:hidden;text-overflow:ellipsis}.vade-hatirlatma-bos,.vade-hatirlatma-temiz{margin:0;padding:14px;color:var(--muted);font-size:12px}.vade-hatirlatma-temiz{border:1px solid #bfe3ca;background:#e8f5ed;color:#1f6b3d;border-radius:14px;font-weight:800}'
      +'@media(max-width:1100px){.vade-hatirlatma-gruplar{grid-template-columns:1fr}.vade-hatirlatma-liste{max-height:280px}}@media(max-width:640px){.vade-hatirlatma-baslik{grid-template-columns:auto 1fr}.vade-hatirlatma-baslik>b{grid-column:1/-1;justify-self:start}.vade-hatirlatma-satir{grid-template-columns:1fr}.vade-hatirlatma-tutar{text-align:left}.vade-hatirlatma-aciklama{max-width:100%}}';
    document.head.appendChild(style);
  }

  function run(){
    if(!/dashboard\.php/i.test(location.pathname)||requested) return;
    requested=true;
    addStyles();
    fetch('dashboard-vade-hatirlatmalari.php?_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(render)
      .catch(function(){requested=false;});
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',run); else run();
})();
