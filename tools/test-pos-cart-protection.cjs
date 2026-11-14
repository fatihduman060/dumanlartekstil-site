// Isolated browser simulation: no live API or accounting database is opened.
const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const path=require('path');
const main=fs.readFileSync(path.join(__dirname,'../muhasebe/assets/barkod-satis.js'),'utf8');
const live=fs.readFileSync(path.join(__dirname,'../muhasebe/assets/barkod-canli-sepet.js'),'utf8');

function boot(storage){
  const timers=new Map(),requests=[],nodes=new Map(),windowEvents=new Map();let nextTimer=0;
  function element(){
    const events={};
    return {value:'',hidden:true,dataset:{},textContent:'',innerHTML:'',events,
      addEventListener(type,fn){(events[type]??=[]).push(fn);},
      dispatchEvent(event){for(const fn of events[event.type]||[])fn.call(this,event);return true;},
      querySelector(){return null;},querySelectorAll(){return [];},focus(){},select(){},setAttribute(){},
      classList:{add(){},remove(){},toggle(){}},elements:new Proxy({}, {get(o,k){return o[k]??=element();}})};
  }
  const root=element();root.dataset={api:'/mock-pos-api',csrf:'mock-only'};
  root.querySelector=selector=>{
    if(selector==='input[name="pos_payment"]:checked')return {value:'cash'};
    if(!nodes.has(selector))nodes.set(selector,element());return nodes.get(selector);
  };
  const window={crypto:{randomUUID:require('crypto').randomUUID},open(){return null;},
    addEventListener(type,fn){if(!windowEvents.has(type))windowEvents.set(type,new Set());windowEvents.get(type).add(fn);},
    removeEventListener(type,fn){windowEvents.get(type)?.delete(fn);}};
  const sessionStorage={getItem:k=>storage.get(k)??null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)};
  const localStorage={getItem:k=>storage.get(k)??null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k),get length(){return storage.size;},key:i=>Array.from(storage.keys())[i]};
  const document=element();document.querySelector=s=>s==='[data-pos-root]'?root:null;
  const context={window,document,sessionStorage,localStorage,crypto:window.crypto,navigator:{
    sendBeacon(url,body){requests.push(Object.fromEntries(body));return true;}
  },Intl,Date,FormData,
    CustomEvent:function(type,options){this.type=type;this.detail=options?.detail;},
    setTimeout(fn){const id=++nextTimer;timers.set(id,fn);return id;},
    clearTimeout(id){timers.delete(id);},setInterval(){},
    fetch(url,options){requests.push(Object.fromEntries(options.body));return Promise.resolve({ok:true,json:()=>Promise.resolve({ok:true,message:'mock',receipt_url:'mock?id=1'})});}
  };
  vm.runInNewContext(live,context);vm.runInNewContext(main,context);
  return {root,nodes,requests,storage,
    flush(){const tasks=Array.from(timers.values());timers.clear();tasks.forEach(fn=>fn());},
    add(){root.dispatchEvent({type:'pos:add-product',detail:{id:7,name:'Çorap',barcode:'7',sale_price:20,quantity:1}});},
    leave(){const event={prevented:false,preventDefault(){this.prevented=true;}};for(const fn of windowEvents.get('beforeunload')||[])fn(event);return event;},
    pagehide(){for(const fn of windowEvents.get('pagehide')||[])fn();}
  };
}

(async()=>{
  const storage=new Map([
    ['dumanlar-pos-terminal-v1','11111111-1111-4111-8111-111111111111'],
    ['dumanlar-pos-cart-v2-u0',JSON.stringify({cart_id:'22222222-2222-4222-8222-222222222222',items:[{id:7,quantity:2,sale_price:20}]})],
    ['dumanlar-pos-saved-cart-v1-u0-22222222-2222-4222-8222-222222222222',JSON.stringify({cart_id:'22222222-2222-4222-8222-222222222222',items:[{id:7,quantity:2,sale_price:20}]})]
  ]);
  const first=boot(storage);first.flush();await new Promise(setImmediate);
  assert.equal(first.nodes.get('[data-pos-total]').textContent,'0,00 TL');
  assert.equal(first.requests.length,0,'opening an empty till must not overwrite the previous live cart');
  first.add();first.flush();await new Promise(setImmediate);
  const snapshot=first.requests.find(x=>x.action==='sync_live_cart'&&JSON.parse(x.items_json).length===1);
  assert.ok(snapshot,'active cart stays visible to the live till monitor');
  assert.notEqual(snapshot.terminal_id,storage.get('dumanlar-pos-terminal-v1'));
  const warning=first.leave();assert.equal(warning.prevented,true);
  const abandoned=first.requests.find(x=>x.action==='mark_abandoned_cart');
  assert.ok(abandoned,'closing a nonempty cart still queues the abandoned-cart email');
  assert.equal(abandoned.cart_id,snapshot.cart_id);
  first.pagehide();
  const second=boot(storage);second.flush();await new Promise(setImmediate);
  assert.equal(second.nodes.get('[data-pos-total]').textContent,'0,00 TL');
  assert.equal(second.requests.length,0,'reopening must not clear the previous live snapshot');
  assert.notEqual(second.root.dataset.posTerminalId,first.root.dataset.posTerminalId);
  assert.equal(second.leave().prevented,false);
  const completed=boot(new Map());completed.add();
  completed.nodes.get('[data-pos-complete]').onclick();
  completed.nodes.get('[data-pos-payment-confirm]').onclick();
  await new Promise(setImmediate);
  assert.equal(completed.leave().prevented,false);
  assert.equal(completed.requests.filter(x=>x.action==='mark_abandoned_cart').length,0);
  console.log('PASS: no cart restoration; new till session; live snapshot and abandonment notification retained; completed sale stays clear.');
})().catch(error=>{console.error(error);process.exitCode=1;});
