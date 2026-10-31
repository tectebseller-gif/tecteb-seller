/*
 * صفحهٔ اصلی + هدر مستقل tecteb-header (Header B) — هماهنگی، روی وردپرس یکبارمصرف محلی.
 *
 *   SITE=http://127.0.0.1:8090 WP="wp --allow-root --path=/home/user/wp/core" \
 *   AXE=<axe.min.js> NODE_PATH=/opt/node-tools/node_modules node tests/browser/with-header.mjs
 *
 * پیش‌نیاز: header-plugin/tests/fixtures/seed.php seed + configure on، سپس tests/fixtures/seed.php seed
 * (به همین ترتیب؛ دو فیکسچر نام دسته‌ای مشترک دارند).
 *
 * می‌سنجد: یک هدر · جست‌وجوی تکراری در موبایل نیست · فونت یک بار دانلود می‌شود · CSS ِ صفحهٔ اصلی روی
 * هدر اثر ندارد و برعکس · Mega Menu و کشوی هدر روی صفحهٔ اصلی کار می‌کنند · همهٔ پیوندها ۲۰۰ · همهٔ
 * تصویرها بارگذاری می‌شوند · جست‌وجو · وضعیت‌های خالی · axe روی کل صفحه · بی سرریز افقی در ۳۲۰/۳۹۰/۷۶۸/۱۴۴۰.
 * خروجی: tests/evidence/with-header/
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
const AXE = fs.readFileSync(process.env.AXE, 'utf8');
const OUT = path.join(PLUGIN, 'tests/evidence/with-header');
fs.mkdirSync(OUT, { recursive: true });
const wp = (cmd) => execSync(`${WP} ${cmd} 2>/dev/null`, { cwd: PLUGIN }).toString();
const state = (verb) => wp(`eval-file tests/fixtures/state.php ${verb}`);

const results = [];
const check = (name, ok, detail = '') => { results.push(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail ? '  — ' + detail : ''}`); };
const WIDTHS = [[320, 700], [390, 844], [768, 1024], [1440, 900]];
const HEADER_PROPS = ['display', 'height', 'width', 'padding-top', 'padding-right', 'margin-top', 'color', 'background-color', 'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'border-radius', 'box-shadow', 'text-decoration-line', 'opacity'];
const HEADER_SEL = ['.tth-bar', '.tth-logo', '.tth-search__input', '.tth-search__go', '.tth-tool--account', '.tth-tool--cart', '.tth-nav', '.tth-menu a:not([aria-current])', '.tth-cats__btn.tth-js', '.tth-seller'];
const readHeader = (page) => page.evaluate(({ sel, props }) => Object.fromEntries(sel.map((s) => {
  const e = document.querySelector('[data-tth-header] ' + s);
  if (!e) return [s, null];
  const cs = getComputedStyle(e);
  return [s, Object.fromEntries(props.filter((p) => p !== 'width').map((p) => [p, cs.getPropertyValue(p)]))];
})), { sel: HEADER_SEL, props: HEADER_PROPS });
const HOME_SEL = ['.th-hero__title', '.th-search__input', '.th-search__submit', '.th-route', '.th-route__title', '.th-head__title', '.th-cat', '.th-product__title', '.th-product__price', '.th-join__title', '.th-join .th-btn'];
const readHome = (page) => page.evaluate(({ sel, props }) => Object.fromEntries(sel.map((s) => {
  const e = document.querySelector('.tecteb-home-v1 ' + s);
  if (!e) return [s, null];
  const cs = getComputedStyle(e);
  const r = e.getBoundingClientRect();
  return [s, { ...Object.fromEntries(props.map((p) => [p, cs.getPropertyValue(p)])), box: [Math.round(r.width), Math.round(r.height)].join('x') }];
})), { sel: HOME_SEL, props: HEADER_PROPS });
const same = (a, b) => { const d = []; for (const k of Object.keys(a)) { if (!a[k] || !b[k]) { d.push(`${k}: missing`); continue; } for (const p of Object.keys(a[k])) if (a[k][p] !== b[k][p]) d.push(`${k}.${p}: ${a[k][p]} → ${b[k][p]}`); } return d; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
try {
  state('full');
  state('front');
  // نشانی‌های تأییدشده، همان پیش‌فرض‌های ۰٫۳٫۰ (state full جانشین‌های قدیمی را می‌گذارد).
  wp(`eval "update_option('tecteb_home_settings', array_merge(get_option('tecteb_home_settings'), array('links'=>array('repair'=>'/https-tecteb-com-repair-request/','supplier'=>'/landing-seller/','supplier_terms'=>'','all_categories'=>'/shop/'))));"`);

  // ---------------------------------------------------------------- ۱. هر عرض
  for (const [w, h] of WIDTHS) {
    for (const motion of ['no-preference', 'reduce']) {
      const ctx = await browser.newContext({ viewport: { width: w, height: h }, reducedMotion: motion });
      const p = await ctx.newPage();
      const fonts = [];
      const errors = [];
      p.on('request', (r) => { if (/\.woff2(\?|$)/.test(r.url())) fonts.push(r.url()); });
      p.on('pageerror', (e) => errors.push(String(e)));
      p.on('console', (m) => { if (m.type() === 'error' && !/favicon|gravatar|net::ERR/.test(m.text())) errors.push(m.text()); });
      await p.goto(`${SITE}/`, { waitUntil: 'networkidle' });
      await p.evaluate(() => document.fonts.ready);
      if (motion === 'reduce') {
        // تصویرهای محصول loading="lazy" ِ خودِ وردپرس‌اند؛ پیش از عکس تمام‌صفحه کل صفحه پیموده می‌شود تا
        // همان‌طور که بازدیدکننده با اسکرول می‌بیند بارگذاری شوند (وگرنه عکس جای تصویر را خالی نشان می‌دهد).
        for (let y = 0; y < await p.evaluate(() => document.body.scrollHeight); y += 400) { await p.evaluate((v) => window.scrollTo(0, v), y); await p.waitForTimeout(60); }
        await p.waitForLoadState('networkidle');
        const lazyOk = await p.$$eval('.tecteb-home-v1 img', (is) => is.every((i) => i.complete && i.naturalWidth > 0));
        check(`${w}: every homepage image (incl. lazy product images) loads when scrolled to`, lazyOk);
        await p.evaluate(() => window.scrollTo(0, 0));
        await p.screenshot({ path: path.join(OUT, `home-${w}.png`), fullPage: true });
        await ctx.close();
        continue;
      }
      await p.screenshot({ path: path.join(OUT, `home-${w}-fold.png`) });
      const r = await p.evaluate(() => {
        const vis = (e) => { if (!e) return false; const cs = getComputedStyle(e); const rc = e.getBoundingClientRect(); return cs.display !== 'none' && cs.visibility !== 'hidden' && rc.width > 0 && rc.height > 0; };
        const bar = document.querySelector('.tth-bar').getBoundingClientRect();
        const hero = document.querySelector('.th-hero').getBoundingClientRect();
        return {
          headers: document.querySelectorAll('[data-tth-header]').length,
          elementorHeader: document.querySelectorAll('[data-elementor-type="header"]').length,
          headerSearch: vis(document.querySelector('#tth-search-form')),
          headerToggle: vis(document.querySelector('[data-tth-search-toggle]')),
          heroSearch: vis(document.querySelector('.tecteb-home-v1 .th-search')),
          overflow: document.documentElement.scrollWidth - innerWidth,
          gap: Math.round(hero.top - bar.bottom),
          h1: document.querySelectorAll('h1').length,
          sprites: document.querySelectorAll('svg.th-sprite').length,
          dupIds: (() => { const seen = {}; const d = []; document.querySelectorAll('[id]').forEach((e) => { if (seen[e.id]) d.push(e.id); seen[e.id] = 1; }); return d; })(),
        };
      });
      check(`${w}: exactly one header (Header B), no Elementor header`, r.headers === 1 && r.elementorHeader === 0, JSON.stringify(r));
      check(`${w}: Hero starts right below the header (no gap, no overlap)`, r.gap === 0, String(r.gap));
      check(`${w}: no horizontal overflow`, r.overflow <= 0, String(r.overflow));
      check(`${w}: one h1 (the Hero), no duplicate ids`, r.h1 === 1 && r.dupIds.length === 0, JSON.stringify(r.dupIds));
      if (w < 768) {
        check(`${w}: no duplicated search — header search folded into its button, Hero search visible`, !r.headerSearch && r.headerToggle && r.heroSearch, JSON.stringify(r));
        // دکمهٔ جست‌وجوی هدر همان فرم هدر را باز می‌کند.
        await p.click('[data-tth-search-toggle]');
        check(`${w}: header search button still opens the header search`, await p.locator('#tth-search-form').isVisible());
      } else {
        check(`${w}: header search and Hero search both visible (desktop/tablet design)`, r.headerSearch && r.heroSearch, JSON.stringify(r));
      }
      const woff = fonts.filter((u) => /Vazirmatn/.test(u));
      check(`${w}: Vazirmatn downloaded once (shared file)`, woff.length === 1 && /tecteb-header\/assets\/fonts/.test(woff[0]), woff.join(' '));
      check(`${w}: no console or page errors`, errors.length === 0, errors.join(' | '));
      await p.addScriptTag({ content: AXE });
      const v = await p.evaluate(async () => (await axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'], rules: { region: { enabled: false } } })).violations.map((x) => `${x.id}×${x.nodes.length}: ${x.nodes[0].target.join(' ')}`));
      check(`${w}: axe on the whole page (header + homepage + footer) — 0 violations`, v.length === 0, v.join(' | '));
      fs.appendFileSync(path.join(OUT, 'axe.txt'), `${w}: ${v.length ? v.join(' | ') : 'none'}\n`);
      await ctx.close();
    }
  }

  // ---------------------------------------------------------------- ۲. ایزولگی دوطرفه
  const desk = await browser.newPage({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  await desk.goto(`${SITE}/tthh-about/`, { waitUntil: 'networkidle' });
  const headerElsewhere = await readHeader(desk);
  await desk.goto(`${SITE}/`, { waitUntil: 'networkidle' });
  const headerOnHome = await readHeader(desk);
  const hd = same(headerElsewhere, headerOnHome);
  check('isolation: homepage CSS changes nothing in Header B (same computed styles as on an inner page)', hd.length === 0, hd.slice(0, 6).join('; '));
  const homeWithB = await readHome(desk);
  wp('eval-file ../header-plugin/tests/fixtures/seed.php configure off');
  await desk.goto(`${SITE}/`, { waitUntil: 'networkidle' });
  const withoutB = await desk.evaluate(() => document.querySelectorAll('[data-tth-header]').length);
  const homeWithoutB = await readHome(desk);
  wp('eval-file ../header-plugin/tests/fixtures/seed.php configure on');
  const dd = same(homeWithoutB, homeWithB);
  check('isolation: Header B changes nothing in the homepage (same computed styles and sizes with and without it)', withoutB === 0 && dd.length === 0, `${withoutB} ${dd.slice(0, 6).join('; ')}`);

  // ---------------------------------------------------------------- ۳. Mega Menu و کشو روی صفحهٔ اصلی
  await desk.goto(`${SITE}/`, { waitUntil: 'networkidle' });
  await desk.click('.tth-cats__btn.tth-js');
  await desk.waitForTimeout(250);
  const mega = await desk.evaluate(() => {
    const m = document.querySelector('#tth-mega');
    const r = m.getBoundingClientRect();
    const top = document.elementFromPoint(r.left + r.width / 2, r.top + Math.min(60, r.height / 2));
    return { visible: getComputedStyle(m).display !== 'none' && r.height > 0, onTop: !!top && m.contains(top) };
  });
  check('mega menu: opens over the Hero and is on top (not covered by homepage layers)', mega.visible && mega.onTop, JSON.stringify(mega));
  await desk.screenshot({ path: path.join(OUT, 'mega-over-home-1440.png') });
  await desk.keyboard.press('Escape');
  await desk.mouse.wheel(0, 1400);
  await desk.waitForTimeout(400);
  const sticky = await desk.evaluate(() => {
    const bar = document.querySelector('.tth-bar').getBoundingClientRect();
    const top = document.elementFromPoint(innerWidth / 2, bar.top + 10);
    return { barTop: Math.round(bar.top), barOnTop: !!top && !!top.closest('[data-tth-header]') };
  });
  check('sticky header stays above homepage sections while scrolling', sticky.barTop === 0 && sticky.barOnTop, JSON.stringify(sticky));
  await desk.screenshot({ path: path.join(OUT, 'sticky-over-products-1440.png') });

  const mob = await browser.newPage({ viewport: { width: 390, height: 844 } });
  await mob.goto(`${SITE}/`, { waitUntil: 'networkidle' });
  await mob.click('[data-tth-burger]');
  await mob.waitForTimeout(250);
  check('mobile drawer: opens as a modal over the homepage', await mob.evaluate(() => document.querySelector('#tth-drawer').open && document.querySelector('#tth-drawer').matches(':modal')));
  await mob.screenshot({ path: path.join(OUT, 'drawer-over-home-390.png') });
  await mob.keyboard.press('Escape');

  // ---------------------------------------------------------------- ۴. پیوندها، تصویرها، جست‌وجو
  await desk.goto(`${SITE}/`, { waitUntil: 'networkidle' });
  const links = [...new Set(await desk.$$eval('.tecteb-home-v1 a[href]', (as) => as.map((a) => a.href)))];
  const bad = [];
  // ووکامرس جست‌وجوی تک‌نتیجه را به همان محصول redirect می‌کند (رفتار خودِ ووکامرس)؛ پس redirect فقط
  // اگر روی همین سایت بماند و به ۲۰۰ برسد پذیرفته است.
  for (const u of links) {
    const res = await desk.request.get(u);
    const final = new URL(res.url());
    if (res.status() !== 200 || final.origin !== new URL(SITE).origin) bad.push(`${res.status()} ${u} → ${res.url()}`);
  }
  check(`links: all ${links.length} homepage links reach 200 on this site (no 404, no off-site redirect)`, bad.length === 0 && links.length > 10, bad.join(' | '));
  check('links: shop route → WooCommerce shop page', (await desk.getAttribute('.th-route--shop', 'href')) === `${SITE}/shop/`);
  check('links: repair route → /https-tecteb-com-repair-request/, no repair form on the page', (await desk.getAttribute('.th-route--repair', 'href')) === `${SITE}/https-tecteb-com-repair-request/` && await desk.locator('.tecteb-home-v1 form:not([role="search"])').count() === 0);
  check('links: supplier route and section → /landing-seller/', (await desk.locator(`.tecteb-home-v1 a[href="${SITE}/landing-seller/"]`).count()) === 2);
  check('links: no # or javascript: links in the homepage', !links.some((u) => /#$|^javascript:/i.test(u)));
  await desk.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await desk.waitForTimeout(600);
  const imgs = await desk.$$eval('.tecteb-home-v1 img', (is) => is.map((i) => ({ src: i.currentSrc || i.src, ok: i.complete && i.naturalWidth > 0, alt: i.hasAttribute('alt') })));
  check(`images: all ${imgs.length} homepage images loaded, each with alt`, imgs.length >= 4 && imgs.every((i) => i.ok && i.alt), JSON.stringify(imgs.filter((i) => !i.ok || !i.alt)));
  const names = await desk.$$eval('.th-product__title', (hs) => hs.map((h) => h.textContent.trim()));
  const real = JSON.parse(wp(`eval "echo wp_json_encode(array_map(fn(\\$id)=>wc_get_product(\\$id)->get_name(), get_option('tecteb_home_settings')['products']));"`));
  check('products: exactly the manually selected WooCommerce products, in the admin order', JSON.stringify(names) === JSON.stringify(real.slice(0, names.length)) && names.length === 4, `${JSON.stringify(names)} vs ${JSON.stringify(real)}`);
  await desk.goto(`${SITE}/`, { waitUntil: 'networkidle' });
  await desk.fill('#th-c-search', 'پالس');
  await Promise.all([desk.waitForNavigation(), desk.click('.th-search__submit')]);
  const u = new URL(desk.url());
  // نتیجهٔ چندتایی ⇒ صفحهٔ نتایج محصول؛ تک‌نتیجه ⇒ ووکامرس مستقیم به همان محصول می‌رود.
  const results = u.searchParams.get('post_type') === 'product' && u.searchParams.get('s') === 'پالس' && await desk.locator('li.product').count() > 0;
  const single = /^\/product\//.test(decodeURIComponent(u.pathname)) && await desk.locator('body.single-product').count() === 1;
  check('search: Hero search → WooCommerce product search (results, or the single matching product)', results || single, desk.url());
  await desk.goto(`${SITE}/`, { waitUntil: 'networkidle' });
  await desk.fill('#th-c-search', 'zzzz-هیچ');
  await Promise.all([desk.waitForNavigation(), desk.click('.th-search__submit')]);
  check('search: no match → WooCommerce «no products» page (200), not an error', (await desk.locator('.woocommerce-info, .woocommerce-no-products-found').count()) > 0, desk.url());

  // ---------------------------------------------------------------- ۵. وضعیت‌های خالی
  wp('eval "delete_option(\'tecteb_home_settings\');"');
  await desk.goto(`${SITE}/`, { waitUntil: 'networkidle' });
  const empty = await desk.evaluate(() => ({
    sections: [...document.querySelectorAll('.tecteb-home-v1 > section')].map((s) => s.getAttribute('aria-labelledby')),
    routes: document.querySelectorAll('.th-route').length,
    products: document.querySelectorAll('.th-product').length,
    prices: document.querySelectorAll('.th-product__price').length,
  }));
  check('empty (fresh install, nothing selected): Hero + supplier only; no empty product or category grid; no invented price', JSON.stringify(empty.sections) === JSON.stringify(['th-hero-title', 'th-supplier-title']) && empty.routes === 3 && empty.products === 0 && empty.prices === 0, JSON.stringify(empty));
  const emptyCtx = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
  const ep = await emptyCtx.newPage();
  await ep.goto(`${SITE}/`, { waitUntil: 'networkidle' });
  await ep.screenshot({ path: path.join(OUT, 'empty-fresh-install-390.png'), fullPage: true });
  await emptyCtx.close();
  state('full');
  check('header unaffected by an empty homepage', await desk.locator('[data-tth-header]').count() === 1);
} finally {
  await browser.close();
}
const pass = results.filter((r) => r.startsWith('PASS')).length;
fs.writeFileSync(path.join(OUT, 'results.txt'), results.join('\n') + `\n\n${pass} passed, ${results.length - pass} failed\n`);
console.log(results.join('\n') + `\n\n${pass} passed, ${results.length - pass} failed`);
process.exit(pass === results.length ? 0 : 1);
