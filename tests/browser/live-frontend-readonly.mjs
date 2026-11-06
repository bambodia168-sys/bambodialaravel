import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';

const origin = 'https://bambodia.com';
const output = process.env.LIVE_VISUAL_REPORT_DIR || 'live-frontend-evidence';
const screenshotFolder = path.join(output, 'screenshots');
const expectedKinds = ['product', 'project', 'service', 'freelance'];
const pages = [
  { label: 'homepage', url: '/' },
  { label: 'search', url: '/search' },
  ...expectedKinds.map(kind => ({ label: kind, url: '/search?type=' + kind })),
  { label: 'jobs', url: '/jobs' }
];
const devices = [
  { name: 'desktop', width: 1440, height: 900, mobile: false },
  { name: 'tablet', width: 820, height: 1180, mobile: true },
  { name: 'iphone', width: 390, height: 844, mobile: true },
  { name: 'android', width: 412, height: 915, mobile: true }
];
const results = [];
const notes = [];
const failures = [];
const blocked = [];
let browser;
await fs.mkdir(screenshotFolder, { recursive: true });

function item(name, status, detail = '') {
  results.push({ name, status, detail });
  console.log(status.toUpperCase() + ': ' + name + (detail ? ' — ' + detail : ''));
  if (status === 'fail') failures.push(name + ': ' + detail);
  if (status === 'blocked') blocked.push(name + ': ' + detail);
}
function expect(condition, message) {
  if (!condition) throw new Error(message);
}
function safeName(value) {
  return String(value).replace(/[^a-z0-9_-]+/gi, '-').slice(0, 75);
}
async function visit(page, url) {
  const target = new URL(url, origin);
  expect(target.origin === origin, 'Refused non-Bambodia page');
  const response = await page.goto(target.href, { waitUntil: 'domcontentloaded', timeout: 40000 });
  expect(response && response.status() === 200,
    'GET ' + target.pathname + ' returned HTTP ' + (response ? response.status() : 'no response'));
  await page.locator('.site-header').waitFor({ state: 'visible', timeout: 12000 });
  await page.waitForTimeout(300);
  return response;
}
async function inspectPage(page, device, theme, label, url) {
  await visit(page, url);
  const data = await page.evaluate(() => {
    const selector = sel => document.querySelector(sel);
    const rect = sel => {
      const node = selector(sel);
      if (!node) return null;
      const b = node.getBoundingClientRect();
      return { x: b.x, y: b.y, top: b.top, bottom: b.bottom, left: b.left,
        right: b.right, width: b.width, height: b.height };
    };
    const root = document.documentElement;
    const styles = getComputedStyle(root);
    const bodyStyles = getComputedStyle(document.body);
    const logo = selector(root.dataset.theme === 'dark' ?
      'img.brand-logo-dark' : 'img.brand-logo-light');
    const logoStyles = logo ? getComputedStyle(logo) : null;
    const heading = selector('main h1') || selector('h1');
    const imgs = [...document.querySelectorAll('img')].filter(img => {
      const b = img.getBoundingClientRect();
      return b.width > 10 && b.height > 10;
    });
    return {
      title: document.title,
      heading: heading?.innerText?.trim() || '',
      viewport: innerWidth,
      documentWidth: Math.max(root.scrollWidth, document.body.scrollWidth),
      pageTheme: root.dataset.theme,
      bg: bodyStyles.backgroundColor,
      textColor: bodyStyles.color,
      fontSize: parseFloat(bodyStyles.fontSize),
      header: rect('.site-header'),
      logo: { present: Boolean(logo), width: logo?.naturalWidth || 0,
        visible: Boolean(logo && logoStyles && logoStyles.display !== 'none' &&
          logoStyles.visibility !== 'hidden' && logoStyles.opacity !== '0') },
      main: rect('.marketplace-results-pane'),
      filter: rect('.marketplace-filter-sidebar'),
      typeDir: rect('.marketplace-type-directory'),
      listingCount: document.querySelectorAll('article.marketplace-card').length,
      brokenImages: imgs.filter(img => img.complete && img.naturalWidth === 0)
        .map(img => img.getAttribute('src')?.slice(0,160)).slice(0,7),
      hasFooter: Boolean(selector('footer')),
      typeTab: selector('.marketplace-discovery-tabs a[aria-current="page"]')?.textContent.trim() || '',
      selectedType: selector('select[name="type"]')?.value ?? null,
      rootTokens: {
        text: styles.getPropertyValue('--text').trim(),
        muted: styles.getPropertyValue('--muted').trim(),
        surface: styles.getPropertyValue('--surface').trim(),
        background: styles.getPropertyValue('--bg').trim(),
        accent: styles.getPropertyValue('--accent').trim(),
        accentContrast: styles.getPropertyValue('--accent-contrast').trim()
      }
    };
  });
  expect(data.viewport === device.width, 'Viewport mismatch: ' + JSON.stringify(data.viewport));
  expect(data.documentWidth <= data.viewport + 5,
    'Horizontal overflow: document ' + data.documentWidth + ', viewport ' + data.viewport);
  expect(data.header && data.header.height <= 78 && data.header.height >= 44,
    'Header too tall or absent: ' + JSON.stringify(data.header));
  expect(data.logo.present && data.logo.visible && data.logo.width > 0,
    'Theme-correct branding image did not load: ' + theme);
  expect(data.pageTheme === theme, 'Theme was not applied: ' + data.pageTheme);
  expect(data.fontSize >= 12 && data.fontSize <= 17, 'Body text size out of range: ' + data.fontSize);
  expect(data.heading && data.title, 'Page heading or title missing');
  expect(data.hasFooter, 'Shared footer missing');
  expect(data.brokenImages.length === 0, 'Visible broken images: ' + data.brokenImages.join(', '));
  if (label === 'homepage') {
    expect(data.typeDir && data.typeDir.width > 100, 'Four-type discovery directory missing');
    expect(await page.locator('.marketplace-type-directory-tile').count() === 4,
      'Not all four live public marketplace entry tiles are present');
  }
  if (label === 'search' || expectedKinds.includes(label)) {
    expect(data.main && data.filter, 'Search content or filter sidebar missing');
    expect(await page.locator('input[name="q"],input#marketplace-q').count() > 0,
      'Search keyword input missing');
    expect(await page.locator('select[name="sort"]').count() > 0,
      'Sort selector missing');
    expect(await page.locator('.marketplace-discovery-tabs a').count() === 5,
      'Five discovery tabs not present');
    if (device.width > 760) {
      expect(data.main.left >= data.filter.right - 3,
        'Desktop filter sidebar overlaps results: ' + JSON.stringify({ filter: data.filter, main: data.main }));
    } else {
      expect(data.main.top >= data.filter.bottom - 3,
        'Mobile filters do not stack above results: ' + JSON.stringify({ filter: data.filter, main: data.main }));
    }
    if (expectedKinds.includes(label)) {
      expect(data.selectedType === label,
        'Filter type selector mismatch: ' + data.selectedType + ' vs ' + label);
      expect(data.typeTab.toLowerCase().includes(label === 'freelance' ? 'freelance' : label),
        'Current category tab mismatch: ' + data.typeTab);
    }
  }
  if (device.name === 'desktop' || device.name === 'iphone') {
    const screenshotPath = path.join(screenshotFolder,
      safeName(device.name + '-' + theme + '-' + label) + '.png');
    await page.screenshot({ path: screenshotPath, fullPage: true, animations: 'disabled',
      timeout: 25000 });
  }
  item(device.name + ' / ' + theme + ' / ' + label, 'pass',
    'HTTP 200, no horizontal overflow, header ' + data.header.height.toFixed(0) +
    'px; cards ' + data.listingCount);
  return data;
}
async function inspectDetail(page, device, theme, kind, href) {
  await visit(page, href);
  const result = await page.evaluate(() => {
    const find = sel => document.querySelector(sel);
    const main = find('.premium-detail-main')?.getBoundingClientRect();
    const side = find('.premium-detail-sidebar')?.getBoundingClientRect();
    const cover = find('.premium-showcase-cover');
    const links = [...document.querySelectorAll('.premium-detail-tabs a')];
    return {
      heading: find('.premium-detail-intro h1')?.textContent?.trim(),
      main: main ? { right: main.right, top: main.top } : null,
      side: side ? { left: side.left, top: side.top } : null,
      tabs: links.map(link => ({
        href: link.getAttribute('href'),
        exists: Boolean(document.querySelector(link.getAttribute('href') || '#invalid'))
      })),
      cover: cover ? { complete: cover.complete, naturalWidth: cover.naturalWidth } : null,
      overflow: document.documentElement.scrollWidth - innerWidth,
      hasCheckoutButton: [...document.querySelectorAll('button,a')].some(el =>
        /^buy now$/i.test(el.textContent.trim()))
    };
  });
  expect(result.heading && result.main && result.side, 'Single listing page lacks premium split layout');
  expect(result.overflow <= 5, 'Detail page horizontal overflow: ' + result.overflow);
  expect(result.tabs.length >= 3 && result.tabs.every(t => t.exists),
    'Section tab has no corresponding content: ' + JSON.stringify(result.tabs));
  if (device.width > 760) expect(result.side.left >= result.main.right - 3,
    'Desktop detail sidebar overlaps primary content');
  else expect(result.side.top >= result.main.top, 'Mobile detail layout reversed');
  if (kind === 'product') {
    expect(result.cover && result.cover.complete && result.cover.naturalWidth > 0,
      'Approved product cover missing/broken on live product detail');
    expect(await page.getByText('Direct checkout is not enabled').count() > 0,
      'Product checkout limitations missing');
  } else {
    expect(await page.getByRole('link', { name: /sign in to contact/i }).count() > 0,
      'Private inquiry guest entry missing');
  }
  expect(!result.hasCheckoutButton, 'Unauthorized buy-now feature on payment-disabled platform');
  if (device.name === 'desktop' || device.name === 'iphone') {
    await page.screenshot({ path: path.join(screenshotFolder,
      safeName(device.name + '-' + theme + '-' + kind + '-detail') + '.png'),
      fullPage: true, animations: 'disabled' });
  }
  item(device.name + ' / ' + theme + ' / ' + kind + ' detail', 'pass',
    'Real approved item, intact anchor tabs, correct action and image');
}
async function inspectThemeToggle(browser) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await context.addInitScript(() => localStorage.setItem('bambodia-theme', 'light'));
  const page = await context.newPage();
  try {
    await visit(page, '/');
    await page.locator('.site-header .profile-dropdown summary').click();
    const button = page.locator('.site-header .profile-dropdown [data-theme-toggle]');
    expect(await button.isVisible(), 'Theme toggle not accessible in profile menu');
    const before = await page.locator('html').getAttribute('data-theme');
    await button.click();
    const after = await page.locator('html').getAttribute('data-theme');
    expect(before === 'light' && after === 'dark', 'Theme menu did not switch to dark');
    await page.reload({ waitUntil: 'domcontentloaded' });
    expect(await page.locator('html').getAttribute('data-theme') === 'dark',
      'Selected theme did not persist across reload');
    item('Live public profile menu / persistent theme switch', 'pass');
  } finally {
    await context.close();
  }
}

