/*
 * Browser checks for the RENDER HARNESS — development only.
 *
 * Measures the plugin's own markup and CSS in Chromium at the viewports the
 * UX spec lists, plus 200% zoom, keyboard order and axe-core.
 *
 * SCOPE: this is a sample of the interface («نمونه رابط»), rendered OUTSIDE
 * WordPress. It is not evidence that the plugin works inside wp-admin, and
 * automated axe checks do not replace a manual screen-reader pass.
 */
import { chromium } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const harnessDir = resolve(root, 'docs/evidence/harness');
const shotDir = resolve(root, 'docs/evidence/screenshots');
mkdirSync(shotDir, { recursive: true });

const scenarios = JSON.parse(readFileSync(resolve(harnessDir, 'index.json'), 'utf8'));
const VIEWPORTS = [
  { name: '320', width: 320, height: 720 },
  { name: '375', width: 375, height: 812 },
  { name: '768', width: 768, height: 1024 },
  { name: '1024', width: 1024, height: 768 },
  { name: '1440', width: 1440, height: 900 },
];
const MIN_TOUCH = 44;

const results = [];
const failures = [];

function record(scenario, viewport, check, ok, detail) {
  results.push({ scenario, viewport, check, ok, detail });
  if (!ok) failures.push(`${scenario} @ ${viewport}: ${check} — ${detail}`);
}

// Use the Chromium already present in the image. The installed Playwright
// package expects a newer build id, and downloading browsers is disabled here.
const EXECUTABLE = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const browser = await chromium.launch({
  executablePath: EXECUTABLE,
  args: ['--allow-file-access-from-files', '--no-sandbox'],
});

