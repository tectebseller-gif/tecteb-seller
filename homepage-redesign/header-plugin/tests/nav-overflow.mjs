/*
 * ردیف منو روی دسکتاپ با منوی بلندِ سایت اصلی: آیا دکمهٔ «دسته‌بندی کالاها»، هر پیوند منو و «همکاری
 * تأمین‌کنندگان» روی هم می‌افتند؟ در همان حالتی که مالک دید: مدیر، `?tth_preview=1`، جایگزینی خاموش.
 *
 *   LABEL=before|after NODE_PATH=/opt/node-tools/node_modules node tests/nav-overflow.mjs
 *
 * برای هر تعداد پیوند (۶ = منوی محلی/Staging، ۸ = سایت اصلی، ۱۰ = فشار) و هر عرض دسکتاپ (≥ ۱۰۸۰px؛ زیر آن ردیف
 * منو کشو می‌شود)، و یک بار با CSS ِ بدخواه سراسری (tthh_hostile). خروجی: evidence/nav-overflow/<LABEL>.txt و تصویرها.
 */
import { createRequire } from 'module';
import { execFileSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const HERE = path.dirname(new URL(import.meta.url).pathname);
const LABEL = process.env.LABEL || 'run';
const OUT = path.join(HERE, '..', 'evidence', 'nav-overflow');
fs.mkdirSync(OUT, { recursive: true });
const SITE = 'http://127.0.0.1:8090';
const WP = (...a) => execFileSync('wp', ['--allow-root', '--path=/home/user/wp/core', ...a], { encoding: 'utf8' }).trim();
const version = WP('plugin', 'get', 'tecteb-header', '--field=version');
const hostileBefore = WP('eval', "echo (string) get_option('tthh_hostile', '');");
// پیش‌شرط خودِ این آزمون (مثل سایت اصلی): جایگزینی خاموش، قالب‌های هدر «کل سایت» ِ صف Elementor انتخاب‌شده، تا
// `?tth_preview=1` ِ مدیر Header B را نشان دهد. تنظیم قبلی در پایان برمی‌گردد.
// نبودِ option با «option ِ خالی» یکی نیست: ذخیرهٔ این افزونه کلیدهای نفرستاده را نگه می‌دارد، پس بازگرداندنِ
// «هیچ» با update_option(array()) مقدارهای همین آزمون را جا می‌گذاشت — در آن حالت option حذف می‌شود.
const settingsBefore = WP('eval', "$v = get_option('tecteb_header_settings', null); echo null === $v ? 'ABSENT' : base64_encode(wp_json_encode($v));");
WP('eval', "$c = get_option('elementor_pro_theme_builder_conditions', array()); $ids = array_map('intval', array_keys((array) ($c['header'] ?? array()))); $s = (array) get_option('tecteb_header_settings', array()); $s['replace'] = false; $s['replace_templates'] = $ids; update_option('tecteb_header_settings', $s); update_option('tthh_emulate', 'both');");

const lines = [];
const results = [];
const check = (name, ok, detail = '') => results.push(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail ? '  — ' + detail : ''}`);

const measure = (page) => page.evaluate(() => {
  const row = document.querySelector('[data-tth-header] .tth-nav__row');
  const vis = (e) => e && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().width > 0;
  const box = (e) => { const r = e.getBoundingClientRect(); return { l: r.left, r: r.right, t: r.top, b: r.bottom, w: r.width }; };
  const cats = [...row.querySelectorAll('.tth-cats__btn')].find(vis);
  const seller = row.querySelector('.tth-seller');
  const menu = row.querySelector('.tth-menu');
  const links = [...row.querySelectorAll('.tth-menu a')].map((a) => ({ ...box(a), name: a.textContent.trim() }));
  const parts = [cats && { ...box(cats), name: 'cats' }, ...links, seller && { ...box(seller), name: 'seller' }].filter(Boolean);
  const hit = (a, b) => a.l < b.r - 0.5 && a.r > b.l + 0.5 && a.t < b.b - 0.5 && a.b > b.t + 0.5;
  const overlaps = [];
  for (let i = 0; i < parts.length; i++) for (let j = i + 1; j < parts.length; j++) if (hit(parts[i], parts[j])) overlaps.push(`${parts[i].name} × ${parts[j].name}`);
  const mb = menu ? box(menu) : null;
  const outside = mb ? links.filter((l) => l.l < mb.l - 0.5 || l.r > mb.r + 0.5).map((l) => l.name) : [];
  const rb = box(row);
  return {
    count: links.length,
    overlaps,
    outside,
    beyondRow: parts.filter((p) => p.l < rb.l - 0.5 || p.r > rb.r + 0.5).map((p) => p.name),
    rowHeight: Math.round(rb.b - rb.t),
    lines: new Set(links.map((l) => Math.round(l.t))).size,
    menuWidth: mb ? Math.round(mb.w) : 0,
    contentWidth: menu ? Math.round(menu.scrollWidth) : 0,
    overflowX: document.documentElement.scrollWidth - window.innerWidth,
  };
});

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
try {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(`${SITE}/wp-login.php`);
  await page.fill('#user_login', 'tthadmin');
  await page.fill('#user_pass', 'local-only-2026');
  await Promise.all([page.waitForNavigation({ timeout: 120000 }), page.click('#wp-submit')]);
  for (const hostile of ['', '1']) {
    WP('eval', `update_option('tthh_hostile', '${hostile}');`);
    for (const n of [6, 8, 10]) {
      WP('eval-file', path.join(HERE, 'fixtures/nav-overflow.php'), 'on', String(n));
      for (const w of [1080, 1180, 1280, 1366, 1440, 1600, 1760, 1920]) {
        await page.setViewportSize({ width: w, height: 900 });
        await page.goto(`${SITE}/?tth_preview=1`, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);
        const m = await measure(page);
        const tag = `${hostile ? 'hostile ' : ''}${n} links @${w}`;
        lines.push(`${tag}: ${JSON.stringify(m)}`);
        check(`${tag}: no two of «دسته‌بندی کالاها», the ${n} menu links and «همکاری تأمین‌کنندگان» overlap`, m.count === n && m.overlaps.length === 0, m.overlaps.join(', '));
        check(`${tag}: every menu link inside the menu box and the row; no horizontal page scroll`, m.outside.length === 0 && m.beyondRow.length === 0 && m.overflowX <= 0, JSON.stringify({ outside: m.outside, beyond: m.beyondRow, x: m.overflowX }));
        if (!hostile && [1280, 1760].includes(w)) await page.locator('[data-tth-header] .tth-nav').screenshot({ path: path.join(OUT, `${LABEL}-${n}-${w}.png`) });
      }
    }
  }
  await ctx.close();
} finally {
  await browser.close();
  WP('eval', `update_option('tthh_hostile', '${hostileBefore}');`);
  WP('eval-file', path.join(HERE, 'fixtures/nav-overflow.php'), 'off');
  WP('eval', settingsBefore === 'ABSENT' ? "delete_option('tecteb_header_settings');" : `update_option('tecteb_header_settings', json_decode(base64_decode('${settingsBefore}'), true));`);
}
const pass = results.filter((r) => r.startsWith('PASS')).length;
const out = `tecteb-header ${version}\n\n${lines.join('\n')}\n\n${results.join('\n')}\n\n${pass} passed, ${results.length - pass} failed\n`;
fs.writeFileSync(path.join(OUT, `${LABEL}.txt`), out);
console.log(results.filter((r) => r.startsWith('FAIL')).join('\n') + `\n\n${pass} passed, ${results.length - pass} failed`);
process.exit(pass === results.length ? 0 : 1);