try {
  const browserChannel = (process.env.BAMBODIA_BROWSER_CHANNEL || '').trim();
  // On a user's own computer, channel=chrome tests their installed Chrome.
  // No anti-bot evasion, custom headers, credentials or production writes.
  browser = await chromium.launch({
    headless: process.env.BAMBODIA_HEADLESS !== 'false',
    ...(browserChannel ? { channel: browserChannel } : {})
  });
  await inspectThemeToggle(browser);
  const listingPaths = {};
  for (const device of devices) {
    for (const theme of ['light', 'dark']) {
      const context = await browser.newContext({
        viewport: { width: device.width, height: device.height },
        deviceScaleFactor: device.mobile ? 2 : 1, isMobile: device.mobile,
        hasTouch: device.mobile, colorScheme: theme
      });
      await context.addInitScript(selected =>
        localStorage.setItem('bambodia-theme', selected), theme);
      const page = await context.newPage();
      const pageErrors = [];
      page.on('pageerror', error => pageErrors.push(String(error.message).slice(0,180)));
      try {
        for (const entry of pages) {
          try {
            const pageInfo = await inspectPage(page, device, theme, entry.label, entry.url);
            if (expectedKinds.includes(entry.label)) {
              if (!listingPaths[entry.label]) listingPaths[entry.label] = [];
              const hrefs = await page.locator('article.marketplace-card a.marketplace-card-art')
                .evaluateAll(nodes => nodes.map(node => node.href).filter(Boolean).slice(0,2));
              listingPaths[entry.label].push({ device: device.name, theme,
                count: pageInfo.listingCount });
              if (!hrefs.length) {
                item(device.name + ' / ' + theme + ' / ' + entry.label + ' detail',
                  'skip', 'No publicly approved live listing; genuine media/actions not testable');
              } else {
                try { await inspectDetail(page, device, theme, entry.label, hrefs[0]); }
                catch (error) { item(device.name + ' / ' + theme + ' / ' + entry.label + ' detail',
                  'fail', String(error.message).slice(0,350)); }
              }
            }
          } catch (error) {
            item(device.name + ' / ' + theme + ' / ' + entry.label,
              'fail', String(error.message).slice(0,350));
          }
        }
        if (device.name === 'desktop' && theme === 'light') {
          try {
            await visit(page, '/search?type=product&q=uat-read-only-visual-check');
            expect(await page.locator('input#marketplace-q').inputValue() ===
              'uat-read-only-visual-check', 'GET search query was not retained');
            expect(await page.locator('select#marketplace-type').inputValue() === 'product',
              'GET listing type was not retained');
            expect(await page.locator('.marketplace-active-filters').isVisible(),
              'Applied filter chips not visible');
            item('Live GET-only search query and selected filters', 'pass');
          } catch (error) {
            item('Live GET-only search query and selected filters',
              'fail', String(error.message).slice(0,350));
          }
        }
        if (pageErrors.length) {
          notes.push({ device: device.name, theme, pageErrors: [...new Set(pageErrors)].slice(0,5) });
        }
      } finally { await context.close(); }
    }
  }
  if (Object.values(listingPaths).every(arr => arr.every(r => r.count === 0)))
    notes.push('All public listing categories currently empty; real-product media, cards and private inquiry actions require a populated approved QA host or real published items.');
} catch (error) {
  const message = String(error?.message || error).slice(0,450);
  if (message.includes('GET / returned HTTP 403')) {
    item('Origin access for automated browser', 'blocked',
      'The production site returned HTTP 403 to this browser/network. Respect access controls; run from your authorized local Chrome session. This is not proof of a UI layout defect.');
  } else {
    item('Runner infrastructure', 'fail', message);
  }
} finally {
  const summary = {
    checkedOrigin: origin,
    checkedAtUtc: new Date().toISOString(),
    runSha: process.env.GITHUB_SHA || 'local',
    readOnly: true,
    authenticates: false,
    dataMutations: false,
    devices,
    statuses: {
      passed: results.filter(x => x.status === 'pass').length,
      failed: results.filter(x => x.status === 'fail').length,
      blocked: results.filter(x => x.status === 'blocked').length,
      skipped: results.filter(x => x.status === 'skip').length
    },
    productionContentLimitations: [
      'Screenshots are from an actual live public website but Chromium-emulated viewports, not physical devices.',
      'No account sessions, private inquiry submissions or payment/checkout actions were exercised.',
      'Real approved single-detail pages and media can only be accepted when public records exist.',
      'A successful automated scan is not independent human design approval.'
    ],
    notes, checks: results
  };
  await fs.writeFile(path.join(output, 'acceptance-report.json'),
    JSON.stringify(summary, null, 2), 'utf8');
  await browser?.close();
  console.log('LIVE FRONTEND: ' + JSON.stringify(summary.statuses));
  if (failures.length || blocked.length) process.exitCode = 1;
}
