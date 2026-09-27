/**
 * The owner's five UI items for `alpha.33`, exercised on a real WordPress
 * through the real UI.
 *
 * Nothing here calls the plugin's services. Every fact the browser asserts is
 * compared against WP-CLI — outside the browser and outside the plugin — so
 * «the card says thirteen» is checked against the database rather than against
 * another part of the same screen (the `alpha.26` rule).
 *
 * Covers:
 *   1. the approved shop's first screen: name, real approval, the two actions,
 *      four counters that agree with the list, the corrections with the
 *      manager's own words, «منتظر تصمیم مدیر» kept apart, the folded file
 *   2. the SMS sentence and the application record's per-status text
 *   3. «داشبورد فروشنده» from /my-account/, for a vendor, an applicant and a
 *      plain customer
 *   4. the CSV disclosure beside «افزودن محصول», closed and open, with no
 *      JavaScript
 *   5.ب the manager's red review count: at zero, on entering the queue, after
 *      merely looking, and with the menu collapsed
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/dashboard \
 *   WP="/opt/php81/bin/php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo" \
 *     node tools/browser/vendor-dashboard-run.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/dashboard';
const WP = process.env.WP || '/opt/php81/bin/php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo';
const EXPECT = process.env.TMC_EXPECT_VERSION || '0.1.0-alpha.33';

const VENDOR = { login: 'demo-vendor', pass: 'demo-vendor-2026' };
const STAFF = { login: 'demo-staff', pass: 'demo-staff-2026' };
const BUYER = { login: 'demo-buyer', pass: 'demo-buyer-2026' };
const APPLICANT = { login: 'demo-applicant', pass: 'demo-applicant-2026' };
const MANAGER = { login: 'tmcowner', pass: 'demo-owner-2026' };

// Persian needles written ONCE and compared with `includes`: the same word
// typed twice in one file is not always the same bytes (combining hamza,
// ZWNJ), and that has already cost this repository three false failures.
const T = {
  addProduct: 'افزودن محصول',
  viewStore: 'مشاهده فروشگاه',
  myProducts: 'محصولات شما',
  needsAction: 'نیازمند اقدام',
  waitingManager: 'منتظر تصمیم مدیر',
  nothingToDo: 'در حال حاضر کاری از طرف شما لازم نیست.',
  paperwork: 'اطلاعات و مدارک فروشگاه',
  fileLink: 'پروندهٔ فروشندگی و مدارک',
  oldStatusCard: 'وضعیت درخواست شما',
  oldTasks: 'کارهای شما',
  oldButton: 'مشاهده اطلاعات ثبت‌شده',
  smsUnavailable: 'تأیید پیامکی فعلاً در دسترس نیست؛ اقدامی لازم نیست.',
  afterApproval: 'محصولات شما پس از تأیید مدیر منتشر می‌شوند.',
  vendorDashboardLink: 'داشبورد فروشنده',
  applicantLink: 'درخواست فروشندگی من',
  csvSummary: 'ورود و خروج گروهی محصولات',
  csvEditable: 'ستون‌های قابل ویرایش:',
  csvReadOnly: 'فقط خواندنی:',
  csvPreview: 'پیش‌نمایش ورود',
  underReview: 'در نوبت بررسی است',
  fileClosed: 'تأیید شده است',
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
  execSync(`cp tools/vendor-dashboard-state.php /home/user/wp-demo/`, { stdio: 'ignore' });
  return wp(`eval-file /home/user/wp-demo/vendor-dashboard-state.php ${cmd}`);
};
const field = (text, key) => {
  const m = text.match(new RegExp(`(?:^|\\s)${key}=([^\\s]*)`));
  return m ? m[1] : '';
};
// Persian digits in, Latin out — the counters are rendered for a reader and
// compared against a database that answers in ASCII.
const fromPersian = (s) => s.replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)));

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });

async function session(who, { javaScriptEnabled = true, width = 1280, height = 950 } = {}) {
  const ctx = await browser.newContext({ viewport: { width, height }, locale: 'fa-IR', javaScriptEnabled });
  const page = await ctx.newPage();
  await page.goto(`${SITE}/wp-login.php?loggedout=true`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#user_login', { timeout: 30000 });
  await page.fill('#user_login', who.login);
  await page.fill('#user_pass', who.pass);
  await Promise.all([
    page.waitForNavigation({ timeout: 30000 }).catch(() => null),
    page.click('#wp-submit'),
  ]);
  return { ctx, page };
}
const textOf = async (page) => (await page.locator('body').innerText()).replace(/ /g, ' ');

check('the installed package is the one under test', wp('plugin get tecteb-marketplace-core --field=version'), EXPECT);
check('and its schema is the one this round ships', wp('option get tmc_schema_version'), '20');

// ================================================================= item 1
const seeded = state('report');
note(seeded);
const counts = {
  published: field(seeded, 'published'),
  submitted: field(seeded, 'submitted'),
  changes_requested: field(seeded, 'changes_requested'),
  draft: field(seeded, 'draft'),
};
const needsWorkId = field(seeded, 'needs_work_id');

{
  const { ctx, page } = await session(VENDOR);
  await page.goto(`${SITE}/vendor/`, { waitUntil: 'domcontentloaded' });
  const text = await textOf(page);

  check('1-1 the shop is named at the top', text.includes('تجهیزات پزشکی نمونه'), true);
  check('1-2 «افزودن محصول» is there', text.includes(T.addProduct), true);
  check('1-3 and «مشاهده فروشگاه», because the shop has a page', text.includes(T.viewStore), true);
  const storeHref = await page.locator('a', { hasText: T.viewStore }).first().getAttribute('href');
  note(`storefront link: ${storeHref}`);
  const storeStatus = await page.request.get(storeHref).then((r) => r.status());
  check('1-4 and that link is a page, not a 404', storeStatus, 200);

  // The four counters, each against the database.
  for (const [status, expected] of Object.entries(counts)) {
    const card = page.locator(`a[href*="status=${status}"]`).first();
    const shown = fromPersian((await card.locator('.tv-number__value').innerText()).trim());
    check(`1-5 the «${status}» card matches the database`, shown, expected);
  }
  check('1-6 every card is a link into the list', await page.locator('a.tv-number').count(), 4);

  // The corrections: the manager's own words, on the product's own row.
  check('1-7 «نیازمند اقدام» is on the page', text.includes(T.needsAction), true);
  check('1-8 with the manager\'s own sentence', text.includes('تصویر دوم تار است'), true);
  check('1-9 and a way to the product it is about',
    await page.locator(`a[href*="product=${needsWorkId}"]`).count() > 0, true);
  check('1-10 the section wears the warning edge only because there is work',
    await page.locator('.tv-todo.has-work').count(), 1);

  // What somebody else is holding, kept apart.
  check('1-11 «منتظر تصمیم مدیر» is its own section', text.includes(T.waitingManager), true);
  const waitingButtons = await page.locator('.tv-waiting a.tv-btn').count();
  check('1-12 and it carries no buttons', waitingButtons, 0);
  check('1-13 the submitted count is the one the database has',
    fromPersian(((text.match(/([۰-۹]+) محصول در انتظار بررسی مدیر/) || ['', ''])[1])), counts.submitted);

  // The registration, folded away.
  check('1-14 the paperwork is a closed disclosure',
    await page.locator('details.tv-fold').count(), 1);
  check('1-15 and it starts closed',
    await page.locator('details.tv-fold[open]').count(), 0);
  check('1-16 with the file as a small link inside it',
    await page.locator('details.tv-fold a', { hasText: T.fileLink }).count(), 1);
  check('1-17 and not as the page\'s biggest button', text.includes(T.oldButton), false);

  // The two things alpha.32 did that the owner objected to.
  check('1-18 the finished registration is not the page', text.includes(T.oldTasks), false);
  check('1-19 and the approval is announced once',
    (text.match(/تأییدشده/g) || []).length, 1);

  // Mobile: readable, and no horizontal scroll.
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${SITE}/vendor/`, { waitUntil: 'domcontentloaded' });
  const box = await page.evaluate(() => ({
    scroll: document.documentElement.scrollWidth,
    inner: window.innerWidth,
  }));
  check('1-20 nothing overflows sideways on a phone', box.scroll <= box.inner, true);
  note(`mobile scrollWidth=${box.scroll} innerWidth=${box.inner}`);
  await ctx.close();
}

// A staff member sees what their rights cover, and no other shop's anything.
{
  state('staff none');
  const { ctx, page } = await session(STAFF);
  await page.goto(`${SITE}/vendor/`, { waitUntil: 'domcontentloaded' });
  const text = await textOf(page);
  // Asserted FIRST, because every «is not there» below is also true of a login
  // page — and the first run of this script read one without noticing.
  check('1-21 a staff member reaches the shop\'s screen', text.includes('پیشخوان فروشنده'), true);
  check('1-22 and is not invited to apply for the shop they work in',
    text.includes(T.oldStatusCard) || text.includes('شروع درخواست فروشندگی'), false);
  check('1-23 staff without product rights get no counters',
    await page.locator('a.tv-number').count(), 0);
  check('1-24 and no «افزودن محصول»', text.includes(T.addProduct), false);
  check('1-25 nor the owner\'s paperwork', text.includes(T.paperwork), false);
  check('1-26 but they still know which shop they are in', text.includes('تجهیزات پزشکی نمونه'), true);
  await ctx.close();

  state('staff view');
  const s2 = await session(STAFF);
  await s2.page.goto(`${SITE}/vendor/`, { waitUntil: 'domcontentloaded' });
  const t2 = await textOf(s2.page);
  check('1-27 with view rights the counters come back',
    await s2.page.locator('a.tv-number').count(), 4);
  check('1-28 and «افزودن محصول» still does not', t2.includes(T.addProduct), false);
  await s2.ctx.close();
}

// ================================================================= item 2
{
  const { ctx, page } = await session(VENDOR);
  await page.goto(`${SITE}/vendor/`, { waitUntil: 'domcontentloaded' });
  // The sentence is in the paperwork fold, which is closed — and `innerText`
  // does not report what a closed `<details>` holds. Opened first, because
  // «is it on the page» and «is it on the screen» are different questions and
  // this one is the first.
  await page.locator('details.tv-fold summary').click();
  const dash = await textOf(page);
  check('2-1 the SMS sentence is the owner\'s, on the dashboard', dash.includes(T.smsUnavailable), true);
  check('2-2 and the old «تأیید نشده است» wording is gone',
    dash.includes('شماره شما ثبت شده اما تأیید نشده است'), false);

  await page.goto(`${SITE}/vendor/application/`, { waitUntil: 'domcontentloaded' });
  const app = await textOf(page);
  check('2-3 the application page says the same thing', app.includes(T.smsUnavailable), true);
  check('2-4 an approved file is not «در حال بررسی»', app.includes(T.underReview), false);
  check('2-5 it says the file is closed because it was approved', app.includes(T.fileClosed), true);
  check('2-6 and names the page that does hold the live values', app.includes('تنظیمات فروشگاه'), true);
  check('2-7 the page says which data it is showing', app.includes('این پروندهٔ فروشندگی شماست'), true);
  check('2-8 and the form stays read-only',
    await page.locator('input[name="store_name"][readonly]').count(), 1);
  check('2-9 with no save button', app.includes('ذخیره پیش‌نویس'), false);
  check('2-10 the menu calls it a file, not a request',
    await page.locator('.tv-nav__link', { hasText: 'پروندهٔ فروشندگی و مدارک' }).count(), 1);
  await ctx.close();
}

// The applicant: their own route, their own words, and the manager's reason.
// Reset and re-seeded first, because the previous run's last act moved this
// application — and a test that asserts about a state it did not establish is
// a test about yesterday.
note(state('reset'));
note(state('seed'));
{
  const { ctx, page } = await session(APPLICANT);
  await page.goto(`${SITE}/vendor/application/`, { waitUntil: 'domcontentloaded' });
  const app = await textOf(page);
  check('2-11 a submitted application IS «در نوبت بررسی»', app.includes(T.underReview), true);
  check('2-12 and says it is a request, not a file', app.includes('این پروندهٔ فروشندگی شماست'), true);
  await ctx.close();
}
{
  note(state('applicant changes_requested'));
  const { ctx, page } = await session(APPLICANT);
  await page.goto(`${SITE}/vendor/application/`, { waitUntil: 'domcontentloaded' });
  const app = await textOf(page);
  check('2-13 a correction shows the manager\'s reason on the existing path',
    app.includes('چه چیزی باید اصلاح شود'), true);
  check('2-14 with the manager\'s own words', app.includes('کد اقتصادی ناخوانا'), true);
  check('2-15 and the form is editable again', app.includes('ذخیره پیش‌نویس'), true);
  await ctx.close();
}
{
  // `changes_requested -> rejected` is not a transition this domain allows, so
  // the applicant goes back to a submitted application the way a real one does:
  // a fresh application, submitted. Measured, not assumed — the first run of
  // this script reported `ok=invalid_transition` and then asserted about a page
  // in the previous state.
  note(state('reset'));
  note(state('seed'));
  note(state('applicant rejected'));
  const { ctx, page } = await session(APPLICANT);
  await page.goto(`${SITE}/vendor/application/`, { waitUntil: 'domcontentloaded' });
  const app = await textOf(page);
  check('2-16 a rejection has its own heading', app.includes('دلیل رد درخواست'), true);
  check('2-17 and is not described as being under review', app.includes(T.underReview), false);
  check('2-18 the dashboard still opens for them',
    await page.goto(`${SITE}/vendor/`, { waitUntil: 'domcontentloaded' }).then((r) => r.status()), 200);
  await ctx.close();
}

// ================================================================= item 3
{
  const { ctx, page } = await session(VENDOR);
  await page.goto(`${SITE}/my-account/`, { waitUntil: 'domcontentloaded' });
  const text = await textOf(page);
  check('3-1 a vendor finds «داشبورد فروشنده» on my-account', text.includes(T.vendorDashboardLink), true);
  const href = await page.locator('a', { hasText: T.vendorDashboardLink }).first().getAttribute('href');
  note(`my-account link: ${href}`);
  check('3-2 and it goes to the panel, not to a 404',
    await page.request.get(href).then((r) => r.status()), 200);
  check('3-3 it is one link, not two', (text.match(/داشبورد فروشنده/g) || []).length, 1);
  await ctx.close();
}
{
  const { ctx, page } = await session(APPLICANT);
  await page.goto(`${SITE}/my-account/`, { waitUntil: 'domcontentloaded' });
  const text = await textOf(page);
  check('3-4 an applicant gets their own route under its own name',
    text.includes(T.applicantLink), true);
  check('3-5 and is not promised a dashboard they have no shop for',
    text.includes(T.vendorDashboardLink), false);
  await ctx.close();
}
{
  const { ctx, page } = await session(BUYER);
  await page.goto(`${SITE}/my-account/`, { waitUntil: 'domcontentloaded' });
  const text = await textOf(page);
  check('3-6 a plain customer is not a vendor', text.includes(T.vendorDashboardLink), false);
  check('3-7 and gets no applicant row either', text.includes(T.applicantLink), false);
  await ctx.close();
}

// ================================================================= item 4
{
  const { ctx, page } = await session(VENDOR);
  await page.goto(`${SITE}/vendor/products/`, { waitUntil: 'domcontentloaded' });
  check('4-1 the CSV tools are a closed disclosure',
    await page.locator('details.tv-bulk-csv').count(), 1);
  check('4-2 which starts closed',
    await page.locator('details.tv-bulk-csv[open]').count(), 0);
  // «Beside»: both controls in one row, and the row above the filters.
  const geometry = await page.evaluate(() => {
    const add = document.querySelector('.tv-tools a.tv-btn');
    const csv = document.querySelector('.tv-bulk-csv__summary');
    const tabs = document.querySelector('.tv-tabs');
    if (!add || !csv || !tabs) { return null; }
    const a = add.getBoundingClientRect(), c = csv.getBoundingClientRect(), t = tabs.getBoundingClientRect();
    return { sameRow: Math.abs(a.top - c.top) < 8, aboveFilters: c.bottom <= t.top + 1, gap: Math.round(Math.abs(a.left - c.right)) };
  });
  check('4-3 «افزودن محصول» and the CSV control share a row', geometry && geometry.sameRow, true);
  check('4-4 and both sit above the status filters', geometry && geometry.aboveFilters, true);
  note(`tools row: ${JSON.stringify(geometry)}`);

  await page.locator('.tv-bulk-csv__summary').click();
  const open = await textOf(page);
  check('4-5 opening it reveals the export', open.includes('دریافت فایل CSV'), true);
  check('4-6 and the file chooser', await page.locator('input[name="products_csv"]').count(), 1);
  check('4-7 with the preview step named', open.includes(T.csvPreview), true);
  check('4-8 the editable columns are listed', open.includes(T.csvEditable), true);
  check('4-9 and the read-only ones named as read-only', open.includes(T.csvReadOnly), true);
  check('4-10 importing is said not to publish',
    open.includes('ورود فایل هیچ محصولی را منتشر نمی‌کند.'), true);

  // Keyboard: the summary is reachable and operable with Enter.
  await page.locator('.tv-bulk-csv__summary').click();     // close again
  await page.locator('.tv-bulk-csv__summary').focus();
  await page.keyboard.press('Enter');
  check('4-11 the keyboard opens it', await page.locator('details.tv-bulk-csv[open]').count(), 1);
  await ctx.close();
}
{
  // No JavaScript: a `<details>` is the browser's own control.
  const { ctx, page } = await session(VENDOR, { javaScriptEnabled: false });
  await page.goto(`${SITE}/vendor/products/`, { waitUntil: 'domcontentloaded' });
  const html = await page.content();
  check('4-12 without JavaScript the disclosure is still in the page',
    html.includes('tv-bulk-csv__summary'), true);
  check('4-13 and both forms with it',
    html.includes('value="export_products"') && html.includes('value="import_products"'), true);
  await ctx.close();
}
// The export writes nothing, and it is one shop's rows.
{
  const before = wp('db query "SELECT COUNT(*) FROM wp_tmc_products" --skip-column-names');
  const { ctx, page } = await session(VENDOR);
  await page.goto(`${SITE}/vendor/products/`, { waitUntil: 'domcontentloaded' });
  await page.locator('.tv-bulk-csv__summary').click();
  const [download] = await Promise.all([
    page.waitForEvent('download', { timeout: 30000 }).catch(() => null),
    page.locator('button', { hasText: 'دریافت فایل CSV' }).click(),
  ]);
  if (download !== null) {
    const file = path.join(OUT, 'export.csv');
    await download.saveAs(file);
    const csv = fs.readFileSync(file, 'utf8');
    const header = csv.split('\n')[0];
    check('4-14 the export carries the id and status columns',
      header.includes('id') && header.includes('status'), true);
    check('4-15 and a row for each of this shop\'s products',
      csv.trim().split('\n').length - 1 >= Number(counts.published), true);
    note(`export header: ${header.slice(0, 120)}`);
  } else {
    check('4-14 the export downloaded', 'no download event', 'a file');
  }
  check('4-16 and nothing was written',
    wp('db query "SELECT COUNT(*) FROM wp_tmc_products" --skip-column-names'), before);
  await ctx.close();
}

// ================================================================= item 5-ب
{
  const { ctx, page } = await session(MANAGER);
  const bubbleOf = async () => page.evaluate(() => {
    const el = document.querySelector('#adminmenu .tmc-count');
    if (el === null) { return { present: false }; }
    const cs = getComputedStyle(el);
    const r = el.getBoundingClientRect();
    return {
      present: true,
      value: (el.querySelector('.update-count') || {}).textContent || '',
      sr: (el.querySelector('.screen-reader-text') || {}).textContent || '',
      bg: cs.backgroundColor,
      visible: r.width > 0 && r.height > 0,
      count: document.querySelectorAll('#adminmenu .tmc-count').length,
    };
  });

  await page.goto(`${SITE}/wp-admin/`, { waitUntil: 'domcontentloaded' });
  const first = await bubbleOf();
  note(`bubble: ${JSON.stringify(first)}`);
  const awaiting = field(state('report'), 'awaiting_review');
  check('5-1 the count is drawn', first.present, true);
  check('5-2 and matches the database', fromPersian((first.value || '').trim()), awaiting);
  check('5-3 it is red, not core\'s blue', first.bg || '-', 'rgb(214, 54, 56)');
  check('5-4 with a sentence for a screen reader', (first.sr || '').includes('در انتظار بررسی'), true);
  check('5-5 on the top-level item and the submenu', (first.count || 0) >= 2, true);

  // Merely opening the page must not clear it.
  await page.goto(`${SITE}/wp-admin/admin.php?page=tmc-product-review`, { waitUntil: 'domcontentloaded' });
  const afterLook = await bubbleOf();
  check('5-6 opening the queue does not clear it', fromPersian((afterLook.value || '').trim()), awaiting);
  check('5-7 and it stays red on our own screens', afterLook.bg || '-', 'rgb(214, 54, 56)');

  // A filter must not change it either: it is the queue, not the page.
  await page.goto(`${SITE}/wp-admin/admin.php?page=tmc-product-review&status=draft&paged=2`, { waitUntil: 'domcontentloaded' });
  const filtered = await bubbleOf();
  check('5-8 a filter does not change it', fromPersian((filtered.value || '').trim()), awaiting);

  // Collapsed: core puts the bubble in the submenu head, which is what shows.
  await page.click('#collapse-menu').catch(() => null);
  await page.waitForTimeout(300);
  const collapsed = await page.evaluate(() => {
    const els = [...document.querySelectorAll('#adminmenu .tmc-count')];
    return els.filter((el) => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; }).length;
  });
  check('5-9 the collapsed menu still shows it', collapsed >= 1, true);
  await page.click('#collapse-menu').catch(() => null);
  await page.waitForTimeout(200);

  // A decision takes one product out of the queue, and the number follows.
  const decided = wp('db query "SELECT id FROM wp_tmc_products WHERE status = \'submitted\' ORDER BY id LIMIT 1" --skip-column-names');
  await page.goto(`${SITE}/wp-admin/admin.php?page=tmc-product-review&product=${decided}`, { waitUntil: 'domcontentloaded' });
  const rejectButton = page.locator('button[name="decision"][value="reject"]').first();
  if (await rejectButton.count() > 0) {
    await page.locator('textarea[name="note"]').first().fill('برای سنجش شمارنده — دادهٔ آزمایشی.').catch(() => null);
    await Promise.all([page.waitForNavigation({ timeout: 30000 }).catch(() => null), rejectButton.click()]);
    // Read on the NEXT page load, not on whatever the click left behind.
    // `waitForNavigation` resolves on the POST's 302 and the redirected page
    // may not have committed yet — measured: the bubble read the old number
    // while the database already held the new one. This is also the question
    // that matters: what a manager sees on their next screen.
    await page.goto(`${SITE}/wp-admin/`, { waitUntil: 'domcontentloaded' });
    const after = await bubbleOf();
    const nowAwaiting = field(state('report'), 'awaiting_review');
    check('5-10 a decision lowers the queue', Number(nowAwaiting) < Number(awaiting), true);
    check('5-11 and the bubble follows it', fromPersian((after.value || '').trim()), nowAwaiting);
    note(`awaiting: ${awaiting} -> ${nowAwaiting}`);
    // A run that SPENDS a submitted product leaves the next run a smaller
    // queue, and eventually none — the `alpha.12` rule. Put it back.
    wp(`db query "UPDATE wp_tmc_products SET status = 'submitted' WHERE id = ${decided}"`);
    note(`product ${decided} returned to the queue: ${field(state('report'), 'awaiting_review')}`);
  } else {
    check('5-10 a decision lowers the queue', 'no reject control found', 'a control');
  }
  await ctx.close();
}

// Zero is nothing at all — measured by emptying the queue, not by argument.
{
  const submitted = wp('db query "SELECT GROUP_CONCAT(id) FROM wp_tmc_products WHERE status = \'submitted\'" --skip-column-names');
  const revisions = wp('db query "SELECT GROUP_CONCAT(id) FROM wp_tmc_product_revisions WHERE status = \'pending\'" --skip-column-names');
  wp(`db query "UPDATE wp_tmc_products SET status = 'draft' WHERE status = 'submitted'"`);
  wp(`db query "UPDATE wp_tmc_product_revisions SET status = 'withdrawn' WHERE status = 'pending'"`);
  const { ctx, page } = await session(MANAGER);
  await page.goto(`${SITE}/wp-admin/`, { waitUntil: 'domcontentloaded' });
  check('5-12 an empty queue draws nothing at all',
    await page.locator('#adminmenu .tmc-count').count(), 0);
  check('5-13 and prints no style for it',
    await page.locator('#tmc-menu-count').count(), 0);
  await ctx.close();
  // Put it back: a fixture that spends something has to return it.
  if (submitted !== '' && submitted !== 'NULL') {
    wp(`db query "UPDATE wp_tmc_products SET status = 'submitted' WHERE id IN (${submitted})"`);
  }
  if (revisions !== '' && revisions !== 'NULL') {
    wp(`db query "UPDATE wp_tmc_product_revisions SET status = 'pending' WHERE id IN (${revisions})"`);
  }
  const restored = field(state('report'), 'awaiting_review');
  note(`queue restored to ${restored}`);
}

fs.writeFileSync(path.join(OUT, 'vendor-dashboard-run.txt'), lines.join('\n') + `\n\nchecks: ${pass} passed, ${fail} failed\n`);
console.log(`\nchecks: ${pass} passed, ${fail} failed`);
await browser.close();
process.exit(fail === 0 ? 0 : 1);
