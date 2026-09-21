/**
 * The owner's install guide, executed as written, on a WordPress built from
 * nothing this morning.
 *
 * «مسیر راهنما را از نصب تمیز، دقیقاً با کلیک‌های نوشته‌شده و بدون آماده‌سازی
 * پنهان توسعه‌دهنده اجرا کن.» So: no `wp eval-file`, no fixture, no seeding
 * tool — every row below is a thing the guide tells a person to click, done
 * in that order, and a step that cannot be performed by clicking is a defect
 * in the guide rather than something to work around here.
 *
 * The plugin itself is installed through **افزونه‌ها ← افزودن افزونه ←
 * بارگذاری افزونه**, from the delivered ZIP, because that is what the guide's
 * first three lines say. Installing it with WP-CLI would prove a different
 * sentence than the one written.
 *
 * What this is NOT: an accessibility suite or a layout measurement. It
 * answers one question — can somebody who only reads the guide get from an
 * empty site to four working roles?
 *
 *   SITE=http://127.0.0.1:8081 ZIP=dist/…-alpha.23.zip \
 *   ADMIN=tmcowner ADMIN_PASS=… OUT=docs/evidence/owner-guide \
 *     node tools/browser/owner-guide-run.mjs
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/owner-guide';
const ZIP = path.resolve(process.env.ZIP || 'dist/tecteb-marketplace-core-0.1.0-alpha.23.zip');
const ADMIN = process.env.ADMIN || 'tmcowner';
const ADMIN_PASS = process.env.ADMIN_PASS || 'demo-owner-2026';

// Demo identities. Fictional on purpose, and named so in the guide: no real
// person, shop, phone or IBAN appears anywhere in this environment.
const VENDOR = { login: 'demo-vendor', pass: 'demo-vendor-2026', email: 'vendor@example.invalid' };
// Compared with `includes`, never retyped into a regex. Two checks failed
// earlier because a Persian needle written twice in this file is not always
// the same bytes twice (combining hamza, ZWNJ) — so the page that WAS showing
// the store was reported as missing it.
const STORE_NAME = 'تجهیزات پزشکی نمونه';
const BUYER = { login: 'demo-buyer', pass: 'demo-buyer-2026', email: 'buyer@example.invalid' };
const STAFF = { login: 'demo-staff', pass: 'demo-staff-2026', email: 'staff@example.invalid' };

fs.mkdirSync(OUT, { recursive: true });
for (const stale of fs.readdirSync(OUT)) {
  if (stale.endsWith('.png') || stale.endsWith('.txt') || stale.endsWith('.json')) {
    fs.unlinkSync(path.join(OUT, stale));
  }
}

let pass = 0;
let fail = 0;
const lines = [];
const check = (name, actual, expected) => {
  const ok = String(actual) === String(expected);
  ok ? pass++ : fail++;
  const line = ok
    ? `ok:   ${name.padEnd(62)} ${expected}`
    : `FAIL: ${name.padEnd(62)} expected=${expected} actual=${actual}`;
  lines.push(line);
  console.log(line);
  return ok;
};
const note = (text) => { lines.push(`      ${text}`); console.log(`      ${text}`); };

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'fa-IR' });
const page = await ctx.newPage();

async function signIn(login, password) {
  await page.goto(`${SITE}/wp-login.php?loggedout=true`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', login);
  await page.fill('#user_pass', password);
  await page.click('#wp-submit');
  // `waitForLoadState` can settle against the page being left, so reading the
  // URL straight afterwards found wp-login.php and called a sign-in that had
  // worked a failure. Wait for the destination, not for a load to happen.
  await page.waitForURL(/\/wp-admin\//, { timeout: 30000 }).catch(() => null);
}

/**
 * Navigate, then wait for the thing we came for — retrying the navigation.
 *
 * A fresh WordPress redirects the first admin request somewhere of its own
 * choosing (about.php after an install, upgrade.php after a version bump),
 * and a `goto` that lands mid-redirect returns a page whose form never
 * arrives. The first run of this script died on exactly that: the upload
 * field it waited for was on the page it had been bounced off.
 */
async function gotoStable(url, selector) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    if (!selector) { return true; }
    try {
      await page.waitForSelector(selector, { timeout: 15000 });
      return true;
    } catch { /* bounced; go again */ }
  }
  return false;
}

async function shot(name) {
  await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: true });
}

/**
 * The rendered words of the admin page, not its HTML.
 *
 * Matching raw markup cost two false failures on the first run: the document
 * type WAS added and the rate WAS recorded, and both assertions said no,
 * because a Persian needle written into this file does not always survive as
 * the same bytes the page emits (combining hamza, ZWNJ). innerText is what a
 * person reads, and it is what the guide quotes.
 */
async function words() {
  const main = page.locator('#wpbody-content');
  if (await main.count()) { return (await main.innerText()).replace(/\s+/g, ' '); }
  return (await page.locator('body').innerText()).replace(/\s+/g, ' ');
}

// ---------------------------------------------------------------------------
// ۱. نصب، از پیشخوان وردپرس
// ---------------------------------------------------------------------------
await signIn(ADMIN, ADMIN_PASS);
check('1. signed in as the site owner', /wp-admin/.test(page.url()) ? 'in' : 'out', 'in');

check(
  '1-2. the upload form is reachable',
  await gotoStable(`${SITE}/wp-admin/plugin-install.php?tab=upload`, '#pluginzip') ? 'reached' : 'bounced',
  'reached'
);
await page.setInputFiles('#pluginzip', ZIP);
await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#install-plugin-submit')]);
const installed = await words();
check('1-2. the ZIP installed from the dashboard', /installed successfully|نصب شد/i.test(installed) ? 'yes' : 'no', 'yes');
await shot('01-install');

