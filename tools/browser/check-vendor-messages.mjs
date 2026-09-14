/**
 * What the vendor is actually TOLD when something is refused.
 *
 * The a11y suite (check-vendor.mjs) measures the page; this one reads it. It
 * exists because a defect got all the way to a delivered package while every
 * automated check passed: the size refusal said «حداکثر ۰ مگابایت» because
 * the limit died in the redirect that follows every write. A code was right
 * and a number was wrong, and nothing was looking at the number.
 *
 * Each case drives the real form in a real browser and asserts the sentence
 * AND the value inside it.
 *
 * Needs an applicant whose application is still editable, because every case
 * is an upload or a submit. Reset first when re-running:
 *
 *   wp db query "TRUNCATE wp_tmc_vendor_documents; TRUNCATE wp_tmc_vendor_document_types; \
 *                TRUNCATE wp_tmc_vendor_profiles;  TRUNCATE wp_tmc_vendor_applications;"
 *   SITE=… TMC_PASS_FILE=… TMC_OUT=docs/evidence/vendor node check-vendor-messages.mjs
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
const OUT = process.env.TMC_OUT || 'docs/evidence/vendor';
const TMP = process.env.TMPDIR || '/tmp';
const LIMIT_MB = 2;
const DOC_LABEL = 'پروانه کسب';

fs.mkdirSync(OUT, { recursive: true });
const results = [];
const check = (name, ok, detail) => {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'ok:  ' : 'FAIL:'} ${name} — ${detail}`);
};

// One file over the manager's limit, one of a format they did not allow, one good.
const bigPdf = path.join(TMP, 'tmc-msg-too-big.pdf');
const png = path.join(TMP, 'tmc-msg-wrong-type.png');
const okPdf = path.join(TMP, 'tmc-msg-licence.pdf');
fs.writeFileSync(bigPdf, '%PDF-1.4\n' + 'x'.repeat((LIMIT_MB + 1) * 1024 * 1024));
fs.writeFileSync(png, Buffer.from('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c489' +
  '0000000a49444154789c6360000002000100ffff03000006000557bfabd4' + '0000000049454e44ae426082', 'hex'));
fs.writeFileSync(okPdf, '%PDF-1.4\n' + 'licence sample\n'.repeat(50) + '%%EOF');

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
async function signIn(user, pass) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR' });
  const page = await ctx.newPage();
  await page.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForURL(/wp-admin|\/$/, { timeout: 30000 }), page.click('#wp-submit')]);
  return page;
}
const admin = await signIn(ADMIN, ADMIN_PASS);
const vendor = await signIn(VENDOR, VENDOR_PASS);

const app = `${SITE}/vendor/application/`;
const noticeText = async (page) => (await page.locator('.tv-notice p').first().textContent() || '').trim();

// --- the manager defines one PDF-only document with a 2 MB ceiling ---------
const docTypes = `${SITE}/wp-admin/admin.php?page=tmc-vendor-documents`;
await admin.goto(docTypes, { waitUntil: 'load' });
if ((await admin.locator('table tbody tr').count()) === 0) {
  await admin.fill('#doc-label', DOC_LABEL);
  await admin.fill('#doc-size', String(LIMIT_MB));
  await admin.uncheck('input[name="mime[]"][value="image/jpeg"]');
  await admin.click('button[value="add"]');
  await admin.waitForLoadState('load');
}
check('a required document is defined', (await admin.locator('table tbody tr').count()) >= 1,
  `${await admin.locator('table tbody tr').count()} row(s) on the manager's page`);

// --- the applicant has a draft to attach documents to ----------------------
await vendor.goto(app, { waitUntil: 'load' });
if (await vendor.locator('#f-store_name[readonly]').count()) {
  console.error('the applicant\'s application is no longer editable — reset the vendor tables first (see the header)');
  await browser.close();
  process.exit(2);
}
if (await vendor.locator('#f-store_name').count()) {
  await vendor.fill('#f-store_name', 'داروخانه آزمایشی پیام‌ها');
  await vendor.fill('#f-legal_name', 'شرکت آزمایشی تک‌طب');
  await vendor.fill('#f-contact_email', 'messages@example.test');
  await vendor.fill('#f-contact_mobile', '09120000000');
  await vendor.fill('#f-address', 'تهران، خیابان آزمایشی، پلاک ۱');
  if (await vendor.locator('input[name="terms"]').isEnabled()) await vendor.check('input[name="terms"]');
  await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 30000 }), vendor.click('form:not([enctype]) button[type=submit]')]);
}

// --- 1. submit is not offered while a required document is missing ---------
// The button is absent by design, so the refusal message can only be reached
// by posting anyway — which is exactly what a bypassed button looks like, and
// what the server must still handle.
await vendor.goto(app, { waitUntil: 'load' });
check('no submit button while a document is missing',
  (await vendor.locator('form:has(input[value="submit"]) button[type=submit]').count()) === 0,
  'the page explains instead of offering a button that would fail');

await Promise.all([
  vendor.waitForURL(/tmc_notice/, { timeout: 30000 }),
  vendor.evaluate(() => {
    const nonce = document.querySelector('input[name="tmc_vendor_nonce"]');
    const form = document.createElement('form');
    form.method = 'post';
    form.action = location.pathname;
    form.innerHTML = '<input name="tmc_vendor_action" value="submit">'
      + `<input name="tmc_vendor_nonce" value="${nonce.value}">`;
    document.body.appendChild(form);
    form.submit();
  }),
]);
let text = await noticeText(vendor);
check('missing-documents message names the document', text.includes(DOC_LABEL), `«${text}»`);
check('missing-documents message is not left dangling', !/:\s*$/.test(text), `«${text}»`);

// --- 2. a file over the limit must QUOTE the limit -------------------------
await vendor.goto(app, { waitUntil: 'load' });
await vendor.setInputFiles('input[type=file]', bigPdf);
await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 60000 }), vendor.click('form[enctype] button[type=submit]')]);
text = await noticeText(vendor);
check('size refusal quotes the manager’s limit', text.includes('۲') && text.includes('مگابایت'), `«${text}»`);
check('size refusal never says zero megabytes', !text.includes('۰ مگابایت'), `«${text}»`);
await vendor.screenshot({ path: path.join(OUT, 'msg-01-too-large-1280.png'), fullPage: true });

// --- 3. a format the manager did not allow must LIST what is allowed -------
await vendor.goto(app, { waitUntil: 'load' });
await vendor.setInputFiles('input[type=file]', png);
await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 60000 }), vendor.click('form[enctype] button[type=submit]')]);
text = await noticeText(vendor);
check('format refusal lists the allowed formats', /pdf/i.test(text), `«${text}»`);
check('format refusal is not left dangling', !/:\s*$/.test(text), `«${text}»`);
await vendor.screenshot({ path: path.join(OUT, 'msg-02-wrong-format-1280.png'), fullPage: true });

// --- 4. the happy path still says so ---------------------------------------
await vendor.goto(app, { waitUntil: 'load' });
await vendor.setInputFiles('input[type=file]', okPdf);
await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 60000 }), vendor.click('form[enctype] button[type=submit]')]);
text = await noticeText(vendor);
check('accepted upload is confirmed', text.includes('بارگذاری شد'), `«${text}»`);

// --- 5. a stale flash must not decorate the next message -------------------
// Re-visiting the same URL replays the code without the values behind it.
const staleUrl = vendor.url();
await vendor.goto(staleUrl, { waitUntil: 'load' });
text = await noticeText(vendor);
check('a replayed notice still renders a sentence', text.length > 10, `«${text}»`);
check('a replayed notice invents no number', !text.includes('۰ مگابایت'), `«${text}»`);

await browser.close();
const failures = results.filter((r) => !r.ok);
fs.writeFileSync(path.join(OUT, 'vendor-messages.json'),
  JSON.stringify({ suite: 'vendor-messages', generated_at: new Date().toISOString(), total: results.length, failures: failures.length, results }, null, 2) + '\n');
console.log(`\nvendor messages — ${results.length} checks, ${failures.length} failures`);
process.exit(failures.length === 0 ? 0 : 1);
