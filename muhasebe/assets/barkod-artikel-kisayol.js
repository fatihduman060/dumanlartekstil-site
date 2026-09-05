(function(){
  var root=document.querySelector('[data-pos-root]');
  if(!root) return;
  var input=root.querySelector('[data-pos-scan]');
  var results=root.querySelector('[data-pos-results]');
  var status=root.querySelector('[data-pos-status]');
  if(!input) return;

  var timer=null;
  var requestNo=0;

  function barcodeMatchesArticle(barcode,article){
    var digits=String(barcode||'').replace(/\D+/g,'');
    return digits.length===13 && digits.slice(-5,-1)===article;
  }

  function matchingBarcode(product,article){
    var list=Array.isArray(product&&product.barcodes)?product.barcodes:[];
    if(product&&product.matched_barcode) list=[product.matched_barcode].concat(list);
    if(product&&product.barcode) list=[product.barcode].concat(list);
    for(var i=0;i<list.length;i++){
      if(barcodeMatchesArticle(list[i],article)) return String(list[i]);
    }
    return '';
  }

  function tryArticle(article){
    var current=++requestNo;
    fetch('barkod-satis-arama.php?q='+encodeURIComponent(article)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(current!==requestNo) return;
        if(String(input.value||'').trim()!==article) return;
        if(!data||data.ok===false) return;
        var products=Array.isArray(data.products)?data.products:[];
        var exact=[];
        products.forEach(function(product){
          var barcode=matchingBarcode(product,article);
          if(barcode) exact.push({product:product,barcode:barcode});
        });
        if(exact.length!==1) return;

        input.value=exact[0].barcode;
        if(results){results.hidden=true;results.style.display='none';results.innerHTML='';}
        if(status) status.textContent=article+' kodu '+exact[0].barcode+' barkoduna çevrildi.';
        root.dispatchEvent(new CustomEvent('pos:add-product',{detail:exact[0].product}));
      })
      .catch(function(){/* normal canlı arama çalışmaya devam etsin */});
  }

  input.addEventListener('input',function(){
    if(timer) clearTimeout(timer);
    requestNo++;
    var value=String(input.value||'').trim();
    if(!/^\d{4}$/.test(value)) return;
    timer=setTimeout(function(){tryArticle(value);},70);
  });
})();
