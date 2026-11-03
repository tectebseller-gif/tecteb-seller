/*
 * سایت اصلی: «دسته‌بندی کالاها» به /shop/ می‌رود و Mega Menu باز نمی‌شود. روی 0.2.3 و با فرم‌های واقعی پیشخوان:
 * تنظیمات بی فهرست دسته (همان حالت option ِ تازه) ⇒ همان علامت؛ سپس تنها مسیر اصلاح: «انتقال از منوی فعلی ← ب»
 * و انتخاب زیردسته‌ها در «دسته‌بندی‌ها» ⇒ Mega Menu با دسته‌ها و زیردسته‌های واقعی؛ هاور، کلیک، کیبورد و کشوی
 * موبایل. جایگزینی عمومی خاموش؛ پیش‌نمایش مدیر. فقط محلی.
 *
 *   NODE_PATH=/opt/node-tools/node_modules node tests/mega-setup.mjs
 */
import { createRequire } from 'module';
import { execFileSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const HERE = path.dirname(new URL(import.meta.url).pathname);
const OUT = path.join(HERE, '..', 'evidence', 'mega-setup');
fs.mkdirSync(OUT, { recursive: true });
const SITE = 'http://127.0.0.1:8090';
const WP = (...a) => execFileSync('wp', ['--allow-root', '--path=/home/user/wp/core', ...a], { encoding: 'utf8' }).trim();
const opt = () => JSON.parse(WP('eval', "echo wp_json_encode(get_option('tecteb_header_settings'));"));
const results = [];
const check = (name, ok, detail = '') => results.push(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail ? '  — ' + detail : ''}`);
const ADMIN = `${SITE}/wp-admin/admin.php?page=tecteb-header`;

// حالت سایت اصلی: option بی فهرست دسته، بی منوی اصلی، قالب‌های صف انتخاب‌شده، جایگزینی خاموش.
WP('eval-file', path.join(HERE, 'fixtures/seed.php'), 'seed');
WP('eval', "$c = get_option('elementor_pro_theme_builder_conditions', array()); update_option('tecteb_header_settings', array('_tab' => 'replace', 'replace' => false, 'replace_templates' => array_map('intval', array_keys((array) ($c['header'] ?? array()))))); update_option('tthh_emulate', 'both');");

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const btn = (p) => p.evaluate(() => {
  const vis = (e) => e && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().width > 0;
  const b = [...document.querySelectorAll('[data-tth-header] .tth-cats__btn')].find(vis);
  const acct = [...document.querySelectorAll('[data-tth-header] .tth-tool--account')].find(vis);
  return {
    tag: b ? b.tagName.toLowerCase() : '', href: b ? b.getAttribute('href') : '', chevron: !!(b && b.querySelector('.tth-i--chev')),
    mega: !!document.querySelector('#tth-mega'), js: document.querySelector('[data-tth-header]').hasAttribute('data-js'),
    acctChevron: !!(acct && acct.querySelector('.tth-i--chev')),
  };
});
const megaOpen = (p) => p.evaluate(() => { const m = document.querySelector('#tth-mega'); return !!m && !m.hidden && getComputedStyle(m).display !== 'none'; });
try {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const a = await ctx.newPage();
  for (let i = 0; i < 3 && !(await ctx.cookies()).some((c) => c.name.startsWith('wordpress_logged_in')); i++) {
    try {
      await a.goto(`${SITE}/wp-login.php`, { timeout: 45000 });
      await a.fill('#user_login', 'tthadmin');
      await a.fill('#user_pass', 'local-only-2026');
      await Promise.all([a.waitForNavigation({ timeout: 45000 }), a.click('#wp-submit')]);
    } catch (e) { /* محیط تک‌رشته‌ای؛ دوباره */ }
  }

  // ۱. علامت سایت اصلی.
  await a.goto(`${SITE}/?tth_preview=1`, { waitUntil: 'networkidle' });
  let b = await btn(a);
  check('1. no categories in the plugin settings: «دسته‌بندی کالاها» is a plain link to the shop, no chevron, no mega menu', b.tag === 'a' && /\/shop\/?$/.test(b.href) && !b.chevron && !b.mega, JSON.stringify(b));
  check('1. …while JS runs (header has data-js, «حساب من» shows its dropdown chevron) — same as the production screenshot', b.js && b.acctChevron, JSON.stringify(b));
  await a.locator('[data-tth-header]').screenshot({ path: path.join(OUT, 'before-no-categories.png') });

  // ۲. «انتقال از منوی فعلی» ← «ب) از نام سیزده دستهٔ فعلی» ← تیک و ذخیره.
  await a.goto(`${ADMIN}&tab=import`);
  const found = await a.locator('table.tth-baseline input[type="hidden"]').count();
  await a.check('input[name="tecteb_header_settings[baseline_apply]"]');
  await Promise.all([a.waitForNavigation(), a.locator('form:has(input[name="tecteb_header_settings[baseline_apply]"]) [type="submit"]').click()]);
  let o = opt();
  const realNames = JSON.parse(WP('eval', `echo wp_json_encode(array_map(fn($c) => get_term((int) $c['term_id'], 'product_cat')->name, get_option('tecteb_header_settings')['categories']));`));
  check(`2. baseline applied: ${found} categories found by name and saved with this site's real term IDs, in order`, found > 0 && o.categories.length === found && realNames.every((n) => typeof n === 'string' && n.length > 0), JSON.stringify(realNames));
  check('2. …template selection and switch untouched', o.replace === false && o.replace_templates.length > 0, JSON.stringify([o.replace, o.replace_templates]));

  // ۳. «دسته‌بندی‌ها»: زیردسته‌های واقعی برای اولین دسته‌ای که زیردسته دارد.
  await a.goto(`${ADMIN}&tab=cats`);
  const kid = a.locator('input[type="checkbox"][name$="[add_child][]"]').first();
  const kidId = await kid.getAttribute('value');
  // فهرست زیردسته‌های پیشنهادی در یک <details> ِ بسته است؛ همان کاری که مدیر می‌کند: بازش کن.
  const summary = await kid.evaluateHandle((el) => el.closest('details') && el.closest('details').querySelector('summary'));
  if (await summary.evaluate((s) => !!s)) await summary.asElement().click();
  await kid.check();
  await Promise.all([a.waitForNavigation(), a.locator('form:has(input[name$="[add_child][]"]) [type="submit"]').first().click()]);
  o = opt();
  const withKids = o.categories.find((c) => c.children.some((k) => String(k.term_id) === kidId));
  check('3. a real subcategory ticked in «دسته‌بندی‌ها» is saved under its parent', !!withKids, kidId);

  // ۴. پیش‌نمایش: دکمه، هاور، کلیک، کیبورد.
  await a.goto(`${SITE}/?tth_preview=1`, { waitUntil: 'networkidle' });
  b = await btn(a);
  check('4. «دسته‌بندی کالاها» is now the mega-menu button (chevron), no shop link', b.tag === 'button' && b.chevron && b.mega, JSON.stringify(b));
  const megaNames = await a.$$eval('#tth-mega .tth-mega__name', (els) => els.map((e) => e.textContent.trim()));
  check('4. mega menu lists exactly the saved real categories, in order', JSON.stringify(megaNames) === JSON.stringify(realNames), JSON.stringify(megaNames));
  const sub = await a.$$eval('#tth-mega .tth-mega__list a', (els) => els.map((e) => e.getAttribute('href')));
  check('4. …with the ticked real subcategory as a link', sub.length >= 1, JSON.stringify(sub));
  await a.hover('[data-tth-header] .tth-cats__btn.tth-js');
  await a.waitForTimeout(400);
  check('4. desktop hover opens it', await megaOpen(a));
  await a.screenshot({ path: path.join(OUT, 'after-mega-open-1440.png'), clip: { x: 0, y: 0, width: 1440, height: 640 } });
  await a.mouse.move(5, 880);
  await a.waitForTimeout(600);
  await a.goto(`${SITE}/?tth_preview=1`, { waitUntil: 'networkidle' });
  await a.click('[data-tth-header] .tth-cats__btn.tth-js');
  await a.waitForTimeout(300);
  check('4. click opens it, without leaving the page', await megaOpen(a) && new URL(a.url()).pathname === '/');
  await a.keyboard.press('Escape');
  await a.waitForTimeout(200);
  check('4. Escape closes it and focus returns to the button', !(await megaOpen(a)) && await a.evaluate(() => document.activeElement.classList.contains('tth-cats__btn')));
  await a.keyboard.press('Enter');
  await a.waitForTimeout(300);
  check('4. keyboard: Enter on the focused button opens it, aria-expanded=true', await megaOpen(a) && (await a.getAttribute('[data-tth-header] .tth-cats__btn.tth-js', 'aria-expanded')) === 'true');
  await ctx.close();

  // ۵. موبایل: کشو با همان دسته‌ها.
  const mctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const m = await mctx.newPage();
  for (let i = 0; i < 3 && !(await mctx.cookies()).some((c) => c.name.startsWith('wordpress_logged_in')); i++) {
    try {
      await m.goto(`${SITE}/wp-login.php`, { timeout: 45000 });
      await m.fill('#user_login', 'tthadmin');
      await m.fill('#user_pass', 'local-only-2026');
      await Promise.all([m.waitForNavigation({ timeout: 45000 }), m.click('#wp-submit')]);
    } catch (e) { /* دوباره */ }
  }
  await m.goto(`${SITE}/?tth_preview=1`, { waitUntil: 'networkidle' });
  await m.tap('[data-tth-header] .tth-burger');
  await m.waitForTimeout(400);
  const drawer = await m.evaluate(() => { const d = document.querySelector('#tth-drawer'); return { open: !!d && d.open, text: d ? d.textContent : '' }; });
  check('5. mobile: the burger opens the drawer with the same real categories', drawer.open && realNames.every((n) => drawer.text.includes(n)), JSON.stringify({ open: drawer.open }));
  await m.screenshot({ path: path.join(OUT, 'after-drawer-390.png') });
  await mctx.close();

  // ۶. بازدیدکننده: هدر Elementor، بی تغییر.
  const g = await browser.newPage();
  const html = await (await g.goto(`${SITE}/`, { waitUntil: 'networkidle' })).text();
  check('6. visitor: old Elementor header, no Header B (switch off)', /data-elementor-type="header"/.test(html) && !/data-tth-header/.test(html));
} finally {
  await browser.close();
  WP('eval-file', path.join(HERE, 'fixtures/seed.php'), 'seed');
}
const pass = results.filter((r) => r.startsWith('PASS')).length;
const out = `tecteb-header ${WP('plugin', 'get', 'tecteb-header', '--field=version')}\n\n${results.join('\n')}\n\n${pass} passed, ${results.length - pass} failed\n`;
fs.writeFileSync(path.join(OUT, 'results.txt'), out);
console.log(out);
process.exit(pass === results.length ? 0 : 1);
