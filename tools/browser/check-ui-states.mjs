/**
 * The interface as a deliverable: the collapsible menu, the five states, and
 * axe on both shells — measured on the plugin installed from the ZIP.
 *
 * Two things this exists to catch that no unit test can:
 *
 * 1. **A menu that eats the page.** «منوی موبایل جمع‌شونده باشد تا ابتدای
 *    صفحه را اشغال نکند» is a measurement, not an opinion: how many pixels of
 *    a 390×844 phone are gone before the first line of content. So the menu's
 *    height is read off the live layout, at both sizes, open and closed.
 * 2. **A state that says nothing.** An empty list is not «no data»; it is one
 *    of five situations with five different next steps. Each state is checked
 *    for a title, an explanation, and — where one exists — an action.
 *
 *   SITE, TMC_VENDOR_USER, TMC_VENDOR_PASS, TMC_USER, TMC_PASS_FILE, TMC_OUT
 */
import { chromium } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';
import fs from 'node:fs';
import path from 'node:path';

const SITE = process.env.SITE || 'http://127.0.0.1:8080';
const VENDOR_USER = process.env.TMC_VENDOR_USER || 'tmcvendor';
const VENDOR_PASS = process.env.TMC_VENDOR_PASS || 'TmcVendor!2026';
const ADMIN_USER = process.env.TMC_USER || 'tmcadmin';
// Same source every other check in this folder uses: the disposable site's
// password lives in a file, never in the repository and never in an argument
// list where `ps` would show it.
const ADMIN_PASS = fs.readFileSync(process.env.TMC_PASS_FILE || '/root/.wp_pass', 'utf8').trim();
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.TMC_OUT || 'docs/evidence/ui-states';
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

const PHONE = { width: 390, height: 844 };
const DESKTOP = { width: 1440, height: 900 };

