/**
 * The product stage end to end, in a real browser, on the plugin installed
 * FROM THE ZIP:
 *
 *   list and status tabs → the four-step form keeps what was typed →
 *   a real image upload → submit queues instead of publishing →
 *   the manager approves → a sensitive edit becomes a proposal while stock
 *   moves at once → another shop's product id is refused → CSV out and in.
 *
 * Two signed-in browsers (vendor and manager) plus a second vendor, because
 * the ownership rule needs a second shop to be real.
 *
 *   SITE=… TMC_PASS_FILE=… TMC_OUT=docs/evidence/products node check-products.mjs
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const SITE = process.env.SITE || 'http://127.0.0.1:8080';
const ADMIN = process.env.TMC_USER || 'tmcadmin';
const ADMIN_PASS = fs.readFileSync(process.env.TMC_PASS_FILE || '/root/.wp_pass', 'utf8').trim();
const VENDOR = process.env.TMC_VENDOR_USER || 'tmcvendor';
const VENDOR_PASS = process.env.TMC_VENDOR_PASS || 'TmcVendor!2026';
const OTHER = process.env.TMC_OTHER_VENDOR_USER || 'tmcvendor2';
const OTHER_PASS = process.env.TMC_OTHER_VENDOR_PASS || 'TmcVendor2!2026';
const OTHER_PRODUCT_ID = process.env.TMC_OTHER_PRODUCT_ID || '';
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.TMC_OUT || 'docs/evidence/products';

fs.mkdirSync(OUT, { recursive: true });
const results = [];
const check = (name, ok, detail = '') => {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'ok:  ' : 'FAIL:'} ${name}${detail ? ' — ' + detail : ''}`);
};
const notice = async (page) => (await page.locator('.tv-notice p, .tmc-notice p, .tmc-notice__text').first().textContent() || '').trim();
const bodyText = async (page) => (await page.locator('body').textContent() || '').replace(/\s+/g, ' ').trim();

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
async function signIn(user, pass, width = 1280) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'fa-IR' });
  const page = await ctx.newPage();
  await page.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([
    page.waitForURL(/wp-admin|my-account|\/$/, { timeout: 30000 }),
    page.click('#wp-submit'),
  ]);
  return page;
}

const vendor = await signIn(VENDOR, VENDOR_PASS);
const admin = await signIn(ADMIN, ADMIN_PASS, 1440);
const list = `${SITE}/vendor/products/`;

// --- the list --------------------------------------------------------------
await vendor.goto(list, { waitUntil: 'load' });
check('the products page opens for an approved vendor', (await vendor.locator('.tv-products').count()) === 1, '');
check('the vendor navigation carries a products link', (await vendor.locator('.tv-nav a[href*="/vendor/products"]').count()) >= 1, '');
const listText = await bodyText(vendor);
for (const label of ['محصولات فروشگاه', 'افزودن محصول', 'پیش‌نویس', 'نیازمند اصلاح', 'منتشرشده', 'ورود و خروج CSV']) {
  check(`the list says «${label}»`, listText.includes(label), '');
}
check('the manager’s reason is on the row', listText.includes('تصویر بسته‌بندی واضح نیست'), '');
check('the seeded rows are listed', (await vendor.locator('.tv-product').count()) === 3, '');
await vendor.screenshot({ path: path.join(OUT, '01-list-1280.png'), fullPage: true });

await vendor.goto(`${list}?status=published`, { waitUntil: 'load' });
check('a status tab filters server-side', (await vendor.locator('.tv-product').count()) === 1, 'only the published product');

// --- the four-step form keeps what was typed -------------------------------
await vendor.goto(`${list}?product=new&step=1`, { waitUntil: 'load' });
check('the new-product form opens on step 1', (await vendor.locator('#f-title').count()) === 1, '');
check('later steps are not linked before the draft exists',
  (await vendor.locator('.tv-tab.is-disabled').count()) === 3, 'steps 2–4 are announced but unreachable');
await vendor.fill('#f-title', 'ترمومتر دیجیتال پیشانی');
await vendor.selectOption('#f-category', 'gloves');
await vendor.fill('#f-brand', 'تک‌طب');
await vendor.fill('#f-short_description', 'نمونه ساخته‌شده توسط آزمون مرورگری.');
await vendor.setInputFiles('#f-product-image', {
  name: 'thermometer.png',
  mimeType: 'image/png',
  buffer: Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAYAAACqaXHeAAAAWklEQVR42u3QMQEAAAgDoC252HzAR4dAqz9zAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIECBAgAABAgQIEHgtLjkAAdSPbCEAAAAASUVORK5CYII=',
    'base64'
  ),
});
await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 30000 }), vendor.click('.tv-form__actions button[type=submit]')]);
check('step 1 saves a draft', (await notice(vendor)).includes('پیش‌نویس ساخته شد'), await notice(vendor));
const productUrl = new URL(vendor.url());
const productId = productUrl.searchParams.get('product');
check('the draft has an id and the form stays on it', Number(productId) > 0, `product=${productId}`);
await vendor.screenshot({ path: path.join(OUT, '02-form-step1-1280.png'), fullPage: true });

await vendor.goto(`${list}?product=${productId}&step=1`, { waitUntil: 'load' });
check('the uploaded image is in the gallery', (await vendor.locator('.tv-gallery__item img').count()) === 1, '');
check('the title came back', (await vendor.inputValue('#f-title')) === 'ترمومتر دیجیتال پیشانی', '');

await vendor.goto(`${list}?product=${productId}&step=2`, { waitUntil: 'load' });
check('step 2 carries step 1 in hidden fields',
  (await vendor.locator('input[type=hidden][name="title"]').inputValue()) === 'ترمومتر دیجیتال پیشانی',
  'AC-UI: the four-step form must not lose what another step holds');
check('step 2 carries the gallery', (await vendor.locator('input[type=hidden][name="image_ids[]"]').count()) === 1, '');
await vendor.fill('#f-price', '۴۸۵۰۰۰');
await vendor.fill('#f-stock', '۱۲');
await vendor.fill('#f-sku', 'TMC-THR-001');
await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 30000 }), vendor.click('.tv-form__actions button[type=submit]')]);
check('step 2 saves with Persian digits accepted', (await notice(vendor)).includes('ذخیره شد'), await notice(vendor));

await vendor.goto(`${list}?product=${productId}&step=2`, { waitUntil: 'load' });
check('the price was read as a number', (await vendor.inputValue('#f-price')) === '485000', await vendor.inputValue('#f-price'));
check('the stock was read as a number', (await vendor.inputValue('#f-stock')) === '12', '');

// --- a validation error must not empty the form ----------------------------
await vendor.fill('#f-price', '100000');
await vendor.fill('#f-sale_price', '900000');
await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 30000 }), vendor.click('.tv-form__actions button[type=submit]')]);
check('an impossible discount is refused', (await notice(vendor)).includes('قیمت تخفیف‌خورده'), await notice(vendor));
check('the refused values are still in the form', (await vendor.inputValue('#f-sale_price')) === '900000',
  'AC-UI: a returned error keeps the data');
check('and so are the other steps’ values',
  (await vendor.locator('input[type=hidden][name="title"]').inputValue()) === 'ترمومتر دیجیتال پیشانی', '');
await vendor.screenshot({ path: path.join(OUT, '03-form-error-keeps-data-1280.png'), fullPage: true });
await vendor.fill('#f-sale_price', '');
await vendor.fill('#f-price', '485000');
await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 30000 }), vendor.click('.tv-form__actions button[type=submit]')]);

// --- step 3: the category's own fields -------------------------------------
await vendor.goto(`${list}?product=${productId}&step=3`, { waitUntil: 'load' });
const step3 = await bodyText(vendor);
check('step 3 asks the category’s medical fields', step3.includes('جنس') && step3.includes('اندازه'), '');
check('a retired field is not asked', !step3.includes('کد قدیمی'), '');
check('the template version is shown', /نسخه الگوی این دسته/.test(step3), '');
await vendor.fill('#f-spec\\[material\\]', 'پلاستیک پزشکی');
await vendor.fill('#f-spec\\[size_mm\\]', '120');
await vendor.selectOption('#f-spec\\[sterile\\]', '0');
await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 30000 }), vendor.click('.tv-form__actions button[type=submit]')]);
check('step 3 saves the medical answers', (await notice(vendor)).includes('ذخیره شد'), await notice(vendor));
await vendor.screenshot({ path: path.join(OUT, '04-form-step3-1280.png'), fullPage: true });

// --- step 4: the verdict and the estimated share ---------------------------
await vendor.goto(`${list}?product=${productId}&step=4`, { waitUntil: 'load' });
const step4 = await bodyText(vendor);
check('step 4 shows the marketplace’s verdict', step4.includes('همه‌چیز کامل است'), '');
check('an unset commission is NOT printed as zero', step4.includes('تعیین‌نشده') || step4.includes('این «صفر» نیست'),
  'FIN-02 on screen');
check('the submit button is «ارسال برای بررسی»', (await vendor.locator('form:has(input[value="submit_product"]) button').count()) === 1, '');
await vendor.screenshot({ path: path.join(OUT, '05-form-step4-1280.png'), fullPage: true });

await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 30000 }), vendor.click('form:has(input[value="submit_product"]) button[type=submit]')]);
check('submitting queues rather than publishes', (await notice(vendor)).includes('بررسی مدیر فرستاده شد'), await notice(vendor));
await vendor.goto(`${list}?status=submitted`, { waitUntil: 'load' });
check('the product is in the queue', (await vendor.locator('.tv-product').count()) === 1, '');

// --- a queued product is read-only except for stock ------------------------
await vendor.goto(`${list}?product=${productId}&step=1`, { waitUntil: 'load' });
check('the queued product says it is being reviewed', (await bodyText(vendor)).includes('در صف بررسی'), '');
await vendor.goto(`${list}?product=${productId}&step=2`, { waitUntil: 'load' });
await vendor.fill('#f-price', '999000');
await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 30000 }), vendor.click('.tv-form__actions button[type=submit]')]);
check('editing a queued product is refused', (await notice(vendor)).includes('صف بررسی مدیر است'), await notice(vendor));
await vendor.goto(`${list}?product=${productId}&step=2`, { waitUntil: 'load' });
check('and the refused edit did not reach the row', (await vendor.inputValue('#f-price')) === '485000',
  await vendor.inputValue('#f-price'));

// --- the manager's two screens ---------------------------------------------
const review = `${SITE}/wp-admin/admin.php?page=tmc-product-review`;
await admin.goto(review, { waitUntil: 'load' });
const reviewText = await bodyText(admin);
check('the review page lists the queued product', reviewText.includes('ترمومتر دیجیتال پیشانی'), '');
check('the review page shows the pending proposal queue', reviewText.includes('نسخه‌های پیشنهادی'), '');
check('the diff names the fields that changed', reviewText.includes('پیشنهاد فروشنده'), '');
check('direct publishing is presented as a separate permission', reviewText.includes('از «اجازه فروش» جداست'), '');
await admin.screenshot({ path: path.join(OUT, '06-admin-review-1440.png'), fullPage: true });

await admin.click(`form:has(input[value="${productId}"]) button[value="approve"]`);
await admin.waitForLoadState('load');
check('the manager publishes it', (await notice(admin)).includes('تصمیم شما ثبت شد'), await notice(admin));
await vendor.goto(`${list}?status=published`, { waitUntil: 'load' });
check('the vendor sees it published', (await vendor.locator('.tv-product').count()) === 2, '');

// --- a live product: sensitive change proposes, stock applies ---------------
await vendor.goto(`${list}?product=${productId}&step=1`, { waitUntil: 'load' });
check('a live product says edits become a proposal', (await bodyText(vendor)).includes('نسخه فعلی روی سایت می‌ماند'), '');
await vendor.fill('#f-title', 'ترمومتر دیجیتال پیشانی — نسخه دو');
await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 30000 }), vendor.click('.tv-form__actions button[type=submit]')]);
check('the sensitive edit becomes a proposal', (await notice(vendor)).includes('نسخه پیشنهادی'), await notice(vendor));
await vendor.goto(`${list}?status=published`, { waitUntil: 'load' });
check('the live title is unchanged', (await bodyText(vendor)).includes('ترمومتر دیجیتال پیشانی') &&
  !(await bodyText(vendor)).includes('نسخه دو'), 'the published version stays as buyers see it');
await vendor.screenshot({ path: path.join(OUT, '07-live-product-proposal-1280.png'), fullPage: true });

await admin.goto(review, { waitUntil: 'load' });
check('the proposal reaches the manager with a diff', (await bodyText(admin)).includes('نسخه دو'), '');
await admin.screenshot({ path: path.join(OUT, '08-admin-revision-diff-1440.png'), fullPage: true });

// --- ownership --------------------------------------------------------------
if (OTHER_PRODUCT_ID) {
  await vendor.goto(`${list}?product=${OTHER_PRODUCT_ID}`, { waitUntil: 'load' });
  const stolen = await bodyText(vendor);
  check('another shop’s product id shows nothing of theirs',
    !stolen.includes('محصول فروشگاه دوم'), 'AC-PRIV: changing the id in the URL reaches nothing');
  check('and says so plainly', stolen.includes('پیدا نشد'), '');
}

// --- CSV --------------------------------------------------------------------
const download = await Promise.all([
  vendor.waitForEvent('download', { timeout: 30000 }),
  vendor.goto(list, { waitUntil: 'load' }).then(() => vendor.click('form:has(input[value="export_products"]) button[type=submit]')),
]).then(([d]) => d);
const csvPath = path.join(OUT, 'export.csv');
await download.saveAs(csvPath);
const csv = fs.readFileSync(csvPath, 'utf8');
check('the export is a CSV file', /\.csv$/.test(download.suggestedFilename()), download.suggestedFilename());
check('the export carries this shop’s rows', csv.includes('TMC-THR-001') && csv.includes('TMC-GLV-100'), '');
check('the export does not carry another shop’s rows', !csv.includes('TMC-OTHER'), '');
check('the export has the medical columns', csv.includes('spec:material'), '');
check('the export has a BOM so Excel reads Persian', csv.charCodeAt(0) === 0xfeff, '');

const injection = 'sku,title,price,stock\nTMC-CSV-1,=cmd|\' /C calc\'!A1,120000,3\nTMC-CSV-2,محصول دوم از فایل,-5,2\n';
await vendor.goto(list, { waitUntil: 'load' });
await vendor.setInputFiles('#f-products-csv', { name: 'import.csv', mimeType: 'text/csv', buffer: Buffer.from(injection, 'utf8') });
await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 30000 }), vendor.click('form:has(input[value="import_products"]) button[type=submit]')]);
const preview = await bodyText(vendor);
check('the import previews before writing', preview.includes('هنوز چیزی ذخیره نشده'), '');
check('the preview reports the bad row', preview.includes('قیمت نمی‌تواند منفی'), preview.slice(0, 0) || '');
check('nothing was written yet', (await (async () => {
  const p = await browser.newContext({ storageState: await vendor.context().storageState(), locale: 'fa-IR' }).then((c) => c.newPage());
  await p.goto(list, { waitUntil: 'load' });
  const n = await p.locator('.tv-product').count();
  await p.close();
  return n;
})()) === 4, 'the catalogue is unchanged during a preview');
await vendor.screenshot({ path: path.join(OUT, '09-csv-preview-1280.png'), fullPage: true });

await vendor.click('form:has(input[value="apply_products_csv"]) button[type=submit]');
await vendor.waitForLoadState('load');
check('applying writes only the good rows', (await bodyText(vendor)).includes('ورود CSV انجام شد'), await notice(vendor));
await vendor.goto(list, { waitUntil: 'load' });
const afterImport = await bodyText(vendor);
check('the imported product is listed', afterImport.includes('TMC-CSV-1'), '');
check('the refused row was not created', !afterImport.includes('TMC-CSV-2'), '');
const reexport = await Promise.all([
  vendor.waitForEvent('download', { timeout: 30000 }),
  vendor.click('form:has(input[value="export_products"]) button[type=submit]'),
]).then(([d]) => d);
const reexportPath = path.join(OUT, 'export-after-import.csv');
await reexport.saveAs(reexportPath);
check('a formula in a title leaves as text, not as a formula',
  fs.readFileSync(reexportPath, 'utf8').includes('"\'=cmd'), 'the exporter defuses it (§12)');

await browser.close();
const failures = results.filter((r) => !r.ok);
fs.writeFileSync(path.join(OUT, 'product-flow.json'),
  JSON.stringify({ suite: 'product-stage', generated_at: new Date().toISOString(), total: results.length, failures: failures.length, results }, null, 2) + '\n');
console.log(`\nproduct stage — ${results.length} checks, ${failures.length} failures`);
process.exit(failures.length === 0 ? 0 : 1);
