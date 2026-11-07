import { chromium } from 'playwright';
import fs from 'node:fs/promises';

const origin = process.env.BASE_URL || 'http://127.0.0.1:8094';
if (origin !== 'http://127.0.0.1:8094')
  throw Error('Row action browser acceptance may only run on isolated localhost');

const out = process.env.BROWSER_ARTIFACT_DIR || 'browser-artifacts';
await fs.mkdir(out, { recursive:true });
const browser = await chromium.launch({ headless:true });
let checks = 0;

async function login(page) {
  const response = await page.goto(origin+'/en/login', { waitUntil:'networkidle' });
  if (response?.status() !== 200) throw Error('QA login page unavailable');
  await page.locator('input[name="email"]').fill('browser.admin@example.test');
  await page.locator('input[name="password"]').fill('BrowserOnlyPassword123!');
  await page.locator('form.auth-form button[type="submit"]').click();
  await page.waitForURL(/\/dashboard/, { timeout:15000 });
}

try {
  for (const { name,width,height,isMobile } of [
    { name:'desktop',width:1440,height:900,isMobile:false },
    { name:'mobile',width:390,height:844,isMobile:true },
  ]) {
    const context = await browser.newContext({
      viewport:{ width,height },deviceScaleFactor:1,isMobile,hasTouch:isMobile,
    });
    const page = await context.newPage();
    await login(page);
    const response = await page.goto(origin+'/dashboard/policy-center',{waitUntil:'networkidle'});
    if (response?.status() !== 200) throw Error(name+' Policy Center unavailable: '+response?.status());

    const trigger = page.locator('[id^="row-action-trigger-policy-"]').first();
    const menuId = await trigger.getAttribute('aria-controls');
    if (!menuId || !(await trigger.getAttribute('aria-label'))?.includes('Actions for'))
      throw Error(name+' row action lacks accessible label or association');

    for (const theme of ['light','dark']) {
      await page.evaluate(value=>{document.documentElement.dataset.theme=value;},theme);
      await trigger.click();
      const menu = page.locator('[id="'+menuId+'"]');
      await menu.waitFor({state:'visible'});
      const check = await page.evaluate(menuId=>{
        const menu = document.getElementById(menuId);
        const trigger = document.querySelector('[aria-controls="'+menuId+'"]');
        const box = menu.getBoundingClientRect();
        const css = getComputedStyle(menu);
        return {
          expanded:trigger.getAttribute('aria-expanded'),
          visible:menu.matches(':popover-open'),
          position:css.position,
          left:box.left,right:box.right,top:box.top,bottom:box.bottom,
          viewportWidth:window.innerWidth,viewportHeight:window.innerHeight,
          links:[...menu.querySelectorAll('a[href]')].map(a=>a.textContent.trim()),
        };
      },menuId);
      if(check.expanded!=='true' || !check.visible || check.position!=='fixed' ||
         check.left<0 || check.right>check.viewportWidth+2 || check.top<0 ||
         check.bottom>check.viewportHeight+2 ||
         !check.links.includes('Edit') || !check.links.includes('Preview')) {
        throw Error(name+'/'+theme+' row popover positioning/actions invalid: '+JSON.stringify(check));
      }
      await page.screenshot({path:out+'/row-actions-'+name+'-'+theme+'.png'});
      await menu.locator('a').first().focus();
      await page.keyboard.press('ArrowDown');
      const second = await page.evaluate(() => document.activeElement?.textContent?.trim());
      if(second!=='Preview') throw Error(name+'/'+theme+' ArrowDown navigation failed: '+second);
      await page.keyboard.press('Escape');
      await menu.waitFor({state:'hidden'});
      if(await trigger.getAttribute('aria-expanded')!=='false')
        throw Error(name+'/'+theme+' popover did not reset expanded state');
      checks += 5;
    }
    await trigger.click();
    const editHref = await page.locator('[id="'+menuId+'"] a').first().getAttribute('href');
    if(!editHref?.includes('/policy-center/') || !editHref.endsWith('/edit'))
      throw Error(name+' Edit route changed: '+editHref);
    await page.locator('[id="'+menuId+'"] a').first().click();
    await page.waitForURL(/\/policy-center\/\d+\/edit/);
    if(!(await page.getByRole('heading',{name:/Edit|Policy/i}).count()))
      throw Error(name+' Policy Builder did not open');
    checks++;

    const licenseResponse = await page.goto(origin+'/dashboard/licenses',{waitUntil:'networkidle'});
    if(licenseResponse?.status()!==200) throw Error(name+' license manager unavailable');
    const license = page.locator('[data-row-action-menu][id^="license-actions-"]').first();
    const licenseTrigger = page.locator('[aria-controls="'+await license.getAttribute('id')+'"]');
    // Existing License Manager uses popovertarget rather than aria-controls.
    const licenseAnchor = await license.getAttribute('data-row-action-anchor');
    if(!licenseAnchor) throw Error(name+' shared License Manager adapter missing');
    await page.locator('#'+licenseAnchor).click();
    await license.waitFor({state:'visible'});
    if(!(await license.getByRole('menuitem',{name:/View definition/}).isVisible()))
      throw Error(name+' License Manager actions not preserved');
    await page.keyboard.press('Escape');
    checks++;
    await context.close();
  }
  console.log('PASS: '+checks+' responsive row action menu assertions');
} finally {
  await browser.close();
}
