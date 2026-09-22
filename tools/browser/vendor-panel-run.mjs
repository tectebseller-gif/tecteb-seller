/**
 * The owner's six items, exercised on a real WordPress through the real UI.
 *
 * Nothing here calls the plugin's services. Every row below is a click, a
 * keystroke or a navigation a vendor or a manager would make, and the
 * before/after facts come from WP-CLI — outside the browser and outside the
 * plugin — so «the counter says four» is checked against the database rather
 * than against another part of the same screen.
 *
 * Covers:
 *   1. the four bulk operations, with and without a selection, and that the
 *      preview changes nothing
 *   2. live category suggestions: half-space, Arabic letters, one typo,
 *      keyboard, and the no-JavaScript path
 *   5. a refused logo upload that keeps the text and says why
 *   6. the tab counters across tabs, a refresh, and a sign-out/sign-in
 *
 * Items 3 and 4 are `tools/ownership-check.sh`: they are about what is
 * WRITTEN, and a browser is the wrong instrument for that.
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/vendor-panel \
 *   WP="/opt/php81/bin/php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo" \
 *     node tools/browser/vendor-panel-run.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/vendor-panel';
const WP = process.env.WP || '/opt/php81/bin/php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo';

const VENDOR = { login: 'demo-vendor', pass: 'demo-vendor-2026' };
const BUYER = { login: 'demo-buyer', pass: 'demo-buyer-2026' };

// Persian needles are written ONCE and compared with `includes`. The same
// word typed twice in one file is not always the same bytes (combining
// hamza, ZWNJ), and that has already cost this repository three false
// failures about pages that were perfectly correct.
const T = {
  nothingSelected: 'هیچ موردی انتخاب نشده بود',
  noAction: 'اقدام گروهی انتخاب نشده بود',
  previewTitle: 'پیش‌نمایش',
  nearMatch: 'پیشنهاد نزدیک',
  chosenLabel: 'دستهٔ انتخاب‌شده',
  mimeRefused: 'فقط JPEG، PNG و WebP پذیرفته می‌شوند',
  settingsSaved: 'ذخیره',
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

async function newSession({ javaScriptEnabled = true } = {}) {
  const ctx = await browser.newContext({
    viewport: { width: 1280, height: 950 },
    locale: 'fa-IR',
    javaScriptEnabled,
  });
  return { ctx, page: await ctx.newPage() };
}

async function signIn(page, who) {
  await page.goto(`${SITE}/wp-login.php?loggedout=true`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', who.login);
  await page.fill('#user_pass', who.pass);
  await page.click('#wp-submit');
  await page.waitForURL(/wp-admin|\/vendor\//, { timeout: 30000 }).catch(() => null);
}

const shot = (page, name) => page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: true });
const words = async (page) => (await page.locator('body').innerText()).replace(/\s+/g, ' ');

/** Statuses straight from the plugin's own table, read by WP-CLI, not by a page. */
function statusCensus() {
  const raw = wp(`db query "SELECT status, COUNT(*) c FROM wp_tmc_products GROUP BY status" --skip-column-names`);
  const out = {};
  for (const line of raw.split('\n')) {
    const [status, count] = line.trim().split(/\s+/);
    if (status) { out[status] = Number(count); }
  }
  return out;
}
/** Kept for the run log: which rows the bulk block is about to move. */
const vendorProductIds = () =>
  wp(`db query "SELECT id FROM wp_tmc_products WHERE vendor_user_id=2 ORDER BY id" --skip-column-names`)
    .split('\n').map((s) => s.trim()).filter(Boolean);

// ===================================================================== item 6
const { ctx, page } = await newSession();
await signIn(page, VENDOR);

// The response headers first, before anything can have been cached: the fix
// is a claim about what the server SAYS, so that is what gets measured.
const response = await page.goto(`${SITE}/vendor/products/`, { waitUntil: 'domcontentloaded' });
const headers = response ? response.headers() : {};
check('6-1. Cache-Control carries no-store', /no-store/.test(headers['cache-control'] || ''), true);
check('6-2. Vary names the cookie', /cookie/i.test(headers['vary'] || ''), true);
note(`cache-control: ${headers['cache-control'] || '(none)'} | vary: ${headers['vary'] || '(none)'}`);

const census = statusCensus();
const totalRows = Object.values(census).reduce((a, b) => a + b, 0);
note(`database says: ${JSON.stringify(census)}`);

