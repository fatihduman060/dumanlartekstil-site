(function(){
  if(!/\/raporlar\.php$/i.test(location.pathname)) return;

  var params=new URLSearchParams(location.search);
  var year=Number(params.get('year')||new Date().getFullYear());
  if(!Number.isFinite(year)||year<2000||year>2100) year=new Date().getFullYear();

  function parseMoney(text){
    var value=String(text||'').replace(/[^0-9,.-]/g,'').trim();
    if(value.indexOf(',')!==-1) value=value.replace(/\./g,'').replace(',','.');
    var n=parseFloat(value);
    return Number.isFinite(n)?n:null;
  }
  function formatMoney(value){
    return Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2})+' TL';
  }
  function cardsByLabel(label){
    return Array.prototype.slice.call(document.querySelectorAll('.ym-card')).filter(function(card){
      var el=card.querySelector('.ym-card-label');
      return el&&el.textContent.trim()===label;
    });
  }
  function addNote(card,count){
    if(!card||card.querySelector('[data-card-report-note]')) return;
    var note=document.createElement('small');
    note.setAttribute('data-card-report-note','1');
    note.className='ym-state';
    note.textContent=count>0 ? count+' kartlı cari ödeme rapor toplamından hariç tutuldu.' : 'Kartlı cari ödemeler bu toplamda gider sayılmaz.';
    card.appendChild(note);
  }

  fetch('rapor-kartli-odeme-haric.php?year='+encodeURIComponent(year)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
    .then(function(response){return response.json();})
    .then(function(data){
      if(!data||!data.ok) return;
      var excluded=Number(data.card_payment_total||0);
      var count=Number(data.card_payment_count||0);
      if(excluded<0) excluded=0;

      cardsByLabel('Ödeme').forEach(function(card){
        var strong=card.querySelector('strong');
        var current=parseMoney(strong?strong.textContent:'');
        if(strong&&current!==null) strong.textContent=formatMoney(Math.max(0,current-excluded));
        addNote(card,count);
      });

      ['Yıllık nakit neti','Nakit akış tablosu · net akış'].forEach(function(label){
        cardsByLabel(label).forEach(function(card){
          var strong=card.querySelector('strong');
          var current=parseMoney(strong?strong.textContent:'');
          if(strong&&current!==null) strong.textContent=formatMoney(current+excluded);
          addNote(card,count);
        });
      });
    })
    .catch(function(){});
})();
