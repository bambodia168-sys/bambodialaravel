import assert from 'node:assert/strict';
import {chromium} from 'playwright';

const url = process.env.SYSTEM_CONFIGURATION_URL;
assert.equal(url, 'http://localhost:8095', 'Use the isolated browser fixture.');
const browser = await chromium.launch({headless: true});
const password = 'BrowserOnlyPassword123!';
const system = '/dashboard/system-configuration/security';

async function login(page, email) {
  await page.goto(url+'/login');
  await page.locator('input[name=email]').fill(email);
  await page.locator('input[name=password]').fill(password);
  await page.locator('.auth-form button[type=submit]').click();
  await page.waitForURL(current => current.pathname !== '/login');
}
async function setSwitch(page, name, enabled) {
  const control = page.getByRole('switch', {name, exact: true});
  if ((await control.getAttribute('aria-checked') === 'true') !== enabled) await control.click();
  assert.equal(await control.getAttribute('aria-checked'), String(enabled));
}

try {
  const admin = await browser.newPage({viewport: {width: 1280, height: 800}});
  await login(admin, 'browser.admin@example.test');
  await admin.goto(url+system);
  const nav = await admin.locator('#workspace-navigation').innerText();
  assert(nav.indexOf('Assets & Communication') < nav.indexOf('System Configuration'));
  assert(nav.indexOf('System Configuration') < nav.indexOf('Assistance'));
  await setSwitch(admin, 'Passkeys', true);
  await setSwitch(admin, 'Right-click protection', true);

  const viewer = await browser.newPage({viewport: {width: 390, height: 844}});
  await viewer.goto(url+'/');
  const blocked = await viewer.locator('[data-protected-content]').evaluate(element => {
    const event = () => new MouseEvent('contextmenu', {bubbles: true, cancelable: true});
    return {
      content: !element.dispatchEvent(event()),
      header: !document.querySelector('.site-header').dispatchEvent(event()),
    };
  });
  assert.deepEqual(blocked, {content: true, header: false});
  await setSwitch(admin, 'Right-click protection', false);

  const customer = await browser.newPage();
  const session = await customer.context().newCDPSession(customer);
  await session.send('WebAuthn.enable');
  const {authenticatorId} = await session.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2',
      transport: 'internal',
      hasResidentKey: true,
      hasUserVerification: true,
      automaticPresenceSimulation: true,
      isUserVerified: true,
    },
  });
  await login(customer, 'browser.client@example.test');
  await customer.goto(url+'/account/security');
  await customer.getByLabel('Device name').fill('Virtual QA device');
  await customer.locator('#passkey-password').fill(password);
  await customer.getByRole('button', {name: 'Add passkey'}).click();
  await customer.getByText('Virtual QA device', {exact: true}).waitFor({timeout: 20000});
  const {credentials} = await session.send('WebAuthn.getCredentials', {authenticatorId});
  assert.equal(credentials.length, 1);

  const token = await customer.locator('meta[name=csrf-token]').getAttribute('content');
  assert.equal((await customer.request.post(url+'/logout', {form: {_token: token}, maxRedirects: 0})).status(), 302);
  await customer.goto(url+'/login');
  await customer.getByRole('button', {name: 'Sign in with a passkey'}).click();
  await customer.waitForURL(current => current.pathname === '/account', {timeout: 20000});
  await customer.goto(url+'/account/security');
  await customer.getByText(/Last used/).waitFor();
  console.log('PASS System Configuration navigation, public protection toggle, and verified passkey enrollment and sign-in.');
} finally {
  await browser.close();
}
