/**
 * Walks the vendor path in a REAL WordPress and photographs every state
 * (development only; never shipped).
 *
 * draft → documents defined by the manager → upload refused → upload accepted
 * → submitted → changes requested → resubmitted → approved.
 *
 * Two browsers are signed in at once — an applicant and a manager — because
 * that is what the flow actually needs; the manager's decisions are made in
 * wp-admin while the applicant's page is refreshed to show the result.
 *
 *   SITE=… TMC_PASS_FILE=… OUT=docs/evidence/vendor node shoot-vendor.mjs
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
const OUT = process.env.OUT || 'docs/evidence/vendor';
const TMP = process.env.TMPDIR || '/tmp';

fs.mkdirSync(OUT, { recursive: true });
const log = [];
const say = (s) => { log.push(s); console.log(s); };

// Two real files: one the manager's rule must refuse, one it must accept.
const bigPdf = path.join(TMP, 'tmc-too-big.pdf');
const okPdf = path.join(TMP, 'tmc-licence.pdf');
fs.writeFileSync(bigPdf, '%PDF-1.4\n' + 'x'.repeat(3 * 1024 * 1024));
fs.writeFileSync(okPdf, '%PDF-1.4\n' + 'licence sample\n'.repeat(200) + '%%EOF');

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });

async function signIn(user, pass) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fa-IR' });
  const page = await ctx.newPage();
  await page.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForURL(/wp-admin|\/$/, { timeout: 30000 }), page.click('#wp-submit')]);
  return { ctx, page, state: await ctx.storageState() };
}

const admin = await signIn(ADMIN, ADMIN_PASS);
const vendor = await signIn(VENDOR, VENDOR_PASS);

/** A second, phone-sized window signed in as the same vendor. */
const mobileCtx = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'fa-IR', storageState: vendor.state });
const mobile = await mobileCtx.newPage();

let step = 0;
async function shoot(name, url, { both = true } = {}) {
  step += 1;
  const prefix = String(step).padStart(2, '0');
  await vendor.page.goto(url, { waitUntil: 'load' });
  await vendor.page.screenshot({ path: path.join(OUT, `${prefix}-${name}-1440.png`), fullPage: true });
  if (both) {
    await mobile.goto(url, { waitUntil: 'load' });
    await mobile.screenshot({ path: path.join(OUT, `${prefix}-${name}-390.png`), fullPage: true });
  }
  say(`${prefix} ${name}  ${url}`);
}

async function shootAdmin(name, url) {
  step += 1;
  const prefix = String(step).padStart(2, '0');
  await admin.page.goto(url, { waitUntil: 'load' });
  await admin.page.screenshot({ path: path.join(OUT, `${prefix}-${name}-1440.png`), fullPage: true });
  say(`${prefix} ${name}  ${url}  (admin)`);
}

const dash = `${SITE}/vendor/`;
const app = `${SITE}/vendor/application/`;

// 1. nothing yet ------------------------------------------------------------
await shoot('vendor-dashboard-empty', dash);

// 2. the form while the manager has defined nothing --------------------------
await shoot('application-requirements-undefined', app);

// 3. fill in the details and save the draft ----------------------------------
await vendor.page.goto(app, { waitUntil: 'load' });
await vendor.page.fill('#f-store_name', 'داروخانه آزمایشی');
await vendor.page.fill('#f-legal_name', 'شرکت آزمایشی تک‌طب');
await vendor.page.fill('#f-contact_email', 'vendor@example.test');
await vendor.page.fill('#f-contact_mobile', '09120000000');
await vendor.page.fill('#f-address', 'تهران، خیابان آزمایشی، پلاک ۱');
await vendor.page.check('input[name="terms"]');
await Promise.all([vendor.page.waitForURL(/tmc_notice/, { timeout: 30000 }), vendor.page.click('button[type=submit]')]);
await shoot('draft-saved', vendor.page.url());
await shoot('dashboard-after-draft', dash);

