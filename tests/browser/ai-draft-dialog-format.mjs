import { chromium } from 'playwright';
import fs from 'node:fs/promises';

const base = process.env.BASE_URL || 'http://127.0.0.1:8094';
if (base !== 'http://127.0.0.1:8094') {
  throw Error('AI Draft UI acceptance must run only on isolated localhost.');
}
const out = process.env.BROWSER_ARTIFACT_DIR || 'browser-artifacts';
await fs.mkdir(out, {recursive:true});

const browser = await chromium.launch({headless:true});
let checks = 0;
const description = [
  'A professional platform for managing real estate listings and projects.',
  '',
  'Key features:',
  '- Property listings and project management',
  '- Company profiles and administrator dashboards',
  '- Responsive layouts and media uploads',
  '',
  'Designed for agencies and property managers. <img src=x onerror=alert(1)>',
].join('\n');

try {
  for (const viewport of [
    {name:'desktop',width:1440,height:900},
    {name:'mobile',width:390,height:844},
  ]) {
    const context = await browser.newContext({viewport:{width:viewport.width,height:viewport.height}});
    const page = await context.newPage();
    await page.route('**/builder/ai-drafts', route => route.fulfill({
      status:200,contentType:'application/json',
      body:JSON.stringify({id:42,title:'Professional Laravel Property Manager',description,remaining:4}),
    }));
    await page.route('**/builder/ai-drafts/42/reviewed', route => route.fulfill({
      status:200,contentType:'application/json',
      body:JSON.stringify({reviewed:true,message:'Review accepted'}),
    }));

    await page.goto(base+'/en/login',{waitUntil:'networkidle'});
    await page.locator('input[name="email"]').fill('browser.admin@example.test');
    await page.locator('input[name="password"]').fill('BrowserOnlyPassword123!');
    await page.locator('form.auth-form button[type="submit"]').click();
    await page.waitForURL(/\/dashboard/);

    const response = await page.goto(base+'/builder?type=product',{waitUntil:'networkidle'});
    if (response?.status() !== 200) throw Error(viewport.name+': Builder unavailable');
    if (await page.locator('link[href*="ai-draft-studio.css"]').count() !== 1)
      throw Error(viewport.name+': scoped modal styles missing');
    if (await page.locator('script[src*="ai-draft-studio.js?v=2"]').count() !== 1)
      throw Error(viewport.name+': reviewed AI formatter asset missing');
    checks += 3;

    // The provider is deliberately not configured in this disposable QA DB.
    // Enable the *button in this test DOM only*, never enable the API provider.
    await page.locator('[data-modal-open="studio-ai-draft"]').evaluate(el => { el.disabled = false; });
    await page.locator('[data-modal-open="studio-ai-draft"]').click();
    await page.locator('#studio-ai-draft').waitFor({state:'visible'});

    const metrics = await page.locator('.ai-draft-consent').evaluate(label => {
      const input = label.querySelector('input[type="checkbox"]');
      const copy = label.querySelector('span');
      const check = input.getBoundingClientRect();
      const text = copy.getBoundingClientRect();
      return {
        inputWidth:check.width, inputHeight:check.height,
        aligned:check.left < text.left && check.right <= text.left - 3,
        vertical:check.top >= text.top - 6 && check.top <= text.top + 13,
        layout:getComputedStyle(label).display,
        documentWidth:document.documentElement.scrollWidth,
        viewport:window.innerWidth,
      };
    });
    if (metrics.inputWidth > 25 || metrics.inputHeight > 25 ||
        !metrics.aligned || !metrics.vertical || metrics.layout !== 'grid' ||
        metrics.documentWidth > metrics.viewport + 5) {
      throw Error(viewport.name+': consent checkbox layout regression '+JSON.stringify(metrics));
    }
    checks += 5;

    const checkbox = page.locator('#ai-draft-consent');
    if (await checkbox.isChecked()) throw Error('Consent must be unchecked on open');
    await page.locator('.ai-draft-consent span').click();
    if (!await checkbox.isChecked()) throw Error('Consent label click did not toggle checkbox');
    checks += 2;
    await page.screenshot({path:out+'/ai-draft-modal-'+viewport.name+'.png'});

    await page.locator('#ai-brief').fill(
      'A Laravel property management application with listing management, '+
      'company profiles, projects, admin dashboard and responsive layouts.'
    );
    await page.locator('[data-ai-generate]').click();
    await page.locator('[data-ai-review]').waitFor({state:'visible'});
    if ((await page.locator('[data-ai-description]').inputValue()) !== description)
      throw Error(viewport.name+': reviewed multiline content changed');
    checks++;

    await page.locator('[data-ai-apply]').click();
    await page.locator('#studio-ai-draft').waitFor({state:'hidden'});
    const rich = page.locator('[data-editor-surface]');
    if (!await rich.locator('p').count() || await rich.locator('h3').count() !== 1 ||
        await rich.locator('ul > li').count() !== 3 ||
        await rich.locator('img,script,iframe').count() !== 0) {
      throw Error(viewport.name+': safe structured editor content missing');
    }
    const html = await rich.innerHTML();
    if (html.includes('<img src=') || !html.includes('&lt;img') ||
        (await page.locator('input[name="title"]').inputValue()) !==
          'Professional Laravel Property Manager') {
      throw Error(viewport.name+': model markup escaped incorrectly or title not applied');
    }
    if (!await page.getByText('Not saved').first().count()) {
      throw Error(viewport.name+': AI applied a draft without manual Builder save');
    }
    checks += 6;
    await page.screenshot({path:out+'/ai-draft-editor-'+viewport.name+'.png',fullPage:true});
    await context.close();
  }
  console.log('PASS: '+checks+' isolated AI Draft modal consent, safe formatting and review/apply checks');
} finally {
  await browser.close();
}
