import {chromium} from 'playwright';
import fs from 'node:fs/promises';

const origin=process.env.BASE_URL || 'http://127.0.0.1:8094';
if(origin!=='http://127.0.0.1:8094') throw Error('Badge UI browser test only runs on isolated localhost');
const out=process.env.BROWSER_ARTIFACT_DIR||'browser-artifacts';
await fs.mkdir(out,{recursive:true});
const browser=await chromium.launch({headless:true});
let checks=0;
try {
  for(const {name,width,height,isMobile} of [
    {name:'desktop',width:1440,height:900,isMobile:false},
    {name:'mobile',width:390,height:844,isMobile:true},
  ]){
    const context=await browser.newContext({viewport:{width,height},isMobile,hasTouch:isMobile});
    const page=await context.newPage();
    await page.goto(origin+'/en/login',{waitUntil:'networkidle'});
    await page.locator('input[name="email"]').fill('browser.admin@example.test');
    await page.locator('input[name="password"]').fill('BrowserOnlyPassword123!');
    await page.locator('form.auth-form button[type="submit"]').click();
    await page.waitForURL(/\/dashboard/,{timeout:15000});

    for(const theme of ['light','dark']){
      const response=await page.goto(origin+'/dashboard/creator-badges',{waitUntil:'networkidle'});
      if(response?.status()!==200)throw Error(name+' '+theme+' badge table HTTP '+response?.status());
      await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
      const restored=page.locator('#badge-catalog .badge-catalog-restored-card');
      if(await restored.count()!==16)
        throw Error(name+' '+theme+' original medal cards missing above user table');
      const order=await page.evaluate(()=>{
        const catalog=document.querySelector('#badge-catalog');
        const table=document.querySelector('.badge-manager-table');
        return !!(catalog && table && (catalog.compareDocumentPosition(table) & Node.DOCUMENT_POSITION_FOLLOWING));
      });
      if(!order)throw Error(name+' '+theme+' badge catalog must precede the user table');
      const firstCard=restored.first();
      if(!(await firstCard.locator('form[action*="/creator-badges/"]').count())
        || !(await firstCard.getByText('Eligibility / manual review conditions').count())
        || !(await firstCard.getByRole('button',{name:'Save badge'}).count())) {
        throw Error(name+' '+theme+' restored badge editor missing');
      }
      const rising=page.locator('[data-badge-card="rising-talent"]');
      if(!(await rising.getByText('Automatic eligibility conditions').count()) ||
         !(await rising.locator('input[name="auto_rules[min_completed]"]').count()))
        throw Error(name+' '+theme+' rising-talent rules not configurable');
      checks+=5;

      const rows=page.locator('.badge-manager-table tbody tr');
      const count=await rows.count();
      if(count<1 || count>10)throw Error(name+' '+theme+' table must have 1-10 users: '+count);
      if(!(await page.getByText('10 accounts per page').count()) ||
         !(await page.getByText('Go To',{exact:true}).count()))
        throw Error(name+' '+theme+' pagination controls missing');
      const open=page.locator('[data-badge-dialog-open^="badge-user-"]').first();
      const id=await open.getAttribute('data-badge-dialog-open');
      await open.click();
      const dialog=page.locator('#'+id);
      if(!(await dialog.isVisible()) || await dialog.locator('.badge-manager-award-option').count()!==16)
        throw Error(name+' '+theme+' modal does not show all sixteen badges');
      if(!(await dialog.locator('form[action*="/grant"]').count()))
        throw Error(name+' '+theme+' modal missing grant action');
      await page.screenshot({path:out+'/badge-manager-'+name+'-'+theme+'.png',fullPage:true});
      await page.keyboard.press('Escape');
      if(await dialog.isVisible())throw Error(name+' '+theme+' Escape does not close modal');
      checks+=5;

      const settingsLink=page.getByRole('link',{name:'Open Badge catalog settings'});
      await settingsLink.click();
      await page.waitForURL(/\/creator-badges\/settings/);
      if(!(await page.getByRole('heading',{name:'Badge Settings'}).count()) ||
         !(await page.getByRole('option',{name:'Badge + Name'}).count()) ||
         await page.locator('.badge-manager-definition-card').count()!==16)
        throw Error(name+' '+theme+' catalog settings missing');
      await page.locator('[data-badge-dialog-open="badge-create-dialog"]').click();
      const create=page.locator('#badge-create-dialog');
      if(!(await create.isVisible()))throw Error(name+' '+theme+' custom badge creation modal missing');
      await page.keyboard.press('Escape');
      checks+=4;
    }
    await context.close();
  }
  console.log('PASS: '+checks+' responsive badge manager and Settings dialog checks');
}finally{
  await browser.close();
}
