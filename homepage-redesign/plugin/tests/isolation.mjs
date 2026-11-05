/*
 * ایزولگی CSS ِ صفحهٔ اصلی در برابر CSS ِ سراسری سایت — همان روش آزمون هدر
 * (header-plugin/tests/isolation.mjs): انتخابگرهای واقعی Kit ِ Elementor 4.2.3،
 * frontend.min.css ِ واقعی Elementor 4.2.3 و الگوهای رایج Custom CSS
 * (header-plugin/tests/fixtures/hostile-global.css، از mu-plugin ِ tthh-test-helpers با option ِ tthh_hostile).
 *
 *   NODE_PATH=/opt/node-tools/node_modules [MINIFIED=1] node tests/isolation.mjs <label>
 *
 * هر حالت یک بار بی CSS ِ بدخواه و یک بار با آن خوانده و مقایسه می‌شود. حرکت کاهش‌یافته، تا
 * انیمیشن‌های ظهور در مقایسه نباشند. خروجی: tests/evidence/isolation/<label>.{txt,json}
 */
import { createRequire } from 'module';
import { execFileSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const HERE = path.dirname(new URL(import.meta.url).pathname);
const OUT = path.join(HERE, 'evidence', 'isolation');
fs.mkdirSync(OUT, { recursive: true });
const LABEL = process.argv[2] || 'run';
const SITE = 'http://127.0.0.1:8090/';
const WP = (...a) => execFileSync('wp', ['--allow-root', '--path=/home/user/wp/core', ...a], { encoding: 'utf8' }).trim();

const PROPS = ['display', 'position', 'width', 'height', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
  'border-top-width', 'border-top-style', 'border-top-color', 'border-right-width', 'border-bottom-width', 'border-left-width', 'border-radius',
  'background-color', 'background-image', 'color', 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-transform', 'text-decoration-line',
  'box-shadow', 'opacity', 'outline-style', 'outline-width', 'list-style-type', 'float', 'fill', 'stroke', 'stroke-width', 'max-width', 'min-height', 'visibility', 'z-index'];

const ALL = ['.th-hero', '.th-hero__title', '.th-hero__lead', '.th-search', '.th-search__input', '.th-search__submit', '.th-search__icon',
  '.th-quick__label', '.th-chip', '.th-hero__render img', '.th-float', '.th-routes', '.th-route', '.th-route__icon', '.th-route__title', '.th-route__kicker', '.th-route__go', '.th-audience',
  '.th-eyebrow', '.th-head__title', '.th-more', '.th-cats', '.th-cat', '.th-cat__name', '.th-cat__meta', '.th-orb', '.th-product', '.th-product__pic img',
  '.th-product__title', '.th-product__cat', '.th-product__specs', '.th-product__price', '.th-badge', '.th-join', '.th-join__title', '.th-join__lead', '.th-join .th-btn', '.th-step',
  // ۰٫۳٫۳
  '.th-floats', '.th-float__text', '.th-price__now', '.th-section--brands', '.th-brands', '.th-brands__list', '.th-brand', '.th-brand__img', '.th-brands__btn'];
const STATES = [
  { name: 'desktop', w: 1440, act: async () => {}, sel: ALL },
  { name: 'hover-route', w: 1440, act: async (p) => { await p.hover('.th-route'); }, sel: ['.th-route'] },
  { name: 'hover-search-submit', w: 1440, act: async (p) => { await p.hover('.th-search__submit'); }, sel: ['.th-search__submit'] },
  { name: 'focus-search', w: 1440, act: async (p) => { await p.focus('#th-c-search'); }, sel: ['.th-search__input', '.th-search'] },
  { name: 'mobile', w: 390, act: async () => {}, sel: ALL },
  { name: 'mobile-320', w: 320, act: async () => {}, sel: ALL },
];

async function snapshot(browser) {
  const result = {};
  for (const st of STATES) {
    const p = await browser.newPage({ viewport: { width: st.w, height: 900 }, reducedMotion: 'reduce' });
    await p.goto(SITE, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    await st.act(p);
    result[st.name] = await p.evaluate(({ sel, props }) => {
      const out = {};
      for (const s of sel) {
        const e = document.querySelector('.tecteb-home-v1 ' + s);
        if (!e) { out[s] = null; continue; }
        const cs = getComputedStyle(e);
        const r = e.getBoundingClientRect();
        out[s] = Object.fromEntries(props.map((k) => [k, cs.getPropertyValue(k)]));
        out[s].__box = [Math.round(r.x), Math.round(r.y + scrollY), Math.round(r.width), Math.round(r.height)].join(',');
      }
      return out;
    }, { sel: st.sel, props: PROPS });
    await p.close();
  }
  return result;
}

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
if (process.env.MINIFIED === '1') WP('option', 'update', 'tthh_minified', '1');
try { WP('option', 'delete', 'tthh_hostile'); } catch {}
const clean = await snapshot(browser);
WP('option', 'update', 'tthh_hostile', '1');
const hostile = await snapshot(browser);
WP('option', 'delete', 'tthh_hostile');
if (process.env.MINIFIED === '1') WP('option', 'delete', 'tthh_minified');
await browser.close();

const diffs = [];
const missing = [];
for (const st of Object.keys(clean)) {
  for (const s of Object.keys(clean[st])) {
    const a = clean[st][s], b = hostile[st][s];
    if (!a || !b) { (a !== b ? diffs : missing).push(`${st} ${s}: present=${!!a} → ${!!b}`); continue; }
    for (const k of Object.keys(a)) if (a[k] !== b[k]) diffs.push(`${st} ${s} {${k}}: ${a[k]} → ${b[k]}`);
  }
}
const elements = Object.values(clean).reduce((n, o) => n + Object.values(o).filter(Boolean).length, 0);
const summary = `${LABEL}: ${STATES.length} states, ${elements} elements × ${PROPS.length} properties + box — ${diffs.length} differences caused by site-wide CSS` + (missing.length ? ` (not on page: ${missing.join('; ')})` : '');
fs.writeFileSync(path.join(OUT, `${LABEL}.txt`), summary + '\n\n' + diffs.join('\n') + '\n');
fs.writeFileSync(path.join(OUT, `${LABEL}.json`), JSON.stringify({ clean, hostile }, null, 1));
console.log(summary);
console.log(diffs.slice(0, 60).join('\n'));
process.exit(diffs.length ? 1 : 0);
