/**
 * The three screens this delivery added, measured the way every screen here is.
 *
 * Two of them live on the STOREFRONT rather than in the vendor area, and that
 * changes what may honestly be asserted. A product page and «حساب من» are the
 * THEME's pages: a colour-contrast failure in the theme's footer is not this
 * plugin's to fix and counting it here would either force us to patch somebody
 * else's markup or make the suite permanently red.
 *
 * So axe runs twice on those pages. Scoped to our own subtree it is a PASS/FAIL
 * — that markup is ours and it must be clean. Over the whole page it is
 * RECORDED, with the theme's own violations named, so a real regression of ours
 * is never hidden behind "the theme was already red". The vendor page is ours
 * end to end, so there the whole page is the assertion.
 */
import { chromium } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';
import fs from 'node:fs';
import path from 'node:path';

const SITE = process.env.SITE || 'http://127.0.0.1:8080';
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.TMC_OUT || 'docs/evidence/wholesale-ui/a11y';
const WIDTHS = [320, 375, 768, 1024, 1440];
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

const VENDOR = { user: process.env.TMC_VENDOR_USER || 'tmcvendor', pass: process.env.TMC_VENDOR_PASS || 'TmcVendor!2026' };
const BUYER = { user: process.env.TMC_BUYER_USER || 'tmcfreshbuyer', pass: process.env.TMC_BUYER_PASS || 'TmcWholesale!2026' };
const PRODUCT_URL = process.env.TMC_PRODUCT_URL;
if (!PRODUCT_URL) {
  console.error('TMC_PRODUCT_URL is required: the product whose ladder is being measured');
  process.exit(2);
}

const PAGES = [
  {
    name: 'vendor-support-tiers',
    url: `${SITE}/vendor/support/`,
    as: VENDOR,
    ready: '.tv-main',
    ours: null,              // the whole page is ours
  },
  {
    name: 'account-wholesale',
    url: `${SITE}/my-account/`,
    as: BUYER,
    ready: '.tmc-wholesale',
    ours: '.tmc-wholesale',
  },
  {
    name: 'product-ladder',
    url: PRODUCT_URL,
    as: BUYER,
    ready: '.tmc-wholesale-ladder, .tmc-wholesale-hint',
    ours: '.tmc-wholesale-ladder, .tmc-wholesale-hint',
  },
];

fs.mkdirSync(OUT, { recursive: true });
const results = [];
const failures = [];
const notes = [];
const record = (page, scope, check, ok, detail = '') => {
  results.push({ page, scope, check, ok, detail });
  if (!ok) failures.push(`${page} @ ${scope}: ${check} — ${detail}`);
};

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });

const states = new Map();
for (const who of [VENDOR, BUYER]) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR' });
  const page = await ctx.newPage();
  await page.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', who.user);
  await page.fill('#user_pass', who.pass);
  await Promise.all([
    page.waitForURL(/wp-admin|my-account|\/$/, { timeout: 30000 }),
    page.click('#wp-submit'),
  ]);
  states.set(who.user, await ctx.storageState());
  await ctx.close();
}

