/**
 * The manager's product list, on a catalogue big enough to page through.
 *
 * Two questions only a browser can answer, and both were reported by the owner
 * about a real install:
 *
 *   A. the filters. «هفت لینک زیرخط‌دار به‌صورت عمودی نمایش داده می‌شوند و وضعیت
 *      فعال مشخص نیست» — so this measures the geometry (are they on one row?),
 *      the computed text-decoration (are they underlined?), the active chip's
 *      own colours, the zero counter, keyboard focus, and the fact that every
 *      one of them still works with JavaScript switched off;
 *   B. the many-products problem. Paging with a real `LIMIT`, a search that
 *      reaches a product on page five, the row count, and coming back from a
 *      product's own page to the page it was opened from.
 *
 * The expected totals are NOT written here: `tools/catalogue-state.php` prints
 * them and this run reads them. A fixture and a test that each carry their own
 * copy of «۸۱ محصول» are two places for it to go stale, and the one that goes
 * stale quietly is the test.
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/catalogue \
 *   WP="/opt/php81/bin/php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo" \
 *     node tools/browser/catalogue-run.mjs
 */
import { chromium } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/catalogue';
const WP = process.env.WP || '/opt/php81/bin/php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo';
const OWNER = { login: process.env.ADMIN || 'tmcowner', pass: process.env.ADMIN_PASS || 'demo-owner-2026' };
const LIST = `${SITE}/wp-admin/admin.php?page=tmc-product-review`;
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const SCOPE = '.tmc-admin';

// Written ONCE and compared with `includes`: the same Persian word typed twice
// in one file is not always the same bytes (combining hamza, ZWNJ), and that
// has already cost this repository three false failures about pages that were
// perfectly correct.
const T = {
  heading: 'همهٔ محصولات بازارگاه',
  placeholder: 'جست‌وجوی عنوان، SKU یا برند…',
  all: 'همه',
  suspended: 'تعلیق‌شده',
  published: 'منتشرشده',
  review: 'مشاهده و بررسی',
  back: 'بازگشت به فهرست محصولات',
  emptyStatus: 'در این وضعیت محصولی نیست.',
  clear: 'پاک‌کردن فیلترها',
  next: 'صفحهٔ بعد',
  previous: 'صفحهٔ قبل',
  history: 'سابقهٔ تصمیم‌ها',
  proposal: 'نسخهٔ پیشنهادی در انتظار',
  fullCard: 'tmc-review--full',
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
    ? `ok:   ${name.padEnd(62)} ${expected}`
    : `FAIL: ${name.padEnd(62)} expected=${expected} actual=${actual}`;
  lines.push(line);
  console.log(line);
  return ok;
};
const note = (text) => { lines.push(`      ${text}`); console.log(`      ${text}`); };
const wp = (args) => {
  try {
    return execSync(`${WP} ${args}`, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
  } catch (e) {
    // A silent failure here would look like a fixture that reported nothing,
    // and every number below it would then be measured against zero.
    console.error(`wp failed: ${args}\n${e.stderr || ''}`);
    throw e;
  }
};
const fa2en = (s) => s.replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)));
const words = async (page) => (await page.locator('body').innerText()).replace(/\s+/g, ' ');

// ------------------------------------------------------ the fixture, measured
//
// The tool is copied into the WordPress root and called by absolute path:
// `wp eval-file` resolves a relative path against the CURRENT directory, and a
// stale copy in the WordPress root answers a new command with «usage:» — which
// reads like a missing feature rather than an old file (CLAUDE.md, alpha.14).
const DEMO_ROOT = process.env.TMC_DEMO_ROOT || '/home/user/wp-demo';
const STATE = path.join(DEMO_ROOT, 'catalogue-state.php');
fs.copyFileSync(path.resolve('tools/catalogue-state.php'), STATE);
const seeded = wp(`eval-file ${STATE} report`);
note(seeded);
const TOTAL = Number(/total=(\d+)/.exec(seeded)?.[1] ?? 0);
const PAGES = Number(/pages_at_20=(\d+)/.exec(seeded)?.[1] ?? 0);
const SUSPENDED = Number(/suspended=(\d+)/.exec(seeded)?.[1] ?? -1);
const PENDING = Number(/pending_revisions=(\d+)/.exec(seeded)?.[1] ?? -1);
check('the fixture is big enough to page through', TOTAL >= 60 ? 'yes' : `no(${TOTAL})`, 'yes');
check('and it leaves one status empty, for the zero counter', SUSPENDED, 0);

