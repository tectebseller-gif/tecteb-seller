/*
 * ۰٫۳٫۳ — مدیریت «برندهای همکار» و «برچسب‌های کنار تصویر Hero» در «ووکامرس ← صفحهٔ اصلی»، با فرم واقعی.
 *
 *   SITE=… WP="wp --allow-root --path=…" AXE=<axe.min.js> NODE_PATH=… OUT=tests/evidence/admin-brands \
 *   node tests/browser/admin-brands.mjs
 *
 * افزودن (با کتابخانهٔ رسانهٔ واقعی وردپرس)، ویرایش، مرتب‌سازی، غیرفعال‌سازی، حذف، لوگوی نامعتبر، نشانی
 * نامعتبر، حفظ بقیهٔ تنظیمات، nonce و مجوز. پیش‌نیاز: seed.php seed، state.php full.
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
const OUT = process.env.OUT || path.join(PLUGIN, 'tests/evidence/admin-brands');
fs.mkdirSync(OUT, { recursive: true });
// فقط فرمان‌های ثابتِ wp-cli روی وردپرس یکبارمصرف محلی؛ هیچ ورودی بیرونی.
const wp = (cmd) => execSync(`${WP} ${cmd} 2>/dev/null`, { cwd: PLUGIN }).toString().trim();
const results = [];
const check = (name, ok, detail = '') => results.push(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail ? '  — ' + detail : ''}`);
const opt = () => JSON.parse(wp('eval-file tests/fixtures/hero-brands.php normalized'));
// «بقیهٔ تنظیمات»: هر کلید (جز brands)، مقدار به مقدار، روی شکل نرمال‌شده — همان که افزونه می‌خواند.
const others = (o, keys = Object.keys(before)) => JSON.stringify(keys.filter((k) => k !== 'brands').map((k) => [k, o[k]]));
const PAGE = `${SITE}/wp-admin/admin.php?page=tecteb-homepage`;

wp('eval-file tests/fixtures/hero-brands.php reset');
const logos = JSON.parse(wp('eval-file tests/fixtures/hero-brands.php brands 3'));
wp('eval-file tests/fixtures/hero-brands.php no-brands');
const pdf = wp('eval-file tests/fixtures/hero-brands.php pdf');
wp('eval-file tests/fixtures/hero-brands.php editor');
wp('eval-file tests/fixtures/state.php front');
const before = opt();

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const login = async (ctx, user, pass) => { const p = await ctx.newPage(); await p.goto(`${SITE}/wp-login.php`); await p.fill('#user_login', user); await p.fill('#user_pass', pass); await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]); return p; };
const front = async (ctx) => { const p = await ctx.newPage(); await p.goto(`${SITE}/`, { waitUntil: 'networkidle' }); const r = await p.evaluate(() => [...document.querySelectorAll('.th-brands__list img')].map((i) => i.alt)); await p.close(); return r; };
const save = async (a, button = '#submit') => { await Promise.all([a.waitForNavigation(), a.click(button)]); };
try {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const a = await login(ctx, 'tthadmin', 'local-only-2026');
  await a.goto(PAGE, { waitUntil: 'load' });
  check('admin: «برندهای همکار» section, empty state says the homepage section is hidden', await a.locator('#tth-brands').count() === 1 && (await a.content()).includes('هنوز برندی ثبت نشده است'));
  check('admin: «برچسب‌های کنار تصویر Hero» section with four rows', await a.locator('tr[data-tth-hero-label]').count() === 4);
  check('front: no brand ⇒ no section', (await front(ctx)).length === 0);

  // افزودن با کتابخانهٔ رسانهٔ واقعی وردپرس.
  await a.fill('#tth-new-brand-name', 'برند آزمایشی یک');
  await a.click('button[data-tth-target="tth-new-brand-logo"]');
  await a.waitForSelector('.media-modal', { state: 'visible' });
  check('admin: «انتخاب لوگو» opens the WordPress media library (images only)', await a.locator('.media-modal').isVisible());
  await a.click(`.media-modal li.attachment[data-id="${logos[0]}"]`);
  await a.click('.media-modal .media-button-select');
  await a.waitForSelector('.media-modal', { state: 'hidden' });
  check('admin: picking an image writes its attachment ID and shows a preview', await a.inputValue('#tth-new-brand-logo') === String(logos[0]) && await a.locator('[data-tth-preview="tth-new-brand-logo"] img').count() === 1);
  await a.fill('#tth-new-brand-url', 'https://example.org/brand-one');
  await save(a, '#tth-add-brand');
  check('admin: «افزودن برند و ذخیره» adds the brand (one row)', await a.locator('tr[data-tth-brand]').count() === 1);
  check('front: the new brand appears, alt = its name, linked to its URL', JSON.stringify(await front(ctx)) === JSON.stringify(['برند آزمایشی یک']));

  // دومی بی جاوااسکریپتِ کتابخانه: شناسه را مستقیم بنویس؛ بی نشانی.
  await a.fill('#tth-new-brand-name', 'برند آزمایشی دو');
  await a.fill('#tth-new-brand-logo', String(logos[1]));
  await save(a, '#tth-add-brand');
  await a.fill('#tth-new-brand-name', 'برند آزمایشی سه');
  await a.fill('#tth-new-brand-logo', String(logos[2]));
  await save(a, '#tth-add-brand');
  check('front: three brands in the order they were added', JSON.stringify(await front(ctx)) === JSON.stringify(['برند آزمایشی یک', 'برند آزمایشی دو', 'برند آزمایشی سه']));

  // ویرایش نام و ترتیب.
  await a.fill('input[name="tecteb_home_settings[brands][0][name]"]', 'برند آزمایشی یک (ویرایش)');
  await a.fill('input[name="tecteb_home_settings[brands][0][order]"]', '9');
  await save(a);
  check('admin: edit name + order ⇒ saved; front order follows the order column', JSON.stringify(await front(ctx)) === JSON.stringify(['برند آزمایشی دو', 'برند آزمایشی سه', 'برند آزمایشی یک (ویرایش)']), JSON.stringify(await front(ctx)));

  // غیرفعال‌سازی.
  await a.uncheck('input[name="tecteb_home_settings[brands][0][active]"]');
  await save(a);
  check('admin: inactive ⇒ kept in the list, gone from the homepage', await a.locator('tr[data-tth-brand]').count() === 3 && JSON.stringify(await front(ctx)) === JSON.stringify(['برند آزمایشی سه', 'برند آزمایشی یک (ویرایش)']));
  await a.check('input[name="tecteb_home_settings[brands][0][active]"]');
  await save(a);

  // لوگوی نامعتبر و نشانی نامعتبر.
  await a.fill('#tth-new-brand-name', 'برند لوگوی نامعتبر');
  await a.fill('#tth-new-brand-logo', pdf);
  await a.fill('#tth-new-brand-url', 'javascript:alert(1)');
  await save(a, '#tth-add-brand');
  const notice = await a.locator('.notice, .error, .settings-error').allTextContents();
  check('admin: a non-image logo is reported and the brand kept inactive (not on the homepage)', notice.join(' ').includes('تصویر معتبری از کتابخانهٔ رسانه نیست') && !(await front(ctx)).includes('برند لوگوی نامعتبر'), notice.join(' | '));
  check('admin: a javascript: URL is reported and left empty', notice.join(' ').includes('پذیرفته نشد') && opt().brands.find((b) => b.name === 'برند لوگوی نامعتبر').url === '');

  // حذف.
  const n = await a.locator('tr[data-tth-brand]').count();
  await a.check('input[name="tecteb_home_settings[brands][3][remove]"]');
  await save(a);
  check('admin: «حذف» removes the brand', await a.locator('tr[data-tth-brand]').count() === n - 1 && !opt().brands.some((b) => b.name === 'برند لوگوی نامعتبر'));
  const now = opt();
check('admin: every other homepage setting untouched by all of the above', others(now) === others(before) && JSON.stringify(now.hero_labels) === '[0,0,0,0]' && now.front_page === false, JSON.stringify(Object.keys(before).filter((k) => k !== 'brands' && JSON.stringify(now[k]) !== JSON.stringify(before[k])).map((k) => [k, before[k], now[k]])));

  await a.locator('#tth-brands').scrollIntoViewIfNeeded();
  await a.screenshot({ path: path.join(OUT, 'admin-brands.png') });
  await a.locator('#tth-hero-labels').scrollIntoViewIfNeeded();
  await a.screenshot({ path: path.join(OUT, 'admin-hero-labels.png') });
  if (AXE) {
    await a.addScriptTag({ content: AXE });
    const v = await a.evaluate(async () => (await axe.run(document.querySelector('#wpbody-content'), { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((x) => x.id + ':' + x.nodes.map((nn) => nn.target.join(' ')).slice(0, 2).join('|')));
    check('admin page: axe wcag2a/aa — 0 violations', v.length === 0, v.join(','));
  }

  // nonce: همان فرم با nonce ِ دست‌کاری‌شده رد می‌شود و هیچ چیزی نمی‌نویسد.
  const snap = JSON.stringify(opt());
  const res = await a.request.post(`${SITE}/wp-admin/options.php`, { form: { option_page: 'tecteb_home', action: 'update', _wpnonce: 'bad', _wp_http_referer: '/wp-admin/admin.php?page=tecteb-homepage', 'tecteb_home_settings[add_brand][name]': 'نفوذی', 'tecteb_home_settings[add_brand][logo]': String(logos[0]) }, maxRedirects: 0 });
  check('security: a POST with a bad nonce is refused and changes nothing', res.status() >= 400 && JSON.stringify(opt()) === snap, String(res.status()));
  await ctx.close();

  // مجوز: کاربری بی manage_woocommerce.
  const ectx = await browser.newContext();
  const e = await login(ectx, 'tth_editor', 'local-only-editor');
  const r1 = await e.goto(PAGE);
  const denied = r1.status() >= 400 || (await e.content()).includes('not allowed') || (await e.content()).includes('اجازه');
  const nonce = await e.evaluate(async (url) => (await (await fetch(url)).text()).match(/name="_wpnonce" value="([^"]+)"/)?.[1] || '', PAGE);
  const r2 = await e.request.post(`${SITE}/wp-admin/options.php`, { form: { option_page: 'tecteb_home', action: 'update', _wpnonce: nonce || 'none', 'tecteb_home_settings[add_brand][name]': 'نفوذی', 'tecteb_home_settings[add_brand][logo]': String(logos[0]) }, maxRedirects: 0 });
  check('security: a user without manage_woocommerce can neither open the page nor save', denied && r2.status() >= 400 && JSON.stringify(opt()) === snap, `${r1.status()} ${r2.status()}`);
  await ectx.close();
} finally {
  await browser.close();
  wp('eval-file tests/fixtures/hero-brands.php reset');
  wp('eval-file tests/fixtures/state.php unfront');
}
const pass = results.filter((r) => r.startsWith('PASS')).length;
fs.writeFileSync(path.join(OUT, 'results.txt'), results.join('\n') + `\n\n${pass} passed, ${results.length - pass} failed\n`);
console.log(results.join('\n') + `\n\n${pass} passed, ${results.length - pass} failed`);
process.exit(pass === results.length ? 0 : 1);
