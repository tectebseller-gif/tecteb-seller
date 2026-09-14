/**
 * The product screens, measured the way every other vendor screen was.
 *
 * Six pages this time, because the four-step form is four different layouts
 * and the CSV report is a table — the one element allowed to scroll sideways,
 * and therefore the one most likely to push the page with it. Same checks,
 * same five widths, same axe tags.
 *
 *   SITE=… TMC_PRODUCT_ID=… TMC_OUT=docs/evidence/products/a11y node check-products-a11y.mjs
 */
import { chromium } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';
import fs from 'node:fs';
import path from 'node:path';

const SITE = process.env.SITE || 'http://127.0.0.1:8080';
const USER = process.env.TMC_VENDOR_USER || 'tmcvendor';
const PASS = process.env.TMC_VENDOR_PASS || 'TmcVendor!2026';
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.TMC_OUT || 'docs/evidence/products/a11y';
const PRODUCT = process.env.TMC_PRODUCT_ID || '1';
const WIDTHS = [320, 375, 768, 1024, 1440];
const PRODUCTS = `${SITE}/vendor/products/`;
const PAGES = [
  { name: 'products-list', url: PRODUCTS },
  { name: 'products-list-filtered', url: `${PRODUCTS}?status=published` },
  { name: 'product-form-step1', url: `${PRODUCTS}?product=${PRODUCT}&step=1` },
  { name: 'product-form-step2', url: `${PRODUCTS}?product=${PRODUCT}&step=2` },
  { name: 'product-form-step3', url: `${PRODUCTS}?product=${PRODUCT}&step=3` },
  { name: 'product-form-step4', url: `${PRODUCTS}?product=${PRODUCT}&step=4` },
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
await Promise.all([loginPage.waitForURL(/wp-admin|my-account|\/$/, { timeout: 30000 }), loginPage.click('#wp-submit')]);
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
  suite: 'vendor-products',
  generated_at: new Date().toISOString(),
  widths: WIDTHS,
  total: results.length,
  failures: failures.length,
  results,
};
fs.writeFileSync(path.join(OUT, 'products-a11y.json'), JSON.stringify(summary, null, 2) + '\n');
const byCheck = {};
for (const r of results) {
  byCheck[r.check] ??= { pass: 0, fail: 0 };
  byCheck[r.check][r.ok ? 'pass' : 'fail']++;
}
const lines = Object.entries(byCheck).map(([k, v]) => `  ${k.padEnd(34)} pass=${v.pass} fail=${v.fail}`);
const textOut = `product screens — ${results.length} checks, ${failures.length} failures\n\n${lines.join('\n')}\n` +
  (failures.length ? `\nFAILURES:\n${failures.map((f) => '  ' + f).join('\n')}\n` : '');
fs.writeFileSync(path.join(OUT, 'products-a11y.log'), textOut);
console.log(textOut);
process.exit(failures.length === 0 ? 0 : 1);
