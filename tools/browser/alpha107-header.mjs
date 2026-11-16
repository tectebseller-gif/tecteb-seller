/**
 * §8's vendor header, measured in a real browser at three widths.
 *
 * The owner's brief has a judgement half and a MEASUREMENT half, and this
 * file is only the second one:
 *
 *   «هدر کم‌ارتفاع‌تر»                  → the header's height, before and after
 *   «بدون ازدحام یا شکست نامناسب خطوط» → no nav label on two lines
 *   «واکنش‌گرایی درست در دسکتاپ، تبلت و موبایل» → three widths, no side scroll
 *   «نام فروشگاه در هدر قابل کلیک باشد» → clicking it opens the menu
 *   «گوشه‌های متعادل، نه دکمه‌های بیش‌ازحد کپسولی» → the computed radius
 *
 * ⛔ WHAT THIS IS NOT. The pages are rendered by `tools/render-vendor-header.php`
 * and opened over `file://`. That is the real markup and the real stylesheet
 * in a real layout engine, which is what a pixel claim needs — and it is NOT
 * the owner's WordPress. Installing the package, the real theme, a persistent
 * object cache and wp-admin all stay `Not Run`, and nothing here touches
 * staging or production.
 *
 *   TMC_HEADER_DIR, TMC_HEADER_BASE, TMC_CHROMIUM — and a server on that base:
 *     php -S 127.0.0.1:8099 -t docs/evidence/alpha107-header
 */
import { chromium } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';
import fs from 'node:fs';
import path from 'node:path';

const DIR = path.resolve(process.env.TMC_HEADER_DIR || 'docs/evidence/alpha107-header');
// ⛔ SERVED, NOT OPENED FROM DISK. Over `file://` a browser reads the `?ver=`
// query string the shell appends as part of the FILENAME, so the stylesheet
// 404s — and an unstyled page still answers «no horizontal scroll» and «no
// label breaks». The first run of this file passed nine checks that way.
const BASE = process.env.TMC_HEADER_BASE || 'http://127.0.0.1:8099';
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

const WIDTHS = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'tablet', width: 1024, height: 768 },
  { name: 'phone', width: 390, height: 844 },
];

