/*
 * «در دسکتاپ، هنگام اسکرول، منوی همبرگری/کشویی نمایش داده می‌شود و حساب و دسته‌ها را تکرار می‌کند.»
 * فقط می‌سنجد و عکس می‌گیرد؛ نسخه‌ای نمی‌سازد. همان آزمون روی کد فعلی (LABEL=current) و روی پیشنهاد (LABEL=proposed).
 *
 *   LABEL=current|proposed NODE_PATH=/opt/node-tools/node_modules node tests/compact-desktop.mjs
 *
 * دسکتاپ ۱۰۸۰، ۱۲۸۰، ۱۴۴۰، ۱۹۲۰ (بالای صفحه و پس از اسکرول): منوی همبرگری، ردیف دوم، Mega Menu با کلیک و
 * کیبورد، «حساب من» (مهمان: پیوند؛ مدیر: منوی کشویی)، جست‌وجو، سبد، هدر چسبان. تبلت/موبایل ۱۰۲۴، ۷۶۸، ۳۹۰، ۳۲۰:
 * همبرگری، کشوی RTL با حساب و دسته‌ها. خروجی: evidence/compact-desktop/<LABEL>.txt و تصویرها.
 */
import { createRequire } from 'module';
import { execFileSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const HERE = path.dirname(new URL(import.meta.url).pathname);
const LABEL = process.env.LABEL || 'run';
const OUT = path.join(HERE, '..', 'evidence', 'compact-desktop');
fs.mkdirSync(OUT, { recursive: true });
const SITE = 'http://127.0.0.1:8090';
const WP = (...a) => execFileSync('wp', ['--allow-root', '--path=/home/user/wp/core', ...a], { encoding: 'utf8' }).trim();
const results = [];
const lines = [];
const check = (name, ok, detail = '') => results.push(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail ? '  — ' + detail : ''}`);
const PAGE = '/tthh-about/';

WP('eval-file', path.join(HERE, 'fixtures/seed.php'), 'seed');
WP('eval-file', path.join(HERE, 'fixtures/seed.php'), 'configure', 'on'); // فقط محلی: هدر برای مهمان هم دیده شود

const state = (p) => p.evaluate(() => {
  const root = document.querySelector('[data-tth-header]');
  const vis = (e) => !!e && getComputedStyle(e).display !== 'none' && getComputedStyle(e).visibility !== 'hidden' && e.getBoundingClientRect().width > 0;
  const bar = root.querySelector('.tth-bar');
  const nav = root.querySelector('.tth-nav');
  const b = bar.getBoundingClientRect();
  return {
    y: Math.round(scrollY),
    compact: root.classList.contains('is-compact'),
    burger: vis(root.querySelector('.tth-burger')),
    nav: vis(nav),
    cats: vis([...root.querySelectorAll('.tth-cats__btn')].find(vis)),
    seller: vis(root.querySelector('.tth-nav .tth-seller')),
    search: vis(root.querySelector('.tth-top .tth-search__input')) || vis(root.querySelector('.tth-tool--search')),
    account: vis([...root.querySelectorAll('.tth-tool--account')].find(vis)),
    cart: vis(root.querySelector('.tth-tool--cart')),
    barTop: Math.round(b.top), barHeight: Math.round(b.height), barPos: getComputedStyle(bar).position,
    overflowX: document.documentElement.scrollWidth - innerWidth,
    headers: document.querySelectorAll('[data-tth-header]').length,
    // ۰٫۲٫۴: یک ردیف — لوگو، دسته‌ها، جست‌وجو، ابزارها روی یک خط، بی هم‌پوشانی.
    ...(() => {
      const parts = [['logo', root.querySelector('.tth-bar .tth-logo')], ['cats', [...root.querySelectorAll('.tth-cats__btn')].find(vis)],
        ['search', [...root.querySelectorAll('.tth-top .tth-search')].find(vis)], ['tools', root.querySelector('.tth-tools')]]
        .filter(([, e]) => vis(e)).map(([n, e]) => { const r = e.getBoundingClientRect(); return { n, l: r.left, r: r.right, t: r.top, b: r.bottom }; });
      const hit = (a, c) => a.l < c.r - 0.5 && a.r > c.l + 0.5 && a.t < c.b - 0.5 && a.b > c.t + 0.5;
      const overlaps = [];
      for (let i = 0; i < parts.length; i++) for (let j = i + 1; j < parts.length; j++) if (hit(parts[i], parts[j])) overlaps.push(parts[i].n + '×' + parts[j].n);
      const mids = parts.map((x) => (x.t + x.b) / 2);
      const order = [...parts].sort((a, c) => c.r - a.r).map((x) => x.n).join('>');
      const inBar = parts.every((x) => x.t >= b.top - 0.5 && x.b <= b.bottom + 0.5);
      return { overlaps, order, oneRow: mids.length > 0 && Math.max(...mids) - Math.min(...mids) <= 2 && inBar, menuShown: vis(root.querySelector('.tth-nav .tth-menu')) };
    })(),
  };
});
const megaOpen = (p) => p.evaluate(() => { const m = document.querySelector('#tth-mega'); if (!m || m.hidden) return false; const r = m.getBoundingClientRect(); const bar = document.querySelector('[data-tth-header] .tth-bar').getBoundingClientRect(); return r.height > 0 && r.top >= bar.bottom - 1 && r.top < innerHeight && r.left >= 0 && r.right <= innerWidth; });

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
try {
  // ---------------------------------------------------------------- دسکتاپ، مهمان
  for (const w of [1080, 1280, 1440, 1920]) {
    const p = await browser.newPage({ viewport: { width: w, height: 900 } });
    await p.goto(`${SITE}${PAGE}`, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    const top = await state(p);
    const y0 = await p.evaluate(() => document.querySelector('[data-tth-header]').getBoundingClientRect().bottom + scrollY);
    await p.evaluate(() => { document.body.style.minHeight = '4000px'; window.scrollTo(0, 700); });
    await p.waitForTimeout(500);
    const s = await state(p);
    const y1 = await p.evaluate(() => document.querySelector('[data-tth-header]').getBoundingClientRect().bottom + scrollY);
    check(`desktop ${w}: compacting does not move the page content`, Math.abs(y1 - y0) < 1, JSON.stringify([y0, y1]));
    lines.push(`desktop ${w} top: ${JSON.stringify(top)}`, `desktop ${w} scrolled: ${JSON.stringify(s)}`);
    check(`desktop ${w}, top of page: no burger; row 2 (categories + supplier) shown`, !top.burger && top.nav && top.cats && top.seller, JSON.stringify(top));
    check(`desktop ${w}, scrolled: sticky header fixed at the top (compact)`, s.compact && s.barPos === 'fixed' && s.barTop === 0 && s.headers === 1, JSON.stringify(s));
    check(`desktop ${w}, scrolled: no burger (no drawer duplicating account and categories)`, !s.burger, JSON.stringify(s));
    check(`desktop ${w}, scrolled: «دسته‌بندی کالاها» still reachable in the sticky bar`, s.nav && s.cats, JSON.stringify(s));
    check(`desktop ${w}, scrolled: one row, RTL order logo › categories › search › tools, nothing overlaps`, s.oneRow && s.order === 'logo>cats>search>tools' && s.overlaps.length === 0, JSON.stringify(s));
    check(`desktop ${w}, scrolled: sticky bar stays close to its 63px (≤ 66px)`, s.barHeight <= 66, String(s.barHeight));
    check(`desktop ${w}, scrolled: search, «حساب من» and cart still there; no horizontal scroll`, s.search && s.account && s.cart && s.overflowX <= 0, JSON.stringify(s));
    if (s.cats) {
      await p.click('[data-tth-header] .tth-cats__btn.tth-js');
      await p.waitForTimeout(350);
      check(`desktop ${w}, scrolled: click opens the mega menu inside the viewport, page stays put`, (await megaOpen(p)) && (await state(p)).y === s.y);
      await p.keyboard.press('Escape');
      await p.waitForTimeout(200);
      await p.focus('[data-tth-header] .tth-cats__btn.tth-js');
      await p.keyboard.press('Enter');
      await p.waitForTimeout(350);
      check(`desktop ${w}, scrolled: keyboard (Enter) opens it, Escape closes it`, await megaOpen(p) && await (async () => { await p.keyboard.press('Escape'); await p.waitForTimeout(200); return !(await megaOpen(p)); })());
      if (w === 1440) {
        await p.click('[data-tth-header] .tth-cats__btn.tth-js');
        await p.waitForTimeout(350);
        await p.screenshot({ path: path.join(OUT, `${LABEL}-desktop-1440-scrolled-mega.png`), clip: { x: 0, y: 0, width: 1440, height: 560 } });
        await p.keyboard.press('Escape');
      }
    }
    if (w === 1440) {
      await p.screenshot({ path: path.join(OUT, `${LABEL}-desktop-1440-scrolled.png`), clip: { x: 0, y: 0, width: 1440, height: 200 } });
      if (s.burger) {
        await p.click('[data-tth-header] .tth-burger');
        await p.waitForTimeout(400);
        await p.screenshot({ path: path.join(OUT, `${LABEL}-desktop-1440-drawer.png`) });
      }
      // هیستِرِسیس: بازگشت به بالای صفحه ⇒ هدر کامل، بی پرش.
      await p.evaluate(() => window.scrollTo(0, 0));
      await p.waitForTimeout(500);
      const back = await state(p);
      check('desktop 1440: back at the top ⇒ full header again', !back.compact && back.nav && !back.burger, JSON.stringify(back));
    }
    await p.close();
  }

  // ---------------------------------------------------------------- دسکتاپ، مدیر واردشده: منوی «حساب من»
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const a = await ctx.newPage();
  for (let i = 0; i < 3 && !(await ctx.cookies()).some((c) => c.name.startsWith('wordpress_logged_in')); i++) {
    try {
      await a.goto(`${SITE}/wp-login.php`, { timeout: 45000 });
      await a.fill('#user_login', 'tthadmin');
      await a.fill('#user_pass', 'local-only-2026');
      await Promise.all([a.waitForNavigation({ timeout: 45000 }), a.click('#wp-submit')]);
    } catch (e) { /* سرور تک‌رشته‌ای محلی؛ دوباره */ }
  }
  // مدیرِ واردشده: «حساب من» با فلش و نوار مدیریت — پهن‌ترین ابزارها؛ در باریک‌ترین عرض دسکتاپ هم یک ردیف.
  await a.setViewportSize({ width: 1080, height: 900 });
  await a.goto(`${SITE}${PAGE}`, { waitUntil: 'networkidle' });
  await a.evaluate(() => { document.body.style.minHeight = '4000px'; window.scrollTo(0, 700); });
  await a.waitForTimeout(500);
  const la = await state(a);
  lines.push(`logged in 1080 scrolled: ${JSON.stringify(la)}`);
  check('desktop 1080 logged in (admin bar), scrolled: one row, no burger, nothing overlaps, below the admin bar', la.oneRow && !la.burger && la.overlaps.length === 0 && la.cats && la.barTop >= 32 && la.barHeight <= 66 && la.overflowX <= 0, JSON.stringify(la));
  await a.screenshot({ path: path.join(OUT, `${LABEL}-desktop-1080-logged-in-scrolled.png`), clip: { x: 0, y: 0, width: 1080, height: 140 } });
  await a.setViewportSize({ width: 1440, height: 900 });
  await a.goto(`${SITE}${PAGE}`, { waitUntil: 'networkidle' });
  await a.evaluate(() => { document.body.style.minHeight = '4000px'; window.scrollTo(0, 700); });
  await a.waitForTimeout(500);
  await a.click('[data-tth-header] .tth-tool--account.tth-js');
  await a.waitForTimeout(300);
  const acct = await a.evaluate(() => { const m = document.querySelector('#tth-acct-menu'); return !!m && !m.hidden && m.querySelectorAll('a').length; });
  check('desktop 1440 logged in, scrolled: «حساب من» opens the account menu with its links', acct > 0, String(acct));
  await ctx.close();

  // ---------------------------------------------------------------- تبلت و موبایل
  for (const [w, h] of [[1024, 768], [768, 1024], [390, 844], [320, 640]]) {
    const p = await browser.newPage({ viewport: { width: w, height: h }, isMobile: w < 800, hasTouch: w < 800 });
    await p.goto(`${SITE}${PAGE}`, { waitUntil: 'networkidle' });
    const top = await state(p);
    await p.evaluate(() => { document.body.style.minHeight = '4000px'; window.scrollTo(0, 700); });
    await p.waitForTimeout(500);
    const s = await state(p);
    lines.push(`mobile ${w} top: ${JSON.stringify(top)}`, `mobile ${w} scrolled: ${JSON.stringify(s)}`);
    check(`${w}: burger shown at the top and after scrolling; row 2 folded into the drawer`, top.burger && s.burger && !top.nav && !s.nav, JSON.stringify([top, s]));
    check(`${w}: search, account and cart still in the bar; no horizontal scroll`, s.account && s.cart && s.overflowX <= 0, JSON.stringify(s));
    await p.click('[data-tth-header] .tth-burger');
    await p.waitForTimeout(450);
    const d = await p.evaluate(() => {
      const dl = document.querySelector('#tth-drawer');
      const panel = dl && dl.querySelector('.tth-drawer__panel');
      const r = panel ? panel.getBoundingClientRect() : null;
      return { open: !!dl && dl.open, dir: dl ? getComputedStyle(dl).direction : '', right: r ? Math.round(innerWidth - r.right) : -1, cats: dl ? dl.querySelectorAll('.tth-drawer__cat').length : 0, account: !!(dl && dl.querySelector('.tth-drawer__account')) };
    });
    check(`${w}: drawer opens RTL from the right, with account and the real categories`, d.open && d.dir === 'rtl' && d.right === 0 && d.cats > 0 && d.account, JSON.stringify(d));
    if (w === 390) await p.screenshot({ path: path.join(OUT, `${LABEL}-mobile-390-drawer.png`) });
    await p.keyboard.press('Escape');
    await p.close();
  }
} finally {
  await browser.close();
  WP('eval-file', path.join(HERE, 'fixtures/seed.php'), 'seed');
}
const pass = results.filter((r) => r.startsWith('PASS')).length;
const out = `tecteb-header ${WP('plugin', 'get', 'tecteb-header', '--field=version')} — ${LABEL}\n\n${lines.join('\n')}\n\n${results.join('\n')}\n\n${pass} passed, ${results.length - pass} failed\n`;
fs.writeFileSync(path.join(OUT, `${LABEL}.txt`), out);
console.log(results.join('\n') + `\n\n${pass} passed, ${results.length - pass} failed`);
process.exit(pass === results.length ? 0 : 1);
