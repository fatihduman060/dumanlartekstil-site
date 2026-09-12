(function(){
  var root=document.querySelector('[data-pos-root]');if(!root)return;
  var terminal=crypto.randomUUID(),snapshot=null,timer=null,busy=false,dirty=false;
  function schedule(){dirty=true;clearTimeout(timer);timer=setTimeout(send,250);}
  function send(){
    if(busy||!dirty||!snapshot)return;
    dirty=false;busy=true;
    var body=new FormData();body.set('action','sync_live_cart');body.set('csrf_token',root.dataset.csrf);
    body.set('terminal_id',terminal);body.set('items_json',JSON.stringify(snapshot.items));
    body.set('discount_amount',String(snapshot.discount_amount));body.set('state',snapshot.state||'open');
    fetch(root.dataset.api,{method:'POST',body:body,credentials:'same-origin',cache:'no-store'})
      .then(function(r){if(!r.ok)throw new Error();return r.json();})
      .then(function(data){if(!data.ok)throw new Error();})
      .catch(function(){dirty=true;})
      .finally(function(){busy=false;if(dirty){clearTimeout(timer);timer=setTimeout(send,1500);}});
  }
  root.addEventListener('pos:cart-changed',function(event){snapshot=event.detail;schedule();});
  root.addEventListener('pos:sale-completed',function(){snapshot={items:[],discount_amount:0,state:'completed'};schedule();});
  setInterval(function(){if(snapshot)schedule();},10000);
})();
