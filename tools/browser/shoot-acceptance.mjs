/**
 * The acceptance path, photographed on a REAL WordPress at both sizes
 * (development only; never shipped).
 *
 * The owner asked for «خروجی واقعی دسکتاپ و موبایل». Two widths, not five: this
 * is not the a11y suite — that one measures at 320/375/768/1024/1440 and
 * asserts. This one shows what a person actually sees at the two sizes they
 * will use, on rows the acceptance path just created.
 *
 * Every screen is photographed as the person who owns it: the shop's pages as
 * the VENDOR, the marketplace's as the MANAGER. Shooting a vendor page while
 * signed in as an administrator would produce a picture nobody will ever see.
 *
 *   SITE=… TMC_PASS_FILE=… OUT=docs/evidence/acceptance-path/screens node shoot-acceptance.mjs
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
const OUT = process.env.OUT || 'docs/evidence/acceptance-path/screens';
const WIDTHS = (process.env.WIDTHS || '390,1440').split(',').map(Number);

fs.mkdirSync(OUT, { recursive: true });
const log = [];
const say = (s) => { log.push(s); console.log(s); };

// In the order of the path itself, so the folder reads as the journey.
const SCREENS = [
  { step: '1', who: 'vendor', file: 'vendor-dashboard', url: (s) => `${s}/vendor/`, name: 'پیشخوان فروشنده' },
  { step: '1', who: 'vendor', file: 'vendor-staff', url: (s) => `${s}/vendor/staff/`, name: 'پرسنل' },
  { step: '2', who: 'vendor', file: 'vendor-products', url: (s) => `${s}/vendor/products/`, name: 'محصولات' },
  { step: '3', who: 'vendor', file: 'vendor-orders', url: (s) => `${s}/vendor/orders/`, name: 'سفارش‌های فروش' },
  { step: '4', who: 'vendor', file: 'vendor-orders-shipping', url: (s) => `${s}/vendor/orders/`, name: 'ارسال جزئی' },
  { step: '5', who: 'admin', file: 'admin-returns', url: (s) => `${s}/wp-admin/admin.php?page=tmc-returns`, name: 'مرجوعی و بازپرداخت' },
  { step: '6', who: 'vendor', file: 'vendor-finance', url: (s) => `${s}/vendor/finance/`, name: 'امور مالی' },
  { step: '6', who: 'admin', file: 'admin-withdrawals', url: (s) => `${s}/wp-admin/admin.php?page=tmc-withdrawals`, name: 'تسویه و برداشت' },
  { step: '7', who: 'vendor', file: 'vendor-reports', url: (s) => `${s}/vendor/notices/`, name: 'گزارش‌های فروشنده' },
  { step: '7', who: 'admin', file: 'admin-reports', url: (s) => `${s}/wp-admin/admin.php?page=tmc-reports`, name: 'گزارش‌های مدیر' },
  { step: '7', who: 'vendor', file: 'vendor-reviews', url: (s) => `${s}/vendor/reviews/`, name: 'نظرها و امتیازها' },
  { step: '·', who: 'admin', file: 'admin-storefront', url: (s) => `${s}/wp-admin/admin.php?page=tmc-storefront`, name: 'وضعیت فروش بازارگاه' },
];

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });

async function signIn(user, pass, width) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'fa-IR' });
  const page = await ctx.newPage();
  await page.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForURL(/wp-admin|vendor|\/$/, { timeout: 30000 }), page.click('#wp-submit')]);
  return { ctx, page };
}

/** Enough to tell a rendered page from an empty shell, at each width. */
const MEASURE = () => {
  const doc = document.documentElement;
  const root = document.querySelector('.tv-main, .tmc-admin');
  return {
    horizontal_scroll: doc.scrollWidth > doc.clientWidth + 1,
    our_markup: root !== null,
    cards: document.querySelectorAll('.tv-card, .tmc-card').length,
    charts: document.querySelectorAll('.tv-chart, .tmc-chart').length,
    // A page that rendered a fatal instead of a screen looks fine in a
    // thumbnail; this does not.
    php_error: /Fatal error|Warning:/.test(document.body.innerText.slice(0, 400)),
  };
};

const report = { site: SITE, generated_at: new Date().toISOString(), runs: [] };
let problems = 0;

for (const width of WIDTHS) {
  const admin = await signIn(ADMIN, ADMIN_PASS, width);
  const vendor = await signIn(VENDOR, VENDOR_PASS, width);
  for (const screen of SCREENS) {
    const page = screen.who === 'admin' ? admin.page : vendor.page;
    await page.goto(screen.url(SITE), { waitUntil: 'load' });
    await page.waitForLoadState('networkidle').catch(() => {});
    const file = `${screen.step}-${screen.file}-${width}.png`;
    await page.screenshot({ path: path.join(OUT, file), fullPage: true });
    const m = await page.evaluate(MEASURE);
    if (!m.our_markup || m.horizontal_scroll || m.php_error) problems++;
    report.runs.push({ step: screen.step, screen: screen.name, who: screen.who, width, file, ...m });
    say(`${screen.step} ${screen.name} (${screen.who}) @ ${width}px → ${file}`
      + `  cards=${m.cards} charts=${m.charts} hscroll=${m.horizontal_scroll} error=${m.php_error}`);
  }
  await admin.ctx.close();
  await vendor.ctx.close();
}

await browser.close();
report.problems = problems;
fs.writeFileSync(path.join(OUT, 'measurements.json'), JSON.stringify(report, null, 2));
fs.writeFileSync(path.join(OUT, 'log.txt'), log.join('\n') + '\n');
say(`\n${report.runs.length} screenshots, ${problems} with a problem → ${OUT}`);
process.exit(problems === 0 ? 0 : 1);