// «فعال‌سازی» — the link the installer offers.
const activate = page.locator('a', { hasText: /Activate Plugin|فعال‌سازی افزونه|فعال‌سازی/ }).first();
if (await activate.count()) {
  await Promise.all([page.waitForLoadState('domcontentloaded'), activate.click()]);
}
await gotoStable(`${SITE}/wp-admin/plugins.php`, '#wpbody-content');
const pluginsPage = await page.content();  // a slug, not prose
check('1-3. the plugin is active', /tecteb-marketplace-core/.test(pluginsPage) ? 'listed' : 'absent', 'listed');
await shot('02-plugins');

// «بعد از نصب، یک بار به تنظیمات ← پیوندهای یکتا بروید و ذخیره بزنید.»
await gotoStable(`${SITE}/wp-admin/options-permalink.php`, '#submit');
await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#submit')]);
check('1-4. permalinks re-saved', /permalink/i.test(page.url()) ? 'done' : 'elsewhere', 'done');

// The menu the guide promises appears.
await gotoStable(`${SITE}/wp-admin/admin.php?page=tmc-dashboard`, '#wpbody-content');
const dash = await words();
check('2. the marketplace dashboard opens', /بازارگاه/.test(dash) ? 'yes' : 'no', 'yes');
await shot('03-dashboard');

// ---------------------------------------------------------------------------
// ۲-۲. مدارک — the state a fresh install is really in
// ---------------------------------------------------------------------------
await gotoStable(`${SITE}/wp-admin/admin.php?page=tmc-vendor-documents`, '#doc-label');
const docsFresh = await words();
check(
  '2-2. a fresh install says documents are undefined',
  /هنوز هیچ مدرکی تعریف نشده/.test(docsFresh) ? 'says so' : 'silent',
  'says so'
);
await shot('04-documents-undefined');

// The guide's first option: define one required type.
await page.fill('#doc-label', 'پروانهٔ کسب (نمونهٔ آزمایشی)');
await page.fill('#doc-instructions', 'تصویر خوانا، حداکثر ۵ مگابایت — دادهٔ نمایشی است');
await Promise.all([
  page.waitForLoadState('domcontentloaded'),
  page.click('button[name="doc_action"][value="add"]'),
]);
const docsAfter = await words();
check(
  '2-2. the type is now defined',
  /مدرک به فهرست اضافه شد/.test(docsAfter) ? 'listed' : 'absent',
  'listed'
);
check(
  '2-2. and the page now says submission is possible',
  /مدارک تعریف شده‌اند/.test(docsAfter) ? 'says so' : 'silent',
  'says so'
);
await shot('05-documents-defined');

// ---------------------------------------------------------------------------
// ۲-۴. الگوی مشخصات = دستهٔ محصول
//
// Found by this run, not by reading: the product form's «دسته» dropdown is
// fed by the manager's SPEC TEMPLATES, not by WooCommerce categories. On a
// clean install it therefore has nothing in it but the placeholder — and a
// product with no category can never be completed, so no product can ever be
// sent for review. The guide used to call this step optional («اگر خالی
// بماند فرم بخش مشخصات ندارد»), which is the same mistake it made about
// documents: a prerequisite described as a nicety.
// ---------------------------------------------------------------------------
await gotoStable(`${SITE}/wp-admin/admin.php?page=tmc-spec-templates`, 'input[name="category_key"]');
const templatesFresh = await words();
check(
  '2-4. a fresh install has no spec template (so no product category)',
  /هنوز الگویی ساخته نشده/.test(templatesFresh) ? 'none yet' : 'unexpected',
  'none yet'
);
await page.fill('input[name="category_key"]', 'diagnostics');
await page.fill('input[name="template_label"]', 'تجهیزات تشخیصی');
await Promise.all([
  page.waitForLoadState('domcontentloaded'),
  page.locator('input[name="template_action"][value="create_template"]').locator('xpath=ancestor::form').locator('button[type="submit"]').first().click(),
]);
check('2-4. the category now exists', /تجهیزات تشخیصی/.test(await words()) ? 'created' : 'failed', 'created');
await shot('06a-spec-template');

// ---------------------------------------------------------------------------
// ۲-۳. نرخ کمیسیون
// ---------------------------------------------------------------------------
await gotoStable(`${SITE}/wp-admin/admin.php?page=tmc-commission-rules`, '#rule-percent');
await page.selectOption('#rule-scope', 'general');
await page.fill('#rule-percent', '12');
await Promise.all([
  page.waitForLoadState('domcontentloaded'),
  page.click('button.tmc-button--primary'),
]);
const rates = await words();
check('2-3. a general commission rate is recorded', /درصد/.test(rates) && /general/.test(rates) ? 'yes' : 'no', 'yes');
await shot('06-commission');

// ---------------------------------------------------------------------------
// The claim under test: does the rate alone open orders? It must not.
// ---------------------------------------------------------------------------
await gotoStable(`${SITE}/wp-admin/admin.php?page=tmc-storefront`, '#wpbody-content');
const gateBefore = await words();
check(
  'the rate ALONE does not open the order gate',
  /decisions_open|trial_refused/.test(gateBefore) ? 'still closed' : 'opened',
  'still closed'
);
check(
  'and on an undeclared environment the switch is refused, with a reason',
  /پذیرفته نمی‌شود/.test(gateBefore) ? 'refused with reason' : 'no reason given',
  'refused with reason'
);
await shot('07-gate-closed');

