// بررسی و تصویربرداری طرح‌های پیش‌نمایش — فقط روی فایل‌های محلی، هیچ سایتی باز نمی‌شود.
//
//   AXE=/path/to/axe.min.js NODE_PATH=/opt/node-tools/node_modules \
//     node homepage-redesign/tools/check-prototypes.mjs
//
// برای هر صفحه و هر عرض: سرریز افقی، axe (فقط روی <main> طرح)، هدف لمسی و تصویر.
// تصویرهای کامل با prefers-reduced-motion: reduce گرفته می‌شوند — یعنی «نسخهٔ بی‌انیمیشن»
// همان چیزی است که در پیش‌نمایش دیده می‌شود. حرکتِ Concept C جدا سنجیده می‌شود.
// به‌علاوهٔ بررسی‌های ایستا: CSS فقط زیر `.tecteb-home-v1`، ویژگی‌های منطقی، آیکون‌های
// یکسان با shared/icons.svg.html، و هیچ درخواست بیرونی.
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

const PAGES = [
  { id: 'concept-a', html: 'concept-a/index.html', css: 'concept-a/concept-a.css', scripts: [], sections: 5 },
  { id: 'concept-b', html: 'concept-b/index.html', css: 'concept-b/concept-b.css', scripts: [], sections: 5 },
  { id: 'concept-c', html: 'concept-c/index.html', css: 'concept-c/concept-c.css', scripts: ['concept-c.js'], sections: 5 },
  { id: 'hero-light', html: 'concept-c/hero-light.html', css: 'concept-c/concept-c.css', scripts: ['concept-c.js'], sections: 1 },
  { id: 'hero-dark', html: 'concept-c/hero-dark.html', css: 'concept-c/concept-c.css', scripts: ['concept-c.js'], sections: 1 },
];

const results = [];
const check = (name, ok, detail = '') => {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
};

// ---------- ایستا ----------
const sprite = fs.readFileSync(path.join(ROOT, 'shared/icons.svg.html'), 'utf8');
const spriteSvg = sprite.slice(sprite.indexOf('<svg')).trim();

