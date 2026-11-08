/*
 * 0.2.5 — حالت JS ِ هدر پیش از اولین رسم (اسکریپت درون‌خطی templates/header.php) و دو مسیر شکستش.
 *
 *   NODE_PATH=/opt/node-tools/node_modules node tests/early-js.mjs
 *
 * ۱. عادی (۳۲۰، ۳۹۰، ۷۶۸، ۱۰۲۴، ۱۴۴۰): data-js پیش از اولین رسم (FCP) است؛ هیچ layout-shift ای در بارگذاری نیست؛
 *    ارتفاع نوار از اولین رسم تا پایان ثابت است؛ header.js اجرا شد (data-tth-ready).
 * ۲. header.js بارگذاری نشود (درخواستش رد شود): پس از load هدر به حالت کامل بی JS برمی‌گردد — «دسته‌بندی کالاها» و
 *    «حساب من» پیوند ساده، ردیف منو در موبایل باز، هیچ دکمهٔ بی‌کاری (همبرگری پنهان).
 * ۳. بهینه‌سازی که header.js را تأخیری کرده (مثل «Delay JavaScript execution» ِ WP Rocket: type و src بازنویسی
 *    می‌شوند): اسکریپت درون‌خطی کاری نمی‌کند — همان رفتار 0.2.4.
 * شبکه و CPU مثل Lighthouse موبایل کند می‌شوند تا «پیش از اولین رسم» معنا داشته باشد. خروجی: evidence/early-js/results.txt
 */
