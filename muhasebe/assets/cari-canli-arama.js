(function(){
  'use strict';

  if(!/\/cariler\.php$/i.test(location.pathname)) return;

  var form=document.querySelector('form.filterbar');
  var input=form ? form.querySelector('input[name="q"]') : null;
  var typeSelect=form ? form.querySelector('select[name="type"]') : null;
  var table=document.querySelector('.cari-mobile-table');
  var tbody=table ? table.querySelector('tbody') : null;
  if(!form || !input || !tbody) return;

  var rawRows=Array.prototype.slice.call(tbody.querySelectorAll('tr')).filter(function(row){
    return !!row.querySelector('.cari-primary a[href*="cari-detay.php?id="]');
  });
  if(!rawRows.length) return;

  input.removeAttribute('list');
  input.setAttribute('autocomplete','off');
  input.setAttribute('aria-autocomplete','list');
  input.setAttribute('aria-expanded','false');

  var wrap=document.createElement('div');
  wrap.className='cari-live-search-wrap';
  input.parentNode.insertBefore(wrap,input);
  wrap.appendChild(input);

  var suggestions=document.createElement('div');
  suggestions.className='cari-live-suggestions';
  suggestions.setAttribute('role','listbox');
  suggestions.hidden=true;
  wrap.appendChild(suggestions);

  var style=document.createElement('style');
  style.textContent=''
    +'.cari-live-search-wrap{position:relative;min-width:0}.cari-live-search-wrap>input{width:100%}'
    +'.cari-live-suggestions{position:absolute;z-index:900;left:0;right:0;top:calc(100% + 6px);display:grid;gap:3px;padding:6px;background:#fff;border:1px solid #ded3c5;border-radius:14px;box-shadow:0 18px 45px rgba(20,35,24,.16);max-height:330px;overflow:auto}'
    +'.cari-live-suggestions[hidden]{display:none!important}.cari-live-suggestion{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;border:0;border-radius:10px;background:#fff;padding:10px 11px;text-align:left;cursor:pointer;color:#173c27}.cari-live-suggestion:hover,.cari-live-suggestion.is-active{background:#eaf5ee}.cari-live-suggestion strong{display:block;font-size:13px}.cari-live-suggestion small{display:block;margin-top:3px;color:#7a7165;font-size:10px}.cari-live-suggestion>span:last-child{font-size:11px;font-weight:900;color:#2b6544}'
    +'.cari-search-best td{background:#eef8f1!important}.cari-search-best td:first-child{box-shadow:inset 4px 0 0 #2b7a4b}.cari-search-best .cari-primary a strong{color:#174b2e}'
    +'@media(max-width:720px){.cari-live-suggestions{position:fixed;left:14px;right:14px;top:150px;max-height:55vh}.cari-live-suggestion{padding:12px}}';
  document.head.appendChild(style);

  function normalize(value){
    return String(value||'')
      .replace(/[Çç]/g,'c').replace(/[Ğğ]/g,'g').replace(/[İIıi]/g,'i')
      .replace(/[Öö]/g,'o').replace(/[Şş]/g,'s').replace(/[Üü]/g,'u')
      .replace(/[ÂâÄä]/g,'a').replace(/[ÎîÏï]/g,'i').replace(/[Ûû]/g,'u')
      .replace(/[ÈÉÊËèéêë]/g,'e').replace(/[Ôô]/g,'o')
      .toLowerCase().replace(/[^a-z0-9]+/g,' ').replace(/\s+/g,' ').trim();
  }

  function getType(row){
    var text=(row.querySelector('.cari-primary small')||{}).textContent||'';
    return /kişi/i.test(text) ? 'Kişi' : (/firma/i.test(text) ? 'Firma' : '');
  }

  var rows=rawRows.map(function(row,index){
    var link=row.querySelector('.cari-primary a[href*="cari-detay.php?id="]');
    var name=(link ? link.textContent : '').replace(/\s+/g,' ').trim();
    var meta=(row.textContent||'').replace(/\s+/g,' ').trim();
    var cityNode=row.querySelector('.cari-meta small');
    var city=cityNode ? cityNode.textContent.trim() : '';
    return {
      row:row,
      link:link,
      name:name,
      nameNorm:normalize(name),
      searchNorm:normalize(meta),
      city:city,
      type:getType(row),
      originalIndex:index,
      score:0
    };
  });

  var activeResults=[];
  var activeIndex=0;
  var countNode=document.querySelector('.cari-list-head .card-head span, .cari-list-head>div>span');

  function score(item,query){
    if(!query) return 1;
    var name=item.nameNorm;
    var all=item.searchNorm;
    var tokens=query.split(' ').filter(Boolean);
    var total=0;

    if(name===query) total+=1200;
    if(name.indexOf(query)===0) total+=900;
    if((' '+name).indexOf(' '+query)>=0) total+=700;
    if(name.indexOf(query)>=0) total+=550;

    var allTokens=true;
    tokens.forEach(function(token){
      if(name.indexOf(token)===0) total+=180;
      else if((' '+name).indexOf(' '+token)>=0) total+=150;
      else if(name.indexOf(token)>=0) total+=110;
      else if(all.indexOf(token)>=0) total+=55;
      else allTokens=false;
    });

    return allTokens ? total : 0;
  }

  function renderSuggestions(){
    suggestions.innerHTML='';
    var query=normalize(input.value);
    if(!query || !activeResults.length){
      suggestions.hidden=true;
      input.setAttribute('aria-expanded','false');
      return;
    }

    activeResults.slice(0,6).forEach(function(item,index){
      var button=document.createElement('button');
      button.type='button';
      button.className='cari-live-suggestion'+(index===activeIndex?' is-active':'');
      button.setAttribute('role','option');
      button.setAttribute('aria-selected',index===activeIndex?'true':'false');
      button.innerHTML='<span><strong></strong><small></small></span><span>Enter ↵</span>';
      button.querySelector('strong').textContent=item.name;
      button.querySelector('small').textContent=[item.type,item.city].filter(Boolean).join(' · ');
      button.addEventListener('mousedown',function(event){event.preventDefault();});
      button.addEventListener('click',function(){location.href=item.link.href;});
      suggestions.appendChild(button);
    });
    suggestions.hidden=false;
    input.setAttribute('aria-expanded','true');
  }

  function refresh(){
    var query=normalize(input.value);
    var selectedType=typeSelect ? String(typeSelect.value||'') : '';

    activeResults=[];
    rows.forEach(function(item){
      var typeOk=!selectedType || item.type===selectedType;
      item.score=typeOk ? score(item,query) : 0;
      item.row.hidden=item.score<=0;
      item.row.classList.remove('cari-search-best');
      if(item.score>0) activeResults.push(item);
    });

    activeResults.sort(function(a,b){
      if(query && b.score!==a.score) return b.score-a.score;
      return a.name.localeCompare(b.name,'tr',{sensitivity:'base'});
    });

    activeResults.forEach(function(item){tbody.appendChild(item.row);});
    rows.filter(function(item){return item.score<=0;}).sort(function(a,b){return a.originalIndex-b.originalIndex;}).forEach(function(item){tbody.appendChild(item.row);});

    if(activeResults.length){
      activeResults[0].row.classList.add('cari-search-best');
    }

    activeIndex=0;
    if(countNode) countNode.textContent=activeResults.length+' kayıt';
    renderSuggestions();
  }

  input.addEventListener('input',refresh);
  input.addEventListener('focus',refresh);

  if(typeSelect){
    typeSelect.addEventListener('change',refresh);
  }

  input.addEventListener('keydown',function(event){
    if(event.key==='ArrowDown' && activeResults.length){
      event.preventDefault();
      activeIndex=Math.min(activeIndex+1,Math.min(activeResults.length,6)-1);
      renderSuggestions();
      return;
    }
    if(event.key==='ArrowUp' && activeResults.length){
      event.preventDefault();
      activeIndex=Math.max(activeIndex-1,0);
      renderSuggestions();
      return;
    }
    if(event.key==='Escape'){
      suggestions.hidden=true;
      input.setAttribute('aria-expanded','false');
      return;
    }
    if(event.key==='Enter' && normalize(input.value) && activeResults.length){
      event.preventDefault();
      var selected=activeResults[Math.min(activeIndex,activeResults.length-1)] || activeResults[0];
      if(selected && selected.link) location.href=selected.link.href;
    }
  });

  form.addEventListener('submit',function(event){
    if(normalize(input.value) && activeResults.length){
      event.preventDefault();
      var selected=activeResults[Math.min(activeIndex,activeResults.length-1)] || activeResults[0];
      if(selected && selected.link) location.href=selected.link.href;
    }
  });

  document.addEventListener('click',function(event){
    if(!wrap.contains(event.target)){
      suggestions.hidden=true;
      input.setAttribute('aria-expanded','false');
    }
  });

  refresh();
})();
