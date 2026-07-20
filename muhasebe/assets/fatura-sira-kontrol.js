(function(){
  'use strict';
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  function norm(value){
    return String(value||'').toLocaleUpperCase('tr-TR').replace(/\s+/g,'').trim();
  }

  function invoiceNoFromRow(row){
    var cell=row.children&&row.children[0];
    if(!cell) return '';
    var small=cell.querySelector('small');
    if(!small) return '';
    return String(small.textContent||'').split('·')[0].trim();
  }

  function invoiceDateFromRow(row){
    var cell=row.children&&row.children[0];
    if(!cell) return 0;
    var strong=cell.querySelector('strong');
    var text=String(strong?strong.textContent:'').trim();
    var match=text.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
    if(!match) return 0;
    return Number(match[3])*10000+Number(match[2])*100+Number(match[1]);
  }

  function fileNameFromRow(row){
    var cell=row.children&&row.children[5];
    if(!cell) return '';
    var link=cell.querySelector('a');
    return String(link?link.textContent:cell.textContent||'').trim();
  }

  function invoiceMeta(value){
    value=norm(value).replace(/\.(PDF|XML|XSLT?)$/i,'');
    var match=value.match(/^([A-Z]{2,8})(\d{4})(\d+)$/);
    if(!match){
      var trailing=value.match(/^(.*?)(\d+)$/);
      if(!trailing) return null;
      return {full:value,group:trailing[1],year:'',serialText:trailing[2],serial:BigInt(trailing[2])};
    }
    return {
      full:value,
      group:match[1]+match[2],
      year:match[2],
      serialText:match[3],
      serial:BigInt(match[3])
    };
  }

  function addBadge(row,text,tone){
    var cell=row.children&&row.children[0];
    if(!cell||cell.querySelector('[data-fatura-sira-badge]')) return;
    var badge=document.createElement('span');
    badge.setAttribute('data-fatura-sira-badge','1');
    badge.className='fatura-sira-badge is-'+tone;
    badge.textContent=text;
    cell.appendChild(badge);
  }

  function compareFileEntries(a,b,direction){
    var aMeta=a.fileMeta||a.invoiceMeta;
    var bMeta=b.fileMeta||b.invoiceMeta;
    var factor=direction==='asc'?1:-1;

    if(aMeta&&bMeta){
      if(aMeta.group!==bMeta.group) return aMeta.group.localeCompare(bMeta.group,'tr')*factor;
      if(aMeta.serial>bMeta.serial) return 1*factor;
      if(aMeta.serial<bMeta.serial) return -1*factor;
    }else if(aMeta){
      return -1;
    }else if(bMeta){
      return 1;
    }
    return a.index-b.index;
  }

  function compareDateEntries(a,b,direction){
    var factor=direction==='asc'?1:-1;
    if(a.dateKey!==b.dateKey) return (a.dateKey-b.dateKey)*factor;
    return a.index-b.index;
  }

  function appendSorted(entries,tbody,comparator){
    entries.slice().sort(comparator).forEach(function(entry){tbody.appendChild(entry.row);});
  }

  function setup(){
    var table=document.querySelector('.table-wrap table');
    if(!table||table.dataset.dateSortReady==='1') return;
    var tbody=table.querySelector('tbody');
    if(!tbody) return;

    var rows=Array.from(tbody.querySelectorAll('tr')).filter(function(row){
      return row.children.length>=8&&!row.querySelector('.empty');
    });
    if(!rows.length) return;

    table.dataset.dateSortReady='1';
    var entries=rows.map(function(row,index){
      var invoiceNo=invoiceNoFromRow(row);
      var fileName=fileNameFromRow(row);
      return {
        row:row,
        index:index,
        dateKey:invoiceDateFromRow(row),
        invoiceNo:invoiceNo,
        fileName:fileName,
        key:norm(invoiceNo),
        invoiceMeta:invoiceMeta(invoiceNo),
        fileMeta:invoiceMeta(fileName)
      };
    });

    var headers=table.querySelectorAll('thead th');
    var dateHeader=headers&&headers.length?headers[0]:null;
    var fileHeader=headers&&headers.length>5?headers[5]:null;
    var dateDirection='desc';
    var fileDirection='desc';

    function activateDateSort(){
      appendSorted(entries,tbody,function(a,b){return compareDateEntries(a,b,dateDirection);});
      if(dateHeader){
        dateHeader.setAttribute('aria-sort',dateDirection==='desc'?'descending':'ascending');
        var button=dateHeader.querySelector('[data-tarih-sirala]');
        if(button){
          button.classList.add('is-active');
          button.querySelector('span').textContent=dateDirection==='desc'?'↓':'↑';
          button.title=dateDirection==='desc'?'En yeni fatura üstte':'En eski fatura üstte';
        }
      }
      if(fileHeader){
        fileHeader.removeAttribute('aria-sort');
        var fileButton=fileHeader.querySelector('[data-dosya-sirala]');
        if(fileButton) fileButton.classList.remove('is-active');
      }
    }

    if(dateHeader&&!dateHeader.querySelector('[data-tarih-sirala]')){
      dateHeader.innerHTML='<button type="button" class="fatura-sirala-button" data-tarih-sirala title="En yeni fatura üstte">TARİH / NO <span aria-hidden="true">↓</span></button>';
      dateHeader.addEventListener('click',function(event){
        var button=event.target.closest('[data-tarih-sirala]');
        if(!button) return;
        dateDirection=dateDirection==='desc'?'asc':'desc';
        activateDateSort();
      });
    }

    if(fileHeader&&!fileHeader.querySelector('[data-dosya-sirala]')){
      fileHeader.innerHTML='<button type="button" class="fatura-sirala-button" data-dosya-sirala title="Dosya numarasına göre sırala">DOSYA <span aria-hidden="true">↕</span></button>';
      fileHeader.addEventListener('click',function(event){
        var button=event.target.closest('[data-dosya-sirala]');
        if(!button) return;
        appendSorted(entries,tbody,function(a,b){return compareFileEntries(a,b,fileDirection);});
        fileHeader.setAttribute('aria-sort',fileDirection==='desc'?'descending':'ascending');
        button.classList.add('is-active');
        button.querySelector('span').textContent=fileDirection==='desc'?'↓':'↑';
        button.title=fileDirection==='desc'?'En yüksek dosya numarası üstte':'En düşük dosya numarası üstte';
        fileDirection=fileDirection==='desc'?'asc':'desc';
        if(dateHeader){
          dateHeader.removeAttribute('aria-sort');
          var dateButton=dateHeader.querySelector('[data-tarih-sirala]');
          if(dateButton) dateButton.classList.remove('is-active');
        }
      });
    }

    // Sayfa ilk açıldığında her zaman en yeni fatura üstte olur.
    activateDateSort();

    var counts={};
    entries.forEach(function(entry){
      if(!entry.key) return;
      counts[entry.key]=(counts[entry.key]||0)+1;
    });

    entries.forEach(function(entry){
      if(entry.key&&counts[entry.key]>1){
        addBadge(entry.row,'Tekrar ×'+counts[entry.key],'danger');
        entry.row.classList.add('fatura-tekrar-row');
      }
    });
  }

  var style=document.createElement('style');
  style.textContent=''
    +'.fatura-sira-badge{display:inline-flex;margin-top:5px;padding:3px 6px;border-radius:999px;font-size:9px;font-weight:900}.fatura-sira-badge.is-danger{background:#ffe4e1;color:#922f28}.fatura-tekrar-row{box-shadow:inset 4px 0 0 #c94c43}.fatura-sirala-button{appearance:none;border:0;background:transparent;color:inherit;font:inherit;font-weight:900;letter-spacing:inherit;padding:5px 7px;margin:-5px -7px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:5px}.fatura-sirala-button:hover,.fatura-sirala-button.is-active{background:rgba(255,255,255,.16);color:inherit}.fatura-sirala-button span{font-size:13px}';
  document.head.appendChild(style);

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',setup);
  else setup();
})();
