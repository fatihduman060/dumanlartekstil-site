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

  function invoiceMeta(invoiceNo){
    var value=norm(invoiceNo);
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

  function shortSerial(meta){
    if(!meta) return '';
    var text=String(meta.serialText||'').replace(/^0+/,'');
    return text||'0';
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
      return {row:row,index:index,invoiceNo:invoiceNo,key:norm(invoiceNo),meta:invoiceMeta(invoiceNo)};
    });

    entries.sort(function(a,b){
      if(a.meta&&b.meta){
        if(a.meta.group!==b.meta.group) return b.meta.group.localeCompare(a.meta.group,'tr');
        if(a.meta.serial>b.meta.serial) return -1;
        if(a.meta.serial<b.meta.serial) return 1;
      }else if(a.meta){
        return -1;
      }else if(b.meta){
        return 1;
      }
      return a.index-b.index;
    });
    entries.forEach(function(entry){tbody.appendChild(entry.row);});

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
      if(!entry.meta||!entry.meta.group) return;
      if(!groups[entry.meta.group]) groups[entry.meta.group]=[];
      groups[entry.meta.group].push(entry.meta);
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

    var latest=entries.find(function(entry){return entry.meta;});
    var latestText=latest?latest.invoiceNo:'Numara okunamadı';
    var duplicateText=duplicateKeys.length
      ? duplicateKeys.map(function(key){return key+' ('+counts[key]+' kayıt)';}).join(', ')
      : 'Tekrar eden fatura numarası yok';
    var missingText=missing.length
      ? missing.map(function(item){return item.serial.replace(/^0+/,'')||'0';}).join(', ')
      : 'Görünen sıra içinde eksik numara yok';

    panel.innerHTML=''
      +'<div><strong>Fatura sıra kontrolü</strong><small>Liste artık fatura numarasına göre büyükten küçüğe sıralanıyor.</small></div>'
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
    +'.fatura-sira-kontrol{display:grid;grid-template-columns:minmax(180px,.45fr) minmax(0,1.55fr);gap:14px;align-items:center;margin:10px 0 14px;padding:12px 14px;border:1px solid #d8dfd9;background:#f4faf6;border-radius:14px}.fatura-sira-kontrol.has-danger{border-color:#e7b4ae;background:#fff5f3}.fatura-sira-kontrol>div:first-child{display:grid;gap:3px}.fatura-sira-kontrol strong{font-size:13px}.fatura-sira-kontrol small{font-size:10px;color:var(--muted)}.fatura-sira-metrics{display:flex;gap:8px;flex-wrap:wrap}.fatura-sira-metrics span{border:1px solid var(--border);background:#fff;border-radius:10px;padding:7px 9px;font-size:10px}.fatura-sira-metrics span.is-danger{border-color:#e3a49e;background:#fff0ee;color:#8f2922}.fatura-sira-metrics span.is-warning{border-color:#ebcb8b;background:#fff8e8;color:#79530e}.fatura-sira-badge{display:inline-flex;margin-top:5px;padding:3px 6px;border-radius:999px;font-size:9px;font-weight:900}.fatura-sira-badge.is-danger{background:#ffe4e1;color:#922f28}.fatura-tekrar-row{box-shadow:inset 4px 0 0 #c94c43}@media(max-width:850px){.fatura-sira-kontrol{grid-template-columns:1fr}}';
  document.head.appendChild(style);

  setup();
})();
