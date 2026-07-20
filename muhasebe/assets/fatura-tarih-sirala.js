(function(){
  'use strict';
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  var direction='desc';

  function findTable(){
    return Array.from(document.querySelectorAll('.table-wrap table')).find(function(table){
      var header=table.querySelector('thead th:first-child');
      return header&&/TAR[Iİ]H\s*\/\s*NO/i.test(String(header.textContent||''));
    })||null;
  }

  function dateKey(row){
    var cell=row.children&&row.children[0];
    var text=String(cell?cell.textContent:'');
    var match=text.match(/(\d{1,2})\.(\d{1,2})\.(\d{4})/);
    if(!match) return 0;
    return Number(match[3])*10000+Number(match[2])*100+Number(match[1]);
  }

  function recordId(row){
    var idInput=row.querySelector('form input[name="id"]');
    if(idInput) return Number(idInput.value||0);
    var edit=row.querySelector('a[href*="edit="]');
    if(edit){
      try{return Number(new URL(edit.href,location.href).searchParams.get('edit')||0);}catch(error){}
    }
    return 0;
  }

  function sortTable(){
    var table=findTable();
    if(!table) return;
    var tbody=table.querySelector('tbody');
    if(!tbody) return;

    var rows=Array.from(tbody.children).filter(function(row){
      return row.tagName==='TR'&&!row.querySelector('.empty');
    });
    var factor=direction==='desc'?-1:1;
    rows.sort(function(a,b){
      var dateDiff=dateKey(a)-dateKey(b);
      if(dateDiff!==0) return dateDiff*factor;
      return (recordId(a)-recordId(b))*factor;
    });
    rows.forEach(function(row){tbody.appendChild(row);});

    var header=table.querySelector('thead th:first-child');
    if(!header) return;
    var button=header.querySelector('[data-fatura-tarih-sirala]');
    if(!button){
      header.innerHTML='<button type="button" class="fatura-tarih-sirala" data-fatura-tarih-sirala><span>TARİH / NO</span><b aria-hidden="true">↓</b></button>';
      button=header.querySelector('[data-fatura-tarih-sirala]');
      button.addEventListener('click',function(){
        direction=direction==='desc'?'asc':'desc';
        sortTable();
      });
    }
    header.setAttribute('aria-sort',direction==='desc'?'descending':'ascending');
    button.querySelector('b').textContent=direction==='desc'?'↓':'↑';
    button.title=direction==='desc'?'En yeni fatura üstte':'En eski fatura üstte';
  }

  function init(){
    sortTable();
    [100,350,800,1600].forEach(function(delay){setTimeout(sortTable,delay);});
  }

  var style=document.createElement('style');
  style.textContent='.fatura-tarih-sirala{appearance:none;border:0;background:transparent;color:inherit;font:inherit;font-weight:900;padding:5px 7px;margin:-5px -7px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:6px}.fatura-tarih-sirala:hover{background:rgba(255,255,255,.16)}.fatura-tarih-sirala b{font-size:13px}';
  document.head.appendChild(style);

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
