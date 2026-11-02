/*
 * ۰٫۳٫۳ — برچسب Hero، چهار برچسب دسته، قالب قیمت و کاروسل برندها، در مرورگر.
 *
 *   SITE=… WP="wp --allow-root --path=…" AXE=<axe.min.js> NODE_PATH=… OUT=tests/evidence/hero-brands \
 *   node tests/browser/hero-brands.mjs
 *
 * پیش‌نیاز: header seed + configure on، tests/fixtures/seed.php seed، state.php full. این اسکریپت خودش
 * hero-brands.php labels/brands، prices.php seed و format staging را می‌چیند و در پایان پس می‌دهد.
 */
import { createRequire } from 'module';
import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const HERE = path.dirname(new URL(import.meta.url).pathname);
const PLUGIN = path.resolve(HERE, '../..');
const SITE = process.env.SITE || 'http://127.0.0.1:8090';
const WP = process.env.WP || 'wp --allow-root --path=/home/user/wp/core';
const AXE = process.env.AXE ? fs.readFileSync(process.env.AXE, 'utf8') : '';
const OUT = process.env.OUT || path.join(PLUGIN, 'tests/evidence/hero-brands');
fs.mkdirSync(OUT, { recursive: true });
// فقط فرمان‌های ثابتِ wp-cli روی وردپرس یکبارمصرف محلی؛ هیچ ورودی بیرونی.
const wp = (cmd) => execSync(`${WP} ${cmd} 2>/dev/null`, { cwd: PLUGIN }).toString().trim();
const results = [];
const check = (name, ok, detail = '') => results.push(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail ? '  — ' + detail : ''}`);
const LABELS = ['پزشکی جنرال', 'بیهوشی و تنفسی', 'آزمایشگاه', 'جراحی'];
// منطقه‌هایی از رندر که برچسب نباید رویشان بیفتد، به نسبت جعبهٔ تصویر (از تصویرهای ۷۶۸ و ۱۴۴۰ تخمین زده شده):
// صفحه و بدنهٔ نمایشگر وسط، و پالس‌اکسیمتر پایین چپ. تخمین است، نه اندازهٔ دقیق پیکسل‌های تصویر.
const KEEP_OUT = { monitor: [0.34, 0.22, 0.78, 0.8], oximeter: [0.06, 0.71, 0.25, 0.86] };

wp('eval-file tests/fixtures/hero-brands.php labels');
wp('eval-file tests/fixtures/hero-brands.php brands 8');
wp('eval-file tests/fixtures/prices.php seed');
wp('eval-file tests/fixtures/prices.php format staging');
wp('eval-file tests/fixtures/state.php front');

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const scrollAll = async (p) => { for (let y = 0; y < await p.evaluate(() => document.body.scrollHeight); y += 400) { await p.evaluate((v) => window.scrollTo(0, v), y); await p.waitForTimeout(30); } await p.evaluate(() => window.scrollTo(0, 0)); };
try {
  for (const [w, h] of [[1440, 900], [1024, 800], [768, 1024], [390, 844], [320, 700]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, reducedMotion: 'reduce' });
    const p = await ctx.newPage();
    await p.goto(`${SITE}/`, { waitUntil: 'networkidle' });
    await scrollAll(p);
    const r = await p.evaluate(({ KEEP_OUT }) => {
      const box = (e) => { const b = e.getBoundingClientRect(); return { l: b.left, t: b.top + scrollY, r: b.right, b: b.bottom + scrollY }; };
      const hit = (a, b, pad = 0) => a.l < b.r - pad && b.l < a.r - pad && a.t < b.b - pad && b.t < a.b - pad;
      const hero = document.querySelector('.th-hero');
      const copy = document.querySelector('.th-hero__copy');
      const title = document.querySelector('#th-hero-title');
      const img = box(document.querySelector('.th-hero__render img'));
      const zone = (z) => ({ l: img.l + (img.r - img.l) * z[0], t: img.t + (img.b - img.t) * z[1], r: img.l + (img.r - img.l) * z[2], b: img.t + (img.b - img.t) * z[3] });
      const labels = [...document.querySelectorAll('.th-floats a')];
      const lb = labels.map(box);
      const others = [...copy.querySelectorAll('h1, p, form, .th-quick')].map(box).concat([...document.querySelectorAll('.th-route')].map(box));
      return {
        pill: !!document.querySelector('.th-pill') || document.body.textContent.includes('بازار B2B'),
        firstInCopy: copy.firstElementChild === title,
        titleGap: Math.round(title.getBoundingClientRect().top - hero.getBoundingClientRect().top - parseFloat(getComputedStyle(hero).paddingTop)),
        names: labels.map((a) => a.textContent.trim()),
        hrefs: labels.map((a) => a.href),
        compact: labels.length ? getComputedStyle(labels[0]).position === 'static' : null,
        overlapEach: lb.some((a, i) => lb.some((b, j) => j > i && hit(a, b))),
        overlapCopy: lb.some((a) => others.some((o) => hit(a, o))),
        overMonitor: lb.some((a) => hit(a, zone(KEEP_OUT.monitor))),
        overOximeter: lb.some((a) => hit(a, zone(KEEP_OUT.oximeter))),
        inViewport: lb.every((a) => a.l >= 0 && a.r <= innerWidth),
        overflow: document.documentElement.scrollWidth - innerWidth,
      };
    }, { KEEP_OUT });
    check(`${w} hero: no «بازار B2B تجهیزات پزشکی» label in the page`, !r.pill);
    check(`${w} hero: the title is the first thing in the hero text, with no gap left by the label`, r.firstInCopy && Math.abs(r.titleGap) <= 2, JSON.stringify(r.titleGap));
    check(`${w} labels: exactly the four, in order`, JSON.stringify(r.names) === JSON.stringify(LABELS), JSON.stringify(r.names));
    check(`${w} labels: no overlap with each other, the hero text/search or the route cards`, !r.overlapEach && !r.overlapCopy, JSON.stringify(r));
    if (!r.compact) check(`${w} labels: off the monitor and the oximeter (estimated zones of the render)`, !r.overMonitor && !r.overOximeter, JSON.stringify(r));
    check(`${w} labels: inside the viewport, no horizontal page overflow`, r.inViewport && r.overflow <= 0, JSON.stringify(r));
    if (w === 1440 || w === 390) check(`${w} labels: layout ${w === 1440 ? 'around the image' : 'compact row under the image'}`, w === 1440 ? r.compact === false : r.compact === true);

    // قیمت: قالب Staging (بی جداکننده، بی فاصله) ⇒ در صفحهٔ اصلی «۵,۶۳۴,۴۰۰ تومان».
    const prices = await p.evaluate(() => [...document.querySelectorAll('.th-price .woocommerce-Price-amount')].map((a) => a.textContent.replace(/\s+/g, ' ').trim()));
    check(`${w} price: every amount has comma thousands and a space before «تومان»`, prices.length >= 10 && prices.every((t) => /^\d{1,3}(,\d{3})* تومان$/.test(t)), JSON.stringify(prices.slice(0, 4)));
    const visible = await p.evaluate(() => document.querySelector('.th-products').innerText);
    check(`${w} price: no unformatted number, no English text`, !/\d{4,}/.test(visible.replace(/,/g, ',')) && !/Price range|through|Original price|Current price/.test(visible));

    // برندها — لوگوها lazy اند و فقط با رسیدن به دید بارگذاری می‌شوند؛ پس اول کل فهرست پیموده می‌شود.
    if (await p.$('[data-th-brands]')) {
      await p.locator('.th-section--brands').scrollIntoViewIfNeeded();
      for (let i = 0; i < 12; i++) { await p.evaluate(() => { const l = document.querySelector('[data-th-brands]'); l.scrollBy({ left: -l.clientWidth }); }); await p.waitForTimeout(120); }
      await p.waitForFunction(() => [...document.querySelectorAll('.th-brand__img')].every((i) => i.complete && i.naturalWidth > 0), null, { timeout: 5000 }).catch(() => {});
      await p.evaluate(() => document.querySelector('[data-th-brands]').scrollTo({ left: 0 }));
      await p.waitForTimeout(150);
    }
    const b = await p.evaluate(() => {
      const sec = document.querySelector('.th-section--brands');
      if (!sec) return null;
      const list = sec.querySelector('[data-th-brands]');
      const root = sec.closest('.tecteb-home-v1');
      const footer = document.querySelector('[data-elementor-type="footer"], #site-footer, footer');
      const imgs = [...sec.querySelectorAll('.th-brand__img')].map((i) => {
        const ib = i.getBoundingClientRect(); const fb = i.closest('.th-brand').getBoundingClientRect();
        // نسبت واقعی فایل از width/height ِ خودِ وردپرس؛ naturalWidth با srcset بر چگالی تقسیم و گرد می‌شود.
        return { ratio: (ib.width / ib.height) / (Number(i.getAttribute('width')) / Number(i.getAttribute('height'))), inside: ib.left >= fb.left - 0.5 && ib.right <= fb.right + 0.5 && ib.top >= fb.top - 0.5 && ib.bottom <= fb.bottom + 0.5, loaded: i.complete && i.naturalWidth > 0 };
      });
      return {
        last: root.lastElementChild === sec,
        afterSupplier: sec.previousElementSibling && sec.previousElementSibling.matches('.th-section--join'),
        beforeFooter: !footer || !!(sec.compareDocumentPosition(footer) & Node.DOCUMENT_POSITION_FOLLOWING),
        count: imgs.length,
        imgs,
        overflow: list.scrollWidth - list.clientWidth,
        navHidden: sec.querySelector('[data-th-brands-nav]').hidden,
        title: sec.querySelector('h2').textContent.trim(),
      };
    });
    check(`${w} brands: section «برندهای همکار» right after the supplier section, last in the homepage, before the footer`, b && b.title === 'برندهای همکار' && b.last && b.afterSupplier && b.beforeFooter, JSON.stringify(b && { last: b.last, after: b.afterSupplier, footer: b.beforeFooter }));
    check(`${w} brands: 7 active brands (1 of 8 is inactive), all logos loaded`, b && b.count === 7 && b.imgs.every((i) => i.loaded), JSON.stringify(b && b.imgs));
    check(`${w} brands: no logo stretched (aspect within 2%) or cropped (inside its frame)`, b && b.imgs.every((i) => Math.abs(i.ratio - 1) < 0.02 && i.inside), JSON.stringify(b && b.imgs));
    check(`${w} brands: prev/next shown only when the list overflows`, b && (b.overflow > 1 ? !b.navHidden : b.navHidden), JSON.stringify(b && { o: b.overflow, h: b.navHidden }));
    if (b && b.overflow > 1) {
      const st = () => p.evaluate(() => { const l = document.querySelector('[data-th-brands]'); const [pr, nx] = document.querySelectorAll('[data-th-brands-dir]'); return { x: l.scrollLeft, prev: pr.disabled, next: nx.disabled }; });
      const s0 = await st();
      await p.click('.th-brands__btn--next');
      await p.waitForTimeout(400);
      const s1 = await st();
      check(`${w} brands: at the start «قبلی» is disabled; «بعدی» moves the list forward (RTL: leftwards)`, s0.prev && !s0.next && s1.x < s0.x && !s1.prev, JSON.stringify([s0, s1]));
      for (let i = 0; i < 12 && !(await st()).next; i++) { await p.click('.th-brands__btn--next'); await p.waitForTimeout(250); }
      const s2 = await st();
      check(`${w} brands: at the end «بعدی» is disabled`, s2.next && !s2.prev, JSON.stringify(s2));
      await p.focus('.th-brands__btn--prev');
      await p.keyboard.press('Enter');
      await p.waitForTimeout(400);
      check(`${w} brands: keyboard — Enter on «قبلی» moves back`, (await st()).x > s2.x);
      await p.evaluate(() => document.querySelector('[data-th-brands]').scrollTo({ left: 0 }));
      await p.focus('[data-th-brands]');
      await p.keyboard.press('ArrowLeft');
      await p.waitForTimeout(300);
      check(`${w} brands: keyboard — the list itself is focusable and scrolls with the arrow keys`, (await st()).x < 0);
    }
    if (AXE) {
      await p.addScriptTag({ content: AXE });
      const v = await p.evaluate(async () => (await axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'], rules: { region: { enabled: false } } })).violations.map((x) => x.id + ':' + x.nodes.map((n) => n.target.join(' ')).slice(0, 2).join('|')));
      check(`${w} axe on the whole page — 0 violations`, v.length === 0, v.join(','));
    }
    await p.evaluate(() => window.scrollTo(0, 0));
    const clip = async (sel) => p.evaluate((q) => { const e = document.querySelector(q); if (!e) return null; const r = e.getBoundingClientRect(); return { x: 0, y: r.top + scrollY, width: innerWidth, height: r.height }; }, sel);
    await p.screenshot({ path: path.join(OUT, `hero-${w}.png`), fullPage: true, clip: await clip('.th-hero') });
    const bc = await clip('.th-section--brands');
    if (bc) await p.screenshot({ path: path.join(OUT, `brands-${w}.png`), fullPage: true, clip: bc });
    const pc = await clip('section[aria-labelledby="th-products-title"]');
    if (pc && (w === 1440 || w === 390)) await p.screenshot({ path: path.join(OUT, `products-${w}.png`), fullPage: true, clip: pc });
    await ctx.close();
  }

  // لمس: کشیدن انگشت روی فهرست (رویدادهای لمسیِ واقعی ِ CDP، در زمینهٔ لمسی).
  for (const w of [390, 768]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, hasTouch: true, isMobile: true, reducedMotion: 'reduce' });
    const p = await ctx.newPage();
    await p.goto(`${SITE}/`, { waitUntil: 'networkidle' });
    await p.locator('.th-section--brands').scrollIntoViewIfNeeded();
    await p.waitForTimeout(200);
    const cdp = await ctx.newCDPSession(p);
    const lb = await p.evaluate(() => { const r = document.querySelector('[data-th-brands]').getBoundingClientRect(); return { x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2) }; });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: lb.x - 80, y: lb.y }] });
    for (let i = 1; i <= 10; i++) await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: lb.x - 80 + i * 20, y: lb.y }] });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await p.waitForTimeout(500);
    const t = await p.evaluate(() => ({ x: document.querySelector('[data-th-brands]').scrollLeft, prev: document.querySelector('.th-brands__btn--prev').disabled, y: scrollY }));
    check(`${w} brands: touch — dragging a finger across the list scrolls it (and the prev button wakes up)`, t.x < 0 && !t.prev, JSON.stringify(t));
    await ctx.close();
  }

  // پیوند هر برچسب: صفحهٔ آرشیو همان دستهٔ ووکامرس، ۲۰۰.
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const p = await ctx.newPage();
    await p.goto(`${SITE}/`, { waitUntil: 'networkidle' });
    const hrefs = await p.evaluate(() => [...document.querySelectorAll('.th-floats a')].map((a) => a.href));
    for (const [i, href] of hrefs.entries()) {
      const res = await p.goto(href, { waitUntil: 'domcontentloaded' });
      const ok = await p.evaluate((name) => document.body.classList.contains('tax-product_cat') && (document.querySelector('h1')?.textContent || '').includes(name), LABELS[i]);
      check(`label «${LABELS[i]}» ⇒ its WooCommerce category archive (200)`, res.status() === 200 && ok, `${res.status()} ${href}`);
    }
    // صفحهٔ محصول قالب خودِ سایت را نگه می‌دارد (اصلاح فقط در صفحهٔ اصلی است).
    const pid = wp('eval "echo get_option(\'tth_price_fixture_ids\')[\'simple\'];"');
    await p.goto(`${SITE}/?p=${pid}`, { waitUntil: 'domcontentloaded' });
    const own = await p.evaluate(() => document.querySelector('.summary .woocommerce-Price-amount, .price .woocommerce-Price-amount')?.textContent.replace(/\s+/g, ' ').trim());
    check('product page keeps the site\'s own price format (not changed globally)', own === '45000000تومان', own);
    await ctx.close();
  }

  // بی جاوااسکریپت: دکمه‌ها پنهان، فهرست همچنان پیمایش‌پذیر.
  {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, javaScriptEnabled: false });
    const p = await ctx.newPage();
    await p.goto(`${SITE}/`, { waitUntil: 'load' });
    const n = await p.evaluate(() => { const s = document.querySelector('.th-section--brands'); const l = s.querySelector('[data-th-brands]'); return { hidden: s.querySelector('[data-th-brands-nav]').hidden && getComputedStyle(s.querySelector('[data-th-brands-nav]')).display === 'none', scroll: getComputedStyle(l).overflowX, more: l.scrollWidth > l.clientWidth }; });
    check('no JavaScript: no dead buttons, the list still scrolls natively', n.hidden && n.scroll === 'auto' && n.more, JSON.stringify(n));
    await ctx.close();
  }

  // بی برند ⇒ بی بخش.
  wp('eval-file tests/fixtures/hero-brands.php no-brands');
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const p = await ctx.newPage();
    await p.goto(`${SITE}/`, { waitUntil: 'networkidle' });
    const e = await p.evaluate(() => ({ sec: !!document.querySelector('.th-section--brands'), text: document.body.textContent.includes('برندهای همکار'), last: document.querySelector('.tecteb-home-v1').lastElementChild.className }));
    check('no active brand ⇒ the whole section is gone (no heading, no empty space)', !e.sec && !e.text && e.last.includes('th-section--join'), JSON.stringify(e));
    await ctx.close();
  }
} finally {
  wp('eval-file tests/fixtures/prices.php format local');
  wp('eval-file tests/fixtures/prices.php reset');
  wp('eval-file tests/fixtures/hero-brands.php reset');
  wp('eval-file tests/fixtures/state.php unfront');
  await browser.close();
}
const pass = results.filter((r) => r.startsWith('PASS')).length;
fs.writeFileSync(path.join(OUT, 'results.txt'), results.join('\n') + `\n\n${pass} passed, ${results.length - pass} failed\n`);
console.log(results.join('\n') + `\n\n${pass} passed, ${results.length - pass} failed`);
process.exit(pass === results.length ? 0 : 1);
