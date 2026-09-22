// No browser or live database: exercise product-read failure and recovery paths.
const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const code=fs.readFileSync(__dirname+'/../muhasebe/assets/barkod-read-json.js','utf8');
let timer,cleared=0,mode='ok',seen;
const ctx={window:{},AbortController,setTimeout(fn){timer=fn;return 1;},clearTimeout(){cleared++;},fetch(url,opts){
 seen=opts;
 if(mode==='wait')return new Promise((resolve,reject)=>opts.signal.addEventListener('abort',()=>reject(Object.assign(new Error('Aborted'),{name:'AbortError'}))));
 return Promise.resolve({ok:mode!=='http',json(){return mode==='json'?Promise.reject(new Error('Invalid JSON')):Promise.resolve({product:{id:1}});}});
}};
vm.runInNewContext(code,ctx);
(async()=>{
 assert.equal((await ctx.window.posReadJson('/product')).product.id,1);
 assert.equal(seen.credentials,'same-origin');assert.equal(seen.method,undefined);
 mode='wait';let p=ctx.window.posReadJson('/product');timer();await assert.rejects(p,/yanıtı gecikti/);
 let c=new AbortController();p=ctx.window.posReadJson('/product',c);c.abort();await assert.rejects(p,{name:'AbortError'});
 mode='http';await assert.rejects(ctx.window.posReadJson('/product'),/alınamadı/);
 mode='json';await assert.rejects(ctx.window.posReadJson('/product'),/Invalid JSON/);
 mode='ok';assert.equal((await ctx.window.posReadJson('/product')).product.id,1);
 assert.equal(cleared,6);
 // The existing lookup must release its busy flag on failure and allow retry.
 let source=fs.readFileSync(__dirname+'/../muhasebe/assets/barkod-satis.js','utf8');
 let lookup=source.slice(source.indexOf('  function lookup(){'),source.indexOf("  scan.addEventListener('input'"));
 let calls=0,added=0;
 let l={scan:{value:'123'},lookupBusy:false,lastLookup:'',lookupRevision:0,status:{},results:{},api:'/api',Date,encodeURIComponent,
 window:{posReadJson(){calls++;return calls===1?Promise.reject(new Error('Timeout')):Promise.resolve({product:{id:1}});}},add(){added++;},showResults(){}};
 vm.runInNewContext(lookup,l);l.lookup();await new Promise(setImmediate);
 assert.equal(l.lookupBusy,false);assert.equal(l.lastLookup,'');assert.equal(l.status.textContent,'Timeout');
 l.lookup();await new Promise(setImmediate);assert.equal(added,1);assert.equal(l.lookupBusy,false);
 console.log('PASS: success, timeout, cancellation, HTTP/JSON errors, timer cleanup, lookup unlock and retry.');
})().catch(e=>{console.error(e);process.exitCode=1;});
