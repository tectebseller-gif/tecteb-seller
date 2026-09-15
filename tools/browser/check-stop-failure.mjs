/**
 * What the MANAGER is told when the stop did not finish — in a real browser,
 * on the real wp-admin screen, with a storage failure injected from outside
 * the plugin (tools/stop-failure-probe.php).
 *
 * The owner's sentence is the whole test: «اگر حتی یک محصول از فروش خارج نشد،
 * عملیات مدیر نباید موفق گزارش شود». So the assertions are about the words on
 * the screen, not about a return value: the notice must be an ERROR, it must
 * name the product that is still on sale, it must warn against swapping the
 * package — and it must not congratulate anybody.
 *
 *   SITE=… TMC_PASS_FILE=… TMC_OUT=docs/evidence/stop-failure node check-stop-failure.mjs
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const SITE = process.env.SITE || 'http://127.0.0.1:8080';
const ADMIN = process.env.TMC_USER || 'tmcadmin';
const ADMIN_PASS = fs.readFileSync(process.env.TMC_PASS_FILE || '/root/.wp_pass', 'utf8').trim();
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.TMC_OUT || 'docs/evidence/stop-failure';
const STOREFRONT = `${SITE}/wp-admin/admin.php?page=tmc-storefront`;

fs.mkdirSync(OUT, { recursive: true });
const results = [];
const check = (name, ok, detail = '') => {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'ok:  ' : 'FAIL:'} ${name}${detail ? ' — ' + detail : ''}`);
};
const text = async (page, sel) => (await page.locator(sel).allTextContents()).join(' ').replace(/\s+/g, ' ').trim();
const shot = (page, name) => page.screenshot({ path: path.join(OUT, name), fullPage: true });

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'fa-IR' });
const page = await ctx.newPage();
await page.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
await page.fill('#user_login', ADMIN);
await page.fill('#user_pass', ADMIN_PASS);
await Promise.all([page.waitForURL(/wp-admin/, { timeout: 30000 }), page.click('#wp-submit')]);

// The button, pressed while one product cannot leave the shop.
await page.goto(STOREFRONT, { waitUntil: 'load' });
const stopButton = page.locator('button[name="storefront_action"][value="stop"]');
check('the storefront page offers the stop button', (await stopButton.count()) === 1);
await Promise.all([page.waitForLoadState('load'), stopButton.click()]);
await shot(page, 'admin-01-partial-stop.png');

const notice = await text(page, '.tmc-notice, .notice');
check('the manager is NOT told it worked', !/فروش بازارگاه متوقف شد/.test(notice), notice.slice(0, 120));
check('…the screen says the stop did not finish', /توقف کامل نشد/.test(notice));
check('…and names what is still on sale', /هنوز روی فروشگاه|هنوز قابل خرید/.test(notice));
check('…and warns against swapping the package now', /عوض یا غیرفعال نکنید/.test(notice));
const errorNotice = await page.locator('.tmc-notice--error, .notice-error').count();
check('…as an error, not an aside', errorNotice >= 1, `error notices: ${errorNotice}`);

// The same fact, carried to every other admin page.
await page.goto(`${SITE}/wp-admin/index.php`, { waitUntil: 'load' });
const dashboard = await text(page, '.notice');
check('the dashboard carries the warning too', /توقف فروش کامل نشد/.test(dashboard), dashboard.slice(0, 120));
await shot(page, 'admin-02-dashboard-warning.png');

// And once the failure is gone, the retry is a plain success with no warning.
await page.goto(STOREFRONT, { waitUntil: 'load' });
const stillOnSale = await text(page, '.tmc-notice');
check('the storefront page still shows the live warning', /هنوز روی فروشگاه/.test(stillOnSale));

fs.writeFileSync(path.join(OUT, 'admin-checks.json'), JSON.stringify(results, null, 2), 'utf8');
const failed = results.filter((r) => !r.ok).length;
console.log(`\nmanager-facing report — ${results.length} checks, ${failed} failures`);
await browser.close();
process.exit(failed === 0 ? 0 : 1);