const VERSION = wp('plugin get tecteb-marketplace-core --field=version');
// Read from the plugin header in THIS repository, not written here. The
// literal said `alpha.32` for two rounds and then reported a failure about an
// install that was exactly right.
const EXPECT = process.env.TMC_EXPECT_VERSION
  || (fs.readFileSync(new URL('../../tecteb-marketplace-core.php', import.meta.url), 'utf8')
    .match(/^\s*\*\s*Version:\s*(.+)$/m)?.[1] ?? '').trim();
check('the installed package is the one under test', VERSION, EXPECT);

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
async function session({ width = 1440, height = 1000, javaScriptEnabled = true } = {}) {
  const ctx = await browser.newContext({ viewport: { width, height }, locale: 'fa-IR', javaScriptEnabled });
  const page = await ctx.newPage();
  // A sign-in that quietly failed is the worst shape this script can take: every
  // check after it then measures the login page and reports «the filters are not
  // there». So the form is waited for, the result is asserted, and one retry is
  // allowed before the run is called what it is.
  for (let attempt = 1; attempt <= 2; attempt++) {
    await page.goto(`${SITE}/wp-login.php?loggedout=true`, { waitUntil: 'load' });
    await page.waitForSelector('#user_login', { state: 'visible', timeout: 30000 });
    await page.fill('#user_login', OWNER.login);
    await page.fill('#user_pass', OWNER.pass);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => null),
      page.click('#wp-submit'),
    ]);
    if (/wp-admin/.test(page.url())) {
      break;
    }
  }
  check(`signed in as ${OWNER.login} (js=${javaScriptEnabled})`, /wp-admin/.test(page.url()) ? 'yes' : `no(${page.url()})`, 'yes');
  return { ctx, page };
}
const shot = (page, name) => page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: true });
const rows = (page) => page.locator('.tmc-catalogue__table tbody tr');
const shown = async (page) => {
  const text = await words(page);
  const m = /نمایش ([۰-۹]+) تا ([۰-۹]+) از ([۰-۹]+) محصول/.exec(text);
  return m ? { from: Number(fa2en(m[1])), to: Number(fa2en(m[2])), total: Number(fa2en(m[3])) } : null;
};

const { ctx, page } = await session();

// ========================================================= A. the filter row
await page.goto(LIST, { waitUntil: 'domcontentloaded' });
check('the list renders', (await words(page)).includes(T.heading) ? 'yes' : 'no', 'yes');

const chips = page.locator('.tmc-filters__list > li > .tmc-filter');
check('seven filters', await chips.count(), 7);

const boxes = await chips.evaluateAll((els) => els.map((el) => {
  const r = el.getBoundingClientRect();
  const s = getComputedStyle(el);
  return {
    text: el.querySelector('.tmc-filter__label')?.textContent?.trim() ?? '',
    top: Math.round(r.top), right: Math.round(r.right), height: Math.round(r.height), width: Math.round(r.width),
    decoration: s.textDecorationLine,
    background: s.backgroundColor,
    color: s.color,
    radius: s.borderTopRightRadius,
    current: el.getAttribute('aria-current') || '',
  };
}));
const filterRows = new Set(boxes.map((b) => b.top)).size;
check('they are laid out in rows, not one per line', filterRows < 7 ? `yes(${filterRows})` : `no(${filterRows})`, `yes(${filterRows})`);
check('and on a desktop width they fit on one row', filterRows, 1);
check('none of them is underlined', boxes.every((b) => b.decoration === 'none') ? 'yes' : 'no', 'yes');
check('every one of them is a touch-sized target', boxes.every((b) => b.height >= 40) ? 'yes' : 'no', 'yes');
check('with rounded corners', boxes.every((b) => parseFloat(b.radius) >= 12) ? 'yes' : 'no', 'yes');
// Right to left: «همه» is the rightmost of the seven.
check('the first filter is the rightmost one (RTL)', boxes[0].right === Math.max(...boxes.map((b) => b.right)) ? 'yes' : 'no', 'yes');
check('the order starts with «همه»', boxes[0].text, T.all);
check('and ends with «بایگانی»', boxes[6].text, 'بایگانی');

