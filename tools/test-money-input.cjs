// Run with Node and Playwright installed. CHROME_PATH optionally selects Chrome.
const {chromium} = require('playwright');
const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, 'muhasebe', file), 'utf8');
(async () => {
  const browser = await chromium.launch({headless:true, ...(process.env.CHROME_PATH ? {executablePath:process.env.CHROME_PATH} : {})});
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const files = ['tahsilat-makbuzu.php','hareketler.php','faturalar.php','cekler.php','hesaplar.php','cari-detay.php','magaza-veresiye.php','vergi-odemeleri.php'];
    for (const file of files) {
      // Use the actual input markup; replace PHP expressions with empty fixture values.
      const markup = read(file).replace(/<\?php[\s\S]*?\?>/g,'');
      const inputs = markup.match(/<input\b[^>]*>/g).filter(tag => tag.includes('data-money-input'));
      assert(inputs.length, file);
      await page.setContent('<form>'+inputs.join('')+'<input name="quantity" inputmode="decimal"><input name="vat_rate"><input name="tax_no"><input name="check_no"><input type="date" name="date"></form>');
      await page.addScriptTag({content:read('assets/money-input.js')});
      for (const input of await page.locator('[data-money-input]').all()) {
        await input.fill(''); await input.pressSequentially('200000');
        assert.equal(await input.inputValue(),'200.000',file);
        await input.pressSequentially(',50');
        assert.equal(await input.inputValue(),'200.000,50',file);
        await input.blur();
        assert.equal(await input.inputValue(),'200.000,50',file+' blur');
        assert.equal(await input.evaluate(el => new FormData(el.form).getAll(el.name)[Array.from(el.form.querySelectorAll('input')).filter(input=>input.name===el.name).indexOf(el)]), '200.000,50');
        await input.fill(''); await input.pressSequentially('1000000');
        assert.equal(await input.inputValue(),'1.000.000',file);
      }
      for (const name of ['quantity','vat_rate','tax_no','check_no']) {
        const field=page.locator('[name="'+name+'"]');
        await field.fill('200000'); await field.blur();
        assert.equal(await field.inputValue(),'200000');
      }
      await page.locator('[name=date]').fill('2026-10-23');
      assert.equal(await page.locator('[name=date]').inputValue(),'2026-10-23');
    }
    const field=page.locator('[data-money-input]').first();
    await field.fill(''); await field.pressSequentially('200000');
    await field.evaluate(el=>el.setSelectionRange(2,2)); await field.pressSequentially('5');
    assert.equal(await field.inputValue(),'2.050.000');
    assert.equal(await field.evaluate(el=>el.selectionStart),4);
    for (const [key,caret,expected] of [['Backspace',2,'000'],['Delete',1,'100']]) {
      await field.fill('1000'); await field.evaluate((el,caret)=>el.setSelectionRange(caret,caret),caret);
      await field.press(key); assert.equal(await field.inputValue(),expected,key+' at separator');
    }
    await field.fill('200000'); await field.pressSequentially('.50');
    assert.equal(await field.inputValue(),'200.000,50','decimal keypad');
    await field.fill('200000'); await field.evaluate(el=>el.setSelectionRange(0,3)); await field.pressSequentially('1');
    assert.equal(await field.inputValue(),'1.000','selection replacement');
    await field.press('ControlOrMeta+a'); await field.press('Backspace'); await field.blur();
    assert.equal(await field.inputValue(),'','clear');
    for (const [value,expected,parsed] of [['200000.50','200.000,50',200000.5],['200000,50','200.000,50',200000.5],['1,234.56','1.234,56',1234.56],['1.234,56','1.234,56',1234.56],['0,05','0,05',0.05],['-200000','-200.000',-200000],['200.000','200.000',200000]]) {
      await field.fill(value); await field.blur();
      assert.equal(await field.inputValue(),expected,value);
      assert.equal(await field.evaluate(el=>BitkeMoney.parse(el.value)),parsed);
    }
    // Invoice's real calculation handler, used for both purchase and sales directions.
    const invoice=read('faturalar.php');
    const calculation=invoice.slice(invoice.indexOf('(function(){\n  function numberValue'),invoice.indexOf('</script>',invoice.indexOf('(function(){\n  function numberValue')));
    for (const direction of ['purchase','sales']) {
      await page.setContent('<form><input name="direction" value="'+direction+'"><input data-money-input data-invoice-subtotal name="subtotal"><input data-money-input data-invoice-vat name="vat_amount"><input data-money-input data-invoice-total name="total_amount"></form>');
      await page.addScriptTag({content:calculation}); // same order as PHP page
      await page.addScriptTag({content:read('assets/money-input.js')});
      await page.locator('[name=subtotal]').pressSequentially('200000');
      await page.locator('[name=vat_amount]').pressSequentially('40000');
      assert.equal(await page.locator('[name=total_amount]').inputValue(),'240.000,00',direction);
      await page.locator('[name=total_amount]').fill(''); await page.locator('[name=total_amount]').pressSequentially('300000');
      await page.locator('[name=subtotal]').pressSequentially('0');
      assert.equal(await page.locator('[name=total_amount]').inputValue(),'300.000','manual total preserved');
    }
    // Dynamic modal/row initialization and stored dot-decimal value.
    await page.evaluate(()=>document.body.insertAdjacentHTML('beforeend','<input data-money-input name="item_amount[]" value="200000.50">'));
    assert.equal(await page.locator('[name="item_amount[]"]').inputValue(),'200.000,50');
    assert.deepEqual(errors,[]);
    console.log('PASS: 8 real form templates, purchase/sales totals, manual total preservation, FormData, caret, deletion, decimals, non-money fields and dynamic inputs');
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
