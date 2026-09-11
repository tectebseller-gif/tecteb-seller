/**
 * Screenshots the vendor-area preview pages (development only).
 *
 * The pages themselves are mock-ups: nothing here runs plugin code and every
 * value on them is invented and labelled «نمونه». The screenshots exist so the
 * design can be judged before it is built.
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const dir = path.join(root, 'docs/evidence/preview');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const WIDTHS = [390, 1440];
const PAGES = ['vendor-dashboard', 'vendor-application'];

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox', '--allow-file-access-from-files'] });
for (const width of WIDTHS) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'fa-IR' });
  const page = await ctx.newPage();
  for (const name of PAGES) {
    const file = path.join(dir, `${name}.html`);
    if (!fs.existsSync(file)) throw new Error(`missing preview page: ${file}`);
    await page.goto('file://' + file, { waitUntil: 'load' });
    const shot = path.join(dir, `${name}-${width}.png`);
    await page.screenshot({ path: shot, fullPage: true });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    console.log(`${String(width).padStart(4)}px  ${name.padEnd(20)} ${path.basename(shot)}  horizontal-overflow=${overflow}`);
  }
  await ctx.close();
}
await browser.close();