import { createRequire } from 'module';
import { execFileSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const HERE = path.dirname(new URL(import.meta.url).pathname);
const OUT = path.join(HERE, '..', 'evidence', 'early-js');
fs.mkdirSync(OUT, { recursive: true });
const SITE = process.env.SITE || 'http://127.0.0.1:8090';
const PAGE = process.env.PAGE || '/tthh-about/';
const WP = (...a) => execFileSync('wp', ['--allow-root', '--path=/home/user/wp/core', ...a], { encoding: 'utf8' }).trim();
const results = [];
const lines = [];
const check = (name, ok, detail = '') => results.push(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail ? '  — ' + detail : ''}`);

WP('eval-file', path.join(HERE, 'fixtures/seed.php'), 'seed');
WP('eval-file', path.join(HERE, 'fixtures/seed.php'), 'configure', 'on');

const INIT = () => {
  window.__t = { fcp: null, dataJs: null, shifts: [], firstPaintHeight: null };
  new PerformanceObserver((l) => l.getEntries().forEach((e) => {
    if (e.name === 'first-contentful-paint') {
      window.__t.fcp = e.startTime;
      const b = document.querySelector('[data-tth-header] .tth-bar');
      window.__t.firstPaintHeight = b ? Math.round(b.getBoundingClientRect().height) : null;
      window.__t.dataJsAtFcp = !!document.querySelector('[data-tth-header][data-js]');
    }
  })).observe({ type: 'paint', buffered: true });
  new PerformanceObserver((l) => l.getEntries().forEach((e) => window.__t.shifts.push(+e.value.toFixed(4)))).observe({ type: 'layout-shift', buffered: true });
  new MutationObserver(() => { if (window.__t.dataJs === null && document.querySelector('[data-tth-header][data-js]')) window.__t.dataJs = performance.now(); })
    .observe(document, { attributes: true, subtree: true, childList: true, attributeFilter: ['data-js'] });
};

async function load(w, route) {
  const mobile = w < 800;
  const ctx = await browser.newContext({ viewport: { width: w, height: mobile ? 823 : 900 }, isMobile: mobile, hasTouch: mobile });
  const page = await ctx.newPage();
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
  await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 300, downloadThroughput: 1474 * 1024 / 8, uploadThroughput: 675 * 1024 / 8 });
  await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
  await page.addInitScript(INIT);
  if (route) await route(page);
  await page.goto(`${SITE}${PAGE}`, { waitUntil: 'load', timeout: 120000 });
  await page.waitForTimeout(1200);
  const s = await page.evaluate(() => {
    const root = document.querySelector('[data-tth-header]');
    const vis = (e) => !!e && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().width > 0;
    const bar = root.querySelector('.tth-bar');
    return { ...window.__t, js: root.hasAttribute('data-js'), ready: root.hasAttribute('data-tth-ready'), height: Math.round(bar.getBoundingClientRect().height),
      burger: vis(root.querySelector('.tth-burger')), nav: vis(root.querySelector('.tth-nav')), catsLink: vis(root.querySelector('a.tth-cats__btn.tth-nojs')), catsButton: vis(root.querySelector('button.tth-cats__btn')),
      accountLink: vis([...root.querySelectorAll('a.tth-tool--account')].find(vis)), /* مهمان: همیشه پیوند ساده؛ عضو: نسخهٔ tth-nojs */ search: vis(root.querySelector('.tth-top .tth-search')) };
  });
  return { page, ctx, s };
}

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
try {
  for (const w of [320, 390, 768, 1024, 1440]) {
    const { ctx, s } = await load(w);
    lines.push(`normal ${w}: ${JSON.stringify(s)}`);
    check(`${w}: header in JS mode before the first paint (data-js at FCP)`, s.dataJsAtFcp === true && s.dataJs !== null && s.fcp !== null && s.dataJs <= s.fcp, JSON.stringify([s.dataJs, s.fcp, s.dataJsAtFcp]));
    // دسکتاپ: تنها جابه‌جایی باقی‌مانده جابه‌جایی ۴ پیکسلی متن هنگام رسیدن Vazirmatn است (font-display: swap؛ در 0.2.4
    // هم بود، ~۰٫۰۰۰۳) — نه تغییر حالت هدر. موبایل: صفر.
    const cls = s.shifts.reduce((a, b) => a + b, 0);
    check(`${w}: no layout shift from the header switching to JS (CLS ${w < 1080 ? '0' : '< 0.001, font swap only'})`, w < 1080 ? cls === 0 : cls < 0.001, JSON.stringify(s.shifts));
    check(`${w}: header height at first paint = final height (${s.height}px)`, s.firstPaintHeight === s.height, `${s.firstPaintHeight} → ${s.height}`);
    check(`${w}: header.js ran (data-tth-ready) and data-js stayed`, s.ready && s.js);
    await ctx.close();
  }
  // ۲. header.js رد شود.
  for (const w of [390, 1440]) {
    const { ctx, s, page } = await load(w, (p) => p.route('**/tecteb-header/assets/js/header.js*', (r) => r.abort()));
    lines.push(`header.js blocked ${w}: ${JSON.stringify(s)}`);
    check(`${w}, header.js fails to load: after load the header is back in its complete no-JS form (no data-js)`, !s.js && !s.ready, JSON.stringify(s));
    check(`${w}, header.js fails: categories and account are plain links, no dead buttons`, s.catsLink && !s.catsButton && s.accountLink && !s.burger, JSON.stringify(s));
    if (w === 390) {
      check('390, header.js fails: menu row (categories, links, supplier) visible for navigation', s.nav, JSON.stringify(s));
      await page.screenshot({ path: path.join(OUT, 'headerjs-blocked-390.png'), clip: { x: 0, y: 0, width: 390, height: 420 } });
    }
    await ctx.close();
  }
  // ۳. بهینه‌ساز header.js را تأخیری کرده (WP Rocket Delay JS): type="rocketlazyloadscript" و data-rocket-src.
  {
    const { ctx, s } = await load(390, (p) => p.route(`${SITE}${PAGE}`, async (r) => {
      const res = await r.fetch();
      const body = (await res.text()).replace(/<script([^>]*?) src=(["'])([^"']*tecteb-header\/assets\/js\/header\.js[^"']*)\2/, '<script$1 type="rocketlazyloadscript" data-rocket-src=$2$3$2');
      await r.fulfill({ response: res, body });
    }));
    lines.push(`delayed by an optimizer 390: ${JSON.stringify(s)}`);
    check('390, header.js delayed by an optimizer: the inline script stands aside (no data-js, as in 0.2.4)', !s.js && !s.ready && s.dataJs === null, JSON.stringify(s));
    check('390, delayed: the no-JS header is complete (plain links, menu row)', s.catsLink && s.accountLink && s.nav && !s.burger, JSON.stringify(s));
    await ctx.close();
  }
} finally {
  await browser.close();
  WP('eval-file', path.join(HERE, 'fixtures/seed.php'), 'seed');
}
const pass = results.filter((r) => r.startsWith('PASS')).length;
const out = `tecteb-header ${WP('plugin', 'get', 'tecteb-header', '--field=version')}\n\n${lines.join('\n')}\n\n${results.join('\n')}\n\n${pass} passed, ${results.length - pass} failed\n`;
fs.writeFileSync(path.join(OUT, 'results.txt'), out);
console.log(results.join('\n') + `\n\n${pass} passed, ${results.length - pass} failed`);
process.exit(pass === results.length ? 0 : 1);
