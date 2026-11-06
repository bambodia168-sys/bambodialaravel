import {chromium} from 'playwright';
import fs from 'node:fs/promises';

const origin=process.env.BASE_URL;
if(origin!=='http://127.0.0.1:8096' || process.env.BAMBODIA_UAT_ISOLATED!=='1')
  throw Error('BLOCKED: UAT must use a disposable localhost instance');
const fixture=JSON.parse(await fs.readFile('storage/framework/uat-fixtures.json','utf8'));
const results=[];
let browser;
const pass=(name)=>{console.log('PASS '+name);results.push({check:name,status:'passed'});};
const assert=(condition,why)=>{if(!condition)throw Error(why);};
const visit=async(page,p)=>{
  const r=await page.goto(origin+p,{waitUntil:'networkidle',timeout:35000});
  assert(r?.status()===200,'GET '+p+' returned '+r?.status());
};
async function login(email){
  const context=await browser.newContext({viewport:{width:1365,height:900}});
  const page=await context.newPage();
  await visit(page,'/login');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill('UatIsolatedBrowserOnly123!');
  await page.locator('form.auth-form button[type="submit"]').click();
  await page.waitForLoadState('networkidle');
  assert(!(await page.locator('form.auth-form input[name="password"]').count()),'Login failed for isolated QA role');
  return {page,context};
}
async function post(page,p,data={},method='POST',expected=302){
  await visit(page,'/');
  const token=await page.locator('meta[name="csrf-token"]').getAttribute('content');
  assert(Boolean(token),'Missing application CSRF token');
  const form={_token:token,...data};
  if(method!=='POST')form._method=method;
  const r=await page.request.post(origin+p,{form,maxRedirects:0});
  assert(r.status()===expected,method+' '+p+' returned '+r.status()+' instead of '+expected);
  return r;
}
async function textOn(page,s){
  assert(await page.getByText(s,{exact:false}).count()>0,'Missing user-visible text: '+s);
}

const report={kind:'Bambodia isolated real-HTTP browser journeys',sha:process.env.GITHUB_SHA||'local',
  environment:'disposable localhost MySQL; not production',
  checks:results,
  limitations:['No real ClamAV or external legal rights approval in this automated browser journey.',
    'No live Bambodia account or customer data used.','Physical devices and deployed aaPanel remain unverified.']};
