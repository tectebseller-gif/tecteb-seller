/**
 * The four screens the owner asked to see before and after, shot from whatever
 * package is installed right now.
 *
 * Deliberately dumb: it signs in, it navigates, it shoots. No assertions — the
 * assertions are `vendor-dashboard-run.mjs`. Keeping them apart is what lets
 * the SAME script run against `alpha.32` (where half of what this round adds
 * does not exist) and produce a real «before» rather than a screenshot of a
 * failing test.
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/dashboard/before \
 *   LABEL=alpha.32 node tools/browser/dashboard-shots.mjs
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/dashboard/before';
const LABEL = process.env.LABEL || 'unlabelled';

const VENDOR = { login: 'demo-vendor', pass: 'demo-vendor-2026' };
const MANAGER = { login: 'tmcowner', pass: 'demo-owner-2026' };
const APPLICANT = { login: 'demo-applicant', pass: 'demo-applicant-2026' };
const STAFF = { login: 'demo-staff', pass: 'demo-staff-2026' };

fs.mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
const shot = async (page, name) => {
  const file = path.join(OUT, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  console.log(`shot: ${name}`);
};

async function session(who, { width = 1280, height = 950 } = {}) {
  const ctx = await browser.newContext({ viewport: { width, height }, locale: 'fa-IR' });
  const page = await ctx.newPage();
  // Wait for the form, not for a load state: a second run starts already
  // signed out and the login page can still be painting.
  await page.goto(`${SITE}/wp-login.php?loggedout=true`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#user_login', { timeout: 30000 });
  await page.fill('#user_login', who.login);
  await page.fill('#user_pass', who.pass);
  await Promise.all([
    page.waitForNavigation({ timeout: 30000 }).catch(() => null),
    page.click('#wp-submit'),
  ]);
  return { ctx, page };
}

const report = [`label=${LABEL}`, `site=${SITE}`];

// --- the vendor's own screens ---------------------------------------------
for (const [suffix, size] of [['desktop', { width: 1280, height: 950 }], ['mobile', { width: 390, height: 844 }]]) {
  const { ctx, page } = await session(VENDOR, size);
  await page.goto(`${SITE}/vendor/`, { waitUntil: 'domcontentloaded' });
  const box = await page.evaluate(() => ({
    height: document.documentElement.scrollHeight,
    width: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
  }));
  report.push(`vendor_dashboard_${suffix} height=${box.height} scrollWidth=${box.width} innerWidth=${box.innerWidth}`);
  await shot(page, `dashboard-${suffix}`);

  await page.goto(`${SITE}/vendor/products/`, { waitUntil: 'domcontentloaded' });
  await shot(page, `products-csv-closed-${suffix}`);
  // Open whatever discloses the CSV tools, if anything does. On `alpha.32`
  // nothing does, and the absence is the point of the «before» shot.
  const summary = page.locator('summary', { hasText: 'ورود و خروج' });
  if (await summary.count() > 0) {
    await summary.first().click();
    await page.waitForTimeout(150);
    await shot(page, `products-csv-open-${suffix}`);
    report.push(`csv_disclosure_${suffix}=present`);
  } else {
    report.push(`csv_disclosure_${suffix}=absent`);
  }

  await page.goto(`${SITE}/my-account/`, { waitUntil: 'domcontentloaded' });
  await shot(page, `my-account-vendor-${suffix}`);
  await ctx.close();
}

// --- the applicant, when there is one ------------------------------------
try {
  const { ctx, page } = await session(APPLICANT);
  await page.goto(`${SITE}/my-account/`, { waitUntil: 'domcontentloaded' });
  await shot(page, 'my-account-applicant');
  await page.goto(`${SITE}/vendor/`, { waitUntil: 'domcontentloaded' });
  await shot(page, 'dashboard-applicant');
  await page.goto(`${SITE}/vendor/application/`, { waitUntil: 'domcontentloaded' });
  await shot(page, 'application-applicant');
  await ctx.close();
  report.push('applicant=shot');
} catch (e) {
  report.push(`applicant=skipped (${String(e.message).slice(0, 60)})`);
}

// --- a staff member, whose rights are narrower ----------------------------
try {
  const { ctx, page } = await session(STAFF);
  await page.goto(`${SITE}/vendor/`, { waitUntil: 'domcontentloaded' });
  await shot(page, 'dashboard-staff');
  await ctx.close();
  report.push('staff=shot');
} catch (e) {
  report.push(`staff=skipped (${String(e.message).slice(0, 60)})`);
}

// --- the manager's menu ---------------------------------------------------
{
  const { ctx, page } = await session(MANAGER);
  await page.goto(`${SITE}/wp-admin/admin.php?page=tmc-dashboard`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#adminmenu', { timeout: 30000 });
  // The menu alone, cropped: a full-page shot of wp-admin makes a 12px bubble
  // impossible to see, and «هست یا نیست» is the whole question.
  const menu = page.locator('#adminmenu');
  await menu.screenshot({ path: path.join(OUT, 'admin-menu-expanded.png') });
  console.log('shot: admin-menu-expanded');
  const bubble = await page.locator('#adminmenu .update-plugins').count();
  report.push(`admin_menu_bubbles=${bubble}`);
  const text = (await page.locator('#adminmenu').innerText()).replace(/\s+/g, ' ');
  report.push(`marketplace_menu_text=${(text.match(/بازارگاه تک‌طب[^|]{0,40}/) || ['-'])[0].trim()}`);
  await page.goto(`${SITE}/wp-admin/admin.php?page=tmc-product-review`, { waitUntil: 'domcontentloaded' });
  await shot(page, 'admin-product-review');
  await ctx.close();
}

fs.writeFileSync(path.join(OUT, 'shots.txt'), report.join('\n') + '\n');
console.log(report.join('\n'));
await browser.close();