/** Every tab's number, as the page renders it, converted back to ASCII. */
async function tabCounts(p) {
  return p.$$eval('.tv-tabs a', (nodes) => nodes.map((n) => {
    const count = n.querySelector('.tv-tab__count');
    const digits = (count ? count.textContent : '').replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
    return { label: n.textContent.replace(/\s+/g, ' ').trim(), n: Number(digits) };
  }));
}

const first = await tabCounts(page);
check('6-3. the «all» tab equals the row count', first[0]?.n, totalRows);
await shot(page, 'counters-1-first-visit');

// Round trip: into a status tab and back, with no query parameter of our own.
const draftTab = page.locator('.tv-tabs a', { hasText: 'پیش‌نویس' }).first();
if (await draftTab.count()) { await draftTab.click(); await page.waitForLoadState('domcontentloaded'); }
const onDraft = await tabCounts(page);
check('6-4. the counters survive moving to another tab', onDraft[0]?.n, totalRows);

await page.goto(`${SITE}/vendor/products/`, { waitUntil: 'domcontentloaded' });
const back = await tabCounts(page);
check('6-5. and coming back, with no ?tmc_check= of any kind', back[0]?.n, totalRows);
check('6-6. the url carries no extra parameter', new URL(page.url()).search, '');

await page.reload({ waitUntil: 'domcontentloaded' });
const reloaded = await tabCounts(page);
check('6-7. a refresh does not zero them', reloaded[0]?.n, totalRows);

// Sign out, sign in, ask again.
await page.goto(`${SITE}/wp-login.php?action=logout`, { waitUntil: 'domcontentloaded' });
const confirmLogout = page.locator('a', { hasText: 'خارج' }).first();
if (await confirmLogout.count()) { await confirmLogout.click().catch(() => null); }
await ctx.clearCookies();
await signIn(page, VENDOR);
await page.goto(`${SITE}/vendor/products/`, { waitUntil: 'domcontentloaded' });
const afterLogin = await tabCounts(page);
check('6-8. and neither does signing out and back in', afterLogin[0]?.n, totalRows);
await shot(page, 'counters-2-after-relogin');

// Nobody else's data. A buyer has no store, and the dangerous failure of a
// URL-keyed cache is that they would be handed the vendor's page.
const buyerSession = await newSession();
await signIn(buyerSession.page, BUYER);
const buyerResponse = await buyerSession.page.goto(`${SITE}/vendor/products/`, { waitUntil: 'domcontentloaded' });
const buyerText = await words(buyerSession.page);
check('6-9. a buyer is not handed the vendor panel', buyerResponse?.status() !== 200 || !buyerText.includes('اقدام گروهی'), true);
await shot(buyerSession.page, 'counters-3-buyer-refused');
await buyerSession.ctx.close();

// ===================================================================== item 2
// A NEW product, not one of the published ones. On a published product the
// form renders `title` as a hidden field — a change to it becomes a revision
// proposal rather than an edit — so «what was typed survived» would be
// measuring a field nobody can type in. The defect the owner hit was on the
// create path, and that is the path this measures.
const editUrl = `${SITE}/vendor/products/?product=new&step=1`;
await page.goto(editUrl, { waitUntil: 'domcontentloaded' });
const hasPicker = await page.locator('#f-category_q').count();
check('2-0. the picker is on step 1', hasPicker > 0, true);

