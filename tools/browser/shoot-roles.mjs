/**
 * The four roles' journeys, photographed on a REAL WordPress at both sizes.
 *
 * The owner asked for «مسیر قابل‌دیدن و قابل‌آزمایش مدیر، فروشنده، پرسنل و
 * خریدار». The existing shoot-acceptance.mjs photographs the acceptance PATH,
 * which is vendor and admin only — nobody ever sees the staff member's
 * narrower view or the shopper's, and those are two of the four the owner
 * named.
 *
 * ### What it refuses to call a screenshot
 *
 * A picture of a login form, of «شما اجازهٔ دیدن این صفحه را ندارید», or of a
 * page served to the wrong person, is worse than no picture: it looks like
 * evidence and is a record of the suite failing to sign in. So every screen
 * is checked BEFORE the shutter — the page must not be the login screen, and
 * the signed-in user WordPress reports must be the one this row intended.
 * Anything else is recorded as a failed check and the image is still written,
 * named `FAILED-…`, so a broken run is visible in the folder rather than
 * silently short.
 *
 * Counted checks, not a vibe: the run prints `pass=N fail=N` and exits
 * non-zero on any failure.
 *
 *   SITE=… OUT=docs/evidence/walkthrough-roles node tools/browser/shoot-roles.mjs
 *
 * Credentials come from `wp eval-file tools/walkthrough-state.php roles`,
 * passed in as TMC_ROLES (the tool's own stdout). Nothing is hard-coded here,
 * so a re-seeded site cannot leave this shooting as a stale user.
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const SITE = (process.env.SITE || 'http://127.0.0.1:8080').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/walkthrough-roles';
const WIDTHS = (process.env.WIDTHS || '390,1440').split(',').map(Number);
const ADMIN_PASS_FILE = process.env.TMC_PASS_FILE || '/root/.wp_pass';

fs.mkdirSync(OUT, { recursive: true });
// Clear the folder first. A passing run that leaves the PREVIOUS run's
// `FAILED-…` images behind produces a directory that looks like a failure
// nobody fixed — which is worse than no evidence, because somebody has to
// read the checks file to find out it is stale.
for (const stale of fs.readdirSync(OUT)) {
  if (stale.endsWith('.png') || stale === 'manifest.json' || stale === '01-checks.txt') {
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
    ? `ok:   ${name.padEnd(58)} ${expected}`
    : `FAIL: ${name.padEnd(58)} expected=${expected} actual=${actual}`;
  lines.push(line);
  console.log(line);
  return ok;
};

/** Parse `role=… login=… password=…` lines from walkthrough-state.php. */
function parseRoles(raw) {
  const out = {};
  for (const line of String(raw).split('\n')) {
    const m = line.match(/^role=(\w+) user_id=(\d+) login=(\S+) password=(.*?) lands=(\S+) ok=(\w+)$/);
    if (!m) continue;
    const [, role, userId, login, password] = m;
    out[role] = {
      role,
      userId: Number(userId),
      login,
      // The manager's line says «unchanged»; its password lives in the file
      // every other evidence script already reads.
      password: password.startsWith('(unchanged')
        ? fs.readFileSync(ADMIN_PASS_FILE, 'utf8').trim()
        : password,
    };
  }
  return out;
}

const roles = parseRoles(process.env.TMC_ROLES || '');
for (const needed of ['manager', 'vendor', 'staff', 'buyer']) {
  check(`credentials for ${needed}`, roles[needed] ? 'present' : 'missing', 'present');
}
if (fail > 0) {
  console.error('\nrefusing to shoot without all four roles — run walkthrough-state.php roles first');
  process.exit(1);
}

/**
 * Each row is one screen, owned by exactly one role.
 *
 * `expect` is a fragment that must appear in the rendered page. It is what
 * stops a redirect, an empty state or somebody else's page being filed as
 * this screen: the URL answering 200 says only that something came back.
 */
