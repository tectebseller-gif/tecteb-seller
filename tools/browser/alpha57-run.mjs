/**
 * The account button's real destination, and the simplified product list — in
 * a real browser.
 *
 * Why a browser, when the upgrade check already fetches these pages with curl:
 * §1 does not ask what the link SAYS, it asks «نشانی نهایی پس از redirectها را
 * بررسی کن». A `href` in markup and the address a browser lands on are two
 * different facts, and this repository has twice shipped a page whose every
 * suite was green and whose real request answered something else — `alpha.48`
 * (a method `Request` does not have) and `alpha.56` (a 403 where a 302 was
 * asserted, because the test called the redirect directly).
 *
 * So everything here is read AFTER a click, from `page.url()`, per role.
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/alpha57-ui \
 *   WP="php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo" \
 *     node tools/browser/alpha57-run.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/alpha57-ui';
const WP = process.env.WP || 'php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo';
const WPROOT = process.env.TMC_WPROOT || '/home/user/wp-demo';
const REVIEW = `${SITE}/wp-admin/admin.php?page=tmc-product-review`;
const PAY = `${SITE}/wp-admin/admin.php?page=tmc-supplier-payments`;

const HEADER = fs.readFileSync(new URL('../../tecteb-marketplace-core.php', import.meta.url), 'utf8');
const EXPECT = process.env.TMC_EXPECT_VERSION
  || (HEADER.match(/^\s*\*\s*Version:\s*(.+)$/m)?.[1] ?? '').trim();

const PASS = 'demo-a57-2026';
const OWNER = { login: 'tmcowner', pass: 'demo-owner-2026' };
const VENDOR = { login: 'tmca57vendor', pass: PASS };
const MGR_SHOP = { login: 'tmca57mgrshop', pass: PASS };
const MGR_BARE = { login: 'tmca57mgrbare', pass: PASS };
const BUYER = { login: 'tmca57buyer', pass: PASS };

// Persian needles written ONCE and compared with `includes` (`alpha.23`).
const T = {
  button: 'ورود به داشبورد فروشنده',
  managerLink: 'بررسی محصول‌ها در پیشخوان مدیریت',
  managerWhy: 'این حساب فروشگاهی در بازارگاه ندارد',
  activeFilters: 'فیلترهای فعال:',
  moreFilters: 'فیلترهای بیشتر',
  rowCount: 'تعداد ردیف و مرتب‌سازی',
  allMarketplace: 'همهٔ ردیف‌های بازارگاه',
  anySource: 'هر منبعی',
  dokanParagraph: 'مسیر ورودش «انتقال از دکان» است',
  twoStatuses: 'این دو یکی نیستند',
  commissionTab: 'کمیسیون تک‌طب',
  commissionNotBlocking: 'مانع تأیید، انتشار یا فروش محصول نیست',
  payTitle: 'پرداخت به تأمین‌کننده',
  payNotTransfer: 'هیچ انتقال وجهی از اینجا انجام نمی‌شود',
  payNotWithdrawal: 'این صفحه چرخهٔ «تسویه و برداشت» فروشنده نیست',
  wholesaleShort: 'برای خرید عمده، مشخصات کسب‌وکارتان را ثبت کنید',
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
  execSync(`cp tools/alpha57-ui-state.php ${WPROOT}/`, { stdio: 'ignore' });
  return execSync(`cd ${WPROOT} && ${WP} eval-file ${WPROOT}/alpha57-ui-state.php ${cmd} 2>/dev/null`, {
    encoding: 'utf8', shell: '/bin/bash',
  }).trim();
};
const field = (text, key) => {
  const m = text.match(new RegExp(`(?:^|\\n)${key}=(.*)`));
  return m ? m[1].trim() : '';
};

check('the installed package is the one under test', wp('plugin get tecteb-marketplace-core --field=version'), EXPECT);
const SCHEMA = (fs.readFileSync(
  new URL('../../src/Core/Migration/SchemaVersion.php', import.meta.url),
  'utf8',
).match(/TARGET\s*=\s*(\d+)/)?.[1] ?? '');
check('and the structure is the one it targets', wp('option get tmc_schema_version'), SCHEMA);
check('WooCommerce is really active', wp('plugin get woocommerce --field=status'), 'active');

const SEEDED = state('report');
note(SEEDED.replace(/\n/g, ' · '));
const WC_ID = field(SEEDED, 'wc_product');
if (!field(SEEDED, 'vendor') || field(SEEDED, 'vendor') === '0' || !WC_ID || WC_ID === '0') {
  console.log('the four accounts are not arranged — run `wp eval-file tools/alpha57-ui-state.php seed` first');
  process.exit(2);
}

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
async function session(who, viewport = { width: 1360, height: 1000 }) {
  const ctx = await browser.newContext({ viewport, locale: 'fa-IR' });
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
  // NOT a `check()`: a session that will not open is a broken measurement,
  // not a finding about the plugin (`alpha.34`).
  throw new Error(`could not sign in as ${who.login}`);
}
const textOf = async (page) => (await page.locator('body').innerText()).replace(/ /g, ' ');

/**
 * Where the account button actually LANDS for one account.
 *
 * The click and `waitForURL` rather than reading the `href`: a redirect is the
 * whole question §1 asks, and an address in markup is not an address a browser
 * reached.
 */
