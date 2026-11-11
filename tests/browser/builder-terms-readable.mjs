import { chromium } from 'playwright';
import fs from 'node:fs/promises';

const base = process.env.BASE_URL || 'http://127.0.0.1:8094';
if (base !== 'http://127.0.0.1:8094') {
  throw Error('Readable submission terms acceptance is restricted to isolated localhost.');
}
const shots = process.env.BROWSER_ARTIFACT_DIR || 'browser-artifacts';
await fs.mkdir(shots, {recursive:true});

const browser = await chromium.launch({headless:true});
let checks = 0;
try {
  for (const device of [
    {name:'desktop',width:1440,height:900},
    {name:'mobile',width:390,height:844},
  ]) {
    const ctx = await browser.newContext({viewport:{width:device.width,height:device.height}});
    const page = await ctx.newPage();
    await page.goto(base+'/en/login',{waitUntil:'networkidle'});
    await page.locator('input[name="email"]').fill('browser.admin@example.test');
    await page.locator('input[name="password"]').fill('BrowserOnlyPassword123!');
    await page.locator('form.auth-form button[type="submit"]').click();
    await page.waitForURL(/\/dashboard/, {timeout:15000}).catch(async () => {
      const current = new URL(page.url()).pathname;
      const message = (await page.locator('main,body').first().innerText()).slice(0,350);
      throw Error(device.name+': isolated login failed ('+current+'): '+message);
    });

    const response = await page.goto(base+'/builder?type=product',{waitUntil:'networkidle'});
    if (response?.status() !== 200) throw Error(device.name+': Builder route unavailable');
    if (await page.locator('link[href*="builder-terms-readable.css?v=1"]').count() !== 1)
      throw Error(device.name+': scoped policy styling missing');
    checks += 2;

    const accept = page.locator('[data-builder-terms-check]');
    if (await accept.isChecked()) throw Error(device.name+': terms pre-accepted');
    await page.locator('[data-builder-terms-open]').click();
    const dialog = page.locator('#studio-terms-popup');
    await dialog.waitFor({state:'visible'});

    const body = dialog.locator('.studio-terms-body');
    const headings = await body.locator('h1,h2').allTextContents();
    if (!headings.includes('Browser QA Submission Terms') ||
        !headings.includes('Original work and licensing') ||
        await body.locator('li').count() !== 2) {
      throw Error(device.name+': rendered headings or policy list missing: '+headings.join(', '));
    }
    if ((await body.innerText()).includes('<h1>') ||
        await body.locator('script,iframe,img').count() !== 0) {
      throw Error(device.name+': raw policy tags or unsafe DOM content surfaced');
    }
    const metrics = await dialog.evaluate(modal => {
      const rect = modal.getBoundingClientRect();
      const scroller = modal.querySelector('.studio-terms-policy-scroll');
      const h1 = modal.querySelector('.studio-terms-body h1');
      const p = modal.querySelector('.studio-terms-body p');
      const inner = scroller.getBoundingClientRect();
      return {
        left:rect.left,right:rect.right,top:rect.top,bottom:rect.bottom,
        viewport:innerWidth,viewportHeight:innerHeight,
        scrollable:getComputedStyle(scroller).overflowY,
        style:getComputedStyle(h1).fontSize,
        lineHeight:getComputedStyle(p).lineHeight,
        width:inner.width
      };
    });
    if (metrics.left < -2 || metrics.right > metrics.viewport + 2 ||
        metrics.top < -2 || metrics.bottom > metrics.viewportHeight + 3 ||
        metrics.scrollable !== 'auto' || parseFloat(metrics.style) < 14 ||
        parseFloat(metrics.lineHeight) < 17) {
      throw Error(device.name+': terms dialog viewport or typography regression '+JSON.stringify(metrics));
    }
    checks += 6;
    await page.screenshot({path:shots+'/builder-terms-'+device.name+'.png'});

    await dialog.locator('[data-builder-terms-close]').first().click();
    if (await accept.isChecked()) throw Error(device.name+': closing dialog accepted terms');
    await page.locator('[data-builder-terms-open]').click();
    await dialog.locator('[data-builder-terms-accept]').click();
    if (!await accept.isChecked() || await dialog.isVisible())
      throw Error(device.name+': verified acceptance was not recorded in the current form');
    checks += 3;
    await ctx.close();
  }
  console.log('PASS: '+checks+' isolated Builder submission terms rendering, safety and acceptance checks');
} finally {
  await browser.close();
}
