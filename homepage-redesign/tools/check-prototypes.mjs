// بررسی و تصویربرداری دو طرح پیش‌نمایش — فقط روی فایل‌های محلی، هیچ سایتی باز نمی‌شود.
//
//   AXE=/path/to/axe.min.js node homepage-redesign/tools/check-prototypes.mjs
//
// برای هر طرح و هر عرض: سرریز افقی، axe (فقط روی <main> طرح)، و تصویر کامل صفحه.
// به‌علاوهٔ دو بررسی ایستا: هر قاعدهٔ CSS زیر `.tecteb-home-v1` است، و آیکون‌های
// داخل هر HTML با shared/icons.svg.html یکی‌اند.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

// Playwright از نصبِ از‌پیش‌موجودِ محیط (require با NODE_PATH، چون import آن را نمی‌خواند).
const { chromium } = createRequire(import.meta.url)('playwright');
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const AXE = process.env.AXE ? fs.readFileSync(process.env.AXE, 'utf8') : null;
const WIDTHS = [1440, 768, 390, 320];
const SHOTS = { 1440: 'desktop', 390: 'mobile' };
const results = [];
const check = (name, ok, detail = '') => {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
};

// ---------- ایستا ----------
const sprite = fs.readFileSync(path.join(ROOT, 'shared/icons.svg.html'), 'utf8');
const spriteSvg = sprite.slice(sprite.indexOf('<svg')).trim();

