(function(){
  var root=document.querySelector('[data-pos-root]');
  if(!root) return;
  var input=root.querySelector('[data-pos-scan]');
  var results=root.querySelector('[data-pos-results]');
  var status=root.querySelector('[data-pos-status]');
  var searchApi='barkod-satis-arama.php';
  if(!input||!results) return;

  var timer=null;
  var requestNo=0;

  function esc(value){
    return String(value||'').replace(/[&<>"']/g,function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
    });
  }
  function money(value){
    return new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(value||0))+' TL';
  }
  function productName(product){
    var name=String((product&&product.name)||'').trim();
    var variant=String((product&&product.variant_name)||'').trim();
    return variant ? name+' - '+variant : name;
  }
  function hide(){
    results.hidden=true;
    results.innerHTML='';
    delete results.dataset.liveSearch;
  }
  function render(items,query){
    if(String(input.value||'').trim()!==query) return;
    results.hidden=false;
    results.dataset.liveSearch='1';
    if(!items.length){
      results.innerHTML='<div class="pos-result-empty">Bu kelimeyle eşleşen ürün bulunamadı.</div>';
      if(status) status.textContent='';
      return;
    }
    results.innerHTML=items.map(function(p){
      return '<button type="button" data-live-pos-barcode="'+esc(p.matched_barcode||p.barcode)+'">'
        +'<span><strong>'+esc(productName(p))+'</strong><small>'+esc(p.matched_barcode||p.barcode)+' · Stok: '+Number(p.stock_quantity||0)+'</small></span>'
        +'<strong>'+money(p.sale_price)+'</strong></button>';
    }).join('');
    if(status) status.textContent=items.length+' ürün bulundu.';
  }
  function searchNow(){
    var query=String(input.value||'').trim();
    if(!query){hide();if(status)status.textContent='';return;}
    var current=++requestNo;
    if(status) status.textContent='Ürünler aranıyor…';
    fetch(searchApi+'?q='+encodeURIComponent(query)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(current!==requestNo) return;
        if(!data||data.ok===false) throw new Error((data&&data.error)||'Arama yapılamadı.');
        render(data.products||[],query);
      })
      .catch(function(error){
        if(current!==requestNo) return;
        if(status) status.textContent=error&&error.message?error.message:'Ürün aranamadı.';
      });
  }

  input.addEventListener('input',function(){
    if(timer) clearTimeout(timer);
    requestNo++;
    var query=String(input.value||'').trim();
    if(!query){hide();if(status)status.textContent='';return;}
    timer=setTimeout(searchNow,180);
  });

  input.addEventListener('keydown',function(event){
    if(event.key==='Enter'&&timer){clearTimeout(timer);timer=null;searchNow();}
  },true);

  results.addEventListener('click',function(event){
    var button=event.target.closest('[data-live-pos-barcode]');
    if(!button) return;
    var barcode=button.getAttribute('data-live-pos-barcode')||'';
    if(!barcode) return;
    input.value=barcode;
    hide();
    input.dispatchEvent(new Event('change',{bubbles:true}));
  });
})();
