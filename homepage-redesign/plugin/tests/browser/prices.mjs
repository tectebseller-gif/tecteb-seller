/*
 * ۰٫۳٫۲ — قیمت کارت‌های محصول و جست‌وجوی Hero، در مرورگر، روی همهٔ شکل‌های قیمت ووکامرس.
 *
 *   SITE=… WP="wp --allow-root --path=…" AXE=<axe.min.js> NODE_PATH=… OUT=tests/evidence/prices \
 *   node tests/browser/prices.mjs
 *
 * پیش‌نیاز: header seed + configure on، tests/fixtures/seed.php seed، state.php full،
 * tests/fixtures/prices.php seed. این اسکریپت خودش صفحهٔ کد کوتاه را صفحهٔ نخست می‌کند و برمی‌گرداند.
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
const OUT = process.env.OUT || path.join(PLUGIN, 'tests/evidence/prices');
fs.mkdirSync(OUT, { recursive: true });
// فقط فرمان‌های ثابتِ wp-cli روی وردپرس یکبارمصرف محلی؛ هیچ ورودی بیرونی.
const wp = (cmd) => execSync(`${WP} ${cmd} 2>/dev/null`, { cwd: PLUGIN }).toString().trim();
const results = [];
const check = (name, ok, detail = '') => results.push(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail ? '  — ' + detail : ''}`);

// انتظارِ هر کارت، به ترتیب «محصولات منتخب» (همان ترتیب tests/fixtures/prices.php).
const EXPECT = [
  { key: 'simple', kind: 'amount', now: '45,000,000' },
  { key: 'sale', kind: 'sale', was: '12,500,000', now: '9,800,000' },
  { key: 'range', kind: 'range', from: '3,200,000', to: '128,500,000' },
  { key: 'var_sale', kind: 'sale', was: '7,000,000', now: '6,300,000' },
  { key: 'var_partial', kind: 'range', from: '915,000,000', to: '1,250,000,000' },
  { key: 'var_noprice', kind: 'none' },
  { key: 'noprice', kind: 'none' },
  { key: 'zero', kind: 'amount', now: '0' },
  { key: 'grouped', kind: 'range', from: '9,800,000', to: '45,000,000' },
  { key: 'var_out', kind: 'out' },
];
const FOREIGN = /Price range|through|Original price|Current price|was:|is:/i;

wp('eval-file tests/fixtures/state.php front');
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const cardData = (p) => p.evaluate(() => [...document.querySelectorAll('.th-products > li')].map((li) => {
  const card = li.querySelector('.th-product');
  const price = card.querySelector('.th-product__price');
  const cr = card.getBoundingClientRect();
  const pr = price ? price.getBoundingClientRect() : null;
  const visibleText = (el) => {
    // متنِ واقعاً دیدنی: هر عنصری که جعبهٔ ۱×۱ ِ پنهان یا display:none دارد کنار می‌رود.
    let out = '';
    const walk = (n) => {
      if (n.nodeType === 3) { out += n.textContent; return; }
      if (n.nodeType !== 1) return;
      const cs = getComputedStyle(n); const r = n.getBoundingClientRect();
      if (cs.display === 'none' || cs.visibility === 'hidden' || (r.width <= 1 && r.height <= 1)) return;
      for (const c of n.childNodes) walk(c);
    };
    walk(el);
    return out.replace(/\s+/g, ' ').trim();
  };
  const del = price ? price.querySelector('del') : null;
  const amounts = price ? [...price.querySelectorAll('.woocommerce-Price-amount')].filter((a) => a.getBoundingClientRect().width > 1) : [];
  return {
    text: price ? visibleText(price) : '',
    a11y: price ? price.textContent.replace(/\s+/g, ' ').trim() : '',
    del: del ? { text: visibleText(del), line: getComputedStyle(del).textDecorationLine } : null,
    nowWeight: price ? Number(getComputedStyle(price.querySelector('ins') || price).fontWeight) : 0,
    // RTL + رقم لاتین ⇒ چند rect برای چند تکهٔ bidi در **یک** سطر؛ سطرها را با موقعیت عمودی می‌شماریم.
    amounts: amounts.map((a) => ({ text: a.textContent.replace(/\s+/g, ' ').trim(), lines: new Set([...a.getClientRects()].filter((r) => r.width > 0).map((r) => Math.round(r.top / 6))).size })),
    // کارت overflow: hidden دارد؛ قیمتی که از پایین بیرون بزند بریده می‌شود، پس هر چهار لبه سنجیده می‌شود.
    inside: pr ? pr.left >= cr.left - 0.5 && pr.right <= cr.right + 0.5 && pr.top >= cr.top - 0.5 && pr.bottom <= cr.bottom + 0.5 : false,
    clipped: price ? price.scrollHeight - price.clientHeight : 99,
    overflow: price ? price.scrollWidth - price.clientWidth : 99,
    rect: [cr.left, cr.top, cr.right, cr.bottom],
    title: card.querySelector('.th-product__title')?.textContent.trim() || '',
  };
}));

try {
  for (const [w, h] of [[1440, 900], [768, 1024], [390, 844], [320, 700]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, reducedMotion: 'reduce' });
    const p = await ctx.newPage();
    await p.goto(`${SITE}/`, { waitUntil: 'networkidle' });
    const cards = await cardData(p);
    check(`${w}: ${EXPECT.length} selected products shown in the admin order`, cards.length === EXPECT.length, `${cards.length}`);
    cards.forEach((c, i) => {
      const e = EXPECT[i]; if (!e) return;
      const tag = `${w} ${e.key}`;
      check(`${tag}: no English screen-reader text visible (Price range / through / Original price)`, !FOREIGN.test(c.text), c.text);
      if (e.kind === 'amount') check(`${tag}: one amount «${e.now}» with the site currency`, c.amounts.length === 1 && c.amounts[0].text.startsWith(e.now) && c.text.includes('تومان'), JSON.stringify(c));
      if (e.kind === 'sale') {
        check(`${tag}: old price «${e.was}» struck through, current «${e.now}» emphasised`, c.del && c.del.text.startsWith(e.was) && c.del.line.includes('line-through') && c.amounts.length === 2 && c.amounts[1].text.startsWith(e.now) && c.nowWeight >= 700, JSON.stringify(c));
        check(`${tag}: screen readers hear «قیمت قبلی» and «قیمت فعلی» in Persian`, c.a11y.includes('قیمت قبلی') && c.a11y.includes('قیمت فعلی'), c.a11y);
      }
      if (e.kind === 'range') {
        check(`${tag}: range «${e.from}» to «${e.to}», both with currency`, c.amounts.length === 2 && c.amounts[0].text.startsWith(e.from) && c.amounts[1].text.startsWith(e.to), JSON.stringify(c));
        check(`${tag}: range reads in Persian («از … تا …»)`, c.text.includes('از') && c.text.includes('تا'), c.text);
      }
      if (e.kind === 'none') check(`${tag}: «قیمت اعلام نشده», no number`, c.text === 'قیمت اعلام نشده' && c.amounts.length === 0, c.text);
      if (e.kind === 'out') check(`${tag}: «فعلاً ناموجود», no price invented`, c.text === 'فعلاً ناموجود', c.text);
      check(`${tag}: price stays inside its card (no overflow onto neighbours)`, c.inside && c.overflow <= 0 && c.clipped <= 0, JSON.stringify({ inside: c.inside, overflow: c.overflow, clipped: c.clipped }));
      check(`${tag}: no amount is broken across lines`, c.amounts.every((a) => a.lines === 1), JSON.stringify(c.amounts));
    });
    const overlap = cards.some((a, i) => cards.some((b, j) => j > i && a.rect[0] < b.rect[2] - 1 && b.rect[0] < a.rect[2] - 1 && a.rect[1] < b.rect[3] - 1 && b.rect[1] < a.rect[3] - 1));
    check(`${w}: product cards do not overlap`, !overlap);
    const s = await p.evaluate(() => {
      const f = document.querySelector('.th-search');
      const vis = (e) => !!e && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().width > 0;
      const fr = f.getBoundingClientRect(); const ir = f.querySelector('.th-search__field').getBoundingClientRect(); const br = f.querySelector('.th-search__submit').getBoundingClientRect();
      return { select: f.querySelectorAll('select').length, field: vis(f.querySelector('input[name="s"]')), button: vis(f.querySelector('button[type="submit"]')), postType: f.querySelector('input[name="post_type"]')?.value, action: f.getAttribute('action'), inside: ir.left >= fr.left && br.right <= fr.right + 0.5 && ir.right <= fr.right + 0.5 && br.left >= fr.left - 0.5, overflow: document.documentElement.scrollWidth - innerWidth, fieldW: Math.round(ir.width), formW: Math.round(fr.width) };
    });
    check(`${w}: Hero search = one field + «جست‌وجو» button, no category dropdown`, s.select === 0 && s.field && s.button, JSON.stringify(s));
    check(`${w}: Hero search still WooCommerce product search (s + post_type=product, home URL)`, s.postType === 'product' && new URL(s.action).pathname === '/', JSON.stringify(s));
    check(`${w}: search field and button fit the bar; no page overflow`, s.inside && s.overflow <= 0 && s.fieldW >= s.formW * 0.5, JSON.stringify(s));
    if (AXE) {
      await p.addScriptTag({ content: AXE });
      const v = await p.evaluate(async () => (await axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'], rules: { region: { enabled: false } } })).violations.map((x) => x.id + ':' + x.nodes.length));
      check(`${w}: axe on the whole page — 0 violations`, v.length === 0, v.join(','));
    }
    for (let y = 0; y < await p.evaluate(() => document.body.scrollHeight); y += 400) { await p.evaluate((v2) => window.scrollTo(0, v2), y); await p.waitForTimeout(40); }
    await p.evaluate(() => window.scrollTo(0, 0));
    // تصویر از کل صفحه بریده می‌شود، نه از عنصر: Header B ِ چسبان فقط بالای صفحه می‌نشیند و روی کارت‌ها نمی‌افتد.
    const box = async (sel) => p.evaluate((q) => { const r = document.querySelector(q).getBoundingClientRect(); return { x: 0, y: r.top + scrollY, width: innerWidth, height: r.height }; }, sel);
    await p.screenshot({ path: path.join(OUT, `products-${w}.png`), fullPage: true, clip: await box('section[aria-labelledby="th-products-title"]') });
    await p.screenshot({ path: path.join(OUT, `hero-${w}.png`), fullPage: true, clip: await box('.th-hero') });
    await ctx.close();
  }

  // CSS ِ رایج قالب‌ها روی قیمت (ins زیرخط‌دار و رنگی، del بی خط، مبلغ درشت، screen-reader-text آشکار، bdi کج):
  // لایهٔ ایزوله و قاعده‌های قیمت باید همان نتیجه را نگه دارند.
  for (const w of [1440, 320]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, reducedMotion: 'reduce' });
    const p = await ctx.newPage();
    await p.goto(`${SITE}/`, { waitUntil: 'networkidle' });
    await p.addStyleTag({ content: 'ins{text-decoration:underline!important;background:#ff0;font-weight:400} del{text-decoration:none;opacity:.2} .woocommerce-Price-amount,.amount{font-size:44px;white-space:normal} .screen-reader-text{position:static;clip:auto;width:auto;height:auto} bdi{font-style:italic;white-space:normal} p{margin:25px 0;font-size:22px} span{letter-spacing:4px}' });
    const cards = await cardData(p);
    const nowStyle = await p.evaluate(() => { const i = document.querySelector('.th-price__now'); const c = getComputedStyle(i); return { deco: c.textDecorationLine, bg: c.backgroundColor, size: c.fontSize }; });
    check(`hostile theme CSS ${w}: prices stay inside cards, no amount broken, no English text`, cards.every((c) => c.inside && c.clipped <= 0 && c.overflow <= 0 && c.amounts.every((a) => a.lines === 1) && !FOREIGN.test(c.text)), JSON.stringify(cards.filter((c) => !(c.inside && c.clipped <= 0 && c.overflow <= 0)).map((c) => c.title)));
    check(`hostile theme CSS ${w}: old price still struck through, current price not underlined/highlighted`, cards[1].del && cards[1].del.line.includes('line-through') && nowStyle.deco === 'none' && nowStyle.bg === 'rgba(0, 0, 0, 0)' && nowStyle.size === '16px', JSON.stringify(nowStyle));
    await ctx.close();
  }

  // جست‌وجو واقعاً ووکامرس است: یک واژه ⇒ صفحهٔ نتایج/محصول ووکامرس.
  {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const p = await ctx.newPage();
    await p.goto(`${SITE}/`, { waitUntil: 'networkidle' });
    await p.fill('#th-c-search', 'سرنگ‌پمپ');
    const [res] = await Promise.all([p.waitForNavigation(), p.press('#th-c-search', 'Enter')]);
    const u = new URL(p.url());
    const isWoo = await p.evaluate(() => document.body.classList.contains('woocommerce') || document.body.classList.contains('woocommerce-page') || document.body.classList.contains('single-product'));
    check('search «سرنگ‌پمپ» lands on WooCommerce results/product (200)', res.status() === 200 && isWoo, `${res.status()} ${u.pathname}${u.search}`);
    await ctx.close();
  }

  // «استعلام قیمت»: متنی که خودِ سایت از فیلتر استاندارد ووکامرس می‌دهد، نه متنی ساختگی.
  wp('eval-file tests/fixtures/prices.php inquiry "استعلام قیمت"');
  {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
    const p = await ctx.newPage();
    await p.goto(`${SITE}/`, { waitUntil: 'networkidle' });
    const cards = await cardData(p);
    const idx = EXPECT.map((e, i) => (e.kind === 'none' ? i : -1)).filter((i) => i >= 0);
    check('site «استعلام قیمت» (woocommerce_empty_price_html) is shown as the site wrote it, for simple and variable', idx.every((i) => cards[i].text === 'استعلام قیمت'), JSON.stringify(idx.map((i) => cards[i].text)));
    check('priced products unaffected by the inquiry filter', cards[0].amounts.length === 1 && cards[2].amounts.length === 2);
    const r = await p.evaluate(() => { const b = document.querySelector('.th-products').getBoundingClientRect(); return { x: 0, y: b.top + scrollY, width: innerWidth, height: b.height }; });
    await p.screenshot({ path: path.join(OUT, 'products-390-inquiry.png'), fullPage: true, clip: r });
    await ctx.close();
  }
} finally {
  wp('eval-file tests/fixtures/prices.php no-inquiry');
  wp('eval-file tests/fixtures/state.php unfront');
  await browser.close();
}
const pass = results.filter((r) => r.startsWith('PASS')).length;
fs.writeFileSync(path.join(OUT, 'results.txt'), results.join('\n') + `\n\n${pass} passed, ${results.length - pass} failed\n`);
console.log(results.join('\n') + `\n\n${pass} passed, ${results.length - pass} failed`);
process.exit(pass === results.length ? 0 : 1);