try{
  browser=await chromium.launch({headless:true});
  const sessions={};
  for(const name of ['client','creator','staff','moderator','super','outsider'])
    sessions[name]=await login('uat.'+name+'@example.test');
  const client=sessions.client.page,creator=sessions.creator.page,
    staff=sessions.staff.page,moderator=sessions.moderator.page,
    superUser=sessions.super.page,outsider=sessions.outsider.page;
  pass('Six distinct authenticated account sessions');

  await post(outsider,'/dashboard/review-queue/'+fixture.approve_id,{decision:'published'},'PATCH',403);
  await post(moderator,'/dashboard/review-queue/'+fixture.approve_id,{decision:'published'},'PATCH');
  await post(moderator,'/dashboard/review-queue/'+fixture.reject_id,{decision:'rejected'},'PATCH');
  await visit(moderator,'/dashboard/review-queue?type=service');
  assert(!(await moderator.getByText('UAT Service For Approval').count()),'Published item remains pending');
  await visit(creator,'/account/notifications');
  await textOn(creator,'Marketplace listing reviewed');
  pass('Moderator approved/rejected listings with permission denial and creator notification');

  await post(creator,'/studio/products',{
    title:'UAT Original Product for Review',
    description:'Original QA-only licensed product with an approved creator-owned private preview and complete release documentation.',
    license_definition_id:String(fixture.license_id),
    cover_media_id:String(fixture.cover_id),
    category_id:String(fixture.category_id),
    status:'pending',
  });
  await visit(moderator,'/dashboard/product-manager?status=pending&search=UAT');
  const prodRow=moderator.locator('tr').filter({hasText:'UAT Original Product for Review'}).first();
  assert(await prodRow.count()===1,'Submitted product is absent from moderation queue');
  const action=await prodRow.locator('form[action*="/dashboard/product-manager/"]').getAttribute('action');
  assert(Boolean(action),'Product review action is not present');
  await post(moderator,new URL(action,origin).pathname,{decision:'published'},'PATCH');
  await visit(creator,'/studio/products?status=published');
  const publishedRow=creator.locator('tr').filter({hasText:'UAT Original Product for Review'}).first();
  assert(await publishedRow.count()===1,'Creator cannot see published product');
  const releases=await publishedRow.locator('a[href*="/releases"]').getAttribute('href');
  assert(Boolean(releases),'Published product has no release manager');
  await visit(creator,'/account/notifications');
  await textOn(creator,'Digital product reviewed');
  pass('Creator submitted product, moderator published, creator received result');

  const releasePath=new URL(releases,origin).pathname;
  await visit(creator,releasePath);
  const csrf=await creator.locator('meta[name="csrf-token"]').getAttribute('content');
  const zip=await fs.readFile(process.env.UAT_ZIP_FILE);
  const upload=await creator.request.post(origin+releasePath,{maxRedirects:0,multipart:{
    _token:csrf,version:'1.0.0',
    release_notes:'QA original release uploaded through real multipart browser session.',
    rights_attested:'1',
    rights_evidence:'Original isolated QA fixture with test-only rights held by the UAT creator account.',
    artifact:{name:'uat-original.zip',mimeType:'application/zip',buffer:zip},
  }});
  assert(upload.status()===302,'Private release upload returned '+upload.status());
  await visit(creator,releasePath+'?status=pending');
  await textOn(creator,'uat-original.zip');
  await visit(moderator,'/dashboard/file-vault?status=pending');
  const pending=moderator.locator('tr').filter({hasText:'UAT Original Product for Review'}).first();
  assert(await pending.count()===1,'Uploaded pending release not in moderation');
  const dialog=await pending.locator('dialog[id^="vault-"]').first().getAttribute('id');
  const version=dialog?.match(/^vault-(\d+)$/)?.[1];
  assert(Boolean(version),'Pending vault release identifier missing');
  await post(moderator,'/dashboard/file-vault/'+version,{decision:'approved',confirm_review:'1'},'PATCH',409);
  pass('Creator private release uploaded; unscanned approval blocked (genuine scanner acceptance outstanding)');

  await visit(client,'/support-center');
  await client.locator('button[data-modal-open="ticket-new"]').click();
  await client.locator('#ticket-new [name="subject"]').fill('UAT Private Support Response');
  await client.locator('#ticket-new [name="message"]').fill('QA-only authenticated support request and notification verification.');
  await client.locator('#ticket-new button[type="submit"]').click();
  await client.waitForLoadState('networkidle');
  const ticket=new URL(client.url()).pathname.match(/\/support-center\/(\d+)$/)?.[1];
  assert(Boolean(ticket),'New support ticket did not open');
  const notOwner=await outsider.request.get(origin+'/support-center/'+ticket,{maxRedirects:0});
  assert(notOwner.status()===404,'Other customer can read private support ticket');
  await visit(staff,'/dashboard/support-center/'+ticket);
  await staff.locator('textarea[name="message"]').fill('QA staff verified your case and recorded the status in the audit.');
  await staff.locator('select[name="status"]').selectOption('in_progress');
  await staff.locator('.ticket-reply button[type="submit"]').click();
  await staff.waitForLoadState('networkidle');
  await visit(client,'/account/notifications');
  await textOn(client,'Support team replied');
  await visit(client,'/support-center/'+ticket);
  await textOn(client,'QA staff verified your case');
  pass('Client support ticket, staff reply, private visibility and recipient notification');

  const inquiry=await post(client,'/inquiries/for/'+fixture.public_service_id,{
    subject:'UAT Private Service Conversation',
    body:'QA-only original private service inquiry that does not create any real commercial order.',
  });
  const inquiryId=(inquiry.headers()['location']||'').match(/\/inquiries\/(\d+)/)?.[1];
  assert(Boolean(inquiryId),'Private inquiry was not created');
  const forbidden=await outsider.request.get(origin+'/inquiries/'+inquiryId,{maxRedirects:0});
  assert(forbidden.status()===404,'Other customer can read private inquiry');
  await visit(creator,'/inquiries/'+inquiryId);
  await creator.locator('textarea[name="body"]').fill('QA-only creator follow-up in the authenticated private conversation.');
  await creator.locator('.creator-application-form button[type="submit"]').click();
  await creator.waitForLoadState('networkidle');
  await visit(client,'/account/notifications');
  await textOn(client,'New inquiry message');
  await visit(client,'/inquiries/'+inquiryId);
  await textOn(client,'QA-only creator follow-up');
  pass('Customer/creator private messages, notification, outsider denial');

  const contract='/contracts/'+fixture.contract_id;
  await visit(client,contract);
  await client.locator('input[name="confirm_offer"]').check();
  await client.getByRole('button',{name:'Send complete contract offer'}).click();
  await client.waitForLoadState('networkidle');
  await visit(creator,contract);
  await creator.locator('input[name="confirm_terms"]').check();
  await creator.getByRole('button',{name:'Accept agreement'}).click();
  await creator.waitForLoadState('networkidle');
  const contractDenied=await outsider.request.get(origin+contract,{maxRedirects:0});
  assert(contractDenied.status()===404,'Other customer can access private contract');
  await visit(creator,contract);
  await creator.locator('button[data-modal-open^="contract-milestone-"]').first().click();
  await creator.locator('dialog[id^="contract-milestone-"] textarea[name="delivery_notes"]')
    .fill('Original QA-only milestone delivered against the contract acceptance criteria without payment.');
  await creator.getByRole('button',{name:'Submit deliverable'}).click();
  await creator.waitForLoadState('networkidle');
  await visit(client,contract);
  await client.locator('button[data-modal-open^="contract-milestone-"]').first().click();
  await client.locator('dialog[id^="contract-milestone-"] textarea[name="feedback"]')
    .fill('QA deliverable accepted against the original documented requirements.');
  await client.getByRole('button',{name:'Accept deliverable'}).click();
  await client.waitForLoadState('networkidle');
  await visit(creator,contract);
  await creator.locator('input[name="confirm_completion"]').check();
  await creator.getByRole('button',{name:'Acknowledge delivery complete'}).click();
  await creator.waitForLoadState('networkidle');
  await visit(client,contract);
  await client.locator('input[name="confirm_completion"]').check();
  await client.getByRole('button',{name:'Confirm contract completed'}).click();
  await client.waitForLoadState('networkidle');
  await visit(client,contract);
  await textOn(client,'Completed');
  pass('Contract offered, accepted, delivered, reviewed and completed with no payment');

  const roleBlock=await moderator.request.get(origin+'/developer',{maxRedirects:0});
  assert(roleBlock.status()===403,'Ordinary admin can access super-admin tokens');
  await visit(superUser,'/developer');
  await superUser.locator('button[data-modal-open="new-api-token"]').click();
  await superUser.locator('#new-api-token input[name="name"]').fill('UAT Revocable Credential');
  await superUser.locator('#new-api-token select[name="days"]').selectOption('7');
  await superUser.locator('#new-api-token input[name="password"]').fill('UatIsolatedBrowserOnly123!');
  await superUser.locator('#new-api-token button[type="submit"]').click();
  await superUser.waitForLoadState('networkidle');
  let secret=(await superUser.locator('#new-token-secret').textContent())?.trim();
  assert(/^bmb_[0-9a-f]{64}$/.test(secret||''),'New API token was not displayed once');
  const before=await superUser.request.get(origin+'/api/v1/account',{headers:{Authorization:'Bearer '+secret},maxRedirects:0});
  assert(before.status()===200,'Fresh token was not authorized');
  await visit(superUser,'/developer');
  const tokenRow=superUser.locator('tr').filter({hasText:'UAT Revocable Credential'}).first();
  assert(await tokenRow.count()===1,'Token not present in account');
  await tokenRow.locator('button[data-modal-open^="token-"]').click();
  await tokenRow.locator('input[name="password"]').fill('UatIsolatedBrowserOnly123!');
  await tokenRow.getByRole('button',{name:'Revoke token'}).click();
  await superUser.waitForLoadState('networkidle');
  const after=await superUser.request.get(origin+'/api/v1/account',{headers:{Authorization:'Bearer '+secret},maxRedirects:0});
  secret='';
  assert(after.status()===401,'Revoked token continued authenticating');
  await visit(superUser,'/developer?status=revoked');
  await textOn(superUser,'UAT Revocable Credential');
  pass('Token created, authenticated, revoked, denied afterward, and role restricted');

  report.result='passed_with_manual_real_host_checks_outstanding';
}catch(e){
  report.result='blocked';
  report.reason=String(e?.message||e).replace(/bmb_[a-f0-9]{64}/g,'[REDACTED]');
  console.error('UAT BLOCKED: '+report.reason);
  process.exitCode=2;
}finally{
  report.completed_at=new Date().toISOString();
  await fs.mkdir('uat-acceptance-results',{recursive:true});
  await fs.writeFile('uat-acceptance-results/browser-report.json',JSON.stringify(report,null,2));
  await browser?.close();
}
