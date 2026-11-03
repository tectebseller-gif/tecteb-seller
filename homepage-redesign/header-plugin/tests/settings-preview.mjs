/*
 * سایت اصلی: «پس از تغییر تنظیمات منو، ?tth_preview=1 دوباره هدر قدیمی را نشان می‌دهد». با فرم‌های واقعی پیشخوان،
 * روی همان شکل سایت اصلی (دو قالب «کل سایت»، #20 اول، #20 و #2652 انتخاب‌شده، جایگزینی خاموش). فقط محلی.
 *
 *   NODE_PATH=/opt/node-tools/node_modules node tests/settings-preview.mjs
 *
 * خروجی: evidence/settings-preview/results.txt و تصویرها.
 */
import { createRequire } from 'module';
import { execFileSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const HERE = path.dirname(new URL(import.meta.url).pathname);
const OUT = path.join(HERE, '..', 'evidence', 'settings-preview');
fs.mkdirSync(OUT, { recursive: true });
const SITE = 'http://127.0.0.1:8090';
const WP = (...a) => execFileSync('wp', ['--allow-root', '--path=/home/user/wp/core', ...a], { encoding: 'utf8' }).trim();
const opt = () => JSON.parse(WP('eval', "echo wp_json_encode(get_option('tecteb_header_settings'));"));
const results = [];
const check = (name, ok, detail = '') => results.push(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail ? '  — ' + detail : ''}`);
const ADMIN = `${SITE}/wp-admin/admin.php?page=tecteb-header`;

WP('eval-file', path.join(HERE, 'fixtures/seed.php'), 'seed');
WP('eval-file', path.join(HERE, 'fixtures/seed.php'), 'configure', 'on');
WP('eval-file', path.join(HERE, 'fixtures/two-headers.php'), 'on'); // #20 اول، جایگزینی خاموش
WP('eval', "$s = get_option('tecteb_header_settings'); $s['_tab'] = 'replace'; $s['replace'] = false; $s['replace_templates'] = array(20, 2652); update_option('tecteb_header_settings', $s);");

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const look = async (page, url) => {
  const html = await (await page.goto(`${SITE}${url}`, { waitUntil: 'networkidle' })).text();
  const row = await page.evaluate(() => {
    const r = document.querySelector('[data-tth-header] .tth-nav__row');
    return r ? { cats: !!r.querySelector('.tth-cats__btn'), links: r.querySelectorAll('.tth-menu a').length, seller: !!r.querySelector('.tth-seller') } : null;
  });
  return {
    elementor: (html.match(/data-elementor-type="header" data-elementor-id="(\d+)"/) || [])[1] || '',
    headerB: (html.match(/data-tth-header/g) || []).length,
    comment: (html.match(/<!-- tecteb-header: ([^>]*) -->/) || [])[1] || '',
    row,
  };
};
try {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const a = await ctx.newPage();
  // سرور تک‌رشته‌ای محلی گاهی روی ورود گیر می‌کند (محیط، نه افزونه): تا سه بار، و ورود با کوکی سنجیده می‌شود.
  for (let i = 0; i < 3 && !(await ctx.cookies()).some((c) => c.name.startsWith('wordpress_logged_in')); i++) {
    try {
      await a.goto(`${SITE}/wp-login.php`, { timeout: 45000 });
      await a.fill('#user_login', 'tthadmin');
      await a.fill('#user_pass', 'local-only-2026');
      await Promise.all([a.waitForNavigation({ timeout: 45000 }), a.click('#wp-submit')]);
    } catch (e) { /* دوباره */ }
  }
  if (!(await ctx.cookies()).some((c) => c.name.startsWith('wordpress_logged_in'))) throw new Error('login failed');
  const guest = await browser.newPage();

  let r = await look(a, '/?tth_preview=1');
  check('1. production state (#20, #2652 selected, switch off): preview shows Header B', r.headerB === 1 && r.elementor === '' && r.comment.startsWith('replaced Elementor header template 20'), JSON.stringify(r));
  check('1. with a menu: links between the two buttons', r.row && r.row.links > 0, JSON.stringify(r.row));

  // ۲. «تنظیمات» ← منوی اصلی ← «— بدون منو —» ← ذخیره (فرم واقعی).
  await a.goto(`${ADMIN}&tab=links`);
  await a.selectOption('#tth-nav', '0');
  await Promise.all([a.waitForNavigation(), a.click('form:has(#tth-nav) [type="submit"]')]);
  let o = opt();
  check('2. saving «منوی اصلی = بدون منو» stores nav_menu 0', o.nav_menu === 0, String(o.nav_menu));
  check('2. …and leaves the template selection [20, 2652] and the switch (off) untouched', JSON.stringify(o.replace_templates) === '[20,2652]' && o.replace === false, JSON.stringify([o.replace_templates, o.replace]));
  r = await look(a, '/?tth_preview=1');
  check('2. preview still shows Header B', r.headerB === 1 && r.elementor === '', JSON.stringify(r));
  check('2. row 2 = Staging: only «دسته‌بندی کالاها» and «همکاری تأمین‌کنندگان», no links between', r.row && r.row.cats && r.row.seller && r.row.links === 0, JSON.stringify(r.row));
  await a.locator('[data-tth-header]').screenshot({ path: path.join(OUT, 'preview-no-menu-1440.png') });
  r = await look(guest, '/');
  check('2. visitor: old Elementor header, unchanged', r.elementor === '20' && r.headerB === 0, JSON.stringify(r));

  // ۳. «جایگزینی هدر» ← فقط «ذخیره» (تیک‌ها همان که صفحه نشان می‌دهد).
  await a.goto(`${ADMIN}&tab=replace`);
  check('3. replace tab shows #20 and #2652 ticked', await a.isChecked('#tth-tpl-20') && await a.isChecked('#tth-tpl-2652'));
  await Promise.all([a.waitForNavigation(), a.click('form:has(#tth-tpl-20) [type="submit"]')]);
  o = opt();
  check('3. saving the replace tab as shown keeps [20, 2652]', JSON.stringify(o.replace_templates) === '[20,2652]' && o.replace === false, JSON.stringify(o.replace_templates));

  // ۴. همان زبانه با تیک‌های برداشته ⇒ همان علامت سایت اصلی.
  await a.goto(`${ADMIN}&tab=replace`);
  await a.uncheck('#tth-tpl-20');
  await a.uncheck('#tth-tpl-2652');
  await Promise.all([a.waitForNavigation(), a.click('form:has(#tth-tpl-20) [type="submit"]')]);
  o = opt();
  check('4. replace tab saved with both boxes unticked ⇒ selection empty', JSON.stringify(o.replace_templates) === '[]', JSON.stringify(o.replace_templates));
  const warn = await a.locator('[data-tth-printed-unselected="20"]').count();
  check('4. …and the replace tab says Elementor prints #20 and it is not selected', warn === 1);
  r = await look(a, '/?tth_preview=1');
  check('4. preview now shows the old Elementor header #20 — the production symptom', r.headerB === 0 && r.elementor === '20' && r.comment.startsWith('not replaced: not_allowed (Elementor queue: 20; selected: none)'), JSON.stringify(r));
  await a.screenshot({ path: path.join(OUT, 'preview-not-allowed-1440.png'), clip: { x: 0, y: 0, width: 1440, height: 260 } });

  // ۵. راه‌حل: تیک دوباره و ذخیره ⇒ پیش‌نمایش برمی‌گردد؛ جایگزینی همچنان خاموش.
  await a.goto(`${ADMIN}&tab=replace`);
  await a.check('#tth-tpl-20');
  await a.check('#tth-tpl-2652');
  await Promise.all([a.waitForNavigation(), a.click('form:has(#tth-tpl-20) [type="submit"]')]);
  r = await look(a, '/?tth_preview=1');
  o = opt();
  check('5. tick #20 and #2652 again and save ⇒ preview shows Header B, switch still off', r.headerB === 1 && r.elementor === '' && o.replace === false && JSON.stringify(o.replace_templates) === '[20,2652]', JSON.stringify([r, o.replace_templates, o.replace]));
  r = await look(a, '/?tth_original=1');
  check('5. ?tth_original=1 ⇒ old Elementor header (instant way back)', r.elementor === '20' && r.headerB === 0, JSON.stringify(r));
  r = await look(guest, '/');
  check('5. visitor: old Elementor header throughout', r.elementor === '20' && r.headerB === 0, JSON.stringify(r));
  await ctx.close();
} finally {
  await browser.close();
  WP('eval-file', path.join(HERE, 'fixtures/two-headers.php'), 'off');
  WP('eval-file', path.join(HERE, 'fixtures/seed.php'), 'seed');
}
const pass = results.filter((r) => r.startsWith('PASS')).length;
const out = `tecteb-header ${WP('plugin', 'get', 'tecteb-header', '--field=version')}\n\n${results.join('\n')}\n\n${pass} passed, ${results.length - pass} failed\n`;
fs.writeFileSync(path.join(OUT, 'results.txt'), out);
console.log(out);
process.exit(pass === results.length ? 0 : 1);
