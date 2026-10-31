/*
 * ایزولگی CSS ِ هدر در برابر CSS ِ سراسری سایت (Kit ِ Elementor با انتخابگرهای خودِ Elementor
 * 4.2.3، frontend.min.css ِ واقعی Elementor 4.2.3، الگوهای رایج Custom CSS، و CSS ِ قالب Hello که
 * همیشه بارگذاری است).
 *
 *   NODE_PATH=/opt/node-tools/node_modules node tests/isolation.mjs <label>
 *
 * برای هر حالت (بسته، هاور، فوکوس، Mega ِ باز، کشوی باز) سبک محاسبه‌شده و جعبهٔ هر جزء هدر یک بار
 * بی CSS ِ بدخواه و یک بار با آن خوانده و مقایسه می‌شود. هر تفاوت یعنی CSS ِ سایت روی هدر اثر
 * گذاشته است. خروجی: evidence/isolation/<label>.json و .txt
 */
import { createRequire } from 'module';
import { execFileSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const HERE = path.dirname(new URL(import.meta.url).pathname);
const OUT = path.join(HERE, '..', 'evidence', 'isolation');
fs.mkdirSync(OUT, { recursive: true });
const LABEL = process.argv[2] || 'run';
const SITE = 'http://127.0.0.1:8090';
const WP = (...a) => execFileSync('wp', ['--allow-root', '--path=/home/user/wp/core', ...a], { encoding: 'utf8' }).trim();

const PROPS = ['display', 'position', 'width', 'height', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
  'border-top-width', 'border-top-style', 'border-top-color', 'border-right-width', 'border-bottom-width', 'border-left-width', 'border-radius',
  'background-color', 'background-image', 'color', 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-transform', 'text-decoration-line',
  'box-shadow', 'opacity', 'outline-style', 'outline-width', 'list-style-type', 'float', 'fill', 'stroke', 'stroke-width', 'max-width', 'min-height', 'visibility', 'z-index'];

const STATES = [
  { name: 'desktop-closed', w: 1440, act: async () => {}, sel: ['.tth-bar', '.tth-top', '.tth-logo', '.tth-logo__mark', '.tth-logo__word', '#tth-search-form', '.tth-search__input', '.tth-search__go', '.tth-search__go .tth-i', '.tth-tools', '.tth-tool--account', '.tth-tool--account .tth-i', '.tth-tool__label', '.tth-tool--cart', '.tth-nav', '.tth-nav__row', '.tth-cats__btn.tth-js', '.tth-cats__btn.tth-js .tth-i', '.tth-menu', '.tth-menu li', '.tth-menu a', '.tth-seller', '.tth-seller .tth-i'] },
  { name: 'hover-cats', w: 1440, act: async (p) => { await p.hover('.tth-cats__btn.tth-js'); await p.waitForTimeout(80); }, sel: ['.tth-cats__btn.tth-js'] },
  { name: 'hover-menu-link', w: 1440, act: async (p) => { await p.hover('.tth-menu li:nth-child(2) a'); }, sel: ['.tth-menu li:nth-child(2) a'] },
  { name: 'hover-search-go', w: 1440, act: async (p) => { await p.hover('.tth-search__go'); }, sel: ['.tth-search__go'] },
  { name: 'hover-account', w: 1440, act: async (p) => { await p.hover('.tth-tool--account'); }, sel: ['.tth-tool--account'] },
  { name: 'focus-search', w: 1440, act: async (p) => { await p.focus('#tth-s'); }, sel: ['.tth-search__input', '#tth-search-form'] },
  { name: 'mega-open', w: 1440, act: async (p) => { await p.click('.tth-cats__btn.tth-js'); await p.hover('.tth-mega__item:nth-child(2) .tth-mega__main'); await p.waitForTimeout(300); await p.mouse.move(5, 5); }, sel: ['#tth-mega', '.tth-mega__mains', '.tth-mega__item', '.tth-mega__main', '.tth-mega__icon', '.tth-mega__name', '.tth-mega__sub', '.tth-mega__item.is-active .tth-mega__subhead', '.tth-mega__item.is-active .tth-mega__list', '.tth-mega__item.is-active .tth-mega__list li', '.tth-mega__item.is-active .tth-mega__list a', '.tth-mega__all'] },
  { name: 'mobile-closed', w: 390, act: async () => {}, sel: ['.tth-bar', '.tth-burger', '.tth-burger .tth-i', '.tth-logo', '#tth-search-form', '.tth-search__input', '.tth-tools', '.tth-tool--account', '.tth-tool--cart'] },
  { name: 'mobile-drawer-open', w: 390, act: async (p) => { await p.click('[data-tth-burger]'); await p.click('.tth-drawer__cat:nth-of-type(2) summary'); await p.waitForTimeout(300); }, sel: ['#tth-drawer', '.tth-drawer__panel', '.tth-drawer__head', '.tth-drawer__head .tth-icon-btn', '.tth-drawer__acct', '#tth-drawer nav li', '#tth-drawer nav a', '.tth-drawer__title', '.tth-drawer__cat', '.tth-drawer__cat summary', '.tth-drawer__icon', '.tth-drawer__name', '.tth-drawer__cat[open] ul', '.tth-drawer__cat[open] ul a', '.tth-drawer__all', '.tth-seller--block'] },
];

async function snapshot(browser) {
  const result = {};
  for (const st of STATES) {
    const p = await browser.newPage({ viewport: { width: st.w, height: 900 } });
    await p.goto(`${SITE}/tthh-about/`, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    await st.act(p);
    result[st.name] = await p.evaluate(({ sel, props }) => {
      const out = {};
      for (const s of sel) {
        const e = document.querySelector('.tecteb-header-v1 ' + s) || document.querySelector(s);
        if (!e) { out[s] = null; continue; }
        const cs = getComputedStyle(e);
        const r = e.getBoundingClientRect();
        out[s] = Object.fromEntries(props.map((k) => [k, cs.getPropertyValue(k)]));
        out[s].__box = [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height)].join(',');
      }
      return out;
    }, { sel: st.sel, props: PROPS });
    await p.close();
  }
  return result;
}

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
// MINIFIED=1: همان مقایسه با CSS ِ کوچک‌شده با کتابخانهٔ Minify CSS ِ WP Rocket (tools/minify-css.php).
if (process.env.MINIFIED === '1') WP('option', 'update', 'tthh_minified', '1');
try { WP('option', 'delete', 'tthh_hostile'); } catch {}
const clean = await snapshot(browser);
WP('option', 'update', 'tthh_hostile', '1');
const hostile = await snapshot(browser);
WP('option', 'delete', 'tthh_hostile');
if (process.env.MINIFIED === '1') WP('option', 'delete', 'tthh_minified');
await browser.close();

const diffs = [];
for (const st of Object.keys(clean)) {
  for (const s of Object.keys(clean[st])) {
    const a = clean[st][s], b = hostile[st][s];
    if (!a || !b) { if (a !== b) diffs.push(`${st} ${s}: present=${!!a} → ${!!b}`); continue; }
    for (const k of Object.keys(a)) if (a[k] !== b[k]) diffs.push(`${st} ${s} {${k}}: ${a[k]} → ${b[k]}`);
  }
}
const elements = Object.values(clean).reduce((n, o) => n + Object.keys(o).length, 0);
const summary = `${LABEL}: ${STATES.length} states, ${elements} elements × ${PROPS.length + 1} properties — ${diffs.length} differences caused by site-wide CSS`;
fs.writeFileSync(path.join(OUT, `${LABEL}.txt`), summary + '\n\n' + diffs.join('\n') + '\n');
fs.writeFileSync(path.join(OUT, `${LABEL}.json`), JSON.stringify({ clean, hostile }, null, 1));
console.log(summary);
console.log(diffs.slice(0, 40).join('\n'));
process.exit(diffs.length ? 1 : 0);
