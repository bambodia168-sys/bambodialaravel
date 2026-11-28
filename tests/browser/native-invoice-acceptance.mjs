import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import {chromium,firefox,webkit} from 'playwright';
const origin='http://127.0.0.1:8098';
assert.equal(process.env.GITHUB_ACTIONS,'true');
assert.equal(process.env.INVOICE_BROWSER_ISOLATED,'1');
assert.equal(process.env.BASE_URL,origin);
const engine=process.env.INVOICE_ENGINE,device=process.env.INVOICE_VIEWPORT;
const browsers={chromium,firefox,webkit};
const sizes={desktop:{width:1440,height:900},tablet:{width:820,height:1180},mobile:{width:390,height:844}};
assert.ok(browsers[engine] && sizes[device]);
const fixture=JSON.parse(await fs.readFile('storage/framework/native-invoice-browser.json','utf8'));
const dir='invoice-browser-evidence';
await fs.mkdir(dir,{recursive:true});
const evidence={commit:process.env.GITHUB_SHA,engine,device,viewport:sizes[device],checks:[],screenshots:[],blockedExternalRequests:0,result:'FAIL'};
const record=name=>{evidence.checks.push(name);console.log('PASS '+name);};
let browser;
const monitored=new Map();
async function newPage(){
 const context=await browser.newContext({viewport:sizes[device],deviceScaleFactor:1,colorScheme:'light',hasTouch:device!=='desktop',serviceWorkers:'block',reducedMotion:'reduce'});
 await context.addInitScript(()=>{if(!localStorage.getItem('bambodia-theme'))localStorage.setItem('bambodia-theme','light');});
 await context.route('**/*',route=>{
  const url=new URL(route.request().url());
  if(url.origin===origin||['data:','blob:','about:'].includes(url.protocol))return route.continue();
  evidence.blockedExternalRequests++;return route.abort();
 });
 const page=await context.newPage();page.setDefaultTimeout(15000);
 const errors=[];monitored.set(page,errors);
 page.on('pageerror',e=>errors.push('pageerror: '+e.message));
 page.on('console',m=>{if(m.type()==='error'&&!/^(net::ERR_FAILED|Failed to load resource|Load failed|NS_ERROR_FAILURE)/.test(m.text()))errors.push('console: '+m.text());});
 page.on('response',r=>{if(new URL(r.url()).origin===origin&&r.status()>=400&&r.request().resourceType()!=='document')errors.push('local HTTP '+r.status()+' '+new URL(r.url()).pathname);});
 return page;
}
async function visit(page,path,status=200){
 const u=new URL(path,origin);assert.equal(u.origin,origin);
 const r=await page.goto(u.href,{waitUntil:'load'});assert.equal(r.status(),status,path);return r;
}
async function login(role){
 const page=await newPage();await visit(page,'/login');
 const form=page.locator('form.auth-form').first();
 await form.locator('input[name="email"]').fill('invoice-browser.'+role+'@example.test');
 await form.locator('input[name="password"]').fill('InvoiceBrowserOnly123!');
 await Promise.all([page.waitForURL(u=>u.pathname!=='/login'),form.locator('button[type="submit"]').click()]);
 return page;
}
async function submit(page,button,path){
 await Promise.all([page.waitForNavigation({waitUntil:'load'}),button.click()]);
 assert.match(new URL(page.url()).pathname,path);
}
async function capture(page,name){
 const url=page.url(),panel=page.locator('.invoice-workspace').first(),palettes={};
 for(const theme of ['light','dark']){
  await page.evaluate(theme=>localStorage.setItem('bambodia-theme',theme),theme);
  const response=await page.reload({waitUntil:'load'});assert.equal(response.status(),200);assert.equal(page.url(),url);
  await panel.waitFor({state:'visible'});assert.equal(await page.locator('html').getAttribute('data-theme'),theme);
  assert.match(response.headers()['cache-control']??'',/no-store/);assert.match(response.headers()['x-robots-tag']??'',/noindex/);
  const layout=await panel.evaluate(el=>{
   const r=el.getBoundingClientRect(),root=document.documentElement;
   const controls=[...el.querySelectorAll('.invoice-form input:not([type=hidden]),.invoice-form button,.invoice-actions a')].filter(n=>n.getClientRects().length).map(n=>n.getBoundingClientRect());
   const style=getComputedStyle(el.querySelector('.panel'));
   return {width:root.clientWidth,scroll:root.scrollWidth,left:r.left,right:r.right,fit:controls.every(b=>b.width>0&&b.left>=-3&&b.right<=root.clientWidth+3),palette:[style.backgroundColor,style.color]};
  });
  assert.ok(layout.scroll<=layout.width+4&&layout.left>=-3&&layout.right<=layout.width+3&&layout.fit,JSON.stringify(layout));
  palettes[theme]=layout.palette;
  const file=name+'-'+theme+'.png';await page.screenshot({path:dir+'/'+file,fullPage:true,animations:'disabled',timeout:15000});evidence.screenshots.push(file);
  assert.deepEqual(monitored.get(page),[],name+' browser errors');
 }
 assert.notDeepEqual(palettes.light,palettes.dark,name+' theme palette');
 record(name+' both themes, responsive layout and private headers');
}
async function noLeak(page,path){
 const response=await visit(page,path,404);assert.ok(!(await response.text()).includes('Synthetic original invoice item'));
}
try{
 browser=await browsers[engine].launch({headless:true});
 const guest=await newPage();await visit(guest,'/studio/invoices');assert.equal(new URL(guest.url()).pathname,'/login');await guest.context().close();
 const seller=await login('seller'),buyer=await login('buyer'),recipient=await login('recipient'),outsider=await login('outsider'),admin=await login('admin');
 // Verify the dashboard itself, including a real filter refresh, before opening invoices.
 await visit(seller,'/studio');
 await seller.locator('[data-chart="revenue"] svg').waitFor();
 assert.deepEqual(monitored.get(seller),[],'Studio initial render');
 const period=seller.locator('[data-filter-period="quarter"]');
 await period.click();
 await seller.waitForFunction(()=>document.querySelector('[data-filter-period="quarter"]').getAttribute('aria-pressed')==='true' && !document.querySelector('[data-studio-analytics]').hasAttribute('aria-busy'));
 assert.equal(await seller.locator('[data-studio-error]').isHidden(),true);
 assert.deepEqual(monitored.get(seller),[],'Studio analytics refresh');
 record('Studio analytics initial render and quarter refresh with optional heatmap absent');
 execFileSync('php',['tests/browser/native-invoice-fixture.php','baseline'],{stdio:'inherit'});
 await visit(seller,'/studio/invoices');await seller.getByRole('heading',{name:'Sales invoices',exact:true}).waitFor();await capture(seller,'01-studio-list');
 const toggle=seller.locator('[data-theme-toggle]').first();
 if(await toggle.isVisible()){await toggle.click();await seller.waitForFunction(()=>document.documentElement.dataset.theme==='light');record('Native theme toggle switches to light');}
 await seller.locator('input[name="order_reference"]').fill(fixture.reference);
 await submit(seller,seller.getByRole('button',{name:'Review source order'}),/\/studio\/invoices\/new$/);
 await seller.getByText('Synthetic original invoice item',{exact:true}).waitFor();await seller.getByText('Total: USD 25.00',{exact:true}).waitFor();
 assert.equal(await seller.getByText('Changed synthetic listing',{exact:true}).count(),0);await capture(seller,'02-source-preview');
 const form=seller.locator('form.invoice-form'),checkbox=form.locator('input[name="confirm"]');
 assert.equal(await checkbox.isChecked(),false);assert.equal(await checkbox.evaluate(e=>e.required),true);
 await form.locator('input[name="due_on"]').fill(fixture.date);await form.getByRole('button',{name:'Create private draft'}).click();
 assert.equal(new URL(seller.url()).pathname,'/studio/invoices/new');await checkbox.check();
 await submit(seller,form.getByRole('button',{name:'Create private draft'}),/\/studio\/invoices\/[a-f0-9-]{36}$/);
 const draftPath=new URL(seller.url()).pathname,draftId=draftPath.split('/').at(-1);
 await seller.getByText('PRIVATE — NOT ISSUED — NOT A TAX INVOICE',{exact:true}).waitFor();
 await seller.getByText('Due date: '+fixture.date,{exact:true}).waitFor();await capture(seller,'03-private-draft');
 await visit(buyer,'/account/invoices');await buyer.getByText('No invoices found.',{exact:true}).waitFor();await capture(buyer,'04-buyer-empty');
 await visit(recipient,'/account/invoices');await recipient.getByText('QA-ONLY-ISSUED',{exact:true}).waitFor();await recipient.getByText('QA-ONLY-VOID',{exact:true}).waitFor();await capture(recipient,'05-recipient-list');
 for(const status of ['issued','void']){
  await visit(recipient,'/account/invoices/'+fixture[status]);await recipient.getByRole('heading',{name:'QA-ONLY-'+status.toUpperCase(),exact:true}).waitFor();
  assert.equal(await recipient.locator('form.invoice-form').count(),0);await capture(recipient,'06-recipient-'+status);
 }
 const update=seller.locator('form.invoice-form').filter({has:seller.getByRole('button',{name:'Save due date'})});
 await update.locator('input[name="due_on"]').fill(fixture.updatedDate);await update.locator('input[name="confirm"]').check();
 await submit(seller,update.getByRole('button',{name:'Save due date'}),/\/studio\/invoices\/[a-f0-9-]{36}$/);
 await seller.getByText('Due date: '+fixture.updatedDate,{exact:true}).waitFor();await capture(seller,'07-updated-date');
 await update.locator('input[name="due_on"]').fill('');await update.locator('input[name="confirm"]').check();
 await submit(seller,update.getByRole('button',{name:'Save due date'}),/\/studio\/invoices\/[a-f0-9-]{36}$/);
 await seller.getByText('Due date: Not set',{exact:true}).waitFor();
 const discard=seller.locator('form.invoice-form').filter({has:seller.getByRole('button',{name:'Discard draft'})});
 assert.equal(await discard.locator('input[name="confirm"]').isChecked(),false);assert.equal(await discard.locator('input[name="confirm"]').evaluate(e=>e.required),true);
 await discard.getByRole('button',{name:'Discard draft'}).click();await seller.getByRole('heading',{name:'Discard private draft'}).waitFor();
 await capture(seller,'08-discard-confirmation');await discard.locator('input[name="confirm"]').check();
 await submit(seller,discard.getByRole('button',{name:'Discard draft'}),/\/studio\/invoices\/[a-f0-9-]{36}$/);
 await seller.getByText(/Document status: Discarded/).waitFor();assert.equal(await seller.locator('form.invoice-form').count(),0);await capture(seller,'09-discarded');
 await visit(seller,'/studio/invoices?status=discarded');await seller.getByText(fixture.reference,{exact:true}).waitFor();await capture(seller,'10-filtered-list');
 await noLeak(buyer,'/account/invoices/'+draftId);await noLeak(recipient,'/account/invoices/'+draftId);
 await noLeak(outsider,draftPath);await noLeak(admin,draftPath);await noLeak(outsider,'/account/invoices/'+fixture.issued);
 await noLeak(outsider,'/studio/invoices/new?order_reference='+fixture.reference);await noLeak(seller,'/studio/invoices/new?order_reference='+fixture.reference);
 record('Participant isolation, terminal discard and source reuse denied');
 execFileSync('php',['tests/browser/native-invoice-fixture.php','verify'],{stdio:'inherit'});
 record('Database lifecycle, exact audit history and unchanged protected records');evidence.result='PASS';
}finally{await fs.writeFile(dir+'/result.json',JSON.stringify(evidence,null,2));if(browser)await browser.close();}