for (const c of ['a', 'b']) {
  const html = fs.readFileSync(path.join(ROOT, `concept-${c}/index.html`), 'utf8');
  check(`concept-${c}: sprite matches shared/icons.svg.html`, html.includes(spriteSvg));
  check(`concept-${c}: lang=fa dir=rtl`, /<html lang="fa" dir="rtl">/.test(html));
  check(`concept-${c}: no <script>`, !/<script/i.test(html));
  check(`concept-${c}: no external URL`, !/(src|href)="https?:/i.test(html));

  const css = fs.readFileSync(path.join(ROOT, `concept-${c}/concept-${c}.css`), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '');
  // انتخابگرهای قواعد عادی. @font-face عمداً سراسری است (قابل scope نیست) و نامِ
  // خانواده‌اش «TH Vazirmatn» یکتاست تا با فونت قالب برخورد نکند.
  const bad = [];
  const re = /([^{}@;]+)\{/g;
  const body = css.replace(/@font-face\s*\{[^}]*\}/g, '');
  let m;
  while ((m = re.exec(body))) {
    const sel = m[1].trim();
    if (!sel || /^(from|to|\d+%)$/.test(sel) || /^(container|media|supports)\b/.test(sel)) continue;
    // ویرگول‌های داخل پرانتز (:where(...)) جداکنندهٔ انتخابگر نیستند.
    let depth = 0, cur = '';
    const parts = [];
    for (const ch of sel) {
      if (ch === '(') depth++;
      if (ch === ')') depth--;
      if (ch === ',' && depth === 0) { parts.push(cur); cur = ''; } else cur += ch;
    }
    parts.push(cur);
    for (const part of parts) {
      if (!part.trim().startsWith('.tecteb-home-v1')) bad.push(part.trim());
    }
  }
  check(`concept-${c}: every selector scoped under .tecteb-home-v1`, bad.length === 0, bad.slice(0, 5).join(' | '));
  const forbidden = css.match(/(^|[\s,>+~])(body|html|header|footer)\b|\.elementor|\.woocommerce|\.dokan/g) || [];
  check(`concept-${c}: no theme/plugin/global selectors`, forbidden.length === 0, forbidden.join(' '));
  const physical = css.match(/\b(margin|padding|border)-(left|right)\b|\b(left|right)\s*:|text-align:\s*(left|right)/g) || [];
  check(`concept-${c}: logical properties only (i18n-ready)`, physical.length === 0, physical.join(' '));
}

// ---------- مرورگر ----------
const browser = await chromium.launch();
fs.mkdirSync(path.join(ROOT, 'previews'), { recursive: true });

for (const c of ['a', 'b']) {
  const url = pathToFileURL(path.join(ROOT, `concept-${c}/index.html`)).href;
  for (const w of WIDTHS) {
    const page = await browser.newPage({ viewport: { width: w, height: 900 }, deviceScaleFactor: w <= 400 ? 2 : 1 });
    const failed = [];
    page.on('requestfailed', (r) => failed.push(r.url()));
    await page.goto(url, { waitUntil: 'load' });
    await page.evaluate(() => document.fonts.ready);

    const fontOk = await page.evaluate(() => document.fonts.check('16px "TH Vazirmatn"', 'تک‌طب'));
    if (w === 1440) check(`concept-${c}: Persian font loaded`, fontOk);
    check(`concept-${c} @${w}: no failed requests`, failed.length === 0, failed.join(' '));

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    check(`concept-${c} @${w}: no horizontal overflow`, overflow <= 0, `${overflow}px`);

    const sections = await page.locator('main.tecteb-home-v1 > section').count();
    if (w === 1440) check(`concept-${c}: five sections`, sections === 5, String(sections));

    const headings = await page.evaluate(() =>
      [...document.querySelectorAll('main h1, main h2, main h3')].map((h) => h.tagName));
    if (w === 1440) check(`concept-${c}: one h1, then h2/h3`, headings.filter((h) => h === 'H1').length === 1 && headings[0] === 'H1');

    // کوچک‌ترین هدف لمسیِ قابل‌کلیک در موبایل.
    if (w <= 390) {
      const small = await page.evaluate(() => [...document.querySelectorAll('main a:not([tabindex="-1"]), main button, main input:not([type=hidden]), main select')]
        // پیوندِ کشیده (::after با position:absolute) هدفش کل کارت است، نه متنش.
        .map((el) => {
          const stretched = getComputedStyle(el, '::after').position === 'absolute';
          const box = (stretched ? el.closest('article') : el).getBoundingClientRect();
          return { t: el.textContent.trim().slice(0, 24) || el.name, h: box.height };
        })
        .filter((x) => x.h > 0 && x.h < 24));
      check(`concept-${c} @${w}: tap targets ≥ 24px`, small.length === 0, JSON.stringify(small.slice(0, 3)));
    }

    if (AXE) {
      await page.addScriptTag({ content: AXE });
      const axe = await page.evaluate(async () => {
        const r = await window.axe.run('main.tecteb-home-v1', { resultTypes: ['violations'] });
        return r.violations.map((v) => `${v.id}(${v.impact})×${v.nodes.length}`);
      });
      check(`concept-${c} @${w}: axe — 0 violations`, axe.length === 0, axe.join(' '));
    }

    if (SHOTS[w]) {
      const out = path.join(ROOT, 'previews', `concept-${c}-${SHOTS[w]}.png`);
      await page.screenshot({ path: out, fullPage: true });
    }
    await page.close();
  }

  // کیبورد: Tab از ابتدای صفحه به جست‌وجو می‌رسد و حلقهٔ تمرکز دیده می‌شود.
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  await page.goto(url);
  let found = null;
  for (let i = 0; i < 8 && !found; i++) {
    await page.keyboard.press('Tab');
    found = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el || el.type !== 'search') return null;
      const f = el.closest('form');
      const s = getComputedStyle(f.querySelector(':focus-within') ? f : el);
      const inner = f.querySelector('.th-search__field');
      const t = inner ? getComputedStyle(inner) : s;
      return { ring: s.boxShadow !== 'none' || t.outlineStyle !== 'none' };
    });
  }
  check(`concept-${c}: Tab reaches search with a visible focus indicator`, !!found && found.ring);
  await page.close();
}

await browser.close();
const failed = results.filter((r) => !r.ok).length;
fs.writeFileSync(path.join(ROOT, 'previews', 'check-results.txt'),
  results.map((r) => `${r.ok ? 'PASS' : 'FAIL'}  ${r.name}${r.detail ? '  — ' + r.detail : ''}`).join('\n') +
  `\n\n${results.length - failed} passed, ${failed} failed${AXE ? '' : ' (axe NOT run: set AXE=…)'}\n`);
console.log(`\n${results.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
