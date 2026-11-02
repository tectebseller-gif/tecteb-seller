/*
 * ۰٫۳٫۴ — بررسی «فاصلهٔ سفید بین هدر و شروع Hero در موبایل»: از کجا می‌آید؟ فقط می‌خواند و عکس می‌گیرد.
 *
 *   SITE=… NODE_PATH=… OUT=tests/evidence/mobile-gap node tests/browser/mobile-gap.mjs
 *
 * سه پرسش دستور: (۱) از این افزونه است؟ (۲) از هدر مستقل یا CSS ِ قالب؟ (۳) از ورود مدیر و نوار مدیریت؟
 * برای هر حالت (بازدیدکننده / مدیرِ واردشده) و هر جای اسکرول، فاصلهٔ لبهٔ پایین نوار هدر تا لبهٔ بالای Hero، و
 * نوار «بی‌پوشش» بالای هدرِ چسبان (جایی که صفحه از پشتش دیده می‌شود) اندازه گرفته می‌شود، به‌علاوهٔ زنجیرهٔ
 * margin/padding از ریشهٔ افزونه تا body. پیش‌نیاز: هدر مستقل (tecteb-header) فعال و «نمایش روی صفحهٔ نخست» روشن.
 */
import { createRequire } from 'module';
import fs from 'fs';
import path from 'path';

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const HERE = path.dirname(new URL(import.meta.url).pathname);
const SITE = process.env.SITE || 'http://127.0.0.1:8090';
const OUT = process.env.OUT || path.join(HERE, '../evidence/mobile-gap');
fs.mkdirSync(OUT, { recursive: true });
const lines = [];
const results = [];
const check = (name, ok, detail = '') => results.push(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail ? '  — ' + detail : ''}`);

const state = (p) => p.evaluate(() => {
  const root = document.querySelector('[data-tth-header]');
  const bar = root && root.querySelector('.tth-bar');
  const hero = document.querySelector('.th-hero');
  const ab = document.querySelector('#wpadminbar');
  const br = bar ? bar.getBoundingClientRect() : null;
  const chain = [];
  for (let e = document.querySelector('.tecteb-home-v1'); e && e !== document.documentElement; e = e.parentElement) {
    const cs = getComputedStyle(e);
    chain.push(`${e.tagName.toLowerCase()}${e.id ? '#' + e.id : ''} mt=${cs.marginTop} pt=${cs.paddingTop}`);
  }
  return {
    y: Math.round(scrollY),
    header: !!bar,
    compact: !!root && root.classList.contains('is-compact'),
    html_margin_top: getComputedStyle(document.documentElement).marginTop,
    adminbar: ab ? { position: getComputedStyle(ab).position, bottom: Math.round(ab.getBoundingClientRect().bottom) } : null,
    bar_top: br ? Math.round(br.top) : null,
    bar_bottom: br ? Math.round(br.bottom) : null,
    hero_top: Math.round(hero.getBoundingClientRect().top),
    gap_bar_to_hero: br && hero.getBoundingClientRect().top >= br.bottom - 1 ? Math.round(hero.getBoundingClientRect().top - br.bottom) : null,
    uncovered_above_bar: br ? Math.max(0, Math.round(br.top - Math.max(0, ab ? ab.getBoundingClientRect().bottom : 0))) : null,
    chain,
  };
});

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
try {
  for (const who of ['visitor', 'admin']) {
    for (const [w, h] of [[390, 844], [360, 740], [430, 932], [768, 1024]]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: h }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
      const p = await ctx.newPage();
      if (who === 'admin') {
        await p.goto(`${SITE}/wp-login.php`);
        await p.fill('#user_login', 'tthadmin');
        await p.fill('#user_pass', 'local-only-2026');
        await Promise.all([p.waitForNavigation({ timeout: 120000 }), p.click('#wp-submit')]);
      }
      await p.goto(`${SITE}/`, { waitUntil: 'networkidle' });
      await p.evaluate(() => document.fonts.ready);
      for (const y of [0, 40, 300]) {
        await p.evaluate((v) => window.scrollTo(0, v), y);
        await p.waitForTimeout(450);
        const s = await state(p);
        lines.push(`${who} ${w}px y=${s.y}: ${JSON.stringify({ ...s, chain: undefined })}`);
        if (y === 0) {
          lines.push(`  chain: ${s.chain.join(' ← ')}`);
          check(`${who} ${w}px top of page: the Hero starts right under the header (gap ${s.gap_bar_to_hero}px)`, s.gap_bar_to_hero === 0, JSON.stringify(s));
          check(`${who} ${w}px: the homepage root and its wrappers add no top margin/padding`, s.chain.every((c) => / mt=0px pt=0px$/.test(c)), s.chain.join(' | '));
        }
        if (w === 390) await p.screenshot({ path: path.join(OUT, `${who}-${w}-y${y}.png`), clip: { x: 0, y: 0, width: w, height: 300 } });
        if (who === 'visitor' && y > 0) check(`visitor ${w}px y=${y}: nothing uncovered above the sticky header`, (s.uncovered_above_bar ?? 0) === 0, JSON.stringify(s));
        if (who === 'admin' && y === 300 && w <= 600) check(`admin ${w}px y=300: the sticky header stays ${s.uncovered_above_bar}px below the top while the admin bar (position: ${s.adminbar && s.adminbar.position}) has scrolled away — the strip comes from the admin bar + the header, not from this plugin`, s.uncovered_above_bar > 0 && s.adminbar && s.adminbar.position === 'absolute', JSON.stringify(s));
      }
      await ctx.close();
    }
  }
} finally {
  await browser.close();
}
const pass = results.filter((r) => r.startsWith('PASS')).length;
fs.writeFileSync(path.join(OUT, 'measurements.txt'), lines.join('\n') + '\n');
fs.writeFileSync(path.join(OUT, 'results.txt'), results.join('\n') + `\n\n${pass} passed, ${results.length - pass} failed\n`);
console.log(results.join('\n') + `\n\n${pass} passed, ${results.length - pass} failed`);
process.exit(pass === results.length ? 0 : 1);