const active = boxes.filter((b) => b.current === 'page');
check('exactly one filter is marked with aria-current', active.length, 1);
check('the active one is the Tecteb navy', active[0].background, 'rgb(20, 60, 77)');
check('with white text', active[0].color, 'rgb(255, 255, 255)');

const zero = boxes.find((b) => b.text === T.suspended);
check('the empty status is on the row', zero ? 'yes' : 'no', 'yes');
check('its counter reads zero', fa2en(await page.locator('.tmc-filter', { hasText: T.suspended }).locator('.tmc-filter__count').innerText()), '0');
check('it is dimmer than the others', zero.color, 'rgb(79, 101, 112)');
check('and it is still a link', await page.locator(`a.tmc-filter:has-text("${T.suspended}")`).count() >= 1 ? 'yes' : 'no', 'yes');

// The count is its own badge, not part of the label.
const badge = await page.locator('.tmc-filter.is-current .tmc-filter__count').evaluate((el) => {
  const s = getComputedStyle(el);
  return { background: s.backgroundColor, radius: s.borderTopRightRadius, width: Math.round(el.getBoundingClientRect().width) };
});
check('the counter is a separate badge', badge.background, 'rgb(255, 255, 255)');
check('and it is round', parseFloat(badge.radius) >= 12 ? 'yes' : 'no', 'yes');

// The search sits above the filters, with the placeholder the owner asked for.
const geometry = await page.evaluate(() => {
  const box = (sel) => {
    const el = document.querySelector(sel);
    if (!el) { return null; }
    const r = el.getBoundingClientRect();
    return { top: Math.round(r.top), bottom: Math.round(r.bottom), width: Math.round(r.width) };
  };
  // Everything that legitimately stands between the filters and the first row,
  // in document order. The question is not how TALL this region is — the row
  // count control, the sort control, the «نمایش ۱ تا ۲۰ از ۸۱» line and the
  // proposal notice all belong there — it is whether any of it is EMPTY.
  // «فضای خالی بلند فعلی حذف شود» is about emptiness, and a total height cannot
  // tell the two apart.
  const between = ['.tmc-filters', '.tmc-catalogue__scope', '.tmc-catalogue__bar', '.tmc-catalogue__table']
    .map((sel) => ({ sel, ...(box(sel) || {}) }))
    .filter((b) => b.top !== undefined);
  let widest = 0;
  let widestAt = '';
  for (let i = 1; i < between.length; i++) {
    const gap = between[i].top - between[i - 1].bottom;
    if (gap > widest) { widest = gap; widestAt = `${between[i - 1].sel} → ${between[i].sel}`; }
  }
  return {
    search: box('.tmc-catalogue__search'),
    filters: box('.tmc-filters'),
    table: box('.tmc-catalogue__table'),
    widestGap: Math.round(widest),
    widestAt,
    region: between.map((b) => `${b.sel}:${b.bottom - b.top}px`).join(' '),
  };
});
check('the search box is above the filters', geometry.search.bottom <= geometry.filters.top ? 'yes' : 'no', 'yes');
note(`between the filters and the table: ${geometry.region} (total ${geometry.table.top - geometry.filters.bottom}px)`);
check('no empty gap is left between the filters and the table',
  geometry.widestGap <= 24 ? `yes(${geometry.widestGap}px)` : `no(${geometry.widestGap}px at ${geometry.widestAt})`,
  `yes(${geometry.widestGap}px)`);
check('the search input has room to type in', geometry.search.width >= 240 ? 'yes' : 'no', 'yes');
check('the placeholder is the agreed one', await page.locator('#tmc-cat-q').getAttribute('placeholder'), T.placeholder);

