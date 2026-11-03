/*
 * ۰٫۳٫۵ — «محصولات منتخب»: عنوان کوچک «فروشگاه» فقط در موبایل پنهان، ردیف دکمه‌های دسته در همهٔ عرض‌ها برداشته.
 * فقط می‌خواند و عکس می‌گیرد. همان آزمون روی 0.3.4 (LABEL=before) و روی تغییر (LABEL=after).
 *
 *   LABEL=before|after SITE=http://127.0.0.1:8090 NODE_PATH=/opt/node-tools/node_modules node tests/browser/products-head.mjs
 *
 * خروجی: tests/evidence/products-head/<LABEL>.json و .txt و تصویر بخش در هر عرض.
 */
import { createRequire } from 'module';
import fs from 'fs';
import { execFileSync } from 'child_process';
import path from 'path';

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const HERE = path.dirname(new URL(import.meta.url).pathname);
const SITE = process.env.SITE || 'http://127.0.0.1:8090';
const LABEL = process.env.LABEL || 'run';
const OUT = path.join(HERE, '../evidence/products-head');
fs.mkdirSync(OUT, { recursive: true });
// همان حالت پس از نصبِ آزمون‌های دیگر: دسته‌ها و محصولات منتخب انتخاب‌شده (فقط وردپرس یکبارمصرف محلی).
execFileSync('wp', ['--allow-root', '--path=/home/user/wp/core', 'eval-file', path.join(HERE, '../fixtures/state.php'), 'shipped'], { cwd: path.join(HERE, '../..') });
const WIDTHS = [320, 390, 768, 1024, 1080, 1280, 1440, 1920];
const results = [];
const check = (name, ok, detail = '') => results.push(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail ? '  — ' + detail : ''}`);

const measure = (p) => p.evaluate(() => {
  document.getAnimations().forEach((a) => { try { if (typeof CSSTransition !== 'undefined' && a instanceof CSSTransition) a.finish(); } catch (e) { /* بی‌پایان */ } });
  const sec = document.querySelector('.tecteb-home-v1 section[aria-labelledby="th-products-title"]');
  if (!sec) return null;
  const vis = (e) => !!e && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().height > 0;
  const r = (e) => e.getBoundingClientRect();
  const head = sec.querySelector('.th-head');
  const eyebrow = head.querySelector('.th-eyebrow');
  const title = sec.querySelector('#th-products-title');
  const more = head.querySelector('.th-more');
  const list = sec.querySelector('.th-products');
  const cats = document.querySelectorAll('.tecteb-home-v1 a.th-cat');
  return {
    eyebrow: vis(eyebrow), eyebrowText: eyebrow ? eyebrow.textContent.trim() : '',
    title: vis(title) ? title.textContent.trim() : '',
    more: more && vis(more) ? { text: more.textContent.trim(), href: more.getAttribute('href') } : null,
    filterRow: sec.querySelectorAll('.th-filter, .th-chip').length,
    products: list ? list.querySelectorAll(':scope > li').length : 0,
    productsShown: list ? [...list.querySelectorAll(':scope > li')].filter(vis).length : 0,
    sectionTopToTitle: Math.round(r(title).top - r(sec).top),
    headHeight: Math.round(r(head).height),
    headToProducts: list ? Math.round(r(list).top - r(head).bottom) : -1,
    titleToMore: more && vis(more) ? Math.round(r(more).top - r(title).bottom) : null,
    categoryCards: cats.length,
    categoryHrefs: [...cats].map((a) => a.getAttribute('href')),
    overflowX: document.documentElement.scrollWidth - innerWidth,
    sectionHeight: Math.round(r(sec).height),
  };
});

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const data = {};
try {
  for (const w of WIDTHS) {
    const p = await browser.newPage({ viewport: { width: w, height: 900 }, isMobile: w < 800, hasTouch: w < 800 });
    await p.goto(`${SITE}${process.env.PAGE || '/tecteb-home-new/'}`, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    // th-reveal با IntersectionObserver ظاهر می‌شود؛ بخش را به دید بیاور.
    await p.evaluate(() => { const s = document.querySelector('section[aria-labelledby="th-products-title"]'); if (s) s.scrollIntoView(); });
    await p.waitForTimeout(700);
    const m = await measure(p);
    data[w] = m;
    if (m) {
      const box = await p.locator('section[aria-labelledby="th-products-title"]').boundingBox();
      await p.screenshot({ path: path.join(OUT, `${LABEL}-${w}.png`), clip: { x: 0, y: Math.max(0, box.y), width: w, height: Math.min(box.height, w < 800 ? 640 : 560) } });
    }
    await p.close();
  }
} finally {
  await browser.close();
}
fs.writeFileSync(path.join(OUT, `${LABEL}.json`), JSON.stringify(data, null, 1));

// سنجش‌ها فقط برای LABEL=after (دستور ۰٫۳٫۵)؛ before فقط اندازه‌گیری است.
if (LABEL !== 'before') {
  const before = fs.existsSync(path.join(OUT, 'before.json')) ? JSON.parse(fs.readFileSync(path.join(OUT, 'before.json'), 'utf8')) : null;
  for (const w of WIDTHS) {
    const m = data[w];
    const b = before && before[w];
    const mobile = w < 600;
    check(`${w}: section, title «محصولات منتخب برای مراکز درمانی» and selected products shown`, !!m && m.title === 'محصولات منتخب برای مراکز درمانی' && m.products > 0 && m.productsShown === m.products, JSON.stringify(m));
    check(`${w}: no category filter row`, !!m && m.filterRow === 0, JSON.stringify(m && m.filterRow));
    check(`${w}: «ورود به فروشگاه» kept, same destination as 0.3.4`, !!m && !!m.more && m.more.text.includes('ورود به فروشگاه') && (!b || (b.more && b.more.href === m.more.href)), JSON.stringify([m && m.more, b && b.more]));
    check(`${w}: eyebrow «فروشگاه» ${mobile ? 'hidden (mobile)' : 'still shown (tablet/desktop)'}`, !!m && m.eyebrow === !mobile && m.eyebrowText === 'فروشگاه', JSON.stringify(m && m.eyebrow));
    if (!mobile && b) check(`${w}: header block of the section unchanged (title position and head height as 0.3.4)`, m.sectionTopToTitle === b.sectionTopToTitle && m.headHeight === b.headHeight, JSON.stringify([m.sectionTopToTitle, b.sectionTopToTitle, m.headHeight, b.headHeight]));
    if (b) check(`${w}: gap head → products is the section's normal head spacing (filter row's height gone, ${b.headToProducts} → ${m.headToProducts}px)`, m.headToProducts < b.headToProducts && m.headToProducts >= 24 && m.headToProducts <= 44, JSON.stringify([b.headToProducts, m.headToProducts]));
    if (mobile && b) check(`${w}: mobile — title moves up by the eyebrow, title → «ورود به فروشگاه» spacing unchanged`, m.sectionTopToTitle < b.sectionTopToTitle && m.titleToMore === b.titleToMore, JSON.stringify([b.sectionTopToTitle, m.sectionTopToTitle, b.titleToMore, m.titleToMore]));
    check(`${w}: category cards above unchanged${b ? ' (same links as 0.3.4)' : ''}`, !!m && m.categoryCards > 0 && (!b || JSON.stringify(b.categoryHrefs) === JSON.stringify(m.categoryHrefs)), JSON.stringify(m && m.categoryCards));
    check(`${w}: no horizontal scroll`, !!m && m.overflowX <= 0, String(m && m.overflowX));
  }
  const pass = results.filter((r) => r.startsWith('PASS')).length;
  const out = `${LABEL}\n\n${results.join('\n')}\n\n${pass} passed, ${results.length - pass} failed\n`;
  fs.writeFileSync(path.join(OUT, `${LABEL}.txt`), out);
  console.log(out);
  process.exit(pass === results.length ? 0 : 1);
}
console.log(JSON.stringify(data[390]), '\n', JSON.stringify(data[1440]));
