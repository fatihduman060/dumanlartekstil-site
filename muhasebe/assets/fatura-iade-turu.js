(function(){
  if(!/\/faturalar\.php$/i.test(location.pathname)) return;

  var iadeIds={};

  function periodValue(){
    var input=document.querySelector('input[type="month"][name="period"]');
    var value=input?String(input.value||''):'';
    if(!/^\d{4}-\d{2}$/.test(value)) value=new URLSearchParams(location.search).get('period')||'';
    return /^\d{4}-\d{2}$/.test(value)?value:new Date().toISOString().slice(0,7);
  }

  function ensureOption(select){
    if(!select||select.querySelector('option[value="iade"]')) return;
    var option=document.createElement('option');
    option.value='iade';
    option.textContent='İade Faturası';
    var first=select.querySelector('option');
    if(first) first.insertAdjacentElement('afterend',option); else select.appendChild(option);
  }

  function applySelect(select){
    ensureOption(select);
    var id=Number(select.getAttribute('data-fatura-tur-auto-select')||0);
    if(id&&iadeIds[id]) select.value='iade';
  }

  function applyAll(){
    document.querySelectorAll('select[data-fatura-tur-auto-select]').forEach(applySelect);
  }

  function applyPayload(data){
    var items=Array.isArray(data&&data.items)?data.items:[];
    iadeIds={};
    items.forEach(function(item){
      if(String(item.category||'')==='iade') iadeIds[Number(item.id||0)]=true;
    });
    applyAll();
  }

  document.addEventListener('bitke:fatura-meta-updated',function(event){
    applyPayload(event.detail||{});
  });

  var observer=new MutationObserver(function(){applyAll();});
  observer.observe(document.documentElement,{childList:true,subtree:true});

  fetch('fatura-tur.php?period='+encodeURIComponent(periodValue())+'&_='+Date.now(),{
    credentials:'same-origin',cache:'no-store'
  })
    .then(function(response){return response.json();})
    .then(function(data){if(data&&data.ok) applyPayload(data);})
    .catch(function(){applyAll();});
})();