// Keyboard focus is visible: focus the first chip and read its outline.
await page.locator('#tmc-cat-q').focus();
const focusRing = await page.locator('a.tmc-filter').first().evaluate((el) => {
  el.focus();
  const s = getComputedStyle(el);
  return { width: s.outlineWidth, style: s.outlineStyle, isFocused: document.activeElement === el };
});
check('a filter can be focused from the keyboard', focusRing.isFocused ? 'yes' : 'no', 'yes');
check('and the focus ring is visible', parseFloat(focusRing.width) >= 3 && focusRing.style !== 'none' ? 'yes' : 'no', 'yes');

// Hover is a real change, not a wish.
const hover = await page.locator('a.tmc-filter.is-empty').first().evaluate(async (el) => {
  const before = getComputedStyle(el).backgroundColor;
  el.classList.add('is-hovered');
  return before;
});
const hoverRule = await page.evaluate(() => {
  const sheets = [...document.styleSheets].filter((s) => (s.href || '').includes('tmc-admin.css'));
  return sheets.some((s) => [...s.cssRules].some((r) => (r.selectorText || '').includes('.tmc-filter:hover')));
});
check('a hover rule exists for the filters', hoverRule ? 'yes' : 'no', 'yes');
note(`the resting background of an empty filter is ${hover}`);

// Nothing of ours reaches wp-admin's own furniture.
const leak = await page.evaluate(() => ({
  outside: document.querySelectorAll('.tmc-filter').length - document.querySelectorAll('.tmc-admin .tmc-filter').length,
  menu: getComputedStyle(document.querySelector('#adminmenu a')).textDecorationLine,
  menuBackground: getComputedStyle(document.querySelector('#adminmenu')).backgroundColor,
}));
check('no filter markup exists outside the plugin shell', leak.outside, 0);
await page.goto(`${SITE}/wp-admin/index.php`, { waitUntil: 'domcontentloaded' });
const baseline = await page.evaluate(() => ({
  menu: getComputedStyle(document.querySelector('#adminmenu a')).textDecorationLine,
  menuBackground: getComputedStyle(document.querySelector('#adminmenu')).backgroundColor,
}));
check('the wp-admin menu looks the same on our page as on the dashboard',
  `${leak.menu}|${leak.menuBackground}`, `${baseline.menu}|${baseline.menuBackground}`);

// ==================================================== B. the many-products list
await page.goto(LIST, { waitUntil: 'domcontentloaded' });
await shot(page, 'desktop-page-1');
check('page one holds twenty rows', await rows(page).count(), 20);
let seen = await shown(page);
check('and says which rows of how many', `${seen.from}-${seen.to}/${seen.total}`, `1-20/${TOTAL}`);
check('the row count control offers three sizes', await page.locator('#tmc-cat-per option').count(), 3);
check('the sort control offers the orders', await page.locator('#tmc-cat-sort option').count(), 3);
check('a summary row carries a thumbnail', await page.locator('.tmc-catalogue__thumb').count() > 0 ? 'yes' : 'no', 'yes');
check('and one action', await page.locator(`.tmc-catalogue__action:has-text("${T.review}")`).count(), 20);
check('no full review card is rendered in the list', (await page.content()).includes(T.fullCard) ? 'yes' : 'no', 'no');
check('and no per-product decision button in the list',
  await page.locator('.tmc-catalogue button[name="decision"]').count(), 0);
note(`the page's only decision button is the vendor-level publish permission: ${await page.locator('button[name="decision"]').count()}`);
if (PENDING > 0) {
  check('a product with an unanswered proposal is marked somewhere in the list',
    (await words(page)).includes('نسخهٔ پیشنهادی') ? 'yes' : 'no', 'yes');
}

// The pager: numbers, and the last page is the remainder.
check('the pager offers page numbers', await page.locator('.tmc-pager__page').count() >= 3 ? 'yes' : 'no', 'yes');
check('the current page is not a link to itself', await page.locator('span.tmc-pager__page.is-current').count(), 1);
await page.click(`.tmc-pager a:has-text("${T.next}")`);
await page.waitForLoadState('domcontentloaded');
seen = await shown(page);
check('the next page starts where the first one stopped', `${seen.from}-${seen.to}`, `21-40`);
check('and it has twenty rows too', await rows(page).count(), 20);