const results = [];
let failures = 0;
const check = (scope, name, ok, detail = '') => {
  results.push({ scope, check: name, ok: !!ok, detail: String(detail) });
  if (!ok) failures++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${scope} :: ${name}${detail ? '  — ' + detail : ''}`);
};

const url = (file) => BASE + '/' + file;

/** The header's own box, and where the first line of content starts. */
const geometry = (page) => page.evaluate(() => {
  const head = document.querySelector('.tv-head');
  const first = document.querySelector('.tv-main .tv-card__title');
  const r = head.getBoundingClientRect();
  return {
    headerHeight: Math.round(r.height),
    contentStartsAt: first ? Math.round(first.getBoundingClientRect().top + window.scrollY) : -1,
    docWidth: document.documentElement.scrollWidth,
    viewportWidth: window.innerWidth,
  };
});

/** How many lines each nav label occupies, by its own box height. */
const navLabelLines = (page) => page.evaluate(() => {
  const out = [];
  for (const a of document.querySelectorAll('.tv-nav__link')) {
    const cs = getComputedStyle(a);
    const lh = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.8;
    // The box is padded; measure the TEXT, which is what wraps.
    const range = document.createRange();
    range.selectNodeContents(a);
    const rects = range.getClientRects();
    out.push({ label: a.textContent.trim(), lines: rects.length, lineHeight: Math.round(lh) });
  }
  return out;
});

(async () => {
  const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
  try {
    for (const vp of WIDTHS) {
      const context = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, deviceScaleFactor: 1 });
      const page = await context.newPage();

      // --- before (alpha.106) and after (§8), same viewport, same stylesheet path
      await page.goto(url('before/with-account.html'));
      await page.waitForLoadState('load');
      const before = await geometry(page);

      await page.goto(url('with-account.html'));
      await page.waitForLoadState('load');
      const after = await geometry(page);

      check(vp.name, 'the header is lower than it was',
        after.headerHeight < before.headerHeight,
        `before ${before.headerHeight}px → after ${after.headerHeight}px`);
      check(vp.name, 'the first line of content starts higher',
        after.contentStartsAt < before.contentStartsAt && after.contentStartsAt > 0,
        `before ${before.contentStartsAt}px → after ${after.contentStartsAt}px`);
      check(vp.name, 'no horizontal page scroll',
        after.docWidth <= after.viewportWidth,
        `doc ${after.docWidth} vs viewport ${after.viewportWidth}`);

      // --- no nav label broken across lines
      // On a phone the menu is a collapsed `<details>`, so open it first:
      // a label inside a closed panel has no boxes to measure at all, and
      // «zero lines» is not «one line» (`alpha.8`, on a geometry read).
      await page.evaluate(() => { document.querySelector('#tv-nav')?.setAttribute('open', ''); });
      const labels = await navLabelLines(page);
      check(vp.name, 'every menu label was measured', labels.length === 10, `${labels.length} of 10`);
      const broken = labels.filter((l) => l.lines !== 1);
      check(vp.name, 'no menu label breaks across lines',
        broken.length === 0,
        broken.map((b) => `${b.label}=${b.lines}`).join(', ') || 'all one line');

      // --- balanced corners, not capsules
      const radius = await page.evaluate(() => {
        const px = (sel) => {
          const el = document.querySelector(sel);
          return el ? Math.round(parseFloat(getComputedStyle(el).borderTopLeftRadius)) : -1;
        };
        return { nav: px('.tv-nav__link'), account: px('.tv-acct__toggle') };
      });
      check(vp.name, 'menu items are not capsules',
        radius.nav > 0 && radius.nav <= 12, `${radius.nav}px`);
      check(vp.name, 'the account control is not a capsule',
        radius.account > 0 && radius.account <= 12, `${radius.account}px`);

      // --- the mark's cross, measured rather than asserted as a constant
      //
      // ⛔ `tools/contrast.php` pins the PAIR of hex values and never reads
      // the stylesheet, so it cannot notice the stylesheet using a different
      // one (`alpha.92`). This reads what the browser actually computed for
      // the pseudo-element and for its tile, and does the ratio here.
      const mark = await page.evaluate(() => {
        const el = document.querySelector('.tv-head__mark');
        if (!el) return null;
        const rgb = (s) => (s.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
        return {
          cross: rgb(getComputedStyle(el, '::before').backgroundColor),
          tile: rgb(getComputedStyle(el).backgroundColor),
        };
      });
      const ratio = (a, b) => {
        const lum = (c) => {
          const f = c.map((v) => { const x = v / 255; return x <= 0.04045 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4; });
          return 0.2126 * f[0] + 0.7152 * f[1] + 0.0722 * f[2];
        };
        const la = lum(a), lb = lum(b);
        return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
      };
      const markRatio = mark && mark.cross.length === 3 && mark.tile.length === 3 ? ratio(mark.cross, mark.tile) : 0;
      check(vp.name, 'the mark is readable: its cross is 3:1 against its tile',
        markRatio >= 3, `${markRatio.toFixed(2)}:1`);

      // --- the store name opens the account menu, by CLICKING it
      await page.goto(url('with-account.html'));
      await page.waitForLoadState('load');
      check(vp.name, 'the account menu starts closed',
        !(await page.locator('.tv-acct').evaluate((d) => d.open)), '');
      await page.locator('.tv-acct__store').click();
      const open = await page.locator('.tv-acct').evaluate((d) => d.open);
      check(vp.name, 'clicking the shop name opens the menu', open, '');
      const items = await page.locator('.tv-acct__menu a').allTextContents();
      check(vp.name, 'the menu shows the three items',
        items.length === 3, items.join(' | '));
      // Inside the viewport, not off the edge — the panel is absolutely
      // positioned and RTL, which is exactly where an `inset-inline-end` typo
      // would push it out of the page.
      const box = await page.locator('.tv-acct__menu').boundingBox();
      check(vp.name, 'the open menu is inside the viewport',
        box !== null && box.x >= 0 && box.x + box.width <= vp.width + 1,
        box ? `x=${Math.round(box.x)} w=${Math.round(box.width)}` : 'no box');

      // --- the keyboard reaches it, which is what makes `<details>` the right element
      await page.goto(url('with-account.html'));
      await page.waitForLoadState('load');
      await page.locator('.tv-acct__toggle').focus();
      await page.keyboard.press('Enter');
      check(vp.name, 'Enter on the shop name opens the menu',
        await page.locator('.tv-acct').evaluate((d) => d.open), '');

      // --- and with no script at all
      const noJs = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, javaScriptEnabled: false });
      const bare = await noJs.newPage();
      await bare.goto(url('with-account.html'));
      await bare.waitForLoadState('load');
      await bare.locator('.tv-acct__store').click();
      check(vp.name, 'the menu opens with JavaScript off',
        await bare.locator('.tv-acct').evaluate((d) => d.open), '');
      const logout = await bare.locator('.tv-acct__link--logout').getAttribute('href');
      const site = await bare.locator('.tv-acct__link--site').getAttribute('href');
      check(vp.name, 'back to the site is not the logout address',
        logout !== site && /action=logout/.test(logout || '') && !/action=logout/.test(site || ''),
        `site=${site} logout=${logout}`);
      await noJs.close();

      // --- screenshots, and axe on the real markup
      const shot = path.join(DIR, 'screens');
      fs.mkdirSync(shot, { recursive: true });
      await page.goto(url('with-account.html'));
      await page.waitForLoadState('load');
      await page.screenshot({ path: path.join(shot, `after-${vp.name}.png`), fullPage: true });
      await page.locator('.tv-acct__store').click();
      await page.screenshot({ path: path.join(shot, `after-${vp.name}-menu.png`), fullPage: true });
      await page.goto(url('before/with-account.html'));
      await page.waitForLoadState('load');
      await page.screenshot({ path: path.join(shot, `before-${vp.name}.png`), fullPage: true });

      await page.goto(url('with-account.html'));
      await page.waitForLoadState('load');
      const axe = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
      const serious = axe.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
      check(vp.name, 'axe: no serious or critical violation',
        serious.length === 0,
        serious.map((v) => `${v.id}×${v.nodes.length}`).join(', ') || `${axe.violations.length} minor/moderate`);

      await context.close();
    }

    // --- the two shells that must NOT carry an account menu
    const context = await browser.newContext({ viewport: WIDTHS[0] });
    const page = await context.newPage();
    await page.goto(url('no-shop-page.html'));
    await page.waitForLoadState('load');
    check('no-shop-page', 'no view-shop link', (await page.locator('.tv-acct__link--shop').count()) === 0, '');
    check('no-shop-page', 'the other two are still there',
      (await page.locator('.tv-acct__menu a').count()) === 2, '');
    await page.goto(url('no-store.html'));
    await page.waitForLoadState('load');
    check('no-store', 'no account control at all', (await page.locator('.tv-acct').count()) === 0, '');
    check('no-store', 'no logout anywhere on the page',
      !(await page.content()).includes('action=logout'), '');
    check('no-store', 'the navigation is still there',
      (await page.locator('.tv-nav__link').count()) === 10, '');
    await context.close();
  } finally {
    await browser.close();
  }

  fs.writeFileSync(path.join(DIR, 'measurements.json'), JSON.stringify({ results, failures }, null, 2) + '\n');
  console.log(`\nchecks: ${results.length}  failures: ${failures}`);
  process.exit(failures === 0 ? 0 : 1);
})();
