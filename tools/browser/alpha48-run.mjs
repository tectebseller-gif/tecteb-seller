/**
 * The owner's own path, in a real browser: vendor → manager → WooCommerce.
 *
 * §6 asks for exactly this — «مسیر کامل مرورگری از فروشنده تا مدیر تا ووکامرس،
 * نه فقط فراخوان مستقیم متدها» — because every defect this round fixes was
 * found by the owner CLICKING, and two of them were invisible to a service
 * call: the quantity tab that was empty, and the maximum that went 20 → 18 and
 * did not arrive.
 *
 * Nothing here calls the plugin's services. Each fact is asserted either in the
 * browser or against WP-CLI — outside the browser and outside the plugin — so
 * «the tab says eighteen» is checked against the database rather than against
 * another part of the same screen (the `alpha.26` rule).
 *
 * The path, in order:
 *   1. the vendor's form carries a brand PICKER, not a free-text box
 *   2. the vendor saves min 1 / max 18 / step 1 and the WooCommerce product
 *      screen shows them — the owner's 20 → 18, in the browser, with NOTHING
 *      else approved
 *   3. the vendor changes the stock and is told it is a REQUEST; WooCommerce's
 *      own stock does not move
 *   4. the manager's queue shows the product with the proposal, approves it,
 *      and WooCommerce's stock moves
 *   5. the vendor asks for a brand that is not in the list; the manager
 *      approves it; WooCommerce → Attributes → برند carries it
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/alpha48-ui \
 *   WP="php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo" \
 *     node tools/browser/alpha48-run.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/alpha48-ui';
const WP = process.env.WP || 'php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo';
const WPROOT = process.env.TMC_WPROOT || '/home/user/wp-demo';

// Read from the header in THIS repository, never written here: a literal
// breaks the first round that moves the number and then reports a failure
// about a site that is entirely correct (`alpha.33`).
const HEADER = fs.readFileSync(new URL('../../tecteb-marketplace-core.php', import.meta.url), 'utf8');
const EXPECT = process.env.TMC_EXPECT_VERSION
  || (HEADER.match(/^\s*\*\s*Version:\s*(.+)$/m)?.[1] ?? '').trim();

const VENDOR = { login: 'demo-vendor', pass: 'demo-vendor-2026' };
const MANAGER = { login: 'tmcowner', pass: 'demo-owner-2026' };

// Persian needles written ONCE and compared with `includes`: the same word
// typed twice in one file is not always the same bytes (combining hamza,
// ZWNJ), and that has already cost this repository three false failures.
const T = {
  brandLabel: 'برند',
  brandPickerHint: 'برند را از فهرست مدیر انتخاب کنید',
  brandRequestLabel: 'برند من در فهرست نیست',
  brandRequestButton: 'ثبت درخواست برند',
  brandRequested: 'در انتظار بررسی مدیر است',
  maxPurchase: 'حداکثر خرید',
  stockLabel: 'موجودی',
  stockIsARequest: 'روی محصول منتشرشده، تغییر موجودی به‌صورت درخواست ثبت می‌شود',
  // The form path answers `revision_requested` for a stock change — the
  // proposal carries it — so the sentence to look for is that message's own
  // half about the stock, not the inventory-only action's.
  stockRequestRecorded: 'تغییر موجودی هم با همین پیشنهاد در صف بررسی است',
  limitsApplied: 'حدود خرید روی فروشگاه هم اعمال شد',
  needsReview: 'نیازمند بررسی',
  reviewButton: 'مشاهده و بررسی',
  approveChange: 'تأیید تغییر',
  addBrandAndApply: 'افزودن به فهرست و اعمال روی محصول',
  quantityPanel: 'تعداد خرید (بازارگاه)',
  save: 'ذخیره و ادامه',
};

fs.mkdirSync(OUT, { recursive: true });
let pass = 0;
let fail = 0;
const lines = [];
const check = (name, actual, expected) => {
  const ok = String(actual) === String(expected);
  ok ? pass++ : fail++;
  const line = ok
    ? `ok:   ${name.padEnd(66)} ${expected}`
    : `FAIL: ${name.padEnd(66)} expected=${expected} actual=${actual}`;
  lines.push(line);
  console.log(line);
  return ok;
};
const note = (text) => { lines.push(`      ${text}`); console.log(`      ${text}`); };
const wp = (args) => execSync(`${WP} ${args}`, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
const state = (cmd) => {
  execSync(`cp tools/alpha48-ui-state.php ${WPROOT}/`, { stdio: 'ignore' });
  return wp(`eval-file ${WPROOT}/alpha48-ui-state.php ${cmd}`);
};
const field = (text, key) => {
  const m = text.match(new RegExp(`(?:^|\\n)${key}=(.*)`));
  return m ? m[1].trim() : '';
};
const fromPersian = (s) => s.replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)));

check('the installed package is the one under test', wp('plugin get tecteb-marketplace-core --field=version'), EXPECT);
const SCHEMA = (fs.readFileSync(
  new URL('../../src/Core/Migration/SchemaVersion.php', import.meta.url),
  'utf8',
).match(/TARGET\s*=\s*(\d+)/)?.[1] ?? '');
check('and the structure is the one it targets', wp('option get tmc_schema_version'), SCHEMA);
check('WooCommerce is really active', wp('plugin get woocommerce --field=status'), 'active');

const SEED = state('seed');
note(SEED.replace(/\n/g, ' · '));
const PRODUCT = field(SEED, 'product');
const WC_PRODUCT = field(SEED, 'wc_product');
if (!PRODUCT || PRODUCT === '0' || !WC_PRODUCT || WC_PRODUCT === '0') {
  console.log('could not seed a published, projected product — every later assertion would be void');
  process.exit(2);
}
note(`product=${PRODUCT} wc_product=${WC_PRODUCT}`);

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });

async function session(who) {
  const ctx = await browser.newContext({ viewport: { width: 1360, height: 1000 }, locale: 'fa-IR' });
  const page = await ctx.newPage();
  for (let attempt = 0; attempt < 2; attempt++) {
    await page.goto(`${SITE}/wp-login.php?loggedout=true`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#user_login', { timeout: 30000 });
    await page.fill('#user_login', who.login);
    await page.fill('#user_pass', who.pass);
    await Promise.all([
      page.waitForNavigation({ timeout: 30000 }).catch(() => null),
      page.click('#wp-submit'),
    ]);
    if ((await ctx.cookies()).some((c) => c.name.startsWith('wordpress_logged_in_'))) {
      return { ctx, page };
    }
  }
  // NOT a `check()`: a session that will not open is a broken measurement, not
  // a finding about the plugin (`alpha.34`).
  throw new Error(`could not sign in as ${who.login}`);
}
const textOf = async (page) => (await page.locator('body').innerText()).replace(/ /g, ' ');

// =========================================================== 1. the picker
const vendor = await session(VENDOR);
await vendor.page.goto(`${SITE}/vendor/products/?product=${PRODUCT}&step=1`, { waitUntil: 'domcontentloaded' });
let body = await textOf(vendor.page);
fs.writeFileSync(`${OUT}/1-vendor-step1.txt`, body);
check('1 the vendor opens their own published product', await vendor.page.locator('input[name="product_id"]').first().getAttribute('value'), PRODUCT);
check('1b the brand is a PICKER, with the manager\'s list named', body.includes(T.brandPickerHint), true);
check('1c there is no free-text brand box left', await vendor.page.locator('input[name="brand"]:not([type=hidden])').count(), 0);
check('1d the chosen brand is a radio the form will post', await vendor.page.locator('input[name="brand_term"]:checked').count(), 1);
check('1e and the request box is there for a brand that is missing', body.includes(T.brandRequestLabel), true);

// ============================== 2. the owner's 20 → 18, in the browser ====
await vendor.page.goto(`${SITE}/vendor/products/?product=${PRODUCT}&step=2`, { waitUntil: 'domcontentloaded' });
// BY ID, not by name. The form carries a HIDDEN `max_purchase` as well —
// `carryOver()` ships the other steps' fields so a one-step save cannot blank
// them (`alpha.39`) — so a selector on the name alone resolves to two elements
// and Playwright refuses it. The visible control is the one with the id.
check('2 the maximum field is on the price and stock step', await vendor.page.locator('#f-max_purchase').count(), 1);
check('2b and it shows the twenty the shop is enforcing', await vendor.page.locator('#f-max_purchase').inputValue(), '20');
await vendor.page.fill('#f-max_purchase', '18');
await Promise.all([
  vendor.page.waitForNavigation({ timeout: 30000 }).catch(() => null),
  vendor.page.getByRole('button', { name: T.save }).first().click(),
]);
body = await textOf(vendor.page);
fs.writeFileSync(`${OUT}/2-after-limit-save.txt`, body);
check('2c the save says the limits reached the shop', body.includes(T.limitsApplied), true);
// Asked of the DATABASE, not of the screen that just told us.
check('2d the marketplace row holds eighteen', wp(`db query "SELECT max_purchase FROM wp_tmc_products WHERE id = ${PRODUCT}" --skip-column-names`), '18');
check('2e and so does the SHOP, with nothing approved in between',
  wp(`post meta get ${WC_PRODUCT} _tmc_max_purchase`), '18');
check('2f no proposal was created for it', field(state('report'), 'pending_revision'), 'none');

// The WooCommerce product screen itself — the tab the owner found empty.
const manager = await session(MANAGER);
await manager.page.goto(`${SITE}/wp-admin/post.php?post=${WC_PRODUCT}&action=edit`, { waitUntil: 'domcontentloaded' });
await manager.page.waitForTimeout(1500);
const editor = await textOf(manager.page);
fs.writeFileSync(`${OUT}/2-woocommerce-editor.txt`, editor);
await manager.page.screenshot({ path: `${OUT}/2-woocommerce-editor.png`, fullPage: false });
check('2g the WooCommerce editor has the marketplace quantity panel', editor.includes(T.quantityPanel), true);
check('2h and its maximum box carries the eighteen the vendor saved',
  await manager.page.locator('#tmc-tmc_max_purchase').inputValue(), '18');

// ====================== 3. the stock change is a REQUEST ==================
await vendor.page.goto(`${SITE}/vendor/products/?product=${PRODUCT}&step=2`, { waitUntil: 'domcontentloaded' });
body = await textOf(vendor.page);
check('3 the stock field says what a change to it does now', body.includes(T.stockIsARequest), true);
await vendor.page.fill('#f-stock', '7');
await Promise.all([
  vendor.page.waitForNavigation({ timeout: 30000 }).catch(() => null),
  vendor.page.getByRole('button', { name: T.save }).first().click(),
]);
body = await textOf(vendor.page);
fs.writeFileSync(`${OUT}/3-after-stock-save.txt`, body);
check('3b the vendor is told a request was recorded', body.includes(T.stockRequestRecorded), true);
let report = state('report');
note(report.replace(/\n/g, ' · '));
check('3c the proposal carries the seven', field(report, 'proposed_stock'), '7');
check('3d the marketplace row still holds twenty', field(report, 'row_stock'), '20');
check('3e and WooCommerce\'s own stock has not moved', field(report, 'shop_stock'), '20');

// ===================== 4. the manager decides, and the shop moves =========
await manager.page.goto(`${SITE}/wp-admin/admin.php?page=tmc-product-review`, { waitUntil: 'domcontentloaded' });
body = await textOf(manager.page);
fs.writeFileSync(`${OUT}/4-review-list.txt`, body);
check('4 the list says how many products need review', body.includes(T.needsReview), true);
const COUNTED = fromPersian(body.match(/([۰-۹]+) محصول نیازمند بررسی است/)?.[1] ?? '');
check('4b and the number agrees with the database',
  COUNTED,
  wp(`eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tmc_products p WHERE p.status = \\"submitted\\" OR EXISTS (SELECT 1 FROM {$wpdb->prefix}tmc_product_revisions r WHERE r.product_id = p.id AND r.status = \\"pending\\") OR EXISTS (SELECT 1 FROM {$wpdb->prefix}tmc_brand_proposals b WHERE b.product_id = p.id AND b.status IN (\\"pending\\",\\"registered\\"))");'`));

await manager.page.goto(`${SITE}/wp-admin/admin.php?page=tmc-product-review&product=${PRODUCT}`, { waitUntil: 'domcontentloaded' });
body = await textOf(manager.page);
fs.writeFileSync(`${OUT}/4-review-detail.txt`, body);
await manager.page.screenshot({ path: `${OUT}/4-review-detail.png`, fullPage: true });
check('4c the proposal is shown with the stock row', /موجودی[\s\S]{0,200}۷/.test(body), true);
await Promise.all([
  manager.page.waitForNavigation({ timeout: 30000 }).catch(() => null),
  manager.page.getByRole('button', { name: T.approveChange }).first().click(),
]);
body = await textOf(manager.page);
fs.writeFileSync(`${OUT}/4-after-approval.txt`, body);
report = state('report');
note(report.replace(/\n/g, ' · '));
check('4d the row takes the approved stock', field(report, 'row_stock'), '7');
check('4e and WooCommerce takes it too', field(report, 'shop_stock'), '7');
check('4f nothing is left pending', field(report, 'pending_revision'), 'none');

// ============================ 5. a brand, end to end =====================
await vendor.page.goto(`${SITE}/vendor/products/?product=${PRODUCT}&step=1`, { waitUntil: 'domcontentloaded' });
await vendor.page.fill('#f-brand_request', 'برند مرورگر ۴۸');
await Promise.all([
  vendor.page.waitForNavigation({ timeout: 30000 }).catch(() => null),
  vendor.page.getByRole('button', { name: T.brandRequestButton }).first().click(),
]);
body = await textOf(vendor.page);
fs.writeFileSync(`${OUT}/5-after-brand-request.txt`, body);
check('5 the vendor is told the request is with the manager', body.includes(T.brandRequested), true);
report = state('report');
check('5b the request is recorded, undecided', field(report, 'open_brand_request'), 'برند مرورگر ۴۸:pending');
check('5c the product keeps the brand it had', field(report, 'row_brand'), 'آریا طب');
check('5d and the list does not have the new name yet',
  wp('term list product_brand --fields=name --format=csv').includes('برند مرورگر ۴۸'), false);

await manager.page.goto(`${SITE}/wp-admin/admin.php?page=tmc-product-review&product=${PRODUCT}`, { waitUntil: 'domcontentloaded' });
body = await textOf(manager.page);
fs.writeFileSync(`${OUT}/5-manager-brand-panel.txt`, body);
check('5e the manager sees the brand request on the product', body.includes('درخواست برند تازه'), true);
await Promise.all([
  manager.page.waitForNavigation({ timeout: 30000 }).catch(() => null),
  manager.page.getByRole('button', { name: T.addBrandAndApply }).first().click(),
]);
body = await textOf(manager.page);
fs.writeFileSync(`${OUT}/5-after-brand-approval.txt`, body);
report = state('report');
note(report.replace(/\n/g, ' · '));
check('5f the brand is now in WooCommerce\'s own list',
  wp('term list product_brand --fields=name --format=csv').includes('برند مرورگر ۴۸'), true);
check('5g the product carries it', field(report, 'row_brand'), 'برند مرورگر ۴۸');
check('5h and so does WooCommerce → Attributes → برند', field(report, 'shop_attribute_brand'), 'برند مرورگر ۴۸');
check('5i nothing is left open', field(report, 'open_brand_request'), 'none');

// The attributes panel, in the browser, because that is where the owner looked.
await manager.page.goto(`${SITE}/wp-admin/post.php?post=${WC_PRODUCT}&action=edit`, { waitUntil: 'domcontentloaded' });
await manager.page.waitForTimeout(1500);
const attributes = await manager.page.content();
fs.writeFileSync(`${OUT}/5-woocommerce-attributes.html`, attributes);
await manager.page.screenshot({ path: `${OUT}/5-woocommerce-editor.png`, fullPage: false });
check('5j the editor\'s own markup carries the brand value', attributes.includes('برند مرورگر ۴۸'), true);

await browser.close();
state('reset');

lines.push('');
lines.push(`checks: ${pass + fail}   pass: ${pass}   fail: ${fail}`);
console.log('');
console.log(`checks: ${pass + fail}   pass: ${pass}   fail: ${fail}`);
fs.writeFileSync(`${OUT}/run.txt`, lines.join('\n') + '\n');
process.exit(fail === 0 ? 0 : 1);
