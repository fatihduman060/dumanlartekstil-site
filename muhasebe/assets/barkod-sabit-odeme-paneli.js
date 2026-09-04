(function(){
  var root=document.querySelector('[data-pos-root]');
  if(!root) return;
  var panel=root.querySelector('.pos-checkout');
  if(!panel) return;

  var desktopMin=981;
  var anchor=document.querySelector('[data-pos-checkout-viewport-anchor]');
  if(!anchor){
    anchor=document.createElement('div');
    anchor.setAttribute('data-pos-checkout-viewport-anchor','1');
    anchor.style.minWidth='0';
    anchor.style.width='100%';
    anchor.style.alignSelf='start';

    var oldPlaceholder=root.querySelector('.pos-checkout-fixed-placeholder');
    if(oldPlaceholder){
      oldPlaceholder.parentNode.insertBefore(anchor,oldPlaceholder);
      oldPlaceholder.remove();
    }else if(panel.parentNode){
      panel.parentNode.insertBefore(anchor,panel);
    }
  }

  function clearInline(){
    panel.classList.remove('pos-checkout-fixed');
    panel.style.position='';
    panel.style.top='';
    panel.style.left='';
    panel.style.right='';
    panel.style.width='';
    panel.style.maxHeight='';
    panel.style.height='';
    panel.style.overflowY='';
    panel.style.zIndex='';
    panel.style.margin='';
    panel.style.transform='';
    panel.style.transition='';
    panel.style.boxSizing='';
  }

  function mobileMode(){
    clearInline();
    if(panel.parentNode!==root){
      anchor.parentNode.insertBefore(panel,anchor.nextSibling);
    }
    anchor.style.display='none';
  }

  function desktopMode(){
    if(window.innerWidth<desktopMin){
      mobileMode();
      return;
    }

    anchor.style.display='block';

    // Panel griddeyken/önceki sabitlemeden kalan konumdan gerçek kolon ölçüsünü al.
    var rect=anchor.getBoundingClientRect();
    if(rect.width<220){
      var rootRect=root.getBoundingClientRect();
      var computedWidth=Math.min(340,Math.max(290,window.innerWidth-rootRect.right+340));
      rect={left:Math.max(12,rootRect.right-computedWidth),width:computedWidth,top:rootRect.top};
    }

    if(panel.parentNode!==document.body){
      document.body.appendChild(panel);
    }

    var top=Math.max(12,Math.min(rect.top,80));
    var width=Math.max(290,Math.min(340,rect.width||320));
    var left=Math.min(window.innerWidth-width-12,Math.max(12,rect.left));

    panel.classList.add('pos-checkout-fixed');
    panel.style.setProperty('position','fixed','important');
    panel.style.setProperty('top',top+'px','important');
    panel.style.setProperty('left',left+'px','important');
    panel.style.setProperty('right','auto','important');
    panel.style.setProperty('width',width+'px','important');
    panel.style.setProperty('max-height','calc(100vh - '+Math.ceil(top+12)+'px)','important');
    panel.style.setProperty('overflow-y','auto','important');
    panel.style.setProperty('z-index','10020','important');
    panel.style.setProperty('margin','0','important');
    panel.style.setProperty('transform','none','important');
    panel.style.setProperty('transition','none','important');
    panel.style.setProperty('box-sizing','border-box','important');

    // Ana grid sağ kolonu panel body'ye taşınsa bile boş kalmasın.
    anchor.style.height=Math.max(panel.scrollHeight,panel.offsetHeight,1)+'px';
  }

  function sync(){
    window.requestAnimationFrame(desktopMode);
  }

  // Önceki sabitleme kodu çalıştıktan sonra bu modül son sözü söyler.
  window.requestAnimationFrame(function(){
    window.requestAnimationFrame(desktopMode);
  });
  window.addEventListener('resize',sync,{passive:true});
  window.addEventListener('orientationchange',sync,{passive:true});

  // İçerik değiştiğinde boyunu güncelle; konumu scroll'a göre değiştirme.
  if(window.ResizeObserver){
    var ro=new ResizeObserver(function(){
      if(window.innerWidth>=desktopMin && panel.parentNode===document.body){
        anchor.style.height=Math.max(panel.scrollHeight,panel.offsetHeight,1)+'px';
      }
    });
    ro.observe(panel);
  }
})();
