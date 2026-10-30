/*
 * آزمون محلی دو نمونهٔ هدر — فقط روی سرور ایستای محلی، هیچ سایت واقعی‌ای.
 *
 *   (cd homepage-redesign && python3 -m http.server 8095 --bind 127.0.0.1) &
 *   AXE=<axe.min.js> NODE_PATH=<playwright> node homepage-redesign/header/tests/run.mjs
 *
 * خروجی: header/evidence/ (results.txt، links.json، measurements.json، axe.txt و تصویرها).
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(process.env.NODE_PATH + '/');
const { chromium } = require('playwright');
const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const OUT = process.env.OUT || path.join(ROOT, 'evidence');
const SITE = process.env.SITE || 'http://127.0.0.1:8095/header';
const AXE = process.env.AXE ? fs.readFileSync(process.env.AXE, 'utf8') : '';
fs.mkdirSync(OUT, { recursive: true });

const lines = [];
let pass = 0;
let fail = 0;
const check = (name, ok, detail = '') => {
  if (ok) pass++; else fail++;
  const line = `${ok ? 'PASS' : 'FAIL'} ${name}${detail && !ok ? `  — ${detail}` : ''}`;
  lines.push(line);
  console.log(line);
};
const note = (text) => { lines.push(`NOTE ${text}`); console.log(`NOTE ${text}`); };

const SIZES = [[1440, 900], [1024, 768], [768, 1024], [390, 844], [320, 640]];
const measurements = {};
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

async function open(ctx, file) {
  const page = await ctx.newPage();
  const errors = [];
  const external = [];
  page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('request', (r) => {
    const u = r.url();
    if (!u.startsWith('http://127.0.0.1:') && !u.startsWith('data:')) external.push(u);
  });
  await page.goto(`${SITE}/${file}`, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  return { page, errors, external };
}

// ------------------------------------------------------------------ ۰. ایستا: دامنهٔ CSS
for (const file of ['shared/header-base.css', 'b/header-b.css']) {
  const css = fs.readFileSync(path.join(ROOT, file), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
  const bad = [];
  // هر بلوک قاعده؛ محتوای @keyframes بررسی نمی‌شود.
  const strip = css.replace(/@keyframes[^{]+\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/g, '');
  for (const m of strip.matchAll(/([^{}@;]+)\{[^{}]*\}/g)) {
    const sel = m[1].trim();
    if (!sel || sel.startsWith('from') || sel.startsWith('to')) continue;
    // تقسیم روی ویرگولِ بیرون از پرانتز (:where(p, h2) یک انتخابگر است).
    const parts = []; let depth = 0; let cur = '';
    for (const ch of sel) { if (ch === '(') depth++; if (ch === ')') depth--; if (ch === ',' && depth === 0) { parts.push(cur); cur = ''; } else cur += ch; }
    parts.push(cur);
    for (const s of parts.map((x) => x.trim())) {
      if (!s.startsWith('.tecteb-header-v1')) bad.push(s);
    }
  }
  check(`scope: every selector in ${file} starts with .tecteb-header-v1`, bad.length === 0, bad.slice(0, 4).join(' | '));
  const forbidden = css.match(/(^|[\s,}])(html|body|header|footer)\b(?![-\w])|\.elementor|\.woocommerce|\.dokan|\.tecteb-home-v1|\.th-[a-z]/g);
  check(`scope: ${file} names no global element, Elementor/Woo/Dokan or homepage class`, !forbidden, String(forbidden));
}
const js = fs.readFileSync(path.join(ROOT, 'shared/header.js'), 'utf8');
check('script: header.js has no network call, storage or eval', !/fetch\(|XMLHttpRequest|localStorage|sessionStorage|eval\(|new Function/.test(js));
measurements.bytes = Object.fromEntries(['shared/header-base.css', 'b/header-b.css', 'shared/header.js', 'shared/header.html'].map((f) => [f, fs.statSync(path.join(ROOT, f)).size]));

// ------------------------------------------------------------------ ۱. عرض‌ها
for (const v of ['b']) { // طرح A مرجع است و در reference-a/ منجمد مانده
  for (const kind of ['home', 'inner']) {
    for (const [w, h] of SIZES) {
      const ctx = await browser.newContext({ viewport: { width: w, height: h }, reducedMotion: 'reduce' });
      const { page, errors, external } = await open(ctx, `${v}-${kind}.html`);
      const m = await page.evaluate(() => {
        const root = document.querySelector('.tecteb-header-v1');
        const vis = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden'; };
        const targets = [...root.querySelectorAll('.tth-bar a, .tth-bar button, .tth-bar input[type=search]')].filter(vis).map((el) => {
          const r = el.getBoundingClientRect();
          return { t: (el.getAttribute('aria-label') || el.textContent).trim().slice(0, 24), w: Math.round(r.width), h: Math.round(r.height) };
        });
        const logo = root.querySelector('.tth-top .tth-logo').getBoundingClientRect();
        const search = root.querySelector('.tth-search').getBoundingClientRect();
        const burger = root.querySelector('.tth-burger').getBoundingClientRect();
        const tools = root.querySelector('.tth-tools').getBoundingClientRect();
        const narrow = innerWidth < 1080;
        const searchShown = vis(root.querySelector('.tth-search'));
        const icons = [...root.querySelectorAll('.tth-tool--count')].filter(vis).map((t) => {
          const a = t.querySelector('.tth-i').getBoundingClientRect();
          const b = t.querySelector('.tth-badge').getBoundingClientRect();
          const overlap = Math.max(0, Math.min(a.right, b.right) - Math.max(a.left, b.left)) * Math.max(0, Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top));
          return { overlap, gap: Math.round(Math.max(a.left - b.right, b.left - a.right)), font: parseFloat(getComputedStyle(t.querySelector('.tth-badge')).fontSize), h: Math.round(b.height) };
        });
        const tl = [...root.querySelectorAll('.tth-tools > a, .tth-tools > button, .tth-tools > .tth-acct > :is(a, button)')].filter(vis).map((e) => e.getBoundingClientRect()).sort((x, y) => x.left - y.left);
        const toolGap = tl.slice(1).reduce((g, r, i) => Math.min(g, r.left - tl[i].right), 99);
        return {
          overflow: document.documentElement.scrollWidth - innerWidth,
          headerOut: [...root.querySelectorAll('.tth-bar *')].filter(vis).filter((el) => { const r = el.getBoundingClientRect(); return r.left < -0.5 || r.right > innerWidth + 0.5; }).length,
          height: Math.round(root.querySelector('.tth-bar').getBoundingClientRect().height),
          minTarget: targets.reduce((a, t) => Math.min(a, t.w, t.h), 999),
          small: targets.filter((t) => t.w < 44 || t.h < 44).map((t) => `${t.t}:${t.w}×${t.h}`),
          // RTL: عریض ⇒ لوگو راستِ جست‌وجو؛ باریک ⇒ دکمهٔ منو راست‌ترین، بعد لوگو، ابزارها چپ، جست‌وجو ردیف دوم.
          rtlLogoRight: narrow
            ? burger.right > logo.right && logo.left > tools.right && (!searchShown || search.top >= logo.bottom)
            : logo.right >= search.right - 0.5 && search.right > tools.right,
          dir: getComputedStyle(root).direction,
          routes: document.querySelectorAll('.tecteb-home-v1 .th-route').length,
          navVisible: vis(root.querySelector('.tth-nav')),
          burgerVisible: vis(root.querySelector('.tth-burger')),
          searchShown,
          toggleShown: vis(root.querySelector('.tth-tool--search')),
          icons,
          toolGap: Math.round(toolGap),
        };
      });
      const tag = `${v}-${kind} ${w}px`;
      check(`${tag}: no horizontal overflow, nothing of the header off-screen`, m.overflow <= 0 && m.headerOut === 0, `overflow=${m.overflow} out=${m.headerOut}`);
      check(`${tag}: every header control ≥ 24×24 (WCAG 2.5.8)`, m.minTarget >= 24, String(m.minTarget));
      check(`${tag}: RTL — logo on the right, search beside it`, m.dir === 'rtl' && m.rtlLogoRight);
      check(`${tag}: ${w >= 1080 ? 'menu row shown, no menu button' : 'menu button shown, menu row moved into the drawer'}`, w >= 1080 ? m.navVisible && !m.burgerVisible : !m.navVisible && m.burgerVisible);
      if (kind === 'home') check(`${tag}: homepage 0.2.0 below the header keeps its three routes`, m.routes === 3, String(m.routes));
      const mobileHome = kind === 'home' && w < 768;
      check(`${tag}: ${mobileHome ? 'large header search folded into a search button (Hero search is the main one)' : 'header search box shown, no search button'}`,
        mobileHome ? !m.searchShown && m.toggleShown : m.searchShown && !m.toggleShown, JSON.stringify({ s: m.searchShown, t: m.toggleShown }));
      check(`${tag}: counters sit beside their icons — no overlap, gap ≥ 4px, ≥ 12px text`, m.icons.length > 0 && m.icons.every((i) => i.overlap === 0 && i.gap >= 4 && i.font >= 12 && i.h >= 20), JSON.stringify(m.icons));
      check(`${tag}: header tools keep a clear gap (≥ 4px)`, m.toolGap >= 4, String(m.toolGap));
      if (w === 1440) check(`${tag}: desktop header shorter than before (≤ 124px; round 7 was 136px)`, m.height <= 124, String(m.height));
      check(`${tag}: no console error, no request outside this machine`, errors.length === 0 && external.length === 0, [...errors, ...external].join(' | '));
      if (AXE) {
        // هر عرض و هر دو صفحه، با هدر بسته — دور ۷ فقط ۱۴۴۰ ِ بسته را می‌سنجید و پیوند بی‌نام موبایل از دستش رفت.
        await page.addScriptTag({ content: AXE });
        const ax = await page.evaluate(async () => (await window.axe.run({ include: [['.tecteb-header-v1']] }, { resultTypes: ['violations'] })).violations.map((x) => `${x.id}(${x.impact}):${x.nodes.length}`));
        fs.appendFileSync(path.join(OUT, 'axe.txt'), `${tag} closed: ${ax.join(', ') || 'none'}\n`);
        check(`${tag}: axe on the closed header — 0 violations`, ax.length === 0, ax.join(', '));
      }
      measurements[`${tag}`] = { header_height: m.height, min_target: m.minTarget, below_44: m.small, counters: m.icons, tool_gap: m.toolGap };
      if (kind === 'home' || w === 1440 || w === 390) await page.screenshot({ path: path.join(OUT, `${v}-${kind}-${w}.png`) });
      if (kind === 'home' && [1440, 390, 320].includes(w)) {
        // تصویر تمام‌صفحه: پیش از آن پایین می‌رویم تا تصویرهای lazy بارگذاری شوند، و دوباره بالا.
        await page.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 600) { scrollTo(0, y); await new Promise((r) => setTimeout(r, 40)); } scrollTo(0, 0); });
        await page.waitForTimeout(300);
        await page.screenshot({ path: path.join(OUT, `${v}-home-full-${w}.png`), fullPage: true });
      }
      await ctx.close();
    }
  }
}

// ------------------------------------------------------------------ ۲. پیوندها
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const { page } = await open(ctx, 'b-home.html');
  const links = await page.evaluate(() => [...document.querySelectorAll('.tecteb-header-v1 a[href]')].map((a) => ({
    text: a.textContent.replace(/\s+/g, ' ').trim() || a.getAttribute('aria-label'),
    href: a.getAttribute('href'),
    src: a.dataset.src || '',
  })));
  fs.writeFileSync(path.join(OUT, 'links.json'), JSON.stringify(links, null, 2));
  const counts = links.reduce((a, l) => ({ ...a, [l.src]: (a[l.src] || 0) + 1 }), {});
  check('links: every header link says where it comes from (owner / wc / demo / sample)', links.filter((l) => l.href !== '#main').every((l) => ['owner', 'wc', 'demo', 'sample'].includes(l.src)), JSON.stringify(links.filter((l) => !l.src)));
  const seller = links.filter((l) => l.href === '/landing-seller/').map((l) => l.text);
  check('links: «همکاری تأمین‌کنندگان» → /landing-seller/, in the menu row and the drawer; old title gone', seller.length === 2 && seller.every((t) => t === 'همکاری تأمین‌کنندگان') && !(await page.content()).includes('همکاری فروشندگان'), JSON.stringify(seller));
  const cats = links.filter((l) => /^#cat-/.test(l.href));
  check('links: every category link is marked as sample data (none passes as a real category)', cats.length > 0 && cats.every((l) => l.src === 'sample'), String(cats.length));
  check('links: no empty "#", no javascript: and no absolute production URL', links.every((l) => l.href !== '#' && !/^javascript:/i.test(l.href) && !/tecteb\.com/.test(l.href)));
  const owner = [...new Set(links.filter((l) => l.src === 'owner').map((l) => l.href))].sort();
  check('links: the three owner addresses are the ones in the brief', JSON.stringify(owner) === JSON.stringify(['/https-tecteb-com-repair-request/', '/landing-seller/', '/shop/']), JSON.stringify(owner));
  const menu = await page.locator('.tth-menu a').allTextContents();
  check('links: every current menu item is present, in order', JSON.stringify(menu.map((t) => t.trim())) === JSON.stringify(['صفحه اصلی', 'فروشگاه', 'وبلاگ', 'درخواست تعمیرات', 'درباره تک‌طب', 'تماس با ما']), JSON.stringify(menu));
  note(`links in the header: ${links.length} — owner ${counts.owner || 0}, wc ${counts.wc || 0}, demo ${counts.demo || 0} (نامعلوم؛ در اجرا از منوی وردپرس), sample ${counts.sample || 0} (دسته‌های نمونه؛ در اجرا از product_cat)`);
  await page.click('.tth-menu a[href="/shop/"]');
  const toast = await page.locator('.proto-toast').textContent();
  check('links: a click goes nowhere and says so (no demo link presented as real)', page.url().endsWith('/b-home.html') && toast.includes('نمونهٔ نمایشی'), toast);
  await ctx.close();
}

// ------------------------------------------------------------------ ۳. Mega Menu، حساب، کشو، جمع‌وجور
for (const v of ['b']) { // طرح A مرجع است و در reference-a/ منجمد مانده
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const { page } = await open(ctx, `${v}-home.html`);
    const btn = page.locator('.tth-cats__btn.tth-js');
    await btn.focus();
    await page.keyboard.press('Enter');
    await page.waitForTimeout(250);
    let st = await page.evaluate(() => ({ exp: document.querySelector('.tth-cats__btn.tth-js').getAttribute('aria-expanded'), vis: !document.querySelector('#tth-mega').hidden }));
    check(`${v} mega: Enter opens the panel (aria-expanded=true)`, st.exp === 'true' && st.vis);
    const onTop = await page.evaluate(() => {
      const r = document.querySelector('#tth-mega').getBoundingClientRect();
      const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      return !!el && !!el.closest('#tth-mega');
    });
    check(`${v} mega: panel paints above the homepage Hero (z-order)`, onTop);
    check(`${v} mega: the sample categories say they are samples`, await page.locator('#tth-mega [data-proto-sample]').isVisible());
    await page.screenshot({ path: path.join(OUT, `${v}-mega-1440.png`) });
    if (AXE) {
      await page.addScriptTag({ content: AXE });
      const r = await page.evaluate(async () => (await window.axe.run({ include: [['.tecteb-header-v1']] }, { resultTypes: ['violations'] })).violations.map((x) => `${x.id}(${x.impact}):${x.nodes.length}`));
      fs.appendFileSync(path.join(OUT, 'axe.txt'), `${v} mega open 1440: ${r.join(', ') || 'none'}\n`);
      check(`${v} axe: header with mega menu open — 0 violations`, r.length === 0, r.join(', '));
    }
    await page.keyboard.press('Tab');
    const inPanel = await page.evaluate(() => !!document.activeElement.closest('#tth-mega'));
    check(`${v} mega: Tab moves into the panel`, inPanel);
    await page.keyboard.press('Escape');
    st = await page.evaluate(() => ({ exp: document.querySelector('.tth-cats__btn.tth-js').getAttribute('aria-expanded'), vis: !document.querySelector('#tth-mega').hidden, focus: document.activeElement.classList.contains('tth-cats__btn') }));
    check(`${v} mega: Escape closes and returns focus to the button`, st.exp === 'false' && !st.vis && st.focus);
    await page.mouse.move(10, 600);
    await page.hover('.tth-cats__btn.tth-js');
    await page.waitForTimeout(400);
    const hovered = await page.evaluate(() => !document.querySelector('#tth-mega').hidden);
    await page.mouse.move(1300, 60);
    await page.waitForTimeout(500);
    const left = await page.evaluate(() => document.querySelector('#tth-mega').hidden);
    check(`${v} mega: mouse hover opens after a short delay and leaving closes it`, hovered && left);

    // حساب کاربری
    const guest = await page.evaluate(() => ({ guest: document.querySelector('.tth-tools [data-tth-guest]').offsetParent !== null, member: document.querySelector('.tth-tools [data-tth-member]').hidden }));
    check(`${v} account: guest sees «ورود / ثبت‌نام», not the member menu`, guest.guest && guest.member);
    await page.click('[data-proto-user="member"]');
    await page.click('.tth-acct button[data-tth-toggle]');
    await page.waitForTimeout(250);
    const acct = await page.evaluate(() => ({ open: !document.querySelector('#tth-acct-menu').hidden, items: [...document.querySelectorAll('#tth-acct-menu a')].map((a) => a.textContent.trim()) }));
    check(`${v} account: logged-in menu opens with account, orders, addresses, details and logout`, acct.open && acct.items.length === 5 && acct.items.at(-1) === 'خروج', JSON.stringify(acct.items));
    await page.screenshot({ path: path.join(OUT, `${v}-account-1440.png`) });
    await page.mouse.click(40, 320); // زمینهٔ خالی Hero — نه کادر جست‌وجو، نه پیوند
    check(`${v} account: a click outside closes it`, await page.evaluate(() => document.querySelector('#tth-acct-menu').hidden));

    // جمع‌وجور، بدون جابه‌جایی محتوا
    const before = await page.evaluate(() => ({ reserve: document.querySelector('.tecteb-header-v1').getBoundingClientRect().height, main: document.querySelector('#main').getBoundingClientRect().top + scrollY }));
    await page.evaluate(() => { window.__cls = 0; new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: false }); });
    for (const y of [200, 600, 1200]) { await page.evaluate((yy) => scrollTo(0, yy), y); await page.waitForTimeout(120); }
    await page.waitForTimeout(400);
    const after = await page.evaluate(() => {
      const root = document.querySelector('.tecteb-header-v1');
      const bar = root.querySelector('.tth-bar').getBoundingClientRect();
      const hit = document.elementFromPoint(innerWidth / 2, bar.top + bar.height / 2);
      return { compact: root.classList.contains('is-compact'), top: bar.top, h: bar.height, main: document.querySelector('#main').getBoundingClientRect().top + scrollY, cls: window.__cls, onTop: !!hit && !!hit.closest('.tth-bar'), nav: getComputedStyle(root.querySelector('.tth-nav')).display, burger: getComputedStyle(root.querySelector('.tth-burger')).display };
    });
    check(`${v} sticky: after scrolling the bar is fixed at the top, compact and above the content`, after.compact && Math.abs(after.top) < 1 && after.h < before.reserve && after.onTop, JSON.stringify(after));
    check(`${v} sticky: compact desktop folds the menu row into the menu button`, after.nav === 'none' && after.burger !== 'none');
    check(`${v} sticky: no layout shift — content under the header did not move (CLS 0)`, Math.abs(after.main - before.main) < 0.5 && after.cls === 0, `main ${before.main}→${after.main} cls=${after.cls}`);
    measurements[`${v} sticky 1440`] = { full: Math.round(before.reserve), compact: Math.round(after.h) };
    await page.screenshot({ path: path.join(OUT, `${v}-compact-1440.png`) });
    await page.evaluate(() => scrollTo(0, 0));
    await page.waitForTimeout(300);
    check(`${v} sticky: back at the top the full header returns`, await page.evaluate(() => !document.querySelector('.tecteb-header-v1').classList.contains('is-compact')));
    await ctx.close();
  }

  for (const [w, h] of [[390, 844], [320, 640]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, hasTouch: true, isMobile: true });
    const { page } = await open(ctx, `${v}-home.html`);
    await page.click('.tth-burger');
    await page.waitForTimeout(350);
    let st = await page.evaluate(() => ({ open: document.querySelector('#tth-drawer').open, inside: !!document.activeElement.closest('#tth-drawer'), exp: document.querySelector('.tth-burger').getAttribute('aria-expanded') }));
    check(`${v} drawer ${w}px: menu button opens the drawer and moves focus into it`, st.open && st.inside && st.exp === 'true', JSON.stringify(st));
    const fits = await page.evaluate(() => { const r = document.querySelector('.tth-drawer').getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth + 0.5 && r.right > innerWidth - 2; });
    check(`${v} drawer ${w}px: opens from the right (RTL) and fits the screen`, fits);
    const items = await page.evaluate(() => ({ menu: document.querySelectorAll('#tth-drawer nav a').length, cats: document.querySelectorAll('#tth-drawer .tth-drawer__cat').length, acct: !!document.querySelector('#tth-drawer [data-tth-guest]'), seller: !!document.querySelector('#tth-drawer .tth-seller') }));
    check(`${v} drawer ${w}px: menu, wishlist, categories, account and seller link all reachable`, items.menu === 7 && items.cats === 6 && items.acct && items.seller, JSON.stringify(items));
    check(`${v} drawer ${w}px: sample categories labelled as samples`, await page.locator('#tth-drawer [data-proto-sample]').isVisible());
    await page.screenshot({ path: path.join(OUT, `${v}-drawer-${w}.png`) });
    await page.click('.tth-drawer__cat:first-of-type > summary');
    check(`${v} drawer ${w}px: a category opens its sub-categories`, await page.evaluate(() => document.querySelector('.tth-drawer__cat').open));
    if (w === 390) await page.screenshot({ path: path.join(OUT, `${v}-drawer-cats-${w}.png`) });
    let trapped = true;
    for (let i = 0; i < 30; i++) { await page.keyboard.press('Tab'); if (!(await page.evaluate(() => !!document.activeElement.closest('#tth-drawer') || document.activeElement === document.body))) { trapped = false; break; } }
    check(`${v} drawer ${w}px: Tab stays inside the open drawer (page behind is inert)`, trapped);
    if (AXE && w === 390) {
      await page.addScriptTag({ content: AXE });
      const r = await page.evaluate(async () => (await window.axe.run({ include: [['.tecteb-header-v1']] }, { resultTypes: ['violations'] })).violations.map((x) => `${x.id}(${x.impact}):${x.nodes.length}`));
      fs.appendFileSync(path.join(OUT, 'axe.txt'), `${v} drawer open 390: ${r.join(', ') || 'none'}\n`);
      check(`${v} axe: drawer open — 0 violations`, r.length === 0, r.join(', '));
    }
    await page.keyboard.press('Escape');
    await page.waitForTimeout(200);
    st = await page.evaluate(() => ({ open: document.querySelector('#tth-drawer').open, focus: document.activeElement.classList.contains('tth-burger'), exp: document.querySelector('.tth-burger').getAttribute('aria-expanded') }));
    check(`${v} drawer ${w}px: Escape closes and returns focus to the menu button`, !st.open && st.focus && st.exp === 'false', JSON.stringify(st));
    const before = await page.evaluate(() => document.querySelector('#main').getBoundingClientRect().top + scrollY);
    await page.evaluate(() => scrollTo(0, 900));
    await page.waitForTimeout(400);
    const after = await page.evaluate(() => { const b = document.querySelector('.tth-bar').getBoundingClientRect(); return { compact: document.querySelector('.tecteb-header-v1').classList.contains('is-compact'), top: b.top, h: Math.round(b.height), main: document.querySelector('#main').getBoundingClientRect().top + scrollY }; });
    check(`${v} sticky ${w}px: compact bar fixed at top (search still reachable); content did not move`, after.compact && Math.abs(after.top) < 1 && Math.abs(after.main - before) < 0.5, JSON.stringify(after));
    measurements[`${v} sticky ${w}`] = { compact: after.h };
    await page.screenshot({ path: path.join(OUT, `${v}-compact-${w}.png`) });
    await ctx.close();
  }

  // جست‌وجو در موبایلِ صفحهٔ اصلی: دکمه همان فرم را باز می‌کند، با کیبورد، بی جابه‌جایی صفحه
  for (const [w, h] of [[390, 844], [320, 640]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, hasTouch: true, isMobile: true });
    const { page } = await open(ctx, `${v}-home.html`);
    const mainBefore = await page.evaluate(() => document.querySelector('#main').getBoundingClientRect().top);
    await page.locator('.tth-tool--search').focus();
    await page.keyboard.press('Enter');
    await page.waitForTimeout(250);
    let st = await page.evaluate(() => {
      const f = document.querySelector('#tth-search-form');
      const r = f.getBoundingClientRect();
      return { shown: r.width > 0 && r.height > 0, focus: document.activeElement === f.querySelector('input[type=search]'), exp: document.querySelector('.tth-tool--search').getAttribute('aria-expanded'), inside: r.left >= 0 && r.right <= innerWidth, main: document.querySelector('#main').getBoundingClientRect().top, action: f.getAttribute('action'), pt: f.querySelector('input[name=post_type]').value };
    });
    check(`${v} home search ${w}px: the search button opens the header search and focuses it (keyboard)`, st.shown && st.focus && st.exp === 'true' && st.inside, JSON.stringify(st));
    check(`${v} home search ${w}px: opening it moves no content (it overlays)`, Math.abs(st.main - mainBefore) < 0.5, `${mainBefore}→${st.main}`);
    check(`${v} home search ${w}px: still the WooCommerce product search (action /, post_type=product)`, st.action === '/' && st.pt === 'product');
    await page.screenshot({ path: path.join(OUT, `${v}-home-search-open-${w}.png`) });
    await page.keyboard.type('پالس');
    await page.keyboard.press('Enter');
    const toast = await page.locator('.proto-toast').textContent();
    check(`${v} home search ${w}px: submitting sends the WooCommerce search`, toast.includes('پالس') && toast.includes('post_type=product'), toast);
    await page.locator('#tth-s').focus();
    await page.keyboard.press('Escape');
    st = await page.evaluate(() => ({ shown: document.querySelector('#tth-search-form').getBoundingClientRect().height > 0, focus: document.activeElement.classList.contains('tth-tool--search'), exp: document.querySelector('.tth-tool--search').getAttribute('aria-expanded') }));
    check(`${v} home search ${w}px: Escape closes it and returns focus to the button`, !st.shown && st.focus && st.exp === 'false', JSON.stringify(st));
    if (AXE && w === 390) {
      await page.click('.tth-tool--search');
      await page.addScriptTag({ content: AXE });
      const r = await page.evaluate(async () => (await window.axe.run({ include: [['.tecteb-header-v1']] }, { resultTypes: ['violations'] })).violations.map((x) => `${x.id}(${x.impact}):${x.nodes.length}`));
      fs.appendFileSync(path.join(OUT, 'axe.txt'), `${v} home search open 390: ${r.join(', ') || 'none'}\n`);
      check(`${v} axe: homepage mobile with search open — 0 violations`, r.length === 0, r.join(', '));
    }
    await ctx.close();
  }

  // کاهش حرکت
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
    const { page } = await open(ctx, `${v}-home.html`);
    await page.click('.tth-cats__btn.tth-js');
    await page.waitForTimeout(100);
    const r = await page.evaluate(() => ({
      anims: document.getAnimations().filter((a) => a.effect?.target?.closest?.('.tecteb-header-v1')).length,
      transition: getComputedStyle(document.querySelector('.tth-menu a')).transitionDuration,
    }));
    check(`${v} reduced motion: no animation or transition in the header`, r.anims === 0 && /^0s(, 0s)*$/.test(r.transition), JSON.stringify(r));
    await ctx.close();
  }

  // بی جاوااسکریپت
  for (const [w, h] of [[1440, 900], [390, 844]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, javaScriptEnabled: false });
    const { page } = await open(ctx, `${v}-home.html`);
    const r = await page.evaluate(() => {
      const vis = (s) => { const el = document.querySelector(s); const b = el.getBoundingClientRect(); return b.width > 0 && b.height > 0; };
      return { nav: vis('.tth-menu'), cats: vis('.tth-cats__btn.tth-nojs'), burger: vis('.tth-burger'), seller: vis('.tth-nav .tth-seller'), search: vis('.tth-search'), toggle: vis('.tth-tool--search'), overflow: document.documentElement.scrollWidth - innerWidth };
    });
    check(`${v} no JavaScript ${w}px: menu, categories, seller link and the search box all visible; no dead button`, r.nav && r.cats && r.seller && r.search && !r.toggle && !r.burger && r.overflow <= 0, JSON.stringify(r));
    if (w === 390) await page.screenshot({ path: path.join(OUT, `${v}-nojs-${w}.png`) });
    await ctx.close();
  }

  // axe در حالت بسته، و جداسازی CSS. کاهش حرکت روشن است، چون انیمیشن ورود صفحهٔ اصلی میان دو
  // اندازه‌گیری پیش می‌رود و تفاوتی می‌سازد که از هدر نیست (اندازه‌گیری‌شده: ۷ عنصر th-in).
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
    const { page } = await open(ctx, `${v}-home.html`);
    if (AXE) {
      await page.addScriptTag({ content: AXE });
      const r = await page.evaluate(async () => (await window.axe.run({ include: [['.tecteb-header-v1']] }, { resultTypes: ['violations'] })).violations.map((x) => `${x.id}(${x.impact}):${x.nodes.length}`));
      fs.appendFileSync(path.join(OUT, 'axe.txt'), `${v} closed 1440: ${r.join(', ') || 'none'}\n`);
      check(`${v} axe: header (closed) — 0 violations, including colour contrast`, r.length === 0, r.join(', '));
    }
    const ph = await page.evaluate(() => {
      const lum = (c) => { const [r, g, b] = c.match(/[\d.]+/g).slice(0, 3).map(Number).map((x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
      const fg = getComputedStyle(document.querySelector('.tth-search__input'), '::placeholder').color;
      // زمینهٔ کادر جست‌وجو روی زمینهٔ نوار: رنگ نیمه‌شفاف با رنگ زیرین ترکیب می‌شود.
      const mix = (top, base) => { const t = top.match(/[\d.]+/g).map(Number); const b = base.match(/[\d.]+/g).map(Number); const a = t[3] ?? 1; return `rgb(${[0, 1, 2].map((i) => Math.round(t[i] * a + b[i] * (1 - a))).join(',')})`; };
      const bar = getComputedStyle(document.querySelector('.tecteb-header-v1')).getPropertyValue('--tth-bg-solid').trim();
      const tmp = document.createElement('i'); tmp.style.color = bar; document.body.append(tmp); const barRgb = getComputedStyle(tmp).color; tmp.remove();
      const bg = mix(getComputedStyle(document.querySelector('.tth-search')).backgroundColor, barRgb);
      const [l1, l2] = [lum(fg), lum(bg)].sort((x, y) => y - x);
      return Math.round(((l1 + 0.05) / (l2 + 0.05)) * 100) / 100;
    });
    check(`${v} contrast: search placeholder ≥ 4.5:1 (axe does not check placeholders)`, ph >= 4.5, String(ph));
    measurements[`${v} placeholder contrast`] = ph;

    const snap = () => page.evaluate(() => {
      const props = ['color', 'background-color', 'background-image', 'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'margin-top', 'margin-bottom', 'padding-top', 'padding-inline-start', 'border-top-width', 'border-radius', 'display', 'position', 'z-index', 'box-shadow', 'text-decoration-line', 'opacity', 'transform', 'width'];
      const out = [];
      document.querySelectorAll('.tecteb-home-v1, .tecteb-home-v1 *, .proto-slot--footer').forEach((el, i) => {
        const cs = getComputedStyle(el);
        out.push(props.map((p) => cs.getPropertyValue(p)).join('|') + '#' + i);
      });
      return out;
    });
    const withHeader = await snap();
    await page.evaluate(() => document.querySelectorAll('link[href*="header-"]').forEach((l) => l.remove()));
    await page.waitForTimeout(150);
    const without = await snap();
    const diffs = withHeader.filter((s, i) => s !== without[i]).length;
    check(`${v} isolation: header CSS changes no style of the homepage or the footer slot (${withHeader.length} elements)`, diffs === 0 && withHeader.length === without.length, `${diffs} differ`);
    await ctx.close();
  }
}

await browser.close();
fs.writeFileSync(path.join(OUT, 'measurements.json'), JSON.stringify(measurements, null, 2));
lines.push('', `${pass} passed, ${fail} failed`);
fs.writeFileSync(path.join(OUT, 'results.txt'), lines.join('\n') + '\n');
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
