(function(){
  function invoiceNoFromFileName(fileName){
    var name=String(fileName||'').toUpperCase().replace(/\.(PDF|XML|XSLT?)$/i,'');
    var match=name.match(/\b([A-Z]{2,8}[0-9]{10,30})\b/);
    return match?match[1]:'';
  }

  function looksLikeTaxNo(value){
    return /^\d{10,11}$/.test(String(value||'').replace(/\s/g,''));
  }

  function fixSingle(){
    if(!/\/faturalar\.php$/i.test(location.pathname)) return;
    var form=document.getElementById('invoiceForm');
    if(!form) return;
    var fileInput=form.querySelector('input[name="document"]');
    var invoiceNo=form.querySelector('[name="invoice_no"]');
    if(!fileInput||!invoiceNo) return;

    function apply(){
      var file=fileInput.files&&fileInput.files[0];
      if(!file) return;
      var fromFile=invoiceNoFromFileName(file.name);
      if(fromFile&&(!invoiceNo.value.trim()||looksLikeTaxNo(invoiceNo.value))){
        invoiceNo.value=fromFile;
        invoiceNo.dispatchEvent(new Event('input',{bubbles:true}));
      }
    }

    fileInput.addEventListener('change',function(){setTimeout(apply,900);});
    document.addEventListener('click',function(event){
      if(event.target.closest('[data-fatura-pdf-oku]')) setTimeout(apply,900);
    });
  }

  function fixBulk(){
    if(!/\/fatura-toplu-yukle\.php$/i.test(location.pathname)) return;
    var rows=document.getElementById('bulkInvoiceRows');
    if(!rows) return;

    function repair(){
      rows.querySelectorAll('.bulk-invoice-row').forEach(function(row){
        var fileName=(row.querySelector('.bulk-row-head strong')||{}).textContent||'';
        var input=row.querySelector('[data-field="invoice_no"]');
        if(!input) return;
        var fromFile=invoiceNoFromFileName(fileName);
        if(fromFile&&(!input.value.trim()||looksLikeTaxNo(input.value))&&input.value!==fromFile){
          input.value=fromFile;
          input.dispatchEvent(new Event('input',{bubbles:true}));
        }
      });
    }

    var observer=new MutationObserver(function(){repair();});
    observer.observe(rows,{childList:true,subtree:true});
    repair();
  }

  fixSingle();
  fixBulk();
})();