fs.mkdirSync(OUT, { recursive: true });
fs.mkdirSync(path.join(OUT, 'screens'), { recursive: true });
const results = [];
const record = (scope, check, ok, detail = '') => {
  results.push({ scope, check, ok, detail });
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${scope} :: ${check}${detail ? '  — ' + detail : ''}`);
};

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });

async function signIn(user, pass) {
  const ctx = await browser.newContext({ viewport: DESKTOP, locale: 'fa-IR' });
  const page = await ctx.newPage();
  await page.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForURL(/wp-admin|\/$/, { timeout: 30000 }), page.click('#wp-submit')]);
  const state = await ctx.storageState();
  await ctx.close();
  return state;
}

/**
 * The menu, at one size. Reported as PIXELS rather than as a boolean: «the
 * menu is collapsed» is an implementation detail, and «the menu costs 52 of
 * your 844 pixels» is the thing the owner actually asked about.
 */
async function measureNav(page, selector) {
  return page.evaluate((sel) => {
    const nav = document.querySelector(sel);
    if (!nav) return null;
    const panel = nav.querySelector('.tmc-nav__panel, .tv-nav__panel');
    const summary = nav.querySelector('summary');
    const main = document.querySelector('#tmc-main, #tv-main');
    return {
      open: nav.hasAttribute('open'),
      isWide: nav.classList.contains('is-wide'),
      navHeight: Math.round(nav.getBoundingClientRect().height),
      panelVisible: panel ? panel.getBoundingClientRect().height > 0 : false,
      summaryVisible: summary ? getComputedStyle(summary).display !== 'none' : false,
      summaryHeight: summary ? Math.round(summary.getBoundingClientRect().height) : 0,
      links: nav.querySelectorAll('a').length,
      contentTop: main ? Math.round(main.getBoundingClientRect().top + window.scrollY) : -1,
      viewport: window.innerHeight,
    };
  }, selector);
}

/**
 * axe, scoped to OUR markup — and the whole page recorded beside it.
 *
 * On wp-admin the page also contains other plugins' chrome. Dokan Lite's own
 * menu item renders as a link with no text here, because its built JS is not
 * in its git source and this box cannot fetch it (`docs/phase-6…` §3). That
 * is a real violation and it is not ours to fix: we do not edit another
 * plugin. So the verdict is taken over `include`, and the unscoped scan is
 * written to the same file so the difference is visible rather than hidden.
 */
async function axeOn(page, name, include) {
  const scoped = include
    ? await new AxeBuilder({ page }).include(include).withTags(AXE_TAGS).analyze()
    : await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
  const whole = include
    ? await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze()
    : scoped;
  fs.writeFileSync(path.join(OUT, `axe-${name}.json`), JSON.stringify({
    url: page.url(),
    scope: include || '(whole page)',
    violations: scoped.violations,
    passes: scoped.passes.length,
    whole_page_violations: whole.violations.map((v) => ({
      id: v.id,
      nodes: v.nodes.map((n) => n.html.slice(0, 160)),
    })),
  }, null, 2));
  return scoped;
}

// ---------------------------------------------------------------- vendor ---

const vendorState = await signIn(VENDOR_USER, VENDOR_PASS);

for (const [label, vp] of [['phone', PHONE], ['desktop', DESKTOP]]) {
  const ctx = await browser.newContext({ viewport: vp, locale: 'fa-IR', storageState: vendorState });
  const page = await ctx.newPage();
  await page.goto(`${SITE}/vendor/`, { waitUntil: 'load' });
  await page.waitForSelector('.tv-main');
  await page.waitForTimeout(250);              // the shell script runs on DOMContentLoaded

  const m = await measureNav(page, '.tv-nav');
  const scope = `vendor/${label}`;
  record(scope, 'the menu exists and carries every section', (m?.links ?? 0) > 0, `links=${m?.links}`);

  if (label === 'phone') {
    record(scope, 'the menu is COLLAPSED on a phone', m.open === false, `open=${m.open}`);
    record(scope, '…its toggle is still there', m.summaryVisible === true, `summary=${m.summaryHeight}px`);
    record(scope, '…and it costs under a tenth of the viewport',
      m.navHeight <= Math.round(vp.height * 0.10), `nav=${m.navHeight}px of ${vp.height}px`);
    record(scope, 'content starts in the first screenful', m.contentTop < vp.height,
      `content top=${m.contentTop}px`);

    // Opening it with the keyboard: the native disclosure, not a div.
    await page.focus('.tv-nav__toggle');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(150);
    const opened = await measureNav(page, '.tv-nav');
    record(scope, 'Enter on the toggle opens it', opened.open === true, `open=${opened.open}`);
    record(scope, '…and the links become visible', opened.panelVisible === true, '');
    await page.screenshot({ path: path.join(OUT, 'screens', 'vendor-phone-menu-open.png') });
    await page.keyboard.press('Enter');
    await page.waitForTimeout(150);
    const closed = await measureNav(page, '.tv-nav');
    record(scope, 'Enter again closes it', closed.open === false, `open=${closed.open}`);
  } else {
    record(scope, 'the menu is OPEN on a wide screen', m.open === true, `open=${m.open}`);
    record(scope, '…as a bar, with the toggle out of the way',
      m.isWide === true && m.summaryVisible === false, `is-wide=${m.isWide} summary=${m.summaryVisible}`);
  }

  await page.screenshot({ path: path.join(OUT, 'screens', `vendor-${label}.png`), fullPage: false });
  const axe = await axeOn(page, `vendor-${label}`, '.tv');
  record(scope, 'axe finds no violation', axe.violations.length === 0,
    axe.violations.map((v) => v.id).join(',') || `${axe.passes.length} passes`);
  await ctx.close();
}

// ------------------------------------------------------- vendor states -----

{
  const ctx = await browser.newContext({ viewport: PHONE, locale: 'fa-IR', storageState: vendorState });
  const page = await ctx.newPage();
  // A search that finds nothing: the emptiest empty state there is, and the
  // one that must NOT read as «you have no products».
  await page.goto(`${SITE}/vendor/products/?q=zzz-nothing-matches-this`, { waitUntil: 'load' });
  await page.waitForSelector('.tv-main');
  const state = await page.evaluate(() => {
    const el = document.querySelector('.tv-state');
    if (!el) return null;
    return {
      kind: [...el.classList].find((c) => c.startsWith('tv-state--')) || '',
      title: (el.querySelector('.tv-state__title')?.textContent || '').trim(),
      text: (el.querySelector('.tv-state__text')?.textContent || '').trim(),
      actions: el.querySelectorAll('.tv-state__actions a').length,
    };
  });
  record('vendor/empty', 'a fruitless search renders a STATE, not a bare line', state !== null,
    state ? state.kind : 'missing');
  record('vendor/empty', '…with a title', (state?.title.length ?? 0) > 5, state?.title ?? '');
  record('vendor/empty', '…an explanation', (state?.text.length ?? 0) > 20, `${state?.text.length ?? 0} chars`);
  record('vendor/empty', '…and a way out', (state?.actions ?? 0) >= 1, `actions=${state?.actions}`);
  record('vendor/empty', 'it does NOT claim the shop has no products',
    !(state?.title ?? '').includes('ثبت نکرده'), state?.title ?? '');
  await page.screenshot({ path: path.join(OUT, 'screens', 'vendor-empty-search.png') });
  const axe = await axeOn(page, 'vendor-empty', '.tv');
  record('vendor/empty', 'axe finds no violation', axe.violations.length === 0,
    axe.violations.map((v) => v.id).join(',') || `${axe.passes.length} passes`);
  await ctx.close();
}

// ----------------------------------------------------------------- admin ---

const adminState = await signIn(ADMIN_USER, ADMIN_PASS);

for (const [label, vp] of [['phone', PHONE], ['desktop', DESKTOP]]) {
  const ctx = await browser.newContext({ viewport: vp, locale: 'fa-IR', storageState: adminState });
  const page = await ctx.newPage();
  await page.goto(`${SITE}/wp-admin/admin.php?page=tmc-handover`, { waitUntil: 'load' });
  await page.waitForSelector('.tmc-main');
  await page.waitForTimeout(250);

  const m = await measureNav(page, '.tmc-nav');
  const scope = `admin/${label}`;
  record(scope, 'the section menu exists', (m?.links ?? 0) > 0, `links=${m?.links}`);
  if (label === 'phone') {
    record(scope, 'it is COLLAPSED on a phone', m.open === false, `open=${m.open}`);
    record(scope, '…and costs under a tenth of the viewport',
      m.navHeight <= Math.round(vp.height * 0.10), `nav=${m.navHeight}px of ${vp.height}px`);
  } else {
    record(scope, 'it is OPEN on a wide screen', m.open === true, `open=${m.open}`);
    record(scope, '…with the toggle out of the way', m.summaryVisible === false, '');
  }
  await page.screenshot({ path: path.join(OUT, 'screens', `admin-handover-${label}.png`), fullPage: false });
  const axe = await axeOn(page, `admin-handover-${label}`, '.tmc-admin');
  record(scope, 'axe finds no violation', axe.violations.length === 0,
    axe.violations.map((v) => v.id).join(',') || `${axe.passes.length} passes`);
  await ctx.close();
}

// ------------------------------------------------------------ public -------

for (const [label, vp] of [['phone', PHONE], ['desktop', DESKTOP]]) {
  const ctx = await browser.newContext({ viewport: vp, locale: 'fa-IR' });
  const page = await ctx.newPage();
  await page.goto(`${SITE}/store/${VENDOR_USER}/`, { waitUntil: 'networkidle' });
  const shop = await page.evaluate(() => ({
    cards: document.querySelectorAll('.tmc-store__product').length,
    // The ATTRIBUTE, not `naturalWidth`. Every card below the fold carries
    // `loading="lazy"`, so its natural width is 0 until it scrolls into
    // view — the first version of this check measured how far down the page
    // the test had scrolled and called it a picture quality result.
    withImages: [...document.querySelectorAll('.tmc-store__product img')]
      .filter((i) => Number(i.getAttribute('width') || 0) >= 150
        || i.naturalWidth >= 150).length,
    horizontal: document.documentElement.scrollWidth > window.innerWidth + 1,
  }));
  const scope = `store/${label}`;
  record(scope, 'the shelf has products', shop.cards > 0, `cards=${shop.cards}`);
  record(scope, '…each with a picture big enough to look at',
    shop.withImages === shop.cards, `${shop.withImages}/${shop.cards} ≥150px`);
  record(scope, 'no horizontal page scroll', shop.horizontal === false, '');
  await page.locator('.tmc-store__products').first().scrollIntoViewIfNeeded();
  await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(OUT, 'screens', `store-${label}.png`) });
  const axe = await axeOn(page, `store-${label}`, '.tmc-store__main');
  record(scope, 'axe finds no violation', axe.violations.length === 0,
    axe.violations.map((v) => v.id).join(',') || `${axe.passes.length} passes`);
  await ctx.close();
}

await browser.close();

const failed = results.filter((r) => !r.ok);
fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(
  { at: new Date().toISOString(), site: SITE, checks: results.length, failures: failed.length, results },
  null, 2
));
console.log(`\nchecks=${results.length} failures=${failed.length}`);
process.exit(failed.length === 0 ? 0 : 1);
