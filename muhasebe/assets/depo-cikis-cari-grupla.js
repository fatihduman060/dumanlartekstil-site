(function(){
  'use strict';
  if(!/\/depo-cikis\.php$/i.test(location.pathname)) return;

  function norm(value){
    return String(value||'').trim().replace(/\s+/g,' ').toLocaleUpperCase('tr-TR');
  }

  function findListTable(){
    var tables=document.querySelectorAll('.wd-card .wd-table table');
    for(var i=0;i<tables.length;i++){
      var heads=Array.prototype.map.call(tables[i].querySelectorAll('thead th'),function(th){return (th.textContent||'').trim();});
      if(heads.indexOf('Firma')!==-1 && heads.indexOf('Durum')!==-1 && heads.indexOf('İşlemler')!==-1) return tables[i];
    }
    return null;
  }

  function run(){
    var table=findListTable();
    if(!table || table.dataset.cariGrouped==='1') return;
    var tbody=table.querySelector('tbody');
    if(!tbody) return;

    var rows=Array.prototype.slice.call(tbody.querySelectorAll(':scope > tr'));
    var realRows=rows.filter(function(row){return row.children.length>=6;});
    if(!realRows.length) return;

    var openRows=[];
    var groups={};
    var order=[];

    realRows.forEach(function(row){
      var cells=row.children;
      var status=(cells[3].textContent||'').replace(/\s+/g,' ').trim();
      var customer=(cells[1].textContent||'').replace(/\s+/g,' ').trim()||'Cari seçilmemiş';
      if(!/Cariye işlendi/i.test(status)){
        openRows.push(row);
        return;
      }
      var key=norm(customer);
      if(!groups[key]){
        groups[key]={name:customer,rows:[]};
        order.push(key);
      }
      groups[key].rows.push(row);
    });

    // Cariye işlenmiş kayıt yoksa sayfaya müdahale etme.
    if(!order.length) return;

    table.dataset.cariGrouped='1';
    tbody.innerHTML='';

    if(openRows.length){
      var activeHead=document.createElement('tr');
      activeHead.className='wd-group-section-title';
      activeHead.innerHTML='<td colspan="6"><strong>Aktif / cariye işlenmemiş fişler</strong><small>'+openRows.length+' kayıt</small></td>';
      tbody.appendChild(activeHead);
      openRows.forEach(function(row){tbody.appendChild(row);});
    }

    var archivedHead=document.createElement('tr');
    archivedHead.className='wd-group-section-title wd-group-archived-title';
    archivedHead.innerHTML='<td colspan="6"><strong>Cariye işlenmiş fişler</strong><small>Müşteriye göre kapalı gösteriliyor</small></td>';
    tbody.appendChild(archivedHead);

    order.forEach(function(key,index){
      var group=groups[key];
      var groupId='wd-cari-grup-'+index;
      var header=document.createElement('tr');
      header.className='wd-cari-group-head';
      header.setAttribute('data-group',groupId);
      header.setAttribute('aria-expanded','false');
      header.innerHTML='<td colspan="6"><button type="button" class="wd-cari-group-toggle"><span class="wd-cari-group-arrow">▶</span><strong>'+escapeHtml(group.name)+'</strong><small>'+group.rows.length+' fiş · açmak için tıkla</small></button></td>';
      tbody.appendChild(header);

      group.rows.forEach(function(row){
        row.classList.add('wd-cari-group-row');
        row.setAttribute('data-group-row',groupId);
        row.hidden=true;
        tbody.appendChild(row);
      });
    });

    tbody.addEventListener('click',function(event){
      var button=event.target.closest('.wd-cari-group-toggle');
      if(!button) return;
      var header=button.closest('.wd-cari-group-head');
      var groupId=header?header.getAttribute('data-group'):'';
      if(!groupId) return;
      var expanded=header.getAttribute('aria-expanded')==='true';
      header.setAttribute('aria-expanded',expanded?'false':'true');
      tbody.querySelectorAll('[data-group-row="'+groupId+'"]') .forEach(function(row){row.hidden=expanded;});
      var arrow=button.querySelector('.wd-cari-group-arrow');
      var small=button.querySelector('small');
      if(arrow) arrow.textContent=expanded?'▶':'▼';
      if(small){
        var count=tbody.querySelectorAll('[data-group-row="'+groupId+'"]').length;
        small.textContent=count+' fiş · '+(expanded?'açmak için tıkla':'kapatmak için tıkla');
      }
    });

    addStyles();
  }

  function escapeHtml(value){
    return String(value==null?'':value).replace(/[&<>\"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[c];});
  }

  function addStyles(){
    if(document.getElementById('wdCariGroupStyle')) return;
    var style=document.createElement('style');
    style.id='wdCariGroupStyle';
    style.textContent=''
      +'.wd-group-section-title td{background:#f7f1e7!important;padding:10px 12px!important;border-bottom:1px solid #e5dccf!important}.wd-group-section-title strong{color:#173f29}.wd-group-section-title small{display:block;margin-top:2px;color:#776f64;font-size:11px}.wd-group-archived-title td{background:#eef5f0!important}'
      +'.wd-cari-group-head td{padding:0!important;background:#fff!important;border-bottom:1px solid #e5dccf!important}.wd-cari-group-toggle{display:grid;grid-template-columns:auto 1fr auto;gap:9px;align-items:center;width:100%;border:0;background:#fbfaf7;color:#173f29;text-align:left;padding:11px 13px;cursor:pointer}.wd-cari-group-toggle:hover{background:#f4f8f5}.wd-cari-group-toggle strong{font-size:13px}.wd-cari-group-toggle small{color:#776f64;font-size:11px;font-weight:750}.wd-cari-group-arrow{width:18px;color:#216b39;font-size:11px}.wd-cari-group-head[aria-expanded="true"] .wd-cari-group-toggle{background:#edf7f0}.wd-cari-group-row[hidden]{display:none!important}'
      +'@media(max-width:700px){.wd-cari-group-toggle{grid-template-columns:auto 1fr}.wd-cari-group-toggle small{grid-column:2}}';
    document.head.appendChild(style);
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',run); else run();
})();
