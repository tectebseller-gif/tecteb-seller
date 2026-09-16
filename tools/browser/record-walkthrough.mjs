/**
 * A short WALKTHROUGH of the running plugin — video, at both sizes — so the
 * owner can see how it looks and behaves before installing anything.
 *
 * The brief: «یک مسیر کامل دسکتاپ و موبایل از نسخهٔ نصب‌شده از همان ZIP با
 * ویدئوی کوتاه یا پیش‌نمایش اجرایی نشان بده تا پیش از نصب، ظاهر و رفتار واقعی
 * را ببینم. تصاویر و داده‌های نمونه فقط در محیط نمایش باشند.»
 *
 * Three things follow from that sentence and shape this file:
 *
 * 1. **The plugin under the camera is the one installed FROM THE ZIP.** The
 *    run refuses to start unless the site reports the version it was told to
 *    expect, because a video of a working directory is a video of something
 *    nobody can install.
 * 2. **Behaviour, not just appearance.** It scrolls, opens, pages and types —
 *    a still cannot show that a form keeps what you typed or that page two is
 *    a different page.
 * 3. **The sample data stays here.** Every product, shop and person on screen
 *    belongs to the disposable site; nothing from it is written into the
 *    package, and the package ships no demo content (a packaging test
 *    asserts that separately).
 *
 *   SITE=… TMC_EXPECT_VERSION=0.1.0-alpha.16 OUT=docs/evidence/walkthrough \
 *     node tools/browser/record-walkthrough.mjs
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const SITE = process.env.SITE || 'http://127.0.0.1:8080';
const OUT = process.env.OUT || 'docs/evidence/walkthrough';
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const EXPECT = process.env.TMC_EXPECT_VERSION || '';
const VENDOR = process.env.TMC_VENDOR_USER || 'tmcvendor';
const VENDOR_PASS = process.env.TMC_VENDOR_PASS || 'TmcVendor!2026';
const ADMIN = process.env.TMC_USER || 'tmcadmin';
const ADMIN_PASS = fs.readFileSync(process.env.TMC_PASS_FILE || '/root/.wp_pass', 'utf8').trim();
const STORE = Number(process.env.TMC_STORE_ID || 4);

const SIZES = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'mobile', width: 390, height: 844 },
];

fs.mkdirSync(OUT, { recursive: true });
const log = [];
const say = (s) => { log.push(s); console.log(s); };
const problems = [];

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });

/** A pause the camera can see. Real people do not teleport between screens. */
const beat = (page, ms = 900) => page.waitForTimeout(ms);

async function settle(page) {
  await page.waitForLoadState('load').catch(() => {});
  await page.waitForLoadState('networkidle').catch(() => {});
}

/** Fails loudly rather than filming a fatal error that looks fine in a thumbnail. */
async function inspect(page, label) {
  const found = await page.evaluate(() => ({
    title: document.title,
    php_error: /Fatal error|Parse error|Warning: /.test(document.body.innerText.slice(0, 600)),
    horizontal_scroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    text: document.body.innerText.trim().length,
  }));
  if (found.php_error || found.text < 40) {
    problems.push(`${label}: php_error=${found.php_error} text=${found.text}`);
  }
  if (found.horizontal_scroll) {
    problems.push(`${label}: the page scrolls sideways`);
  }
  return found;
}

async function shoot(page, size, step, name) {
  const file = `${step}-${name}-${size.name}.png`;
  await page.screenshot({ path: path.join(OUT, file), fullPage: false });
  return file;
}

async function signIn(ctx, user, pass) {
  // One context per size, so the video is one continuous recording — which
  // means the previous person is still signed in. WordPress answers
  // «you are already logged in» rather than the login form, and the wait for
  // a post-login URL then times out. Clearing the cookies is the logout.
  await ctx.clearCookies();
  const page = await ctx.newPage();
  await page.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([
    page.waitForURL(/wp-admin|vendor|\/$/, { timeout: 30000 }),
    page.click('#wp-submit'),
  ]);
  return page;
}

// --- the plugin on the site must be the packaged one -----------------------
{
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, locale: 'fa-IR' });
  const page = await signIn(ctx, ADMIN, ADMIN_PASS);
  await page.goto(`${SITE}/wp-admin/plugins.php`, { waitUntil: 'domcontentloaded' });
  const running = await page.evaluate(() => {
    const row = document.querySelector('tr[data-slug="tecteb-marketplace-core"]');
    const m = row && row.innerText.match(/(\d+\.\d+\.\d+[-\w.]*)/);
    return m ? m[1] : '';
  });
  say(`plugin on the site: ${running || '<not found>'}`);
  if (EXPECT && running !== EXPECT) {
    console.error(`refusing to record: the site runs ${running || 'nothing'}, not ${EXPECT}.`);
    console.error('Install the ZIP first — a walkthrough of anything else is not a walkthrough of the package.');
    await browser.close();
    process.exit(2);
  }
  await ctx.close();
}

const manifest = { site: SITE, version: EXPECT, recorded_at: new Date().toISOString(), runs: [] };

