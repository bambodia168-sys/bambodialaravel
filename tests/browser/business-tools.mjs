import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const origin = process.env.BASE_URL ?? 'http://127.0.0.1:8095';
const browser = await chromium.launch();

try {
  for (const width of [1440, 820, 360]) {
    const context = await browser.newContext({ viewport: { width, height: 900 }, acceptDownloads: true });
    try {
      const page = await context.newPage();
      await page.goto(origin + '/en/login');
      await page.locator('input[name=email]').fill('browser.admin@example.test');
      await page.locator('input[name=password]').fill('BrowserOnlyPassword123!');
      await Promise.all([
        page.waitForURL(/dashboard/),
        page.locator('form.auth-form button[type=submit]').click(),
      ]);
      await page.goto(origin + '/en/dashboard/business-tools');
      await page.getByRole('heading', { name: 'Business Tools', exact: true }).waitFor();

      const workspace = page.locator('.business-tools');
      const toolbar = workspace.locator('.business-tools-actions');
      assert.equal(await workspace.locator('button, select, a').count(), 5);
      assert.equal(await toolbar.locator('button, select, a').count(), 5);
      const toolbarFits = await toolbar.evaluate(element => {
        const topRight = element.closest('header').getBoundingClientRect().right;
        return Math.abs(element.getBoundingClientRect().right - topRight) < 2
          && [...element.querySelectorAll('button, select, a')].every(control => {
            const bounds = control.getBoundingClientRect();
            return bounds.width > 0 && bounds.left >= 0 && bounds.right <= document.documentElement.clientWidth;
          });
      });
      assert.ok(toolbarFits, 'The action toolbar must align right and fit the viewport.');

      const backgrounds = await workspace.locator('.business-tools-report').first().evaluate(element => {
        const root = document.documentElement;
        root.dataset.theme = 'light';
        const light = getComputedStyle(element).backgroundColor;
        root.dataset.theme = 'dark';
        return [light, getComputedStyle(element).backgroundColor];
      });
      assert.notEqual(...backgrounds);

      await toolbar.getByLabel('Order report period').selectOption('7');
      await Promise.all([
        page.waitForURL(/days=7/),
        toolbar.getByRole('button', { name: 'Apply', exact: true }).click(),
      ]);
      await page.getByText('Orders placed in the last 7 calendar days, including today.').waitFor();
      const [download] = await Promise.all([
        page.waitForEvent('download'),
        toolbar.getByRole('link', { name: 'Export orders CSV' }).click(),
      ]);
      assert.equal(download.suggestedFilename(), 'orders-last-7-days.csv');

      await toolbar.getByLabel('Business tool', { exact: true }).selectOption('dashboard.marketplace.index:service');
      await Promise.all([
        page.waitForURL(/service-manager/),
        toolbar.getByRole('button', { name: 'Open tool', exact: true }).click(),
      ]);
      console.log('Business Tools toolbar and navigation passed at ' + width + 'px.');
    } finally {
      await context.close();
    }
  }
} finally {
  await browser.close();
}
