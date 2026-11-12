/**
 * Real-HTTPS read-only QA browser acceptance for Preview Visibility.
 *
 * Requires Playwright installed on a trusted QA workstation, six disposable
 * accounts and a private mode-0600 role credential file. Not physical devices,
 * not a substitute for independent signed release approval. No PATCH/POST
 * actions other than session login are issued.
 */
import { chromium } from 'playwright';
import fs from 'node:fs/promises';

const fail=(message)=>{throw Error('BLOCKED: '+message);};
const expected=process.env.BAMBODIA_PREVIEW_QA_EXPECTED_ORIGIN||'';
const origin=process.env.QA_BASE_URL||'';
if(process.env.BAMBODIA_PREVIEW_QA_ISOLATED!=='1' ||
  process.env.QA_REAL_BROWSER_ACK!=='readonly-synthetic-only')fail('Missing independent QA acknowledgement');
if(origin!==expected)fail('QA origin differs from independently expected origin');
const u=new URL(origin);
if(u.protocol!=='https:' || u.pathname!=='/' || u.search || u.hash ||
  ['bambodia.com','www.bambodia.com'].includes(u.hostname.toLowerCase()) ||
  u.hostname==='localhost' || u.hostname==='127.0.0.1')
  fail('Requires a dedicated HTTPS QA origin, never production or localhost');
const credentialFile=process.env.QA_BROWSER_ROLES_FILE||'';
if(!credentialFile.startsWith('/') || credentialFile.includes('/public/') ||
  credentialFile.includes('/www/wwwroot/'))fail('Private absolute credentials file required');
const stats=await fs.lstat(credentialFile);
if(!stats.isFile() || stats.isSymbolicLink() || (stats.mode & 0o077)!==0)
  fail('Role file must be a regular 0600 private file');
const roles=JSON.parse(await fs.readFile(credentialFile,'utf8'));
const expectedRoles=['super_admin','admin','staff','freelancer','customer','outsider'];
for(const name of expectedRoles){
  if(typeof roles[name]?.email!=='string' || !roles[name].email ||
     typeof roles[name]?.password!=='string' || !roles[name].password)
     fail('QA role credentials missing: '+name);
}
if(new Set(expectedRoles.map(role=>roles[role].email)).size!==expectedRoles.length)
  fail('All six QA identities must be distinct');

let checks=0;
const assert=(good,msg)=>{if(!good)fail(msg);checks++;};
const browser=await chromium.launch({headless:true});
const sessions=new Map();
try{
  const guest=await browser.newContext();
  const anonymous=await guest.newPage();
  const anon=await anonymous.goto(origin+'/dashboard/preview-content',{waitUntil:'networkidle',timeout:35000});
  assert(anon?.status()===200 && /\/login(?:\?|$)/.test(new URL(anonymous.url()).pathname),
    'Guest must be redirected to login');
  await guest.close();
  for(const role of expectedRoles){
    const context=await browser.newContext({viewport:{width:1365,height:900}});
    const page=await context.newPage();
    const login=await page.goto(origin+'/login',{waitUntil:'networkidle',timeout:35000});
    assert(login?.status()===200,'QA login unavailable for '+role);
    await page.locator('input[name="email"]').fill(roles[role].email);
    await page.locator('input[name="password"]').fill(roles[role].password);
    await page.locator('form.auth-form button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
    assert(!/\/login(?:\?|$)/.test(new URL(page.url()).pathname),
      'QA login not completed for '+role);
    const response=await page.goto(origin+'/dashboard/preview-content',{
      waitUntil:'networkidle',timeout:35000
    });
    const expectedStatus=role==='super_admin'?200:403;
    assert(response?.status()===expectedStatus,role+' review permissions not enforced');
    sessions.set(role,{page,context});
    console.log('PASS: '+role+' access boundary ('+expectedStatus+')');
  }
  const page=sessions.get('super_admin').page;
  assert(await page.getByText('Changes locked',{exact:false}).isVisible(),
    'Default-off safety indicator missing');
  assert(await page.locator('dialog.config-dialog button[type="submit"]:enabled').count()===0,
    'Write submit controls are enabled in locked QA mode');
  for(const size of [
    {name:'desktop',width:1440,height:900},
    {name:'tablet-emulation',width:820,height:1180},
    {name:'iphone-emulation',width:390,height:844},
    {name:'android-emulation',width:412,height:915},
  ]){
    await page.setViewportSize({width:size.width,height:size.height});
    const response=await page.goto(origin+'/dashboard/preview-content?kind=listing&status=all',
      {waitUntil:'networkidle',timeout:35000});
    assert(response?.status()===200,size.name+' review page failed');
    assert(await page.locator('table.data-table').first().count()===1,
      size.name+' review table unavailable');
    const rows=await page.locator('table.data-table').first().locator('tbody > tr td.mono').count();
    assert(rows>0 && rows<=10,size.name+' requires 1–10 synthetic QA preview rows');
    assert(await page.locator('table dialog').count()===0,
      size.name+' dialogs must be outside tables');
    assert(await page.locator('dialog.config-dialog button[type="submit"]:enabled').count()===0,
      size.name+' locked mode exposes an enabled write button');
    const trigger=page.locator('.row-action-trigger').first();
    assert(await trigger.count()===1,size.name+' has no row action trigger');
    await trigger.click();
    const menu=page.locator('[role="menu"][popover]').first();
    assert(await menu.isVisible(),size.name+' action menu did not appear');
    await menu.locator('button[data-modal-open]').first().click();
    const dialog=page.locator('dialog.config-dialog[open]').first();
    assert(await dialog.isVisible(),size.name+' inspection dialog did not open');
    assert(await dialog.locator('button[type="submit"]').isDisabled(),
      size.name+' inspection dialog allows disabled-mode writes');
    await dialog.locator('button[data-modal-close]').click();
    assert(!(await dialog.isVisible()),size.name+' inspection dialog did not close');
    console.log('PASS: '+size.name+' locked dialog and row actions');
  }
  await page.setViewportSize({width:1365,height:900});
  const job=await page.goto(origin+'/dashboard/preview-content?kind=job&status=all',
    {waitUntil:'networkidle',timeout:35000});
  assert(job?.status()===200,'Job preview review unavailable');
  const rows=await page.locator('table.data-table').first().locator('tbody > tr td.mono').count();
  assert(rows>0 && rows<=10,'Expected synthetic QA preview jobs');
  assert(await page.getByText('Changes locked',{exact:false}).isVisible(),'Job write lock hidden');
  console.log('REAL_HOST_CHROMIUM_READONLY=PASS');
  console.log('ASSERTIONS='+checks);
  console.log('PHYSICAL_IPHONE_ANDROID_TABLET=NOT_TESTED');
  console.log('LIVE_WRITE_ACCEPTANCE=NOT_TESTED');
} finally {
  for(const {context} of sessions.values())await context.close();
  await browser.close();
}
