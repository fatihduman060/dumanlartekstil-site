(function(){
  var root=document.querySelector('[data-pos-root]');
  if(!root) return;
  var main=root.querySelector('.pos-main');
  var panel=root.querySelector('.pos-checkout')||document.querySelector('.pos-checkout');
  if(!main||!panel) return;

  var desktopMin=981;
  var placeholder=null;

  var style=document.createElement('style');
  style.textContent=''
    +'.pos-checkout.pos-checkout-lock{display:grid!important;gap:9px!important;padding:14px!important;position:fixed!important;top:150px!important;right:24px!important;left:auto!important;width:320px!important;height:auto!important;max-height:none!important;overflow:visible!important;overflow-y:visible!important;z-index:10020!important;margin:0!important;transform:none!important;transition:none!important;box-sizing:border-box!important}'
    +'.pos-checkout.pos-checkout-lock::-webkit-scrollbar{display:none!important}'
    +'.pos-checkout.pos-checkout-lock .pos-total-box{padding:14px 16px!important;border-radius:16px!important}'
    +'.pos-checkout.pos-checkout-lock .pos-total-box strong{font-size:29px!important;margin:5px 0!important}'
    +'.pos-checkout.pos-checkout-lock .pos-customer-summary{padding:10px 12px!important}'
    +'.pos-checkout.pos-checkout-lock>label{gap:3px!important;margin:0!important}'
    +'.pos-checkout.pos-checkout-lock>label input{min-height:38px!important;padding:7px 9px!important}'
    +'.pos-checkout.pos-checkout-lock .pos-complete{min-height:48px!important;padding:10px 12px!important}'
    +'.pos-checkout.pos-checkout-lock .pos-direct-print-setup{font-size:10px!important;line-height:1.15!important}'
    +'.pos-checkout.pos-checkout-lock>.muted{font-size:9px!important;line-height:1.2!important;margin:0!important}'
    +'.pos-checkout.pos-checkout-lock .pos-status{min-height:0!important;margin:0!important}'
    +'@media(max-width:980px){.pos-checkout.pos-checkout-lock{position:static!important;top:auto!important;right:auto!important;left:auto!important;width:auto!important;padding:15px!important;gap:14px!important}}';
  document.head.appendChild(style);

  function removeOldHelpers(){
    document.querySelectorAll('.pos-checkout-fixed-placeholder,[data-pos-checkout-viewport-anchor]').forEach(function(el){el.remove();});
  }

  function clearOldInline(){
    panel.classList.remove('pos-checkout-fixed','pos-checkout-fixed-final');
    ['position','top','left','right','width','max-height','height','overflow-y','overflow','z-index','margin','transform','transition','box-sizing','grid-column','grid-row','align-self'].forEach(function(name){
      panel.style.removeProperty(name);
    });
  }

  function restoreMobile(){
    removeOldHelpers();
    clearOldInline();
    panel.classList.remove('pos-checkout-lock');
    if(panel.parentNode!==root || panel.previousElementSibling!==main){
      root.insertBefore(panel,main.nextSibling);
    }
  }

  function lockDesktop(){
    if(window.innerWidth<desktopMin){
      restoreMobile();
      return;
    }

    removeOldHelpers();
    clearOldInline();

    if(panel.parentNode!==root || panel.previousElementSibling!==main){
      root.insertBefore(panel,main.nextSibling);
    }

    placeholder=document.createElement('div');
    placeholder.className='pos-checkout-fixed-placeholder';
    placeholder.setAttribute('aria-hidden','true');
    placeholder.style.gridColumn='2';
    placeholder.style.gridRow='1';
    placeholder.style.width='320px';
    placeholder.style.minWidth='0';
    placeholder.style.height=Math.max(panel.offsetHeight,1)+'px';
    root.insertBefore(placeholder,panel);

    document.body.appendChild(panel);
    panel.classList.add('pos-checkout-lock');
  }

  lockDesktop();
  window.addEventListener('resize',lockDesktop,{passive:true});
  window.addEventListener('orientationchange',lockDesktop,{passive:true});
})();
