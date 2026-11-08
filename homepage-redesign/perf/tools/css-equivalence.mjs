/*
 * آیا CSS ِ ساخته‌شدهٔ تازه همان رندر را می‌دهد؟ صفحه یک بار با CSS ِ قدیمی (از فایلی که داده شود، با page.route
 * جایگزین نشانی همان فایل) و یک بار با CSS ِ تازه بارگذاری می‌شود و getComputedStyle ِ همهٔ عنصرهای
 * هدر و صفحهٔ اصلی (۶۰ ویژگی + جعبه) مقایسه می‌شود، در چند عرض و در حالت هدر جمع‌شده. فقط می‌خواند.
 *
 *   NODE_PATH=… node perf/tools/css-equivalence.mjs <url> <old-header.css> <old-home.css> <out.txt>
 */
import { createRequire } from 'module';
import fs from 'fs';

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const [url, oldHeader, oldHome, out] = process.argv.slice(2);
const PROPS = ['display', 'position', 'top', 'left', 'width', 'height', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
  'border-top-width', 'border-top-color', 'border-radius', 'background-color', 'background-image', 'color', 'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing',
  'text-align', 'text-decoration-line', 'box-shadow', 'opacity', 'transform', 'z-index', 'grid-template-columns', 'grid-template-areas', 'grid-column-start', 'gap', 'flex-wrap',
  'justify-content', 'align-items', 'overflow-x', 'visibility', 'pointer-events', 'inset-inline-start', 'max-width', 'min-height', 'aspect-ratio', 'object-fit', 'animation-name',
  'animation-duration', 'transition-duration', 'cursor', 'white-space', 'list-style-type', 'outline-style', 'fill', 'stroke', 'backdrop-filter', 'container-type', 'direction', 'order', 'clip-path'];
const STATES = [[320, 'top'], [390, 'top'], [768, 'top'], [1080, 'top'], [1440, 'top'], [1440, 'compact'], [390, 'compact'], [1920, 'top']];

const snap = (page) => page.evaluate((PROPS) => {
  document.getAnimations().forEach((a) => { try { a.finish(); } catch (e) { a.cancel(); } });
  const els = [...document.querySelectorAll('[data-tth-header], [data-tth-header] *, .tecteb-home-v1, .tecteb-home-v1 *')].filter((e) => !(e instanceof SVGElement && e.tagName !== 'svg'));
  return els.map((e, i) => { const cs = getComputedStyle(e); const r = e.getBoundingClientRect(); return `${i}:${e.tagName}.${(e.getAttribute('class') || '').split(' ')[0]}|` + PROPS.map((p) => cs.getPropertyValue(p)).join('|') + `|${Math.round(r.x)},${Math.round(r.y)},${Math.round(r.width)},${Math.round(r.height)}`; });
}, PROPS);

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const lines = [];
let diffs = 0;
try {
  for (const [w, state] of STATES) {
    const shots = {};
    for (const which of ['old', 'new']) {
      const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, reducedMotion: 'reduce' });
      const page = await ctx.newPage();
      if (which === 'old') {
        await page.route('**/tecteb-header/assets/css/header.css*', (r) => r.fulfill({ contentType: 'text/css', body: fs.readFileSync(oldHeader) }));
        await page.route('**/tecteb-homepage/assets/css/tecteb-home.css*', (r) => r.fulfill({ contentType: 'text/css', body: fs.readFileSync(oldHome) }));
      }
      await page.goto(url, { waitUntil: 'networkidle' });
      await page.evaluate(() => document.fonts.ready);
      if (state === 'compact') { await page.evaluate(() => { document.body.style.minHeight = '6000px'; window.scrollTo(0, 900); }); await page.waitForTimeout(600); }
      shots[which] = await snap(page);
      await ctx.close();
    }
    const n = Math.max(shots.old.length, shots.new.length);
    let d = 0;
    for (let i = 0; i < n; i++) if (shots.old[i] !== shots.new[i]) { d++; if (d <= 3) lines.push(`  ${w} ${state} DIFF\n    old ${shots.old[i]}\n    new ${shots.new[i]}`); }
    diffs += d;
    lines.push(`${w}px ${state}: ${shots.new.length} elements × ${PROPS.length} properties + box — ${d} differences`);
  }
} finally {
  await browser.close();
}
lines.push(`\nTOTAL: ${diffs} differences`);
fs.writeFileSync(out, lines.join('\n') + '\n');
console.log(lines.join('\n'));
process.exit(diffs === 0 ? 0 : 1);