// 4. the manager defines a required document ---------------------------------
const docTypes = `${SITE}/wp-admin/admin.php?page=tmc-vendor-documents`;
await shootAdmin('admin-document-types-empty', docTypes);
await admin.page.goto(docTypes, { waitUntil: 'load' });
await admin.page.fill('#doc-label', 'پروانه کسب');
await admin.page.fill('#doc-instructions', 'اسکن رنگی و خوانا');
await admin.page.fill('#doc-size', '2');
await admin.page.click('button[value="add"]');
await admin.page.waitForLoadState('load');
await shootAdmin('admin-document-types-configured', docTypes);

// 5. the applicant now sees the requirement, and hits its limit --------------
await shoot('application-documents-required', app);
await vendor.page.goto(app, { waitUntil: 'load' });
await vendor.page.setInputFiles('input[type=file]', bigPdf);
await Promise.all([vendor.page.waitForURL(/tmc_notice/, { timeout: 60000 }), vendor.page.click('form[enctype] button[type=submit]')]);
await shoot('upload-refused-too-large', vendor.page.url());

await vendor.page.goto(app, { waitUntil: 'load' });
await vendor.page.setInputFiles('input[type=file]', okPdf);
await Promise.all([vendor.page.waitForURL(/tmc_notice/, { timeout: 60000 }), vendor.page.click('form[enctype] button[type=submit]')]);
await shoot('upload-accepted', vendor.page.url());

// 6. submit -------------------------------------------------------------------
await vendor.page.goto(app, { waitUntil: 'load' });
await Promise.all([
  vendor.page.waitForURL(/tmc_notice/, { timeout: 30000 }),
  vendor.page.click('form:has(input[value="submit"]) button[type=submit]'),
]);
await shoot('submitted', vendor.page.url());

// 7. the manager reviews and asks for a change --------------------------------
const queue = `${SITE}/wp-admin/admin.php?page=tmc-vendor-applications`;
await shootAdmin('admin-review-queue', queue);
await admin.page.goto(queue, { waitUntil: 'load' });
await admin.page.click('a.tmc-button:has-text("بررسی")');
await admin.page.waitForLoadState('load');
await shootAdmin('admin-review-detail', admin.page.url());
await admin.page.fill('#tmc-review-note', 'اسکن پروانه خوانا نیست؛ لطفاً نسخه واضح‌تری بارگذاری کنید.');
await admin.page.click('button[value="changes"]');
await admin.page.waitForLoadState('load');
await shootAdmin('admin-changes-requested', admin.page.url());

// 8. the applicant sees exactly what to fix -----------------------------------
await shoot('changes-requested', dash);
await shoot('application-editable-again', app);

// 9. fix, resubmit, approve ----------------------------------------------------
await vendor.page.goto(app, { waitUntil: 'load' });
await vendor.page.setInputFiles('input[type=file]', okPdf);
await Promise.all([vendor.page.waitForURL(/tmc_notice/, { timeout: 60000 }), vendor.page.click('form[enctype] button[type=submit]')]);
await vendor.page.goto(app, { waitUntil: 'load' });
await Promise.all([
  vendor.page.waitForURL(/tmc_notice/, { timeout: 30000 }),
  vendor.page.click('form:has(input[value="submit"]) button[type=submit]'),
]);

await admin.page.goto(queue, { waitUntil: 'load' });
await admin.page.click('a.tmc-button:has-text("بررسی")');
await admin.page.waitForLoadState('load');
await admin.page.click('button[value="approve"]');
await admin.page.waitForLoadState('load');
await shootAdmin('admin-approved', admin.page.url());

await shoot('approved-vendor', dash);

// A last look for anything broken on the pages we shot.
const errors = [];
for (const [name, page] of [['desktop', vendor.page], ['mobile', mobile]]) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  if (overflow > 1) errors.push(`${name}: horizontal overflow ${overflow}px`);
}
say('');
say(errors.length ? 'PROBLEMS: ' + errors.join('; ') : 'no horizontal overflow on the final pages');
fs.writeFileSync(path.join(OUT, 'walkthrough.txt'), log.join('\n') + '\n');
await browser.close();
