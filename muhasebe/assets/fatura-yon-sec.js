(function(){
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  var state={csrf:'',canWrite:false};

  function norm(value){
    return String(value||'')
      .toLocaleUpperCase('tr-TR')
      .replace(/İ/g,'I').replace(/Ş/g,'S').replace(/Ğ/g,'G').replace(/Ü/g,'U').replace(/Ö/g,'O').replace(/Ç/g,'C')
      .replace(/[^A-Z0-9]+/g,' ')
      .replace(/\s+/g,' ')
      .trim();
  }

  function invoiceIdFromRow(row){
    var action=row.querySelector('input[name="action"][value="post_cari"]');
    var form=action?action.closest('form'):null;
    var input=form?form.querySelector('input[name="id"]'):null;
    return input?Number(input.value||0):0;
  }

  function badgeHtml(direction){
    var label=direction==='giden'?'Giden fatura':'Gelen fatura';
    var tone=direction==='giden'?'success':'danger';
    return '<span class="badge badge-'+tone+'">'+label+'</span>';
  }

  function bindRows(){
    if(!state.canWrite) return;
    document.querySelectorAll('.table-wrap table tbody tr').forEach(function(row){
      var cells=row.children;
      if(!cells||cells.length<2) return;
      var cell=cells[1];
      if(cell.querySelector('[data-fatura-yon-sec]')) return;
      var text=norm(cell.textContent);
      var current=text.indexOf('GIDEN FATURA')!==-1?'giden':(text.indexOf('GELEN FATURA')!==-1?'gelen':'');
      if(!current) return;
      var invoiceId=invoiceIdFromRow(row);
      if(!invoiceId) return;
      var target=current==='giden'?'gelen':'giden';
      var buttonLabel=target==='giden'?'Giden yap':'Gelen yap';
      cell.innerHTML='<div class="fatura-yon-cell">'+badgeHtml(current)+'<button type="button" data-fatura-yon-sec="'+invoiceId+'" data-current="'+current+'" data-target="'+target+'">'+buttonLabel+'</button></div>';
    });
  }

  document.addEventListener('click',function(event){
    var button=event.target.closest('[data-fatura-yon-sec]');
    if(!button) return;
    var invoiceId=Number(button.getAttribute('data-fatura-yon-sec')||0);
    var target=button.getAttribute('data-target');
    if(!invoiceId||!target) return;

    var label=target==='giden'?'giden (bizim kestiğimiz)':'gelen (bize kesilen)';
    if(!window.confirm('Bu fatura '+label+' olarak değiştirilsin mi?')) return;

    var oldText=button.textContent;
    button.disabled=true;
    button.textContent='Değişiyor...';

    var body=new FormData();
    body.set('csrf_token',state.csrf);
    body.set('invoice_id',String(invoiceId));
    body.set('direction',target);

    fetch('fatura-yon-sec.php',{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json();})
      .then(function(data){
        if(!data.ok) throw new Error(data.error||'Fatura yönü değiştirilemedi.');
        state.csrf=String(data.csrf_token||state.csrf);
        var cell=button.closest('td');
        if(cell){
          var nextTarget=data.direction==='giden'?'gelen':'giden';
          var nextLabel=nextTarget==='giden'?'Giden yap':'Gelen yap';
          cell.innerHTML='<div class="fatura-yon-cell">'+badgeHtml(data.direction)+'<button type="button" data-fatura-yon-sec="'+invoiceId+'" data-current="'+data.direction+'" data-target="'+nextTarget+'">'+nextLabel+'</button></div>';
        }
        window.location.reload();
      })
      .catch(function(error){window.alert(error.message||'Fatura yönü değiştirilemedi.');button.disabled=false;button.textContent=oldText;});
  });

  var style=document.createElement('style');
  style.textContent='.fatura-yon-cell{display:grid;gap:4px;justify-items:start}.fatura-yon-cell button{border:0;background:transparent;padding:0;color:#7b6745;font-size:9px;font-weight:850;text-decoration:underline;cursor:pointer}.fatura-yon-cell button:hover{color:#3f3423}.fatura-yon-cell button:disabled{opacity:.55;cursor:wait}';
  document.head.appendChild(style);

  fetch('fatura-yon-sec.php?_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
    .then(function(response){return response.json();})
    .then(function(data){
      if(!data.ok) return;
      state.csrf=String(data.csrf_token||'');
      state.canWrite=!!data.can_write;
      bindRows();
    })
    .catch(function(){});
})();