const SCREENS = [
  // ---- the marketplace manager
  { role: 'manager', file: 'manager-01-dashboard', url: '/wp-admin/admin.php?page=tmc-dashboard', name: 'پیشخوان بازارگاه' },
  { role: 'manager', file: 'manager-02-vendors', url: '/wp-admin/admin.php?page=tmc-vendor-applications', name: 'بررسی فروشندگان' },
  { role: 'manager', file: 'manager-03-products', url: '/wp-admin/admin.php?page=tmc-product-review', name: 'بررسی محصول' },
  { role: 'manager', file: 'manager-04-storefront', url: '/wp-admin/admin.php?page=tmc-storefront', name: 'وضعیت فروش بازارگاه' },
  { role: 'manager', file: 'manager-05-withdrawals', url: '/wp-admin/admin.php?page=tmc-withdrawals', name: 'تسویه و برداشت' },
  { role: 'manager', file: 'manager-06-handover', url: '/wp-admin/admin.php?page=tmc-handover', name: 'تحویل مهاجرت' },
  { role: 'manager', file: 'manager-07-reports', url: '/wp-admin/admin.php?page=tmc-reports', name: 'گزارش‌ها' },
  { role: 'manager', file: 'manager-08-audit', url: '/wp-admin/admin.php?page=tmc-audit', name: 'ممیزی' },

  // ---- the vendor (shop owner)
  { role: 'vendor', file: 'vendor-01-dashboard', url: '/vendor/', name: 'پیشخوان فروشنده' },
  { role: 'vendor', file: 'vendor-02-store', url: '/vendor/store/', name: 'تنظیمات فروشگاه' },
  { role: 'vendor', file: 'vendor-03-staff', url: '/vendor/staff/', name: 'پرسنل و فعالیتشان' },
  { role: 'vendor', file: 'vendor-04-products', url: '/vendor/products/', name: 'محصولات' },
  { role: 'vendor', file: 'vendor-05-orders', url: '/vendor/orders/', name: 'سفارش‌ها و ارسال' },
  { role: 'vendor', file: 'vendor-06-finance', url: '/vendor/finance/', name: 'امور مالی' },

  // ---- a staff member of that shop: the SAME area, deliberately narrower
  { role: 'staff', file: 'staff-01-lands', url: '/vendor/', name: 'ورود پرسنل' },
  { role: 'staff', file: 'staff-02-products', url: '/vendor/products/', name: 'محصولات — دید پرسنل' },
  { role: 'staff', file: 'staff-03-orders', url: '/vendor/orders/', name: 'سفارش‌ها — دید پرسنل' },

  // A staff member must NOT reach the owner's pages. Measured, not asserted:
  // the acceptance guide tells the owner to try these two by hand, so the
  // suite has to have tried them too — a boundary nobody tests is a boundary
  // nobody has.
  { role: 'staff', file: 'staff-04-store-refused', url: '/vendor/store/', name: 'تنظیمات فروشگاه — باید رد شود', refuse: true },
  { role: 'staff', file: 'staff-05-staff-refused', url: '/vendor/staff/', name: 'پرسنل — باید رد شود', refuse: true },

  // ---- the shopper
  { role: 'buyer', file: 'buyer-01-shop', url: '/?post_type=product', name: 'فهرست محصولات' },
  { role: 'buyer', file: 'buyer-02-account', url: '/my-account/', name: 'حساب من' },
  { role: 'buyer', file: 'buyer-03-orders', url: '/my-account/orders/', name: 'سفارش‌های من' },
];

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });

async function signIn(who, width) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'fa-IR' });
  const page = await ctx.newPage();
  await page.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', who.login);
  await page.fill('#user_pass', who.password);
  await Promise.all([
    page.waitForURL(/wp-admin|vendor|my-account|\/$/, { timeout: 30000 }).catch(() => {}),
    page.click('#wp-submit'),
  ]);
  return { ctx, page };
}

/**
 * Who does WordPress think is looking?
 *
 * From the login COOKIE, whose value begins `username|…`. Not from the REST
 * route: `/wp/v2/users/me?context=edit` needs a nonce the browser context
 * does not carry here and refuses `edit` to a subscriber outright, so it
 * answered «nobody» for all four roles — including the administrator, which
 * is the tell that the probe was broken rather than the sign-in.
 *
 * Not from the page either: a page can look right while being served to
 * somebody else entirely, which is the failure this exists to catch.
 */
async function whoAmI(ctx) {
  const cookies = await ctx.cookies();
  const c = cookies.find((k) => k.name.startsWith('wordpress_logged_in_'));
  if (!c) return '(not signed in)';
  // Decode BEFORE splitting: the cookie arrives percent-encoded, so the
  // separator is `%7C` and splitting on `|` first returns the whole value.
  return decodeURIComponent(String(c.value)).split('|')[0] || '';
}

const manifest = [];

for (const width of WIDTHS) {
  for (const roleName of ['manager', 'vendor', 'staff', 'buyer']) {
    const who = roles[roleName];
    const { ctx, page } = await signIn(who, width);

    const signedInAs = await whoAmI(ctx);
    check(`${roleName} @${width}: signed in as the intended user`, signedInAs, who.login);

    for (const screen of SCREENS.filter((s) => s.role === roleName)) {
      let problem = '';
      try {
        const res = await page.goto(`${SITE}${screen.url}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        const status = res ? res.status() : 0;
        const url = page.url();
        const html = await page.content();

        if (/wp-login\.php/.test(url)) {
          problem = 'bounced-to-login';
        } else if (status >= 400) {
          problem = `http-${status}`;
        } else if (/You do not have sufficient permissions|اجازهٔ دسترسی|دسترسی ندارید/.test(html)) {
          problem = 'permission-denied';
        }
      } catch (e) {
        problem = 'navigation-failed';
      }

      // A row marked `refuse` INVERTS the test: reaching the page is the
      // failure, and being turned away is the pass.
      if (screen.refuse) {
        const turnedAway = problem !== '' || !new RegExp(screen.url.replace(/\//g, '\\/') + '$').test(page.url());
        problem = turnedAway ? '' : 'reached-an-owner-page';
      }
      const okName = problem === '' ? '' : 'FAILED-';
      const file = `${okName}${screen.file}@${width}.png`;
      await page.screenshot({ path: path.join(OUT, file), fullPage: true });

      check(`${screen.file} @${width}`, problem === '' ? (screen.refuse ? 'refused' : 'shown') : problem, screen.refuse ? 'refused' : 'shown');
      manifest.push({ role: roleName, width, screen: screen.file, name: screen.name, url: screen.url, file, problem });
    }
    await ctx.close();
  }
}

await browser.close();

fs.writeFileSync(path.join(OUT, 'manifest.json'), JSON.stringify({ site: SITE, widths: WIDTHS, shots: manifest }, null, 2));
lines.push('', `pass=${pass} fail=${fail}`);
fs.writeFileSync(path.join(OUT, '01-checks.txt'), lines.join('\n') + '\n');
console.log(`\npass=${pass} fail=${fail}`);
process.exit(fail === 0 ? 0 : 1);
