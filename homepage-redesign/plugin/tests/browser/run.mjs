// آزمون مرورگری افزونه روی وردپرس یکبارمصرف محلی (Hello Elementor ۳٫۵٫۱ + ووکامرس ۱۱).
//
//   SITE=http://127.0.0.1:8090 WP="wp --allow-root --path=/home/user/wp/core" \
//   AXE=<axe.min.js> NODE_PATH=/opt/node-tools/node_modules node tests/browser/run.mjs
//
// سنجش‌ها: پنج عرض × دو وضعیت، سرریز افقی، هدر و پاورقی سایت، یک h1، axe روی
// markup ِ خودمان، صفحه‌کلید و فوکوس، کاهش حرکت، هیچ درخواست بیرونی، نشتِ
// استایل قالب در برابر پیش‌نمایش تأییدشده (و با یک kit ِ Elementor ِ نمونه)،
// و مسیر واقعی صفحهٔ تنظیمات (جست‌وجو، افزودن، ترتیب، ذخیره).
import { createRequire } from 'node:module';
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import url from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const HERE = path.dirname(url.fileURLToPath(import.meta.url));
const PLUGIN = path.resolve(HERE, '../..');
const REDESIGN = path.resolve(PLUGIN, '..');
const SITE = process.env.SITE || 'http://127.0.0.1:8090';
const WP = process.env.WP || 'wp --allow-root --path=/home/user/wp/core';
const AXE = fs.readFileSync(process.env.AXE, 'utf8');
const OUT = process.env.OUT || path.join(PLUGIN, 'tests/evidence/browser');
fs.mkdirSync(OUT, { recursive: true });

