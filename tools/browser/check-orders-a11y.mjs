/**
 * The two vendor screens this stage added, measured the way the earlier ones
 * were: the orders list, the variations panel of a variable product, and the
 * shop's own finance page.
 *
 * A page nobody measures is a page that drifts, and both of these arrived
 * after check-vendor.mjs and check-vendor-staff-a11y.mjs were written. Same
 * checks, same five widths, same axe tags.
 *
 *   SITE=… TMC_VARIABLE_PRODUCT=3 TMC_OUT=docs/evidence/orders/a11y \
 *     node check-orders-a11y.mjs
 */
import { chromium } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';
import fs from 'node:fs';
import path from 'node:path';

const SITE = process.env.SITE || 'http://127.0.0.1:8080';
const USER = process.env.TMC_VENDOR_USER || 'tmcvendor';
const PASS = process.env.TMC_VENDOR_PASS || 'TmcVendor!2026';
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.TMC_OUT || 'docs/evidence/orders/a11y';
const WIDTHS = [320, 375, 768, 1024, 1440];
const VARIABLE = process.env.TMC_VARIABLE_PRODUCT || '3';
const PAGES = [
  { name: 'vendor-orders', url: `${SITE}/vendor/orders/` },
  { name: 'vendor-variations', url: `${SITE}/vendor/products/?product=${VARIABLE}&step=2` },
  { name: 'vendor-finance', url: `${SITE}/vendor/finance/` },
];
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

fs.mkdirSync(OUT, { recursive: true });
const results = [];
const failures = [];
const record = (page, scope, check, ok, detail = '') => {
  results.push({ page, scope, check, ok, detail });
  if (!ok) failures.push(`${page} @ ${scope}: ${check} — ${detail}`);
};

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
const loginCtx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR' });
const loginPage = await loginCtx.newPage();
await loginPage.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
await loginPage.fill('#user_login', USER);
await loginPage.fill('#user_pass', PASS);
// Click, then wait for the page that follows — racing a waitForURL against
// the click times out when the navigation finishes first.
await loginPage.click('#wp-submit');
await loginPage.waitForLoadState('load');
if (await loginPage.locator('#user_pass').count() > 0) {
  throw new Error(`login as ${USER} failed: still on the login form`);
}
const storageState = await loginCtx.storageState();
await loginCtx.close();

for (const p of PAGES) {
  for (const width of WIDTHS) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'fa-IR', storageState });
    const page = await ctx.newPage();
    const jsErrors = [];
    page.on('pageerror', (e) => jsErrors.push(String(e).slice(0, 120)));
    await page.goto(p.url, { waitUntil: 'load' });
    await page.waitForSelector('.tv-main', { timeout: 15000 });

    const m = await page.evaluate(() => {
      const doc = document.documentElement;
      const starved = [];
      for (const el of document.querySelectorAll('body *')) {
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
      for (const el of document.querySelectorAll('a.tv-btn, a.tv-tab, button, .tv-nav__link, input, textarea, select')) {
        const type = (el.getAttribute('type') || '').toLowerCase();
        if (type === 'hidden') continue;
        const r = el.getBoundingClientRect();
        if (r.height === 0) continue;
        const floor = (type === 'checkbox' || type === 'radio') ? 24 : 40;
        if (r.height < floor) small.push(`${el.tagName.toLowerCase()}[${type || 'n/a'}] ${Math.round(r.height)}px < ${floor}px`);
      }
      const unlabelled = [];
      for (const el of document.querySelectorAll('input:not([type=hidden]), select, textarea')) {
        const id = el.getAttribute('id');
        const labelled = (id && document.querySelector(`label[for="${id}"]`)) || el.closest('label')
          || el.getAttribute('aria-label') || el.getAttribute('aria-labelledby');
        if (!labelled) unlabelled.push(el.getAttribute('name') || el.tagName.toLowerCase());
      }
      return {
        overflow: doc.scrollWidth - doc.clientWidth,
        lang: doc.lang,
        dir: doc.dir,
        starved: starved.slice(0, 4),
        small: small.slice(0, 4),
        unlabelled: unlabelled.slice(0, 4),
        h1: document.querySelectorAll('h1').length,
      };
    });

    record(p.name, String(width), 'no-horizontal-scroll', m.overflow <= 1, `overflow=${m.overflow}`);
    record(p.name, String(width), 'no-starved-text-box', m.starved.length === 0, m.starved.join('; ') || 'none');
    record(p.name, String(width), 'controls-are-touch-sized', m.small.length === 0, m.small.join('; ') || 'none');
    record(p.name, String(width), 'every-field-has-a-label', m.unlabelled.length === 0, m.unlabelled.join('; ') || 'none');
    record(p.name, String(width), 'document-is-persian-rtl', m.lang.startsWith('fa') && m.dir === 'rtl', `lang=${m.lang} dir=${m.dir}`);
    record(p.name, String(width), 'exactly-one-h1', m.h1 === 1, `h1=${m.h1}`);
    record(p.name, String(width), 'no-js-errors', jsErrors.length === 0, jsErrors.join(' | ') || 'none');

    const axe = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
    record(p.name, String(width), 'axe-wcag-aa', axe.violations.length === 0,
      axe.violations.map((v) => `${v.id}(${v.nodes.length})`).join(', ') || 'none');

    if (width === 375 || width === 1440) {
      await page.screenshot({ path: path.join(OUT, `${p.name}-${width}.png`), fullPage: true });
    }
    await ctx.close();
  }
}

await browser.close();
const summary = {
  suite: 'vendor-orders-and-variations',
  generated_at: new Date().toISOString(),
  widths: WIDTHS,
  total: results.length,
  failures: failures.length,
  results,
};
fs.writeFileSync(path.join(OUT, 'orders-a11y.json'), JSON.stringify(summary, null, 2) + '\n');
const byCheck = {};
for (const r of results) {
  byCheck[r.check] ??= { pass: 0, fail: 0 };
  byCheck[r.check][r.ok ? 'pass' : 'fail']++;
}
const lines = Object.entries(byCheck).map(([k, v]) => `  ${k.padEnd(34)} pass=${v.pass} fail=${v.fail}`);
const textOut = `orders and variations screens — ${results.length} checks, ${failures.length} failures\n\n${lines.join('\n')}\n` +
  (failures.length ? `\nFAILURES:\n${failures.map((f) => '  ' + f).join('\n')}\n` : '');
fs.writeFileSync(path.join(OUT, 'orders-a11y.log'), textOut);
console.log(textOut);
process.exit(failures.length === 0 ? 0 : 1);