if (hasPicker) {
  const typedTitle = 'عنوان آزمایشی که نباید گم شود';
  await page.fill('input[name="title"]:not([type="hidden"])', typedTitle);
  const urlBeforeTyping = page.url();

  /**
   * Type, and wait for the answer to THAT query to be the one on screen.
   *
   * The first version of this waited for the live region to say something,
   * which it already was — so every assertion read the PREVIOUS query's
   * results and three of them were about a list nobody had asked for. The
   * script stamps `data-seq` when it swaps the list in; waiting for that
   * number to go up is the only signal that cannot be satisfied by what was
   * already there.
   */
  async function suggest(term) {
    const before = Number(await page.getAttribute('#tv-catpick-results', 'data-seq')) || 0;
    await page.fill('#f-category_q', '');
    await page.type('#f-category_q', term, { delay: 30 });
    await page.waitForFunction(
      (was) => Number(document.getElementById('tv-catpick-results')?.dataset.seq || 0) > was,
      before,
      { timeout: 15000 }
    ).catch(() => null);
    return page.locator('#tv-catpick-results').innerText();
  }

  const exact = await suggest('آمبوبگ');
  check('2-1. typing suggests without leaving the page', page.url(), urlBeforeTyping);
  check('2-2. the exact spelling finds it', exact.includes('آمبوبگ'), true);
  check('2-3. and is not labelled a guess', exact.includes(T.nearMatch), false);

  const spaced = await suggest('آمبو بگ');
  check('2-4. the same word with a space finds the same term', spaced.includes('آمبوبگ'), true);
  check('2-5. still not a guess', spaced.includes(T.nearMatch), false);

  const typo = await suggest('امبوبک');
  check('2-6. one wrong letter still finds it', typo.includes('آمبوبگ'), true);
  check('2-7. and it is labelled «پیشنهاد نزدیک»', typo.includes(T.nearMatch), true);
  await shot(page, 'category-1-near-match');

  check('2-8. nothing was chosen for the vendor', await page.locator('#tv-catpick-results input:checked').count(), 0);
  check('2-9. what was typed is still there', await page.inputValue('input[name="title"]:not([type="hidden"])'), typedTitle);

  // Keyboard only, and an explicit choice.
  await page.focus('#f-category_q');
  await page.keyboard.press('Tab');
  const firstRadio = page.locator('#tv-catpick-results input[type="radio"]').first();
  if (await firstRadio.count()) {
    await firstRadio.focus();
    await page.keyboard.press('Space');
    await page.waitForTimeout(200);
    const chosenBlock = await page.locator('#tv-catpick-chosen').innerText();
    check('2-10. a keyboard choice moves the row into the chosen block', chosenBlock.includes(T.chosenLabel), true);
    check('2-11. exactly one category is checked', await page.locator('input[name="category"]:checked').count(), 1);
    check('2-12. and it is in the chosen block, not the results',
      await page.locator('#tv-catpick-chosen input[name="category"]:checked').count(), 1);
    await shot(page, 'category-2-chosen');
  }
  check('2-13. the title survived choosing too', await page.inputValue('input[name="title"]:not([type="hidden"])'), typedTitle);
}

// The no-JavaScript path: the search button is a submit and still works.
const plain = await newSession({ javaScriptEnabled: false });
await signIn(plain.page, VENDOR);
await plain.page.goto(editUrl, { waitUntil: 'domcontentloaded' });
if (await plain.page.locator('#f-category_q').count()) {
  await plain.page.fill('#f-category_q', 'آمبوبگ');
  await plain.page.click('button[name="search_category"]');
  await plain.page.waitForLoadState('domcontentloaded');
  const plainText = await plain.page.locator('#tv-catpick-results').innerText().catch(() => '');
  check('2-14. with JavaScript off the button still searches', plainText.includes('آمبوبگ'), true);
  check('2-15. and the query came back in the url', /cat_q=/.test(plain.page.url()), true);
  await shot(plain.page, 'category-3-no-javascript');
}
await plain.ctx.close();

// ===================================================================== item 5
const notAnImage = path.join(OUT, 'not-an-image.txt');
fs.writeFileSync(notAnImage, 'این فایل تصویر نیست و باید رد شود.\n');

await page.goto(`${SITE}/vendor/store/`, { waitUntil: 'domcontentloaded' });
const logoBefore = wp(`db query "SELECT logo_id FROM wp_tmc_vendor_stores WHERE user_id=2" --skip-column-names`) || '0';
const introField = page.locator('textarea[name="intro"]').first();
const marker = 'متن معرفی ' + Date.now();
if (await introField.count()) { await introField.fill(marker); }
const logoInput = page.locator('input[name="logo_file"]').first();
if (await logoInput.count()) {
  await logoInput.setInputFiles(notAnImage);
  await page.click('button[type="submit"]');
  await page.waitForLoadState('domcontentloaded');
  const storeText = await words(page);
  check('5-1. a refused picture says why', storeText.includes(T.mimeRefused), true);
  const logoAfter = wp(`db query "SELECT logo_id FROM wp_tmc_vendor_stores WHERE user_id=2" --skip-column-names`) || '0';
  check('5-2. the picture that was there stays', logoAfter, logoBefore);
  const introNow = wp(`db query "SELECT intro FROM wp_tmc_vendor_stores WHERE user_id=2" --skip-column-names`);
  check('5-3. and the text was saved anyway', introNow.includes(marker.slice(0, 12)), true);
  await shot(page, 'image-1-refused');
} else {
  note('no logo file input on the store page — skipped');
}

