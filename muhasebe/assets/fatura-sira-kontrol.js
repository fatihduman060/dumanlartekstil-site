(function(){
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

  function compareEntries(a,b,direction){
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

  function sortRows(entries,tbody,direction){
    entries.sort(function(a,b){return compareEntries(a,b,direction);});
    entries.forEach(function(entry){tbody.appendChild(entry.row);});
  }

  function setup(){
    var table=document.querySelector('.table-wrap table');
    if(!table) return;
    var tbody=table.querySelector('tbody');
    if(!tbody) return;

    var rows=Array.from(tbody.querySelectorAll('tr')).filter(function(row){
      return row.children.length>=8&&!row.querySelector('.empty');
    });
    if(!rows.length) return;

    var entries=rows.map(function(row,index){
      var invoiceNo=invoiceNoFromRow(row);
      var fileName=fileNameFromRow(row);
      return {
        row:row,
        index:index,
        invoiceNo:invoiceNo,
        fileName:fileName,
        key:norm(invoiceNo),
        invoiceMeta:invoiceMeta(invoiceNo),
        fileMeta:invoiceMeta(fileName)
      };
    });

    // Sayfa ilk açıldığında en yüksek dosya/fatura numarası üstte olsun.
    sortRows(entries,tbody,'desc');

    var headers=table.querySelectorAll('thead th');
    var fileHeader=headers&&headers.length>5?headers[5]:null;
    if(fileHeader&&!fileHeader.querySelector('[data-dosya-sirala]')){
      fileHeader.innerHTML='<button type="button" class="fatura-dosya-sirala" data-dosya-sirala title="Dosya numarasına göre en yüksekten en düşüğe sırala">DOSYA <span aria-hidden="true">↓</span></button>';
      fileHeader.addEventListener('click',function(event){
        var button=event.target.closest('[data-dosya-sirala]');
        if(!button) return;
        sortRows(entries,tbody,'desc');
        button.classList.add('is-active');
        button.querySelector('span').textContent='↓';
        button.title='En yüksek dosya numarası üstte';
      });
    }

    var counts={};
    entries.forEach(function(entry){
      if(!entry.key) return;
      counts[entry.key]=(counts[entry.key]||0)+1;
    });

    var duplicateKeys=Object.keys(counts).filter(function(key){return counts[key]>1;});
    entries.forEach(function(entry){
      if(entry.key&&counts[entry.key]>1){
        addBadge(entry.row,'Tekrar ×'+counts[entry.key],'danger');
        entry.row.classList.add('fatura-tekrar-row');
      }
    });

    var groups={};
    entries.forEach(function(entry){
      var meta=entry.fileMeta||entry.invoiceMeta;
      if(!meta||!meta.group) return;
      if(!groups[meta.group]) groups[meta.group]=[];
      groups[meta.group].push(meta);
    });

    var missing=[];
    Object.keys(groups).forEach(function(group){
      var metas=groups[group];
      var unique={};
      metas.forEach(function(meta){unique[meta.serial.toString()]=meta;});
      var serials=Object.keys(unique).map(function(value){return BigInt(value);}).sort(function(a,b){return a<b?-1:(a>b?1:0);});
      if(serials.length<2) return;
      var min=serials[0];
      var max=serials[serials.length-1];
      if(max-min>1000n) return;
      for(var current=min;current<=max;current++){
        if(!unique[current.toString()]){
          missing.push({group:group,serial:current.toString()});
          if(missing.length>=50) return;
        }
      }
    });

    var section=table.closest('.panel-card');
    if(!section||section.querySelector('[data-fatura-sira-kontrol]')) return;
    var filter=section.querySelector('.filterbar');
    var panel=document.createElement('div');
    panel.setAttribute('data-fatura-sira-kontrol','1');
    panel.className='fatura-sira-kontrol '+(duplicateKeys.length?'has-danger':'is-ok');

    var latest=entries.find(function(entry){return entry.fileMeta||entry.invoiceMeta;});
    var latestText=latest?(latest.fileName||latest.invoiceNo):'Numara okunamadı';
    var duplicateText=duplicateKeys.length
      ? duplicateKeys.map(function(key){return key+' ('+counts[key]+' kayıt)';}).join(', ')
      : 'Tekrar eden fatura numarası yok';
    var missingText=missing.length
      ? missing.map(function(item){return item.serial.replace(/^0+/,'')||'0';}).join(', ')
      : 'Görünen sıra içinde eksik numara yok';

    panel.innerHTML=''
      +'<div><strong>Fatura sıra kontrolü</strong><small>DOSYA başlığına basınca en yüksek sıra numarası üste gelir.</small></div>'
      +'<div class="fatura-sira-metrics">'
      +'<span><b>En son:</b> '+latestText+'</span>'
      +'<span class="'+(duplicateKeys.length?'is-danger':'')+'"><b>Tekrar:</b> '+duplicateText+'</span>'
      +'<span class="'+(missing.length?'is-warning':'')+'"><b>Eksik sıra:</b> '+missingText+'</span>'
      +'</div>';

    if(filter) filter.insertAdjacentElement('afterend',panel);
    else section.insertAdjacentElement('afterbegin',panel);
  }

  var style=document.createElement('style');
  style.textContent=''
    +'.fatura-sira-kontrol{display:grid;grid-template-columns:minmax(180px,.45fr) minmax(0,1.55fr);gap:14px;align-items:center;margin:10px 0 14px;padding:12px 14px;border:1px solid #d8dfd9;background:#f4faf6;border-radius:14px}.fatura-sira-kontrol.has-danger{border-color:#e7b4ae;background:#fff5f3}.fatura-sira-kontrol>div:first-child{display:grid;gap:3px}.fatura-sira-kontrol strong{font-size:13px}.fatura-sira-kontrol small{font-size:10px;color:var(--muted)}.fatura-sira-metrics{display:flex;gap:8px;flex-wrap:wrap}.fatura-sira-metrics span{border:1px solid var(--border);background:#fff;border-radius:10px;padding:7px 9px;font-size:10px}.fatura-sira-metrics span.is-danger{border-color:#e3a49e;background:#fff0ee;color:#8f2922}.fatura-sira-metrics span.is-warning{border-color:#ebcb8b;background:#fff8e8;color:#79530e}.fatura-sira-badge{display:inline-flex;margin-top:5px;padding:3px 6px;border-radius:999px;font-size:9px;font-weight:900}.fatura-sira-badge.is-danger{background:#ffe4e1;color:#922f28}.fatura-tekrar-row{box-shadow:inset 4px 0 0 #c94c43}.fatura-dosya-sirala{appearance:none;border:0;background:transparent;color:inherit;font:inherit;font-weight:900;letter-spacing:inherit;padding:5px 7px;margin:-5px -7px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:5px}.fatura-dosya-sirala:hover,.fatura-dosya-sirala.is-active{background:#eee7da;color:#554322}.fatura-dosya-sirala span{font-size:13px}@media(max-width:850px){.fatura-sira-kontrol{grid-template-columns:1fr}}';
  document.head.appendChild(style);

  setup();
})();
