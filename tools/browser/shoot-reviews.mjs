/**
 * Photographs the screens this round added, in a REAL WordPress with real rows
 * behind them (development only; never shipped).
 *
 * Four screens, because they are four different readers:
 *   1. /vendor/reviews/   — what a SHOP sees, and the one control it has
 *   2. /vendor/notices/   — the shop's reports, now with charts
 *   3. wp-admin «نظرات»   — the MANAGER's moderation queue
 *   4. wp-admin «گزارش‌ها» — the marketplace's reports, now with charts
 *
 * Two viewports each: a phone and a desktop. The layout is written with
 * `@container`, not `@media` (ADR-006), so the narrow shot is the real test —
 * a chart or a review card that starves its own text box shows up there first.
 *
 *   SITE=… TMC_PASS_FILE=… OUT=docs/evidence/reviews/screens node shoot-reviews.mjs
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const SITE = process.env.SITE || 'http://127.0.0.1:8080';
const ADMIN = process.env.TMC_USER || 'tmcadmin';
const ADMIN_PASS = fs.readFileSync(process.env.TMC_PASS_FILE || '/root/.wp_pass', 'utf8').trim();
const VENDOR = process.env.TMC_VENDOR_USER || 'tmcvendor';
const VENDOR_PASS = process.env.TMC_VENDOR_PASS || 'TmcVendor!2026';
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/reviews/screens';
const WIDTHS = (process.env.WIDTHS || '390,1280').split(',').map(Number);

fs.mkdirSync(OUT, { recursive: true });
const log = [];
const say = (s) => { log.push(s); console.log(s); };

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });

async function signIn(user, pass, width) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'fa-IR' });
  const page = await ctx.newPage();
  await page.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForURL(/wp-admin|\/$/, { timeout: 30000 }), page.click('#wp-submit')]);
  return { ctx, page };
}

/**
 * What a chart and a review card actually got, measured in the page.
 *
 * Not decoration: `no-starved-text-box` exists because a box too narrow for
 * its text is the failure this project has already shipped once, and a chart
 * is the most likely thing to cause it.
 */
const MEASURE = () => {
  const px = (v) => Math.round(v * 10) / 10;
  const out = { charts: [], narrowest_text_box: null, horizontal_scroll: false };
  out.horizontal_scroll = document.documentElement.scrollWidth > document.documentElement.clientWidth + 1;
  // Charts are HTML, not SVG — see Charts.php for why the SVG version was
  // rejected. So what is measured is the label column each row actually got,
  // which is where a narrow chart starves its own text.
  for (const figure of document.querySelectorAll('.tv-chart, .tmc-chart')) {
    const r = figure.getBoundingClientRect();
    const labels = Array.from(figure.querySelectorAll('.tv-chart__label, .tmc-chart__label'));
    out.charts.push({
      width: px(r.width),
      rows: labels.length,
      narrowest_label: labels.length
        ? px(Math.min(...labels.map((el) => el.getBoundingClientRect().width)))
        : null,
    });
  }
  let min = Infinity;
  for (const el of document.querySelectorAll('.tv-review__body, .tv-hint, .tmc-field__desc, .tmc-chart__caption, .tv-chart__caption, .tv-chart__label, .tmc-chart__label')) {
    const r = el.getBoundingClientRect();
    if (r.width > 0 && r.width < min) min = r.width;
  }
  out.narrowest_text_box = min === Infinity ? null : px(min);
  return out;
};

const SCREENS = [
  { who: 'vendor', file: 'vendor-reviews', url: (s) => `${s}/vendor/reviews/`, name: 'نظرها و امتیازها (فروشنده)' },
  { who: 'vendor', file: 'vendor-notices', url: (s) => `${s}/vendor/notices/`, name: 'اطلاعیه‌ها و گزارش‌ها (فروشنده)' },
  { who: 'admin', file: 'admin-reviews', url: (s) => `${s}/wp-admin/admin.php?page=tmc-reviews`, name: 'نظرها و امتیازها (مدیر)' },
  { who: 'admin', file: 'admin-reports', url: (s) => `${s}/wp-admin/admin.php?page=tmc-reports`, name: 'گزارش‌ها (مدیر)' },
];

const report = { site: SITE, generated_at: new Date().toISOString(), runs: [] };

for (const width of WIDTHS) {
  const admin = await signIn(ADMIN, ADMIN_PASS, width);
  const vendor = await signIn(VENDOR, VENDOR_PASS, width);
  for (const screen of SCREENS) {
    const page = screen.who === 'admin' ? admin.page : vendor.page;
    await page.goto(screen.url(SITE), { waitUntil: 'load' });
    // Fonts settle before the shot; a half-loaded Vazirmatn measures narrower
    // than the real thing and would make every text-box number a lie.
    await page.waitForLoadState('networkidle').catch(() => {});
    const shot = path.join(OUT, `${screen.file}-${width}.png`);
    await page.screenshot({ path: shot, fullPage: true });
    const measured = await page.evaluate(MEASURE);
    report.runs.push({ screen: screen.name, width, file: path.basename(shot), ...measured });
    const narrowestLabel = measured.charts
      .map((c) => c.narrowest_label)
      .filter((v) => v !== null);
    say(`${screen.name} @ ${width}px → ${path.basename(shot)}  charts=${measured.charts.length}`
      + `  narrowest_chart_label=${narrowestLabel.length ? Math.min(...narrowestLabel) + 'px' : '—'}`
      + `  narrowest_text=${measured.narrowest_text_box}px  hscroll=${measured.horizontal_scroll}`);
  }
  await admin.ctx.close();
  await vendor.ctx.close();
}

await browser.close();
fs.writeFileSync(path.join(OUT, 'measurements.json'), JSON.stringify(report, null, 2));
fs.writeFileSync(path.join(OUT, 'log.txt'), log.join('\n') + '\n');
say(`\nwrote ${report.runs.length} screenshots to ${OUT}`);
