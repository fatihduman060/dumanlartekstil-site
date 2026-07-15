(function(){
  if(!/\/faturalar\.php$/i.test(location.pathname) || window.BITKE_STORE_SALES_ONLY) return;

  var params=new URLSearchParams(location.search);
  var editId=Number(params.get('edit')||0);
  var splitState=null;

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
  function directionTitle(direction){
    return direction==='giden'
      ? {title:'Giden Faturalar',copy:'Bizim gönderdiğimiz / kestiğimiz faturalar'}
      : {title:'Gelen Faturalar',copy:'Bize gönderilen / kesilen faturalar'};
  }
  function tableShell(sourceTable,direction,columnCount){
    var meta=directionTitle(direction);
    var card=document.createElement('section');
    card.className='fatura-yon-card fatura-yon-'+direction;
    card.setAttribute('data-fatura-yon-card',direction);
    card.innerHTML='<div class="fatura-yon-head"><div><h4>'+meta.title+'</h4><small>'+meta.copy+'</small></div><strong data-fatura-yon-count>0 kayıt</strong></div>';

    var tableWrap=document.createElement('div');
    tableWrap.className='table-wrap fatura-yon-table-wrap';
    var table=sourceTable.cloneNode(false);
    table.classList.add('fatura-yon-table');
    table.setAttribute('data-fatura-yon-table',direction);
    if(sourceTable.tHead) table.appendChild(sourceTable.tHead.cloneNode(true));
    var tbody=document.createElement('tbody');
    table.appendChild(tbody);
    var tfoot=document.createElement('tfoot');
    tfoot.innerHTML='<tr class="fatura-yon-total-row"><td colspan="'+columnCount+'"><div class="fatura-yon-totals"><span><small>Matrah toplamı</small><strong data-fatura-total-subtotal>0,00 TL</strong></span><span><small>KDV toplamı</small><strong data-fatura-total-vat>0,00 TL</strong></span><span><small>Genel toplam</small><strong data-fatura-total-grand>0,00 TL</strong></span></div></td></tr>';
    table.appendChild(tfoot);
    tableWrap.appendChild(table);
    card.appendChild(tableWrap);
    return {card:card,table:table,tbody:tbody};
  }
  function markDirectionColumn(table,index){
    if(index<0) return;
    qsa('tr',table).forEach(function(row){
      if(row.cells&&row.cells[index]) row.cells[index].classList.add('fatura-yon-gizli-kolon');
    });
  }
  function removeEmptyRows(tbody){
    qsa('tr[data-fatura-split-empty]',tbody).forEach(function(row){row.remove();});
  }
  function ensureEmptyRow(tbody,columnCount,direction){
    removeEmptyRows(tbody);
    var realRows=qsa(':scope > tr',tbody).filter(function(row){return !row.classList.contains('empty');});
    if(realRows.length) return;
    var tr=document.createElement('tr');
    tr.setAttribute('data-fatura-split-empty','1');
    tr.innerHTML='<td colspan="'+columnCount+'" class="empty">Bu dönemde '+(direction==='giden'?'giden':'gelen')+' fatura bulunamadı.</td>';
    tbody.appendChild(tr);
  }
  function updateCard(direction){
    if(!splitState) return;
    var group=splitState[direction];
    removeEmptyRows(group.tbody);
    var rows=qsa(':scope > tr',group.tbody).filter(function(row){return !row.classList.contains('empty');});
    var subtotal=0,vat=0,grand=0;
    rows.forEach(function(row){
      var amountCell=splitState.amountIndex>=0?row.cells[splitState.amountIndex]:null;
      var totalCell=splitState.totalIndex>=0?row.cells[splitState.totalIndex]:null;
      var amounts=moneyValues(amountCell?amountCell.textContent:'');
      subtotal+=Number(amounts[0]||0);
      vat+=Number(amounts[1]||0);
      var totals=moneyValues(totalCell?totalCell.textContent:'');
      grand+=Number(totals[0]||0);
    });
    var card=group.card;
    qs('[data-fatura-yon-count]',card).textContent=rows.length+' kayıt';
    qs('[data-fatura-total-subtotal]',card).textContent=money(subtotal);
    qs('[data-fatura-total-vat]',card).textContent=money(vat);
    qs('[data-fatura-total-grand]',card).textContent=money(grand);
    ensureEmptyRow(group.tbody,splitState.columnCount,direction);
  }
  function refreshSplit(){
    if(!splitState||splitState.refreshing) return;
    splitState.refreshing=true;
    ['giden','gelen'].forEach(function(direction){removeEmptyRows(splitState[direction].tbody);});
    var rows=qsa('tbody > tr',splitState.wrap).filter(function(row){return !row.hasAttribute('data-fatura-split-empty')&&!row.classList.contains('empty');});
    rows.forEach(function(row){
      var direction=rowDirection(row,splitState.directionIndex);
      if(direction&&row.parentElement!==splitState[direction].tbody) splitState[direction].tbody.appendChild(row);
      if(splitState.directionIndex>=0&&row.cells[splitState.directionIndex]) row.cells[splitState.directionIndex].classList.add('fatura-yon-gizli-kolon');
    });
    updateCard('giden');
    updateCard('gelen');
    splitState.refreshing=false;
  }
  function buildSplit(){
    if(splitState||editId>0) return;
    var grid=qs('.form-grid');
    if(!grid) return;
    var listCard=qsa(':scope > .panel-card',grid).find(function(card){return !card.classList.contains('form-card')&&qs('table',card);});
    if(!listCard) return;
    var sourceTable=qs('.table-wrap table',listCard);
    if(!sourceTable||!sourceTable.tBodies.length) return;
    var headers=qsa('thead th',sourceTable);
    var directionIndex=headerIndex(headers,['yon']);
    var amountIndex=headerIndex(headers,['matrah','kdv']);
    var totalIndex=headerIndex(headers,['toplam']);
    var columnCount=Math.max(headers.length,sourceTable.tBodies[0].rows[0]?sourceTable.tBodies[0].rows[0].cells.length:headers.length,1);
    var originalWrap=sourceTable.closest('.table-wrap');
    var splitWrap=document.createElement('div');
    splitWrap.className='fatura-iki-kolon';
    splitWrap.setAttribute('data-fatura-iki-kolon','1');
    var outgoing=tableShell(sourceTable,'giden',columnCount);
    var incoming=tableShell(sourceTable,'gelen',columnCount);
    splitWrap.appendChild(outgoing.card);
    splitWrap.appendChild(incoming.card);

    var originalRows=qsa(':scope > tr',sourceTable.tBodies[0]);
    originalRows.forEach(function(row){
      if(row.classList.contains('empty')) return;
      var direction=rowDirection(row,directionIndex);
      (direction==='giden'?outgoing.tbody:incoming.tbody).appendChild(row);
    });
    originalWrap.replaceWith(splitWrap);
    markDirectionColumn(outgoing.table,directionIndex);
    markDirectionColumn(incoming.table,directionIndex);

    splitState={wrap:splitWrap,giden:outgoing,gelen:incoming,directionIndex:directionIndex,amountIndex:amountIndex,totalIndex:totalIndex,columnCount:columnCount,refreshing:false};
    var headCount=qs('.card-head > span',listCard);
    if(headCount) headCount.textContent='Giden ve gelen faturalar ayrı gösteriliyor';
    refreshSplit();

    var scheduled=false;
    var observer=new MutationObserver(function(){
      if(scheduled) return;
      scheduled=true;
      window.setTimeout(function(){scheduled=false;refreshSplit();},80);
    });
    observer.observe(outgoing.tbody,{childList:true,subtree:true,characterData:true});
    observer.observe(incoming.tbody,{childList:true,subtree:true,characterData:true});
  }
  function setupEntryModal(){
    if(editId>0) return;
    var formCard=qs('.form-grid > .form-card');
    var form=formCard?qs('#invoiceForm',formCard):null;
    if(!formCard||!form) return;
    if(qs('[data-fatura-entry-modal]')) return;

    var listCard=qsa('.form-grid > .panel-card').find(function(card){return !card.classList.contains('form-card')&&qs('table',card);});
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
    document.addEventListener('keydown',function(event){if(event.key==='Escape'&&!overlay.hidden) close();});
  }
  function addStyle(){
    if(qs('#faturaIkiKolonStyle')) return;
    var style=document.createElement('style');
    style.id='faturaIkiKolonStyle';
    style.textContent=''
      +'.fatura-iki-kolon{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;align-items:start}.fatura-yon-card{min-width:0;border:1px solid var(--border);border-radius:16px;background:#fff;overflow:hidden}.fatura-yon-giden{border-color:#c7dfcf}.fatura-yon-gelen{border-color:#ead5cf}.fatura-yon-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:13px 14px;border-bottom:1px solid var(--border)}.fatura-yon-head>div{display:grid;gap:3px}.fatura-yon-head h4{margin:0;font-size:14px}.fatura-yon-head small,.fatura-yon-head>strong{font-size:10px;color:var(--muted)}.fatura-yon-giden .fatura-yon-head{background:#f4fbf6}.fatura-yon-gelen .fatura-yon-head{background:#fff7f4}.fatura-yon-table-wrap{margin:0;border:0;border-radius:0}.fatura-yon-table{width:100%;min-width:900px}.fatura-yon-table th,.fatura-yon-table td{font-size:9px;padding:7px}.fatura-yon-gizli-kolon{display:none!important}.fatura-yon-total-row td{padding:10px!important;border-top:2px solid #dfd5c6;background:#fbf8f2}.fatura-yon-totals{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.fatura-yon-totals span{display:grid;gap:3px;padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:#fff}.fatura-yon-totals small{font-size:8px;color:var(--muted);font-weight:800}.fatura-yon-totals strong{font-size:11px}.fatura-entry-open{margin-left:auto;white-space:nowrap}.fatura-entry-overlay[hidden]{display:none!important}.fatura-entry-overlay{position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:20px;background:rgba(14,24,37,.62);backdrop-filter:blur(3px)}.fatura-entry-dialog{position:relative;width:min(920px,96vw);max-height:92vh;overflow:auto;border-radius:20px;background:#f8f6f1;box-shadow:0 30px 90px rgba(0,0,0,.3);padding:18px}.fatura-entry-close{position:absolute;right:12px;top:10px;z-index:2;width:34px;height:34px;border:0;border-radius:50%;background:#eee6d8;color:#503f25;font-size:23px;cursor:pointer}.fatura-entry-note{display:grid;gap:4px;padding:3px 44px 14px 3px}.fatura-entry-note strong{font-size:17px}.fatura-entry-note small{font-size:11px;color:var(--muted)}.fatura-entry-modal-card{width:100%!important;max-width:none!important;margin:0!important}.fatura-entry-modal-card>.card-head{display:none}.fatura-entry-modal-open{overflow:hidden}'
      +'@media(max-width:1180px){.fatura-iki-kolon{grid-template-columns:1fr}.fatura-yon-table{min-width:980px}}'
      +'@media(max-width:650px){.fatura-entry-overlay{padding:8px}.fatura-entry-dialog{width:100%;max-height:96vh;padding:12px;border-radius:15px}.fatura-yon-totals{grid-template-columns:1fr}.fatura-entry-open{width:100%;margin-top:8px}}';
    document.head.appendChild(style);
  }
  function start(){
    addStyle();
    setupEntryModal();
    buildSplit();
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',function(){window.setTimeout(start,250);});
  else window.setTimeout(start,250);
})();