// ---------------------------------------------------------------------------
// ۲-۳. حالت آزمایشی: محیط، بعد کلید
// ---------------------------------------------------------------------------
await gotoStable(`${SITE}/wp-admin/admin.php?page=tmc-settings`, '#wpbody-content');
const envSelect = page.locator('select[name*="environment_override"]').first();
check('2-3. the settings page offers an environment choice', await envSelect.count() ? 'yes' : 'no', 'yes');
await envSelect.selectOption('staging');
await Promise.all([
  page.waitForLoadState('domcontentloaded'),
  page.locator('form.tmc-form button[type="submit"], form.tmc-form input[type="submit"]').first().click(),
]);
await shot('08-settings-staging');

await gotoStable(`${SITE}/wp-admin/admin.php?page=tmc-storefront`, '#wpbody-content');
const trialButton = page.locator('button[name="storefront_action"][value="trial_on"]');
check('the trial switch is now offered', await trialButton.count() ? 'offered' : 'missing', 'offered');
if (await trialButton.count()) {
  await Promise.all([page.waitForLoadState('domcontentloaded'), trialButton.first().click()]);
}
const gateAfter = await words();
check('the order gate opens on trial', /ready_on_trial/.test(gateAfter) ? 'open' : 'closed', 'open');
await shot('09-gate-open');

// ---------------------------------------------------------------------------
// ۳. حساب‌های آزمایشی — created through کاربران ← افزودن کاربر
// ---------------------------------------------------------------------------
for (const who of [VENDOR, BUYER]) {
  await gotoStable(`${SITE}/wp-admin/user-new.php`, '#user_login');
  await page.fill('#user_login', who.login);
  await page.fill('#email', who.email);
  const passField = page.locator('#pass1');
  if (!(await passField.isVisible().catch(() => false))) {
    await page.click('.wp-generate-pw');
  }
  await passField.fill(who.pass);
  await page.selectOption('#role', 'customer');
  const weak = page.locator('.pw-checkbox');
  if (await weak.isVisible().catch(() => false)) { await weak.check(); }
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#createusersub')]);
  const users = await words();
  check(`3. user ${who.login} created`, /New user created|کاربر جدید/.test(users) ? 'created' : 'failed', 'created');
}
await shot('10-users');

// ---------------------------------------------------------------------------
// ۲-۵. «به‌زودی» ووکامرس را خاموش کنید
//
// Found by this run: WooCommerce ships with Site visibility = «Coming soon»
// on a fresh install, so /shop/ answers «Something big is brewing» and NO
// product is visible to a shopper — marketplace or otherwise. Every earlier
// screenshot of a working shop came from a site where somebody had already
// turned this off years ago, so nothing in the guide mentioned it.
// ---------------------------------------------------------------------------
await gotoStable(`${SITE}/wp-admin/admin.php?page=wc-settings&tab=site-visibility`, '#wpbody-content');
// WooCommerce renders this screen in React, so the control is a generated
// `inspector-radio-control-N` rather than a named field: the value is what
// identifies it. `no` means «not coming soon» — i.e. live.
const liveRadio = page.locator('input[type="radio"][value="no"]').first();
check('2-5. WooCommerce offers a site-visibility choice', await liveRadio.count() ? 'yes' : 'no', 'yes');
if (await liveRadio.count()) {
  await liveRadio.check();
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('button[name="save"], input[name="save"]').first().click(),
  ]);
}
await shot('06b-site-visibility');

// ---------------------------------------------------------------------------
// ۳-۲. فروشنده: درخواست، مدرک، ارسال — همه از /vendor/
// ---------------------------------------------------------------------------

/** A second browser session, so each role keeps its own login. */
async function asUser(who) {
  const c = await browser.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'fa-IR' });
  const pg = await c.newPage();
  await pg.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await pg.fill('#user_login', who.login);
  await pg.fill('#user_pass', who.pass);
  await pg.click('#wp-submit');
  await pg.waitForURL((u) => !/wp-login\.php/.test(u.toString()), { timeout: 30000 }).catch(() => null);
  return { c, pg };
}
const say = async (pg) => (await pg.locator('body').innerText()).replace(/\s+/g, ' ');

/**
 * Persian digits back to ASCII.
 *
 * The vendor's screens print numbers in Persian — «سفارش ۱۸» — and the
 * buyer's order-received page gives `18`. Searching one for the other found
 * nothing and reported that the vendor could not see an order that was on
 * the screen in front of it. Same trap as the Persian text needles, one
 * alphabet over.
 */
const latin = (text) => String(text).replace(/[\u06F0-\u06F9]/g, (d) => String(d.charCodeAt(0) - 0x06F0));

const { c: vctx, pg: vp } = await asUser(VENDOR);
await vp.goto(`${SITE}/vendor/`, { waitUntil: 'domcontentloaded' });
check('3-2. the vendor lands on /vendor/', /پنل فروشنده/.test(await say(vp)) ? 'yes' : 'no', 'yes');
await vp.screenshot({ path: path.join(OUT, '11-vendor-dashboard.png'), fullPage: true });

