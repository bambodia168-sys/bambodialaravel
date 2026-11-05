import {chromium} from 'playwright';
import fs from 'node:fs/promises';

const origin = process.env.BASE_URL || 'http://127.0.0.1:8094';
const out = process.env.BROWSER_ARTIFACT_DIR || 'browser-artifacts';
await fs.mkdir(out,{recursive:true});

const devices = [
  {name:'desktop',viewport:{width:1440,height:900},mobile:false},
  {name:'tablet',viewport:{width:820,height:1180},mobile:true},
  {name:'iphone-emulation',viewport:{width:390,height:844},mobile:true},
  {name:'android-emulation',viewport:{width:412,height:915},mobile:true},
];
const paths = ['/', '/search', '/jobs', '/creators', '/login', '/register', '/forgot-password'];
const browser = await chromium.launch({headless:true});
let checks=0;
try {
  for(const device of devices){
    const context=await browser.newContext({
      viewport:device.viewport,deviceScaleFactor:device.mobile?2:1,
      isMobile:device.mobile,hasTouch:device.mobile,
    });
    const page=await context.newPage();
    for(const path of paths){
      const response=await page.goto(origin+path,{waitUntil:'networkidle',timeout:30000});
      if(!response || response.status()!==200)
        throw new Error(device.name+' '+path+' returned '+response?.status());
      if(!(await page.locator('main, .site-body, .auth-body').count()))
        throw new Error(device.name+' '+path+' has no renderable page root');
      const measured=await page.evaluate(()=>({
        documentWidth:document.documentElement.scrollWidth,
        viewport:window.innerWidth,
        pageHeight:document.documentElement.scrollHeight,
      }));
      if(measured.documentWidth>measured.viewport+5)
        throw new Error(device.name+' '+path+' horizontal overflow '+JSON.stringify(measured));
      checks++;
      if(path==='/'){
        if(!(await page.getByText('Browser QA Published Service').first().isVisible()))
          throw new Error(device.name+' homepage missing approved listing');
        if(!(await page.getByText('Browser QA Published Freelance Job').first().isVisible()))
          throw new Error(device.name+' homepage missing approved job');
        await page.screenshot({path:out+'/'+device.name+'-homepage.png',fullPage:true});
      }
      if(path==='/search'){
        if(!(await page.getByText('Browser QA Published Service').first().isVisible()))
          throw new Error(device.name+' marketplace missing published service');
      }
      if(['/login','/register','/forgot-password'].includes(path)){
        if(await page.locator('.auth-brand').count())
          throw new Error(device.name+' '+path+' unexpectedly renders brand outside auth modal');
        if(!(await page.locator('.auth-tools .auth-home-tool svg').isVisible()))
          throw new Error(device.name+' '+path+' missing Home SVG');
        if(!(await page.locator('.auth-tools [data-theme-toggle]').isVisible()))
          throw new Error(device.name+' '+path+' missing theme control');
        if(!(await page.locator('.auth-tools .language-menu summary .locale-flag').isVisible()))
          throw new Error(device.name+' '+path+' missing language SVG flag');
      }
      if(path==='/register'){
        if(!(await page.locator('input[name="terms_accepted"][required]').isVisible()))
          throw new Error(device.name+' registration Terms checkbox is missing');
        if(!(await page.getByText('Terms of Service').first().isVisible()))
          throw new Error(device.name+' registration has no published Terms');
      }
      if(path==='/creators'){
        if(!(await page.getByText('Original full-stack Laravel consulting').first().isVisible()))
          throw new Error(device.name+' missing opt-in creator');
      }
    }
    if(device.name==='desktop'){
      await page.goto(origin+'/register',{waitUntil:'networkidle'});
      const colors=await page.evaluate(()=>({
        primary:getComputedStyle(document.documentElement).getPropertyValue('--accent').trim(),
        secondary:getComputedStyle(document.documentElement).getPropertyValue('--secondary').trim(),
      }));
      if(!['#c2410c','#fb923c'].includes(colors.primary) || !['#1d4ed8','#93c5fd'].includes(colors.secondary))
        throw new Error('Expected orange/blue platform colors: '+JSON.stringify(colors));
      const language=page.locator('.auth-tools .language-menu');
      await language.locator('summary').click();
      const flags=language.locator('.language-options .locale-flag');
      if(await flags.count()!==8)
        throw new Error('Expected 8 supported locale SVG flags');
      await language.locator('button[name="locale"][value="km"]').click();
      await page.waitForLoadState('networkidle');
      if(!(await page.locator('.auth-tools .language-menu summary').getByText('ខ្មែរ').isVisible()))
        throw new Error('Locale POST did not persist selected Khmer locale');
      await page.locator('.auth-tools [data-theme-toggle]').click();
      if(!['dark','light'].includes(await page.locator('html').getAttribute('data-theme')))
        throw new Error('Theme did not toggle');
      checks+=4;
      await page.goto(origin+'/login',{waitUntil:'networkidle'});
      await page.locator('input[name="email"]').fill('browser.client@example.test');
      await page.locator('input[name="password"]').fill('BrowserOnlyPassword123!');
      await page.locator('form.auth-form button[type="submit"]').click();
      await page.waitForURL(/\/account\?/,{timeout:15000});
      await page.goto(origin+'/search',{waitUntil:'networkidle'});
      const save=page.locator('form[action*="/account/saved-listings/"] button[type="submit"]').first();
      if(!(await save.isVisible()))throw new Error('Saved-listing action missing for signed-in customer');
      await save.click();
      await page.goto(origin+'/account/saved-listings',{waitUntil:'networkidle'});
      if(!(await page.getByText('Browser QA Published Service').first().isVisible()))
        throw new Error('Saved listing not persisted for real browser customer session');
      checks+=3;

      // The Account shell must keep its sidebar and content scroll independent.
      await page.goto(origin+'/en/account',{waitUntil:'networkidle'});
      if(await page.locator('body').getAttribute('data-workspace')!=='account')
        throw new Error('Account workspace did not use shared premium shell');
      if(!(await page.locator('.workspace-menu-search input').isVisible()))
        throw new Error('Account header search is missing');
      await page.locator('[data-workspace-toggle]').click();
      if(!(await page.locator('body.workspace-collapsed').count()))
        throw new Error('Desktop sidebar did not collapse to icons');
      await page.goto(origin+'/en/account/saved-jobs',{waitUntil:'networkidle'});
      if(!(await page.locator('body.workspace-collapsed').count()))
        throw new Error('Sidebar collapse preference was not persisted between pages');
      // Expand labels before checking visibility of matching menu text.
      await page.locator('[data-workspace-toggle]').click();
      await page.locator('[data-workspace-search]').fill('Notifications');
      if(!(await page.locator('.workspace-nav-link:visible').getByText('Notifications').isVisible()))
        throw new Error('Workspace navigation search not functional');
      await page.locator('[data-workspace-search]').fill('');
      checks+=5;

      // Admin and Developer have separate role-governed menus, not account clones.
      const adminContext=await browser.newContext({viewport:device.viewport});
      const admin=await adminContext.newPage();
      await admin.goto(origin+'/en/login',{waitUntil:'networkidle'});
      await admin.locator('input[name="email"]').fill('browser.admin@example.test');
      await admin.locator('input[name="password"]').fill('BrowserOnlyPassword123!');
      await admin.locator('form.auth-form button[type="submit"]').click();
      await admin.waitForURL(/\/dashboard/,{timeout:15000});
      await admin.goto(origin+'/en/dashboard',{waitUntil:'networkidle'});
      if(await admin.locator('body').getAttribute('data-workspace')!=='admin')
        throw new Error('Admin shell missing');
      const sidebar=admin.locator('#workspace-sidebar');
      const main=admin.locator('#workspace-main');
      const scrollBefore=await main.evaluate(node=>node.scrollTop);
      await sidebar.evaluate(node=>node.scrollTo({top:node.scrollHeight,behavior:'instant'}));
      await admin.waitForTimeout(200);
      const scrollAfter=await main.evaluate(node=>node.scrollTop);
      if(scrollBefore!==scrollAfter)
        throw new Error('Scrolling admin sidebar moved main content');
      if(!(await admin.getByText('Feature Manager').first().count()))
        throw new Error('Admin menu lacks real controls');
      await admin.screenshot({path:out+'/desktop-admin-workspace.png',fullPage:false});
      await admin.goto(origin+'/en/developer',{waitUntil:'networkidle'});
      if(await admin.locator('body').getAttribute('data-workspace')!=='developer')
        throw new Error('Developer workspace is not distinct');
      if(await admin.locator('.workspace-nav-link').getByText('Digital File Vault').count())
        throw new Error('Developer navigation leaked administration modules');
      checks+=5;
      await adminContext.close();

      const creatorContext=await browser.newContext({viewport:device.viewport});
      const creator=await creatorContext.newPage();
      await creator.goto(origin+'/en/login',{waitUntil:'networkidle'});
      await creator.locator('input[name="email"]').fill('browser.creator@example.test');
      await creator.locator('input[name="password"]').fill('BrowserOnlyPassword123!');
      await creator.locator('form.auth-form button[type="submit"]').click();
      await creator.waitForURL(/\/account\?/,{timeout:15000});
      await creator.goto(origin+'/en/creator',{waitUntil:'networkidle'});
      if(await creator.locator('body').getAttribute('data-workspace')!=='creator')
        throw new Error('Creator workspace route did not render correctly');
      await creator.screenshot({path:out+'/desktop-creator-workspace.png',fullPage:false});
      checks+=2;
      await creatorContext.close();
    }
    await context.close();
  }
  console.log('PASS: '+checks+' responsive page and authenticated-collection checks (browser emulation only).');
} finally{
  await browser.close();
}
