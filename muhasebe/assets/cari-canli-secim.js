(function(){
  'use strict';

  if(!/\/cariler\.php$/i.test(location.pathname)) return;

  function normalize(value){
    return String(value||'')
      .toLocaleLowerCase('tr-TR')
      .replace(/[çÇ]/g,'c').replace(/[ğĞ]/g,'g').replace(/[ıİI]/g,'i')
      .replace(/[öÖ]/g,'o').replace(/[şŞ]/g,'s').replace(/[üÜ]/g,'u')
      .replace(/[^a-z0-9]+/g,' ')
      .trim();
  }

  function init(){
    var form=document.querySelector('form.filterbar');
    if(!form) return;
    var input=form.querySelector('input[name="q"]');
    if(!input||input.dataset.liveCariReady==='1') return;
    input.dataset.liveCariReady='1';

    var direct=new URLSearchParams(location.search).get('direct');
    if(direct==='1'){
      var resultRows=Array.prototype.filter.call(document.querySelectorAll('.cari-mobile-table tbody tr'),function(row){
        return !!row.querySelector('.cari-primary a[href*="cari-detay.php?id="]');
      });
      if(resultRows.length===1){
        var onlyLink=resultRows[0].querySelector('.cari-primary a[href*="cari-detay.php?id="]');
        if(onlyLink){ location.replace(onlyLink.href); return; }
      }
    }

    var listId=input.getAttribute('list');
    var nativeList=listId?document.getElementById(listId):null;
    var raw=[];
    if(nativeList){
      Array.prototype.forEach.call(nativeList.querySelectorAll('option'),function(option){
        var name=String(option.value||'').trim();
        if(!name) return;
        raw.push({name:name,meta:String(option.textContent||'').trim(),norm:normalize(name)});
      });
      input.removeAttribute('list');
    }

    var linkByName={};
    Array.prototype.forEach.call(document.querySelectorAll('.cari-mobile-table tbody .cari-primary a[href*="cari-detay.php?id="]'),function(link){
      var name=String(link.textContent||'').trim();
      if(name) linkByName[normalize(name)]=link.href;
    });

    var seen={};
    raw=raw.filter(function(item){
      var key=item.norm;
      if(!key||seen[key]) return false;
      seen[key]=1;
      return true;
    });

    var wrap=document.createElement('div');
    wrap.className='cari-live-wrap';
    input.parentNode.insertBefore(wrap,input);
    wrap.appendChild(input);

    var box=document.createElement('div');
    box.className='cari-live-box';
    box.hidden=true;
    wrap.appendChild(box);

    var style=document.createElement('style');
    style.textContent=''
      +'.cari-live-wrap{position:relative;min-width:0;width:100%}.cari-live-wrap>input{width:100%;box-sizing:border-box}'
      +'.cari-live-box{position:absolute;left:0;right:0;top:calc(100% + 5px);z-index:1300;background:#fff;border:1px solid #d9d1c5;border-radius:12px;box-shadow:0 14px 34px rgba(18,35,25,.18);max-height:320px;overflow:auto;padding:5px}'
      +'.cari-live-box[hidden]{display:none!important}.cari-live-item{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;border:0;background:#fff;color:#15251b;text-align:left;padding:10px 11px;border-radius:9px;cursor:pointer;font:inherit}'
      +'.cari-live-item:hover,.cari-live-item.is-active{background:#f2eee7}.cari-live-item strong{display:block;font-size:14px}.cari-live-item small{color:#817568;font-size:11px;white-space:nowrap}'
      +'@media(max-width:720px){.cari-live-box{max-height:260px}.cari-live-item{padding:12px 10px}}';
    document.head.appendChild(style);

    var results=[];
    var active=-1;

    function score(item,q){
      if(item.norm===q) return 1000;
      if(item.norm.indexOf(q)===0) return 700;
      if((' '+item.norm).indexOf(' '+q)>=0) return 500;
      if(item.norm.indexOf(q)>=0) return 300;
      var tokens=q.split(/\s+/).filter(Boolean);
      if(tokens.length&&tokens.every(function(t){return item.norm.indexOf(t)>=0;})) return 200;
      return 0;
    }

    function render(){
      var q=normalize(input.value);
      box.innerHTML='';
      results=[];
      active=-1;
      if(!q){ box.hidden=true; return; }

      results=raw.map(function(item){return {item:item,score:score(item,q)};})
        .filter(function(row){return row.score>0;})
        .sort(function(a,b){return b.score-a.score||a.item.name.localeCompare(b.item.name,'tr');})
        .slice(0,8)
        .map(function(row){return row.item;});

      if(!results.length){ box.hidden=true; return; }

      results.forEach(function(item,index){
        var button=document.createElement('button');
        button.type='button';
        button.className='cari-live-item';
        button.innerHTML='<strong></strong><small></small>';
        button.querySelector('strong').textContent=item.name;
        button.querySelector('small').textContent=item.meta||'';
        button.addEventListener('mousedown',function(event){event.preventDefault();});
        button.addEventListener('click',function(){go(item);});
        button.addEventListener('mouseenter',function(){setActive(index);});
        box.appendChild(button);
      });
      box.hidden=false;
    }

    function setActive(index){
      if(!results.length) return;
      if(index<0) index=results.length-1;
      if(index>=results.length) index=0;
      active=index;
      Array.prototype.forEach.call(box.children,function(child,i){child.classList.toggle('is-active',i===active);});
      var el=box.children[active];
      if(el&&el.scrollIntoView) el.scrollIntoView({block:'nearest'});
    }

    function go(item){
      if(!item) return;
      input.value=item.name;
      box.hidden=true;
      var url=linkByName[item.norm];
      if(url){ location.href=url; return; }
      var hidden=form.querySelector('input[name="direct"]');
      if(!hidden){
        hidden=document.createElement('input');
        hidden.type='hidden'; hidden.name='direct'; hidden.value='1';
        form.appendChild(hidden);
      }else hidden.value='1';
      form.submit();
    }

    input.addEventListener('input',render);
    input.addEventListener('focus',render);
    input.addEventListener('keydown',function(event){
      if(event.key==='ArrowDown'){
        if(box.hidden) render();
        if(results.length){event.preventDefault();setActive(active+1);}
      }else if(event.key==='ArrowUp'){
        if(results.length){event.preventDefault();setActive(active-1);}
      }else if(event.key==='Enter'){
        if(!box.hidden&&results.length){
          event.preventDefault();
          go(results[active>=0?active:0]);
        }
      }else if(event.key==='Escape'){
        box.hidden=true; active=-1;
      }
    });

    document.addEventListener('click',function(event){if(!wrap.contains(event.target)) box.hidden=true;});
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init);
  else init();
})();
