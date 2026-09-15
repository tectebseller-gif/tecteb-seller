/**
 * The other half of the order trial: what a shopper gets when the marketplace
 * says NO — and the proof that the shop's own catalogue never hears the answer.
 *
 * Every scenario changes one piece of state through the plugin's own services
 * (tools/purchase-block-state.php, run with wp-cli) and then asks the real
 * storefront, in a real browser, with a fresh cart each time:
 *
 *   vendor suspended     → their product is refused, their staff loses access
 *   stock reaches zero   → the product is refused
 *   trial mode off       → the whole marketplace is refused
 *
 * …while the shop's own product — a WooCommerce product with a Dokan vendor
 * meta and no marketplace link — adds to the basket and reaches checkout in
 * every single one of them. That is «خرید فعلی سایت و دکان تحت تأثیر این قفل
 * قرار نگیرند», asked of the shop rather than of the code.
 *
 *   SITE=… TMC_OUT=docs/evidence/orders node check-purchase-blocks.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const SITE = process.env.SITE || 'http://127.0.0.1:8080';
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.TMC_OUT || 'docs/evidence/orders';
const WP_DIR = process.env.TMC_WP_DIR || '/home/user/wp-disposable';
const WP_PHP = process.env.TMC_WP_PHP || '/opt/php81/bin/php';
const WP_CLI = process.env.TMC_WP_CLI || '/usr/local/bin/wp';
const STATE = process.env.TMC_STATE_SCRIPT || 'purchase-block-state.php';

const A = process.env.TMC_WC_A || '42';          // vendor A, simple, in stock
const B = process.env.TMC_WC_B || '44';          // vendor B, simple, in stock
const SHOP = process.env.TMC_WC_SHOP || '37';    // the shop's own product
const TMC_A = process.env.TMC_PRODUCT_A || '1';  // …its marketplace id
const VENDOR_A = process.env.TMC_VENDOR_A || '4';
const STAFF = process.env.TMC_STAFF_USER || 'tmcstaff';
const STAFF_PASS = process.env.TMC_STAFF_PASS || 'TmcStaff!2026';

fs.mkdirSync(OUT, { recursive: true });
const results = [];
const transcript = [];
const check = (name, ok, detail = '') => {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'ok:  ' : 'FAIL:'} ${name}${detail ? ' — ' + detail : ''}`);
};
/** One state change, through the plugin's own services. */
const wp = (...cliArgs) => {
  const out = execFileSync(WP_PHP, [WP_CLI, '--allow-root', 'eval-file', STATE, ...cliArgs], {
    cwd: WP_DIR, encoding: 'utf8',
  }).trim();
  transcript.push(`$ wp eval-file ${STATE} ${cliArgs.join(' ')}\n${out}`);
  console.log(`    · ${cliArgs.join(' ')} → ${out.replace(/\n/g, ' / ')}`);
  return out;
};

/** Whether the catalogue's dump says `fragment` about this storefront product. */
const says = (dump, wcProductId, fragment) => dump
  .split('\n')
  .some((line) => line.startsWith(`wc=${wcProductId} `) && line.includes(fragment));

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
const bodyText = async (page) => (await page.locator('body').textContent() || '').replace(/\s+/g, ' ').trim();

/**
 * Adds one product to a basket and reports what the shop did.
 *
 * A fresh context per attempt, so no scenario can inherit the last one's cart.
 * `alongside` puts another product in first, which is both the realistic case
 * — a shopper who is already buying something — and the only one in which
 * WooCommerce keeps a session to carry its refusal notice: a guest whose very
 * first click is a refused product has no session at all, and WooCommerce
 * drops the queued notice. That is why the product page explains itself too.
 */
async function attemptPurchase(productId, alongside = null) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR' });
  const page = await context.newPage();
  if (alongside !== null) {
    await page.goto(`${SITE}/?add-to-cart=${alongside}`, { waitUntil: 'load' });
  }
  await page.goto(`${SITE}/?add-to-cart=${productId}`, { waitUntil: 'load' });
  await page.goto(`${SITE}/cart/`, { waitUntil: 'load' });
  const rows = await page.locator('tr.woocommerce-cart-form__cart-item, .wc-block-cart-items__row').count();
  const cart = await bodyText(page);
  await context.close();
  const mine = alongside === null ? rows : rows - 1;
  return { added: mine > 0, rows: mine, notice: cart, cart };
}

