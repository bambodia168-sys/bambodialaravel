import {chromium} from 'playwright';
import assert from 'node:assert/strict';

const origin=process.env.BASE_URL || 'http://127.0.0.1:8096';
const browser=await chromium.launch({headless:true});
try {
  const page=await browser.newPage({viewport:{width:1440,height:900}});
  const verify=async(selector)=>{
    const cart=page.locator(selector+' .mini-cart');
    const trigger=cart.locator('summary');
    assert.equal(await trigger.count(),1);
    assert.equal(await trigger.getAttribute('aria-label'),'Cart');
    assert.equal(await trigger.locator('svg').count(),1);
    assert.equal(await trigger.locator('[data-cart-badge]').count(),1);
    const bounds=await trigger.boundingBox();
    assert.ok(bounds && bounds.width>=35 && bounds.height>=35);
    const style=()=>trigger.evaluate(el=>{
      const s=getComputedStyle(el);
      return {background:s.backgroundColor,border:s.borderTopColor,shadow:s.boxShadow,color:s.color,outline:s.outlineStyle,outlineWidth:s.outlineWidth};
    });
    for(const theme of ['light','dark']){
      await page.evaluate(theme=>{document.documentElement.dataset.theme=theme},theme);
      await page.mouse.move(0,0);
      for(const state of ['rest','hover','open']){
        if(state==='hover') await trigger.hover();
        if(state==='open'){
          await trigger.click();
          assert.equal(await cart.getAttribute('open'),'');
          await cart.locator('.mini-cart-panel').waitFor({state:'visible'});
          assert.match(await cart.locator('.mini-cart-view').getAttribute('href'),/\/cart$/);
        }
        const s=await style();
        assert.equal(s.background,'rgba(0, 0, 0, 0)',theme+' '+state);
        assert.equal(s.border,'rgba(0, 0, 0, 0)',theme+' '+state);
        assert.equal(s.shadow,'none',theme+' '+state);
      }
      await trigger.click();
      assert.equal(await cart.getAttribute('open'),null);
      await page.mouse.move(0,0);
      await trigger.focus();
      await page.keyboard.press('Tab');
      await page.keyboard.press('Shift+Tab');
      assert.equal(await trigger.evaluate(el=>el.matches(':focus-visible')),true);
      const focus=await style();
      assert.equal(focus.outline,'solid');
      assert.ok(parseFloat(focus.outlineWidth)>=2);
      await page.keyboard.press('Enter');
      assert.equal(await cart.getAttribute('open'),'');
      await page.keyboard.press('Enter');
      assert.equal(await cart.getAttribute('open'),null);
      await trigger.evaluate(el=>el.blur());
    }
  };
  await page.goto(origin+'/',{waitUntil:'networkidle'});
  await verify('.site-header');
  await page.setViewportSize({width:390,height:844});
  await verify('.site-header');
  await page.setViewportSize({width:1440,height:900});
  await page.goto(origin+'/login',{waitUntil:'networkidle'});
  await page.locator('input[name="email"]').fill('browser.client@example.test');
  await page.locator('input[name="password"]').fill('BrowserOnlyPassword123!');
  await page.locator('form.auth-form button[type="submit"]').click();
  await page.waitForURL(/\/account\?/,{timeout:15000});
  await verify('.workspace-topbar');
  await page.setViewportSize({width:390,height:844});
  await verify('.workspace-topbar');
  console.log('PASS: transparent public and account cart controls, both themes and viewport sizes');
} finally {
  await browser.close();
}
