// اندازه‌گیری نشتِ استایل قالب Hello Elementor (و یک kit نمونهٔ Elementor) روی Concept C.
// فقط محلی؛ هیچ سایتی باز نمی‌شود.  NODE_PATH=/opt/node-tools/node_modules node measure-theme-leak.mjs
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
const { chromium } = createRequire(import.meta.url)('playwright');
const HERE = path.dirname(fileURLToPath(import.meta.url));
const url = pathToFileURL(path.join(HERE, '../concept-c/index.html')).href;
const fullCss = fs.readFileSync(path.join(HERE, 'hello-elementor-3.5.1-reset-excerpt.css'), 'utf8');
// دو اجرا: فقط reset ِ قالب، و قالب + یک kit نمونهٔ Elementor — تا معلوم باشد کدام نشت از کجاست.
const KIT = process.argv[2] === 'kit';
const leakCss = KIT ? fullCss : fullCss.slice(0, fullCss.indexOf('/* و یک قاعدهٔ نمونهٔ'));
const probes = {
  'search input': '.th-search__input',
  'category select': '.th-search__select',
  'search button': '.th-search__submit',
  'h1': '.th-hero__title',
  'h2 (section)': '#th-cats-title',
  'h3 (product)': '.th-product__title',
  'lead p': '.th-hero__lead',
  'category link': '.th-cat',
  'quote link': '.th-product__quote',
};
const props = ['paddingTop', 'paddingRight', 'borderTopWidth', 'borderTopColor', 'borderRadius', 'backgroundColor', 'color', 'fontWeight', 'fontSize', 'lineHeight', 'marginTop', 'marginBottom', 'textTransform', 'letterSpacing', 'fontStyle', 'width'];
async function read(withTheme) {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  await p.goto(url);
  await p.evaluate(() => document.fonts.ready);
  if (withTheme) {
    await p.addStyleTag({ content: leakCss });
    await p.evaluate(() => document.body.classList.add('elementor-kit-5'));
  }
  const out = await p.evaluate(({ probes, props }) => {
    const r = {};
    for (const [k, sel] of Object.entries(probes)) {
      const el = document.querySelector(sel); const cs = getComputedStyle(el);
      r[k] = Object.fromEntries(props.map((x) => [x, cs[x]]));
    }
    return r;
  }, { probes, props });
  if (withTheme) await p.screenshot({ path: path.join(HERE, KIT ? 'theme-leak-with-kit.png' : 'theme-leak-hello-only.png'), clip: { x: 0, y: 100, width: 1440, height: 700 } });
  await b.close();
  return out;
}
const base = await read(false);
const themed = await read(true);
const lines = [];
for (const k of Object.keys(probes)) {
  for (const x of props) {
    if (base[k][x] !== themed[k][x]) lines.push(`LEAK  ${k}  ${x}: ${base[k][x]}  →  ${themed[k][x]}`);
  }
}
const report = lines.length ? lines.join('\n') : 'no leak';
fs.writeFileSync(path.join(HERE, KIT ? 'theme-leak-with-kit.txt' : 'theme-leak-hello-only.txt'), report + '\n');
console.log(report);