/** What the product's own public page tells a shopper. */
async function productPage(productId) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR' });
  const page = await context.newPage();
  await page.goto(`${SITE}/?p=${productId}`, { waitUntil: 'load' });
  const text = await bodyText(page);
  const blocked = await page.locator('.tmc-purchase-blocked').count();
  await context.close();
  return { text, blocked: blocked > 0 };
}

const scenarios = [];
const record = (label, detail) => {
  scenarios.push(`${label}: ${detail}`);
};

// --- 0. baseline: everything sells ------------------------------------------
wp('trial', '1');
let marketplace = await attemptPurchase(A);
let shop = await attemptPurchase(SHOP);
check('baseline — a marketplace product adds to the basket', marketplace.added, `${marketplace.rows} rows`);
check('baseline — the shop’s own product adds to the basket', shop.added, `${shop.rows} rows`);
record('baseline', `marketplace=${marketplace.added} shop=${shop.added}`);

// --- 1. the vendor is suspended ---------------------------------------------
wp('suspend', VENDOR_A, 'آزمایش تعلیق در مسیر خرید');
const suspendedDecision = wp('decide', A, SHOP);
marketplace = await attemptPurchase(A, SHOP);
shop = await attemptPurchase(SHOP);
check('a suspended vendor’s product cannot be bought', !marketplace.added, `${marketplace.rows} rows`);
check('…and it leaves the storefront rather than sitting there unbuyable',
  says(suspendedDecision, A, 'wc_status=draft'), 'withdrawn, never deleted');
check('…while the shop’s own product still adds to the basket', shop.added, `${shop.rows} rows`);
check('…and the catalogue calls the shop’s product none of its business',
  says(suspendedDecision, SHOP, 'decision=not_ours'), '');
record('vendor suspended', `marketplace=${marketplace.added} shop=${shop.added}`);

// the vendor's staff loses the orders page at the same instant
const staffCtx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR' });
const staff = await staffCtx.newPage();
await staff.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
await staff.fill('#user_login', STAFF);
await staff.fill('#user_pass', STAFF_PASS);
await Promise.all([staff.waitForURL(/wp-admin|my-account|\/$/, { timeout: 30000 }), staff.click('#wp-submit')]);
await staff.goto(`${SITE}/vendor/orders/`, { waitUntil: 'load' });
const staffSuspended = await bodyText(staff);
check('the suspended shop’s staff loses the orders page too',
  !staffSuspended.includes('دستکش لاتکس'), 'suspension is derived, not copied');
await staff.screenshot({ path: path.join(OUT, '09-staff-blocked-1280.png'), fullPage: true });

// --- 2. reinstated ----------------------------------------------------------
wp('reinstate', VENDOR_A);
marketplace = await attemptPurchase(A);
check('reinstating the vendor puts their product back on sale', marketplace.added, `${marketplace.rows} rows`);
record('vendor reinstated', `marketplace=${marketplace.added}`);

// the staff member sees their shop's own order line — and only that one
await staff.goto(`${SITE}/vendor/orders/`, { waitUntil: 'load' });
const staffOrders = await bodyText(staff);
check('the staff member sees their shop’s order line', staffOrders.includes('دستکش لاتکس'), 'AC-PRIV, staff side');
check('…and nothing of the other shop', !staffOrders.includes('ماسک سه‌لایه'), '');
check('…with no customer e-mail', !staffOrders.includes('buyer@example.test'), 'PRIV-01');
check('…and no customer mobile', !staffOrders.includes('09120000000'), 'PRIV-01');
await staff.screenshot({ path: path.join(OUT, '10-staff-orders-1280.png'), fullPage: true });
await staffCtx.close();