function selectorsOf(css) {
  // انتخابگرهای قواعد عادی. @font-face عمداً سراسری است (قابل scope نیست) و نامِ
  // خانواده‌اش «TH Vazirmatn» یکتاست؛ بدنهٔ @keyframes انتخابگر نیست.
  const body = css
    .replace(/@font-face\s*\{[^}]*\}/g, '')
    .replace(/@keyframes[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/g, '');
  const out = [];
  const re = /([^{}@;]+)\{/g;
  let m;
  while ((m = re.exec(body))) {
    const sel = m[1].trim();
    if (!sel || /^(container|media|supports)\b/.test(sel)) continue;
    // ویرگول‌های داخل پرانتز (:where(...)) جداکنندهٔ انتخابگر نیستند.
    let depth = 0, cur = '';
    for (const ch of sel) {
      if (ch === '(') depth++;
      if (ch === ')') depth--;
      if (ch === ',' && depth === 0) { out.push(cur.trim()); cur = ''; } else cur += ch;
    }
    out.push(cur.trim());
  }
  return out;
}

const seenCss = new Set();
for (const p of PAGES) {
  const html = fs.readFileSync(path.join(ROOT, p.html), 'utf8');
  check(`${p.id}: sprite matches shared/icons.svg.html`, html.includes(spriteSvg));
  check(`${p.id}: lang=fa dir=rtl`, /<html lang="fa" dir="rtl">/.test(html));
  const scripts = [...html.matchAll(/<script\b[^>]*>/gi)].map((s) => (s[0].match(/src="([^"]+)"/) || [])[1] || 'inline');
  check(`${p.id}: scripts limited to ${p.scripts.join(', ') || 'none'}`, JSON.stringify(scripts) === JSON.stringify(p.scripts), scripts.join(' '));
  check(`${p.id}: no external URL`, !/(src|srcset|href)="https?:/i.test(html));

  if (seenCss.has(p.css)) continue;
  seenCss.add(p.css);
  const css = fs.readFileSync(path.join(ROOT, p.css), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
  const bad = selectorsOf(css).filter((s) => !s.startsWith('.tecteb-home-v1'));
  check(`${p.css}: every selector scoped under .tecteb-home-v1`, bad.length === 0, bad.slice(0, 5).join(' | '));
  const forbidden = css.match(/(^|[\s,>+~])(body|html|header|footer)\b|\.elementor|\.woocommerce|\.dokan/g) || [];
  check(`${p.css}: no theme/plugin/global selectors`, forbidden.length === 0, forbidden.join(' '));
  const physical = css.match(/\b(margin|padding|border)-(left|right)\b|[\s;{](left|right)\s*:|text-align:\s*(left|right)/g) || [];
  check(`${p.css}: logical properties only (i18n-ready)`, physical.length === 0, physical.join(' '));
  check(`${p.css}: no external URL`, !/url\(\s*["']?https?:/i.test(css));
  if (p.css.includes('concept-c')) {
    // هر انیمیشن باید زیر no-preference تعریف شده باشد.
    const outside = css.replace(/@media\s*\(prefers-reduced-motion:\s*no-preference\)\s*\{[\s\S]*?\n\}/, '');
    check(`${p.css}: every animation sits under prefers-reduced-motion: no-preference`,
      !/animation\s*:\s*th-/.test(outside));
  }
}

// ---------- مرورگر ----------
const browser = await chromium.launch();
fs.mkdirSync(path.join(ROOT, 'previews'), { recursive: true });

for (const p of PAGES) {
  const url = pathToFileURL(path.join(ROOT, p.html)).href;
  for (const w of WIDTHS) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, deviceScaleFactor: w <= 400 ? 2 : 1, reducedMotion: 'reduce' });
    const page = await ctx.newPage();
    const failed = [];
    page.on('requestfailed', (r) => failed.push(r.url()));
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(url, { waitUntil: 'load' });
    await page.evaluate(() => document.fonts.ready);

    if (w === 1440) {
      check(`${p.id}: Persian font loaded`, await page.evaluate(() => document.fonts.check('16px "TH Vazirmatn"', 'تک‌طب')));
      check(`${p.id}: ${p.sections} section(s)`, (await page.locator('main.tecteb-home-v1 > section').count()) === p.sections);
      const heads = await page.evaluate(() => [...document.querySelectorAll('main h1, main h2, main h3')].map((h) => h.tagName));
      check(`${p.id}: one h1, first`, heads.filter((h) => h === 'H1').length === 1 && heads[0] === 'H1');
      const imgs = await page.evaluate(() => [...document.querySelectorAll('main img')].map((i) => ({ src: i.currentSrc, ok: i.complete && i.naturalWidth > 0 })));
      check(`${p.id}: every image loaded`, imgs.every((i) => i.ok), JSON.stringify(imgs.filter((i) => !i.ok)));
    }
    check(`${p.id} @${w}: no failed requests, no script errors`, failed.length === 0 && errors.length === 0, [...failed, ...errors].join(' '));

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    check(`${p.id} @${w}: no horizontal overflow`, overflow <= 0, `${overflow}px`);

    if (w <= 390) {
      const small = await page.evaluate(() => [...document.querySelectorAll('main a:not([tabindex="-1"]), main button, main input:not([type=hidden]), main select')]
        // پیوندِ کشیده (::after با position:absolute) هدفش کل کارت است، نه متنش.
        .map((el) => {
          const card = el.closest('article');
          const stretched = !!card && getComputedStyle(el, '::after').position === 'absolute';
          const box = (stretched ? card : el).getBoundingClientRect();
          return { t: el.textContent.trim().slice(0, 24) || el.name, h: box.height };
        })
        .filter((x) => x.h > 0 && x.h < 24));
      check(`${p.id} @${w}: tap targets ≥ 24px`, small.length === 0, JSON.stringify(small.slice(0, 3)));
    }

    if (AXE) {
      await page.addScriptTag({ content: AXE });
      const axe = await page.evaluate(async () => {
        const r = await window.axe.run('main.tecteb-home-v1', { resultTypes: ['violations'] });
        return r.violations.map((v) => `${v.id}(${v.impact})×${v.nodes.length}`);
      });
      check(`${p.id} @${w}: axe — 0 violations`, axe.length === 0, axe.join(' '));
    }

    if (SHOTS[w]) await page.screenshot({ path: path.join(ROOT, 'previews', `${p.id}-${SHOTS[w]}.png`), fullPage: true });
    await ctx.close();
  }

  // کیبورد: Tab از ابتدای صفحه به جست‌وجو می‌رسد و حلقهٔ تمرکز دیده می‌شود.
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(url);
  let found = null;
  for (let i = 0; i < 10 && !found; i++) {
    await page.keyboard.press('Tab');
    found = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el || el.type !== 'search') return null;
      const ring = (x) => !!x && (getComputedStyle(x).boxShadow !== 'none' || getComputedStyle(x).outlineStyle !== 'none');
      return { ring: ring(el.closest('.th-search__field')) || ring(el.closest('form')) };
    });
  }
  check(`${p.id}: Tab reaches search with a visible focus indicator`, !!found && found.ring);
  await ctx.close();
}

// ---------- حرکتِ Concept C ----------
{
  const url = pathToFileURL(path.join(ROOT, 'concept-c/index.html')).href;
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'no-preference' });
  const page = await ctx.newPage();
  await page.goto(url, { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);

  const below = await page.evaluate(() => {
    const el = [...document.querySelectorAll('.th-reveal')].find((e) => e.getBoundingClientRect().top > innerHeight + 200);
    return el ? Number(getComputedStyle(el).opacity) : null;
  });
  check('concept-c motion: a section below the fold waits to reveal (scroll-driven)', below !== null && below < 0.5, String(below));

  await page.waitForTimeout(1800);
  const entry = await page.evaluate(() => [...document.querySelectorAll('.th-in')].map((e) => Number(getComputedStyle(e).opacity)));
  check('concept-c motion: hero entry animation ends fully visible', entry.length > 0 && entry.every((o) => o > 0.99), entry.join(','));
  await page.screenshot({ path: path.join(ROOT, 'previews', 'concept-c-hero-motion.png') });

  const reveals = await page.locator('.th-reveal').count();
  let shown = 0;
  for (let i = 0; i < reveals; i++) {
    const el = page.locator('.th-reveal').nth(i);
    await el.evaluate((e) => e.scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(60);
    if (Number(await el.evaluate((e) => getComputedStyle(e).opacity)) > 0.98) shown++;
  }
  check('concept-c motion: every section is fully visible once in view', shown === reveals, `${shown}/${reveals}`);

  const cat = page.locator('.th-cat').first();
  await cat.evaluate((e) => e.scrollIntoView({ block: 'center' }));
  await page.waitForTimeout(100);
  const before = await cat.evaluate((e) => getComputedStyle(e).transform);
  await cat.hover();
  await page.waitForTimeout(450);
  const after = await cat.evaluate((e) => getComputedStyle(e).transform);
  check('concept-c motion: category card lifts on hover', before !== after && after !== 'none', `${before} → ${after}`);

  await page.evaluate(() => window.scrollTo(0, 0));
  await page.mouse.move(400, 300);
  await page.mouse.move(420, 320);
  await page.waitForTimeout(200);
  check('concept-c motion: pointer light follows the cursor on desktop',
    await page.locator('.th-hero').evaluate((e) => e.classList.contains('is-lit') && !!e.style.getPropertyValue('--th-mx')));
  await ctx.close();

  const rctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  const r = await rctx.newPage();
  await r.goto(url, { waitUntil: 'load' });
  const running = await r.evaluate(() => document.getAnimations().length);
  const hidden = await r.evaluate(() => [...document.querySelectorAll('.th-reveal, .th-in')].filter((e) => Number(getComputedStyle(e).opacity) < 1).length);
  check('concept-c reduced motion: no animation runs and nothing is hidden', running === 0 && hidden === 0, `animations=${running} hidden=${hidden}`);
  await rctx.close();
}

await browser.close();
const failed = results.filter((r) => !r.ok).length;
fs.writeFileSync(path.join(ROOT, 'previews', 'check-results.txt'),
  results.map((r) => `${r.ok ? 'PASS' : 'FAIL'}  ${r.name}${r.detail ? '  — ' + r.detail : ''}`).join('\n') +
  `\n\n${results.length - failed} passed, ${failed} failed${AXE ? '' : ' (axe NOT run: set AXE=…)'}\n`);
console.log(`\n${results.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
