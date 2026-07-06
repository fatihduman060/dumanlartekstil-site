(function(){
  function esc(s){return String(s == null ? '' : s).replace(/[&<>\"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[c];});}
  function norm(s){return String(s||'').toLocaleLowerCase('tr-TR').replace(/ı/g,'i').replace(/ç/g,'c').replace(/ğ/g,'g').replace(/ö/g,'o').replace(/ş/g,'s').replace(/ü/g,'u');}
  function panel(){
    var p=document.getElementById('bankaDetayPanel');
    if(p) return p;
    p=document.createElement('section');
    p.id='bankaDetayPanel';
    p.className='panel-card banka-detay-panel';
    p.style.display='none';
    p.innerHTML='<div class="card-head"><div><h3>Banka detayı</h3><span id="bankaDetayOzet"></span></div><button type="button" id="bankaDetayKapat" class="btn btn-secondary">Kapat</button></div><div id="bankaDetayIcerik"></div>';
    var bankSection=Array.from(document.querySelectorAll('.panel-card')).find(function(x){return norm(x.textContent).indexOf('banka bakiyeleri')>-1;});
    (bankSection || document.querySelector('.main') || document.body).insertAdjacentElement('afterend',p);
    document.getElementById('bankaDetayKapat').onclick=function(){p.style.display='none';};
    return p;
  }
  function render(data){
    var p=panel(), b=document.getElementById('bankaDetayIcerik');
    p.querySelector('h3').textContent=data.bank || 'Banka detayı';
    document.getElementById('bankaDetayOzet').textContent=(data.account_count||0)+' hesap · Toplam '+(data.total_text||'0,00 TL');
    var accounts=(data.accounts||[]).map(function(a){
      var tone=Number(a.balance||0)<0?'text-danger':'text-success';
      return '<div class="banka-detay-account"><div><a href="'+esc(a.url||'#')+'"><strong>'+esc(a.name||'-')+'</strong></a><small>'+esc(a.iban||'IBAN yok')+' · Açılış: '+esc(a.opening_balance||'0,00 TL')+'</small></div><strong class="'+tone+'">'+esc(a.balance_text||'0,00 TL')+'</strong></div>';
    }).join('');
    if(!accounts) accounts='<p class="muted">Bu bankaya bağlı aktif hesap bulunamadı.</p>';
    var tx=(data.transactions||[]).map(function(t){
      var isOut=(t.direction||'')==='out';
      var amount='<strong class="'+(isOut?'text-danger':'text-success')+'">'+(isOut?'-':'+')+esc(t.amount_text||'0,00 TL')+'</strong>';
      return '<tr><td>'+esc(t.date||'-')+'</td><td><strong>'+esc(t.account_name||'-')+'</strong><small>'+esc(t.source_type||'')+'</small></td><td>'+esc(t.description||'-')+'<small>'+esc(t.user_name||'')+'</small></td><td class="right">'+amount+'</td></tr>';
    }).join('');
    if(!tx) tx='<tr><td colspan="4" class="empty">Son hareket bulunamadı.</td></tr>';
    b.innerHTML='<div class="banka-detay-total"><span>Toplam bakiye</span><strong class="'+(Number(data.total||0)<0?'text-danger':'text-success')+'">'+esc(data.total_text||'0,00 TL')+'</strong></div><h4>Hesaplar</h4><div class="banka-detay-list">'+accounts+'</div><h4>Son hareketler</h4><div class="table-wrap"><table><thead><tr><th>Tarih</th><th>Hesap</th><th>Açıklama</th><th class="right">Tutar</th></tr></thead><tbody>'+tx+'</tbody></table></div>';
    p.style.display='block';
    p.scrollIntoView({behavior:'smooth',block:'nearest'});
  }
  function load(bank){
    var p=panel();
    p.style.display='block';
    document.getElementById('bankaDetayOzet').textContent='Yükleniyor...';
    document.getElementById('bankaDetayIcerik').innerHTML='<p class="muted">Banka içeriği okunuyor...</p>';
    fetch('hesap-banka-detay.php?bank='+encodeURIComponent(bank)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(r){return r.json();})
      .then(function(d){if(!d||!d.ok) throw new Error((d&&d.error)||'Detay okunamadı'); render(d);})
      .catch(function(err){document.getElementById('bankaDetayIcerik').innerHTML='<p class="text-danger">'+esc(err.message||'Detay okunamadı')+'</p>';});
  }
  function enhance(){
    if(!/hesaplar\.php/i.test(location.pathname)) return;
    var bankSection=Array.from(document.querySelectorAll('.panel-card')).find(function(x){return norm(x.textContent).indexOf('banka bakiyeleri')>-1;});
    if(!bankSection) return;
    bankSection.querySelectorAll('.stat-card').forEach(function(card){
      var span=card.querySelector('span');
      var bank=span ? (span.textContent||'').trim() : '';
      if(!bank || card.dataset.bankDetailBound) return;
      card.dataset.bankDetailBound='1';
      card.style.cursor='pointer';
      card.title='Banka hesap detayını aç';
      card.addEventListener('click',function(){load(bank);});
    });
  }
  function styles(){
    if(document.getElementById('bankaDetayStyle')) return;
    var s=document.createElement('style');
    s.id='bankaDetayStyle';
    s.textContent='.banka-detay-panel{margin-top:16px}.banka-detay-total{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px;border:1px solid #e5dccf;border-radius:16px;background:#fff8e8;margin-bottom:14px}.banka-detay-total span{font-weight:900;color:#776b5c}.banka-detay-total strong{font-size:22px}.banka-detay-panel h4{margin:14px 0 8px;color:#102818}.banka-detay-list{display:grid;gap:8px}.banka-detay-account{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;padding:11px 12px;border:1px solid #e5dccf;border-radius:14px;background:#fff}.banka-detay-account a{color:#102818;text-decoration:none}.banka-detay-account a:hover{text-decoration:underline}.banka-detay-account small,.banka-detay-panel small{display:block;color:#776b5c;margin-top:3px}.banka-detay-panel table td{vertical-align:top}.panel-card .stat-card[data-bank-detail-bound="1"]{transition:transform .15s ease, box-shadow .15s ease}.panel-card .stat-card[data-bank-detail-bound="1"]:hover{transform:translateY(-2px);box-shadow:0 14px 34px rgba(7,27,63,.10)}';
    document.head.appendChild(s);
  }
  function init(){styles();enhance();}
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