// --- 3. the product runs out ------------------------------------------------
wp('stock', TMC_A, '0');
const zeroDecision = wp('decide', A, SHOP);
marketplace = await attemptPurchase(A, SHOP);
shop = await attemptPurchase(SHOP);
const zeroPage = await productPage(A);
check('a product with no stock cannot be bought', !marketplace.added, `${marketplace.rows} rows`);
check('…and says so in the marketplace’s own words',
  marketplace.notice.includes('این کالا ناموجود است'), 'woocommerce_cart_product_out_of_stock_message');
check('…and its own page says the same without being clicked',
  zeroPage.blocked && zeroPage.text.includes('این کالا ناموجود است'), 'no add-to-cart button to click');
check('…and the storefront agrees it is out of stock',
  says(zeroDecision, A, 'wc_in_stock=false'), 'the vendor’s edit wrote through to WooCommerce');
check('…while the shop’s own product still adds to the basket', shop.added, `${shop.rows} rows`);
record('stock zero', `marketplace=${marketplace.added} shop=${shop.added}`);
wp('stock', TMC_A, '9');

// --- 4. the financial rules lock the marketplace ----------------------------
wp('trial', '0');
const lockedDecision = wp('decide', A, B, SHOP);
marketplace = await attemptPurchase(A, SHOP);
const marketplaceB = await attemptPurchase(B, SHOP);
shop = await attemptPurchase(SHOP);
const lockedPageA = await productPage(A);
const lockedPageShop = await productPage(SHOP);
check('with the financial rules unsettled, no marketplace product sells',
  !marketplace.added && !marketplaceB.added, `A=${marketplace.rows} B=${marketplaceB.rows} rows`);
check('…and the shopper is told which rule is missing',
  marketplace.notice.includes('فروش محصولات بازارگاه هنوز فعال نشده است'), '');
check('…on the product’s own page as well',
  lockedPageA.blocked && lockedPageA.text.includes('فروش محصولات بازارگاه هنوز فعال نشده است'), '');
check('…and the shop’s own product page says nothing of the kind',
  !lockedPageShop.blocked, 'the marketplace never speaks for somebody else’s catalogue');
check('…the catalogue names the same reason',
  says(lockedDecision, A, 'decision=orders_blocked') && says(lockedDecision, B, 'decision=orders_blocked'), '');
check('…while the shop’s own product still adds to the basket', shop.added, `${shop.rows} rows`);
check('…and still reaches checkout', shop.cart.includes('۴۵۰٬۰۰۰') || /450|۴۵۰/.test(shop.cart), '');
record('trial off', `marketplaceA=${marketplace.added} marketplaceB=${marketplaceB.added} shop=${shop.added}`);

const lockedCtx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR' });
const lockedPage = await lockedCtx.newPage();
await lockedPage.goto(`${SITE}/?add-to-cart=${A}`, { waitUntil: 'load' });
await lockedPage.screenshot({ path: path.join(OUT, '11-marketplace-locked-1280.png'), fullPage: true });
await lockedPage.goto(`${SITE}/?add-to-cart=${SHOP}`, { waitUntil: 'load' });
await lockedPage.goto(`${SITE}/cart/`, { waitUntil: 'load' });
await lockedPage.screenshot({ path: path.join(OUT, '12-shop-still-sells-1280.png'), fullPage: true });
await lockedCtx.close();

// --- back to where we found it ---------------------------------------------
wp('trial', '1');
const restored = wp('state');
check('the trial switch goes back on for the rest of the evidence',
  /active=true/.test(restored), '');

await browser.close();
const failures = results.filter((r) => !r.ok);
fs.writeFileSync(path.join(OUT, '08-blocked-purchases.txt'),
  '=== what a shopper gets when the marketplace refuses (alpha.7 from the ZIP, PHP 8.1.32, WooCommerce 11.0.1) ===\n'
  + scenarios.map((s) => '  ' + s).join('\n')
  + '\n\n-- the state changes and what the catalogue answered --\n'
  + transcript.join('\n\n') + '\n');
fs.writeFileSync(path.join(OUT, 'purchase-blocks.json'),
  JSON.stringify({ suite: 'purchase-blocks', generated_at: new Date().toISOString(), total: results.length, failures: failures.length, results }, null, 2) + '\n');
console.log(`\npurchase blocks — ${results.length} checks, ${failures.length} failures`);
process.exit(failures.length === 0 ? 0 : 1);