for (const p of PAGES) {
  for (const width of WIDTHS) {
    const ctx = await browser.newContext({
      viewport: { width, height: 900 },
      locale: 'fa-IR',
      storageState: states.get(p.as.user),
    });
    const page = await ctx.newPage();
    const jsErrors = [];
    page.on('pageerror', (e) => jsErrors.push(String(e).slice(0, 120)));
    await page.goto(p.url, { waitUntil: 'load' });
    await page.waitForSelector(p.ready, { timeout: 15000 });

    const m = await page.evaluate((oursSelector) => {
      const doc = document.documentElement;
      // Only OUR markup is measured for starved boxes and touch targets: a
      // theme's own cramped button is not something this plugin may fix.
      const roots = oursSelector
        ? Array.from(document.querySelectorAll(oursSelector))
        : [document.body];
      const within = (selector) => roots.flatMap((r) => Array.from(r.querySelectorAll(selector)));

      const starved = [];
      for (const el of within('*')) {
        if (el.children.length > 0) continue;
        const text = (el.textContent || '').trim();
        if (text.length < 5) continue;
        const r = el.getBoundingClientRect();
        const cs = getComputedStyle(el);
        if (r.width === 0 || r.height <= 2 || cs.clipPath !== 'none') continue;
        const fs = parseFloat(cs.fontSize) || 16;
        if (r.width < 4 * fs * 0.6) starved.push(`${el.tagName.toLowerCase()} "${text.slice(0, 18)}" ${Math.round(r.width)}px`);
      }
      const small = [];
      for (const el of within('button, input, textarea, select, a.tv-btn')) {
        const type = (el.getAttribute('type') || '').toLowerCase();
        if (type === 'hidden') continue;
        const r = el.getBoundingClientRect();
        if (r.height === 0) continue;
        const floor = (type === 'checkbox' || type === 'radio') ? 24 : 40;
        if (r.height < floor) small.push(`${el.tagName.toLowerCase()}[${type || 'n/a'}] ${Math.round(r.height)}px < ${floor}px`);
      }
      const unlabelled = [];
      for (const el of within('input:not([type=hidden]), select, textarea')) {
        const id = el.getAttribute('id');
        const labelled = (id && document.querySelector(`label[for="${id}"]`)) || el.closest('label')
          || el.getAttribute('aria-label') || el.getAttribute('aria-labelledby');
        if (!labelled) unlabelled.push(el.getAttribute('name') || el.tagName.toLowerCase());
      }
      return {
        overflow: doc.scrollWidth - doc.clientWidth,
        lang: doc.lang,
        dir: doc.dir,
        found: roots.length,
        starved: starved.slice(0, 4),
        small: small.slice(0, 4),
        unlabelled: unlabelled.slice(0, 4),
      };
    }, p.ours);

    record(p.name, String(width), 'our-markup-is-on-the-page', m.found > 0, `roots=${m.found}`);
    record(p.name, String(width), 'no-horizontal-scroll', m.overflow <= 1, `overflow=${m.overflow}`);
    record(p.name, String(width), 'no-starved-text-box', m.starved.length === 0, m.starved.join('; ') || 'none');
    record(p.name, String(width), 'controls-are-touch-sized', m.small.length === 0, m.small.join('; ') || 'none');
    record(p.name, String(width), 'every-field-has-a-label', m.unlabelled.length === 0, m.unlabelled.join('; ') || 'none');
    record(p.name, String(width), 'document-is-persian-rtl', m.lang.startsWith('fa') && m.dir === 'rtl', `lang=${m.lang} dir=${m.dir}`);
    record(p.name, String(width), 'no-js-errors', jsErrors.length === 0, jsErrors.join(' | ') || 'none');

    let builder = new AxeBuilder({ page }).withTags(AXE_TAGS);
    if (p.ours) {
      for (const selector of p.ours.split(',').map((s) => s.trim())) {
        builder = builder.include(selector);
      }
    }
    const axe = await builder.analyze();
    record(p.name, String(width), 'axe-wcag-aa', axe.violations.length === 0,
      axe.violations.map((v) => `${v.id}(${v.nodes.length})`).join(', ') || 'none');

    if (p.ours) {
      // Recorded, never asserted: what the theme's own page does around us.
      const whole = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
      notes.push({
        page: p.name,
        width,
        whole_page_violations: whole.violations.map((v) => `${v.id}(${v.nodes.length})`),
      });
    }

    if (width === 375 || width === 1440) {
      await page.screenshot({ path: path.join(OUT, `${p.name}-${width}.png`), fullPage: true });
    }
    await ctx.close();
  }
}

await browser.close();
const summary = {
  suite: 'wholesale-and-coupon-screens',
  generated_at: new Date().toISOString(),
  widths: WIDTHS,
  total: results.length,
  failures: failures.length,
  results,
  theme_pages_recorded_not_asserted: notes,
};
fs.writeFileSync(path.join(OUT, 'wholesale-a11y.json'), JSON.stringify(summary, null, 2) + '\n');
const byCheck = {};
for (const r of results) {
  byCheck[r.check] ??= { pass: 0, fail: 0 };
  byCheck[r.check][r.ok ? 'pass' : 'fail']++;
}
const lines = Object.entries(byCheck).map(([k, v]) => `  ${k.padEnd(34)} pass=${v.pass} fail=${v.fail}`);
const themeSeen = [...new Set(notes.flatMap((n) => n.whole_page_violations))];
const textOut = `wholesale and coupon screens — ${results.length} checks, ${failures.length} failures\n\n${lines.join('\n')}\n`
  + `\nrecorded, NOT asserted — the theme's own page around our markup:\n  `
  + (themeSeen.length ? themeSeen.join(', ') : 'none')
  + '\n'
  + (failures.length ? `\nFAILURES:\n${failures.map((f) => '  ' + f).join('\n')}\n` : '');
fs.writeFileSync(path.join(OUT, 'wholesale-a11y.log'), textOut);
console.log(textOut);
process.exit(failures.length === 0 ? 0 : 1);