await page.goto(`${LIST}&paged=${PAGES}`, { waitUntil: 'domcontentloaded' });
seen = await shown(page);
check('the last page ends at the total', seen.to, TOTAL);
check('it holds the remainder', await rows(page).count(), TOTAL - (PAGES - 1) * 20);
check('and offers no next page', await page.locator(`.tmc-pager a:has-text("${T.next}")`).count(), 0);

// Every page is a different set of rows: measured, because a tie in
// `updated_at` without a tiebreaker shows one product twice and hides another.
const idsOn = async (n) => {
  await page.goto(`${LIST}&paged=${n}`, { waitUntil: 'domcontentloaded' });
  return new Set(await page.locator('.tmc-catalogue__link').evaluateAll(
    (els) => els.map((el) => new URL(el.href).searchParams.get('product'))
  ));
};
const allIds = new Set();
let drawn = 0;
for (let n = 1; n <= PAGES; n++) {
  const ids = await idsOn(n);
  drawn += ids.size;
  for (const id of ids) { allIds.add(id); }
}
check('every page together draws the whole catalogue', drawn, TOTAL);
check('and no product appears on two pages', allIds.size, TOTAL);

// The row count is a real query limit.
await page.goto(`${LIST}&per_page=50`, { waitUntil: 'domcontentloaded' });
check('fifty a page reads fifty rows', await rows(page).count(), 50);
await page.goto(`${LIST}&per_page=100`, { waitUntil: 'domcontentloaded' });
// Written against `TOTAL` rather than against «all of them»: the catalogue on
// this install is not fixed at the size it had when this line was written, and
// the first run with more than a hundred products made both of these report a
// failure about paging that was working correctly. The claim is «the row count
// is a real LIMIT», which is `min(total, 100)` at any catalogue size.
check('a hundred a page reads a hundred rows, or the lot',
  await rows(page).count(), Math.min(TOTAL, 100));
check('and the pager appears only when a hundred is not the lot',
  await page.locator('.tmc-pager').count(), TOTAL > 100 ? 1 : 0);
const hiddenRows = await page.evaluate(() => [...document.querySelectorAll('.tmc-catalogue__table tbody tr')]
  .filter((tr) => getComputedStyle(tr).display === 'none').length);
check('nothing is fetched and hidden with CSS', hiddenRows, 0);

// A search reaches a product that is not on page one.
// Read from the fixture's own report, taken before anything was measured.
const OFF_PAGE = /off_page_one_sku=(\S+)/.exec(seeded)?.[1] || '';
check('the fixture named a landmark to search for', OFF_PAGE === '' ? 'no' : 'yes', 'yes');
await page.goto(LIST, { waitUntil: 'domcontentloaded' });
const firstPageText = await words(page);
check('the landmark product is not on page one', firstPageText.includes(OFF_PAGE) ? 'no' : 'yes', 'yes');
await page.fill('#tmc-cat-q', OFF_PAGE);
await page.click('.tmc-catalogue__search button[type="submit"]');
await page.waitForLoadState('domcontentloaded');
check('the search finds it anyway', (await words(page)).includes(OFF_PAGE) ? 'yes' : 'no', 'yes');
seen = await shown(page);
check('and says it is the only result', `${seen.from}-${seen.to}/${seen.total}`, '1-1/1');
check('the search term survives in the box', await page.locator('#tmc-cat-q').inputValue(), OFF_PAGE);

// Search and filter together, and then the status changes underneath it.
await page.goto(`${LIST}&q=CAT-&status=published`, { waitUntil: 'domcontentloaded' });
await shot(page, 'desktop-search-and-filter');
const withBoth = await shown(page);
note(`search «CAT-» inside «منتشرشده»: rows ${withBoth?.from}-${withBoth?.to} of ${withBoth?.total}`);
check('the active filter is the published one',
  await page.locator('.tmc-filter.is-current .tmc-filter__label').innerText(), T.published);
await page.click(`a.tmc-filter:has-text("${T.suspended}")`);
await page.waitForLoadState('domcontentloaded');
const url = new URL(page.url());
check('changing the status keeps the search', url.searchParams.get('q'), 'CAT-');
check('and goes back to page one', url.searchParams.get('paged'), null);
check('an empty status says so', (await words(page)).includes(T.emptyStatus) || (await words(page)).includes('چیزی با این عبارت پیدا نشد.') ? 'yes' : 'no', 'yes');
check('and offers to clear the filters', await page.locator(`a:has-text("${T.clear}")`).count() >= 1 ? 'yes' : 'no', 'yes');
await shot(page, 'desktop-empty-with-filters');

