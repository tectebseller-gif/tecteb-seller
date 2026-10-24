/**
 * The shop-record path in a browser: preview → import → result.
 *
 * ⛔ The bytes under test are the INSTALLED ones (`alpha.61`), and the state is
 * arranged OUTSIDE the browser by a seeding script, because rendering a page
 * says nothing about its decisions (`alpha.13`). Each round's evidence tool
 * has its own name (`alpha.54`): nothing under `tools/browser/alpha7[0-6]-run.mjs`
 * is edited.
 *
 * ⛔ Dokan itself does not run here and cannot (`alpha.53`). What the seeder
 * builds is Dokan-SHAPED data in Dokan's own tables, and the reader under
 * measurement is the real `WpDokanReader`.
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/alpha77-ui \
 *   WP="php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo" \
 *     node tools/browser/alpha77-run.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/alpha77-ui';
const WP = process.env.WP || 'php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo';
const HANDOVER = `${SITE}/wp-admin/admin.php?page=tmc-handover`;
const QUEUE = `${SITE}/wp-admin/admin.php?page=tmc-jobs`;
const IMPORT = `${SITE}/wp-admin/admin.php?page=tmc-import`;
const MANAGER = { login: 'tmcowner', pass: 'demo-owner-2026' };

const HEADER = fs.readFileSync(new URL('../../tecteb-marketplace-core.php', import.meta.url), 'utf8');
const EXPECT = process.env.TMC_EXPECT_VERSION
  || (HEADER.match(/^\s*\*\s*Version:\s*(.+)$/m)?.[1] ?? '').trim();

// Persian needles copied from the views' own strings, not retyped from the
// idea of them (`alpha.23`).
const T = {
  handoverTitle: 'تحویل مهاجرت',
  preview: 'پیش‌نمایش سوابق فروشگاه',
  readOnly: 'این جدول فقط می‌خواند',
  button: 'ورود سوابق به صف',
  historyOnly: 'ورود سوابق فقط تاریخچه ثبت می‌کند',
  retired: 'هنوز سابقه‌ای وارد نشده است',
  retiredAction: 'رفتن به مهاجرت از دکان',
  pending: 'واردنشده',
  imported: 'واردشده',
  staff: 'پرسنل',
  balance: 'دفتر مانده',
  withdrawals: 'درخواست برداشت',
  queued: 'به صف اضافه شد',
  counts: 'این عددها از کجا می‌آیند؟',
  catalogueWide: 'نگاشت‌شده در کلِ کاتالوگ',
  manifestTotal: 'جمعِ دفترِ اجراها',
};

fs.mkdirSync(OUT, { recursive: true });
const lines = [];
let pass = 0;
let fail = 0;
const check = (what, got, want) => {
  const ok = got === want;
  ok ? pass++ : fail++;
  const line = `${ok ? 'PASS' : 'FAIL'} ${what}${ok ? '' : ` — got ${JSON.stringify(got)}, want ${JSON.stringify(want)}`}`;
  lines.push(line);
  console.log(line);
};
const note = (text) => {
  lines.push(`note ${text}`);
  console.log(`note ${text}`);
};
const state = (verb) => execSync(`${WP} eval-file tools/alpha77-ui-state.php ${verb}`, { encoding: 'utf8' }).trim();
const field = (report, key) => (report.match(new RegExp(`^${key}=(.*)$`, 'm'))?.[1] ?? '').trim();

let browser;
try {
  const installed = execSync(
    `${WP} plugin list --name=tecteb-marketplace-core --field=version`,
    { encoding: 'utf8' },
  ).trim();
  check('0 the installed package is the one built here', installed, EXPECT);

  const seeded = state('seed');
  note(`seed: ${seeded.replace(/\n/g, ' · ')}`);
  check('0a the two shops exist', field(seeded, 'shop_a') !== '0' && field(seeded, 'shop_b') !== '0', true);
  check('0b and NOT one record is held yet',
    `${field(seeded, 'our_staff')}/${field(seeded, 'our_balance')}/${field(seeded, 'our_withdrawals')}`,
    '0/0/0');

  browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
  const session = async (who, viewport = { width: 1360, height: 1000 }) => {
    const ctx = await browser.newContext({ viewport, locale: 'fa-IR' });
    const page = await ctx.newPage();
    for (let attempt = 0; attempt < 2; attempt++) {
      await page.goto(`${SITE}/wp-login.php?loggedout=true`, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('#user_login', { timeout: 30000 });
      await page.fill('#user_login', who.login);
      await page.fill('#user_pass', who.pass);
      await Promise.all([
        page.waitForNavigation({ timeout: 30000 }).catch(() => null),
        page.click('#wp-submit'),
      ]);
      if ((await ctx.cookies()).some((c) => c.name.startsWith('wordpress_logged_in_'))) {
        return { ctx, page };
      }
    }
    // NOT a check(): a session that will not open is a broken measurement,
    // not a finding about the plugin (`alpha.34`).
    throw new Error(`could not sign in as ${who.login}`);
  };
  const textOf = async (page) => (await page.locator('body').innerText()).replace(/ /g, ' ');

  // ---------------------------------------------------------------- 1 preview
  const mgr = await session(MANAGER);
  await mgr.page.goto(HANDOVER, { waitUntil: 'domcontentloaded' });
  let body = await textOf(mgr.page);
  let html = await mgr.page.content();
  fs.writeFileSync(`${OUT}/1-handover-before.txt`, body);

  check('1a the handover screen is the one it says it is', body.includes(T.handoverTitle), true);
  check('1b the record preview is there', body.includes(T.preview), true);
  check('1c …and says it only reads', body.includes(T.readOnly), true);
  check('1d all three kinds are named apart',
    body.includes(T.staff) && body.includes(T.balance) && body.includes(T.withdrawals), true);
  check('1e both imported shops are listed, records or not',
    body.includes(`#${toPersian(field(seeded, 'shop_a'))}`) && body.includes(`#${toPersian(field(seeded, 'shop_b'))}`),
    true);
  check('1f …and the record kinds carry the «not imported yet» badge',
    html.includes(`>${T.pending}<`), true);
  // ⛔ The sentence that sent a manager back to a finished import is gone.
  check('1g the retired empty state is NOT on the page', body.includes(T.retired), false);
  check('1h …nor its call to action', body.includes(T.retiredAction), false);
  check('1i the explicit button is offered', html.includes('value="import_records"'), true);
  check('1j …and what it will and will not do is said beside it',
    body.includes(T.historyOnly), true);

  // Drawing the page imports nothing.
  const afterLooking = state('report');
  check('1k drawing the page imported nothing',
    `${field(afterLooking, 'our_staff')}/${field(afterLooking, 'our_balance')}/${field(afterLooking, 'our_withdrawals')}`,
    '0/0/0');
  check('1l …and queued nothing', field(afterLooking, 'record_jobs'), '0');
  await mgr.page.screenshot({ path: `${OUT}/1-handover-before.png`, fullPage: true });

  // ---------------------------------------------------------------- 2 import
  await Promise.all([
    mgr.page.waitForNavigation({ timeout: 30000 }).catch(() => null),
    mgr.page.click('button[name="handover_action"][value="import_records"]'),
  ]);
  body = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/2-queued.txt`, body);
  check('2a the manager is told it was queued', body.includes(T.queued), true);
  const queued = state('report');
  check('2b …and a record job exists', field(queued, 'record_jobs'), '1');
  check('2c …and pressing the button imported nothing by itself',
    `${field(queued, 'our_staff')}/${field(queued, 'our_balance')}/${field(queued, 'our_withdrawals')}`,
    '0/0/0');
  await mgr.page.screenshot({ path: `${OUT}/2-queued.png`, fullPage: true });

  // ---------------------------------------------------------- 3 run the queue
  await mgr.page.goto(QUEUE, { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 6; i++) {
    const run = await mgr.page.$('button[name="jobs_action"][value="run_now"]');
    if (!run) break;
    await Promise.all([
      mgr.page.waitForNavigation({ timeout: 60000 }).catch(() => null),
      run.click(),
    ]);
    const now = state('report');
    if (field(now, 'our_withdrawals') !== '0') {
      // Every kind has started; one more pass finishes the last of them.
      await Promise.all([
        mgr.page.waitForNavigation({ timeout: 60000 }).catch(() => null),
        (await mgr.page.$('button[name="jobs_action"][value="run_now"]'))?.click() ?? Promise.resolve(),
      ]);
      break;
    }
  }
  const done = state('report');
  note(`after the queue: ${done.replace(/\n/g, ' · ')}`);
  check('3a the staff record arrived', field(done, 'our_staff'), '1');
  check('3b the balance rows arrived', field(done, 'our_balance'), '3');
  check('3c the withdrawals arrived', field(done, 'our_withdrawals'), '2');
  body = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/3-queue.txt`, body);
  await mgr.page.screenshot({ path: `${OUT}/3-queue.png`, fullPage: true });

  // ---------------------------------------------------------------- 4 result
  await mgr.page.goto(HANDOVER, { waitUntil: 'domcontentloaded' });
  body = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/4-handover-after.txt`, body);
  html = await mgr.page.content();
  check('4a the preview now reads «imported»', body.includes(T.imported), true);
  // ⛔ The BADGE, asked of the HTML, not the bare word asked of the text. The
  // first version used `body.includes('واردنشده')` and failed on a page that
  // was right: the «nothing left to import» notice says «سابقهٔ واردنشده‌ای
  // پیدا نشد», which contains it. The substring trap of `alpha.33`, this time
  // in my own measurement.
  check('4b …and no kind still carries the «not imported yet» badge',
    html.includes(`>${T.pending}<`), false);
  await mgr.page.screenshot({ path: `${OUT}/4-handover-after.png`, fullPage: true });

  // --------------------------------------------------- 5 where numbers are from
  await mgr.page.goto(IMPORT, { waitUntil: 'domcontentloaded' });
  html = await mgr.page.content();
  // ⛔ A closed `<details>` gives only its summary to innerText (`alpha.57`),
  // so the contents are asked of the HTML.
  check('5a the figures section is on the import page', html.includes(T.counts), true);
  check('5b …naming the catalogue-wide read', html.includes(T.catalogueWide), true);
  check('5c …beside the run manifests', html.includes(T.manifestTotal), true);
  await mgr.page.screenshot({ path: `${OUT}/5-import.png`, fullPage: true });

  // ------------------------------------------------------------- 6 mobile, axe
  const mobile = await session(MANAGER, { width: 390, height: 844 });
  await mobile.page.goto(HANDOVER, { waitUntil: 'domcontentloaded' });
  const overflow = await mobile.page.evaluate(
    () => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth),
  );
  check('6 no horizontal overflow at 390px', overflow, 0);
  await mobile.page.screenshot({ path: `${OUT}/6-handover-390.png`, fullPage: true });
  await mobile.ctx.close();

  const axeSource = fs.readFileSync(
    new URL('../../tools/browser/node_modules/axe-core/axe.min.js', import.meta.url),
    'utf8',
  );
  for (const [label, url] of [['handover', HANDOVER], ['import', IMPORT]]) {
    await mgr.page.goto(url, { waitUntil: 'domcontentloaded' });
    await mgr.page.addScriptTag({ content: axeSource });
    const result = await mgr.page.evaluate(async () => await window.axe.run(document, {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
    }));
    const serious = result.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    note(`axe ${label}: ${result.violations.length} violations, ${serious.length} serious/critical`);
    fs.writeFileSync(`${OUT}/7-axe-${label}.json`, JSON.stringify(result.violations, null, 2));
    check(`7 axe on the ${label} screen: no serious or critical violation`, serious.length, 0);
  }
  await mgr.ctx.close();
} finally {
  if (browser) await browser.close();
  // ⛔ Give back what the fixture spent, always — a fixture that leaves a
  // shop behind makes the next run's figures a statement about this one
  // (`alpha.33`).
  try {
    note(`reset: ${state('reset').replace(/\n/g, ' · ')}`);
  } catch (e) {
    note(`reset failed: ${e.message}`);
  }
}

function toPersian(value) {
  return String(value).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);
}

lines.push(` pass=${pass} fail=${fail}`);
console.log(` pass=${pass} fail=${fail}`);
fs.writeFileSync(`${OUT}/run.txt`, `${lines.join('\n')}\n`);
process.exit(fail === 0 ? 0 : 1);
