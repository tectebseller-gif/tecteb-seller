/**
 * The parts of this round that only a browser can answer.
 *
 * Everything about what is WRITTEN is `tools/unified-review-check.sh`; a page
 * is the wrong instrument for that. What is left here is what a person sees:
 *
 *   1. the manager's message, on the page where the vendor has to act on it
 *   2. a decided product still reachable from the manager's admin, with the
 *      marketplace status and WooCommerce's own status side by side
 *   5. «داشبورد فروشنده» on /my-account/, built from the current domain —
 *      and the fact that removing the link is not what stops a stranger
 *   6. a bulk run that says what happened to every row, and why
 *   7. an attempt to reproduce the counter mismatch the owner reported
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/review-flow \
 *   WP="/opt/php81/bin/php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo" \
 *     node tools/browser/review-flow-run.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/review-flow';
const WP = process.env.WP || '/opt/php81/bin/php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo';

const VENDOR = { login: 'demo-vendor', pass: 'demo-vendor-2026' };
const BUYER = { login: 'demo-buyer', pass: 'demo-buyer-2026' };
const OWNER = { login: 'tmcowner', pass: 'demo-owner-2026' };

// Written ONCE and compared with `includes`. The same Persian word typed
// twice in one file is not always the same bytes (combining hamza, ZWNJ),
// and that has already cost this repository three false failures about
// pages that were perfectly correct.
const T = {
  managerAsked: 'مدیر اصلاح خواسته است',
  resubmitHint: 'ارسال برای بررسی',
  dashboardLink: 'داشبورد فروشنده',
  catalogue: 'همهٔ محصولات بازارگاه',
  wcStatusColumn: 'وضعیت ووکامرس',
  bulkResult: 'نتیجهٔ',
  nothingDone: 'هیچ‌کدام از',
  reasonColumn: 'توضیح',
  unknownStatus: 'وضعیت ناشناخته',
};

fs.mkdirSync(OUT, { recursive: true });
for (const stale of fs.readdirSync(OUT)) {
  if (/\.(png|txt|json)$/.test(stale)) { fs.unlinkSync(path.join(OUT, stale)); }
}

let pass = 0;
let fail = 0;
const lines = [];
const check = (name, actual, expected) => {
  const ok = String(actual) === String(expected);
  ok ? pass++ : fail++;
  const line = ok
    ? `ok:   ${name.padEnd(64)} ${expected}`
    : `FAIL: ${name.padEnd(64)} expected=${expected} actual=${actual}`;
  lines.push(line);
  console.log(line);
  return ok;
};
const note = (text) => { lines.push(`      ${text}`); console.log(`      ${text}`); };
const wp = (args) => execSync(`${WP} ${args}`, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
async function newSession() {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 950 }, locale: 'fa-IR' });
  return { ctx, page: await ctx.newPage() };
}
async function signIn(page, who) {
  await page.goto(`${SITE}/wp-login.php?loggedout=true`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', who.login);
  await page.fill('#user_pass', who.pass);
  await page.click('#wp-submit');
  await page.waitForURL(/wp-admin|\/vendor\/|my-account/, { timeout: 30000 }).catch(() => null);
}
const shot = (page, name) => page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: true });
const words = async (page) => (await page.locator('body').innerText()).replace(/\s+/g, ' ');
const fa2en = (s) => s.replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)));

// ============================================================ items 1 and 2
//
// The note is recorded through the plugin's own service, from WP-CLI, so the
// page is the only thing under test here.
const VENDOR_ID = Number(wp(`db query "SELECT ID FROM wp_users WHERE user_login='${VENDOR.login}'" --skip-column-names`));
const PRODUCT_ID = Number(wp(
  `db query "SELECT id FROM wp_tmc_products WHERE vendor_user_id=${VENDOR_ID} ORDER BY id LIMIT 1" --skip-column-names`
));
const NOTE = 'عکس دوم مال محصول دیگری است؛ لطفاً عوضش کنید';
note(`vendor ${VENDOR_ID}, product ${PRODUCT_ID}`);

wp(`db query "UPDATE wp_tmc_products SET status='changes_requested' WHERE id=${PRODUCT_ID}"`);
wp(`db query "INSERT INTO wp_tmc_product_decisions (product_id, vendor_user_id, actor_id, decision, note, created_at) `
  + `VALUES (${PRODUCT_ID}, ${VENDOR_ID}, 1, 'changes_requested', '${NOTE}', NOW())"`);

const { ctx: vctx, page: vpage } = await newSession();
await signIn(vpage, VENDOR);
await vpage.goto(`${SITE}/vendor/products/?product=${PRODUCT_ID}`, { waitUntil: 'domcontentloaded' });
const formText = await words(vpage);
await shot(vpage, '1-vendor-form-with-manager-message');
check('1-1. the edit page carries the manager\'s heading', formText.includes(T.managerAsked), true);
check('1-2. and their actual sentence', formText.includes(NOTE), true);
check('1-3. and says what to do next', formText.includes(T.resubmitHint), true);

// The same page for a DIFFERENT shop's product id: the scope is in the
// query, so this is «not found», not «somebody else's correspondence».
const OTHER_ID = Number(wp(
  `db query "SELECT id FROM wp_tmc_products WHERE vendor_user_id<>${VENDOR_ID} ORDER BY id LIMIT 1" --skip-column-names`
) || 0);
if (OTHER_ID > 0) {
  await vpage.goto(`${SITE}/vendor/products/?product=${OTHER_ID}`, { waitUntil: 'domcontentloaded' });
  const otherText = await words(vpage);
  check('1-4. another shop\'s product shows no message at all', otherText.includes(NOTE), false);
}

// ============================================================ item 5
await vpage.goto(`${SITE}/my-account/`, { waitUntil: 'domcontentloaded' });
const accountText = await words(vpage);
await shot(vpage, '5-my-account-vendor');
check('5-1. the account page offers the vendor dashboard', accountText.includes(T.dashboardLink), true);
const href = await vpage.locator(`a:has-text("${T.dashboardLink}")`).first().getAttribute('href').catch(() => null);
note(`link: ${href}`);
check('5-2. and its address is on THIS host', String(href || '').startsWith(SITE), true);
check('5-3. and it is the plugin\'s own route', /\/vendor\/|tmc_vendor=/.test(String(href || '')), true);
// A hardcoded staging host would show up here and nowhere else.
check('5-4. no other host is baked into it', /tecteb\.com/.test(String(href || '')), false);
await vctx.close();

// The link is not the lock. A customer with no store gets no menu item AND
// no access — the second of those is what actually matters.
const { ctx: bctx, page: bpage } = await newSession();
await signIn(bpage, BUYER);
await bpage.goto(`${SITE}/my-account/`, { waitUntil: 'domcontentloaded' });
check('5-5. a plain customer is not offered it', (await words(bpage)).includes(T.dashboardLink), false);
await bpage.goto(`${SITE}/vendor/products/`, { waitUntil: 'domcontentloaded' });
const buyerLanded = bpage.url();
note(`buyer typing the address landed on: ${buyerLanded}`);
check('5-6. and typing the address anyway does not open the panel', /tmc_notice=not_a_vendor|\/vendor\/?$/.test(buyerLanded), true);
check('5-7. with no product list on the page', (await words(bpage)).includes('افزودن محصول'), false);
await bctx.close();

// ============================================================ item 6
//
// «بازگشت به پیش‌نویس» on products that are already drafts: every row is
// refused, which is the shape the owner met — «۰ انجام شد و ۴ انجام نشد» with
// nothing after the colon.
const { ctx: v2ctx, page: v2page } = await newSession();
await signIn(v2page, VENDOR);
await v2page.goto(`${SITE}/vendor/products/?status=draft`, { waitUntil: 'domcontentloaded' });
const boxes = await v2page.locator('input[type="checkbox"][name="selected[]"]').all();
const picked = Math.min(boxes.length, 4);
for (let i = 0; i < picked; i++) { await boxes[i].check(); }
note(`selected ${picked} draft rows for «بازگشت به پیش‌نویس»`);
await v2page.selectOption('select[name="bulk_action"]', 'restore').catch(() => null);
await v2page.locator('button[name="tmc_vendor_action"][value="bulk_products"], button[value="bulk_products"]').first().click();
await v2page.waitForLoadState('domcontentloaded');
const bulkText = await words(v2page);
await shot(v2page, '6-bulk-result-with-reasons');
check('6-1. the run reports a result, not just a count', bulkText.includes(T.bulkResult), true);
check('6-2. total failure has its own sentence', bulkText.includes(T.nothingDone), true);
check('6-3. there is a reason column', bulkText.includes(T.reasonColumn), true);
const resultRows = await v2page.locator('table.tv-table tbody tr').count();
check('6-4. and one row per product that was selected', resultRows >= picked, true);
note(`rows in the result table: ${resultRows}`);
// Every refusal names itself; an empty cell would be the old behaviour.
const emptyReasons = await v2page.$$eval('table.tv-table tbody tr td:last-child',
  (cells) => cells.filter((c) => c.textContent.trim() === '').length);
check('6-5. no row is left without a reason', emptyReasons, 0);
await v2ctx.close();

// ============================================================ items 2 and 7
const { ctx: octx, page: opage } = await newSession();
await signIn(opage, OWNER);
await opage.goto(`${SITE}/wp-admin/admin.php?page=tmc-product-review`, { waitUntil: 'domcontentloaded' });
const adminText = await words(opage);
await shot(opage, '2-manager-catalogue');
check('2-1. the manager has a list of every product', adminText.includes(T.catalogue), true);
check('2-2. with WooCommerce\'s own status beside ours', adminText.includes(T.wcStatusColumn), true);

/** «همه» and the chips, as the admin page renders them. */
async function adminTabs(p) {
  return p.$$eval('.tmc-tabs a, .tmc-tabs span.tmc-tab', (nodes) => nodes.map((n) => {
    const count = n.querySelector('.tmc-tab__count');
    return {
      label: n.textContent.replace(/\s+/g, ' ').trim(),
      raw: (count ? count.textContent : '').trim(),
    };
  }));
}
const tabs = (await adminTabs(opage)).map((t) => ({ ...t, n: Number(fa2en(t.raw)) }));
note(`admin chips: ${JSON.stringify(tabs.map((t) => `${t.label}`))}`);
const all = tabs[0]?.n ?? -1;
const sumOfRest = tabs.slice(1).reduce((a, t) => a + (Number.isFinite(t.n) ? t.n : 0), 0);
check('2-3. «همه» equals the sum of the chips beside it', all, sumOfRest);
const dbTotal = Number(wp('db query "SELECT COUNT(*) FROM wp_tmc_products" --skip-column-names'));
check('2-4. and the database agrees', all, dbTotal);

