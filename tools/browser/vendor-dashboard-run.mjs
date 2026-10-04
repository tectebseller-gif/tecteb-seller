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
 *   3. «ورود به پنل فروشنده» from /my-account/, for a vendor, an applicant and a
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
// The version under test is read from the plugin header in THIS repository, not
// written here. A literal breaks the first round that moves the number and then
// reports a failure about a site that is entirely correct — `alpha.33`'s own
// lesson, which this file had not learned.
const HEADER = fs.readFileSync(new URL('../../tecteb-marketplace-core.php', import.meta.url), 'utf8');
const EXPECT = process.env.TMC_EXPECT_VERSION
  || (HEADER.match(/^\s*\*\s*Version:\s*(.+)$/m)?.[1] ?? '').trim();

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
  vendorDashboardLink: 'ورود به پنل فروشنده',
  applicantLink: 'درخواست فروشندگی من',
  csvSummary: 'ورود و خروج گروهی محصولات',
  csvEditable: 'ستون‌های قابل ویرایش:',
  csvReadOnly: 'فقط خواندنی:',
  csvPreview: 'پیش‌نمایش ورود',
  underReview: 'در نوبت بررسی است',
  fileClosed: 'تأیید شده است',
  startApplication: 'شروع درخواست فروشندگی',
  noApplicationYet: 'هنوز درخواستی ثبت نکرده‌اید',
  // The SHOP's half of the suspension sentence, on its own. The whole sentence
  // «این فروشگاه تعلیق شده است…» is a SUBSTRING of the member's own sentence
  // «دسترسی شما در این فروشگاه تعلیق شده است…», so a needle cut at the wrong
  // word answers about either — which is the collision «محصولات شما» caused in
  // `alpha.33`.
  shopSuspended: 'دسترسی همکاران آن هم موقتاً بسته است',
  myAccessSuspended: 'دسترسی شما در این فروشگاه تعلیق شده است',
  myAccessList: 'دسترسی‌های شما در این فروشگاه',
  accessClosed: 'فعلاً بسته',
  nothingLost: 'چیزی از دست نرفته است',
  managerNote: 'تعلیق آزمایشی فروشگاه، برای سنجش صفحهٔ پرسنل.',
  noteHeading: 'یادداشت مدیر بازارگاه',
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
  // The login is CONFIRMED, once, here — and a second attempt is made before
  // giving up. Without this, a login that did not take made every «is not on
  // the page» assertion below it pass about a login form: one run reported ten
  // failures in the menu-bubble section while the bubble was there and the
  // database held the right number, because that session was never signed in.
  // `check()` is not used, so this cannot be confused for a finding about the
  // plugin: a session that will not open is a broken measurement.
  for (let attempt = 0; attempt < 2; attempt++) {
    if ((await ctx.cookies()).some((c) => c.name.startsWith('wordpress_logged_in_'))) {
      return { ctx, page };
    }
    await page.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#user_login', { timeout: 30000 });
    await page.fill('#user_login', who.login);
    await page.fill('#user_pass', who.pass);
    await Promise.all([
      page.waitForNavigation({ timeout: 30000 }).catch(() => null),
      page.click('#wp-submit'),
    ]);
  }
  if (!(await ctx.cookies()).some((c) => c.name.startsWith('wordpress_logged_in_'))) {
    throw new Error(`could not sign in as ${who.login} — every later assertion would be about a login page`);
  }
  return { ctx, page };
}
const textOf = async (page) => (await page.locator('body').innerText()).replace(/ /g, ' ');
// The badge's numbers come from `review-badge-state.php`, not from this file's
// own state tool: the bubble is «how many has THIS manager not seen», and that
// tool is the one that names the manager by login and answers per person.
// Item 5 compared it against `awaiting_review` until `alpha.37`, which is a
// different number and was only ever equal while nobody had marks.
const badgeState = (cmd) => {
  execSync('cp tools/review-badge-state.php /home/user/wp-demo/', { stdio: 'ignore' });
  return wp(`eval-file /home/user/wp-demo/review-badge-state.php ${cmd}`);
};

