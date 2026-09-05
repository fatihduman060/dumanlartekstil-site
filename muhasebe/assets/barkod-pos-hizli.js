(function(){
  if(!document.querySelector('link[href*="barkod-pos-hizli.css"]')){
    var style=document.createElement('link');
    style.rel='stylesheet';style.href='assets/barkod-pos-hizli.css?v=4';
    document.head.appendChild(style);
  }
  var root=document.querySelector('[data-pos-root]');
  if(!root)return;
  var modal=root.querySelector('[data-pos-payment-modal]');
  var completeBtn=root.querySelector('[data-pos-complete]');
  if(!modal||!completeBtn)return;
  // Keep the original checkout handler: choose payment in the dialog, then confirm.
  document.addEventListener('keydown',function(event){
    if(event.altKey||event.ctrlKey||event.metaKey)return;
    if(event.key==='F9'){
      event.preventDefault();
      if(modal.hidden)completeBtn.click();
      return;
    }
    if(modal.hidden)return;
    var value={F6:'cash',F7:'card',F8:'credit'}[event.key];
    if(!value)return;
    event.preventDefault();
    var input=modal.querySelector('input[name="pos_payment"][value="'+value+'"]');
    if(input){input.checked=true;input.dispatchEvent(new Event('change',{bubbles:true}));}
    if(value==='credit'){
      var person=modal.querySelector('[data-pos-person]');
      if(person)person.focus();
    }
  });
})();
