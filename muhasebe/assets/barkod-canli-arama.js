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
  var activeIndex=-1;

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
    activeIndex=-1;
    delete results.dataset.liveSearch;
  }
  function buttons(){
    return Array.prototype.slice.call(results.querySelectorAll('[data-live-pos-barcode]'));
  }
  function setActive(index,scroll){
    var items=buttons();
    if(!items.length){activeIndex=-1;return;}
    activeIndex=(index+items.length)%items.length;
    items.forEach(function(button,i){
      var selected=i===activeIndex;
      button.classList.toggle('pos-result-active',selected);
      button.setAttribute('aria-selected',selected?'true':'false');
    });
    if(scroll!==false) items[activeIndex].scrollIntoView({block:'nearest'});
  }
  function choose(button){
    if(!button) return;
    var barcode=button.getAttribute('data-live-pos-barcode')||'';
    if(!barcode) return;
    input.value=barcode;
    hide();
    input.dispatchEvent(new Event('change',{bubbles:true}));
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
      return '<button type="button" role="option" aria-selected="false" data-live-pos-barcode="'+esc(p.matched_barcode||p.barcode)+'">'
        +'<span><strong>'+esc(productName(p))+'</strong><small>'+esc(p.matched_barcode||p.barcode)+' · Stok: '+Number(p.stock_quantity||0)+'</small></span>'
        +'<strong>'+money(p.sale_price)+'</strong></button>';
    }).join('');
    activeIndex=-1;
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
    var items=buttons();
    if((event.key==='ArrowDown'||event.key==='ArrowUp')&&!results.hidden&&items.length){
      event.preventDefault();event.stopImmediatePropagation();
      setActive(activeIndex+(event.key==='ArrowDown'?1:-1));
      return;
    }
    if(event.key==='Enter'&&!results.hidden&&items.length){
      event.preventDefault();event.stopImmediatePropagation();
      choose(items[activeIndex<0?0:activeIndex]);
      return;
    }
    if(event.key==='Escape'&&!results.hidden){event.preventDefault();hide();return;}
    if(event.key==='Enter'&&timer){clearTimeout(timer);timer=null;searchNow();}
  },true);

  results.addEventListener('click',function(event){
    var button=event.target.closest('[data-live-pos-barcode]');
    if(button) choose(button);
  });
  results.addEventListener('mousemove',function(event){
    var button=event.target.closest('[data-live-pos-barcode]');
    if(!button) return;
    var index=buttons().indexOf(button);
    if(index!==activeIndex) setActive(index,false);
  });
})();

(function(){
  var root=document.querySelector('[data-pos-root]');
  if(!root||root.querySelector('[data-pos-misc-box]')) return;
  var scanRow=root.querySelector('.pos-scan-row');
  var scan=root.querySelector('[data-pos-scan]');
  var results=root.querySelector('[data-pos-results]');
  var status=root.querySelector('[data-pos-status]');
  if(!scanRow||!scan) return;

  var style=document.createElement('style');
  style.textContent=''
    +'.pos-misc-box{display:grid;grid-template-columns:auto minmax(120px,190px) auto;gap:9px;align-items:end;margin:10px 0 4px;padding:10px 12px;border:1px solid #e3d9cb;border-radius:14px;background:#fffaf2}'
    +'.pos-misc-box>span{font-size:12px;font-weight:900;color:#5f564b;align-self:center}.pos-misc-box label{margin:0}.pos-misc-box label span{display:block;font-size:11px;font-weight:800;color:#756b60;margin-bottom:4px}.pos-misc-box input{width:100%;min-height:40px;font-size:16px}.pos-misc-box button{min-height:40px;white-space:nowrap}'
    +'.pos-misc-box small{grid-column:1/-1;color:#887b6d;font-size:10px}.pos-product-table tr[data-misc-system-row="1"]{display:none!important}'
    +'@media(max-width:680px){.pos-misc-box{grid-template-columns:1fr 1fr}.pos-misc-box>span{grid-column:1/-1}.pos-misc-box small{grid-column:1/-1}}';
  document.head.appendChild(style);

  var box=document.createElement('div');
  box.className='pos-misc-box';
  box.setAttribute('data-pos-misc-box','1');
  box.innerHTML='<span>Muhtelif satış</span><label><span>Tutar</span><input type="text" inputmode="decimal" autocomplete="off" placeholder="Örn. 275" data-pos-misc-amount></label><button type="button" class="btn btn-secondary" data-pos-misc-add>Sepete ekle</button><small>Ürün tanımlı değilse tutarı yaz; stoktan düşmeden “Muhtelif Satış” olarak sepete eklenir.</small>';
  scanRow.insertAdjacentElement('afterend',box);

  function hideSystemRows(){
    root.querySelectorAll('.pos-product-table tbody tr').forEach(function(row){
      var barcodeCell=row.querySelector('td:nth-child(2)');
      if(barcodeCell&&/^MUHTELIF-/i.test(String(barcodeCell.textContent||'').trim())) row.setAttribute('data-misc-system-row','1');
    });
  }
  hideSystemRows();

  var amountInput=box.querySelector('[data-pos-misc-amount]');
  var addButton=box.querySelector('[data-pos-misc-add]');

  function normalizeAmount(value){
    var text=String(value||'').trim().replace(/\s+/g,'');
    if(!text) return 0;
    if(text.indexOf(',')!==-1&&text.indexOf('.')!==-1){text=text.replace(/\./g,'').replace(',','.');}
    else if(text.indexOf(',')!==-1){text=text.replace(',','.');}
    var number=Number(text);
    return Number.isFinite(number)?Math.round(number*100)/100:0;
  }

  function addMisc(){
    var amount=normalizeAmount(amountInput.value);
    if(amount<=0){if(status)status.textContent='Muhtelif satış için geçerli bir tutar yaz.';amountInput.focus();return;}
    addButton.disabled=true;
    addButton.textContent='Ekleniyor…';
    if(status)status.textContent='Muhtelif satış hazırlanıyor…';
    var body=new FormData();
    body.set('action','muhtelif');
    body.set('csrf_token',root.dataset.csrf||'');
    body.set('amount',String(amount));
    fetch('barkod-satis-arama.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(!data||!data.ok||!data.barcode) throw new Error((data&&data.error)||'Muhtelif satış hazırlanamadı.');
        if(results){results.hidden=true;results.innerHTML='';}
        scan.value=data.barcode;
        amountInput.value='';
        scan.dispatchEvent(new Event('change',{bubbles:true}));
        setTimeout(hideSystemRows,250);
      })
      .catch(function(error){if(status)status.textContent=error&&error.message?error.message:'Muhtelif satış eklenemedi.';})
      .finally(function(){addButton.disabled=false;addButton.textContent='Sepete ekle';});
  }

  addButton.addEventListener('click',addMisc);
  amountInput.addEventListener('keydown',function(event){if(event.key==='Enter'){event.preventDefault();addMisc();}});
})();
