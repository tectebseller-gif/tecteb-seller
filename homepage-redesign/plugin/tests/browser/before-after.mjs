/*
 * تصویرهای «قبل/بعد» — همان دادهٔ محلی، یک بار با نسخهٔ نصب‌شده از ZIP ِ قبلی و یک بار با این نسخه.
 * فقط عکس می‌گیرد؛ هیچ ادعایی نمی‌سنجد (آزمون‌ها جای دیگرند).
 *
 *   LABEL=before|after SITE=… NODE_PATH=… OUT=tests/evidence/before-after node tests/browser/before-after.mjs
 */
import { createRequire } from 'module';
import fs from 'fs';
import path from 'path';

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const HERE = path.dirname(new URL(import.meta.url).pathname);
const SITE = process.env.SITE || 'http://127.0.0.1:8090';
const LABEL = process.env.LABEL || 'after';
const OUT = process.env.OUT || path.join(HERE, '../evidence/before-after');
fs.mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const lines = [];
try {
  for (const [w, h] of [[1440, 900], [390, 844]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, isMobile: w < 800, hasTouch: w < 800, deviceScaleFactor: 1 });
    const p = await ctx.newPage();
    await p.goto(`${SITE}/`, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    // تصویرهای lazy را بیدار کن، بعد به بالا برگرد.
    await p.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 500) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); } window.scrollTo(0, 0); });
    const list = p.locator('[data-th-brands]');
    if (await list.count()) {
      for (let i = 0; i < 10; i++) await p.evaluate(() => { const l = document.querySelector('[data-th-brands]'); l.scrollBy({ left: -l.clientWidth }); });
      await p.evaluate(() => document.querySelector('[data-th-brands]').scrollTo({ left: 0 }));
    }
    await p.waitForTimeout(1500);
    const version = await p.evaluate(() => (document.querySelector('link[href*="tecteb-home.css"]')?.getAttribute('href').match(/ver=([^&]+)/) || [])[1] || '');
    await p.screenshot({ path: path.join(OUT, `${LABEL}-full-${w}.png`), fullPage: true });
    await p.locator('.th-hero').screenshot({ path: path.join(OUT, `${LABEL}-hero-${w}.png`) });
    const b = p.locator('.th-section--brands');
    if (await b.count()) {
      await b.scrollIntoViewIfNeeded();
      await p.waitForTimeout(400);
      await b.screenshot({ path: path.join(OUT, `${LABEL}-brands-${w}.png`) });
      const m = await b.evaluate((s) => ({ h: Math.round(s.getBoundingClientRect().height), title: getComputedStyle(s.querySelector('.th-head__title')).fontSize, card: Math.round(s.querySelector('.th-brand').getBoundingClientRect().height) }));
      lines.push(`${LABEL} ${w}: css ver=${version} · brands section ${m.h}px tall, title ${m.title}, card ${m.card}px`);
    }
    await ctx.close();
  }
} finally {
  await browser.close();
}
fs.appendFileSync(path.join(OUT, 'measurements.txt'), lines.join('\n') + '\n');
console.log(lines.join('\n'));