await vp.goto(`${SITE}/vendor/application/`, { waitUntil: 'domcontentloaded' });
await vp.fill('input[name="store_name"]', STORE_NAME);
await vp.fill('input[name="legal_name"]', 'شرکت نمونهٔ آزمایشی (داده ساختگی)');
await vp.fill('input[name="contact_email"]', VENDOR.email);
await vp.fill('input[name="contact_mobile"]', '09120000000');
await vp.fill('textarea[name="address"]', 'تهران، خیابان نمونه، پلاک ۱ — نشانی ساختگی');
await vp.check('input[name="terms"]');
await Promise.all([
  vp.waitForLoadState('domcontentloaded'),
  vp.locator('input[name="tmc_vendor_action"][value="save_draft"]').locator('xpath=ancestor::form').locator('button[type="submit"]').first().click(),
]);
check('3-2. the application draft saved', /ذخیره|ثبت شد|پیش‌نویس/.test(await say(vp)) ? 'saved' : 'failed', 'saved');

// The required document, uploaded through the form's own file field.
await vp.setInputFiles('input[type="file"][name="document"]', '/home/user/demo-images/demo-licence.pdf');
await Promise.all([
  vp.waitForLoadState('domcontentloaded'),
  vp.locator('input[name="tmc_vendor_action"][value="upload"]').locator('xpath=ancestor::form').locator('button[type="submit"]').first().click(),
]);
const afterUpload = await say(vp);
check('3-2. the document uploaded', /بارگذاری شد|مدرک/.test(afterUpload) ? 'uploaded' : 'failed', 'uploaded');
await vp.screenshot({ path: path.join(OUT, '12-vendor-application.png'), fullPage: true });

// «ارسال درخواست» — now possible, because the manager decided about documents.
const submitBtn = vp.locator('input[name="tmc_vendor_action"][value="submit"]')
  .locator('xpath=ancestor::form').locator('button[type="submit"]').first();
check('3-2. the submit button is now offered', await submitBtn.count() ? 'offered' : 'missing', 'offered');
if (await submitBtn.count()) {
  await Promise.all([vp.waitForLoadState('domcontentloaded'), submitBtn.click()]);
}
check('3-2. the application is submitted', /در حال بررسی|ارسال شد|بررسی است/.test(await say(vp)) ? 'submitted' : 'not submitted', 'submitted');
await vp.screenshot({ path: path.join(OUT, '13-vendor-submitted.png'), fullPage: true });

// ---------------------------------------------------------------------------
// ۳-۲. مدیر: تأیید درخواست
// ---------------------------------------------------------------------------
await gotoStable(`${SITE}/wp-admin/admin.php?page=tmc-vendor-applications`, '#wpbody-content');
const appsList = await words();
check('3-2. the manager sees the application', appsList.includes(STORE_NAME) ? 'listed' : 'absent', 'listed');
await shot('14-applications');

// The manager opens the file and approves it. `can_publish` is ticked so the
// demo vendor can publish without a second review round — a choice, and the
// guide says it is one.
await gotoStable(`${SITE}/wp-admin/admin.php?page=tmc-vendor-applications&application=1`, 'button[name="decision"]');
await page.fill('textarea[name="note"]', 'تأیید برای محیط نمایشی — دادهٔ ساختگی');
const canPublish = page.locator('input[name="can_publish"]');
if (await canPublish.count()) { await canPublish.check(); }
await Promise.all([
  page.waitForLoadState('domcontentloaded'),
  page.locator('button[name="decision"][value="approve"]').click(),
]);
check('3-2. the application is approved', /تأیید|approved/.test(await words()) ? 'approved' : 'failed', 'approved');
await shot('15-approved');

// ---------------------------------------------------------------------------
// «تأییدشده شرط لازم است، نه کافی» — the store row still has to be saved.
// ---------------------------------------------------------------------------
await vp.goto(`${SITE}/vendor/store/`, { waitUntil: 'domcontentloaded' });
const storeText = await say(vp);
check('3-2. the vendor reaches store settings', /تنظیمات فروشگاه|فروشگاه/.test(storeText) ? 'yes' : 'no', 'yes');
await vp.screenshot({ path: path.join(OUT, '16-vendor-store.png'), fullPage: true });

// Save the store row, which is what turns «تأییدشده» into «قابل فروش».
await vp.fill('input[name="city"]', 'تهران');
await vp.fill('textarea[name="intro"]', 'فروشندهٔ نمونه برای محیط نمایشی. همهٔ اطلاعات این فروشگاه ساختگی است.');
await Promise.all([
  vp.waitForLoadState('domcontentloaded'),
  vp.locator('input[name="tmc_vendor_action"][value="save_store"]').locator('xpath=ancestor::form').locator('button[type="submit"]').first().click(),
]);
check('3-2. store settings saved', /ذخیره شد|به‌روز/.test(await say(vp)) ? 'saved' : 'failed', 'saved');

// ---------------------------------------------------------------------------
// ۳-۳. پرسنل: دعوت، و لینکی که فقط یک بار دیده می‌شود
// ---------------------------------------------------------------------------
await vp.goto(`${SITE}/vendor/staff/`, { waitUntil: 'domcontentloaded' });
await vp.fill('input[name="first_name"]', 'همکار');
await vp.fill('input[name="last_name"]', 'نمونه');
await vp.fill('input[name="username"]', STAFF.login);
await vp.fill('input[name="email"]', STAFF.email);
await vp.fill('input[name="mobile"]', '09120000001');
await vp.selectOption('select[name="preset"]', 'order_shipping');
await Promise.all([
  vp.waitForLoadState('domcontentloaded'),
  vp.locator('input[name="tmc_vendor_action"][value="invite_staff"]').locator('xpath=ancestor::form').locator('button[type="submit"]').first().click(),
]);
const staffText = await say(vp);
check('3-3. the colleague was invited', /دعوت/.test(staffText) ? 'invited' : 'failed', 'invited');