// A zero counter is still a place to stand: press it with nothing behind it.
await page.goto(`${LIST}&status=suspended`, { waitUntil: 'domcontentloaded' });
check('the zero filter opens its own empty list', (await words(page)).includes(T.emptyStatus) ? 'yes' : 'no', 'yes');
await shot(page, 'desktop-zero-counter');

// ======================================= the way to a product, and the way back
// The page has to exist under that filter: «منتشرشده» holds fewer rows than the
// whole catalogue, so its last page is not the catalogue's last page. Read, not
// assumed.
const PUBLISHED = Number(/ published=(\d+)/.exec(seeded)?.[1] ?? 0);
const DETAIL_PAGE = Math.max(1, Math.min(2, Math.ceil(PUBLISHED / 20)));
await page.goto(`${LIST}&status=published&paged=${DETAIL_PAGE}`, { waitUntil: 'domcontentloaded' });
const fromPage = await shown(page);
await page.locator(`.tmc-catalogue__action:has-text("${T.review}")`).first().click();
await page.waitForLoadState('domcontentloaded');
await shot(page, 'desktop-product-detail');
const detail = new URL(page.url());
check('the product opens on its own page', detail.searchParams.get('product') !== null ? 'yes' : 'no', 'yes');
check('the list is not underneath it', (await words(page)).includes(T.heading) ? 'no' : 'yes', 'yes');
check('the review card is here instead', (await page.content()).includes(T.fullCard) ? 'yes' : 'no', 'yes');
check('with the decision history', (await words(page)).includes(T.history) ? 'yes' : 'no', 'yes');
const backHref = await page.locator(`a:has-text("${T.back}")`).first().getAttribute('href');
const back = new URL(backHref, SITE);
check('the way back carries the filter', back.searchParams.get('status'), 'published');
check('and the page number', back.searchParams.get('paged'), String(DETAIL_PAGE));
await page.locator(`a:has-text("${T.back}")`).first().click();
await page.waitForLoadState('domcontentloaded');
const returned = await shown(page);
check('pressing it lands on the same page of the same filter',
  `${returned.from}-${returned.to}/${returned.total}`, `${fromPage.from}-${fromPage.to}/${fromPage.total}`);
check('with the same filter still active',
  await page.locator('.tmc-filter.is-current .tmc-filter__label').innerText(), T.published);

// ================================================================== no script
const off = await session({ javaScriptEnabled: false });
await off.page.goto(`${LIST}&status=published`, { waitUntil: 'domcontentloaded' });
check('with JavaScript off, the list still renders', (await words(off.page)).includes(T.heading) ? 'yes' : 'no', 'yes');
check('and the rows are there', await off.page.locator('.tmc-catalogue__table tbody tr').count() > 0 ? 'yes' : 'no', 'yes');
await off.page.locator(`a.tmc-filter:has-text("${T.suspended}")`).click();
await off.page.waitForLoadState('domcontentloaded');
check('a filter still navigates', new URL(off.page.url()).searchParams.get('status'), 'suspended');
await off.page.goto(`${LIST}&paged=2`, { waitUntil: 'domcontentloaded' });
await off.page.locator(`.tmc-pager a:has-text("${T.next}")`).click();
await off.page.waitForLoadState('domcontentloaded');
check('and so does the pager', new URL(off.page.url()).searchParams.get('paged'), '3');
await shot(off.page, 'no-script-list');
await off.ctx.close();

