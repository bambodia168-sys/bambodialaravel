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
  const headings = await admin.locator('#workspace-navigation .workspace-nav-heading').allTextContents();
  const start = headings.indexOf('Assets & Communication');
  assert.notEqual(start, -1, 'The admin sidebar renders its navigation groups');
  assert.deepEqual(headings.slice(start, start + 3), ['Assets & Communication', 'System Configuration', 'Assistance']);
  assert.equal(await admin.getByRole('button', {name: /^Configure /}).count(), 5);
  await admin.setViewportSize({width: 390, height: 844});
  await admin.getByRole('button', {name: 'Configure Two-factor authentication (2FA) enrollment', exact: true}).click();
  const enrollment = admin.getByRole('dialog', {name: 'Two-factor authentication (2FA) enrollment', exact: true});
  const dialogBounds = await enrollment.boundingBox();
  assert(dialogBounds && dialogBounds.x >= 0 && dialogBounds.x + dialogBounds.width <= 390);
  assert.match(await enrollment.getByRole('link', {name: 'Open Account Security'}).getAttribute('href'), /\/account\/security#account-two-factor$/);
  await enrollment.getByRole('button', {name: 'Cancel', exact: true}).click();
  await enrollment.waitFor({state: 'hidden'});
  await admin.setViewportSize({width: 1280, height: 800});
  for (const feature of ['Content protector', 'Selection protector']) {
    await admin.getByRole('button', {name: 'Configure '+feature, exact: true}).click();
    const dialog = admin.getByRole('dialog', {name: feature, exact: true});
    assert.equal(await dialog.getByLabel('Status').inputValue(), '0');
    await admin.keyboard.press('Escape');
    await dialog.waitFor({state: 'hidden'});
  }
  await setSwitch(admin, 'Passkeys', true);
  await admin.getByRole('button', {name: 'Configure Passkeys', exact: true}).click();
  const passkeySettings = admin.getByRole('dialog', {name: 'Passkeys', exact: true});
  assert.match(await passkeySettings.getByRole('link', {name: 'Open Account Security'}).getAttribute('href'), /\/account\/security#account-passkeys$/);
  await passkeySettings.getByRole('button', {name: 'Cancel', exact: true}).click();
  await admin.getByRole('button', {name: 'Configure Right-click protection', exact: true}).click();
  const rightClickSettings = admin.getByRole('dialog', {name: 'Right-click protection', exact: true});
  await rightClickSettings.getByLabel('Status').selectOption('1');
  await rightClickSettings.getByRole('button', {name: 'Save', exact: true}).click();
  assert.equal(await admin.getByRole('switch', {name: 'Right-click protection', exact: true}).getAttribute('aria-checked'), 'true');

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

  await admin.goto(url+'/dashboard/system-configuration/social-auth');
  await admin.setViewportSize({width: 390, height: 740});
  for (const [label, key] of [['Google', 'google'], ['Facebook', 'facebook'], ['LinkedIn', 'linkedin'], ['GitHub', 'github']]) {
    await admin.getByRole('button', {name: 'Configure '+label, exact: true}).click();
    const dialog = admin.getByRole('dialog', {name: 'Configure '+label, exact: true});
    await dialog.waitFor();
    const bounds = await dialog.boundingBox();
    assert(bounds && bounds.x >= 0 && bounds.x + bounds.width <= 390 && bounds.height <= 740);
    assert.equal(await dialog.locator('code').innerText(), url+'/auth/'+key+'/callback');
    assert.equal(await dialog.getByLabel('Client secret', {exact: true}).inputValue(), '');
    await dialog.getByRole('button', {name: 'Cancel', exact: true}).click();
    await dialog.waitFor({state: 'hidden'});
  }
  await admin.getByRole('button', {name: 'Configure Google', exact: true}).click();
  const google = admin.getByRole('dialog', {name: 'Configure Google', exact: true});
  await admin.context().grantPermissions(['clipboard-read', 'clipboard-write'], {origin: url});
  await google.getByRole('button', {name: 'Copy URL', exact: true}).click();
  await google.getByRole('button', {name: 'Copied', exact: true}).waitFor();
  assert.equal(await admin.evaluate(() => navigator.clipboard.readText()), url+'/auth/google/callback');
  await google.getByLabel('Client ID', {exact: true}).fill('fixture-client');
  await google.getByLabel('Client secret', {exact: true}).fill('synthetic-test-secret');
  await google.getByRole('button', {name: 'Save credentials', exact: true}).click();
  await google.waitFor({state: 'hidden'});
  await admin.getByRole('button', {name: 'Configure Google', exact: true}).click();
  assert.equal(await google.getByLabel('Client secret', {exact: true}).inputValue(), '');
  await google.getByLabel('Client ID', {exact: true}).fill('updated-fixture-client');
  await google.getByRole('button', {name: 'Save credentials', exact: true}).click();
  await google.getByText('Enter the secret for this client ID.', {exact: true}).waitFor();
  assert.equal(await google.getByLabel('Client ID', {exact: true}).inputValue(), 'updated-fixture-client');
  await admin.setViewportSize({width: 1280, height: 800});
  await admin.goto(url+system);

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
  await customer.getByText('Virtual QA device', {exact: true}).waitFor({timeout: 15000}).catch(async error => {
    console.error('Passkey enrollment status:', await customer.locator('[data-passkey-form] [role=status]').allTextContents());
    throw error;
  });
  await customer.locator('#account-passkeys > [role=status]').getByText('Passkey saved successfully.').waitFor();
  assert.equal(new URL(customer.url()).hash, '#account-passkeys');
  const {credentials} = await session.send('WebAuthn.getCredentials', {authenticatorId});
  assert.equal(credentials.length, 1);

  await customer.getByLabel('Authenticator name').fill('Travel phone');
  await customer.getByLabel('Current password', {exact: true}).first().fill(password);
  await customer.getByRole('button', {name: 'Set up two-factor authentication'}).click();
  const qr = customer.getByRole('img', {name: 'QR code for authenticator setup'});
  await qr.waitFor();
  assert(await qr.evaluate(image => image.complete && image.naturalWidth > 0));
  await customer.getByRole('button', {name: 'Cancel setup'}).click();
  await qr.waitFor({state: 'detached'});

  const token = await customer.locator('meta[name=csrf-token]').getAttribute('content');
  assert.equal((await customer.request.post(url+'/logout', {form: {_token: token}, maxRedirects: 0})).status(), 302);
  await customer.goto(url+'/login');
  await customer.getByRole('button', {name: 'Sign in with a passkey'}).click();
  await customer.waitForURL(current => current.pathname === '/account', {timeout: 15000}).catch(async error => {
    console.error('Passkey sign-in status:', await customer.locator('[data-passkey-form] [role=status]').allTextContents());
    throw error;
  });
  await customer.goto(url+'/account/security');
  await customer.getByText(/Last used/).waitFor();
  await setSwitch(admin, 'Passkeys', false);
  console.log('PASS System Configuration navigation, public protection toggle, and verified passkey enrollment and sign-in.');
} finally {
  await browser.close();
}
