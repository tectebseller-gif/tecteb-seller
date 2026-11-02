/*
 * «?tth_preview=1 هدر قدیمی را نشان می‌دهد» روی سایت اصلی (0.2.1، جایگزینی خاموش، دو قالب «کل سایت» ۲۰ و ۲۶۵۲):
 * هر علت ممکن، یک به یک، روی وردپرس یکبارمصرف محلی — با این پرسش که **روی صفحه و در منبع صفحه چه چیزی هر
 * علت را از بقیه جدا می‌کند**. فقط محلی؛ هیچ چیزی به Staging یا سایت اصلی وصل نمی‌شود.
 *
 *   LABEL=0.2.1 NODE_PATH=/opt/node-tools/node_modules node tests/preview-diagnosis.mjs
 *
 * نسخهٔ زیر آزمون همانی است که روی سایت محلی فعال است (lifecycle یا نصب از ZIP). خروجی:
 * evidence/preview-diagnosis/<LABEL>.txt
 */
import { createRequire } from 'module';
import { execFileSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const HERE = path.dirname(new URL(import.meta.url).pathname);
const OUT = path.join(HERE, '..', 'evidence', 'preview-diagnosis');
fs.mkdirSync(OUT, { recursive: true });
const LABEL = process.env.LABEL || 'run';
const SITE = 'http://127.0.0.1:8090';
const WP = (...a) => execFileSync('wp', ['--allow-root', '--path=/home/user/wp/core', ...a], { encoding: 'utf8' }).trim();
const php = (code) => WP('eval', code);
const version = WP('plugin', 'get', 'tecteb-header', '--field=version');
const saved = php("echo wp_json_encode(get_option('tecteb_header_settings', array()));");

// ترتیب قالب‌های «کل سایت» در option ِ شرط‌های Elementor، و قالب‌های انتخاب‌شده در این افزونه.
const order = (ids) => php(`$c = get_option('elementor_pro_theme_builder_conditions', array()); $h = (array) ($c['header'] ?? array()); $n = array(); foreach (array(${ids.join(',')}) as $i) { $n[$i] = array('include/general'); } foreach ($h as $k => $v) { if (!isset($n[$k])) { $n[$k] = $v; } } $c['header'] = $n; update_option('elementor_pro_theme_builder_conditions', $c);`);
const select = (ids) => php(`$s = (array) get_option('tecteb_header_settings', array()); $s['_tab'] = 'replace'; $s['replace'] = false; $s['replace_templates'] = array(${ids.join(',')}); update_option('tecteb_header_settings', $s);`);
const emulate = (mode) => php(`update_option('tthh_emulate', '${mode}');`);

const rows = [];
const results = [];
const check = (name, ok, detail = '') => results.push(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail ? '  — ' + detail : ''}`);

WP('eval-file', path.join(HERE, 'fixtures/seed.php'), 'seed');
WP('eval-file', path.join(HERE, 'fixtures/two-headers.php'), 'on');
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const look = async (page, url) => {
  const html = await (await page.goto(`${SITE}${url}`, { waitUntil: 'networkidle' })).text();
  return {
    elementor: (html.match(/data-elementor-type="header" data-elementor-id="(\d+)"/) || [])[1] || '—',
    themeHeader: /<header[^>]+class="[^"]*site-header/.test(html),
    headerB: (html.match(/data-tth-header/g) || []).length,
    comment: (html.match(/<!-- tecteb-header: ([^>]*) -->/) || [])[1] || '—',
    adminBar: /id="wpadminbar"/.test(html),
  };
};
try {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const admin = await ctx.newPage();
  await admin.goto(`${SITE}/wp-login.php`);
  await admin.fill('#user_login', 'tthadmin');
  await admin.fill('#user_pass', 'local-only-2026');
  await Promise.all([admin.waitForNavigation({ timeout: 120000 }), admin.click('#wp-submit')]);
  const guest = await browser.newPage();

  const scenarios = [
    // [نام، ترتیب در Elementor، انتخاب‌شده، شبیه‌ساز مکان، کاربر، نشانی]
    ['A  nothing selected (fresh install default)', [20, 2652], [], 'both', 'admin', '/?tth_preview=1'],
    ['B  2652 selected, Elementor prints 20 first', [20, 2652], [2652], 'both', 'admin', '/?tth_preview=1'],
    ['C  2652 selected, Elementor prints 2652 first (staging-like)', [2652, 20], [2652], 'both', 'admin', '/?tth_preview=1'],
    ['D  20 selected, Elementor prints 20 first', [20, 2652], [20], 'both', 'admin', '/?tth_preview=1'],
    ['E  both selected, either order', [20, 2652], [20, 2652], 'both', 'admin', '/?tth_preview=1'],
    ['F  both selected, other order', [2652, 20], [20, 2652], 'both', 'admin', '/?tth_preview=1'],
    ['G  not recognised as admin (other host/cookie, or logged out)', [20, 2652], [20, 2652], 'both', 'guest', '/?tth_preview=1'],
    ['H  header not printed through Elementor Pro\'s header location', [20, 2652], [20, 2652], 'none', 'admin', '/?tth_preview=1'],
    ['I  ?tth_original=1 (by design: always the old header)', [20, 2652], [20, 2652], 'both', 'admin', '/?tth_original=1'],
  ];
  for (const [name, ord, sel, emu, who, url] of scenarios) {
    order(ord);
    select(sel);
    emulate(emu);
    const r = await look(who === 'admin' ? admin : guest, url);
    rows.push({ name, ord: ord.join(','), sel: sel.join(',') || '—', who, url, ...r });
  }
  const R = Object.fromEntries(rows.map((r) => [r.name.slice(0, 1), r]));
  check('A: nothing selected ⇒ old header (#20), no Header B, source says «not replaced: not_allowed»', R.A.elementor === '20' && R.A.headerB === 0 && R.A.comment.startsWith('not replaced: not_allowed') && R.A.adminBar);
  check('B: the selected template is not the one Elementor prints ⇒ the same symptom, the same comment', R.B.elementor === '20' && R.B.headerB === 0 && R.B.comment.startsWith('not replaced: not_allowed'));
  check('C: the selected template is the one Elementor prints ⇒ preview works (why staging worked)', R.C.elementor === '—' && R.C.headerB === 1 && R.C.comment.startsWith('replaced Elementor header template 2652'));
  check('D: select the printed one ⇒ preview works, switch still off', R.D.elementor === '—' && R.D.headerB === 1);
  check('E/F: both selected ⇒ works whichever Elementor prints', R.E.headerB === 1 && R.F.headerB === 1 && R.E.elementor === '—' && R.F.elementor === '—');
  check('G: not seen as an admin ⇒ old header, NO comment and NO admin bar', R.G.elementor === '20' && R.G.headerB === 0 && R.G.comment === '—' && !R.G.adminBar);
  check('H: no Elementor header location ⇒ old header, NO comment, admin bar present', R.H.headerB === 0 && R.H.comment === '—' && R.H.adminBar);
  check('I: ?tth_original=1 ⇒ old header by design, no Header B', R.I.elementor === '20' && R.I.headerB === 0);
} finally {
  await browser.close();
  emulate('both');
  php(`update_option('tecteb_header_settings', json_decode(base64_decode('${Buffer.from(saved).toString('base64')}'), true));`);
  WP('eval-file', path.join(HERE, 'fixtures/two-headers.php'), 'off');
  WP('eval-file', path.join(HERE, 'fixtures/seed.php'), 'seed');
}
const table = ['| scenario | Elementor order | selected | user | url | Elementor header printed | Header B | tecteb-header comment | admin bar |', '|---|---|---|---|---|---|---|---|---|']
  .concat(rows.map((r) => `| ${r.name} | ${r.ord} | ${r.sel} | ${r.who} | ${r.url} | ${r.elementor}${r.themeHeader ? ' (theme header)' : ''} | ${r.headerB} | ${r.comment} | ${r.adminBar ? 'yes' : 'no'} |`));
const pass = results.filter((r) => r.startsWith('PASS')).length;
const out = `tecteb-header ${version} (active on the local disposable site)\n\n${table.join('\n')}\n\n${results.join('\n')}\n\n${pass} passed, ${results.length - pass} failed\n`;
fs.writeFileSync(path.join(OUT, `${LABEL}.txt`), out);
console.log(out);
process.exit(pass === results.length ? 0 : 1);
