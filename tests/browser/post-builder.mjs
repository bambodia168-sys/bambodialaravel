import { chromium } from 'playwright';
import fs from 'node:fs/promises';

const base = process.env.BASE_URL || 'http://127.0.0.1:8094';
if (base !== 'http://127.0.0.1:8094') throw Error('Post Builder acceptance runs only on isolated localhost');
const screenshots = process.env.BROWSER_ARTIFACT_DIR || 'browser-artifacts';
await fs.mkdir(screenshots, { recursive:true });
const browser = await chromium.launch({ headless:true });
let assertions = 0;
try {
  const context = await browser.newContext({viewport:{width:1440,height:900}});
  const page = await context.newPage();
  await page.goto(base+'/en/login',{waitUntil:'networkidle'});
  await page.locator('input[name="email"]').fill('browser.admin@example.test');
  await page.locator('input[name="password"]').fill('BrowserOnlyPassword123!');
  await page.locator('form.auth-form button[type="submit"]').click();
  await page.waitForURL(/\/dashboard/);

  for(const size of [{name:'desktop',w:1440,h:900},{name:'mobile',w:390,h:844}]){
    await page.setViewportSize({width:size.w,height:size.h});
    const response=await page.goto(base+'/dashboard/post-manager/create',{waitUntil:'networkidle'});
    if(response?.status() !== 200)throw Error('Create post route unavailable');
    const columns=page.locator('.policy-builder-columns');
    const main=page.getByRole('main',{name:'Main Content'});
    const aside=page.getByRole('complementary',{name:'Right Sidebar'});
    if(!await main.isVisible() || !await aside.isVisible())throw Error(size.name+' builder regions missing');
    const metrics=await columns.evaluate(el=>({
      columns:getComputedStyle(el).gridTemplateColumns,
      overflow:document.documentElement.scrollWidth>window.innerWidth+3,
    }));
    if(metrics.overflow)throw Error(size.name+' horizontal page overflow');
    await page.screenshot({path:screenshots+'/post-builder-'+size.name+'.png',fullPage:true});
    assertions+=3;
  }

  await page.setViewportSize({width:1440,height:900});
  await page.goto(base+'/dashboard/post-manager/create',{waitUntil:'networkidle'});
  const title='Verified Post Builder '+Date.now();
  await page.locator('#post-title').fill(title);
  await page.locator('#post-excerpt').fill('A reliable announcement about Bambodia digital products.');
  await page.locator('#post-category').selectOption('Technology');
  await page.locator('#post-tags').fill('News, Updates');
  await page.locator('#post-status').selectOption('draft');
  await page.locator('#post-body-editor').fill('This is a complete professional post with enough useful editorial content for the new Post Builder.');
  await page.getByRole('button',{name:'Create post'}).click();
  await page.waitForURL(/\/dashboard\/post-manager\/\d+\/edit/,{timeout:15000});
  const editUrl=page.url(), publicPath=await page.locator('.policy-reference-row').innerText();
  if(!publicPath.includes('Post ID'))throw Error('Save did not create a post');
  assertions+=2;
  // The admin navigation includes "Preview Visibility". Require the exact
  // Post Builder action, rather than a substring-matched sidebar link.
  const previewLink=page.locator('.post-builder-top-actions')
    .getByRole('link',{name:'Preview',exact:true});
  if(await previewLink.count()!==1
      || await previewLink.getAttribute('target')!=='_blank'
      || !(await previewLink.getAttribute('href'))?.match(/\/post-manager\/\d+\/preview(?:\?|$)/)) {
    throw Error('Post Builder draft preview must provide one safe new-tab action');
  }
  const [popup]=await Promise.all([
    context.waitForEvent('page',{timeout:15000}),
    previewLink.click(),
  ]);
  await popup.waitForLoadState('domcontentloaded');
  if(!(await popup.locator('body').innerText()).includes(title))throw Error('Draft preview missing title');
  await popup.close();
  await page.locator('#post-status').selectOption('published');
  await page.getByRole('button',{name:'Save changes'}).click();
  await page.waitForURL(editUrl,{waitUntil:'load',timeout:15000}).catch(()=>{});
  await page.goto(base+'/blog',{waitUntil:'networkidle'});
  if(!(await page.locator('body').innerText()).includes(title))throw Error('Published article missing from blog');
  assertions++;
  await page.goto(editUrl,{waitUntil:'networkidle'});
  await page.locator('[data-modal-open="post-delete-dialog"]').click();
  await page.locator('#post-confirm-title').fill(title);
  await page.getByRole('button',{name:'Delete permanently'}).click();
  await page.waitForURL(/\/dashboard\/post-manager(?:\?.*)?$/,{timeout:15000});
  assertions++;
  console.log('PASS: Post Builder editor layout and create/publish/delete, '+assertions+' checks');
  await context.close();
} finally {
  await browser.close();
}
