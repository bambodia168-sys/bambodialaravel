import { chromium } from 'playwright';
import fs from 'node:fs/promises';

const origin = process.env.BASE_URL || 'http://127.0.0.1:8094';
if (origin !== 'http://127.0.0.1:8094')
  throw Error('Row action browser acceptance may only run on isolated localhost');

const out = process.env.BROWSER_ARTIFACT_DIR || 'browser-artifacts';
await fs.mkdir(out, { recursive:true });
const browser = await chromium.launch({ headless:true });
let checks = 0;

async function login(page, email = 'browser.admin@example.test', destination = /\/dashboard/) {
  const response = await page.goto(origin+'/en/login', { waitUntil:'networkidle' });
  if (response?.status() !== 200) throw Error('QA login page unavailable');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill('BrowserOnlyPassword123!');
  await page.locator('form.auth-form button[type="submit"]').click();
  await page.waitForURL(destination, { timeout:15000 });
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
    for (const type of ['product', 'job', 'project', 'freelance', 'service']) {
      const response = await page.goto(origin+'/dashboard/'+type+'-manager', {waitUntil:'domcontentloaded'});
      const label = 'Create '+type[0].toUpperCase()+type.slice(1);
      const button = page.getByRole('link', {name:label,exact:true});
      if (response?.status() !== 200 || !(await button.isVisible()))
        throw Error(name+' missing '+label);
      const target = new URL(await button.getAttribute('href'), origin);
      if (!target.pathname.endsWith('/builder') || target.searchParams.get('type') !== type)
        throw Error(name+' '+label+' opens the wrong Builder Studio');
      const topRight = await button.evaluate(link => {
        const intro = link.closest('.page-intro');
        const heading = intro.querySelector('h1').getBoundingClientRect();
        const button = link.getBoundingClientRect();
        const header = intro.getBoundingClientRect();
        return Math.abs(button.right - header.right) < 2 &&
          Math.abs(button.top - header.top) < 2 && button.left >= heading.right;
      });
      if (!topRight) throw Error(name+' '+label+' is outside the top-right header position');
      const draft = page.getByRole('row').filter({hasText:'Browser QA Saved Featured '+label.slice(7)});
      const edit = draft.getByRole('button', {name:/Actions for/});
      await edit.click();
      await draft.getByRole('menuitem', {name:'Edit',exact:true}).click();
      // Read the live selection after the script has initialized, then submit
      // the form without touching Featured media.
      const featured = page.locator('[data-featured-media]');
      await page.getByRole('button', {name:'Upload featured media'}).waitFor({state:'visible'});
      const preview = page.locator('[data-featured-preview] img');
      await preview.waitFor({state:'attached',timeout:5000});
      const selected = await featured.locator('option:checked').textContent();
      if (!selected?.includes('Original approved browser acceptance preview'))
        throw Error(name+' did not retain featured '+type+' media');
      await page.getByRole('button', {name:type==='product'?'Save product':'Save changes',exact:true}).click();
      await page.getByText('Listing changes saved.', {exact:true}).first().waitFor({state:'attached',timeout:5000});
      await preview.waitFor({state:'attached',timeout:5000});
      const previewAddress = new URL(page.url());
      previewAddress.searchParams.set('mode', 'preview');
      await page.goto(previewAddress.toString(), {waitUntil:'domcontentloaded'});
      await preview.waitFor({state:'attached',timeout:5000});
      if (!(await featured.locator('option:checked').textContent())?.includes('Original approved browser acceptance preview'))
        throw Error(name+' preview lost featured '+type+' media');
      if (await page.getByRole('button', {name:'Upload featured media'}).count())
        throw Error(name+' exposes upload controls in a read-only preview');
      if (type === 'service' && isMobile) {
        previewAddress.searchParams.set('mode', 'manage');
        await page.goto(previewAddress.toString(), {waitUntil:'domcontentloaded'});
        const target = page.locator('[data-featured-drop]');
        const upload = target.getByRole('button', {name:'Upload featured media'});
        const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l5cAAAAASUVORK5CYII=', 'base64');
        const [fileChooser] = await Promise.all([page.waitForEvent('filechooser'), upload.click()]);
        await fileChooser.setFiles({name:'direct featured image.png',mimeType:'image/png',buffer:png});
        await page.getByText('Media selected. Save the listing to apply it.', {exact:true}).waitFor();
        if (!(await featured.locator('option:checked').textContent())?.includes('direct featured image'))
          throw Error(name+' file picker upload did not select the new image');
        const dropped = await page.evaluateHandle(bytes => {
          const data = new DataTransfer();
          data.items.add(new File([new Uint8Array(bytes)], 'dropped featured image.png', {type:'image/png'}));
          return data;
        }, [...png]);
        await target.dispatchEvent('drop', {dataTransfer:dropped});
        await page.waitForFunction(() => document.querySelector('[data-featured-media]').selectedOptions[0]?.textContent.includes('dropped featured image'));
        await page.getByRole('button', {name:'Save changes',exact:true}).click();
        await page.getByText('Listing changes saved.', {exact:true}).first().waitFor({state:'attached'});
        await preview.waitFor({state:'attached'});
        if (!(await featured.locator('option:checked').textContent())?.includes('dropped featured image'))
          throw Error(name+' did not save the dropped featured image');
        checks += 3;
      }
      checks += 3;
    }
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
      await page.waitForFunction(id => {
        const menu=document.getElementById(id);
        const button=document.querySelector('[aria-controls="'+id+'"]');
        const bounds=menu?.getBoundingClientRect();
        return menu?.matches(':popover-open') && button?.getAttribute('aria-expanded')==='true'
          && bounds?.top>0;
      },menuId);
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
      await page.waitForFunction(id => document.querySelector('[aria-controls="'+id+'"]')?.getAttribute('aria-expanded')==='false',menuId);
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

    const licenseResponse = await page.goto(origin+'/dashboard/license-manager',{waitUntil:'networkidle'});
    if(licenseResponse?.status()!==200) throw Error(name+' license manager unavailable');
    const license = page.locator('[data-row-action-menu][id^="license-actions-"]').first();
    const licenseAnchor = await license.getAttribute('data-row-action-anchor');
    if(!licenseAnchor) throw Error(name+' shared License Manager adapter missing');
    await page.locator('#'+licenseAnchor).click();
    await license.waitFor({state:'visible'});
    if(!(await license.getByRole('menuitem',{name:/View definition/}).isVisible()))
      throw Error(name+' License Manager actions not preserved');
    await page.keyboard.press('Escape');
    checks++;
    await context.close();

    const creatorContext = await browser.newContext({
      viewport:{ width,height },deviceScaleFactor:1,isMobile,hasTouch:isMobile,
    });
    const creator = await creatorContext.newPage();
    await login(creator, 'browser.creator@example.test', /\/account\?/);
    await creator.goto(origin+'/creator', {waitUntil:'networkidle'});
    const recent = creator.locator('[aria-labelledby="creator-recent"] .workspace-overview-row').first();
    const recentTitle = await recent.locator('strong').innerText();
    if (!/\/builder\?=\d{9}$/.test(await recent.getAttribute('href') ?? ''))
      throw Error(name+' recent submission did not include its Builder ID');
    await recent.getByText('Manage', {exact:true}).click();
    await creator.waitForURL(/\/builder\?=\d{9}$/);
    if (!(await creator.locator('input[name="title"]').inputValue()).includes(recentTitle))
      throw Error(name+' recent submission opened a different listing');

    await creator.goto(origin+'/studio/listings', {waitUntil:'networkidle'});
    const row = creator.getByRole('row').filter({hasText:'Browser QA Published Freelance'});
    const listingTrigger = row.getByRole('button', {name:/Actions for/});
    const listingMenu = row.locator('[data-row-action-menu]');
    await listingTrigger.click();
    await listingMenu.waitFor({state:'visible'});
    for (const action of ['Details','Views','Archive','Draft','Delete']) {
      const item = listingMenu.getByRole('menuitem', {name:action,exact:true});
      if (!(await item.isVisible()) || await item.locator('svg').count() !== 1)
        throw Error(name+' listing menu missing icon action '+action);
    }
    await listingMenu.getByRole('menuitem', {name:'Archive',exact:true}).click();
    const archiveDialog = creator.getByRole('dialog', {name:'Archive listing'});
    await archiveDialog.waitFor({state:'visible'});
    await archiveDialog.getByRole('button', {name:'Cancel',exact:true}).click();
    await archiveDialog.waitFor({state:'hidden'});
    if (!(await row.getByText('Published', {exact:true}).isVisible()))
      throw Error(name+' canceling the Archive dialog changed listing status');
    await creatorContext.close();
    checks += 8;
  }
  console.log('PASS: '+checks+' responsive row action menu assertions');
} finally {
  await browser.close();
}
