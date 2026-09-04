(function(){
  var root=document.querySelector('[data-pos-root]');
  if(!root) return;
  var main=root.querySelector('.pos-main');
  var panel=root.querySelector('.pos-checkout')||document.querySelector('.pos-checkout');
  if(!main||!panel) return;

  var desktopMin=981;
  var placeholder=null;
  var resizeTimer=null;

  var style=document.createElement('style');
  style.textContent=''
    +'.pos-checkout.pos-checkout-fixed-final{overflow:visible!important;max-height:none!important;height:auto!important;overscroll-behavior:auto!important;scrollbar-width:none!important;gap:9px!important;padding:14px!important}'
    +'.pos-checkout.pos-checkout-fixed-final::-webkit-scrollbar{display:none!important}'
    +'.pos-checkout.pos-checkout-fixed-final .pos-total-box{padding:14px 16px!important;border-radius:16px!important}'
    +'.pos-checkout.pos-checkout-fixed-final .pos-total-box strong{font-size:29px!important;margin:5px 0!important}'
    +'.pos-checkout.pos-checkout-fixed-final .pos-customer-summary{padding:10px 12px!important}'
    +'.pos-checkout.pos-checkout-fixed-final>label{gap:3px!important;margin:0!important}'
    +'.pos-checkout.pos-checkout-fixed-final>label input{min-height:38px!important;padding:7px 9px!important}'
    +'.pos-checkout.pos-checkout-fixed-final .pos-complete{min-height:48px!important;padding:10px 12px!important}'
    +'.pos-checkout.pos-checkout-fixed-final .pos-direct-print-setup{font-size:10px!important;line-height:1.15!important}'
    +'.pos-checkout.pos-checkout-fixed-final>.muted{font-size:9px!important;line-height:1.25!important;margin:0!important}'
    +'.pos-checkout.pos-checkout-fixed-final .pos-status{min-height:0!important;margin:0!important}'
    +'@media(max-width:980px){.pos-checkout.pos-checkout-fixed-final{padding:15px!important;gap:14px!important}}';
  document.head.appendChild(style);

  function clearPanelStyles(){
    panel.classList.remove('pos-checkout-fixed','pos-checkout-fixed-final');
    ['position','top','left','right','width','max-height','height','overflow-y','overflow','z-index','margin','transform','transition','box-sizing','grid-column','grid-row','align-self'].forEach(function(name){
      panel.style.removeProperty(name);
    });
  }

  function removeOldPlaceholders(){
    document.querySelectorAll('.pos-checkout-fixed-placeholder,[data-pos-checkout-viewport-anchor]').forEach(function(el){
      if(el!==placeholder) el.remove();
    });
  }

  function putBackInRightColumn(){
    clearPanelStyles();
    removeOldPlaceholders();
    if(panel.parentNode!==root || panel.previousElementSibling!==main){
      root.insertBefore(panel,main.nextSibling);
    }
    panel.style.setProperty('grid-column','2');
    panel.style.setProperty('grid-row','1');
    panel.style.setProperty('align-self','start');
  }

  function mobileMode(){
    if(placeholder){
      placeholder.remove();
      placeholder=null;
    }
    putBackInRightColumn();
    panel.style.removeProperty('grid-column');
    panel.style.removeProperty('grid-row');
    panel.style.removeProperty('align-self');
  }

  function desktopMode(){
    if(window.innerWidth<desktopMin){
      mobileMode();
      return;
    }

    if(placeholder){
      placeholder.remove();
      placeholder=null;
    }

    // Her seferinde önce paneli kendi gerçek sağ kolonuna koyup orada ölç.
    putBackInRightColumn();
    void panel.offsetWidth;
    var rect=panel.getBoundingClientRect();
    var rootRect=root.getBoundingClientRect();

    if(rect.width<220 || rect.left<main.getBoundingClientRect().right-4){
      var widthFallback=Math.min(340,Math.max(290,rootRect.width*0.25));
      rect={
        width:widthFallback,
        left:rootRect.right-widthFallback,
        right:rootRect.right,
        top:rootRect.top,
        height:panel.offsetHeight
      };
    }

    placeholder=document.createElement('div');
    placeholder.className='pos-checkout-fixed-placeholder';
    placeholder.setAttribute('aria-hidden','true');
    placeholder.style.gridColumn='2';
    placeholder.style.gridRow='1';
    placeholder.style.width='100%';
    placeholder.style.minWidth='0';
    placeholder.style.height=Math.max(rect.height||panel.offsetHeight,1)+'px';
    root.insertBefore(placeholder,panel);

    // Scroll/transform olan kapsayıcıdan çıkar, ama ölçülen sağ kolon koordinatını aynen koru.
    document.body.appendChild(panel);

    var width=Math.max(290,Math.min(340,rect.width));
    var right=Math.max(12,window.innerWidth-rect.right);
    var top=Math.max(10,Math.min(rect.top,74));

    panel.classList.add('pos-checkout-fixed','pos-checkout-fixed-final');
    panel.style.setProperty('position','fixed','important');
    panel.style.setProperty('top',top+'px','important');
    panel.style.setProperty('right',right+'px','important');
    panel.style.setProperty('left','auto','important');
    panel.style.setProperty('width',width+'px','important');
    panel.style.setProperty('max-height','none','important');
    panel.style.setProperty('height','auto','important');
    panel.style.setProperty('overflow','visible','important');
    panel.style.setProperty('overflow-y','visible','important');
    panel.style.setProperty('z-index','10020','important');
    panel.style.setProperty('margin','0','important');
    panel.style.setProperty('transform','none','important');
    panel.style.setProperty('transition','none','important');
    panel.style.setProperty('box-sizing','border-box','important');
  }

  function resync(){
    clearTimeout(resizeTimer);
    resizeTimer=setTimeout(function(){
      window.requestAnimationFrame(desktopMode);
    },80);
  }

  window.requestAnimationFrame(function(){
    window.requestAnimationFrame(desktopMode);
  });
  window.addEventListener('resize',resync,{passive:true});
  window.addEventListener('orientationchange',resync,{passive:true});
})();
