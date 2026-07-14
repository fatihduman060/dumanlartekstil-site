(function(){
  function invoiceNoFromFileName(fileName){
    if(window.FaturaOkumaCore) return window.FaturaOkumaCore.invoiceNoFromFileName(fileName);
    var name=String(fileName||'').toUpperCase().replace(/\.(PDF|XML|XSLT?)$/i,'');
    var matches=name.match(/[A-Z0-9]{2,8}20\d{2}\d{5,20}/g)||[];
    return matches.length?matches[0]:'';
  }

  function looksInvalid(value){
    var compact=String(value||'').toUpperCase().replace(/[^A-Z0-9]/g,'');
    if(!compact) return true;
    if(/^(TICARETSICILNO|TICARETSICILNUMARASI|ETTN|UUID|VKN|TCKN|MERSISNO)$/.test(compact)) return true;
    if(/^\d{10,11}$/.test(compact)) return true;
    return window.FaturaOkumaCore?!window.FaturaOkumaCore.isValidInvoiceNo(compact):false;
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
      if(fromFile&&looksInvalid(invoiceNo.value)&&invoiceNo.value!==fromFile){
        invoiceNo.value=fromFile;
        invoiceNo.dispatchEvent(new Event('input',{bubbles:true}));
      }
    }

    fileInput.addEventListener('change',apply);
    invoiceNo.addEventListener('input',function(){if(looksInvalid(invoiceNo.value)) setTimeout(apply,0);});
    document.addEventListener('click',function(event){
      if(event.target.closest('[data-fatura-pdf-oku]')) setTimeout(apply,0);
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
        if(fromFile&&looksInvalid(input.value)&&input.value!==fromFile){
          input.value=fromFile;
          input.dispatchEvent(new Event('input',{bubbles:true}));
        }
      });
    }

    var observer=new MutationObserver(repair);
    observer.observe(rows,{childList:true,subtree:true});
    rows.addEventListener('input',function(event){
      if(event.target&&event.target.getAttribute('data-field')==='invoice_no'&&looksInvalid(event.target.value)) setTimeout(repair,0);
    });
    repair();
  }

  fixSingle();
  fixBulk();
})();
