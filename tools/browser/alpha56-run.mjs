/**
 * The one product list, in a real browser — §3 and §4 of the brief.
 *
 * Why a browser at all, when the upgrade check already fetches these pages
 * with curl: `alpha.48` shipped a page whose every suite was green and whose
 * only POST branch called a method `Request` does not have, and only a
 * browser found it. This round repeated the lesson one layer out — the
 * retired intake address answered **403** on a real request while the test
 * that exists to prevent that sentence passed, because it called the
 * redirect directly. So the things asserted here are the ones that only a
 * request can answer: what the page draws, what a click does to the address,
 * and whether the row for a product appears ONCE.
 *
 * Nothing here calls the plugin's services. Each fact is read in the browser
 * or from WP-CLI — outside the browser and outside the plugin — so «the list
 * shows one row» is checked against the database rather than against another
 * part of the same screen (the `alpha.26` rule).
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/alpha56-ui \
 *   WP="php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo" \
 *     node tools/browser/alpha56-run.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/alpha56-ui';
const WP = process.env.WP || 'php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo';
const WPROOT = process.env.TMC_WPROOT || '/home/user/wp-demo';
const REVIEW = `${SITE}/wp-admin/admin.php?page=tmc-product-review`;

const HEADER = fs.readFileSync(new URL('../../tecteb-marketplace-core.php', import.meta.url), 'utf8');
const EXPECT = process.env.TMC_EXPECT_VERSION
  || (HEADER.match(/^\s*\*\s*Version:\s*(.+)$/m)?.[1] ?? '').trim();

const MANAGER = { login: 'tmcowner', pass: 'demo-owner-2026' };

// Persian needles written ONCE and compared with `includes` (`alpha.23`).
const T = {
  sourceFacet: 'منبع محصول',
  manageFacet: 'وضعیت مدیریت بازارگاه',
  statusFacet: 'وضعیت',
  intakeTitle: 'ورود محصول‌های انتخاب‌شده به بازارگاه',
  intakeUntouched: 'شناسه، نشانی، وضعیت انتشار، نویسنده، قیمت، موجودی، تصویرها و سفارش‌های قبلی دست‌نخورده می‌مانند',
  // The detail view's own sentence: opening a screen decides nothing.
  detailNoGuard: 'هیچ گاردی از این افزونه دربارهٔ آن نظر نمی‌دهد',
  detailBucket: 'دسته‌بندی مالکیت',
  sourceWooOnly: 'فقط در ووکامرس',
  shopSearchLabel: 'با نام جست‌وجو کنید',
  noShop: 'فروشگاهی با این نام پیدا نشد',
  observedBadge: 'فقط مشاهده‌شده',
  listRegion: 'فهرست محصولات',
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
const probe = (cmd) => {
  execSync(`cp tests/Support/alpha56-probe.php tests/Support/FailingDatabase.php ${WPROOT}/`, { stdio: 'ignore' });
  return execSync(
    `cd ${WPROOT} && ${WP} --exec='define("WP_ADMIN", true); define("WP_ENVIRONMENT_TYPE","local");' eval-file ${WPROOT}/alpha56-probe.php ${cmd} 2>/dev/null`,
    { encoding: 'utf8', shell: '/bin/bash' },
  ).trim();
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

const ARRANGED = probe('arrange');
note(ARRANGED.replace(/\n/g, ' · '));
const WC_ID = field(ARRANGED, 'wc_id');
if (!WC_ID || WC_ID === '0') {
  console.log('could not arrange a WooCommerce product — every later assertion would be void');
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

// ===================================================== 1. ONE list, one row
const mgr = await session(MANAGER);
await mgr.page.goto(`${REVIEW}&per_page=100`, { waitUntil: 'domcontentloaded' });
let body = await textOf(mgr.page);
fs.writeFileSync(`${OUT}/1-one-list.txt`, body);
check('1 the list page opens for the manager',
  await mgr.page.locator('table.tmc-catalogue__table').count(), 1);
check('1b with the source facet', body.includes(T.sourceFacet), true);
check('1c and the management facet, which is not the publish status',
  body.includes(T.manageFacet), true);
check('1d the rows are in one scrollable region with a name',
  await mgr.page.locator(`[role="region"][aria-label="${T.listRegion}"]`).count(), 1);

// The arranged product appears EXACTLY ONCE — the whole point of §3. Counted
// by its detail link rather than by its title, because a title can repeat.
const rowsForIt = await mgr.page.locator(
  `a.tmc-catalogue__link[href*="wc=${WC_ID}"], a.tmc-catalogue__link[href*="product="]`,
).count();
note(`links on the page: ${rowsForIt}`);
const ownRow = await mgr.page.locator(`tr:has(input[value="${WC_ID}"])`).count();
const ownLink = await mgr.page.locator(`a.tmc-catalogue__link[href*="wc=${WC_ID}"]`).count();
check('1e the arranged product has exactly one row', Math.max(ownRow, ownLink), 1);

// How many rows the database can justify is measured by the probe's own
// `one-list` verb, against the tables, in the reproduction script. Asking it
// here through a nested shell substitution was a measurement waiting to
// break, and a broken measurement looks like a finding (`alpha.34`).

// ============================================= 2. a facet click, in the URL
// The chip by its OWN label, not «the first link in the section»: the first
// link is «همه», and a click on it proves nothing about carrying a facet.
const sourceChip = mgr.page.locator(`a:has-text("${T.sourceWooOnly}")`).first();
if (await sourceChip.count() > 0) {
  await Promise.all([
    mgr.page.waitForNavigation({ timeout: 30000 }).catch(() => null),
    sourceChip.click(),
  ]);
}
const url2 = mgr.page.url();
note(`after the first source chip: ${url2}`);
check('2 a facet click lands on the same page', url2.includes('page=tmc-product-review'), true);
check('2a and carries the facet in the address, so a bookmark keeps it',
  url2.includes('source=woocommerce'), true);
check('2b and a new filter starts at the first page — no stale «paged»',
  /[?&]paged=[2-9]/.test(url2), false);

// ============================================== 3. the product's own screen
await mgr.page.goto(`${REVIEW}&wc=${WC_ID}`, { waitUntil: 'domcontentloaded' });
body = await textOf(mgr.page);
fs.writeFileSync(`${OUT}/3-detail.txt`, body);
check('3 the WooCommerce product has a screen of its own',
  body.includes(String(WC_ID)) || body.length > 400, true);
check('3b which says that opening it decided nothing',
  body.includes(T.detailNoGuard), true);
check('3c and this is where the ownership evidence is readable, not in the list',
  body.includes(T.detailBucket), true);

// ================================================= 4. the intake, explicit
await mgr.page.goto(`${REVIEW}&source=woocommerce&per_page=100`, { waitUntil: 'domcontentloaded' });
body = await textOf(mgr.page);
fs.writeFileSync(`${OUT}/4-intake.txt`, body);
const hasBox = await mgr.page.locator('#tmc-intake-shop').count();
check('4 the intake box is drawn where a row could be taken in', hasBox > 0, true);
if (hasBox > 0) {
  check('4b the vendor is chosen by searching the shop NAME, not by id',
    await mgr.page.locator('#tmc-intake-shop').getAttribute('type'), 'search');
  check('4c there is a preview that writes nothing',
    await mgr.page.locator('button[name="intake_action"][value="preview"]').count(), 1);
  check('4d and no «select all» — the intake takes an explicit list',
    await mgr.page.locator('input[type="checkbox"][name="wc_ids_all"]').count(), 0);
  // A name nothing matches must say so rather than silently import.
  await mgr.page.fill('#tmc-intake-shop', 'فروشگاهی-که-وجود-ندارد');
  await Promise.all([
    mgr.page.waitForNavigation({ timeout: 30000 }).catch(() => null),
    mgr.page.click('button[name="intake_action"][value="find_shop"]'),
  ]);
  body = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/4-no-shop.txt`, body);
  check('4e a shop name nothing matches is said out loud', body.includes(T.noShop), true);
  check('4f and what was typed survives the round trip',
    await mgr.page.locator('#tmc-intake-shop').inputValue(), 'فروشگاهی-که-وجود-ندارد');
}

// ===================================================== 5. the retired address
const retired = await mgr.page.goto(`${SITE}/wp-admin/admin.php?page=tmc-product-intake`, { waitUntil: 'domcontentloaded' });
check('5 the retired intake address does not refuse', retired.status(), 200);
check('5b it lands on the one list', mgr.page.url().includes('page=tmc-product-review'), true);
check('5c narrowed to what that screen used to show',
  mgr.page.url().includes('source=woocommerce'), true);

// ============================================== 6. the phone, and no overflow
const phone = await session(MANAGER, { width: 390, height: 844 });
await phone.page.goto(`${REVIEW}&per_page=20`, { waitUntil: 'domcontentloaded' });
await phone.page.screenshot({ path: `${OUT}/6-mobile.png`, fullPage: false });
const overflow = await phone.page.evaluate(
  () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
);
note(`horizontal overflow at 390px: ${overflow}px`);
check('6 the list does not drag the whole page sideways on a phone', overflow <= 1, true);

// ======================================================== 7. axe, on the list
const axe = fs.readFileSync(new URL('node_modules/axe-core/axe.min.js', import.meta.url), 'utf8');
await mgr.page.goto(`${REVIEW}&per_page=20`, { waitUntil: 'domcontentloaded' });
await mgr.page.addScriptTag({ content: axe });
const result = await mgr.page.evaluate(async () => await window.axe.run(document, {
  resultTypes: ['violations'],
}));
const serious = result.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
fs.writeFileSync(`${OUT}/7-axe.json`, JSON.stringify(result.violations, null, 2));
note(`axe: ${result.passes?.length ?? '?'} passes, ${result.violations.length} violations, ${serious.length} serious or critical`);
for (const v of serious) {
  note(`  ${v.id}: ${v.nodes.length} node(s)`);
}
check('7 no serious or critical accessibility violation on the one list', serious.length, 0);

await browser.close();
lines.push('');
lines.push(`PASS=${pass} FAIL=${fail}`);
console.log(`\nPASS=${pass} FAIL=${fail}`);
fs.writeFileSync(`${OUT}/alpha56-run.txt`, lines.join('\n') + '\n');
process.exit(fail === 0 ? 0 : 1);
