/*
 * تصویرهای مقایسهٔ دیداری صفحهٔ اصلی — یک بار با نسخه‌های قبلی و یک بار با نسخه‌های تازه (پوشه‌ها را بیرون از
 * این اسکریپت عوض کنید)، بعد perf/tools/visual-diff.py پیکسل‌به‌پیکسل مقایسه می‌کند. فقط می‌خواند.
 *
 *   NODE_PATH=… node perf/tools/visual-shots.mjs <outdir> [url=http://127.0.0.1:8090/]
 *
 * ۹ عرض × (کل صفحه در بالای صفحه)، به‌علاوهٔ هدر جمع‌شدهٔ دسکتاپ (۱۰۸۰، ۱۴۴۰، ۱۹۲۰) و کشوی باز موبایل (۳۲۰، ۳۹۰، ۷۶۸).
 * حرکت کم (reduced motion) و پایان همهٔ انیمیشن‌ها، تا تفاوت فقط از CSS/HTML باشد نه از زمان‌بندی.
 */
import { createRequire } from 'module';
import fs from 'fs';
import path from 'path';

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const [outdir, url = 'http://127.0.0.1:8090/'] = process.argv.slice(2);
fs.mkdirSync(outdir, { recursive: true });
const WIDTHS = [320, 390, 600, 768, 1024, 1080, 1280, 1440, 1920];
// مثل بازدیدکننده تا پایین صفحه می‌رود و برمی‌گردد تا تصویرهای loading="lazy" بارگذاری شوند — تصویر تمام‌صفحه به‌تنهایی
// اسکرول نمی‌کند، پس تصویر تنبلِ پایین صفحه در آن خالی می‌ماند در حالی که هیچ بازدیدکننده‌ای آن را خالی نمی‌بیند.
const visit = async (p) => {
  await p.evaluate(async () => { for (let y = 0; y < document.documentElement.scrollHeight; y += 400) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); } window.scrollTo(0, 0); });
  await p.waitForLoadState('networkidle');
  // فقط تصویرهای دیده‌شدنی؛ تصویر تنبلِ پنهان (کشو، Mega Menu) هرگز بارگذاری نمی‌شود. سقف ۵ ثانیه.
  await p.evaluate(() => Promise.race([new Promise((r) => setTimeout(r, 5000)), Promise.all([...document.images].filter((i) => !i.complete && i.getClientRects().length).map((i) => new Promise((r) => { i.onload = i.onerror = r; })))]));
};
const settle = async (p) => {
  await p.evaluate(() => document.fonts.ready);
  await p.evaluate(() => document.getAnimations().forEach((a) => { try { a.finish(); } catch (e) { a.cancel(); } }));
  await p.addStyleTag({ content: '*{caret-color:transparent!important}' });
  await p.waitForTimeout(300);
};
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
try {
  for (const w of WIDTHS) {
    const mobile = w < 800;
    const ctx = await browser.newContext({ viewport: { width: w, height: mobile ? 844 : 900 }, reducedMotion: 'reduce', isMobile: mobile, hasTouch: mobile, deviceScaleFactor: 1 });
    const p = await ctx.newPage();
    await p.goto(url, { waitUntil: 'networkidle' });
    await visit(p);
    await settle(p);
    await p.screenshot({ path: path.join(outdir, `full-${w}.png`), fullPage: true });
    if ([1080, 1440, 1920].includes(w)) {
      await p.evaluate(() => window.scrollTo(0, 900));
      await p.waitForTimeout(500);
      await settle(p);
      await p.screenshot({ path: path.join(outdir, `compact-${w}.png`) });
    }
    if ([320, 390, 768].includes(w)) {
      await p.evaluate(() => window.scrollTo(0, 0));
      await p.click('[data-tth-header] .tth-burger');
      await p.waitForTimeout(500);
      await settle(p);
      await p.screenshot({ path: path.join(outdir, `drawer-${w}.png`) });
    }
    await ctx.close();
  }
} finally {
  await browser.close();
}
console.log('shots in', outdir);
