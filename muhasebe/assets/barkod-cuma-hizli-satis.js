(function(){
  var root=document.querySelector('[data-pos-root]');
  if(!root) return;
  var normalButton=root.querySelector('[data-pos-complete]');
  if(!normalButton||root.querySelector('[data-friday-fast-sale]')) return;

  var style=document.createElement('style');
  style.textContent=''
    +'.pos-friday-fast-sale{display:grid;gap:5px;margin:8px 0 10px}'
    +'.pos-friday-fast-sale[hidden]{display:none!important}'
    +'.pos-friday-fast-sale button{min-height:52px;font-size:15px;font-weight:950;background:#174f34;color:#fff;border:0;border-radius:14px;cursor:pointer}'
    +'.pos-friday-fast-sale button:disabled{opacity:.65;cursor:wait}'
    +'.pos-friday-fast-sale small{text-align:center;font-size:10px;font-weight:800;color:#7d6f61}'
    +'@media(max-width:680px){.pos-friday-fast-sale button{min-height:50px;font-size:14px}}';
  document.head.appendChild(style);

  var wrap=document.createElement('div');
  wrap.className='pos-friday-fast-sale';
  wrap.setAttribute('data-friday-fast-sale','1');
  wrap.hidden=true;
  wrap.innerHTML='<button type="button" data-friday-fast-complete>⚡ Satışı Tamamla</button><small>Cuma 12:00–14:00 hızlı mod · fiş yazdırmaz</small>';
  normalButton.insertAdjacentElement('beforebegin',wrap);

  var fastButton=wrap.querySelector('[data-friday-fast-complete]');
  var formatter=null;
  try{
    formatter=new Intl.DateTimeFormat('en-US',{timeZone:'Europe/Istanbul',weekday:'short',hour:'2-digit',minute:'2-digit',hourCycle:'h23'});
  }catch(e){}

  function istanbulParts(){
    var now=new Date();
    if(formatter&&formatter.formatToParts){
      var values={};
      formatter.formatToParts(now).forEach(function(part){if(part.type!=='literal')values[part.type]=part.value;});
      return {weekday:values.weekday||'',hour:Number(values.hour||0),minute:Number(values.minute||0)};
    }
    return {weekday:now.getDay()===5?'Fri':'',hour:now.getHours(),minute:now.getMinutes()};
  }

  function isFastWindow(){
    var p=istanbulParts();
    var minutes=p.hour*60+p.minute;
    return p.weekday==='Fri'&&minutes>=12*60&&minutes<14*60;
  }

  function refreshVisibility(){
    wrap.hidden=!isFastWindow();
  }

  fastButton.addEventListener('click',function(){
    if(!isFastWindow()){
      refreshVisibility();
      return;
    }
    if(normalButton.disabled) return;

    fastButton.disabled=true;
    fastButton.textContent='Satış kaydediliyor…';

    // Mevcut güvenli satış akışını aynen kullan; yalnızca fiş penceresini sanal bir
    // pencereye yönlendirerek yazdırma adımını atla. Başarılı satışta mevcut kod
    // sayfayı yeniler; hata olursa aşağıdaki süre sonunda buton yeniden açılır.
    var originalOpen=window.open;
    var fakeWindow={location:{href:''},closed:false,close:function(){this.closed=true;}};
    window.open=function(){return fakeWindow;};
    try{
      normalButton.click();
    }finally{
      window.open=originalOpen;
    }

    window.setTimeout(function(){
      if(!document.body.contains(fastButton)) return;
      fastButton.disabled=false;
      fastButton.textContent='⚡ Satışı Tamamla';
    },2500);
  });

  refreshVisibility();
  window.setInterval(refreshVisibility,30000);
})();