async function destinationFor(who, label) {
  const s = await session(who);
  await s.page.goto(`${SITE}/my-account/`, { waitUntil: 'domcontentloaded' });
  const body = await textOf(s.page);
  fs.writeFileSync(`${OUT}/account-${who.login}.txt`, body);
  const link = s.page.locator('a.tmc-account-vendor__link');
  const count = await link.count();
  let landed = '';
  let title = '';
  if (count > 0) {
    title = (await link.first().innerText()).trim();
    await Promise.all([
      s.page.waitForNavigation({ timeout: 30000 }).catch(() => null),
      link.first().click(),
    ]);
    landed = s.page.url();
  }
  note(`${label}: buttons=${count} title=${title || '__none__'} landed=${landed || '__none__'}`);
  await s.ctx.close();
  return { count, title, landed, body };
}

// ============================================ 1. the button, per role
const vendor = await destinationFor(VENDOR, 'vendor');
check('1 the vendor sees exactly one marketplace button', vendor.count, 1);
check('1b with the title the owner asked for, exactly', vendor.title, T.button);
check('1c and it lands on this plugin\'s own vendor dashboard',
  /\/vendor\/?($|[?#])|[?&]tmc_vendor=dashboard/.test(vendor.landed), true);
check('1d not in wp-admin', vendor.landed.includes('/wp-admin/'), false);
check('1e not on the manager review page', vendor.landed.includes('tmc-product-review'), false);
check('1f and not on Dokan\'s dashboard', /dokan|\/dashboard\//.test(vendor.landed), false);

const mgrShop = await destinationFor(MGR_SHOP, 'manager with a shop');
check('2 a manager who ALSO has a shop sees one button', mgrShop.count, 1);
check('2b titled for the vendor dashboard', mgrShop.title, T.button);
check('2c and lands on their OWN vendor dashboard',
  /\/vendor\/?($|[?#])|[?&]tmc_vendor=dashboard/.test(mgrShop.landed), true);
check('2d never in wp-admin — the defect the owner reported',
  mgrShop.landed.includes('/wp-admin/'), false);

const mgrBare = await destinationFor(MGR_BARE, 'manager without a shop');
check('3 a manager with NO shop sees one link', mgrBare.count, 1);
check('3b titled for the admin dashboard, not the vendor one', mgrBare.title, T.managerLink);
check('3c which says why', mgrBare.body.includes(T.managerWhy), true);
check('3d and lands in wp-admin', mgrBare.landed.includes('/wp-admin/'), true);
// …and NOTHING was created for them: no shop, no vendor, no capability.
check('3e no shop was created for them',
  field(state(`shop-of ${field(SEEDED, 'manager_without_shop')}`), 'profile'), '0');

const buyer = await destinationFor(BUYER, 'ordinary customer');
check('4 an ordinary customer sees no marketplace button at all', buyer.count, 0);
check('4b and no vendor title anywhere on the page', buyer.body.includes(T.button), false);
// The wholesale text is the one the owner screenshotted: short now, and with
// no decision code in it.
check('4c the wholesale section is one short sentence', buyer.body.includes(T.wholesaleShort), true);
check('4d with no DEC code on the account page', /DEC-0\d/.test(buyer.body), false);

// ============================================ 5. the product list
const mgr = await session(OWNER);
await mgr.page.goto(`${REVIEW}&per_page=20`, { waitUntil: 'domcontentloaded' });
let body = await textOf(mgr.page);
fs.writeFileSync(`${OUT}/5-product-list.txt`, body);
check('5 the list opens', await mgr.page.locator('table.tmc-catalogue__table').count(), 1);
check('5b the repeated Dokan paragraph is gone from under the rows',
  body.includes(T.dokanParagraph), false);
check('5c the specialist facets are folded away',
  await mgr.page.locator('details.tmc-catalogue__more').count(), 1);
check('5d and closed when none of them is active',
  await mgr.page.locator('details.tmc-catalogue__more[open]').count(), 0);
// `textContent` and not `innerText`: Chromium gives a CLOSED `<details>` only
// its summary through `innerText`, so the first version of this check reported
// «absent» about a sentence that was there — a measurement artefact, not a
// finding (`alpha.34`).
check('5e the sentence telling the two «وضعیت» apart is inside that box',
  (await mgr.page.locator('details.tmc-catalogue__more').textContent()).includes(T.twoStatuses), true);
check('5f no active-filter bar when nothing is filtering',
  await mgr.page.locator('.tmc-catalogue__active').count(), 0);
check('5g the row-count form is folded and last',
  await mgr.page.locator('details.tmc-catalogue__settings').count(), 1);
check('5h no chip is called just «همه»',
  (await mgr.page.locator('.tmc-filter__label', { hasText: /^همه$/ }).count()), 0);
check('5i the marketplace status row names its own universe',
  body.includes(T.allMarketplace), true);
// The pager sits directly after the rows — measured by DOM order, not by eye.
const pagerAfterTable = await mgr.page.evaluate(() => {
  const table = document.querySelector('table.tmc-catalogue__table');
  const pager = document.querySelector('.tmc-pager, nav[aria-label], .tmc-catalogue__pager');
  if (!table) return 'no-table';
  const settings = document.querySelector('details.tmc-catalogue__settings');
  if (!settings) return 'no-settings';
  return table.compareDocumentPosition(settings) & Node.DOCUMENT_POSITION_FOLLOWING ? 'after' : 'before';
});
check('5j and the row-count box comes after the table', pagerAfterTable, 'after');

// A facet click: the address carries it, the box opens, the filter is named.
await mgr.page.goto(`${REVIEW}&source=woocommerce`, { waitUntil: 'domcontentloaded' });
body = await textOf(mgr.page);
fs.writeFileSync(`${OUT}/6-facet-on.txt`, body);
check('6 a specialist facet opens its own box',
  await mgr.page.locator('details.tmc-catalogue__more[open]').count(), 1);
check('6b and the active filter is named above the rows', body.includes(T.activeFilters), true);
const removeHref = await mgr.page.locator('.tmc-activefilter__remove').first().getAttribute('href');
note(`remove link: ${removeHref}`);
check('6c with a × whose own address drops only that filter',
  /source=/.test(removeHref ?? 'source='), false);

// Two filters: the × of one keeps the other, and a clear-all appears.
await mgr.page.goto(`${REVIEW}&source=woocommerce&status=published`, { waitUntil: 'domcontentloaded' });
const removes = await mgr.page.locator('.tmc-activefilter__remove').count();
check('7 two active filters are named separately', removes, 2);
check('7b and a «clear all» appears only then',
  await mgr.page.locator('.tmc-catalogue__clearall').count(), 1);
// The FIRST named filter is the marketplace status (the order is search,
// status, source, management, publish status, review), so its × is the one
// that must keep `source` — asserting the opposite would have been asserting
// that a filter's × does not remove it.
const firstRemove = await mgr.page.locator('.tmc-activefilter__remove').first().getAttribute('href');
note(`first remove: ${firstRemove}`);
check('7c the first filter\'s × drops itself', /status=/.test(firstRemove ?? ''), false);
check('7d and keeps the other', /source=woocommerce/.test(firstRemove ?? ''), true);
const clearAll = await mgr.page.locator('.tmc-catalogue__clearall').getAttribute('href');
check('7e while «clear all» drops both',
  /status=|source=/.test(clearAll ?? ''), false);

// ============================================ 8. commission and payments
await mgr.page.goto(`${SITE}/wp-admin/post.php?post=${WC_ID}&action=edit`, { waitUntil: 'domcontentloaded' });
body = await textOf(mgr.page);
fs.writeFileSync(`${OUT}/8-product-editor.txt`, body);
check('8 the commission tab is on the WooCommerce product editor',
  await mgr.page.locator('li.tmc_commission_options, li.tmc_commission_tab, a[href="#tmc_commission_panel"]').count() > 0, true);
check('8b the panel says a missing commission blocks nothing',
  (await mgr.page.locator('div#tmc_commission_panel').textContent()).includes(T.commissionNotBlocking), true);
// …and the panel's id belongs to ONE element: `wp_nonce_field()` gives its
// input an id equal to its name, which collided with the panel div until this
// round. Only a browser could see it.
check('8c the panel id is not shared with a hidden input',
  await mgr.page.locator('#tmc_commission_panel').count(), 1);
check('8d with a type control and a value control',
  await mgr.page.locator('#tmc-commission-type').count() + await mgr.page.locator('#tmc-commission-value').count(), 2);
check('8e and three choices, no more',
  await mgr.page.locator('#tmc-commission-type option').count(), 3);

await mgr.page.goto(PAY, { waitUntil: 'domcontentloaded' });
body = await textOf(mgr.page);
fs.writeFileSync(`${OUT}/9-supplier-payments.txt`, body);
check('9 the supplier payments page opens for the manager',
  body.includes(T.payTitle), true);
check('9b and says it is not the settlement queue', body.includes(T.payNotWithdrawal), true);
check('9c and promises no transfer happens', body.includes(T.payNotTransfer), true);
check('9d with exactly one submit button — the one that writes a record',
  await mgr.page.locator('button[type="submit"]').count(), 1);

// ============================================ 10. mobile, and axe
const mobile = await session(OWNER, { width: 390, height: 844 });
for (const [label, url] of [['list', `${REVIEW}&per_page=20`], ['payments', PAY]]) {
  await mobile.page.goto(url, { waitUntil: 'domcontentloaded' });
  const overflow = await mobile.page.evaluate(
    () => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth),
  );
  check(`10 ${label}: no horizontal overflow at 390px`, overflow, 0);
  await mobile.page.screenshot({ path: `${OUT}/mobile-${label}.png`, fullPage: true });
}
await mobile.ctx.close();

const axeSource = fs.readFileSync(
  new URL('../../tools/browser/node_modules/axe-core/axe.min.js', import.meta.url),
  'utf8',
);
const axeRun = async (scope) => {
  await mgr.page.addScriptTag({ content: axeSource });
  return mgr.page.evaluate(async (sel) => await window.axe.run(
    sel ? { include: [[sel]] } : document,
    { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } },
  ), scope);
};
for (const [label, url] of [['list', `${REVIEW}&per_page=20`], ['payments', PAY]]) {
  await mgr.page.goto(url, { waitUntil: 'domcontentloaded' });
  const result = await axeRun(null);
  const serious = result.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
  note(`axe ${label}: ${result.violations.length} violations, ${serious.length} serious/critical`);
  for (const v of serious) note(`  ${v.id}: ${v.nodes.length} node(s)`);
  check(`11 axe on the ${label}: no serious or critical violation`, serious.length, 0);
  fs.writeFileSync(`${OUT}/axe-${label}.json`, JSON.stringify(result.violations, null, 2));
}

// THE PRODUCT EDITOR IS SCOPED, and the reason is said out loud rather than
// hidden in a threshold: a page-wide run there reports seven serious
// violations — `nested-interactive` on twelve nodes, `button-name` on eleven,
// `aria-required-children`, `listitem` — and every one of them is in
// WooCommerce's and WordPress's own editor chrome, which this round does not
// touch and may not fix. Claiming them as ours would be false; claiming the
// page is clean would be false too. So the panel is measured and the page is
// REPORTED, with the number.
await mgr.page.goto(`${SITE}/wp-admin/post.php?post=${WC_ID}&action=edit`, { waitUntil: 'domcontentloaded' });
const wide = await axeRun(null);
const wideSerious = wide.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
note(`axe editor (whole page, WooCommerce's own chrome): ${wideSerious.length} serious/critical`);
for (const v of wideSerious) note(`  ${v.id}: ${v.nodes.length} node(s) — not ours, not touched this round`);
fs.writeFileSync(`${OUT}/axe-editor-page.json`, JSON.stringify(wide.violations, null, 2));

const panelAxe = await axeRun('div#tmc_commission_panel');
const panelSerious = panelAxe.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
note(`axe editor (our panel only): ${panelAxe.violations.length} violations, ${panelSerious.length} serious/critical`);
for (const v of panelSerious) note(`  ${v.id}: ${v.nodes.length} node(s)`);
check('11 axe on OUR commission panel: no serious or critical violation', panelSerious.length, 0);
fs.writeFileSync(`${OUT}/axe-editor-panel.json`, JSON.stringify(panelAxe.violations, null, 2));

await mgr.page.screenshot({ path: `${OUT}/desktop-payments.png`, fullPage: true });
await mgr.ctx.close();
await browser.close();

lines.push('', `checks: ${pass} passed, ${fail} failed`);
console.log('', `checks: ${pass} passed, ${fail} failed`);
fs.writeFileSync(`${OUT}/alpha57-run.txt`, `${lines.join('\n')}\n`);
process.exit(fail === 0 ? 0 : 1);
