(function(){
  'use strict';
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  var sortDirection='desc';
  var observer=null;
  var sortTimer=0;

  function invoiceTable(){
    var tables=Array.from(document.querySelectorAll('.table-wrap table'));
    return tables.find(function(table){
      var first=table.querySelector('thead th');
      return first&&/TAR[Iİ]H\s*\/\s*NO/i.test(String(first.textContent||''));
    })||null;
  }

  function invoiceIdFromRow(row){
    var input=row.querySelector('form input[name="id"]');
    if(input&&Number(input.value||0)>0) return Number(input.value);
    var edit=row.querySelector('a[href*="edit="]');
    if(edit){
      try{return Number(new URL(edit.href,location.href).searchParams.get('edit')||0);}catch(error){}
    }
    return 0;
  }

  function dateKeyFromRow(row){
    var saved=String(row.getAttribute('data-invoice-date')||'').trim();
    var iso=saved.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
    if(iso) return Number(iso[1])*10000+Number(iso[2])*100+Number(iso[3]);

    var first=row.children&&row.children[0];
    var text=String(first?first.textContent:'').trim();
    var tr=text.match(/(\d{1,2})\.(\d{1,2})\.(\d{4})/);
    if(tr) return Number(tr[3])*10000+Number(tr[2])*100+Number(tr[1]);

    iso=text.match(/(\d{4})-(\d{1,2})-(\d{1,2})/);
    if(iso) return Number(iso[1])*10000+Number(iso[2])*100+Number(iso[3]);
    return 0;
  }

  function invoiceNoFromRow(row){
    var first=row.children&&row.children[0];
    if(!first) return '';
    var small=first.querySelector('small');
    return String(small?small.textContent:'').split('·')[0].trim();
  }

  function addDuplicateBadges(table){
    var rows=Array.from(table.querySelectorAll('tbody tr')).filter(function(row){
      return row.children.length>1&&!row.querySelector('.empty');
    });
    var counts={};
    rows.forEach(function(row){
      var key=invoiceNoFromRow(row).toLocaleUpperCase('tr-TR').replace(/\s+/g,'').trim();
      if(key) counts[key]=(counts[key]||0)+1;
    });
    rows.forEach(function(row){
      var cell=row.children[0];
      var key=invoiceNoFromRow(row).toLocaleUpperCase('tr-TR').replace(/\s+/g,'').trim();
      var old=cell&&cell.querySelector('[data-fatura-sira-badge]');
      if(key&&counts[key]>1){
        if(!old){
          old=document.createElement('span');
          old.setAttribute('data-fatura-sira-badge','1');
          old.className='fatura-sira-badge is-danger';
          cell.appendChild(old);
        }
        old.textContent='Tekrar ×'+counts[key];
        row.classList.add('fatura-tekrar-row');
      }else{
        if(old) old.remove();
        row.classList.remove('fatura-tekrar-row');
      }
    });
  }

  function updateHeader(table){
    var header=table.querySelector('thead th');
    if(!header) return;
    var button=header.querySelector('[data-tarih-sirala]');
    if(!button){
      header.innerHTML='<button type="button" class="fatura-sirala-button" data-tarih-sirala><span class="label">TARİH / NO</span> <span class="arrow" aria-hidden="true">↓</span></button>';
      button=header.querySelector('[data-tarih-sirala]');
      button.addEventListener('click',function(event){
        event.preventDefault();
        sortDirection=sortDirection==='desc'?'asc':'desc';
        sortRows(true);
      });
    }
    header.setAttribute('aria-sort',sortDirection==='desc'?'descending':'ascending');
    var arrow=button.querySelector('.arrow');
    if(arrow) arrow.textContent=sortDirection==='desc'?'↓':'↑';
    button.title=sortDirection==='desc'?'En yeni fatura üstte':'En eski fatura üstte';
    button.classList.add('is-active');
  }

  function observe(table){
    if(observer) observer.disconnect();
    observer=new MutationObserver(function(mutations){
      var needsSort=mutations.some(function(mutation){
        return mutation.type==='childList'||mutation.type==='attributes';
      });
      if(!needsSort) return;
      clearTimeout(sortTimer);
      sortTimer=setTimeout(function(){sortRows(false);},40);
    });
    observer.observe(table,{childList:true,subtree:true,attributes:true,attributeFilter:['hidden','data-invoice-date']});
  }

  function sortRows(userTriggered){
    var table=invoiceTable();
    if(!table) return;
    var tbody=table.querySelector('tbody');
    if(!tbody) return;

    var rows=Array.from(tbody.children).filter(function(row){
      return row.tagName==='TR'&&row.children.length>1&&!row.querySelector('.empty');
    });
    if(!rows.length){updateHeader(table);return;}

    if(observer) observer.disconnect();

    var factor=sortDirection==='desc'?-1:1;
    rows.sort(function(a,b){
      var dateA=dateKeyFromRow(a);
      var dateB=dateKeyFromRow(b);
      if(dateA!==dateB) return (dateA-dateB)*factor;
      var idA=invoiceIdFromRow(a);
      var idB=invoiceIdFromRow(b);
      if(idA!==idB) return (idA-idB)*factor;
      return 0;
    });

    var fragment=document.createDocumentFragment();
    rows.forEach(function(row){fragment.appendChild(row);});
    tbody.appendChild(fragment);

    updateHeader(table);
    addDuplicateBadges(table);
    observe(table);

    if(userTriggered) table.scrollIntoView({block:'nearest'});
  }

  function setup(){
    var table=invoiceTable();
    if(!table) return;
    sortRows(false);
    [100,400,1000,2200,4500].forEach(function(delay){
      setTimeout(function(){sortRows(false);},delay);
    });
  }

  var style=document.createElement('style');
  style.textContent=''
    +'.fatura-sira-badge{display:inline-flex;margin-top:5px;padding:3px 6px;border-radius:999px;font-size:9px;font-weight:900}.fatura-sira-badge.is-danger{background:#ffe4e1;color:#922f28}.fatura-tekrar-row{box-shadow:inset 4px 0 0 #c94c43}.fatura-sirala-button{appearance:none;border:0;background:transparent;color:inherit;font:inherit;font-weight:900;letter-spacing:inherit;padding:5px 7px;margin:-5px -7px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:5px}.fatura-sirala-button:hover,.fatura-sirala-button.is-active{background:rgba(255,255,255,.16);color:inherit}.fatura-sirala-button .arrow{font-size:13px}';
  document.head.appendChild(style);

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',setup);
  else setup();
})();
