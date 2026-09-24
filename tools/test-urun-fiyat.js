/* Run via test-urun-fiyat.py against a disposable local application. */
const assert=require('assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const base=process.argv[2];
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.CHROME_BINARY?{executablePath:process.env.CHROME_BINARY}:{})});
 try{
  const context=await browser.newContext();
  await context.addCookies([{name:'bitke_muhasebe_session',value:'qa101',url:base}]);
  const page=await context.newPage();
  await page.route('**/*',route=>route.request().url().startsWith(base)||route.request().url().startsWith(new URL(base).origin)?route.continue():route.abort());
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  for(const [file,table,cari,add] of [['teklif-ver.php','#offerRows','#cariSelect','#addRow'],['depo-cikis.php','#wdRows','#wdCari','#wdAdd']]){
   await page.goto(base+file);
   const row=page.locator(table+' tbody tr').first();
   const price=row.locator('.price');
   async function customer(id){
    const response=page.waitForResponse(r=>r.url().includes('musteri-urun-son-fiyat.php?cari_id='+id));
    await page.selectOption(cari,String(id));await response;
    await page.waitForFunction(()=>!document.querySelector('small[role="status"]').textContent.includes('kontrol'));
   }
   async function product(name){await row.locator('.product-name').fill(name);await row.locator('.product-name').dispatchEvent('change');}
   async function expectPrice(value){await page.waitForFunction(([selector,value])=>document.querySelector(selector+' tbody tr .price').value===value,[table,value]);}
   await customer(101);await product('6000 modal');await expectPrice('444');
   await price.fill('420');await row.locator('.qty').fill('2');assert.equal(await price.inputValue(),'420');
   await customer(102);await expectPrice('280');
   await product('bambu');await expectPrice('380');
   await product('6000 MODAL ÇORAP');await expectPrice('280');
   await price.fill('275');
   await page.locator(add).click();
   const newRow=page.locator(table+' tbody tr').last();
   await newRow.locator('.product-name').fill('7000 BAMBU ÇORAP');await newRow.locator('.product-name').dispatchEvent('change');
   await page.waitForFunction(selector=>[...document.querySelectorAll(selector+' tbody tr .price')].at(-1).value==='380',table);
   assert.equal(await price.inputValue(),'275');
   await page.selectOption(cari,'101');await expectPrice('444'); // Cached customer without history.
   await page.selectOption(cari,'');await expectPrice('444');
   await product('Bilinmeyen ürün');await expectPrice('');
   await product('6000 MODAL ÇORAP');await expectPrice('444');
   if(file==='teklif-ver.php'){
    await page.selectOption('[name="currency"]','USD');await expectPrice('');
    await customer(102);await expectPrice('99');
    await page.selectOption('[name="currency"]','TL');await expectPrice('280');
   }
   console.log(file+': list fallback, customer override, manual edit, cloned row, customer/product/currency changes passed');
  }
  await page.goto(base+'teklif-ver.php?edit=1');
  await page.waitForTimeout(300);
  assert.equal(await page.locator('#offerRows tbody tr .price').first().inputValue(),'280');
  assert.equal(await page.locator('#offerRows tbody tr .price').last().inputValue(),'');
  // A late response for customer 102 must not overwrite the selection of a new customer.
  await page.goto(base+'depo-cikis.php');
  let release;
  const gate=new Promise(r=>release=r);
  await page.route('**/musteri-urun-son-fiyat.php?cari_id=102&*',async route=>{await gate;await route.continue();});
  await page.selectOption('#wdCari','102');
  await page.selectOption('#wdCari','');
  const row=page.locator('#wdRows tbody tr').first();
  await row.locator('.product-name').fill('6000 MODAL ÇORAP');await row.locator('.product-name').dispatchEvent('change');
  release();await page.waitForTimeout(350);
  assert.equal(await row.locator('.price').inputValue(),'444');
  await page.unroute('**/musteri-urun-son-fiyat.php?cari_id=102&*');
  // A manual price typed during a request stays intact.
  let releaseManual;const manualGate=new Promise(r=>releaseManual=r);
  await page.route('**/musteri-urun-son-fiyat.php?cari_id=103&*',async route=>{await manualGate;await route.continue();});
  await page.selectOption('#wdCari','103');await row.locator('.price').fill('401');releaseManual();
  await page.waitForTimeout(350);assert.equal(await row.locator('.price').inputValue(),'401');
  await row.locator('.qty').fill('1');
  await Promise.all([page.waitForURL('**/depo-cikis.php?edit=*'),page.locator('#wdForm button.primary').click()]);
  await page.goto(base+'depo-cikis.php');
  await page.selectOption('#wdCari','103');
  const savedRow=page.locator('#wdRows tbody tr').first();
  await savedRow.locator('.product-name').fill('6000 MODAL ÇORAP');await savedRow.locator('.product-name').dispatchEvent('change');
  await page.waitForFunction(()=>document.querySelector('#wdRows tbody tr .price').value==='401');
  await page.goto(base+'urun-fiyat-listesi.php');
  await page.locator('#priceSearch').fill('6000');
  assert.equal(await page.locator('#priceRows tr:visible').count(),1);
  if(process.env.PRICE_SCREENSHOT)await page.screenshot({path:process.env.PRICE_SCREENSHOT,fullPage:true});
  assert.deepEqual(errors,[]);
  console.log('Saved document preservation, async races, manual input while loading, price list UI passed');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