const results = [];
const check = (name, ok, detail = '') => { results.push({ ok, name, detail }); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail ? '  — ' + detail : ''}`); };
const wp = (cmd) => execSync(`${WP} ${cmd} 2>/dev/null`, { cwd: PLUGIN }).toString();
const state = (verb) => wp(`eval-file tests/fixtures/state.php ${verb}`);

const PROPS = ['paddingTop', 'paddingRight', 'borderTopWidth', 'borderTopStyle', 'borderTopLeftRadius', 'fontWeight', 'fontStyle', 'textTransform', 'letterSpacing', 'fontSize', 'backgroundColor', 'color', 'boxShadow'];
const PROBES = {
  'search input': '#th-c-search',
  'category select': '#th-c-scope',
  'search button': '.th-search__submit',
  'h1': '#th-hero-title',
  'h1 line': '.th-hero__line',
  'section h2': '#th-products-title',
  'product title': '.th-product__title',
  'product link': '.th-product__title a',
  'chip': '.th-filter .th-chip',
};
// انتقال‌های CSS (۰٫۲۵ ثانیه روی رنگ دکمه و چیپ) اگر در لحظهٔ اندازه‌گیری در جریان
// باشند، مقدار میانی را می‌دهند؛ پیش از خواندن، همه تمام می‌شوند.
const measure = (page) => page.evaluate(({ PROBES, PROPS }) => {
  document.getAnimations().forEach((a) => { if (typeof CSSTransition !== 'undefined' && a instanceof CSSTransition) a.finish(); });
  const out = {};
  for (const [label, sel] of Object.entries(PROBES)) {
    const el = document.querySelector('.tecteb-home-v1 ' + sel);
    if (!el) { out[label] = null; continue; }
    const cs = getComputedStyle(el);
    out[label] = Object.fromEntries(PROPS.map((p) => [p, cs[p]]));
  }
  return out;
}, { PROBES, PROPS });
// تفاوت‌های عمدی با پیش‌نمایش: پیش‌نمایش padding ِ پیش‌فرض مرورگر را داشت (۱px/۲px)
// و سخت‌سازی آن را صفر می‌کند تا padding ِ ۸px/۱۶px ِ Hello جایش ننشیند.
const INTENDED = new Set(['search input.paddingTop:1px→0px', 'search input.paddingRight:2px→0px', 'search button.paddingTop:1px→0px']);
const diff = (a, b) => {
  const d = [];
  for (const k of Object.keys(PROBES)) {
    if (!a[k] || !b[k]) { d.push(`${k}: missing`); continue; }
    for (const p of PROPS) if (a[k][p] !== b[k][p] && !INTENDED.has(`${k}.${p}:${a[k][p]}→${b[k][p]}`)) d.push(`${k}.${p}: ${a[k][p]} → ${b[k][p]}`);
  }
  return d;
};

const browser = await chromium.launch();
try {
  // ---------------------------------------------------------------- مرجع: پیش‌نمایش تأییدشده
  const ref = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  await ref.goto(url.pathToFileURL(path.join(REDESIGN, 'concept-c/index.html')).href, { waitUntil: 'load' });
  await ref.evaluate(() => document.fonts.ready);
  const reference = await measure(ref);
  await ref.close();
  fs.writeFileSync(path.join(OUT, 'measure-preview.json'), JSON.stringify(reference, null, 1));

  // ---------------------------------------------------------------- وضعیت کامل (همهٔ بخش‌ها)
  state('full');
  const external = new Set();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('request', (r) => { const u = new URL(r.url()); if (/^https?:$/.test(u.protocol) && !u.host.startsWith('127.0.0.1')) external.add(r.url()); });
  await page.goto(`${SITE}/tecteb-home-new/`, { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);

  const live = await measure(page);
  fs.writeFileSync(path.join(OUT, 'measure-live.json'), JSON.stringify(live, null, 1));
  const leaks = diff(reference, live);
  fs.writeFileSync(path.join(OUT, 'theme-leak-live-hello.txt'), leaks.length ? leaks.join('\n') + '\n' : 'no difference from the approved preview\n');
  check('theme: Hello Elementor 3.5.1 changes none of the measured properties vs the approved preview', leaks.length === 0, leaks.slice(0, 6).join('; '));

  const kit = fs.readFileSync(path.join(REDESIGN, 'readiness/hello-elementor-3.5.1-reset-excerpt.css'), 'utf8').split('/* و یک قاعدهٔ نمونهٔ')[1];
  await page.addStyleTag({ content: '/*' + kit });
  await page.evaluate(() => document.body.classList.add('elementor-kit-5'));
  const withKit = await measure(page);
  const kitLeaks = diff(reference, withKit);
  fs.writeFileSync(path.join(OUT, 'theme-leak-live-kit.txt'), kitLeaks.length ? kitLeaks.join('\n') + '\n' : 'no difference from the approved preview\n');
  check('theme: an Elementor kit with form/heading styles changes nothing either', kitLeaks.length === 0, kitLeaks.slice(0, 6).join('; '));
  await page.reload({ waitUntil: 'load' });

  check('page: site header kept', await page.locator('#site-header').count() === 1);
  check('page: site footer kept', await page.locator('#site-footer').count() === 1);
  check('page: exactly one h1', await page.locator('h1').count() === 1);
  check('page: all four sections present (full state)', await page.locator('.tecteb-home-v1 > section').count() === 4);
  check('page: no services section, no quote button', await page.locator('.th-services, .th-product__quote, .th-route--buyer').count() === 0);
  check('page: no external requests', external.size === 0, [...external].join(' '));
  check('page: no console errors', errors.length === 0, errors.join(' | '));

  await page.addScriptTag({ content: AXE });
  const axe = await page.evaluate(async () => {
    const r = await window.axe.run(document.querySelector('.tecteb-home-v1'), { resultTypes: ['violations'] });
    return r.violations.map((v) => `${v.id}(${v.impact})×${v.nodes.length}`);
  });
  fs.writeFileSync(path.join(OUT, 'axe.txt'), axe.length ? axe.join('\n') + '\n' : '0 violations inside .tecteb-home-v1\n');
  check('a11y: axe — 0 violations inside our markup', axe.length === 0, axe.join(', '));

  // صفحه‌کلید: از اول صفحه Tab تا کادر جست‌وجو، و حلقهٔ فوکوس دیدنی.
  let reached = false;
  for (let i = 0; i < 40 && !reached; i++) {
    await page.keyboard.press('Tab');
    reached = await page.evaluate(() => document.activeElement?.id === 'th-c-search');
  }
  const ring = await page.evaluate(() => getComputedStyle(document.querySelector('.th-search__field')).boxShadow);
  check('keyboard: search input reachable with Tab', reached);
  check('keyboard: visible focus ring on the search field', reached && ring !== 'none', ring);
  await page.keyboard.type('پالس');
  await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
  // ووکامرس با یک نتیجهٔ تنها مستقیم به صفحهٔ همان محصول می‌رود (woocommerce_redirect_single_search_result).
  check('search: submitting the hero search finds the product', (await page.content()).includes('پالس‌اکسیمتر انگشتی'), page.url());

  // حرکت روی صفحهٔ واقعی: هر بخشِ زیر خط تا پیش از اسکرول منتظر است، و با رسیدن به آن کامل دیده می‌شود.
  await page.goto(`${SITE}/tecteb-home-new/`, { waitUntil: 'load' });
  const waiting = await page.evaluate(() => { const e = [...document.querySelectorAll('.tecteb-home-v1 .th-reveal')].find((x) => x.getBoundingClientRect().top > innerHeight + 200); return e ? Number(getComputedStyle(e).opacity) : null; });
  check('motion: a block below the fold waits for scroll (scroll-driven reveal)', waiting !== null && waiting < 0.5, String(waiting));
  const reveals = await page.locator('.tecteb-home-v1 .th-reveal').count();
  let unrevealed = 0;
  for (let i = 0; i < reveals; i++) {
    const el = page.locator('.tecteb-home-v1 .th-reveal').nth(i);
    await el.evaluate((e) => e.scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(60);
    if (Number(await el.evaluate((e) => getComputedStyle(e).opacity)) < 0.99) unrevealed++;
  }
  check(`motion: every one of ${reveals} blocks is fully visible once scrolled into view`, unrevealed === 0, String(unrevealed));

  // تصویرها و چیدمان: با «کاهش حرکت»، یعنی همان حالت نهایی که بی انیمیشن هم کامل است
  // (عکسِ تمام‌صفحه اسکرول نمی‌کند و بخش‌های منتظرِ اسکرول را خالی نشان می‌داد).
  const shotCtx = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  const shot = await shotCtx.newPage();
  const shoot = async (route, prefix, widths) => {
    for (const w of widths) {
      await shot.setViewportSize({ width: w, height: 900 });
      await shot.goto(`${SITE}${route}`, { waitUntil: 'load' });
      await shot.evaluate(() => document.fonts.ready);
      // عکس تمام‌صفحه اسکرول نمی‌کند؛ صفحه را مثل یک بازدیدکننده تا پایین می‌پیماییم تا
      // تصویرهای lazy با همان اندازه‌ای که مرورگر انتخاب می‌کند بارگذاری شوند.
      // (lazy→eager اندازهٔ sizes="auto" را عوض می‌کند و دانلود دوم را وسط عکس می‌گذارد.)
      for (const img of await shot.locator('.tecteb-home-v1 img').all()) {
        await img.scrollIntoViewIfNeeded();
        await img.evaluate((i) => (i.complete && i.naturalWidth ? null : new Promise((r) => { i.addEventListener('load', r, { once: true }); i.addEventListener('error', r, { once: true }); })));
        await img.evaluate((i) => i.decode().catch(() => null));
      }
      await shot.evaluate(() => window.scrollTo(0, 0));
      await shot.waitForLoadState('networkidle');
      const broken = await shot.evaluate(() => [...document.querySelectorAll('.tecteb-home-v1 img')].filter((i) => !i.naturalWidth).map((i) => i.src.split('/').pop()));
      check(`images ${prefix} ${w}px: every image loaded`, broken.length === 0, broken.join(', '));
      const overflow = await shot.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      check(`layout ${prefix} ${w}px: no horizontal overflow`, overflow <= 0, `${overflow}px`);
      const tight = await shot.evaluate(() => [...document.querySelectorAll('.tecteb-home-v1 a, .tecteb-home-v1 button, .tecteb-home-v1 input, .tecteb-home-v1 select')]
        .filter((e) => e.offsetParent !== null)
        .map((e) => { const t = e.closest('article') || e; const r = t.getBoundingClientRect(); return { r, label: (e.textContent || e.id || e.className).trim().slice(0, 30) }; })
        .filter(({ r }) => r.height < 24 || r.width < 24).map(({ label }) => label));
      check(`layout ${prefix} ${w}px: every control at least 24×24`, tight.length === 0, tight.join(', '));
      await shot.screenshot({ path: path.join(OUT, `${prefix}-${w}.png`), fullPage: true });
    }
  };
  await shoot('/tecteb-home-new/', 'full', [1440, 1024, 768, 390, 320]);

  // سه مسیر اصلی در هر عرض دیده می‌شوند، و هر کدام به صفحهٔ واقعی خودش می‌رود.
  for (const w of [1440, 768, 390, 320]) {
    await shot.setViewportSize({ width: w, height: 900 });
    await shot.goto(`${SITE}/tecteb-home-new/`, { waitUntil: 'load' });
    const routes = await shot.evaluate(() => [...document.querySelectorAll('.tecteb-home-v1 .th-route')].map((a) => {
      const r = a.getBoundingClientRect();
      return { cls: a.className.replace('th-route th-route--', ''), visible: r.width > 0 && r.height >= 44 && r.left >= 0 && r.right <= innerWidth + 0.5, title: a.querySelector('.th-route__title').textContent.trim() };
    }));
    check(`routes ${w}px: shop, repair, supplier — all three fully visible`, JSON.stringify(routes.map((r) => r.cls)) === JSON.stringify(['shop', 'repair', 'supplier']) && routes.every((r) => r.visible), JSON.stringify(routes));
    if (w === 1440) {
      const row = await shot.evaluate(() => [...document.querySelectorAll('.tecteb-home-v1 .th-route')].map((a) => Math.round(a.getBoundingClientRect().top)));
      check('routes 1440px: three cards side by side in one row', new Set(row).size === 1, JSON.stringify(row));
      check('routes: repair card title', routes[1].title === 'درخواست تعمیرات تجهیزات پزشکی', routes[1].title);
    }
  }
  for (const [cls, expect] of [['shop', '/shop/'], ['repair', '/medical-equipment-repair/'], ['supplier', '/supplier-application/']]) {
    await shot.goto(`${SITE}/tecteb-home-new/`, { waitUntil: 'load' });
    const [resp] = await Promise.all([shot.waitForNavigation(), shot.click(`.tecteb-home-v1 .th-route--${cls}`)]);
    check(`routes: «${cls}» card opens ${expect} (HTTP ${resp?.status()})`, resp?.status() === 200 && new URL(shot.url()).pathname === expect, shot.url());
  }
  await shot.goto(`${SITE}/shop/`, { waitUntil: 'load' });
  check('shop: the WooCommerce shop page lists products (unchanged archive)', await shot.locator('ul.products li.product').count() >= 4);

  // کاهش حرکت: هیچ انیمیشنی اجرا نمی‌شود و همه‌چیز دیدنی است.
  const calm = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  const cp = await calm.newPage();
  await cp.goto(`${SITE}/tecteb-home-new/`, { waitUntil: 'load' });
  const anim = await cp.evaluate(() => document.getAnimations().filter((a) => a.effect?.target?.closest?.('.tecteb-home-v1')).length);
  const hidden = await cp.evaluate(() => [...document.querySelectorAll('.tecteb-home-v1 .th-reveal, .tecteb-home-v1 .th-in')].filter((e) => getComputedStyle(e).opacity !== '1').length);
  check('motion: prefers-reduced-motion → no running animation', anim === 0, String(anim));
  check('motion: prefers-reduced-motion → every block fully visible', hidden === 0, String(hidden));
  const noJs = await browser.newContext({ viewport: { width: 390, height: 900 }, javaScriptEnabled: false });
  const np = await noJs.newPage();
  await np.goto(`${SITE}/tecteb-home-new/`, { waitUntil: 'load' });
  check('no JavaScript: page complete (4 sections, three routes, search form)', await np.locator('.tecteb-home-v1 > section').count() === 4 && await np.locator('.tecteb-home-v1 .th-route').count() === 3 && await np.locator('form[role="search"]').count() === 1);
  await calm.close(); await noJs.close();

  // ---------------------------------------------------------------- وضعیت تحویلی، به‌عنوان صفحهٔ اصلی
  state('shipped');
  state('front');
  await shoot('/', 'shipped-front', [1440, 390, 320]);
  await page.goto(`${SITE}/`, { waitUntil: 'load' });
  const shipped = await page.evaluate(() => ({
    sections: [...document.querySelectorAll('.tecteb-home-v1 > section')].map((s) => s.getAttribute('aria-labelledby')),
    routes: [...document.querySelectorAll('.tecteb-home-v1 .th-route')].map((a) => a.className),
    supplier: document.querySelectorAll('.th-route--supplier, #th-supplier-title').length,
    repair: document.querySelectorAll('.th-route--repair').length,
    products: [...document.querySelectorAll('.th-product__title')].map((h) => h.textContent.trim()),
  }));
  check('shipped state on the real front page: hero + categories + products only', JSON.stringify(shipped.sections) === JSON.stringify(['th-hero-title', 'th-cats-title', 'th-products-title']), JSON.stringify(shipped.sections));
  check('shipped state: only the shop route; no repair or supplier card until their addresses are set', JSON.stringify(shipped.routes) === JSON.stringify(['th-route th-route--shop']) && shipped.repair === 0 && shipped.supplier === 0, JSON.stringify(shipped));
  check('shipped state: the four selected products, in the selected order', JSON.stringify(shipped.products) === JSON.stringify(['مانیتور علائم حیاتی پنج‌پارامتری', 'پالس‌اکسیمتر انگشتی با نمایشگر دوخطی و قاب محافظ سیلیکونی', 'سرنگ‌پمپ تک‌کاناله', 'سانتریفیوژ رومیزی <b>آزمایش</b>']), JSON.stringify(shipped.products));

  // ---------------------------------------------------------------- صفحهٔ تنظیمات، با کلیک
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto(`${SITE}/wp-login.php`);
  await page.fill('#user_login', 'tthadmin');
  await page.fill('#user_pass', 'local-only-2026');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  check('admin: logged in', (await ctx.cookies()).some((c) => c.name.startsWith('wordpress_logged_in')));
  await page.goto(`${SITE}/wp-admin/admin.php?page=tecteb-homepage`);
  check('admin: settings page under WooCommerce renders', await page.locator('h1', { hasText: 'صفحهٔ اصلی تک‌طب' }).count() === 1);
  // --- محصولات: جست‌وجو با نام، افزودن، ترتیب، حذف
  await page.fill('#tth-pq', 'دستکش');
  await Promise.all([page.waitForNavigation(), page.click('#tth-pq-go')]);
  const addProduct = page.locator('button[name="tecteb_home_settings[add_product]"]');
  check('admin: product search by name returns an add button', await addProduct.count() >= 1, String(await addProduct.count()));
  await page.screenshot({ path: path.join(OUT, 'admin-product-search.png'), fullPage: true });
  await page.fill('#tth-hero-lead', 'متن تازهٔ آزمایشی که پس از افزودن محصول باید بماند.');
  await Promise.all([page.waitForNavigation(), addProduct.first().click()]);
  let saved = JSON.parse(state('show'));
  const ids = JSON.parse(wp('eval-file tests/fixtures/seed.php ids')).products;
  check('admin: add button saves the product at the end of the list', saved.products.length === 5 && saved.products[4] === ids.plain, JSON.stringify(saved.products));
  check('admin: text typed before adding is saved with it', saved.hero_lead === 'متن تازهٔ آزمایشی که پس از افزودن محصول باید بماند.');
  const featuredAfter = wp(`eval 'echo wc_get_product(${ids.plain})->get_featured() ? "yes" : "no";'`).trim();
  check('admin: selecting a product does not make it «featured» in WooCommerce', featuredAfter === 'no', featuredAfter);
  await page.fill('#tth-pq', String(ids.search));
  await Promise.all([page.waitForNavigation(), page.click('#tth-pq-go')]);
  check('admin: product search by numeric ID', await page.locator('td', { hasText: `#${ids.search}` }).count() >= 1);
  // ترتیب: دستکش (پنجمی) اول شود، و اکسیمتر (دومی) حذف شود.
  await page.fill('input[name="tecteb_home_settings[products][4][order]"]', '0');
  await page.check('input[name="tecteb_home_settings[products][1][remove]"]');
  await page.selectOption('#tth-count', '3');
  await Promise.all([page.waitForNavigation(), page.locator('#submit').first().click()]);
  saved = JSON.parse(state('show'));
  check('admin: product order and removal saved', JSON.stringify(saved.products) === JSON.stringify([ids.plain, ids.monitor, ids.pump, ids.centri]) && saved.product_count === 3, JSON.stringify(saved.products));
  await page.screenshot({ path: path.join(OUT, 'admin-settings.png'), fullPage: true });
  await page.goto(`${SITE}/`, { waitUntil: 'load' });
  const front = await page.locator('.th-product__title').allTextContents();
  check('admin → front: exactly those products, that order, that count', JSON.stringify(front.map((t) => t.trim())) === JSON.stringify(['دستکش جراحی غیرویژه', 'مانیتور علائم حیاتی پنج‌پارامتری', 'سرنگ‌پمپ تک‌کاناله']), JSON.stringify(front));

  // --- دسته‌ها: همان روش ۰٫۱٫۰
  await page.goto(`${SITE}/wp-admin/admin.php?page=tecteb-homepage`);
  await page.fill('#tth-q', 'اتاق');
  await Promise.all([page.waitForNavigation(), page.click('#tth-q-go')]);
  check('admin: an already selected category is marked, not offered again', await page.locator('td', { hasText: 'انتخاب‌شده' }).count() >= 1);
  await page.fill('#tth-q', 'تجهیزات');
  await Promise.all([page.waitForNavigation(), page.click('#tth-q-go')]);
  const addButtons = page.locator('button[name="tecteb_home_settings[add_category]"]');
  check('admin: category search returns an add button', await addButtons.count() >= 1, String(await addButtons.count()));
  await Promise.all([page.waitForNavigation(), addButtons.first().click()]);
  saved = JSON.parse(state('show'));
  check('admin: add button saves the category', saved.categories.length === 5, JSON.stringify(saved.categories));
  await page.fill('input[name="tecteb_home_settings[categories][4][order]"]', '0');
  await page.check('input[name="tecteb_home_settings[categories][0][remove]"]');
  // --- مقصد تعمیرات: ناامن رد می‌شود، معتبر کارت را نشان می‌دهد
  await page.fill('#tth-link-repair', 'javascript:alert(1)');
  await Promise.all([page.waitForNavigation(), page.locator('#submit').first().click()]);
  const after = JSON.parse(state('show'));
  check('admin: category order and remove applied', after.categories.length === 4 && after.categories[0].id === saved.categories[4].id && !after.categories.some((c) => c.id === saved.categories[0].id), JSON.stringify(after.categories));
  check('admin: unsafe repair link refused with a visible message', after.links.repair === '' && await page.locator('.notice-error, .error').filter({ hasText: 'پذیرفته نشد' }).count() >= 1);
  check('admin: status table says the repair route is hidden and why', await page.locator('td', { hasText: 'نشانی صفحهٔ تعمیرات تعیین نشده است' }).count() === 1);
  await page.fill('#tth-link-repair', '/medical-equipment-repair/');
  await Promise.all([page.waitForNavigation(), page.locator('#submit').first().click()]);
  await page.goto(`${SITE}/`, { waitUntil: 'load' });
  const first = await page.locator('.th-cat__name').first().textContent();
  check('admin → front: new first category is first on the homepage', first.trim() === 'تجهیزات پزشکی', first);
  check('admin → front: repair card appears once its address is saved', await page.locator('.tecteb-home-v1 .th-route--repair').count() === 1);

  const subPage = await browser.newPage();
  await subPage.goto(`${SITE}/wp-admin/admin.php?page=tecteb-homepage`);
  check('admin: logged-out visitor is sent to login', subPage.url().includes('wp-login.php'));
  await subPage.close();
} finally {
  state('unfront');
  state('shipped');
  await browser.close();
}

const failed = results.filter((r) => !r.ok);
fs.writeFileSync(path.join(OUT, 'results.txt'), results.map((r) => `${r.ok ? 'PASS' : 'FAIL'} ${r.name}${r.ok ? '' : '  — ' + r.detail}`).join('\n') + `\n\n${results.length - failed.length} passed, ${failed.length} failed\n`);
console.log(`\n${results.length - failed.length} passed, ${failed.length} failed`);
process.exit(failed.length ? 1 : 0);