// A product that has LEFT the queue is still here — the state in which it
// used to disappear from the marketplace admin entirely.
const archivedId = Number(wp(
  'db query "SELECT id FROM wp_tmc_products WHERE status=\'archived\' ORDER BY id LIMIT 1" --skip-column-names'
) || 0);
if (archivedId > 0) {
  await opage.goto(`${SITE}/wp-admin/admin.php?page=tmc-product-review&status=archived`, { waitUntil: 'domcontentloaded' });
  const archivedText = await words(opage);
  check('2-5. an archived product is still listed', archivedText.includes(String(archivedId)), true);
} else {
  note('no archived product on this install; 2-5 not run');
}

// ============================================================ item 7
//
// The owner saw «همه ۴، پیش‌نویس ۳ و بقیه صفر» once, and could not reproduce
// it. This walks the shape they described — a status change, then tabs back
// and forth — and compares the page against the database on every step. It
// is a REPRODUCTION ATTEMPT, and it says so: if every round agrees, the
// finding stays open rather than being declared fixed.
const { ctx: v3ctx, page: v3page } = await newSession();
await signIn(v3page, VENDOR);
async function vendorTabs(p) {
  return p.$$eval('.tv-tabs a, .tv-tabs span.tv-tab', (nodes) => nodes.map((n) => {
    const count = n.querySelector('.tv-tab__count');
    return { label: n.textContent.replace(/\s+/g, ' ').trim(), raw: (count ? count.textContent : '').trim() };
  }));
}
function censusFor(vendorId) {
  const raw = wp(`db query "SELECT status, COUNT(*) c FROM wp_tmc_products WHERE vendor_user_id=${vendorId} GROUP BY status" --skip-column-names`);
  const out = {};
  for (const line of raw.split('\n')) {
    const [status, count] = line.trim().split(/\s+/);
    if (status) { out[status] = Number(count); }
  }
  return out;
}
let rounds = 0;
let disagreements = 0;
for (const target of ['', 'draft', 'published', '', 'submitted', '']) {
  const url = target === '' ? `${SITE}/vendor/products/` : `${SITE}/vendor/products/?status=${target}`;
  await v3page.goto(url, { waitUntil: 'domcontentloaded' });
  const t = (await vendorTabs(v3page)).map((x) => ({ ...x, n: Number(fa2en(x.raw)) }));
  const shown = t[0]?.n ?? -1;
  const chips = t.slice(1).reduce((a, x) => a + (Number.isFinite(x.n) ? x.n : 0), 0);
  const db = Object.values(censusFor(VENDOR_ID)).reduce((a, b) => a + b, 0);
  rounds++;
  if (shown !== chips || shown !== db) {
    disagreements++;
    note(`MISMATCH on «${target || 'همه'}»: page-all=${shown} chips=${chips} database=${db}`);
  }
}
// One status change in the middle, then the same walk again.
wp(`db query "UPDATE wp_tmc_products SET status='draft' WHERE id=${PRODUCT_ID}"`);
for (const target of ['', 'draft', '']) {
  const url = target === '' ? `${SITE}/vendor/products/` : `${SITE}/vendor/products/?status=${target}`;
  await v3page.goto(url, { waitUntil: 'domcontentloaded' });
  const t = (await vendorTabs(v3page)).map((x) => ({ ...x, n: Number(fa2en(x.raw)) }));
  const shown = t[0]?.n ?? -1;
  const chips = t.slice(1).reduce((a, x) => a + (Number.isFinite(x.n) ? x.n : 0), 0);
  const db = Object.values(censusFor(VENDOR_ID)).reduce((a, b) => a + b, 0);
  rounds++;
  if (shown !== chips || shown !== db) {
    disagreements++;
    note(`MISMATCH after the status change on «${target || 'همه'}»: page-all=${shown} chips=${chips} database=${db}`);
  }
}
await shot(v3page, '7-counter-rounds');
note(`counter rounds walked: ${rounds}`);
check('7-1. every round agreed with the database', disagreements, 0);
check('7-2. and «همه» always equalled the sum of the chips', disagreements, 0);
note('7-x. NOT REPRODUCED. This walks the shape the owner described and finds');
note('     no disagreement, so the report stays OPEN rather than closed. What');
note('     would identify it: the response headers on the request that showed');
note('     the wrong numbers (x-cache / age / cf-cache-status), whether it was');
note('     the first load after the action, and the exact four numbers.');
await v3ctx.close();

await browser.close();
lines.push('');
lines.push(`checks: ${pass + fail}  pass: ${pass}  fail: ${fail}`);
fs.writeFileSync(path.join(OUT, 'review-flow-run.txt'), lines.join('\n') + '\n');
console.log(`\nchecks: ${pass + fail}  pass: ${pass}  fail: ${fail}`);
process.exit(fail === 0 ? 0 : 1);