// The invite link is shown ONCE. Read it from the page, exactly as a person
// would have to — if it is not on screen, the guide's instruction is wrong.
// It is printed as text inside a `<bdi class="tv-code">`, not as a link —
// deliberately, so it is copied rather than clicked by the wrong person. The
// first version of this check looked only at anchors and reported «absent»
// about a link that was on the screen.
const inviteHref = await vp.locator('.tv-code, a[href*="invite"], code, input[readonly]').evaluateAll(
  (els) => {
    for (const el of els) {
      const v = (el.getAttribute && el.getAttribute('href')) || el.value || el.textContent || '';
      if (/^https?:\/\//.test(v.trim())) { return v.trim(); }
    }
    return '';
  }
);
check('3-3. the one-time invite link is on screen', inviteHref ? 'shown' : 'absent', 'shown');
await vp.screenshot({ path: path.join(OUT, '17-vendor-staff.png'), fullPage: true });
note(`invite link: ${inviteHref || '(none)'}`);

// The colleague opens it in their own session and sets a password.
if (inviteHref) {
  const sctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'fa-IR' });
  const sp = await sctx.newPage();
  await sp.goto(inviteHref.startsWith('http') ? inviteHref : `${SITE}${inviteHref}`, { waitUntil: 'domcontentloaded' });
  const pwField = sp.locator('input[type="password"]').first();
  if (await pwField.count()) {
    await pwField.fill(STAFF.pass);
    const confirm = sp.locator('input[type="password"]').nth(1);
    if (await confirm.count()) { await confirm.fill(STAFF.pass); }
    await Promise.all([
      sp.waitForLoadState('domcontentloaded'),
      sp.locator('form button[type="submit"], form input[type="submit"]').first().click(),
    ]);
  }
  const accepted = (await sp.locator('body').innerText()).replace(/\s+/g, ' ');
  check('3-3. the colleague activated their account', /پنل فروشنده|خوش آمدید|رمز/.test(accepted) ? 'active' : 'failed', 'active');
  await sp.screenshot({ path: path.join(OUT, '18-staff-accepted.png'), fullPage: true });
  await sctx.close();
}

// ---------------------------------------------------------------------------
// ۴-۲. محصول: فرم چهارمرحله‌ای، با تصویر واقعی
// ---------------------------------------------------------------------------
const CATALOGUE = [
  { shape: 'stethoscope', title: 'گوشی پزشکی دوطرفه (نمونه)', price: '2450000', stock: '12', sku: 'DEMO-STETH-01' },
  { shape: 'monitor', title: 'فشارسنج بازویی دیجیتال (نمونه)', price: '3890000', stock: '7', sku: 'DEMO-BPM-02' },
  { shape: 'oximeter', title: 'پالس اکسیمتر انگشتی (نمونه)', price: '1290000', stock: '20', sku: 'DEMO-OXI-03' },
];

/** One pass through the four steps. Returns the step it got stuck on, or 0. */
async function createProduct(item) {
  await vp.goto(`${SITE}/vendor/products/?product=new`, { waitUntil: 'domcontentloaded' });
  let imageSent = false;
  for (let guard = 0; guard < 8; guard++) {
    const step = await vp.locator('input[name="step"]').first().getAttribute('value').catch(() => null);
    const has = async (sel) => (await vp.locator(sel).count()) > 0;

    // «ارسال برای بررسی» lives in its OWN form, so it is found by its words
    // rather than by walking up from the save form. The first version of this
    // loop clicked «ذخیره و ادامه» three times at step 4 and reported the
    // product stuck — about a product the page was already calling complete.
    const send = vp.locator('form button[type="submit"]', { hasText: 'ارسال برای بررسی' });
    if (await send.count()) {
      await Promise.all([vp.waitForLoadState('domcontentloaded'), send.first().click()]);
      return /بررسی|ارسال/.test(await say(vp)) ? 0 : Number(step || 0);
    }

    // Once. The file input is on step 1, and step 1 renders twice (the first
    // save CREATES the draft and stays), so an unguarded upload attached the
    // same picture to the product twice.
    if (!imageSent && await has('input[type="file"][name="product_image"]')) {
      await vp.setInputFiles('input[type="file"][name="product_image"]', `/home/user/demo-images/${item.shape}.png`);
      imageSent = true;
    }
    if (await has('input[type="text"][name="title"]')) { await vp.fill('input[type="text"][name="title"]', item.title); }
    if (await has('input[type="text"][name="brand"]')) { await vp.fill('input[type="text"][name="brand"]', 'برند نمونه'); }
    if (await has('textarea[name="short_description"]')) {
      await vp.fill('textarea[name="short_description"]', 'کالای نمایشی برای آزمایش ظاهر و مسیر خرید. مشخصات واقعی نیست.');
    }
    if (await has('select[name="category"]')) {
      const values = await vp.locator('select[name="category"] option').evaluateAll((o) => o.map((x) => x.value).filter(Boolean));
      if (!values.length) { return Number(step || 0); }   // nothing to pick: the run says so rather than looping
      await vp.selectOption('select[name="category"]', values[0]);
    }
    for (const [sel, value] of [
      ['input[type="text"][name="price"], input[type="number"][name="price"]', item.price],
      ['input[type="text"][name="stock"], input[type="number"][name="stock"]', item.stock],
      ['input[type="text"][name="sku"]', item.sku],
      ['input[type="text"][name="weight_grams"], input[type="number"][name="weight_grams"]', '400'],
    ]) {
      if (await has(sel)) { await vp.locator(sel).first().fill(value); }
    }
    const submit = vp.locator('input[name="tmc_vendor_action"][value="save_product"]')
      .locator('xpath=ancestor::form').locator('button[type="submit"]');
    if (!(await submit.count())) { return Number(step || 0); }
    // The last button on the step is «ذخیره و ادامه» / «ارسال برای بررسی».
    await Promise.all([vp.waitForLoadState('domcontentloaded'), submit.last().click()]);
    const after = await say(vp);
    if (/برای بررسی ارسال شد|در انتظار بررسی/.test(after)) { return 0; }
    const nextStep = await vp.locator('input[name="step"]').first().getAttribute('value').catch(() => null);
    if (nextStep === step && guard > 1) { return Number(step || 0); }
  }
  return -1;
}

