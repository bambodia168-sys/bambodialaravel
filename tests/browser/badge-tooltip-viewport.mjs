import { chromium } from 'playwright';
import fs from 'node:fs/promises';

const origin = process.env.BASE_URL || 'http://127.0.0.1:8094';
if (origin !== 'http://127.0.0.1:8094') {
  throw Error('Badge tooltip viewport test must run only against isolated localhost.');
}
const out = process.env.BROWSER_ARTIFACT_DIR || 'browser-artifacts';
await fs.mkdir(out, {recursive: true});
const browser = await chromium.launch({headless: true});
let checks = 0;

try {
  for (const viewport of [
    {name: 'desktop', width: 1440, height: 900},
    {name: 'mobile-width', width: 390, height: 720},
  ]) {
    const context = await browser.newContext({viewport: {width: viewport.width, height: viewport.height}});
    const page = await context.newPage();
    const response = await page.goto(origin + '/', {waitUntil: 'networkidle'});
    if (response?.status() !== 200) throw Error(viewport.name + ': homepage unavailable.');
    await page.waitForFunction(() => document.documentElement.classList.contains('badge-tooltip-viewport-ready'));
    checks++;

    for (const theme of ['light', 'dark']) {
      await page.evaluate(t => { document.documentElement.dataset.theme = t; }, theme);
      for (const edge of ['top-left', 'top-right', 'bottom-left', 'bottom-right']) {
        await page.evaluate(edge => {
          document.querySelector('[data-qa-tooltip-fixture]')?.remove();
          const clip = document.createElement('div');
          clip.dataset.qaTooltipFixture = 'true';
          clip.style.cssText = 'position:fixed;z-index:30000;width:142px;height:70px;overflow:hidden;background:var(--surface,white);border:1px solid var(--line,#ccc);';
          clip.style[edge.includes('top') ? 'top' : 'bottom'] = '1px';
          clip.style[edge.includes('left') ? 'left' : 'right'] = '1px';
          clip.innerHTML = '<div class="creator-badge-row creator-badge-row--tips creator-badge-row--badge">' +
            '<span class="creator-badge" tabindex="0" aria-label="Rising Talent: Responsive engagement">' +
            '<span class="creator-badge-fallback creator-badge-icon">★</span>' +
            '<span class="creator-badge-tooltip" role="tooltip"><strong>Rising Talent</strong>' +
            '<span>Long verified description with no clipped text near sidebars or browser edges.</span></span></span></div>';
          document.body.appendChild(clip);
        }, edge);
        const trigger = page.locator('[data-qa-tooltip-fixture] .creator-badge');
        await trigger.hover();
        await page.locator('body > .creator-badge-tooltip--viewport').waitFor({state: 'visible'});
        const result = await page.evaluate(() => {
          const badge = document.querySelector('[data-qa-tooltip-fixture] .creator-badge');
          const original = badge?.querySelector('.creator-badge-tooltip');
          const overlay = document.querySelector('body > .creator-badge-tooltip--viewport');
          const rect = overlay?.getBoundingClientRect();
          return {
            ready: document.documentElement.classList.contains('badge-tooltip-viewport-ready'),
            originalDisplay: original ? getComputedStyle(original).display : null,
            rendered: !!overlay,
            parentIsBody: overlay?.parentElement === document.body,
            fixed: overlay ? getComputedStyle(overlay).position === 'fixed' : false,
            zIndex: overlay ? Number(getComputedStyle(overlay).zIndex) : 0,
            inside: !!rect && rect.left >= 10 && rect.right <= innerWidth - 10 &&
              rect.top >= 10 && rect.bottom <= innerHeight - 10,
            text: overlay?.textContent || '',
            accessibleName: badge?.getAttribute('aria-label'),
          };
        });
        if (!result.ready || result.originalDisplay !== 'none' || !result.rendered ||
            !result.parentIsBody || !result.fixed || result.zIndex < 100000 ||
            !result.inside || !result.text.includes('Rising Talent') ||
            result.accessibleName !== 'Rising Talent: Responsive engagement') {
          throw Error(viewport.name + ' ' + theme + ' ' + edge + ': ' + JSON.stringify(result));
        }
        checks += 9;
      }
      await page.screenshot({path: out + '/badge-tooltip-viewport-' + viewport.name + '-' + theme + '.png'});

      const trigger = page.locator('[data-qa-tooltip-fixture] .creator-badge');
      await page.mouse.move(viewport.width / 2, viewport.height / 2);
      await page.waitForFunction(() => !document.querySelector('body > .creator-badge-tooltip--viewport'));
      await trigger.focus();
      await page.locator('body > .creator-badge-tooltip--viewport').waitFor({state: 'visible'});
      await page.keyboard.press('Escape');
      await page.waitForFunction(() => !document.querySelector('body > .creator-badge-tooltip--viewport'));
      checks += 3;

      await page.evaluate(() => {
        const row = document.querySelector('[data-qa-tooltip-fixture] .creator-badge-row');
        row.classList.remove('creator-badge-row--tips');
        row.classList.add('creator-badge-row--no-tips');
      });
      await page.mouse.move(viewport.width / 2, viewport.height / 2);
      await trigger.hover();
      if (await page.locator('body > .creator-badge-tooltip--viewport').count()) {
        throw Error('Tooltip should respect admin-disabled mode.');
      }
      checks++;
    }
    await context.close();
  }

  // Confirm the same adapter is loaded inside the authenticated workspace layout.
  const page = await browser.newPage();
  await page.goto(origin + '/en/login', {waitUntil: 'networkidle'});
  await page.locator('input[name="email"]').fill('browser.admin@example.test');
  await page.locator('input[name="password"]').fill('BrowserOnlyPassword123!');
  await page.locator('form.auth-form button[type="submit"]').click();
  await page.waitForURL(/\/dashboard/, {timeout: 15000});
  await page.goto(origin + '/dashboard/creator-badges', {waitUntil: 'networkidle'});
  const script = page.locator('script[src*="creator-badge-tooltip-viewport.js"]');
  const css = page.locator('link[href*="creator-badge-tooltip-viewport.css"]');
  if (await script.count() !== 1 || await css.count() !== 1) {
    throw Error('Viewport tooltip adapter missing from authenticated workspace.');
  }
  checks += 2;
  await page.close();
  console.log('PASS: ' + checks + ' creator badge viewport, clipping, focus and workspace checks');
} finally {
  await browser.close();
}
