(function(){
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;
  if(document.querySelector('[data-toplu-fatura-link]')) return;

  var section=document.querySelector('.dashboard-section');
  if(!section) return;
  var filter=section.querySelector('.filterbar');
  var link=document.createElement('a');
  link.href='fatura-toplu-yukle.php';
  link.className='btn btn-primary';
  link.setAttribute('data-toplu-fatura-link','1');
  link.textContent='Toplu PDF yükle';

  if(filter) filter.appendChild(link);
  else section.appendChild(link);
})();
