/*
 * ۰٫۳٫۱ — «نمایش روی صفحهٔ نخست سایت»، سرتاسری در مرورگر، به شکل Staging: «تنظیمات ← خواندن» یک برگهٔ
 * عادی با محتوای خودش را صفحهٔ نخست کرده و Header B فعال است.
 *
 *   SITE=http://127.0.0.1:8090 WP="wp --allow-root --path=/home/user/wp/core" \
 *   AXE=<axe.min.js> NODE_PATH=/opt/node-tools/node_modules node tests/browser/front-page.mjs
 *
 * پیش‌نیاز: header-plugin/tests/fixtures/seed.php seed + configure on، سپس tests/fixtures/seed.php seed.
 * خروجی: tests/evidence/front-page/
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
const OUT = path.join(PLUGIN, 'tests/evidence/front-page');
fs.mkdirSync(OUT, { recursive: true });
// فقط فرمان‌های ثابتِ wp-cli روی وردپرس یکبارمصرف محلی؛ هیچ ورودی بیرونی.
const wp = (cmd) => execSync(`${WP} ${cmd} 2>/dev/null`, { cwd: PLUGIN }).toString().trim();
const results = [];
const check = (name, ok, detail = '') => results.push(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail ? '  — ' + detail : ''}`);

wp('eval-file tests/fixtures/state.php full');
wp(`eval "update_option('tecteb_home_settings', array_merge(get_option('tecteb_home_settings'), array('front_page'=>false,'links'=>array('repair'=>'/https-tecteb-com-repair-request/','supplier'=>'/landing-seller/','supplier_terms'=>'','all_categories'=>'/shop/'))));"`);
const plain = wp(`eval "echo get_page_by_path('plain-page')->ID;"`);
const prevFront = [wp('option get show_on_front'), wp(`eval "echo (int) get_option('page_on_front');"`)];
wp('option update show_on_front page');
wp(`option update page_on_front ${plain}`);
const contentBefore = wp(`eval "echo md5(get_post(${plain})->post_content . serialize(get_post_meta(${plain})));"`);
const pagesBefore = wp(`eval "echo wp_count_posts('page')->publish;"`);

const isConceptC = async (p) => (await p.locator('#th-hero-title').count()) === 1;
const isPlain = async (p) => (await p.content()).includes('متن عادی.');

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
try {
  const guest = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const g = await guest.newPage();
  await g.goto(`${SITE}/`, { waitUntil: 'networkidle' });
  check('visitor, switch off: the Reading page as today (no Concept C)', await isPlain(g) && !(await isConceptC(g)));

  const admin = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const a = await admin.newPage();
  await a.goto(`${SITE}/wp-login.php`);
  await a.fill('#user_login', 'tthadmin'); await a.fill('#user_pass', 'local-only-2026');
  await Promise.all([a.waitForNavigation(), a.click('#wp-submit')]);
  await a.goto(`${SITE}/wp-admin/admin.php?page=tecteb-homepage`, { waitUntil: 'load' });
  check('admin: «نمایش روی صفحهٔ نخست سایت» section with the Reading page name', await a.locator('#tth-front').count() === 1 && (await a.content()).includes('صفحهٔ عادی'));
  check('admin: status says the front page does not show the design yet', await a.locator('[data-tth-front-state="off"]').count() === 1);
  await a.locator('#tth-front').scrollIntoViewIfNeeded();
  await a.screenshot({ path: path.join(OUT, 'admin-front-off.png') });
  await a.addScriptTag({ content: AXE });
  const av = await a.evaluate(async () => (await axe.run(document.querySelector('#wpbody-content'), { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((v) => v.id + ':' + v.nodes.map((n) => n.target.join(' ')).join('|')));
  check('admin page: axe wcag2a/aa — 0 violations', av.length === 0, av.join(','));

  // پیش‌نمایش فقط برای مدیر، پیش از روشن‌کردن.
  const previewHref = await a.getAttribute('a[href*="tecteb_home_preview=1"]', 'href');
  await a.goto(previewHref, { waitUntil: 'networkidle' });
  check('admin preview (?tecteb_home_preview=1): Concept C on the front page while the switch is off', await isConceptC(a) && !(await isPlain(a)) && await a.locator('[data-tth-header]').count() === 1);
  await g.goto(`${SITE}/?tecteb_home_preview=1`, { waitUntil: 'networkidle' });
  check('visitor with the preview parameter: still the Reading page', await isPlain(g) && !(await isConceptC(g)));

  // روشن‌کردن از فرم واقعی.
  await a.goto(`${SITE}/wp-admin/admin.php?page=tecteb-homepage`, { waitUntil: 'load' });
  await a.check('input[name="tecteb_home_settings[front_page]"]');
  await Promise.all([a.waitForNavigation(), a.click('#submit')]);
  check('admin: saved; status now says the front page shows Concept C', await a.locator('[data-tth-front-state="on"]').count() === 1);
  await a.locator('#tth-front').scrollIntoViewIfNeeded();
  await a.screenshot({ path: path.join(OUT, 'admin-front-on.png') });
  const saved = JSON.parse(wp(`eval "echo wp_json_encode(get_option('tecteb_home_settings'));"`));
  check('settings kept after saving the switch (products, categories, links)', saved.front_page === true && saved.products.length === 4 && saved.categories.length === 4 && saved.links.repair === '/https-tecteb-com-repair-request/');

  for (const [w, h] of [[1440, 900], [768, 1024], [390, 844], [320, 700]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, reducedMotion: 'reduce' });
    const p = await ctx.newPage();
    await p.goto(`${SITE}/`, { waitUntil: 'networkidle' });
    const r = await p.evaluate(() => {
      const vis = (e) => { if (!e) return false; const cs = getComputedStyle(e); const rc = e.getBoundingClientRect(); return cs.display !== 'none' && rc.width > 0 && rc.height > 0; };
      return { headers: document.querySelectorAll('[data-tth-header]').length, h1: document.querySelectorAll('h1').length, headerSearch: vis(document.querySelector('#tth-search-form')), heroSearch: vis(document.querySelector('.th-search')), overflow: document.documentElement.scrollWidth - innerWidth, plain: document.body.textContent.includes('متن عادی.') };
    });
    check(`visitor ${w}: Concept C on the front page, one header, one h1, no page content, no overflow`, await isConceptC(p) && !r.plain && r.headers === 1 && r.h1 === 1 && r.overflow <= 0, JSON.stringify(r));
    if (w < 768) check(`visitor ${w}: no duplicated search (header folded, Hero visible)`, !r.headerSearch && r.heroSearch, JSON.stringify(r));
    await p.addScriptTag({ content: AXE });
    const v = await p.evaluate(async () => (await axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'], rules: { region: { enabled: false } } })).violations.map((x) => x.id));
    check(`visitor ${w}: axe on the whole page — 0 violations`, v.length === 0, v.join(','));
    for (let y = 0; y < await p.evaluate(() => document.body.scrollHeight); y += 400) { await p.evaluate((v2) => window.scrollTo(0, v2), y); await p.waitForTimeout(50); }
    await p.evaluate(() => window.scrollTo(0, 0));
    await p.screenshot({ path: path.join(OUT, `front-on-${w}.png`), fullPage: true });
    await ctx.close();
  }
  await a.goto(`${SITE}/?tecteb_home_original=1`, { waitUntil: 'networkidle' });
  check('admin ?tecteb_home_original=1: the Reading page even with the switch on', await isPlain(a) && !(await isConceptC(a)));
  await g.goto(`${SITE}/?tecteb_home_original=1`, { waitUntil: 'networkidle' });
  check('visitor with the original parameter: Concept C (parameter is admin-only)', await isConceptC(g));
  await g.goto(`${SITE}/shop/`, { waitUntil: 'networkidle' });
  check('other pages untouched: /shop/ is the WooCommerce shop', !(await isConceptC(g)) && await g.locator('li.product').count() > 0);
  await g.goto(`${SITE}/`, { waitUntil: 'networkidle' });
  for (const [cls, slug] of [['th-route--shop', '/shop/'], ['th-route--repair', '/https-tecteb-com-repair-request/'], ['th-route--supplier', '/landing-seller/']]) {
    const [res] = await Promise.all([g.waitForNavigation(), g.click(`.${cls}`)]);
    check(`route ${slug} from the front page: 200`, res.status() === 200 && new URL(g.url()).pathname === slug, `${res.status()} ${g.url()}`);
    await g.goto(`${SITE}/`, { waitUntil: 'networkidle' });
  }

  // بازگشت فوری از همان فرم.
  const t0 = Date.now();
  await a.goto(`${SITE}/wp-admin/admin.php?page=tecteb-homepage`, { waitUntil: 'load' });
  await a.uncheck('input[name="tecteb_home_settings[front_page]"]');
  await Promise.all([a.waitForNavigation(), a.click('#submit')]);
  await g.goto(`${SITE}/`, { waitUntil: 'networkidle' });
  const took = ((Date.now() - t0) / 1000).toFixed(1);
  check(`rollback: untick + save ⇒ visitor sees the Reading page again (${took}s)`, await isPlain(g) && !(await isConceptC(g)));
  check('the Reading page itself never changed (content + meta), Reading settings unchanged, no new page', contentBefore === wp(`eval "echo md5(get_post(${plain})->post_content . serialize(get_post_meta(${plain})));"`) && plain === wp(`eval "echo (int) get_option('page_on_front');"`) && pagesBefore === wp(`eval "echo wp_count_posts('page')->publish;"`));
  await g.screenshot({ path: path.join(OUT, 'front-off-after-rollback-1440.png') });
} finally {
  await browser.close();
  wp(`option update show_on_front ${prevFront[0]}`);
  if (Number(prevFront[1]) > 0) wp(`option update page_on_front ${prevFront[1]}`); else wp('option delete page_on_front');
  wp(`eval "update_option('tecteb_home_settings', array_merge(get_option('tecteb_home_settings'), array('front_page'=>false)));"`);
}
const pass = results.filter((r) => r.startsWith('PASS')).length;
fs.writeFileSync(path.join(OUT, 'results.txt'), results.join('\n') + `\n\n${pass} passed, ${results.length - pass} failed\n`);
console.log(results.join('\n') + `\n\n${pass} passed, ${results.length - pass} failed`);
process.exit(pass === results.length ? 0 : 1);
