/*
 * آزمون مرورگری tecteb-header روی وردپرس یکبارمصرفِ محلی (Chromium واقعی + axe-core).
 *
 *   NODE_PATH=/opt/node-tools/node_modules node tests/browser.mjs
 *
 * پیش‌نیاز: tests/fixtures/seed.php seed و configure on، سرور php روی 8090، و سرور
 * ایستای نمونه روی 8095 (برای مقایسهٔ ابعاد با نمونهٔ تأییدشدهٔ B).
 * خروجی: evidence/browser/results.txt، axe.txt و تصویرها.
 */
import { createRequire } from 'module';
import { execFileSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const req = createRequire(process.env.NODE_PATH + '/');
const { chromium } = req('playwright');
const HERE = path.dirname(new URL(import.meta.url).pathname);
const OUT = path.join(HERE, '..', 'evidence', 'browser');
fs.mkdirSync(OUT, { recursive: true });
const AXE = fs.readFileSync(process.env.AXE || '/tmp/claude-0/-home-user-tecteb-seller/abc7dd8b-6e40-5d40-8130-3e6a187d547a/scratchpad/axe/package/axe.min.js', 'utf8');
const SITE = 'http://127.0.0.1:8090';
const PROTO = 'http://127.0.0.1:8095/header/b-inner.html';
const WP = (...a) => execFileSync('wp', ['--allow-root', '--path=/home/user/wp/core', ...a], { encoding: 'utf8' }).trim();
const opt = () => JSON.parse(WP('option', 'get', 'tecteb_header_settings', '--format=json'));
const ids = JSON.parse(WP('eval-file', path.join(HERE, 'fixtures/seed.php'), 'ids'));

const lines = [];
const axeLines = [];
let pass = 0, fail = 0;
const ok = (name, cond, detail = '') => {
  cond ? pass++ : fail++;
  lines.push(`${cond ? 'ok   ' : 'FAIL '} ${name}${!cond && detail ? '  — ' + detail : ''}`);
};

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const errorsOf = (page) => { const e = []; page.on('pageerror', (x) => e.push(x.message)); page.on('console', (m) => m.type() === 'error' && !/favicon|ERR_TUNNEL|gravatar|net::ERR/.test(m.text()) && e.push(m.text())); return e; };
async function axe(page, label, include = '[data-tth-header]') {
  await page.addScriptTag({ content: AXE });
  const r = await page.evaluate(async (inc) => (await window.axe.run({ include: [inc] }, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'], rules: { region: { enabled: false } } })).violations.map((v) => `${v.id} (${v.nodes.length}): ${v.nodes[0].target.join(' ')}`), include);
  axeLines.push(`${label}: ${r.length ? r.join(' | ') : 'none'}`);
  ok(`axe ${label}`, r.length === 0, r.join(' | '));
}
async function login(ctx, user) {
  const p = await ctx.newPage();
  await p.goto(`${SITE}/wp-login.php`);
  await p.fill('#user_login', user);
  await p.fill('#user_pass', 'local-only-2026');
  await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
  const cookies = await ctx.cookies();
  if (!cookies.some((c) => c.name.startsWith('wordpress_logged_in'))) throw new Error('login failed: ' + user);
  return p;
}

// ------------------------------------------------------------------ ۱. جلوی سایت، جایگزینی روشن
WP('eval-file', path.join(HERE, 'fixtures/seed.php'), 'configure', 'on');
for (const [w, h] of [[1440, 900], [1024, 768], [768, 1024], [390, 844], [320, 640]]) {
  for (const [label, url] of [['home', '/'], ['inner', '/tthh-about/']]) {
    const page = await browser.newPage({ viewport: { width: w, height: h } });
    const errs = errorsOf(page);
    await page.goto(SITE + url, { waitUntil: 'networkidle' });
    const r = await page.evaluate(() => ({
      plugin: document.querySelectorAll('[data-tth-header]').length,
      original: document.querySelectorAll('[data-tthh-original], .elementor-location-header').length,
      footer: document.querySelectorAll('[data-tthh-footer]').length,
      overflow: document.documentElement.scrollWidth - window.innerWidth,
      font: getComputedStyle(document.querySelector('.tecteb-header-v1')).fontFamily,
      banner: document.querySelectorAll('[role="banner"]').length,
      js: document.querySelector('[data-tth-header]').hasAttribute('data-js'),
      bar: Math.round(document.querySelector('.tth-bar').getBoundingClientRect().height),
    }));
    const tag = `${label}@${w}`;
    ok(`${tag}: exactly one header (plugin), Elementor 2652 not printed`, r.plugin === 1 && r.original === 0, JSON.stringify(r));
    ok(`${tag}: Elementor footer still printed once`, r.footer === 1);
    ok(`${tag}: one banner landmark`, r.banner === 1);
    ok(`${tag}: no horizontal overflow`, r.overflow <= 0, String(r.overflow));
    ok(`${tag}: design font TTH Vazirmatn`, /TTH Vazirmatn/.test(r.font));
    ok(`${tag}: header.js ran`, r.js);
    ok(`${tag}: no console/page errors`, errs.length === 0, errs.join(' | '));
    await axe(page, tag);
    if (label === 'inner' || w <= 390) await page.screenshot({ path: path.join(OUT, `front-${tag}.png`) });
    await page.close();
  }
}

// ابعاد = نمونهٔ تأییدشدهٔ B (صفحهٔ داخلی، همان ردیف بالا و ناوبری). نمونه نماد علاقه‌مندی دارد،
// پس برای مقایسهٔ هم‌ارز، حالت «پیوند» موقتاً روشن می‌شود.
WP('eval', 'update_option("tecteb_header_settings", ["wishlist_mode"=>"link","wishlist_url"=>"/wishlist/"] + get_option("tecteb_header_settings"));');
for (const w of [1440, 1024, 390]) {
  const dims = async (url) => {
    const p = await browser.newPage({ viewport: { width: w, height: 900 } });
    await p.goto(url, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    const d = await p.evaluate(() => {
      const box = (s) => { const e = document.querySelector('.tecteb-header-v1 ' + s); if (!e || !e.offsetParent && getComputedStyle(e).position !== 'fixed') return null; const r = e.getBoundingClientRect(); return [Math.round(r.height), Math.round(r.width)]; };
      const top = document.querySelector('.tecteb-header-v1').getBoundingClientRect().top;
      return { top: Math.round(top), bar: box('.tth-bar'), row: box('.tth-top'), logo: box('.tth-logo__mark'), word: box('.tth-logo__word'), search: box('.tth-search'), nav: box('.tth-nav'), cats: box('.tth-cats__btn.tth-js'), seller: box('.tth-nav .tth-seller') };
    });
    await p.close();
    return d;
  };
  const a = await dims(`${SITE}/tthh-about/`);
  const b = await dims(PROTO);
  const keys = ['bar', 'row', 'logo', 'word', 'search', 'nav', 'cats', 'seller'];
  const diff = keys.filter((k) => JSON.stringify(a[k]) !== JSON.stringify(b[k]) && !(a[k] && b[k] && Math.abs(a[k][0] - b[k][0]) <= 1 && Math.abs(a[k][1] - b[k][1]) <= 2));
  ok(`dimensions @${w} equal approved prototype B (±1px h, ±2px w)`, diff.length === 0, diff.map((k) => `${k} plugin=${a[k]} proto=${b[k]}`).join('; '));
  if (w === 1440) ok('desktop header height 122px (round 8)', a.bar && a.bar[0] === 122, String(a.bar));
}
WP('eval', 'update_option("tecteb_header_settings", ["wishlist_mode"=>"off"] + get_option("tecteb_header_settings"));');

// ------------------------------------------------------------------ ۲. تعامل دسکتاپ
{
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  await page.goto(`${SITE}/tthh-about/`, { waitUntil: 'networkidle' });
  const btn = page.locator('.tth-cats button[data-tth-toggle]');
  await btn.click();
  ok('mega: opens on click, aria-expanded=true', (await btn.getAttribute('aria-expanded')) === 'true' && await page.locator('#tth-mega').isVisible());
  const mains = await page.$$eval('.tth-mega__name', (e) => e.map((x) => x.textContent));
  ok('mega: 13 categories in configured order', mains.length === 13 && mains[0] === 'پزشکی جنرال' && mains[12] === 'بیهوشی و تنفسی', mains.join('،'));
  const hrefs = await page.$$eval('.tth-mega__main', (e) => e.map((x) => x.getAttribute('href')));
  ok('mega: links are product_cat archive URLs', hrefs.every((h) => /\/product-category\//.test(h)), hrefs[0]);
  await page.hover('.tth-mega__item:nth-child(2) .tth-mega__main');
  await page.waitForTimeout(250);
  const sub = await page.locator('.tth-mega__item.is-active .tth-mega__list').innerText();
  ok('mega: hover switches pane to its subcategories', /مانیتورینگ قلب/.test(sub) && /الکتروکاردیوگراف/.test(sub), sub);
  ok('mega: icon from Media Library (WebP)', /\.webp$/.test(await page.getAttribute('.tth-mega__item:nth-child(2) .tth-mega__icon img', 'src')));
  await page.screenshot({ path: path.join(OUT, 'desktop-mega.png') });
  await page.keyboard.press('Escape');
  ok('mega: Esc closes, focus returns to button', (await btn.getAttribute('aria-expanded')) === 'false' && await page.evaluate(() => document.activeElement.matches('.tth-cats button')));
  await btn.focus();
  await page.keyboard.press('Enter');
  await page.keyboard.press('Tab');
  await page.keyboard.press('Tab');
  ok('mega: keyboard reaches categories', await page.evaluate(() => !!document.activeElement.closest('#tth-mega')));
  await page.keyboard.press('Escape');
  // حالت جمع‌وجور بی جابه‌جایی محتوا
  await page.evaluate(() => { const d = document.createElement('div'); d.style.height = '3000px'; document.body.append(d); });
  const before = await page.evaluate(() => document.querySelector('main, #content, .site-main, footer').getBoundingClientRect().top + scrollY);
  await page.mouse.wheel(0, 600);
  await page.waitForTimeout(400);
  const after = await page.evaluate(() => ({ y: document.querySelector('main, #content, .site-main, footer').getBoundingClientRect().top + scrollY, compact: document.querySelector('.tecteb-header-v1').classList.contains('is-compact'), h: Math.round(document.querySelector('.tth-bar').getBoundingClientRect().height) }));
  ok('sticky: compact after scroll (63px) and content did not move', after.compact && after.h === 63 && Math.abs(after.y - before) < 1, JSON.stringify({ before, ...after }));
  ok('guest: account link → my-account', /\/my-account\/$/.test(await page.getAttribute('[data-tth-guest].tth-tool', 'href')));
  ok('wishlist off: no heart icon', (await page.locator('.tth-tool--wish').count()) === 0);
  ok('nav: WP menu items, hidden ones skipped', (await page.$$eval('.tth-menu a', (e) => e.map((x) => x.textContent).join('|'))) === 'صفحه اصلی|فروشگاه|وبلاگ|درخواست تعمیرات|درباره تک طب|تماس با ما !');
  ok('nav: current page marked aria-current', (await page.getAttribute('.tth-menu a[href$="/tthh-about/"]', 'aria-current')) === 'page');
  await page.close();
}

// ------------------------------------------------------------------ ۳. موبایل: کشو و جست‌وجو
{
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  await page.goto(`${SITE}/`, { waitUntil: 'networkidle' });
  ok('home mobile: search folded into a toggle', await page.locator('[data-tth-search-toggle]').isVisible() && !(await page.locator('#tth-search-form').isVisible()));
  await page.click('[data-tth-search-toggle]');
  ok('home mobile: toggle opens the same search form, focused', await page.locator('#tth-search-form').isVisible() && await page.evaluate(() => document.activeElement.id === 'tth-s'));
  await page.keyboard.press('Escape');
  await page.click('[data-tth-burger]');
  await page.waitForTimeout(300);
  ok('drawer: opens as modal dialog', await page.evaluate(() => document.querySelector('#tth-drawer').open));
  const drawerCats = await page.$$eval('.tth-drawer__name', (e) => e.map((x) => x.textContent));
  const megaCats = await page.$$eval('.tth-mega__name', (e) => e.map((x) => x.textContent));
  ok('drawer and mega: same categories from one source', JSON.stringify(drawerCats) === JSON.stringify(megaCats) && drawerCats.length === 13);
  const dNav = await page.$$eval('#tth-drawer nav a', (e) => e.map((x) => x.textContent).join('|'));
  const mNav = await page.$$eval('.tth-menu a', (e) => e.map((x) => x.textContent).join('|'));
  ok('drawer and desktop: same nav items', dNav === mNav, dNav + ' / ' + mNav);
  await page.click('.tth-drawer__cat:nth-of-type(2) summary');
  await page.waitForTimeout(300);
  await axe(page, 'drawer open @390', '#tth-drawer');
  await page.screenshot({ path: path.join(OUT, 'mobile-drawer.png') });
  await page.keyboard.press('Escape');
  ok('drawer: Esc closes, focus back on burger', await page.evaluate(() => !document.querySelector('#tth-drawer').open && document.activeElement.matches('[data-tth-burger]')));
  await page.goto(`${SITE}/tthh-about/`, { waitUntil: 'networkidle' });
  ok('inner mobile: search form visible, toggle hidden', await page.locator('#tth-search-form').isVisible() && !(await page.locator('[data-tth-search-toggle]').isVisible()));
  await page.fill('#tth-s', 'نمونه');
  await Promise.all([page.waitForNavigation(), page.press('#tth-s', 'Enter')]);
  const res = await page.content();
  ok('search: standard WooCommerce product search works', /post_type=product/.test(page.url()) && /یونیت دندانپزشکی نمونه/.test(res) && /دستگاه نوار قلب نمونه/.test(res), page.url());
  await page.close();
}

// ------------------------------------------------------------------ ۴. سبد و حساب (واقعی)
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(`${SITE}/tthh-about/`, { waitUntil: 'networkidle' });
  ok('cart empty: no badge', (await page.locator('.tth-tool--cart .tth-badge').count()) === 0);
  await page.goto(`${SITE}/?add-to-cart=${ids.products[0]}`, { waitUntil: 'networkidle' });
  await page.goto(`${SITE}/tthh-about/`, { waitUntil: 'networkidle' });
  ok('cart: WooCommerce count shown (۱)', (await page.locator('.tth-tool--cart .tth-badge').textContent()) === '۱');
  ok('cart: link = WooCommerce cart URL', /\/cart\/$/.test(await page.getAttribute('.tth-tool--cart', 'href')));
  await ctx.close();
  const actx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const ap = await login(actx, 'tthadmin');
  await ap.goto(`${SITE}/tthh-about/`, { waitUntil: 'networkidle' });
  await ap.click('.tth-acct button[data-tth-toggle]');
  const items = await ap.$$eval('#tth-acct-menu a', (e) => e.map((x) => [x.textContent.trim(), x.getAttribute('href')]));
  ok('member: account menu = WooCommerce account items', items.length >= 5 && items.some(([, h]) => /orders/.test(h)) && items.some(([, h]) => /logout.*_wpnonce=/.test(h)), JSON.stringify(items));
  await axe(ap, 'member account menu open');
  await ap.screenshot({ path: path.join(OUT, 'member-menu.png') });
  await actx.close();
}

// ------------------------------------------------------------------ ۵. بی جاوااسکریپت
{
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, javaScriptEnabled: false });
  const page = await ctx.newPage();
  await page.goto(`${SITE}/tthh-about/`);
  ok('no-JS: categories is a link to the shop', /\/shop\/$/.test(await page.getAttribute('.tth-cats__btn.tth-nojs', 'href')) && await page.locator('.tth-cats__btn.tth-nojs').isVisible());
  ok('no-JS: nav links visible on mobile', await page.locator('.tth-menu a').first().isVisible());
  await page.screenshot({ path: path.join(OUT, 'nojs-390.png'), fullPage: false });
  await ctx.close();
}

// ------------------------------------------------------------------ ۶. پیشخوان
{
  // ویرایشگر: نه صفحه، نه ذخیره
  const ectx = await browser.newContext();
  const ep = await login(ectx, 'tthheditor');
  const resp = await ep.goto(`${SITE}/wp-admin/admin.php?page=tecteb-header`);
  ok('editor: dashboard page refused', resp.status() === 403 || /not allowed|اجازه/.test(await ep.content()), String(resp.status()));
  const before = WP('option', 'get', 'tecteb_header_settings', '--format=json');
  const r = await ep.request.post(`${SITE}/wp-admin/options.php`, { form: { option_page: 'tecteb_header', action: 'update', 'tecteb_header_settings[_tab]': 'replace', 'tecteb_header_settings[replace]': '' } });
  ok('editor: POST to options.php refused, option unchanged', r.status() === 403 && before === WP('option', 'get', 'tecteb_header_settings', '--format=json'), String(r.status()));
  await ectx.close();

  const actx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const ap = await login(actx, 'tthadmin');
  const errs = errorsOf(ap);
  await ap.goto(`${SITE}/wp-admin/admin.php?page=tecteb-header`, { waitUntil: 'networkidle' });
  const menuTxt = await ap.$$eval('#adminmenu .wp-has-current-submenu .wp-menu-name, #adminmenu .wp-has-current-submenu .wp-submenu a', (e) => e.map((x) => x.textContent.trim()));
  ok('admin: «تک‌طب ← مدیریت هدر» in the menu', menuTxt.includes('تک‌طب') && menuTxt.includes('مدیریت هدر'), menuTxt.join('|'));
  ok('admin: 13 cards', (await ap.locator('[data-tth-card]').count()) === 13);
  await axe(ap, 'admin categories tab', '.tth-hdr');
  await ap.screenshot({ path: path.join(OUT, 'admin-categories.png'), fullPage: true });
  // nonce دست‌کاری‌شده
  const fields = await ap.evaluate(() => Object.fromEntries(new FormData(document.querySelector('form[action$="options.php"]'))));
  fields._wpnonce = 'deadbeef00';
  delete fields['tecteb_header_settings[categories][0][enabled]'];
  const tr = await ap.request.post(`${SITE}/wp-admin/options.php`, { form: fields, maxRedirects: 0 });
  const tampered = [tr.status(), await tr.text()];
  ok('admin: tampered nonce refused, option unchanged', tampered[0] === 403 && /expired|منقضی/.test(tampered[1]) && opt().categories[0].enabled === true, String(tampered[0]));
  // جابه‌جایی با JS، خاموش‌کردن، افزودن زیردسته، ذخیره
  await ap.click('[data-tth-card]:nth-child(2) [data-tth-move="up"]');
  await ap.uncheck('[data-tth-card]:nth-child(3) input[name$="[enabled]"]');
  const zanan = await ap.$eval('[data-tth-card]:nth-child(5) input[name$="[term_id]"]', (e) => e.value);
  const hasSel = await ap.locator('[data-tth-card]:nth-child(5) select').count();
  await Promise.all([ap.waitForNavigation(), ap.click('#submit')]);
  let o = opt();
  ok('admin: up button reorders and saves', o.categories[0].term_id === ids.mains['قلب و عروق'] && o.categories[1].term_id === ids.mains['پزشکی جنرال'], JSON.stringify(o.categories.slice(0, 2)));
  ok('admin: switch off saves enabled=false', o.categories[2].enabled === false);
  ok('admin: settings-updated notice shown', /Settings saved|تنظیمات ذخیره شد/.test(await ap.content()));
  ok('admin: zanan card has no descendants ⇒ no add-child select', hasSel === 0 && Number(zanan) === ids.mains['زنان و زایمان']);
  // افزودن زیردسته به دندانپزشکی (فقط زیرمجموعه‌ها در فهرست)
  const dIdx = o.categories.findIndex((c) => c.term_id === ids.mains['دندانپزشکی']) + 1;
  const optsTxt = await ap.$$eval(`[data-tth-card]:nth-child(${dIdx}) select option`, (e) => e.map((x) => x.value));
  ok('admin: add-child list only offers descendants not yet chosen', optsTxt.includes(String(ids.surgery2)) && !optsTxt.includes(String(ids.chair)) && !optsTxt.includes(String(ids.bed)));
  await ap.selectOption(`[data-tth-card]:nth-child(${dIdx}) select`, String(ids.surgery2));
  await Promise.all([ap.waitForNavigation(), ap.click('#submit')]);
  o = opt();
  ok('admin: child added', o.categories[dIdx - 1].children.map((k) => k.term_id).includes(ids.surgery2));
  // جست‌وجو و افزودن دستهٔ اصلی
  await ap.fill('#tth-find', 'ملزومات');
  await Promise.all([ap.waitForNavigation(), ap.press('#tth-find', 'Enter')]);
  await ap.check(`input[name="tecteb_header_settings[add_main][]"][value="${ids.other_top}"]`);
  await Promise.all([ap.waitForNavigation(), ap.click('#submit')]);
  o = opt();
  ok('admin: search + add main appends real ID', o.categories.at(-1).term_id === ids.other_top && o.categories.length === 14);
  // کتابخانهٔ رسانه
  await ap.click('[data-tth-card]:nth-child(1) [data-tth-media]');
  await ap.waitForSelector('.media-modal', { state: 'visible', timeout: 10000 });
  const lib = await ap.evaluate(() => { const f = wp.media.frame || Object.values(wp.media.frames || {})[0]; const st = f && f.state && f.state(); const t = st && st.get('library') && st.get('library').props.get('type'); return t; });
  ok('admin: Media Library opens, limited to PNG/WebP', JSON.stringify(lib) === JSON.stringify(['image/png', 'image/webp']), JSON.stringify(lib));
  await ap.screenshot({ path: path.join(OUT, 'admin-media.png') });
  await ap.keyboard.press('Escape');
  // زبانه‌ها
  for (const t of ['import', 'links', 'replace', 'data']) {
    await ap.goto(`${SITE}/wp-admin/admin.php?page=tecteb-header&tab=${t}`, { waitUntil: 'networkidle' });
    await axe(ap, `admin ${t} tab`, '.tth-hdr');
    await ap.screenshot({ path: path.join(OUT, `admin-${t}.png`), fullPage: true });
  }
  await ap.goto(`${SITE}/wp-admin/admin.php?page=tecteb-header&tab=import`);
  const baseline = await ap.$$eval('.tth-baseline tbody tr', (e) => e.map((r) => r.lastElementChild.textContent.trim()));
  ok('admin import: baseline 12 found, 1 not found (no guess)', baseline.filter((x) => x === 'پیدا شد').length === 12 && baseline.filter((x) => x === 'پیدا نشد').length === 1, baseline.join('|'));
  // جایگزینی خاموش از پیشخوان ⇒ هدر Elementor برمی‌گردد (بازگشت سریع)
  await ap.goto(`${SITE}/wp-admin/admin.php?page=tecteb-header&tab=replace`);
  await ap.uncheck('input[name="tecteb_header_settings[replace]"]');
  await Promise.all([ap.waitForNavigation(), ap.click('#submit')]);
  ok('rollback: one save turns replacement off', opt().replace === false && opt().categories.length === 14);
  ok('admin: no console errors', errs.length === 0, errs.join(' | '));
  const guest = await browser.newPage();
  await guest.goto(`${SITE}/tthh-about/`);
  ok('rollback: visitor sees Elementor 2652 again, plugin header absent', (await guest.locator('[data-tthh-original]').count()) === 1 && (await guest.locator('[data-tth-header]').count()) === 0);
  await guest.goto(`${SITE}/tthh-about/?tth_preview=1`);
  ok('preview param ignored for visitors', (await guest.locator('[data-tth-header]').count()) === 0);
  await guest.close();
  await ap.goto(`${SITE}/tthh-about/?tth_preview=1`, { waitUntil: 'networkidle' });
  ok('admin preview: plugin header in place of 2652, only one', (await ap.locator('[data-tth-header]').count()) === 1 && (await ap.locator('[data-tthh-original]').count()) === 0);
  await ap.goto(`${SITE}/wp-admin/admin.php?page=tecteb-header&tab=replace`);
  await ap.check('input[name="tecteb_header_settings[replace]"]');
  await Promise.all([ap.waitForNavigation(), ap.click('#submit')]);
  ok('replace back on', opt().replace === true);
  await actx.close();
}

await browser.close();
fs.writeFileSync(path.join(OUT, 'results.txt'), lines.join('\n') + `\n\n${pass} passed, ${fail} failed\n`);
fs.writeFileSync(path.join(OUT, 'axe.txt'), axeLines.join('\n') + '\n');
console.log(lines.filter((l) => l.startsWith('FAIL')).join('\n'));
console.log(`${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