for (const s of scenarios) {
  for (const vp of VIEWPORTS) {
    const ctx = await browser.newContext({
      viewport: { width: vp.width, height: vp.height },
      deviceScaleFactor: 1,
      locale: 'fa-IR',
      reducedMotion: 'reduce',
    });
    const page = await ctx.newPage();
    const pageErrors = [];
    page.on('pageerror', (e) => pageErrors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') pageErrors.push(m.text()); });
    await page.goto('file://' + s.file, { waitUntil: 'load' });

    // 1. no horizontal scrolling of the page itself
    const overflow = await page.evaluate(() => ({
      scrollW: document.documentElement.scrollWidth,
      clientW: document.documentElement.clientWidth,
    }));
    record(s.name, vp.name, 'no-horizontal-page-scroll', overflow.scrollW <= overflow.clientW + 1,
      `scrollWidth=${overflow.scrollW} clientWidth=${overflow.clientW}`);

    // 2. no element sticking out of the viewport
    const spill = await page.evaluate(() => {
      const w = document.documentElement.clientWidth;
      const out = [];
      for (const el of document.querySelectorAll('.tmc-admin *')) {
        const r = el.getBoundingClientRect();
        if (r.width > 0 && (r.right > w + 1 || r.left < -1)) {
          out.push(`${el.tagName.toLowerCase()}.${(el.className || '').toString().split(' ')[0]} right=${Math.round(r.right)}`);
        }
      }
      return out.slice(0, 5);
    });
    record(s.name, vp.name, 'no-element-overflow', spill.length === 0, spill.join('; ') || 'none');

    // 3. interactive targets are at least 44x44 (UX §1.1)
    const small = await page.evaluate((min) => {
      const out = [];
      for (const el of document.querySelectorAll('.tmc-admin a, .tmc-admin button, .tmc-admin input:not([type=hidden]), .tmc-admin select')) {
        if (el.classList.contains('tmc-skip')) continue; // visually hidden until focused
        const r = el.getBoundingClientRect();
        if (r.width === 0 && r.height === 0) continue;
        const inProse = el.closest('p, dd, .tmc-field__desc, .tmc-error-summary');
        if (inProse) continue; // inline text links are exempt from the target rule
        if (r.height < min - 0.5 || r.width < min - 0.5) {
          out.push(`${el.tagName.toLowerCase()} ${Math.round(r.width)}x${Math.round(r.height)} "${(el.textContent || '').trim().slice(0, 20)}"`);
        }
      }
      return out.slice(0, 6);
    }, MIN_TOUCH);
    record(s.name, vp.name, 'touch-target-44px', small.length === 0, small.join('; ') || 'none');

    // 4. no JS errors
    record(s.name, vp.name, 'no-js-errors', pageErrors.length === 0, pageErrors.join(' | ') || 'none');

    // 5. axe (WCAG 2.x A/AA)
    const axe = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
      .include('.tmc-admin')
      .analyze();
    const violations = axe.violations.map((v) => `${v.id}(${v.nodes.length})`);
    record(s.name, vp.name, 'axe-wcag-aa', axe.violations.length === 0, violations.join(', ') || 'none');

    if (vp.name === '375' || vp.name === '1440') {
      await page.screenshot({ path: resolve(shotDir, `${s.name}-${vp.name}.png`), fullPage: true });
    }
    await ctx.close();
  }

  // 6. keyboard: skip link first, DOM order, visible focus ring
  {
    const ctx = await browser.newContext({ viewport: { width: 1024, height: 768 }, locale: 'fa-IR', reducedMotion: 'reduce' });
    const page = await ctx.newPage();
    await page.goto('file://' + s.file, { waitUntil: 'load' });
    // Focus placement happens on load; read it BEFORE tabbing moves it.
    const hasSummary = await page.evaluate(() => !!document.getElementById('tmc-error-summary'));
    const initialFocus = await page.evaluate(() => (document.activeElement && document.activeElement.id) || '');
    const order = [];
    for (let i = 0; i < 14; i++) {
      await page.keyboard.press('Tab');
      const info = await page.evaluate(() => {
        const el = document.activeElement;
        if (!el || el === document.body) return null;
        const cs = getComputedStyle(el);
        const ring = cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0;
        return { tag: el.tagName.toLowerCase(), cls: (el.className || '').toString().split(' ')[0], ring };
      });
      if (!info) break;
      order.push(info);
    }
    record(s.name, 'keyboard', 'reaches-controls', order.length > 0, `${order.length} stops`);

    // A page that reports validation errors moves focus to the error summary
    // on load (UX §17), so tabbing continues from there. Any other page must
    // offer the skip link first.
    if (hasSummary) {
      const summaryLinks = order.filter((o) => o.cls === 'tmc-error-summary__title' || o.tag === 'a').length;
      record(s.name, 'keyboard', 'error-summary-focused-on-load', initialFocus === 'tmc-error-summary', `activeElement id="${initialFocus}"`);
      record(s.name, 'keyboard', 'error-summary-links-reachable', summaryLinks > 0, `${summaryLinks} links reachable`);
    } else {
      record(s.name, 'keyboard', 'skip-link-first', order.length > 0 && order[0].cls === 'tmc-skip', order[0] ? order[0].cls : 'none');
    }
    const noRing = order.filter((o) => !o.ring).map((o) => `${o.tag}.${o.cls}`);
    record(s.name, 'keyboard', 'focus-visible', noRing.length === 0, noRing.join(', ') || 'all focusable stops show a ring');
    await ctx.close();
  }

  // 7. reflow at 200% zoom (WCAG 1.4.10): 1280x1024 at 2x ≈ 640x512 CSS px
  {
    const ctx = await browser.newContext({ viewport: { width: 640, height: 512 }, deviceScaleFactor: 2, locale: 'fa-IR' });
    const page = await ctx.newPage();
    await page.goto('file://' + s.file, { waitUntil: 'load' });
    const o = await page.evaluate(() => ({ s: document.documentElement.scrollWidth, c: document.documentElement.clientWidth }));
    record(s.name, 'zoom200', 'no-horizontal-scroll', o.s <= o.c + 1, `scrollWidth=${o.s} clientWidth=${o.c}`);
    await ctx.close();
  }
}

const browserVersion = browser.version();
await browser.close();

const chromeVersion = browserVersion;
const summary = {
  generated_at: new Date().toISOString(),
  chromium: chromeVersion,
  scope: 'Render harness OUTSIDE WordPress — interface sample, not proof the plugin works in wp-admin.',
  playwright: JSON.parse(readFileSync(resolve(here, 'node_modules/playwright/package.json'), 'utf8')).version,
  axe_core: JSON.parse(readFileSync(resolve(here, 'node_modules/axe-core/package.json'), 'utf8')).version,
  total: results.length,
  failed: failures.length,
  results,
};
writeFileSync(resolve(root, 'docs/evidence/browser-checks.json'), JSON.stringify(summary, null, 2));

const byCheck = {};
for (const r of results) {
  byCheck[r.check] ??= { pass: 0, fail: 0 };
  byCheck[r.check][r.ok ? 'pass' : 'fail']++;
}
console.log(`playwright ${summary.playwright}  axe-core ${summary.axe_core}  chromium ${summary.chromium}`);
for (const [k, v] of Object.entries(byCheck)) console.log(`  ${k.padEnd(28)} pass=${v.pass} fail=${v.fail}`);
console.log(`\ntotal ${results.length}, failures ${failures.length}`);
for (const f of failures.slice(0, 25)) console.log('  FAIL ' + f);
process.exit(failures.length === 0 ? 0 : 1);
