import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import fs from 'node:fs/promises';
import {chromium} from 'playwright';

const origin = process.env.BASE_URL;
if (origin !== 'http://127.0.0.1:8097' || process.env.BAMBODIA_UAT_ISOLATED !== '1') {
  throw new Error('Commerce pilot requires an isolated loopback application.');
}
const fixture = JSON.parse(await fs.readFile('storage/framework/commerce-pilot.json', 'utf8'));
const evidence = {commit: process.env.GITHUB_SHA ?? 'local', checks: [], liveMoney: false};
let browser;
const receipt = Buffer.from(fixture.receipt, 'base64');
const record = name => {
  evidence.checks.push(name);
  console.log('PASS '+name);
};

async function visit(page, path) {
  const url = new URL(path, origin);
  assert.equal(url.origin, origin);
  const response = await page.goto(url.href, {waitUntil: 'domcontentloaded'});
  assert.equal(response.status(), 200, url.pathname);
}
async function login(role, viewport) {
  const page = await browser.newPage({viewport});
  await visit(page, '/login');
  await submitCredentials(page.locator('form.auth-form'), role);
  await page.waitForURL(url => url.pathname !== '/login');
  return page;
}
async function submitCredentials(form, role) {
  await form.locator('input[name="email"]').fill('commerce-pilot.'+role+'@example.test');
  await form.locator('input[name="password"]').fill('CommercePilotBrowserOnly123!');
  await form.locator('button[type="submit"]').click();
}
async function order(page, listing, {guest = false} = {}) {
  await visit(page, listing);
  await page.getByRole('button', {name: 'Add to cart', exact: true}).click();
  const added = page.getByRole('dialog', {name: 'Added to cart', exact: true});
  await added.getByRole('button', {name: 'View cart', exact: true}).click();
  await page.getByRole('link', {name: 'Review checkout', exact: true}).click();
  if (guest) {
    await page.getByRole('link', {name: 'Create account', exact: true}).click();
    const registration = page.getByRole('dialog', {name: 'Create your account', exact: true});
    for (const [name, path] of [
      ['Terms of Service', '/policies/91204896-terms-of-service'],
      ['Privacy Policy', '/policies/21879964-privacy-policy'],
    ]) {
      const link = registration.getByRole('link', {name, exact: true});
      await link.waitFor();
      assert.equal(new URL(await link.getAttribute('href')).pathname, path);
    }
    const consent = registration.locator('input[name="terms_accepted"]');
    assert.notEqual(await consent.getAttribute('required'), null);
    assert.equal(await consent.isChecked(), false);
    await registration.getByRole('link', {name: 'Already have an account? Sign in', exact: true}).click();
    const signIn = page.getByRole('dialog', {name: 'Sign in to checkout', exact: true});
    await submitCredentials(signIn.locator('form.auth-form'), 'buyer');
    await page.waitForURL(url => url.pathname === '/checkout' && url.search === '');
    record('Guest cart survives checkout sign-in and registration shows both policy links');
  }
  const method = page.getByRole('radio', {name: 'Bank Transfer', exact: true});
  const normalBorder = await method.evaluate(radio => getComputedStyle(radio.closest('label')).borderColor);
  await method.check();
  const selected = await method.evaluate(radio => {
    const row = radio.closest('label');
    const icon = row.querySelector('svg');
    return {border: getComputedStyle(row).borderColor, iconWidth: icon.getBoundingClientRect().width};
  });
  assert.ok(selected.iconWidth >= 28);
  assert.notEqual(selected.border, normalBorder);
  const submit = page.getByRole('button', {name: 'Continue to payment', exact: true});
  const payload = await submit.evaluate(button => ({
    action: button.form.action, form: Object.fromEntries(new FormData(button.form)),
  }));
  assert.equal(payload.form.payment_method, await method.getAttribute('value'));
  await submit.click();
  await page.waitForURL(/\/account\/orders\/[a-f0-9-]{36}$/);
  const preferred = page.locator('.order-payment-option--selected');
  await preferred.getByText('Selected', {exact: true}).waitFor();
  assert.equal(await preferred.locator('details').getAttribute('open'), '');
  const orderPath = new URL(page.url()).pathname;
  const repeated = await page.request.post(payload.action, {form: payload.form, maxRedirects: 0});
  assert.equal(repeated.status(), 302);
  assert.equal(new URL(repeated.headers().location, origin).pathname, orderPath);
  return orderPath;
}
async function uploadReceipt(page, ref) {
  const transfer = page.getByText('Bank Transfer transfer', {exact: true});
  if (!(await transfer.evaluate(label => label.closest('details').open))) await transfer.click();
  await page.getByLabel('Transfer reference').fill(ref);
  await page.getByLabel('Payment receipt').setInputFiles({name: 'pilot-receipt.png', mimeType: 'image/png', buffer: receipt});
  await page.getByRole('button', {name: 'Submit receipt', exact: true}).click();
  await page.getByText('Your payment receipt is awaiting review.', {exact: false}).waitFor();
}
async function reviewPayment(page, orderPath, ref, approve, total) {
  await visit(page, '/dashboard/commerce-operations');
  const link = page.getByRole('row').filter({hasText: orderPath.split('/').at(-1)})
    .getByRole('link', {name: 'Review receipt', exact: true});
  await link.click();
  await page.getByText('Review transfer', {exact: true}).click();
  if (approve) {
    await page.getByLabel('Verified reference').fill(ref);
    await page.getByLabel('Verified USD amount').fill(total);
  }
  await page.getByLabel('Review note').fill('Verified against the isolated pilot statement.');
  await page.getByRole('button', {name: approve ? 'Approve transfer' : 'Reject transfer', exact: true}).click();
  await page.getByText(approve ? 'Verified' : 'Rejected', {exact: true}).first().waitFor();
}

