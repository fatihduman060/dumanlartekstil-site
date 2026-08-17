(function(){
  if(!/\/magaza\.php$/i.test(location.pathname||'')) return;

  function money(value){
    return Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' TL';
  }

  function trDateToIso(value){
    var parts=String(value||'').trim().split('.');
    if(parts.length!==3) return '';
    return parts[2]+'-'+parts[1]+'-'+parts[0];
  }

  function setLabel(container){
    if(!container) return;
    var strong=container.querySelector('strong');
    if(!strong) return;
    Array.prototype.slice.call(container.childNodes).forEach(function(node){
      if(node.nodeType===3 && String(node.nodeValue||'').trim()) node.nodeValue='Nakit toplam ';
    });
  }

  function apply(){
    var rows={};
    document.querySelectorAll('[data-magaza-odeme-row]').forEach(function(row){
      var date=String(row.getAttribute('data-date')||'');
      if(!date) return;
      rows[date]={
        cash:Number(row.getAttribute('data-cash')||0),
        cashCollection:Number(row.getAttribute('data-cash-collection')||0)
      };
    });

    var latest=document.querySelector('[data-magaza-mobile-latest]');
    if(latest){
      var latestDateNode=latest.querySelector('[data-magaza-latest-date]');
      var latestCash=latest.querySelector('[data-magaza-latest-cash]');
      var latestDate=trDateToIso(latestDateNode?latestDateNode.textContent:'');
      var data=rows[latestDate];
      if(data&&latestCash){
        latestCash.textContent=money(data.cash+data.cashCollection);
        setLabel(latestCash.parentElement);
      }
    }

    document.querySelectorAll('.magaza-mobile-payment-card').forEach(function(card){
      var dateNode=card.querySelector('.magaza-mobile-payment-head strong');
      var date=trDateToIso(dateNode?dateNode.textContent:'');
      var data=rows[date];
      var cashBox=card.querySelector('.magaza-mobile-payment-breakdown span:first-child');
      var cashStrong=cashBox?cashBox.querySelector('strong'):null;
      if(!data||!cashStrong) return;
      cashStrong.textContent=money(data.cash+data.cashCollection);
      setLabel(cashBox);
    });
  }

  var queued=false;
  function queue(){
    if(queued) return;
    queued=true;
    window.setTimeout(function(){queued=false;apply();},40);
  }

  document.addEventListener('bitke:magaza-odeme-updated',queue);
  if(document.body) new MutationObserver(queue).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['data-cash','data-cash-collection']});
  window.addEventListener('load',function(){window.setTimeout(apply,150);window.setTimeout(apply,700);});
  window.setTimeout(apply,100);
})();