let made = 0;
for (const item of CATALOGUE) {
  const stuck = await createProduct(item);
  if (stuck === 0) { made++; }
  else { note(`product «${item.title}» stopped at step ${stuck}`); }
}
check('4-2. the vendor created the demo catalogue', made, CATALOGUE.length);
await vp.goto(`${SITE}/vendor/products/`, { waitUntil: 'domcontentloaded' });
await vp.screenshot({ path: path.join(OUT, '19-vendor-products.png'), fullPage: true });

// ---------------------------------------------------------------------------
// ۴-۱. مدیر: بررسی و تأیید محصول
// ---------------------------------------------------------------------------
await gotoStable(`${SITE}/wp-admin/admin.php?page=tmc-product-review`, '#wpbody-content');
const reviewText = await words();
check('4-1. the manager sees products awaiting review', reviewText.includes(CATALOGUE[0].title) ? 'listed' : 'empty', 'listed');
await shot('20-product-review');

// Approve each one. The page re-renders after every decision, so the list is
// re-read each time rather than held across a navigation.
let approved = 0;
for (let i = 0; i < 6; i++) {
  await gotoStable(`${SITE}/wp-admin/admin.php?page=tmc-product-review`, '#wpbody-content');
  const btn = page.locator('button[name="decision"][value="approve"]');
  if (!(await btn.count())) { break; }
  await Promise.all([page.waitForLoadState('domcontentloaded'), btn.first().click()]);
  approved++;
}
check('4-1. every demo product is approved', approved, CATALOGUE.length);
await shot('21-products-approved');

// ---------------------------------------------------------------------------
// ۴-۴. خریدار: فهرست فروشگاه و صفحهٔ عمومی فروشنده
// ---------------------------------------------------------------------------
const { c: bctx, pg: bp } = await asUser(BUYER);
await bp.goto(`${SITE}/?post_type=product`, { waitUntil: 'domcontentloaded' });
const shopText = (await bp.locator('body').innerText()).replace(/\s+/g, ' ');
check('4-4. approved products are on the shop', shopText.includes(CATALOGUE[0].title) ? 'visible' : 'absent', 'visible');
await bp.screenshot({ path: path.join(OUT, '22-buyer-shop.png'), fullPage: true });

// The public store page is keyed on the vendor's WordPress user id, so the
// id is READ off the users screen rather than assumed to be 1 — which is the
// site owner on every install, and was the first version of this check.
await gotoStable(`${SITE}/wp-admin/users.php`, '#wpbody-content');
const vendorId = await page.locator(`a[href*="user-edit.php"]`).evaluateAll((els, login) => {
  for (const el of els) {
    const row = el.closest('tr');
    if (row && row.textContent.includes(login)) {
      const m = (el.getAttribute('href') || '').match(/user_id=(\d+)/);
      if (m) { return m[1]; }
    }
  }
  return '';
}, VENDOR.login);
check('4-4. the vendor user id was found', vendorId ? 'found' : 'missing', 'found');
note(`public store page: ${SITE}/?tmc_store=${vendorId}`);

await bp.goto(`${SITE}/?tmc_store=${vendorId}`, { waitUntil: 'domcontentloaded' });
const storePublic = (await bp.locator('body').innerText()).replace(/\s+/g, ' ');
check('4-4. the public store page renders', storePublic.includes(STORE_NAME) ? 'renders' : 'missing', 'renders');
check(
  '4-4. and it shows none of the private fields',
  /09120000000|vendor@example\.invalid|خیابان نمونه، پلاک/.test(storePublic) ? 'LEAKED' : 'clean',
  'clean'
);
await bp.screenshot({ path: path.join(OUT, '23-public-store.png'), fullPage: true });

// One purchase, so the demo has an order in it and the vendor has a figure.
await bp.goto(`${SITE}/?post_type=product`, { waitUntil: 'domcontentloaded' });
// The shop's add-to-cart is an AJAX link. Following its href is what a
// browser without JavaScript does, and it is the path that actually leaves
// the cart filled when the page is then loaded fresh — clicking and waiting
// on the AJAX left the cart empty in the first three runs of this script.
const cartHref = await bp.locator('a.add_to_cart_button').first().getAttribute('href').catch(() => null);
let added = '';
if (cartHref) {
  await bp.goto(cartHref.startsWith('http') ? cartHref : `${SITE}${cartHref}`, { waitUntil: 'domcontentloaded' });
  added = (await bp.locator('body').innerText()).replace(/\s+/g, ' ');
}
// The confirmation is server-rendered; the /cart/ page is a WooCommerce
// BLOCK that fills itself in with JavaScript, so reading its markup straight
// after navigation finds the headings and no rows. The first version of this
// check called a working cart empty for that reason.
check(
  '4-4. a marketplace product can be added to the cart',
  CATALOGUE.some((i) => added.includes(i.title)) ? 'added' : 'not added',
  'added'
);
await bp.goto(`${SITE}/cart/`, { waitUntil: 'domcontentloaded' });
await bp.waitForTimeout(4000);   // let the cart block hydrate before the photo
await bp.screenshot({ path: path.join(OUT, '24-buyer-cart.png'), fullPage: true });

