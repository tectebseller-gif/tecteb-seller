/*
 * گزارش Staging در مرورگر: دو قالب هدر «کل سایت»، ۲۰ اول، فقط ۲۶۵۲ انتخاب‌شده، جایگزینی خاموش.
 *   NODE_PATH=/opt/node-tools/node_modules node tests/two-headers.mjs
 * خروجی: evidence/two-headers/results.txt و تصویرها.
 */
import { createRequire } from 'module';
import { execFileSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const HERE = path.dirname(new URL(import.meta.url).pathname);
const OUT = path.join(HERE, '..', 'evidence', 'two-headers');
fs.mkdirSync(OUT, { recursive: true });
const SITE = 'http://127.0.0.1:8090';
const WP = (...a) => execFileSync('wp', ['--allow-root', '--path=/home/user/wp/core', ...a], { encoding: 'utf8' }).trim();
const AXE = fs.readFileSync(process.env.AXE || '/tmp/claude-0/-home-user-tecteb-seller/abc7dd8b-6e40-5d40-8130-3e6a187d547a/scratchpad/axe/package/axe.min.js', 'utf8');
const lines = []; let pass = 0, fail = 0;
const ok = (n, c, d = '') => { c ? pass++ : fail++; lines.push(`${c ? 'ok   ' : 'FAIL '} ${n}${!c && d ? '  — ' + d : ''}`); };

WP('eval-file', path.join(HERE, 'fixtures/seed.php'), 'seed');
WP('eval-file', path.join(HERE, 'fixtures/two-headers.php'), 'on');
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
try {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const p = await ctx.newPage();
  await p.goto(`${SITE}/wp-login.php`);
  await p.fill('#user_login', 'tthadmin'); await p.fill('#user_pass', 'local-only-2026');
  await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);

  // ۱. همان کلیک ِ Staging: «هدر افزونه به‌جای هدر فعلی» از زبانهٔ جایگزینی.
  await p.goto(`${SITE}/wp-admin/admin.php?page=tecteb-header&tab=replace`);
  const href = await p.getAttribute('a[href*="tth_preview=1"]', 'href');
  ok('replace tab: preview link is ?tth_preview=1 on the home page', /\/\?tth_preview=1$/.test(href || ''), href);
  const err = p.locator('[data-tth-printed-unselected="20"]');
  ok('replace tab: says Elementor prints #20 and it is not selected', await err.isVisible());
  ok('replace tab: lists both Entire Site templates in Elementor order', (await p.getAttribute('[data-tth-sitewide]', 'data-tth-sitewide')) === '20,2652');
  await p.addScriptTag({ content: AXE });
  const v = await p.evaluate(async () => (await window.axe.run({ include: ['.tth-hdr'] }, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] })).violations.map((x) => x.id));
  ok('axe replace tab with the two-header notices', v.length === 0, v.join(','));
  await err.scrollIntoViewIfNeeded();
  await p.screenshot({ path: path.join(OUT, 'admin-replace-two-headers.png'), fullPage: true });

  const resp = await p.goto(href, { waitUntil: 'networkidle' });
  const html = await resp.text();
  ok('preview as admin: Elementor #20 printed, Header B absent (the staging symptom)', /data-elementor-id="20"/.test(html) && !/data-tth-header/.test(html), '');
  ok('preview as admin: page source explains it', html.includes('<!-- tecteb-header: not replaced: not_allowed (Elementor queue: 20; selected: 2652) -->'));
  await p.screenshot({ path: path.join(OUT, 'preview-not-allowed.png') });

  // ۲. همان صفحه پس از انتخاب ۲۰ (فقط محلی) ⇒ پیش‌نمایش جایگزین می‌کند، و بازگشت فوری.
  WP('eval', "update_option('tecteb_header_settings', array_merge(get_option('tecteb_header_settings'), array('_tab'=>'replace','replace_templates'=>array(2652,20))));");
  const html2 = await (await p.goto(href, { waitUntil: 'networkidle' })).text();
  ok('after selecting #20: preview shows Header B, one header, Elementor #20 not printed', (html2.match(/data-tth-header/g) || []).length === 1 && !/data-elementor-id="20"/.test(html2));
  await p.screenshot({ path: path.join(OUT, 'preview-replaced.png') });
  const html3 = await (await p.goto(`${SITE}/?tth_original=1`, { waitUntil: 'networkidle' })).text();
  ok('?tth_original=1 as admin: Elementor #20 back immediately', /data-elementor-id="20"/.test(html3) && !/data-tth-header/.test(html3));
  const guest = await browser.newPage();
  const html4 = await (await guest.goto(`${SITE}/?tth_preview=1`, { waitUntil: 'networkidle' })).text();
  ok('visitor with ?tth_preview=1: Elementor #20, no Header B, no comment', /data-elementor-id="20"/.test(html4) && !/data-tth-header/.test(html4) && !html4.includes('tecteb-header:'));
  const html5 = await (await guest.goto(`${SITE}/`, { waitUntil: 'networkidle' })).text();
  ok('visitor without preview (replacement off): Elementor #20 unchanged', /data-elementor-id="20"/.test(html5) && !/data-tth-header/.test(html5));
} finally {
  await browser.close();
  WP('eval-file', path.join(HERE, 'fixtures/two-headers.php'), 'off');
  WP('eval-file', path.join(HERE, 'fixtures/seed.php'), 'seed');
}
fs.writeFileSync(path.join(OUT, 'results.txt'), lines.join('\n') + `\n\n${pass} passed, ${fail} failed\n`);
console.log(lines.join('\n') + `\n\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
