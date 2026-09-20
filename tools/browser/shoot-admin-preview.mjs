/**
 * Desktop + mobile preview of the current admin markup, for design review.
 * Development only: the pages are «نمونه رابط (خارج WordPress)».
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const root = '/home/user/tecteb-seller';
const src = path.join(root, 'docs/evidence/harness');
const out = path.join(root, 'docs/evidence/preview-alpha18');
fs.mkdirSync(out, { recursive: true });

const VIEWPORTS = [{ name: 'desktop', width: 1440 }, { name: 'mobile', width: 390 }];
const PAGES = fs.readdirSync(src).filter(f => f.endsWith('.html')).sort();

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--allow-file-access-from-files'] });
const report = [];
for (const vp of VIEWPORTS) {
  const ctx = await browser.newContext({ viewport: { width: vp.width, height: 900 }, locale: 'fa-IR', deviceScaleFactor: 2, reducedMotion: 'reduce' });
  const page = await ctx.newPage();
  for (const f of PAGES) {
    const name = f.replace(/\.html$/, '');
    await page.goto('file://' + path.join(src, f), { waitUntil: 'load' });
    const shot = path.join(out, `${name}-${vp.name}.png`);
    await page.screenshot({ path: shot, fullPage: true });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    report.push({ page: name, viewport: vp.name, width: vp.width, horizontal_overflow: overflow });
    console.log(`${vp.name.padEnd(8)} ${String(vp.width).padStart(4)}px  ${name.padEnd(22)} overflow=${overflow}`);
  }
  await ctx.close();
}
await browser.close();
fs.writeFileSync(path.join(out, 'index.json'), JSON.stringify(report, null, 2));
const bad = report.filter(r => r.horizontal_overflow > 0);
console.log(bad.length ? `FAIL: ${bad.length} page(s) overflow` : `OK: ${report.length} shots, no horizontal overflow`);