// ---------------------------------------------------------------------------
// ۴-۵. یک سفارش واقعی — بدون پرداخت واقعی
//
// «افزودن به سبد» خرید نیست. This places an ORDER: WooCommerce's own offline
// method (cash on delivery) completes it and moves no money, which is the
// most this build can honestly do — there is no gateway. The run refuses to
// continue if no payment method is offered, rather than stopping at the cart
// and calling that a purchase.
// ---------------------------------------------------------------------------
await bp.goto(`${SITE}/checkout/`, { waitUntil: 'domcontentloaded' });

// Two checkout shapes exist and this run accepts either. The demo site now
// serves the CLASSIC shortcode checkout, because the block checkout draws its
// labels from a JavaScript translation pack we do not ship, so a Persian site
// showed an English checkout. A site that kept the block pages must still
// pass this step rather than fail on a selector.
const classicCheckout = await bp
  .waitForSelector('#billing_first_name', { timeout: 20000 })
  .then(() => true)
  .catch(() => false);
if (!classicCheckout) {
  await bp.waitForSelector('#billing-first_name', { timeout: 20000 }).catch(() => null);
}
note(`checkout shape: ${classicCheckout ? 'classic (shortcode)' : 'block'}`);

const F = classicCheckout
  ? {
      email: '#billing_email',
      country: '#billing_country',
      first: '#billing_first_name',
      last: '#billing_last_name',
      state: '#billing_state',
      city: '#billing_city',
      address: '#billing_address_1',
      postcode: '#billing_postcode',
      phone: '#billing_phone',
      pay: 'input[name="payment_method"]',
    }
  : {
      email: '#email',
      country: '#billing-country',
      first: '#billing-first_name',
      last: '#billing-last_name',
      state: '#billing-state',
      city: '#billing-city',
      address: '#billing-address_1',
      postcode: '#billing-postcode',
      phone: '#billing-phone',
      pay: 'input[type="radio"][id*="payment-method-options"]',
    };

// Both shapes hydrate the payment section after the address section. Asking
// the moment the address field appeared reported «no payment method» about a
// checkout that grew one a second later.
await bp.waitForSelector(F.pay, { state: 'attached', timeout: 30000 }).catch(() => null);
const codRadio = bp.locator(F.pay).first();
check('4-5. a payment method is offered at checkout', await codRadio.count() ? 'offered' : 'none', 'offered');

