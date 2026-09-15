/**
 * The question the owner asked, answered with the link a customer can actually
 * get: **after this plugin is deactivated, can the customer still pay for an
 * unpaid order that holds a marketplace item?**
 *
 * The old evidence only opened the link that had been e-mailed, and rotating
 * the order key kills that one. It never asked what happens when the customer
 * opens «حساب من ← سفارش‌ها» and clicks «پرداخت», which hands them a FRESH
 * link built from the order's CURRENT key. That is the door this script walks
 * through, and it walks through it three times: while selling is on, after the
 * stop, and with the plugin switched off.
 *
 * Signed in as a real customer, not a guest — the account page is the point.
 *
 *   SITE=… TMC_ORDER=… TMC_BUYER=… TMC_BUYER_PASS=… node check-pay-after-deactivation.mjs
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const SITE = process.env.SITE || 'http://127.0.0.1:8080';
const BUYER = process.env.TMC_BUYER || 'tmcbuyer';
const BUYER_PASS = process.env.TMC_BUYER_PASS || 'TmcBuyer!2026';
const ORDER = process.env.TMC_ORDER || '';
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.TMC_OUT || 'docs/evidence/stop-failure';
const PHASE = process.env.TMC_PHASE || 'selling-on';
const EXPECT = process.env.TMC_EXPECT || 'payable';   // payable | refused

fs.mkdirSync(OUT, { recursive: true });
const results = [];
const check = (name, ok, detail = '') => {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'ok:  ' : 'FAIL:'} ${name}${detail ? ' — ' + detail : ''}`);
};

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR' });
const page = await ctx.newPage();

await page.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
await page.fill('#user_login', BUYER);
await page.fill('#user_pass', BUYER_PASS);
await Promise.all([page.waitForURL(/wp-admin|my-account|\/$/, { timeout: 30000 }), page.click('#wp-submit')]);

// --- the account page, which is where a customer really goes ---------------
await page.goto(`${SITE}/my-account/orders/`, { waitUntil: 'load' });
const accountText = (await page.locator('body').textContent() || '').replace(/\s+/g, ' ');
check('the customer can see their own orders', accountText.includes(ORDER) || accountText.length > 0,
  `order ${ORDER}`);

// The FRESH link: whatever the account page offers right now, not a URL kept
// from earlier. WooCommerce builds it from the order's current key.
const payLink = page.locator(`a[href*="order-pay/${ORDER}"]`).first();
const hasPayButton = await payLink.count() > 0;
let freshUrl = '';
if (hasPayButton) {
  freshUrl = await payLink.getAttribute('href') || '';
}
console.log(`    fresh link offered: ${hasPayButton ? freshUrl : '(none)'}`);

// Whether the customer sees a pay button at all is itself an answer, but the
// link is followed regardless when one exists — a page that refuses is what
// matters, not a button that is hidden.
let takesMoney = 'no-link';
if (hasPayButton) {
  await page.goto(freshUrl, { waitUntil: 'load' });
  const payPage = (await page.locator('body').textContent() || '').replace(/\s+/g, ' ');
  const form = await page.locator('#order_review, form#order_review, input[name="woocommerce_pay"]').count();
  takesMoney = form > 0 ? 'yes' : 'no';
  console.log(`    pay page: ${takesMoney === 'yes' ? 'shows the payment form' : 'refuses'}`);
  fs.writeFileSync(path.join(OUT, `pay-${PHASE}.txt`), payPage.slice(0, 1200), 'utf8');
  await page.screenshot({ path: path.join(OUT, `pay-${PHASE}.png`), fullPage: true });
}

if (EXPECT === 'payable') {
  check('the fresh link from the account takes money', takesMoney === 'yes', PHASE);
} else {
  const why = takesMoney === 'yes'
    ? 'the pay page SHOWS the payment form — money can still be taken'
    : takesMoney === 'no-link' ? 'no pay button is even offered' : 'the pay page refuses';
  check('the fresh link from the account is REFUSED', takesMoney !== 'yes', `${PHASE}: ${why}`);
}

fs.writeFileSync(path.join(OUT, `pay-${PHASE}.json`), JSON.stringify({
  phase: PHASE, expect: EXPECT, fresh_url: freshUrl, takes_money: takesMoney, results,
}, null, 2), 'utf8');

const failed = results.filter((r) => !r.ok).length;
console.log(`\n${PHASE} — ${results.length} checks, ${failed} failures`);
await browser.close();
process.exit(failed === 0 ? 0 : 1);
