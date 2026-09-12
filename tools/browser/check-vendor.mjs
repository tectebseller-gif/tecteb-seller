/**
 * Acceptance checks for the VENDOR AREA — its own suite, on its own routes.
 *
 * The four admin screens have their own suite (check-wpadmin.mjs) and it
 * proves nothing about /vendor/: different shell, different stylesheet,
 * different audience. This one signs in as a vendor and measures the pages
 * they actually see.
 *
 *   SITE, TMC_VENDOR_USER, TMC_VENDOR_PASS, TMC_OUT
 */
import { chromium } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';
import fs from 'node:fs';
import path from 'node:path';

const SITE = process.env.SITE || 'http://127.0.0.1:8080';
const USER = process.env.TMC_VENDOR_USER || 'tmcvendor';
const PASS = process.env.TMC_VENDOR_PASS || 'TmcVendor!2026';
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.TMC_OUT || 'docs/evidence/vendor/a11y';
const WIDTHS = [320, 375, 768, 1024, 1440];
const PAGES = [
  { name: 'vendor-dashboard', url: `${SITE}/vendor/` },
  { name: 'vendor-application', url: `${SITE}/vendor/application/` },
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
await Promise.all([loginPage.waitForURL(/wp-admin|\/$/, { timeout: 30000 }), loginPage.click('#wp-submit')]);
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
      // Two floors, both from WCAG 2.2 §2.5.8 and plain thumb geometry: a
      // checkbox must clear 24px, anything you press or type into 40px.
      const small = [];
      for (const el of document.querySelectorAll('a.tv-btn, button, .tv-nav__link, input, textarea, select')) {
        const type = (el.getAttribute('type') || '').toLowerCase();
        if (type === 'hidden') continue;
        const r = el.getBoundingClientRect();
        if (r.height === 0) continue;
        const floor = (type === 'checkbox' || type === 'radio') ? 24 : 40;
        if (r.height < floor) small.push(`${el.tagName.toLowerCase()}[${type || 'n/a'}] ${Math.round(r.height)}px < ${floor}px`);
      }
      return {
        overflow: doc.scrollWidth - doc.clientWidth,
        lang: doc.lang,
        dir: doc.dir,
        starved: starved.slice(0, 4),
        small: small.slice(0, 4),
        h1: document.querySelectorAll('h1').length,
      };
    });

    record(p.name, String(width), 'no-horizontal-scroll', m.overflow <= 1, `overflow=${m.overflow}`);
    record(p.name, String(width), 'no-starved-text-box', m.starved.length === 0, m.starved.join('; ') || 'none');
    record(p.name, String(width), 'controls-are-touch-sized', m.small.length === 0, m.small.join('; ') || 'none');
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

  // Keyboard: the skip link must be the first stop and must move focus.
  const ctx = await browser.newContext({ viewport: { width: 1024, height: 768 }, locale: 'fa-IR', storageState });
  const page = await ctx.newPage();
  await page.goto(p.url, { waitUntil: 'load' });
  await page.keyboard.press('Tab');
  const first = await page.evaluate(() => ({
    cls: document.activeElement?.className || '',
    text: (document.activeElement?.textContent || '').trim().slice(0, 24),
  }));
  record(p.name, 'keyboard', 'skip-link-is-the-first-stop', first.cls.includes('tv-skip'), `first=${first.cls} "${first.text}"`);
  await page.keyboard.press('Enter');
  const landed = await page.evaluate(() => ({
    hash: location.hash,
    focused: document.activeElement?.id || '',
  }));
  record(p.name, 'keyboard', 'skip-link-moves-focus-to-main', landed.hash === '#tv-main' && landed.focused === 'tv-main', JSON.stringify(landed));

  const reach = await page.evaluate(() => {
    const sel = 'a[href], button:not([disabled]), input:not([type=hidden]):not([disabled]), textarea, select';
    return document.querySelectorAll(`.tv-main ${sel}, .tv-head ${sel}`).length;
  });
  let reached = 0;
  for (let i = 0; i < 60; i++) {
    await page.keyboard.press('Tab');
    const inside = await page.evaluate(() => !!document.activeElement?.closest('.tv-main, .tv-head'));
    if (inside) reached++;
  }
  record(p.name, 'keyboard', 'controls-are-reachable-by-tab', reached >= Math.min(reach, 5), `${reached} stops inside the page, ${reach} focusable controls`);
  await ctx.close();
}

await browser.close();

const summary = {
  suite: 'vendor-area',
  scope: 'The vendor pages at /vendor/ in a real WordPress, signed in as a vendor. Separate from the admin-screen suite on purpose.',
  generated_at: new Date().toISOString(),
  widths: WIDTHS,
  total: results.length,
  failures: failures.length,
  results,
};
fs.writeFileSync(path.join(OUT, 'vendor-a11y.json'), JSON.stringify(summary, null, 2) + '\n');
const byCheck = {};
for (const r of results) {
  byCheck[r.check] ??= { pass: 0, fail: 0 };
  byCheck[r.check][r.ok ? 'pass' : 'fail']++;
}
const lines = Object.entries(byCheck).map(([k, v]) => `  ${k.padEnd(34)} pass=${v.pass} fail=${v.fail}`);
const text = `vendor area — ${results.length} checks, ${failures.length} failures\n\n${lines.join('\n')}\n` +
  (failures.length ? `\nFAILURES:\n${failures.map((f) => '  ' + f).join('\n')}\n` : '');
fs.writeFileSync(path.join(OUT, 'vendor-a11y.log'), text);
console.log(text);
process.exit(failures.length === 0 ? 0 : 1);