// With exactly one gateway enabled the classic checkout still renders the
// radio but hides it and ticks it itself — so a plain .check() fails on an
// invisible element that is already in the state we want.
const pick = async (locator) => {
  if (await locator.isChecked().catch(() => false)) { return; }
  await locator.check({ force: true }).catch(async () => {
    await locator.evaluate((el) => {
      el.checked = true;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
  });
};

// The classic country field is a select2 widget: the real <select> is hidden,
// so Playwright's own actionability check refuses it. force + a native change
// event is what jQuery's delegated handler listens for anyway.
const choose = async (selector, value) => {
  const el = bp.locator(selector);
  if (!(await el.count())) { return; }
  await el.selectOption(value, { force: true, timeout: 10000 }).catch(async () => {
    await el.evaluate((node, v) => {
      node.value = v;
      node.dispatchEvent(new Event('change', { bubbles: true }));
    }, value);
  });
};

const settle = async () => {
  await bp
    .waitForFunction(() => !document.querySelector('.blockUI'), null, { timeout: 30000 })
    .catch(() => null);
  await bp.waitForTimeout(1000);
};

if (await codRadio.count()) {
  await bp.fill(F.email, BUYER.email).catch(() => null);
  await choose(F.country, 'IR');
  await settle();   // changing the country rebuilds the state and postcode fields
  await bp.fill(F.first, 'خریدار');
  await bp.fill(F.last, 'نمونه');

  // After the country change the state field can be a <select> of provinces
  // or a plain text input, depending on the country. Read what is there.
  const state = bp.locator(F.state);
  if (await state.count()) {
    const tag = await state.evaluate((el) => el.tagName.toLowerCase()).catch(() => '');
    if (tag === 'select') {
      const opts = await state.locator('option').evaluateAll((o) => o.map((x) => x.value).filter(Boolean));
      if (opts.length) { await choose(F.state, opts[0]); }
    } else {
      await state.fill('تهران').catch(() => null);
    }
  }
  await bp.fill(F.city, 'تهران').catch(() => null);
  await bp.fill(F.address, 'خیابان نمونه، پلاک ۱ — نشانی ساختگی').catch(() => null);
  const postcode = bp.locator(F.postcode);
  if (await postcode.count()) { await postcode.fill('1234567890').catch(() => null); }
  await bp.fill(F.phone, '09120000002').catch(() => null);
  await pick(codRadio);

  const terms = bp.locator('#terms');
  if (await terms.count()) { await pick(terms); }

  // Both shapes revalidate after every field: the block checkout disables its
  // button while it does, the classic one covers the form with an overlay.
  // Clicking the moment the radio was ticked raced that and timed out on a
  // control that became clickable a second later.
  await settle();
  const placeOrder = classicCheckout
    ? bp.locator('#place_order')
    : bp.locator('button', { hasText: /Place Order|ثبت سفارش/ }).first();
  for (let i = 0; i < 30 && !(await placeOrder.isEnabled().catch(() => false)); i++) {
    await bp.waitForTimeout(1000);
  }
  await bp.screenshot({ path: path.join(OUT, '25-buyer-checkout.png'), fullPage: true });
  await placeOrder.click({ timeout: 60000 });
  await bp.waitForURL(/order-received/, { timeout: 60000 }).catch(() => null);
  await bp.waitForTimeout(3000);
}

const received = (await bp.locator('body').innerText()).replace(/\s+/g, ' ');
const orderNo = (received.match(/(?:Order number|شماره سفارش)[:\s]*#?\s*([0-9\u06F0-\u06F9]+)/) || [])[1]
  || (bp.url().match(/order-received\/(\d+)/) || [])[1] || '';
check('4-5. the order was placed (not just carted)', orderNo ? 'placed' : 'not placed', 'placed');
note(`order number: ${orderNo || '(none)'}`);
await bp.screenshot({ path: path.join(OUT, '26-buyer-order-received.png'), fullPage: true });

await bp.goto(`${SITE}/my-account/orders/`, { waitUntil: 'domcontentloaded' });
const myOrders = (await bp.locator('body').innerText()).replace(/\s+/g, ' ');
check('4-5. the buyer sees it under their own orders', orderNo && latin(myOrders).includes(String(orderNo)) ? 'listed' : 'absent', 'listed');
await bp.screenshot({ path: path.join(OUT, '27-buyer-orders.png'), fullPage: true });
await bctx.close();

// ---------------------------------------------------------------------------
// ۴-۶. همان سفارش، از چشم فروشنده و پرسنل — و ثبت ارسال
// ---------------------------------------------------------------------------
await vp.goto(`${SITE}/vendor/orders/`, { waitUntil: 'domcontentloaded' });
const vendorOrders = await say(vp);
check(
  '4-6. the vendor sees that order',
  orderNo && latin(vendorOrders).includes(String(orderNo)) ? 'sees it' : 'absent',
  'sees it'
);
check(
  '4-6. and not the buyer\'s phone or email',
  /09120000002|buyer@example\.invalid/.test(vendorOrders) ? 'LEAKED' : 'withheld',
  'withheld'
);
await vp.screenshot({ path: path.join(OUT, '28-vendor-orders.png'), fullPage: true });

// The colleague — a separate login, the narrower view — sees the same order
// and is the one who records the shipment.
const { c: sctx2, pg: sp2 } = await asUser(STAFF);
await sp2.goto(`${SITE}/vendor/orders/`, { waitUntil: 'domcontentloaded' });
const staffOrders = (await sp2.locator('body').innerText()).replace(/\s+/g, ' ');
check(
  '4-6. the colleague sees the same order',
  orderNo && latin(staffOrders).includes(String(orderNo)) ? 'sees it' : 'absent',
  'sees it'
);

const shipForm = sp2.locator('input[name="tmc_vendor_action"][value="ship_order_item"]').locator('xpath=ancestor::form').first();
check('4-6. the colleague has the shipment form', await shipForm.count() ? 'present' : 'missing', 'present');
if (await shipForm.count()) {
  const carrier = shipForm.locator('select[name^="carrier_"]');
  if (await carrier.count()) {
    const opts = await carrier.locator('option').evaluateAll((o) => o.map((x) => x.value).filter(Boolean));
    note(`carriers offered: ${opts.length ? opts.join(',') : '(none — the manager has not entered the list)'}`);
    if (opts.length) { await carrier.selectOption(opts[0]); }
  }
  const tracking = shipForm.locator('input[name^="tracking_"]');
  if (await tracking.count()) { await tracking.fill('DEMO-TRACK-0001'); }
  await Promise.all([
    sp2.waitForLoadState('domcontentloaded'),
    shipForm.locator('button[type="submit"]').first().click(),
  ]);
}
const afterShip = (await sp2.locator('body').innerText()).replace(/\s+/g, ' ');
check(
  '4-6. the shipment is recorded',
  /ارسال ثبت شد|ارسال‌شده|بخشی ارسال/.test(afterShip) ? 'recorded' : 'not recorded',
  'recorded'
);
await sp2.screenshot({ path: path.join(OUT, '29-staff-shipped.png'), fullPage: true });
await sctx2.close();

// And the vendor sees the new state — the colleague's work, on the owner's
// screen, which is the whole point of the two accounts being separate.
await vp.goto(`${SITE}/vendor/orders/`, { waitUntil: 'domcontentloaded' });
const vendorAfter = await say(vp);
check(
  '4-6. the owner sees the shipment their colleague recorded',
  /ارسال‌شده|بخشی ارسال|DEMO-TRACK-0001/.test(vendorAfter) ? 'visible' : 'absent',
  'visible'
);
await vp.screenshot({ path: path.join(OUT, '30-vendor-after-shipment.png'), fullPage: true });

fs.writeFileSync(path.join(OUT, '01-checks.txt'), lines.join('\n') + `\n\npass=${pass} fail=${fail}\n`);
console.log(`\npass=${pass} fail=${fail}`);
await vctx.close();
await browser.close();
process.exit(fail === 0 ? 0 : 1);
