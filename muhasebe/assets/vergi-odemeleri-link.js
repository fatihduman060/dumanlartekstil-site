(function(){
  if(!/\/dashboard\.php$/i.test(location.pathname)) return;
  function add(){
    var grid=document.querySelector('.quick-grid.mobile-focus');
    if(!grid||grid.querySelector('[data-vergi-quick-link]')) return;
    var link=document.createElement('a');
    link.className='quick-action';
    link.href='vergi-odemeleri.php';
    link.setAttribute('data-vergi-quick-link','1');
    link.innerHTML='<strong>+ Vergi Ödemeleri</strong><span>Makbuz yükle · bankadan düş</span>';
    grid.appendChild(link);
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',add);
  else add();
})();