for (const size of SIZES) {
  const videoDir = path.join(OUT, `video-${size.name}`);
  fs.mkdirSync(videoDir, { recursive: true });
  const ctx = await browser.newContext({
    viewport: { width: size.width, height: size.height },
    locale: 'fa-IR',
    isMobile: size.name === 'mobile',
    hasTouch: size.name === 'mobile',
    recordVideo: { dir: videoDir, size: { width: size.width, height: size.height } },
  });
  const steps = [];

  // --- 1. the public shop page, which is what a customer meets first -------
  const shop = await ctx.newPage();
  await shop.goto(`${SITE}/?tmc_store=${STORE}`, { waitUntil: 'domcontentloaded' });
  await settle(shop);
  await beat(shop, 1200);
  let seen = await inspect(shop, `${size.name}/store`);
  steps.push({ step: '1', name: 'store-page', title: seen.title, file: await shoot(shop, size, '1', 'store-page') });
  say(`${size.name} 1 فروشگاه عمومی — ${seen.title}`);

  // Scrolling is the point: the card grid, the ratings and the policies are
  // below the fold on a phone, and a still of the top says nothing about them.
  for (const y of [400, 900, 1500]) {
    await shop.evaluate((to) => window.scrollTo({ top: to, behavior: 'smooth' }), y);
    await beat(shop, 700);
  }
  steps.push({ step: '2', name: 'store-products', file: await shoot(shop, size, '2', 'store-products') });

  // --- 3. page two is a different page, not the same one -------------------
  const pager = shop.locator('.tmc-store__pager a').first();
  if (await pager.count()) {
    await pager.click();
    await settle(shop);
    await beat(shop, 900);
    seen = await inspect(shop, `${size.name}/store-page-2`);
    steps.push({ step: '3', name: 'store-page-two', title: seen.title, file: await shoot(shop, size, '3', 'store-page-two') });
    say(`${size.name} 3 صفحهٔ دوم کاتالوگ`);
  } else {
    say(`${size.name} 3 صفحهٔ دوم: کاتالوگ یک صفحه است`);
  }
  await shop.close();

  // --- 4..6 the vendor's own area -----------------------------------------
  const vendor = await signIn(ctx, VENDOR, VENDOR_PASS);
  for (const [step, slug, label] of [
    ['4', '/vendor/', 'پیشخوان فروشنده'],
    ['5', '/vendor/products/', 'محصولات'],
    ['6', '/vendor/orders/', 'سفارش‌های فروش'],
  ]) {
    await vendor.goto(`${SITE}${slug}`, { waitUntil: 'domcontentloaded' });
    await settle(vendor);
    await beat(vendor, 1000);
    seen = await inspect(vendor, `${size.name}${slug}`);
    steps.push({ step, name: slug.replace(/\//g, '-').replace(/^-|-$/g, ''), title: seen.title,
      file: await shoot(vendor, size, step, slug.replace(/\//g, '-').replace(/^-|-$/g, '')) });
    say(`${size.name} ${step} ${label}`);
  }

  // --- 7. a form that keeps what you typed --------------------------------
  await vendor.goto(`${SITE}/vendor/store/`, { waitUntil: 'domcontentloaded' });
  await settle(vendor);
  const city = vendor.locator('input[name="city"]').first();
  if (await city.count()) {
    await city.scrollIntoViewIfNeeded();
    await city.click();
    await city.fill('');
    await city.type('اصفهان', { delay: 90 });
    await beat(vendor, 900);
  }
  steps.push({ step: '7', name: 'vendor-store-form', file: await shoot(vendor, size, '7', 'vendor-store-form') });
  say(`${size.name} 7 فرم تنظیمات فروشگاه`);
  await vendor.close();

  // --- 8..9 the manager's side --------------------------------------------
  const admin = await signIn(ctx, ADMIN, ADMIN_PASS);
  for (const [step, url, label] of [
    ['8', '/wp-admin/admin.php?page=tmc-dashboard', 'پیشخوان مدیر'],
    ['9', '/wp-admin/admin.php?page=tmc-storefront', 'وضعیت فروش بازارگاه'],
  ]) {
    await admin.goto(`${SITE}${url}`, { waitUntil: 'domcontentloaded' });
    await settle(admin);
    await beat(admin, 1000);
    seen = await inspect(admin, `${size.name}${url}`);
    steps.push({ step, name: url.split('page=')[1], title: seen.title, file: await shoot(admin, size, step, url.split('page=')[1]) });
    say(`${size.name} ${step} ${label}`);
  }
  await admin.close();

  await ctx.close();                      // the video is only written on close
  const video = fs.readdirSync(videoDir).filter((f) => f.endsWith('.webm'));
  manifest.runs.push({ size: size.name, viewport: `${size.width}x${size.height}`, steps, videos: video });
  say(`${size.name}: ${video.length} video file(s) in ${videoDir}`);
}

await browser.close();
manifest.problems = problems;
fs.writeFileSync(path.join(OUT, 'walkthrough.json'), JSON.stringify(manifest, null, 2));
fs.writeFileSync(path.join(OUT, 'log.txt'), log.join('\n') + '\n');
say(`\n${manifest.runs.length} walkthroughs, ${problems.length} problem(s) → ${OUT}`);
problems.forEach((p) => console.error('  ! ' + p));
process.exit(problems.length === 0 ? 0 : 1);