// ==================================================================== mobile
const small = await session({ width: 390, height: 844 });
await small.page.goto(LIST, { waitUntil: 'domcontentloaded' });
await shot(small.page, 'mobile-page-1');
const mobile = await small.page.evaluate(() => {
  const row = document.querySelector('.tmc-catalogue__table tbody tr');
  const action = row?.querySelector('.tmc-catalogue__action');
  const table = document.querySelector('.tmc-catalogue__table');
  const chip = document.querySelector('.tmc-filter');
  return {
    rowIsCard: row ? getComputedStyle(row).display === 'block' : false,
    rowBorder: row ? parseFloat(getComputedStyle(row).borderTopWidth) : 0,
    actionWidth: action ? Math.round(action.getBoundingClientRect().width) : 0,
    rowWidth: row ? Math.round(row.getBoundingClientRect().width) : 0,
    labelled: table ? getComputedStyle(table.querySelector('td'), '::before').content !== 'none' : false,
    chipHeight: chip ? Math.round(chip.getBoundingClientRect().height) : 0,
    pageScrollX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
  };
});
check('on a phone each product is a card', mobile.rowIsCard ? 'yes' : 'no', 'yes');
check('with a border of its own', mobile.rowBorder >= 1 ? 'yes' : 'no', 'yes');
check('and the action reaches across the card',
  mobile.actionWidth > mobile.rowWidth * 0.6 ? `yes(${mobile.actionWidth}/${mobile.rowWidth})` : `no(${mobile.actionWidth}/${mobile.rowWidth})`,
  `yes(${mobile.actionWidth}/${mobile.rowWidth})`);
check('the filters are still touch-sized', mobile.chipHeight >= 40 ? 'yes' : 'no', 'yes');
check('and the page does not scroll sideways', mobile.pageScrollX, 0);
const mobileRows = new Set(await small.page.locator('.tmc-filters__list > li > .tmc-filter').evaluateAll(
  (els) => els.map((el) => Math.round(el.getBoundingClientRect().top))
)).size;
check('on a phone the filters wrap into rows instead of one per line',
  mobileRows < 7 ? `yes(${mobileRows} rows)` : `no(${mobileRows} rows)`, `yes(${mobileRows} rows)`);
await small.page.goto(`${LIST}&status=published&paged=2`, { waitUntil: 'domcontentloaded' });
await shot(small.page, 'mobile-active-filter');
await small.page.locator(`.tmc-catalogue__action:has-text("${T.review}")`).first().click();
await small.page.waitForLoadState('domcontentloaded');
await shot(small.page, 'mobile-product-detail');
check('and a product page is reachable from a phone',
  new URL(small.page.url()).searchParams.get('product') !== null ? 'yes' : 'no', 'yes');
await small.ctx.close();

// ======================================================================= axe
await page.goto(LIST, { waitUntil: 'domcontentloaded' });
const axeList = await new AxeBuilder({ page }).withTags(AXE_TAGS).include(SCOPE).analyze();
check('axe over the list finds nothing', axeList.violations.map((v) => `${v.id}(${v.nodes.length})`).join(', ') || 'none', 'none');
await page.goto(`${LIST}&status=suspended`, { waitUntil: 'domcontentloaded' });
const axeEmpty = await new AxeBuilder({ page }).withTags(AXE_TAGS).include(SCOPE).analyze();
check('axe over the empty list finds nothing', axeEmpty.violations.map((v) => `${v.id}(${v.nodes.length})`).join(', ') || 'none', 'none');
await page.goto(`${LIST}&paged=1`, { waitUntil: 'domcontentloaded' });
await page.locator(`.tmc-catalogue__action:has-text("${T.review}")`).first().click();
await page.waitForLoadState('domcontentloaded');
const axeDetail = await new AxeBuilder({ page }).withTags(AXE_TAGS).include(SCOPE).analyze();
check('axe over a product page finds nothing', axeDetail.violations.map((v) => `${v.id}(${v.nodes.length})`).join(', ') || 'none', 'none');

await ctx.close();
await browser.close();

lines.push('');
lines.push(`pass=${pass} fail=${fail}`);
console.log(`\npass=${pass} fail=${fail}`);
fs.writeFileSync(path.join(OUT, 'catalogue-run.txt'), lines.join('\n') + '\n');
fs.writeFileSync(path.join(OUT, 'catalogue-run.json'), JSON.stringify({
  site: SITE,
  version: VERSION,
  scope: 'The manager product list on a real wp-admin, signed in as an administrator, with a catalogue seeded by tools/catalogue-state.php.',
  fixture: seeded,
  total: TOTAL,
  pages: PAGES,
  pass,
  fail,
}, null, 2) + '\n');
process.exit(fail === 0 ? 0 : 1);
