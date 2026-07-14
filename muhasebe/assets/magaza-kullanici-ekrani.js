(function(){
  if(!window.BITKE_STORE_SALES_ONLY) return;
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  function periodValue(){
    var value=new URLSearchParams(location.search).get('period')||'';
    return /^\d{4}-\d{2}$/.test(value)?value:new Date().toISOString().slice(0,7);
  }

  var main=document.querySelector('main.main');
  var topbar=main?main.querySelector('.topbar'):null;
  if(!main||!topbar) return;

  Array.prototype.slice.call(main.children).forEach(function(child){
    if(child!==topbar) child.remove();
  });

  var title=topbar.querySelector('h1');
  var eyebrow=topbar.querySelector('p');
  if(title) title.textContent='Mağaza Günlük Satışları';
  if(eyebrow) eyebrow.textContent='Fabrika satış mağazası';

  var section=document.createElement('section');
  section.className='dashboard-section store-sales-shell';
  section.innerHTML=''
    +'<div class="dashboard-section-head store-sales-head">'
    +'<div><span>Günlük satış girişi</span><h3>Mağaza satışlarını kaydet</h3></div>'
    +'<p>Gün sonu raporundaki KDV dahil toplamı yaz. Sistem %10 KDV ve matrahı otomatik hesaplar.</p>'
    +'</div>'
    +'<form class="filterbar store-sales-period" method="get" action="faturalar.php">'
    +'<input type="month" name="period" value="'+periodValue()+'">'
    +'<button class="btn btn-secondary" type="submit">Ayı göster</button>'
    +'</form>'
    +'<div class="store-sales-only-body" data-fatura-alt-kontrol-body></div>';
  main.appendChild(section);

  var nav=document.querySelector('.side-nav');
  if(nav){
    nav.innerHTML='<a class="active" href="faturalar.php"><span class="nav-ico">▤</span><span>Faturalar</span></a>';
  }

  var brand=document.querySelector('.sidebar .brand');
  if(brand) brand.setAttribute('href','faturalar.php');

  var footerRole=document.querySelector('.side-footer span');
  if(footerRole) footerRole.textContent='Mağaza Kullanıcısı';

  var style=document.createElement('style');
  style.textContent=''
    +'.store-sales-shell{display:grid!important;gap:16px;max-width:1120px}'
    +'.store-sales-head{margin-bottom:0}'
    +'.store-sales-period{margin:0;padding:12px;border:1px solid var(--border);border-radius:14px;background:#fff}'
    +'.store-sales-only-body{display:grid;gap:14px}'
    +'.store-sales-user .top-actions .ghost-link{display:none!important}'
    +'.store-sales-user .main>.store-sales-shell{display:grid!important}'
    +'@media(max-width:700px){.store-sales-period{align-items:stretch}}';
  document.head.appendChild(style);
})();
