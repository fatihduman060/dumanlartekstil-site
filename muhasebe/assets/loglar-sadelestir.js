(function(){
  if(!/\/loglar\.php$/i.test(location.pathname)) return;

  var table=document.querySelector('.audit-table-human table');
  if(!table) return;

  var labelMap={
    'Type':'Tür',
    'Currency':'Para birimi',
    'Date':'Tarih',
    'Cari Id':'Cari numarası',
    'Reminder Status':'Hatırlatma durumu',
    'Is Check Adjustment':'Çek düzeltmesi',
    'Is Cancelled':'İptal edildi',
    'Cancel Reason':'İptal nedeni'
  };

  function translateLabels(root){
    root.querySelectorAll('strong').forEach(function(el){
      var text=String(el.textContent||'').trim().replace(/:$/,'');
      if(labelMap[text]) el.textContent=labelMap[text]+':';
    });
  }

  var headers=Array.from(table.querySelectorAll('thead th'));
  var beforeIndex=headers.findIndex(function(th){return th.textContent.trim()==='Önce';});
  var afterIndex=headers.findIndex(function(th){return th.textContent.trim()==='Sonra';});

  if(beforeIndex>=0&&afterIndex>=0){
    Array.from(table.rows).forEach(function(row){
      if(row.cells[afterIndex]) row.deleteCell(afterIndex);
      if(row.cells[beforeIndex]) row.deleteCell(beforeIndex);
    });
  }

  table.querySelectorAll('tbody tr').forEach(function(row){
    var actionCell=row.cells[3];
    var changeCell=row.cells[4];
    if(!actionCell||!changeCell) return;

    var action=String(actionCell.textContent||'').trim();
    var lines=Array.from(changeCell.querySelectorAll('.audit-change-line'));

    translateLabels(changeCell);

    if(action==='Eklendi'&&lines.length){
      var box=document.createElement('div');
      box.className='audit-simple-card is-added';
      box.innerHTML='<strong>Yeni kayıt eklendi</strong>';
      var list=document.createElement('div');
      list.className='audit-simple-list';
      lines.forEach(function(line){
        var label=line.querySelector('strong');
        var values=line.querySelectorAll('span');
        if(!label||!values.length) return;
        var item=document.createElement('div');
        item.innerHTML='<b>'+label.textContent+'</b> <span>'+values[values.length-1].textContent+'</span>';
        list.appendChild(item);
      });
      box.appendChild(list);
      changeCell.innerHTML='';
      changeCell.appendChild(box);
    }

    if((action==='Silindi'||action==='İptal edildi')&&lines.length){
      var deleted=document.createElement('div');
      deleted.className='audit-simple-card is-deleted';
      deleted.innerHTML='<strong>Kayıt silindi</strong>';
      var deletedList=document.createElement('div');
      deletedList.className='audit-simple-list';
      lines.forEach(function(line){
        var label=line.querySelector('strong');
        var values=line.querySelectorAll('span');
        if(!label||!values.length) return;
        var item=document.createElement('div');
        item.innerHTML='<b>'+label.textContent+'</b> <span>'+values[0].textContent+'</span>';
        deletedList.appendChild(item);
      });
      deleted.appendChild(deletedList);
      changeCell.innerHTML='';
      changeCell.appendChild(deleted);
    }
  });

  table.style.minWidth='980px';
  var style=document.createElement('style');
  style.textContent=''
    +'.audit-table-human table{min-width:980px!important}'
    +'.audit-change-cell{min-width:390px!important;max-width:620px!important}'
    +'.audit-simple-card{display:grid;gap:7px;padding:9px 11px;border-radius:10px;background:#f4f7f3;border:1px solid #dfe9df}'
    +'.audit-simple-card>strong{color:#173e2b;font-size:11px}'
    +'.audit-simple-card.is-deleted{background:#fff1ef;border-color:#f1d5d0}'
    +'.audit-simple-list{display:grid;grid-template-columns:repeat(2,minmax(150px,1fr));gap:5px 12px}'
    +'.audit-simple-list div{display:flex;justify-content:space-between;gap:8px;padding-bottom:3px;border-bottom:1px dashed #ddd;font-size:10px}'
    +'.audit-simple-list b{font-weight:700;color:#594a36}.audit-simple-list span{font-weight:800;text-align:right}'
    +'.audit-change-line{grid-template-columns:minmax(130px,auto) minmax(100px,1fr) 18px minmax(100px,1fr)!important}'
    +'@media(max-width:760px){.audit-simple-list{grid-template-columns:1fr}.audit-table-human table{min-width:900px!important}}';
  document.head.appendChild(style);
})();