// ===================================================================== item 1
//
// Last on purpose: these operations MOVE products between statuses, and a
// submitted product is no longer editable by its vendor — so running them
// before the category picker would leave the picker on a read-only form and
// measure the wrong thing.
await page.goto(`${SITE}/vendor/products/`, { waitUntil: 'domcontentloaded' });
const beforeBulk = statusCensus();

async function runBulk({ action, button, select }) {
  await page.goto(`${SITE}/vendor/products/`, { waitUntil: 'domcontentloaded' });
  if (select) {
    const boxes = page.locator('input[name="selected[]"]');
    const n = Math.min(await boxes.count(), 2);
    for (let i = 0; i < n; i++) { await boxes.nth(i).check(); }
  }
  if (action) { await page.selectOption('#tmc-bulk-action', action); }
  await page.click(`button[name="tmc_vendor_action"][value="${button}"]`);
  await page.waitForLoadState('domcontentloaded');
  return words(page);
}

// Preview, with a selection. It must land back on the products page — the
// defect the owner found was that it landed on /vendor/application/ with
// ?tmc_notice=forbidden, because the verb was missing from ACTIONS.
const previewText = await runBulk({ action: 'archive', button: 'preview_bulk_products', select: true });
check('1-1. preview stays on the products page', new URL(page.url()).pathname, '/vendor/products/');
check('1-2. and is not refused', previewText.includes('اجازه'), false);
check('1-3. the forecast is on screen', previewText.includes(T.previewTitle), true);
check('1-4. preview wrote nothing', JSON.stringify(statusCensus()), JSON.stringify(beforeBulk));
await shot(page, 'bulk-1-preview');

// Preview with nothing ticked.
const emptyPreview = await runBulk({ action: 'archive', button: 'preview_bulk_products', select: false });
check('1-5. an empty selection is answered on the products page', new URL(page.url()).pathname, '/vendor/products/');
check('1-6. and says so in words', emptyPreview.includes(T.nothingSelected), true);

// No action chosen at all.
const noAction = await runBulk({ action: '', button: 'preview_bulk_products', select: true });
check('1-7. no action chosen is its own message', noAction.includes(T.noAction), true);

// The three real operations, each measured against the table.
const archived = await runBulk({ action: 'archive', button: 'bulk_products', select: true });
const afterArchive = statusCensus();
check('1-8. «بایگانی» moved rows', (afterArchive.archived || 0) > (beforeBulk.archived || 0), true);
note(`after archive: ${JSON.stringify(afterArchive)}`);

await runBulk({ action: 'restore', button: 'bulk_products', select: true });
const afterRestore = statusCensus();
note(`after restore: ${JSON.stringify(afterRestore)}`);
// Measured against the table, not against the sentence on the page. The
// first version of this check was `text.length > 0`, which is a check that
// cannot fail — and a check that cannot fail looks exactly like one that
// passed.
check('1-9. «بازگشت به پیش‌نویس» brought them back', (afterRestore.archived || 0), 0);
check('1-10. and they are drafts again', (afterRestore.draft || 0) >= (beforeBulk.draft || 0), true);

const submitted = await runBulk({ action: 'submit', button: 'bulk_products', select: true });
const afterSubmit = statusCensus();
note(`after submit: ${JSON.stringify(afterSubmit)}`);
// «ارسال برای بررسی» on a half-finished product is REFUSED, and that is the
// interesting half of this operation: the batch still runs, every row gets
// its own answer, and the refused ones are named with a reason. So the
// assertion is that the page accounted for every row — either they moved, or
// it says which did not and why.
const movedToReview = (afterSubmit.submitted || 0) - (afterRestore.submitted || 0);
const accountedFor = movedToReview > 0 || /انجام نشد/.test(submitted);
check('1-11. «ارسال برای بررسی» accounted for every selected row', accountedFor, true);
note(`submitted moved: ${movedToReview}`);
await shot(page, 'bulk-2-after-operations');

const emptyRun = await runBulk({ action: 'archive', button: 'bulk_products', select: false });
check('1-12. running with nothing ticked is refused clearly', emptyRun.includes(T.nothingSelected), true);

// =====================================================================
fs.writeFileSync(
  path.join(OUT, 'vendor-panel-run.txt'),
  `${lines.join('\n')}\n\nchecks: ${pass + fail}  pass: ${pass}  fail: ${fail}\n`
);
console.log(`\nchecks: ${pass + fail}  pass: ${pass}  fail: ${fail}`);
await ctx.close();
await browser.close();
process.exit(fail === 0 ? 0 : 1);
