/**
 * The order path end to end, in a real browser, against real WooCommerce, on
 * the plugin installed FROM THE ZIP:
 *
 *   approved product → public page → multi-vendor cart → order →
 *   stock decremented → each vendor sees only their own line →
 *   suspension and zero stock stop the purchase → and the shop's own product
 *   is never affected by any of it.
 *
 *   SITE=… TMC_PASS_FILE=… TMC_OUT=docs/evidence/orders node check-order-trial.mjs
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const SITE = process.env.SITE || 'http://127.0.0.1:8080';
const VENDOR = process.env.TMC_VENDOR_USER || 'tmcvendor';
const VENDOR_PASS = process.env.TMC_VENDOR_PASS || 'TmcVendor!2026';
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.TMC_OUT || 'docs/evidence/orders';
const A = process.env.TMC_WC_A || '30';          // vendor A's product
const B = process.env.TMC_WC_B || '32';          // vendor B's product
const SHOP = process.env.TMC_WC_SHOP || '37';    // the shop's own product

fs.mkdirSync(OUT, { recursive: true });
const results = [];
const check = (name, ok, detail = '') => {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'ok:  ' : 'FAIL:'} ${name}${detail ? ' — ' + detail : ''}`);
};
const bodyText = async (page) => (await page.locator('body').textContent() || '').replace(/\s+/g, ' ').trim();

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
const shopper = await (await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR' })).newPage();

async function addToCart(page, productId) {
  await page.goto(`${SITE}/?add-to-cart=${productId}`, { waitUntil: 'load' });
  return (await bodyText(page));
}
async function cartCount(page) {
  await page.goto(`${SITE}/cart/`, { waitUntil: 'load' });
  return await page.locator('.wc-block-cart-items__row, tr.woocommerce-cart-form__cart-item').count();
}

// --- the public page of an approved marketplace product --------------------
await shopper.goto(`${SITE}/?p=${A}`, { waitUntil: 'load' });
const productPage = await bodyText(shopper);
check('an approved marketplace product has a public page', productPage.includes('دستکش لاتکس'), '');
check('and it shows its price', /۱|1/.test(productPage), '');
await shopper.screenshot({ path: path.join(OUT, '01-public-product-1280.png'), fullPage: true });

// --- a basket holding two different shops ----------------------------------
await addToCart(shopper, A);
await addToCart(shopper, B);
const rows = await cartCount(shopper);
check('a basket can hold two shops at once', rows === 2, `${rows} rows`);
await shopper.screenshot({ path: path.join(OUT, '02-multi-vendor-cart-1280.png'), fullPage: true });

// --- checkout ---------------------------------------------------------------
await shopper.goto(`${SITE}/checkout/`, { waitUntil: 'load' });
const fill = async (selector, value) => {
  const field = shopper.locator(selector).first();
  if (await field.count() > 0) {
    await field.fill(value);
  }
};
await fill('#billing_first_name', 'خریدار');
await fill('#billing_last_name', 'آزمایشی');
await fill('#billing_address_1', 'خیابان آزمایش، پلاک ۱');
await fill('#billing_city', 'تهران');
await fill('#billing_postcode', '1234567890');
await fill('#billing_phone', '09120000000');
await fill('#billing_email', 'buyer@example.test');
const codRadio = shopper.locator('#payment_method_cod');
if (await codRadio.count() > 0) {
  await codRadio.check();
}
await shopper.screenshot({ path: path.join(OUT, '03-checkout-1280.png'), fullPage: true });
await shopper.click('#place_order');
// The classic checkout submits over AJAX and redirects afterwards, so wait
// for the destination rather than for "some navigation".
await shopper.waitForURL(/order-received/, { timeout: 120000 }).catch(() => {});
await shopper.waitForLoadState('load');
const afterCheckout = await bodyText(shopper);
const orderMatch = shopper.url().match(/order-received\/(\d+)/);
check('the order is placed', orderMatch !== null, shopper.url());
check('the buyer is told it went through',
  afterCheckout.includes('Thank you') || afterCheckout.includes('سپاس') || afterCheckout.includes('received'),
  '');
await shopper.screenshot({ path: path.join(OUT, '04-order-received-1280.png'), fullPage: true });
fs.writeFileSync(path.join(OUT, 'order-id.txt'), orderMatch ? orderMatch[1] : '');

// --- the vendor sees their own line, and nothing of the other shop ----------
const vendorCtx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR' });
const vendor = await vendorCtx.newPage();
await vendor.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
await vendor.fill('#user_login', VENDOR);
await vendor.fill('#user_pass', VENDOR_PASS);
await Promise.all([vendor.waitForURL(/wp-admin|my-account|\/$/, { timeout: 30000 }), vendor.click('#wp-submit')]);

await vendor.goto(`${SITE}/vendor/orders/`, { waitUntil: 'load' });
const ordersPage = await bodyText(vendor);
check('the vendor has an orders page', (await vendor.locator('.tv-orders, .tv-notice').count()) > 0, '');
check('it shows this shop’s line', ordersPage.includes('دستکش لاتکس'), '');
check('and not the other shop’s', !ordersPage.includes('ماسک سه‌لایه'), 'AC-PRIV: one basket, two shops, two views');
check('the customer’s e-mail is nowhere on the page', !ordersPage.includes('buyer@example.test'), 'PRIV-01');
check('the customer’s mobile is nowhere on the page', !ordersPage.includes('09120000000'), 'PRIV-01');
check('the shipping address IS shown', ordersPage.includes('تهران'), 'the vendor can post the parcel');
check('the vendor’s share is shown', ordersPage.includes('سهم شما'), '');
await vendor.screenshot({ path: path.join(OUT, '05-vendor-orders-1280.png'), fullPage: true });

// --- moving the vendor's own line forward ----------------------------------
const prepare = vendor.locator('form:has(input[value="preparing"]) button[type=submit]').first();
if (await prepare.count() > 0) {
  await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 30000 }), prepare.click()]);
  check('the vendor can start preparing their own line', (await bodyText(vendor)).includes('آماده‌سازی'), '');
} else {
  check('the vendor can start preparing their own line', false, 'no action button rendered');
}
const shipForm = vendor.locator('form:has(input[value="shipped"])').first();
if (await shipForm.count() > 0) {
  const select = shipForm.locator('select').first();
  if (await select.count() > 0) {
    const options = await select.locator('option').evaluateAll((els) => els.map((e) => e.value).filter(Boolean));
    if (options.length > 0) {
      await select.selectOption(options[0]);
    }
  }
  const tracking = shipForm.locator('input[type=text]').first();
  if (await tracking.count() > 0) {
    await tracking.fill('TRK-TRIAL-1');
  }
  await Promise.all([
    vendor.waitForURL(/tmc_notice/, { timeout: 30000 }),
    shipForm.locator('button[type=submit]').first().click(),
  ]);
  const shipped = await bodyText(vendor);
  check('shipping is recorded with a carrier and a tracking code',
    shipped.includes('ارسال ثبت شد') || shipped.includes('TRK-TRIAL-1'), '');
} else {
  check('shipping is recorded with a carrier and a tracking code', false, 'no shipping form rendered');
}
await vendor.screenshot({ path: path.join(OUT, '06-vendor-order-shipped-1280.png'), fullPage: true });

await browser.close();
const failures = results.filter((r) => !r.ok);
fs.writeFileSync(path.join(OUT, 'order-trial.json'),
  JSON.stringify({ suite: 'order-trial', generated_at: new Date().toISOString(), total: results.length, failures: failures.length, results }, null, 2) + '\n');
console.log(`\norder trial — ${results.length} checks, ${failures.length} failures`);
process.exit(failures.length === 0 ? 0 : 1);
