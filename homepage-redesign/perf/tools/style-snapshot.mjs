/*
 * لقطهٔ getComputedStyle ِ همهٔ عنصرهای هدر و صفحهٔ اصلی (۶۰ ویژگی + جعبه با سه رقم اعشار) در ۸ حالت، برای مقایسهٔ
 * دو نسخهٔ نصب‌شده (perf/tools/switch-plugins.sh بینشان). برخلاف css-equivalence.mjs که فایل CSS را با page.route
 * جابه‌جا می‌کند، این همان صفحه‌ای را می‌سنجد که هر نسخه واقعاً می‌فرستد — پس CSS ِ درون‌خطی، ترتیب cascade و
 * اسکریپت درون‌خطی هم در مقایسه‌اند. فقط می‌خواند.
 *
 *   NODE_PATH=… node perf/tools/style-snapshot.mjs <out.json>
 *   NODE_PATH=… node perf/tools/style-snapshot.mjs --compare <before.json> <after.json> <out.txt>
 */
import { createRequire } from 'module';
import fs from 'fs';

const PROPS = ['display', 'position', 'top', 'left', 'width', 'height', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
  'border-top-width', 'border-top-color', 'border-radius', 'background-color', 'background-image', 'color', 'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing',
  'text-align', 'text-decoration-line', 'box-shadow', 'opacity', 'transform', 'z-index', 'grid-template-columns', 'grid-template-areas', 'grid-column-start', 'gap', 'flex-wrap',
  'justify-content', 'align-items', 'overflow-x', 'visibility', 'pointer-events', 'inset-inline-start', 'max-width', 'min-height', 'aspect-ratio', 'object-fit', 'animation-name',
  'animation-duration', 'transition-duration', 'cursor', 'white-space', 'list-style-type', 'outline-style', 'fill', 'stroke', 'backdrop-filter', 'container-type', 'direction', 'order', 'clip-path'];
const STATES = [[320, 'top'], [390, 'top'], [600, 'top'], [768, 'top'], [1024, 'top'], [1080, 'top'], [1280, 'top'], [1440, 'top'], [1920, 'top'], [1440, 'compact'], [390, 'compact'], [390, 'drawer']];

if (process.argv[2] === '--compare') {
  const [, , , bf, af, out] = process.argv;
  // عنصرهای رسم‌نشدنی (script/style) کنار گذاشته می‌شوند و شمارهٔ ترتیب دوباره ساخته می‌شود — اسکریپت درون‌خطی 0.2.5
  // یک عنصر به هدر می‌افزاید که هیچ‌چیز رسم نمی‌کند، و بدون این، همهٔ عنصرهای بعدی یکی جابه‌جا خوانده می‌شوند.
  const norm = (o) => Object.fromEntries(Object.entries(o).map(([k, v]) => [k, v.filter((l) => !/^\d+:(SCRIPT|STYLE|NOSCRIPT|TEMPLATE)\./.test(l)).map((l, i) => l.replace(/^\d+:/, `${i}:`))]));
  const B = norm(JSON.parse(fs.readFileSync(bf))), A = norm(JSON.parse(fs.readFileSync(af)));
  const lines = []; let total = 0;
  for (const k of Object.keys(B)) {
    const b = B[k], a = A[k] || []; let d = 0;
    for (let i = 0; i < Math.max(b.length, a.length); i++) if (b[i] !== a[i]) { d++; if (d <= 3) lines.push(`  ${k} DIFF\n    before ${b[i]}\n    after  ${a[i]}`); }
    total += d; lines.push(`${k}: ${a.length} elements × ${PROPS.length} properties + box — ${d} differences`);
  }
  lines.push(`\nTOTAL: ${total} differences`);
  fs.writeFileSync(out, lines.join('\n') + '\n'); console.log(lines.join('\n')); process.exit(total ? 1 : 0);
}

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const url = process.env.URL || 'http://127.0.0.1:8090/';
const res = {};
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
try {
  for (const [w, state] of STATES) {
    const mobile = w < 800;
    const ctx = await browser.newContext({ viewport: { width: w, height: mobile ? 844 : 900 }, reducedMotion: 'reduce', isMobile: mobile, hasTouch: mobile });
    const page = await ctx.newPage();
    await page.goto(url, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    if (state === 'compact') { await page.evaluate(() => window.scrollTo(0, 900)); await page.waitForTimeout(600); }
    if (state === 'drawer') { await page.click('[data-tth-header] .tth-burger'); await page.waitForTimeout(600); }
    res[`${w}px ${state}`] = await page.evaluate((PROPS) => {
      document.getAnimations().forEach((a) => { try { a.finish(); } catch (e) { a.cancel(); } });
      const els = [...document.querySelectorAll('[data-tth-header], [data-tth-header] *, .tecteb-home-v1, .tecteb-home-v1 *')].filter((e) => !(e instanceof SVGElement && e.tagName !== 'svg') && !['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE'].includes(e.tagName));
      return els.map((e, i) => { const cs = getComputedStyle(e); const r = e.getBoundingClientRect(); return `${i}:${e.tagName}.${(e.getAttribute('class') || '').split(' ')[0]}|` + PROPS.map((p) => cs.getPropertyValue(p)).join('|') + `|${r.x.toFixed(3)},${(r.y + scrollY).toFixed(3)},${r.width.toFixed(3)},${r.height.toFixed(3)}`; });
    }, PROPS);
    await ctx.close();
  }
} finally { await browser.close(); }
fs.writeFileSync(process.argv[2], JSON.stringify(res));
console.log('states', Object.keys(res).length, '→', process.argv[2]);
