(function(){
  var root=document.querySelector('[data-pos-root]');
  if(!root) return;
  var main=root.querySelector('.pos-main');
  var panel=root.querySelector('.pos-checkout')||document.querySelector('.pos-checkout');
  if(!main||!panel) return;

  var desktopMin=981;
  var placeholder=null;
  var resizeTimer=null;

  function clearPanelStyles(){
    panel.classList.remove('pos-checkout-fixed');
    ['position','top','left','right','width','max-height','height','overflow-y','z-index','margin','transform','transition','box-sizing'].forEach(function(name){
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

    // Önce paneli gerçek sağ kolonuna geri koy ve tüm eski sabitlemeleri temizle.
    putBackInRightColumn();

    // Tarayıcı grid yerleşimini tamamlasın.
    void panel.offsetWidth;
    var rect=panel.getBoundingClientRect();
    if(rect.width<220 || rect.left<main.getBoundingClientRect().right-5){
      // CSS geç yüklenmişse sağ kolon genişliğini ana grid üzerinden tekrar hesapla.
      var rootRect=root.getBoundingClientRect();
      var width=Math.min(340,Math.max(290,rootRect.width*0.25));
      rect={
        width:width,
        left:rootRect.right-width,
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

    // Paneli transformed/scroll edilen kapsayıcıların dışına çıkar.
    document.body.appendChild(panel);

    var top=Math.max(12,Math.min(rect.top,90));
    var width=Math.max(290,Math.min(340,rect.width));
    var right=Math.max(12,window.innerWidth-rect.right);

    panel.classList.add('pos-checkout-fixed');
    panel.style.setProperty('position','fixed','important');
    panel.style.setProperty('top',top+'px','important');
    panel.style.setProperty('right',right+'px','important');
    panel.style.setProperty('left','auto','important');
    panel.style.setProperty('width',width+'px','important');
    panel.style.setProperty('max-height','calc(100vh - '+Math.ceil(top+12)+'px)','important');
    panel.style.setProperty('overflow-y','auto','important');
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

  // Son çalışan modül olarak önceki sabitleme girişimlerini temizleyip doğru sağ konumu uygula.
  window.requestAnimationFrame(function(){
    window.requestAnimationFrame(desktopMode);
  });

  window.addEventListener('resize',resync,{passive:true});
  window.addEventListener('orientationchange',resync,{passive:true});
})();