try {
  browser = await chromium.launch({headless: true});
  const buyer = await browser.newPage({viewport: {width: 390, height: 844}});
  const seller = await login('seller', {width: 1365, height: 900});
  const admin = await login('admin', {width: 1365, height: 900});
  const outsider = await login('outsider', {width: 1365, height: 900});

  await visit(admin, '/dashboard/payment-methods');
  await admin.getByRole('button', {name: 'Configure Wing', exact: true}).click();
  const wing = admin.getByRole('dialog', {name: 'Configure Wing', exact: true});
  const previewImage = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAAAAADhZOFXAAAAEElEQVR4nGP8zwABTAwUMQBJQQEPeWDz+AAAAABJRU5ErkJggg==', 'base64');
  await wing.locator('input[name="qr"]').setInputFiles({name: 'pilot-merchant.png', mimeType: 'image/png', buffer: previewImage});
  const selectedQr = wing.getByRole('img', {name: 'Selected Wing merchant QR'});
  await selectedQr.waitFor();
  assert.ok(await selectedQr.evaluate(async image => {
    await image.decode();
    return image.naturalWidth > 0;
  }));
  await wing.getByRole('button', {name: 'Save settings', exact: true}).click();
  await admin.getByText('Payment method settings saved.', {exact: true}).waitFor();
  await visit(admin, '/dashboard/payment-methods');
  await admin.getByRole('button', {name: 'Configure Wing', exact: true}).click();
  const savedQr = wing.getByRole('img', {name: 'Saved Wing merchant QR'});
  await savedQr.waitFor();
  assert.ok(await savedQr.evaluate(async image => {
    await image.decode();
    return image.naturalWidth > 0;
  }));
  const qrUrl = await wing.getByRole('link', {name: 'View full-size QR', exact: true}).getAttribute('href');
  assert.equal((await outsider.request.get(qrUrl)).status(), 403);
  assert.equal(await wing.getByLabel('Availability').inputValue(), '0');
  await wing.getByRole('button', {name: 'Close configuration'}).click();
  record('Hidden manual payment method previews selected and saved QR images privately');

  const stripe = admin.getByRole('dialog', {name: 'Configure Stripe', exact: true});
  const openStripe = admin.getByRole('button', {name: 'Configure Stripe', exact: true});
  await openStripe.click();
  await stripe.getByLabel('Credentials environment').selectOption({label: 'Live Production'});
  await stripe.getByLabel('API secret key').fill('sk_live_isolated_pilot_fixture');
  await stripe.getByLabel('Webhook signing secret').fill('whsec_isolated_pilot_fixture');
  await stripe.getByRole('button', {name: 'Save settings', exact: true}).click();
  await admin.getByText('Payment method settings saved.', {exact: true}).waitFor();
  await openStripe.click();
  await stripe.getByText(/Live Production credentials saved/).waitFor();
  await stripe.getByText('Stripe checkout environment Sandbox', {exact: true}).waitFor();
  assert.equal(await stripe.getByLabel('Credentials environment').inputValue(), 'sandbox');
  assert.equal(await stripe.getByLabel('API secret key').inputValue(), '');
  assert.equal(await stripe.getByLabel('Use this environment for new Stripe checkouts').isChecked(), false);
  await stripe.getByRole('button', {name: 'Close configuration'}).click();
  record('Stripe live credentials can be saved while Sandbox remains active');

  const productOrder = await order(buyer, fixture.product_url, {guest: true});
  record('Checkout retries return the same product order');
  await uploadReceipt(buyer, 'PILOT-PRODUCT-REJECT');
  assert.equal(await buyer.getByRole('link', {name: 'Download product'}).count(), 0);
  await reviewPayment(admin, productOrder, 'PILOT-PRODUCT-REJECT', false);
  await visit(buyer, productOrder);
  await buyer.getByText('Your payment receipt was rejected.', {exact: false}).waitFor();
  assert.equal(await buyer.getByRole('link', {name: 'Download product'}).count(), 0);
  assert.equal(await buyer.getByRole('link', {name: 'View receipt'}).count(), 0);
  record('Rejected manual receipt leaves the order locked');

  await uploadReceipt(buyer, 'PILOT-PRODUCT-APPROVE');
  await visit(admin, '/dashboard/payment-methods');
  await admin.getByRole('button', {name: 'Pause new payments', exact: true}).click();
  await admin.getByRole('button', {name: 'Enable payments', exact: true}).waitFor();
  await reviewPayment(admin, productOrder, 'PILOT-PRODUCT-APPROVE', true, '29.00');
  await visit(buyer, productOrder);
  const download = buyer.getByRole('link', {name: 'Download product', exact: true});
  await download.waitFor();
  await visit(admin, '/dashboard/payment-methods');
  await admin.getByRole('button', {name: 'Enable payments', exact: true}).click();
  await admin.getByRole('button', {name: 'Pause new payments', exact: true}).waitFor();
  record('Site-wide pause allows a submitted receipt to finish fulfillment and collection can resume');
  const archiveURL = await download.getAttribute('href');
  const file = await buyer.request.get(archiveURL);
  assert.equal(file.status(), 200);
  assert.equal(createHash('sha256').update(await file.body()).digest('hex'), fixture.sha256);
  assert.equal((await outsider.request.get(archiveURL)).status(), 404);
  await buyer.getByRole('link', {name: 'My licenses', exact: true}).click();
  await buyer.getByText('River Market Restaurant Website Template', {exact: false}).first().waitFor();
  record('Approved receipt delivers the exact template ZIP and an immediate buyer license');

  const serviceOrder = await order(buyer, fixture.service_url);
  await uploadReceipt(buyer, 'PILOT-SERVICE');
  assert.equal(await buyer.getByRole('link', {name: 'Open service workspace'}).count(), 0);
  await reviewPayment(admin, serviceOrder, 'PILOT-SERVICE', true, '75.00');
  await visit(buyer, serviceOrder);
  await buyer.getByRole('link', {name: 'Open service workspace', exact: true}).click();
  const workspace = new URL(buyer.url()).pathname;
  assert.equal((await outsider.request.get(origin+workspace)).status(), 404);
  await buyer.getByLabel('Order requirements').fill('Install the restaurant website and explain how to update the menu.');
  await buyer.getByRole('button', {name: 'Send requirements', exact: true}).click();
  await buyer.getByText('The creator is preparing your delivery.', {exact: true}).waitFor();

  await visit(seller, workspace);
  await seller.getByLabel('Delivery notes').fill('The menu and hours are configured. The attached package includes the template and setup guide.');
  await seller.getByLabel('Attachment (optional)').setInputFiles('storage/framework/commerce-pilot-template.zip');
  await seller.getByRole('button', {name: 'Submit delivery', exact: true}).click();
  await seller.getByText('Waiting for the buyer to review your delivery.', {exact: true}).waitFor();
  await visit(buyer, workspace);
  const deliveryURL = await buyer.getByRole('link', {name: /Download commerce-pilot-template.zip/}).getAttribute('href');
  assert.equal((await buyer.request.get(deliveryURL)).status(), 200);
  assert.equal((await outsider.request.get(deliveryURL)).status(), 404);
  await buyer.getByLabel('Feedback (required for revisions)').fill('Please include instructions to update the opening hours.');
  await buyer.getByRole('button', {name: 'Request revision', exact: true}).click();
  await buyer.getByText('The creator can submit a revised delivery.', {exact: true}).waitFor();
  await visit(seller, workspace);
  await seller.getByLabel('Delivery notes').fill('Edit the Opening hours section of index.html and redeploy the file.');
  await seller.getByRole('button', {name: 'Submit delivery', exact: true}).click();
  await seller.getByText('Waiting for the buyer to review your delivery.', {exact: true}).waitFor();
  await visit(buyer, workspace);
  await buyer.getByRole('button', {name: 'Accept delivery', exact: true}).click();
  await buyer.getByText('The buyer accepted the delivery.', {exact: true}).waitFor();
  record('Buyer and seller complete private delivery, revision and acceptance');

  await buyer.getByText('Open a private support ticket', {exact: true}).click();
  await buyer.getByLabel('Subject', {exact: true}).fill('Pilot service delivery question');
  await buyer.getByLabel('Describe the issue').fill('Please confirm the recommended publishing steps.');
  await buyer.getByRole('button', {name: 'Create ticket', exact: true}).click();
  await buyer.getByText('Related order', {exact: false}).waitFor();
  assert.equal((await outsider.request.get(buyer.url())).status(), 404);

  await visit(buyer, serviceOrder);
  await buyer.getByLabel('Rating', {exact: true}).selectOption('5');
  await buyer.getByLabel('Public review (optional)').fill('Pilot installation and handoff delivered as described.');
  await buyer.getByRole('button', {name: 'Post review', exact: true}).click();
  await buyer.getByText('Purchase review', {exact: true}).waitFor();
  await visit(outsider, fixture.service_url);
  await outsider.getByText('Verified buyer · 5 out of 5', {exact: true}).waitFor();
  record('Service order links to a private ticket and one eligible buyer review');
} catch (error) {
  evidence.error = error.message;
  throw error;
} finally {
  await fs.mkdir('uat-acceptance-results', {recursive: true});
  await fs.writeFile('uat-acceptance-results/commerce-pilot.json', JSON.stringify(evidence, null, 2)+'\n');
  await browser?.close();
}