check('the installed package is the one under test', wp('plugin get tecteb-marketplace-core --field=version'), EXPECT);
// Read from `SchemaVersion::TARGET`, never written here: a literal breaks the
// first round that moves the number and then reports a failure about a site
// that is entirely correct (`alpha.33`'s lesson — which the version line above
// had learned and this one had not).
const SCHEMA = (fs.readFileSync(
  new URL('../../src/Core/Migration/SchemaVersion.php', import.meta.url),
  'utf8',
).match(/TARGET\s*=\s*(\d+)/)?.[1] ?? '').trim();
check('and its schema is the one this round ships', wp('option get tmc_schema_version'), SCHEMA);

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
  check('3-1 a vendor finds «ورود به پنل فروشنده» on my-account', text.includes(T.vendorDashboardLink), true);
  const href = await page.locator('a', { hasText: T.vendorDashboardLink }).first().getAttribute('href');
  note(`my-account link: ${href}`);
  check('3-2 and it goes to the panel, not to a 404',
    await page.request.get(href).then((r) => r.status()), 200);
  // «دکمه در هیچ صفحه‌ای تکرار نشود» — ONE button. The menu row beside it is a
  // different thing in a different place (`alpha.37`): counting the words
  // instead would have failed about a page that is right, and in `alpha.36` the
  // guard that kept that count at one was what removed the button altogether.
  const buttons = await page.locator('p.tmc-account-vendor a.button').count();
  check('3-3 the dashboard button is there, exactly once', buttons, 1);
  const navRows = await page.locator('nav.woocommerce-MyAccount-navigation a', { hasText: T.vendorDashboardLink }).count();
  note(`my-account: ${buttons} button(s), ${navRows} navigation row(s)`);
  check('3-3b and the navigation row did not remove it', navRows >= 1 && buttons === 1, true);
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
  check('3-8 and no block of ours at all', await page.locator('p.tmc-account-vendor').count(), 0);
  await ctx.close();
}
{
  // «همکار فروشگاه نیز باید مسیر ورود مناسب ... داشته باشد». Staff hold no
  // application of their own, so `alpha.36` gave them nothing once their shop
  // was suspended — and nothing at all if they were still invited.
  const { ctx, page } = await session(STAFF);
  await page.goto(`${SITE}/my-account/`, { waitUntil: 'domcontentloaded' });
  check('3-9 a shop employee gets the button too',
    await page.locator('p.tmc-account-vendor a.button', { hasText: T.vendorDashboardLink }).count(), 1);
  const staffHref = await page.locator('p.tmc-account-vendor a.button').first().getAttribute('href');
  check('3-10 and it opens the panel for them',
    await page.request.get(staffHref).then((r) => r.status()), 200);
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
  // `alpha.34`: the search and the add button share the TOP row, and the CSV
  // control is below the products. Measured as geometry rather than document
  // order, because the claim the owner made is about what moves on the screen.
  const geometry = await page.evaluate(() => {
    const search = document.querySelector('#f-product-search');
    const add = document.querySelector('.tv-tools__action a.tv-btn');
    const csv = document.querySelector('.tv-bulk-csv__summary');
    const tabs = document.querySelector('.tv-tabs');
    const list = document.querySelector('.tv-products');
    const pager = document.querySelector('.tv-pager') || document.querySelector('.tv-hint');
    if (!search || !add || !csv || !tabs || !list) { return null; }
    const box = (el) => el.getBoundingClientRect();
    const s = box(search), a = box(add), c = box(csv), t = box(tabs), l = box(list);
    return {
      // The two overlap vertically — the button's box sits within the search
      // card's height, whatever the exact alignment. `Math.abs(top - top)` was
      // the wrong test: the search is a bordered card with a label above the
      // input, so its top is 20-odd pixels above the button's however
      // correctly they share the row.
      searchAndAddShareARow: a.top < s.bottom && s.top < a.bottom && a.top < t.top,
      searchAboveFilters: s.bottom <= t.top + 1,
      csvBelowProducts: c.top >= l.bottom - 1,
      csvIsLast: pager ? c.top >= box(pager).top : true,
      searchTop: Math.round(s.top),
      csvTop: Math.round(c.top),
      listBottom: Math.round(l.bottom),
    };
  });
  check('4-3 the search and «افزودن محصول» are the top row', geometry && geometry.searchAndAddShareARow, true);
  check('4-4 above the status filters', geometry && geometry.searchAboveFilters, true);
  check('4-4b and the CSV control is below the products', geometry && geometry.csvBelowProducts, true);
  note(`layout: ${JSON.stringify(geometry)}`);

  // The reason it moved: opening it must not push the search or the products
  // down. Measured — the two positions before and after the click.
  // DOCUMENT positions, not viewport ones. The first version read
  // `getBoundingClientRect().top` on both sides, and clicking the summary
  // scrolls it into view — so every number moved by the scroll amount and the
  // check failed about a layout that had not shifted at all (365 → -3191).
  // `window.scrollY` takes the scroll back out.
  const positions = () => page.evaluate(() => {
    const at = (sel) => Math.round(document.querySelector(sel).getBoundingClientRect().top + window.scrollY);
    return { search: at('#f-product-search'), firstProduct: at('.tv-products li') };
  });
  const before = await positions();
  await page.locator('.tv-bulk-csv__summary').click();
  const after = await positions();
  check('4-4c opening it does not move the search', after.search, before.search);
  check('4-4d nor the first product', after.firstProduct, before.firstProduct);
  note(`before/after open: ${JSON.stringify({ before, after })}`);
  await page.locator('.tv-bulk-csv__summary').click();     // back to closed

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

  // The marks this manager already holds are cleared FIRST, so there is
  // something unseen to measure. A previous run of this very file reads the
  // whole list, and «the badge is drawn» then fails about a manager who has
  // genuinely read everything — a fixture that spends state has to set it
  // (`alpha.33`). Only the marks go; the queue is not touched.
  note(badgeState('forget ana'));

  await page.goto(`${SITE}/wp-admin/`, { waitUntil: 'domcontentloaded' });
  // Asserted BEFORE anything about the bubble, for the same reason the staff
  // section does it: «the badge is not drawn» is also true of a login page, and
  // «an empty queue draws nothing» passes there without measuring anything.
  check('5-0 this is wp-admin, with a menu to hang a badge on',
    await page.locator('#adminmenu').count(), 1);
  const first = await bubbleOf();
  note(`bubble: ${JSON.stringify(first)}`);
  // THIS manager's unseen count — not the size of the queue.
  //
  // Until `alpha.37` this section compared the bubble against
  // `awaiting_review`, which was the right question in `alpha.33` and the wrong
  // one from `alpha.36`, when the badge became «چند مورد را ندیده‌ام». It kept
  // passing because the two numbers are equal on an install with no marks — and
  // the old `5-6` («opening the queue does not clear it») passed only BECAUSE
  // of the defect this round fixes: the bubble was built before the view was
  // recorded, so it printed the number from before the view.
  const unseen = () => field(badgeState('report'), 'unseen_ana');
  const startUnseen = unseen();
  check('5-1 the count is drawn', first.present, true);
  check('5-2 and matches what the database says this manager has not seen',
    fromPersian((first.value || '').trim()), startUnseen);
  check('5-3 it is red, not core\'s blue', first.bg || '-', 'rgb(214, 54, 56)');
  check('5-4 with a sentence for a screen reader', (first.sr || '').includes('در انتظار بررسی'), true);
  check('5-5 on the top-level item and the submenu', (first.count || 0) >= 2, true);

  // ---- «عدد اعلان باید در همان صفحه به‌روز شود» --------------------------
  //
  // Read on the review page ITSELF, with no second navigation. This is the
  // whole of defect 1, and the reason the owner said a run that navigates
  // elsewhere to pass is not evidence.
  // The QUEUE, in one page — not the default list.
  //
  // The default list is every product sorted by last change, and on this
  // install the twenty most recent are published ones: the page showed nothing
  // waiting, `unseen 21 -> 21` was correct, and «the list lowered the count»
  // failed about a page that had nothing to lower. Measured, and the same trap
  // the probe fell into. A check whose precondition does not hold refutes
  // nothing — so the page this opens is the one the claim is about.
  await page.goto(
    `${SITE}/wp-admin/admin.php?page=tmc-product-review&status=submitted&per_page=100`,
    { waitUntil: 'domcontentloaded' },
  );
  const onList = await bubbleOf();
  const afterList = unseen();
  note(`unseen ${startUnseen} -> ${afterList} (read on the list itself)`);
  check('5-6 the list lowered the count', Number(afterList) < Number(startUnseen), true);
  check('5-6b and the badge on THAT page already says the new number',
    onList.present ? fromPersian((onList.value || '').trim()) : '0', afterList);
  check('5-7 and it is still red while there is one to draw',
    onList.present ? (onList.bg || '-') : 'none', afterList === '0' ? 'none' : 'rgb(214, 54, 56)');

  // A different page agrees with the database too — the default list, whose
  // rows may or may not be waiting. The claim here is only «the number on
  // whatever page is open is the database's», which is the part that holds
  // regardless of what the page happened to show.
  await page.goto(`${SITE}/wp-admin/admin.php?page=tmc-product-review`, { waitUntil: 'domcontentloaded' });
  const filtered = await bubbleOf();
  check('5-8 another page agrees with the database, on that page',
    filtered.present ? fromPersian((filtered.value || '').trim()) : '0', unseen());
  note(`default list: unseen now ${unseen()}`);

  // Collapsed: core puts the bubble in the submenu head, which is what shows.
  // Asked only while there IS one to find — «it is not there» would otherwise
  // be a pass about an empty menu.
  if (Number(unseen()) > 0) {
    await page.click('#collapse-menu').catch(() => null);
    await page.waitForTimeout(300);
    const collapsed = await page.evaluate(() => {
      const els = [...document.querySelectorAll('#adminmenu .tmc-count')];
      return els.filter((el) => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; }).length;
    });
    check('5-9 the collapsed menu still shows it', collapsed >= 1, true);
    await page.click('#collapse-menu').catch(() => null);
    await page.waitForTimeout(200);
  } else {
    note('5-9 skipped: nothing unseen to draw while collapsed');
  }

  // ---- a resubmission is exactly one new notification ---------------------
  //
  // The product is taken from the marks this manager actually holds. Picking
  // one at random can land on something they never saw, and then «it became
  // unseen again» is true of something that was never seen (`alpha.36`).
  //
  // And it has to be a product that is ALSO still waiting: `submit` on a
  // marked product that has since left the queue puts it BACK, which grows the
  // queue — measured, and it made «the queue is the size it was» fail about a
  // fixture rather than about the code.
  const report = badgeState('report');
  const awaitingIds = report.split('\n')
    .map((l) => (l.match(/^awaiting id=(\d+)/) || [])[1])
    .filter(Boolean);
  const marked = badgeState('marks ana').split('\n')
    .map((l) => (l.match(/^mark user=\d+ product=(\d+)/) || [])[1])
    .filter(Boolean)
    .filter((id) => awaitingIds.includes(id));
  const queueBefore = field(state('report'), 'awaiting_review');
  const seenNow = unseen();
  if (marked.length > 0) {
    const target = marked[0];
    badgeState(`submit ${target}`);
    const resubmitted = unseen();
    check('5-10 a resubmission adds exactly one notification',
      Number(resubmitted) - Number(seenNow), 1);
    await page.goto(`${SITE}/wp-admin/`, { waitUntil: 'domcontentloaded' });
    const after = await bubbleOf();
    check('5-11 and the bubble follows it',
      after.present ? fromPersian((after.value || '').trim()) : '0', resubmitted);
    check('5-12 while the review queue is the size it was',
      field(state('report'), 'awaiting_review'), queueBefore);
    note(`resubmitted ${target}: unseen ${seenNow} -> ${resubmitted}, queue ${queueBefore}`);
  } else {
    check('5-10 a resubmission adds exactly one notification', 'no mark to resubmit', 'a mark');
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
  check('5-13 an empty queue draws nothing at all',
    await page.locator('#adminmenu .tmc-count').count(), 0);
  check('5-14 and prints no style for it',
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

// ================================================================= item 2 (alpha.34)
//
// The colleague of a SUSPENDED shop. `alpha.33` fixed the approved shop's screen
// for staff and left this one: `storeFor()` answers `null` for a suspended shop,
// the route read that as «belongs to no shop», and the only page for somebody
// who belongs to no shop invites them to register — so a suspended shop's
// employee was offered a vendor application for the shop they work in.
{
  state('staff view');
  note(`shop: ${state('shop suspend')}`);
  const { ctx, page } = await session(STAFF);
  await page.goto(`${SITE}/vendor/`, { waitUntil: 'domcontentloaded' });
  const text = await textOf(page);

  // Asserted FIRST: every «is not there» below is also true of a login page.
  check('6-1 the staff member reaches a vendor screen', text.includes('پیشخوان فروشنده'), true);
  check('6-2 and is NOT invited to register', text.includes(T.startApplication), false);
  check('6-3 nor told they have never applied', text.includes(T.noApplicationYet), false);
  check('6-4 nor shown the application status card', text.includes(T.oldStatusCard), false);

  check('6-5 the shop they work in is named', text.includes('تجهیزات پزشکی نمونه'), true);
  check('6-6 the suspension is stated', text.includes(T.shopSuspended), true);
  check('6-7 and that nothing is lost', text.includes(T.nothingLost), true);

  // Nothing of the owner's.
  check('6-8 the manager\'s note is not shown', text.includes(T.managerNote), false);
  check('6-9 nor its heading', text.includes(T.noteHeading), false);
  check('6-10 nor the shop file', text.includes(T.paperwork), false);
  check('6-11 nor any figures', await page.locator('a.tv-number').count(), 0);
  check('6-12 nor «افزودن محصول»', text.includes(T.addProduct), false);

  // Their own access, stated and stated as closed.
  check('6-13 their own access is listed', text.includes(T.myAccessList), true);
  check('6-14 and marked closed', text.includes(T.accessClosed), true);
  const areas = ['محصول', 'موجودی', 'سفارش', 'گزارش', 'مالی'];
  check('6-15 every area appears', areas.every((a) => text.includes(a)), true);

  // Operational access is still blocked — asserted by going at the page
  // directly rather than by the absence of a link.
  const products = await page.goto(`${SITE}/vendor/products/`, { waitUntil: 'domcontentloaded' });
  const afterProducts = page.url();
  check('6-16 the product list is refused', afterProducts.includes('tmc_notice=not_a_vendor')
    || !(await textOf(page)).includes(T.csvSummary), true);
  note(`product list landed on: ${afterProducts} (${products?.status()})`);
  await page.screenshot({ path: path.join(OUT, 'staff-suspended-shop.png'), fullPage: true });
  await ctx.close();

  // Reinstatement: one field, and the working screen comes back.
  note(`shop: ${state('shop reinstate')}`);
  const back = await session(STAFF);
  await back.page.goto(`${SITE}/vendor/`, { waitUntil: 'domcontentloaded' });
  const t3 = await textOf(back.page);
  check('6-17 after reinstatement the suspension line is gone', t3.includes(T.shopSuspended), false);
  check('6-18 and still no invitation to register', t3.includes(T.startApplication), false);
  check('6-19 the counters are back', await back.page.locator('a.tv-number').count(), 4);
  check('6-20 and the access list stands down', t3.includes(T.myAccessList), false);
  await back.ctx.close();
}

fs.writeFileSync(path.join(OUT, 'vendor-dashboard-run.txt'), lines.join('\n') + `\n\nchecks: ${pass} passed, ${fail} failed\n`);
console.log(`\nchecks: ${pass} passed, ${fail} failed`);
await browser.close();
process.exit(fail === 0 ? 0 : 1);
