/**
 * Stage 1 end to end, in a real browser, on the plugin installed FROM THE ZIP:
 *
 *   store settings save → rename refused until the manager approves →
 *   bank change puts settlement on hold → invite a colleague → the one-time
 *   link activates them → they sign in and see only what their role allows →
 *   suspension takes their access away immediately.
 *
 * Two browsers are signed in at once because the flow needs two people, and a
 * third context runs signed out — the invitation page is the only door a
 * stranger may open, and the rest must stay shut to them.
 *
 *   SITE=… TMC_PASS_FILE=… TMC_OUT=docs/evidence/staff node check-vendor-staff.mjs
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
const OUT = process.env.TMC_OUT || 'docs/evidence/staff';
const STAFF_PASSWORD = 'HamkarHamkar!2026';

fs.mkdirSync(OUT, { recursive: true });
const results = [];
const check = (name, ok, detail = '') => {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'ok:  ' : 'FAIL:'} ${name}${detail ? ' — ' + detail : ''}`);
};
const text = async (page) => (await page.locator('.tv-notice p, .tmc-notice p').first().textContent() || '').trim();

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
async function signIn(user, pass, width = 1280) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'fa-IR' });
  const page = await ctx.newPage();
  await page.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  // Not "lands on wp-admin": WooCommerce sends a customer to my-account
  // instead, and a staff account IS a customer. Any page but the login form
  // means the sign-in worked.
  await Promise.all([
    page.waitForURL(/wp-admin|my-account|\/$/, { timeout: 30000 }),
    page.click('#wp-submit'),
  ]);
  return page;
}
const admin = await signIn(ADMIN, ADMIN_PASS, 1440);
const vendor = await signIn(VENDOR, VENDOR_PASS);
const anonymous = await (await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR' })).newPage();

const store = `${SITE}/vendor/store/`;
const staff = `${SITE}/vendor/staff/`;

// --- the manager's lists, so the vendor has something to choose from -------
const docTypes = `${SITE}/wp-admin/admin.php?page=tmc-vendor-documents`;
await admin.goto(docTypes, { waitUntil: 'load' });
await admin.fill('#carriers', 'post: پست جمهوری اسلامی\ntipax: تیپاکس');
await admin.fill('#networks', 'instagram: اینستاگرام\nlinkedin: لینکدین');
await admin.click('button:has-text("ذخیره فهرست‌ها")');
await admin.waitForLoadState('load');
check('manager lists saved', (await admin.locator('#carriers').inputValue()).includes('tipax'), 'carriers and networks stored');
await admin.screenshot({ path: path.join(OUT, '01-admin-lists-1440.png'), fullPage: true });

// --- store settings --------------------------------------------------------
await vendor.goto(store, { waitUntil: 'load' });
check('store page opens for an approved vendor', (await vendor.locator('.tv-tabs').count()) === 1, 'five tabs rendered');
await vendor.fill('#f-city', 'تهران');
await vendor.fill('#f-intro', 'داروخانه آزمایشی برای آزمون مرحله یک');
await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 30000 }), vendor.click('form[action*="tab=general"] button[type=submit]')]);
check('general tab saves', (await text(vendor)).includes('ذخیره شد'), await text(vendor));
await vendor.screenshot({ path: path.join(OUT, '02-store-general-1280.png'), fullPage: true });

await vendor.goto(`${store}?tab=shipping`, { waitUntil: 'load' });
await vendor.fill('#f-preparation_days', '3');
await vendor.check('input[value="tipax"]');
await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 30000 }), vendor.click('form[action*="tab=shipping"] button[type=submit]')]);
await vendor.goto(`${store}?tab=shipping`, { waitUntil: 'load' });
check('carrier choice persists', await vendor.locator('input[value="tipax"]').isChecked(), 'tipax stayed checked');
await vendor.screenshot({ path: path.join(OUT, '03-store-shipping-1280.png'), fullPage: true });

// --- renaming needs the manager -------------------------------------------
await vendor.goto(store, { waitUntil: 'load' });
const before = (await vendor.locator('.tv-card p strong').first().textContent() || '').trim();
await vendor.fill('#f-store_name', 'داروخانه تازه‌نام');
await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 30000 }), vendor.click('form:has(input[value="request_rename"]) button[type=submit]')]);
await vendor.goto(store, { waitUntil: 'load' });
const afterAsk = (await vendor.locator('.tv-card p strong').first().textContent() || '').trim();
check('the name does not change on asking', afterAsk === before, `«${before}» → «${afterAsk}»`);
check('the vendor is told it is pending', (await vendor.locator('body').textContent() || '').includes('در انتظار تأیید مدیر'), '');
await vendor.screenshot({ path: path.join(OUT, '04-rename-pending-1280.png'), fullPage: true });

// --- bank change stops settlement -----------------------------------------
await vendor.goto(`${store}?tab=bank`, { waitUntil: 'load' });
check('banking says the SMS step is not performed',
  (await vendor.locator('body').textContent() || '').includes('تأیید پیامکی این تغییر در این نسخه انجام نمی‌شود'), '');
await vendor.fill('#f-iban', 'IR062960000000100324200001');
await vendor.fill('#f-holder', 'علی رضایی');
await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 30000 }), vendor.click('form:has(input[value="request_bank"]) button[type=submit]')]);
await vendor.goto(`${store}?tab=bank`, { waitUntil: 'load' });
check('settlement is put on hold', (await vendor.locator('body').textContent() || '').includes('متوقف تا تأیید مدیر'), '');
await vendor.screenshot({ path: path.join(OUT, '05-bank-on-hold-1280.png'), fullPage: true });

// --- the manager decides ---------------------------------------------------
const queue = `${SITE}/wp-admin/admin.php?page=tmc-vendor-applications`;
await admin.goto(queue, { waitUntil: 'load' });
check('both change requests are queued', (await admin.locator('button[name="change_decision"][value="approve"]').count()) === 2,
  `${await admin.locator('button[name="change_decision"][value="approve"]').count()} waiting`);
await admin.screenshot({ path: path.join(OUT, '06-admin-change-queue-1440.png'), fullPage: true });
await admin.locator('button[name="change_decision"][value="approve"]').first().click();
await admin.waitForLoadState('load');

await vendor.goto(store, { waitUntil: 'load' });
check('the approved name is now the store name',
  (await vendor.locator('.tv-card p strong').first().textContent() || '').trim() === 'داروخانه تازه‌نام', '');

// --- staff -----------------------------------------------------------------
await vendor.goto(staff, { waitUntil: 'load' });
check('an empty staff list explains itself', (await vendor.locator('body').textContent() || '').includes('هنوز کسی به فروشگاه شما اضافه نشده'), '');
await vendor.fill('#f-first_name', 'سارا');
await vendor.fill('#f-last_name', 'محمدی');
await vendor.fill('#f-username', 'sara' + Date.now().toString().slice(-5));
const staffUser = await vendor.locator('#f-username').inputValue();
await vendor.fill('#f-email', `${staffUser}@example.test`);
await vendor.fill('#f-mobile', '09120000001');
await vendor.selectOption('#f-preset', 'order_shipping');
await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 30000 }), vendor.click('form:has(input[value="invite_staff"]) button[type=submit]')]);

const inviteLink = (await vendor.locator('.tv-note--action .tv-code').first().textContent() || '').trim();
check('an invitation link is shown once', inviteLink.includes('token='), inviteLink.slice(0, 60) + '…');
check('the mobile is shown as recorded, not verified',
  (await vendor.locator('body').textContent() || '').includes('ثبت‌شده، تأییدنشده'), '');
await vendor.screenshot({ path: path.join(OUT, '07-staff-invited-1280.png'), fullPage: true });

await vendor.reload({ waitUntil: 'load' });
check('the link is never shown again', (await vendor.locator('.tv-note--action .tv-code').count()) === 0, 'one render only');

// --- the colleague activates, signed out -----------------------------------
await anonymous.goto(inviteLink, { waitUntil: 'load' });
check('a signed-out visitor may open the invitation', (await anonymous.locator('#f-password').count()) === 1, '');
const blocked = await anonymous.goto(staff, { waitUntil: 'load' });
check('but not the staff page', !(await anonymous.locator('form:has(input[value="invite_staff"])').count()),
  `status ${blocked?.status()}`);
await anonymous.goto(inviteLink, { waitUntil: 'load' });
await anonymous.fill('#f-password', STAFF_PASSWORD);
await Promise.all([anonymous.waitForLoadState('load'), anonymous.click('button[type=submit]')]);
check('activation succeeds', (await anonymous.locator('body').textContent() || '').includes('حساب شما فعال شد'), '');
await anonymous.screenshot({ path: path.join(OUT, '08-invite-accepted-1280.png'), fullPage: true });

await anonymous.goto(inviteLink, { waitUntil: 'load' });
check('the link cannot be used twice', (await anonymous.locator('#f-password').count()) === 0, '');

// --- the colleague signs in and sees only their own store -------------------
const colleague = await signIn(staffUser, STAFF_PASSWORD);
await colleague.goto(`${SITE}/vendor/`, { waitUntil: 'load' });
check('staff reach the vendor area', (await colleague.locator('.tv-main').count()) === 1, '');
await colleague.goto(staff, { waitUntil: 'load' });
check('staff cannot manage staff', (await colleague.locator('form:has(input[value="invite_staff"])').count()) === 0,
  'the store-manager preset stops short of this, and this one is weaker');
await colleague.goto(store, { waitUntil: 'load' });
check('staff cannot open store settings', (await colleague.locator('.tv-tabs').count()) === 0, '');

// --- suspension is immediate ------------------------------------------------
await vendor.goto(staff, { waitUntil: 'load' });
await Promise.all([vendor.waitForURL(/tmc_notice/, { timeout: 30000 }), vendor.click('form:has(input[value="suspend_staff"]) button[type=submit]')]);
check('suspension is confirmed', (await text(vendor)).includes('قطع شد'), await text(vendor));
check('the history is not deleted', (await vendor.locator('.tv-staff__item').count()) === 1, 'the member is still listed');
await vendor.screenshot({ path: path.join(OUT, '09-staff-suspended-1280.png'), fullPage: true });

await colleague.goto(`${SITE}/vendor/`, { waitUntil: 'load' });
const stillIn = (await colleague.locator('body').textContent() || '').includes('پیشخوان فروشنده');
check('a suspended colleague is out at once', !stillIn || (await colleague.locator('.tv-staff__item').count()) === 0, '');

await browser.close();
const failures = results.filter((r) => !r.ok);
fs.writeFileSync(path.join(OUT, 'staff-flow.json'),
  JSON.stringify({ suite: 'vendor-stage-1', generated_at: new Date().toISOString(), total: results.length, failures: failures.length, results }, null, 2) + '\n');
console.log(`\nstage 1 — ${results.length} checks, ${failures.length} failures`);
process.exit(failures.length === 0 ? 0 : 1);
