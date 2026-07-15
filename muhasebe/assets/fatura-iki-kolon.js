(function(){
  if(!/\/faturalar\.php$/i.test(location.pathname) || window.BITKE_STORE_SALES_ONLY) return;

  var params=new URLSearchParams(location.search);
  var editId=Number(params.get('edit')||0);
  var serverDirection=params.get('direction')||'';

  function qs(selector,root){return (root||document).querySelector(selector);}
  function qsa(selector,root){return Array.from((root||document).querySelectorAll(selector));}
  function norm(value){
    return String(value||'').toLocaleLowerCase('tr-TR')
      .replace(/ı/g,'i').replace(/ç/g,'c').replace(/ğ/g,'g')
      .replace(/ö/g,'o').replace(/ş/g,'s').replace(/ü/g,'u')
      .replace(/\s+/g,' ').trim();
  }
  function money(value){
    return Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' TL';
  }
  function moneyValues(text){
    var matches=String(text||'').match(/-?\d[\d.\s]*(?:,\d{2})|-?\d[\d,\s]*(?:\.\d{2})/g)||[];
    return matches.map(function(raw){
      var value=raw.replace(/\s/g,'');
      var comma=value.lastIndexOf(',');
      var dot=value.lastIndexOf('.');
      if(comma>-1&&dot>-1){
        value=comma>dot?value.replace(/\./g,'').replace(',','.'):value.replace(/,/g,'');
      }else if(comma>-1){
        value=value.replace(/\./g,'').replace(',','.');
      }else if(dot>-1&&value.length-dot-1!==2){
        value=value.replace(/\./g,'');
      }
      var number=parseFloat(value);
      return Number.isFinite(number)?number:0;
    });
  }
  function headerIndex(headers,needles){
    for(var i=0;i<headers.length;i++){
      var text=norm(headers[i].textContent);
      if(needles.some(function(needle){return text.indexOf(needle)!==-1;})) return i;
    }
    return -1;
  }
  function rowDirection(row,index){
    var text=index>=0&&row.cells[index]?norm(row.cells[index].textContent):norm(row.textContent);
    if(text.indexOf('giden')!==-1) return 'giden';
    if(text.indexOf('gelen')!==-1) return 'gelen';
    return '';
  }
  function activeDirection(){
    var saved='';
    try{saved=sessionStorage.getItem('faturaAktifYon')||'';}catch(error){}
    return saved==='gelen'?'gelen':'giden';
  }
  function saveActiveDirection(direction){
    try{sessionStorage.setItem('faturaAktifYon',direction);}catch(error){}
  }

  // Eski yön filtresiyle açılmış sayfada yalnızca tek yön yüklenmiş olabilir.
  // Sekmelerin ikisinin de aynı sayfada çalışması için yön parametresini bir kez temizle.
  if((serverDirection==='giden'||serverDirection==='gelen')&&editId<=0){
    saveActiveDirection(serverDirection);
    params.delete('direction');
    var clean=params.toString();
    location.replace(location.pathname+(clean?'?'+clean:''));
    return;
  }

  function setupEntryModal(){
    if(editId>0) return;
    var formCard=qs('.form-grid > .form-card');
    var form=formCard?qs('#invoiceForm',formCard):null;
    if(!formCard||!form||qs('[data-fatura-entry-modal]')) return;

    var listCard=qsa('.form-grid > .panel-card').find(function(card){
      return !card.classList.contains('form-card')&&qs('table',card);
    });
    if(!listCard) return;

    var head=qs('.card-head',listCard);
    var openButton=document.createElement('button');
    openButton.type='button';
    openButton.className='btn btn-primary fatura-entry-open';
    openButton.setAttribute('data-fatura-entry-open','1');
    openButton.textContent='+ Fatura Girişi';
    if(head) head.appendChild(openButton); else listCard.insertAdjacentElement('afterbegin',openButton);

    var overlay=document.createElement('div');
    overlay.className='fatura-entry-overlay';
    overlay.hidden=true;
    overlay.setAttribute('data-fatura-entry-modal','1');
    overlay.innerHTML='<div class="fatura-entry-dialog" role="dialog" aria-modal="true" aria-label="Tekli fatura girişi"><button type="button" class="fatura-entry-close" data-fatura-entry-close aria-label="Kapat">×</button><div class="fatura-entry-note"><strong>Tekli Fatura Girişi</strong><small>PDF’yi seçtiğinde yön, fatura no, tarih, matrah, KDV ve genel toplam otomatik okunur; kaydetmeden önce kontrol edebilirsin.</small></div><div data-fatura-entry-body></div></div>';
    document.body.appendChild(overlay);
    qs('[data-fatura-entry-body]',overlay).appendChild(formCard);
    formCard.hidden=false;
    formCard.removeAttribute('aria-hidden');
    formCard.classList.add('fatura-entry-modal-card');

    function open(){
      overlay.hidden=false;
      document.body.classList.add('fatura-entry-modal-open');
      var input=qs('input[name="document"]',form);
      window.setTimeout(function(){if(input) input.focus();},80);
    }
    function close(){
      overlay.hidden=true;
      document.body.classList.remove('fatura-entry-modal-open');
    }
    openButton.addEventListener('click',open);
    overlay.addEventListener('click',function(event){
      if(event.target===overlay||event.target.closest('[data-fatura-entry-close]')) close();
    });
    document.addEventListener('keydown',function(event){
      if(event.key==='Escape'&&!overlay.hidden) close();
    });
  }

  function setupDirectionTabs(){
    if(editId>0||qs('[data-fatura-direction-tabs]')) return;
    var grid=qs('.form-grid');
    if(!grid) return;
    var listCard=qsa(':scope > .panel-card',grid).find(function(card){
      return !card.classList.contains('form-card')&&qs('table',card);
    });
    if(!listCard) return;

    var table=qs('.table-wrap table',listCard);
    if(!table||!table.tBodies.length) return;
    var tbody=table.tBodies[0];
    var headers=qsa('thead th',table);
    var directionIndex=headerIndex(headers,['yon']);
    var amountIndex=headerIndex(headers,['matrah','kdv']);
    var totalIndex=headerIndex(headers,['toplam']);
    var columnCount=Math.max(headers.length,1);
    var selected=activeDirection();

    var oldDirectionSelect=qs('form.filterbar select[name="direction"]',listCard);
    if(oldDirectionSelect){
      oldDirectionSelect.value='';
      oldDirectionSelect.hidden=true;
      oldDirectionSelect.setAttribute('aria-hidden','true');
    }

    var tabs=document.createElement('nav');
    tabs.className='fatura-direction-tabs';
    tabs.setAttribute('data-fatura-direction-tabs','1');
    tabs.setAttribute('aria-label','Fatura yönü');
    tabs.innerHTML=''
      +'<button type="button" data-fatura-direction="giden"><strong>Giden Fatura</strong><small>Bizim kestiğimiz faturalar · <span data-fatura-tab-count="giden">0</span> kayıt</small></button>'
      +'<button type="button" data-fatura-direction="gelen"><strong>Gelen Fatura</strong><small>Bize kesilen faturalar · <span data-fatura-tab-count="gelen">0</span> kayıt</small></button>';

    var filter=qs('form.filterbar',listCard);
    if(filter) filter.insertAdjacentElement('beforebegin',tabs);
    else {
      var head=qs('.card-head',listCard);
      if(head) head.insertAdjacentElement('afterend',tabs);
      else listCard.insertAdjacentElement('afterbegin',tabs);
    }

    var tfoot=table.tFoot||table.createTFoot();
    tfoot.className='fatura-direction-total-foot';
    tfoot.innerHTML='<tr class="fatura-direction-total-row"><td colspan="'+columnCount+'"><div class="fatura-direction-totals"><span><small>Matrah toplamı</small><strong data-fatura-total-subtotal>0,00 TL</strong></span><span><small>KDV toplamı</small><strong data-fatura-total-vat>0,00 TL</strong></span><span><small>Genel toplam</small><strong data-fatura-total-grand>0,00 TL</strong></span></div></td></tr>';

    function removeGeneratedEmpty(){
      qsa('tr[data-fatura-tab-empty]',tbody).forEach(function(row){row.remove();});
    }
    function realRows(){
      return qsa(':scope > tr',tbody).filter(function(row){
        return !row.classList.contains('empty')&&!row.hasAttribute('data-fatura-tab-empty');
      });
    }
    function calculate(direction){
      var subtotal=0,vat=0,grand=0,count=0;
      realRows().forEach(function(row){
        var rowDir=rowDirection(row,directionIndex);
        row.setAttribute('data-fatura-row-direction',rowDir);
        row.classList.add('fatura-tab-row');
        row.hidden=rowDir!==direction;
        if(rowDir!==direction) return;
        count++;
        if(row.classList.contains('row-cancelled')) return;
        var amountCell=amountIndex>=0?row.cells[amountIndex]:null;
        var totalCell=totalIndex>=0?row.cells[totalIndex]:null;
        var amounts=moneyValues(amountCell?amountCell.textContent:'');
        subtotal+=Number(amounts[0]||0);
        vat+=Number(amounts[1]||0);
        var totals=moneyValues(totalCell?totalCell.textContent:'');
        grand+=Number(totals[0]||0);
      });
      return {count:count,subtotal:subtotal,vat:vat,grand:grand};
    }
    function countDirection(direction){
      return realRows().filter(function(row){return rowDirection(row,directionIndex)===direction;}).length;
    }
    function render(){
      removeGeneratedEmpty();
      var result=calculate(selected);
      qsa('[data-fatura-direction]',tabs).forEach(function(button){
        var direction=button.getAttribute('data-fatura-direction');
        var active=direction===selected;
        button.classList.toggle('active',active);
        button.setAttribute('aria-pressed',active?'true':'false');
      });
      ['giden','gelen'].forEach(function(direction){
        var count=qs('[data-fatura-tab-count="'+direction+'"]',tabs);
        if(count) count.textContent=String(countDirection(direction));
      });
      qs('[data-fatura-total-subtotal]',tfoot).textContent=money(result.subtotal);
      qs('[data-fatura-total-vat]',tfoot).textContent=money(result.vat);
      qs('[data-fatura-total-grand]',tfoot).textContent=money(result.grand);

      if(result.count===0){
        var empty=document.createElement('tr');
        empty.setAttribute('data-fatura-tab-empty','1');
        empty.innerHTML='<td colspan="'+columnCount+'" class="empty">Bu dönemde '+(selected==='giden'?'giden':'gelen')+' fatura bulunamadı.</td>';
        tbody.appendChild(empty);
      }

      var headCount=qs('.card-head > span',listCard);
      if(headCount) headCount.textContent=(selected==='giden'?'Giden faturalar':'Gelen faturalar')+' · '+result.count+' kayıt';
    }

    tabs.addEventListener('click',function(event){
      var button=event.target.closest('[data-fatura-direction]');
      if(!button) return;
      selected=button.getAttribute('data-fatura-direction')==='gelen'?'gelen':'giden';
      saveActiveDirection(selected);
      render();
    });

    var scheduled=false;
    var observer=new MutationObserver(function(){
      if(scheduled) return;
      scheduled=true;
      window.setTimeout(function(){scheduled=false;render();},80);
    });
    observer.observe(tbody,{childList:true,subtree:true,characterData:true});
    render();
  }

  function addStyle(){
    if(qs('#faturaSekmeliStyle')) return;
    var style=document.createElement('style');
    style.id='faturaSekmeliStyle';
    style.textContent=''
      +'.fatura-direction-tabs{display:flex;gap:8px;margin:0 0 12px;padding:7px;border:1px solid #e5dccf;border-radius:16px;background:#fff;box-shadow:0 8px 22px rgba(7,27,63,.04)}'
      +'.fatura-direction-tabs button{flex:1 1 0;display:grid;gap:3px;text-align:center;border:1px solid transparent;border-radius:12px;padding:11px 14px;background:#fbf6ed;color:#16482e;cursor:pointer}'
      +'.fatura-direction-tabs button strong{font-size:13px}.fatura-direction-tabs button small{font-size:9px;font-weight:700;opacity:.72}'
      +'.fatura-direction-tabs button.active{background:#c49a4f;color:#102818;border-color:#b58a3f;box-shadow:0 6px 16px rgba(196,154,79,.2)}'
      +'.fatura-tab-row[hidden]{display:none!important}.fatura-direction-total-row td{padding:10px!important;border-top:2px solid #dfd5c6;background:#fbf8f2}'
      +'.fatura-direction-totals{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.fatura-direction-totals span{display:grid;gap:3px;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:#fff}.fatura-direction-totals small{font-size:8px;color:var(--muted);font-weight:800}.fatura-direction-totals strong{font-size:12px}'
      +'.fatura-entry-open{margin-left:auto;white-space:nowrap}.fatura-entry-overlay[hidden]{display:none!important}.fatura-entry-overlay{position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:20px;background:rgba(14,24,37,.62);backdrop-filter:blur(3px)}.fatura-entry-dialog{position:relative;width:min(920px,96vw);max-height:92vh;overflow:auto;border-radius:20px;background:#f8f6f1;box-shadow:0 30px 90px rgba(0,0,0,.3);padding:18px}.fatura-entry-close{position:absolute;right:12px;top:10px;z-index:2;width:34px;height:34px;border:0;border-radius:50%;background:#eee6d8;color:#503f25;font-size:23px;cursor:pointer}.fatura-entry-note{display:grid;gap:4px;padding:3px 44px 14px 3px}.fatura-entry-note strong{font-size:17px}.fatura-entry-note small{font-size:11px;color:var(--muted)}.fatura-entry-modal-card{width:100%!important;max-width:none!important;margin:0!important}.fatura-entry-modal-card>.card-head{display:none}.fatura-entry-modal-open{overflow:hidden}'
      +'@media(max-width:650px){.fatura-direction-tabs{display:grid}.fatura-direction-totals{grid-template-columns:1fr}.fatura-entry-overlay{padding:8px}.fatura-entry-dialog{width:100%;max-height:96vh;padding:12px;border-radius:15px}.fatura-entry-open{width:100%;margin-top:8px}}';
    document.head.appendChild(style);
  }

  function start(){
    addStyle();
    setupEntryModal();
    setupDirectionTabs();
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',function(){window.setTimeout(start,250);});
  else window.setTimeout(start,250);
})();
