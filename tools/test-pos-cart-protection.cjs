// Isolated browser state only: no live API or accounting database is opened.
const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const path=require('path');
const main=fs.readFileSync(path.join(__dirname,'../muhasebe/assets/barkod-satis.js'),'utf8');
const sync=fs.readFileSync(path.join(__dirname,'../muhasebe/assets/barkod-canli-sepet.js'),'utf8');
const key='dumanlar-pos-cart-v1';
function boot(storage=new Map()){
 const timers=new Map(),posted=[],nodes=new Map();let timerId=0;
 function element(){
  const events={};return {value:'',hidden:true,dataset:{},textContent:'',innerHTML:'',events,
   addEventListener(type,fn){(events[type]??=[]).push(fn);},
   dispatchEvent(event){for(const fn of events[event.type]||[])fn.call(this,event);return true;},
   querySelector(){return null;},querySelectorAll(){return [];},focus(){},select(){},setAttribute(){},
   classList:{add(){},remove(){},toggle(){}},elements:new Proxy({}, {get(o,k){return o[k]??=(element());}})};
 }
 const root=element();root.dataset={api:'/mock-pos-api',csrf:'mock-only'};
 root.querySelector=selector=>{
  if(selector==='input[name="pos_payment"]:checked')return {value:'cash'};
  if(!nodes.has(selector))nodes.set(selector,element());return nodes.get(selector);
 };
 const winEvents=new Map();
 const window={crypto:{randomUUID:require('crypto').randomUUID},sessionStorage:null,open(){return null;},
  addEventListener(type,fn){if(!winEvents.has(type))winEvents.set(type,new Set());winEvents.get(type).add(fn);},
  removeEventListener(type,fn){winEvents.get(type)?.delete(fn);}};
 const sessionStorage={getItem:k=>storage.get(k)??null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)};
 window.sessionStorage=sessionStorage;
 const document=element();document.visibilityState='visible';document.querySelector=s=>s==='[data-pos-root]'?root:null;
 const context={window,document,crypto:window.crypto,sessionStorage,Intl,Date,FormData,
  CustomEvent:function(type,options){this.type=type;this.detail=options?.detail;},
  setTimeout(fn){const id=++timerId;timers.set(id,fn);return id;},clearTimeout:id=>timers.delete(id),setInterval(){},
  fetch(url,options){const body=Object.fromEntries(options.body);posted.push(body);return Promise.resolve({ok:true,json:()=>Promise.resolve({ok:true,message:'mock sale',receipt_url:'mock?id=1'})});}};
 vm.runInNewContext(sync,context);vm.runInNewContext(main,context);
 return {storage,root,nodes,posted,window,document,
  guards:()=>winEvents.get('beforeunload')?.size||0,
  leave(){const event={prevented:false,preventDefault(){this.prevented=true;}};for(const fn of winEvents.get('beforeunload')||[])fn(event);return event;},
  add(id,price=10){root.dispatchEvent({type:'pos:add-product',detail:{id,name:'Test '+id,barcode:String(id),sale_price:price,quantity:1}});},
  flush(){const tasks=Array.from(timers.values());timers.clear();tasks.forEach(fn=>fn());}};
}
(async()=>{
 const app=boot();assert.equal(app.guards(),0);
 app.add(1,10);app.add(2,20);app.add(1,10);
 const discount=app.nodes.get('[data-pos-discount]');discount.value='5';discount.dispatchEvent({type:'input'});
 const note=app.nodes.get('[data-pos-note]');note.value='Bekleyen müşteri';note.dispatchEvent({type:'input'});
 assert.equal(app.guards(),1);assert.equal(app.nodes.get('[data-pos-cart-protection]').hidden,false);
 const warning=app.leave();assert.equal(warning.prevented,true);assert.equal(warning.returnValue,'');
 assert.equal(app.nodes.get('[data-pos-total]').textContent,'35,00 TL');
 // Prepare a request with a fake successful API. The token must survive a reload
 // while a real response could still be uncertain.
 app.nodes.get('[data-pos-payment-confirm]').onclick();
 const token=JSON.parse(app.storage.get(key)).source_token;assert.ok(token.length>=20);
 const restored=boot(new Map(app.storage));assert.equal(restored.guards(),1);
 assert.equal(restored.nodes.get('[data-pos-total]').textContent,'35,00 TL');
 assert.equal(restored.nodes.get('[data-pos-note]').value,'Bekleyen müşteri');
 assert.equal(restored.root.dataset.posTerminalId,app.root.dataset.posTerminalId);
 assert.equal(JSON.parse(restored.storage.get(key)).source_token,token);
 restored.flush();await new Promise(setImmediate);
 const resume=restored.posted.find(x=>x.action==='sync_live_cart');
 assert.equal(resume.terminal_id,app.root.dataset.posTerminalId);
 assert.equal(JSON.parse(resume.items_json).length,2);assert.equal(resume.state,'open');
 const scan=restored.nodes.get('[data-pos-scan]');scan.value='+3';scan.dispatchEvent({type:'keydown',key:'Enter',preventDefault(){}});
 const saved=JSON.parse(restored.storage.get(key));assert.equal(saved.items.find(x=>x.id===1).quantity,5);
 assert.equal(saved.last_added_product_id,1);
 // Failed/cancelled navigation does not drop the cart; backgrounding saves it.
 restored.leave();restored.document.visibilityState='hidden';restored.document.dispatchEvent({type:'visibilitychange'});
 assert.equal(JSON.parse(restored.storage.get(key)).items.length,2);
 restored.nodes.get('[data-pos-payment-confirm]').onclick();await new Promise(setImmediate);
 assert.equal(restored.guards(),0);assert.equal(restored.storage.has(key),false);
 assert.equal(restored.nodes.get('[data-pos-cart-protection]').hidden,true);
 assert.equal(restored.leave().prevented,false);assert.equal(boot().guards(),0);
 // Older stored carts do not have last_added_product_id: retain compatibility.
 const legacy=new Map([[key,JSON.stringify({items:[{id:8,quantity:1,sale_price:10}]})]]);
 const migrated=boot(legacy);const input=migrated.nodes.get('[data-pos-scan]');input.value='+3';input.dispatchEvent({type:'keydown',key:'Enter',preventDefault(){}});
 assert.equal(JSON.parse(legacy.get(key)).items[0].quantity,4);
 console.log('PASS: nonempty-cart exit guard, reload restoration, terminal identity, open live snapshot, discount/note/token/last product, +3, background save, successful-sale unlock, empty and legacy carts.');
})().catch(e=>{console.error(e);process.exitCode=1;});
