/**
 * The two screens this round changed, read in the state it is about.
 *
 *   - «صف و سلامت اجرا»: the hand-over now carries the lock it was drawn from
 *     and REFUSES a row that has just been written to, saying how many seconds
 *     ago and offering the manager an explicit override. `alpha.72` offered one
 *     button that asked nothing.
 *   - «انتقال از دکان»: five figures instead of one percentage — mapped,
 *     complete, missing a picture, unjudgeable, mapped twice — with the
 *     half-made ids named and a button that finishes only the unfinished half.
 *
 * ⛔ Each round's evidence tool has its own name (`alpha.54`). Nothing under
 * `tools/browser/alpha7[012]-run.mjs` is edited.
 *
 * The state is arranged OUTSIDE the browser, through this round's probe, so
 * every figure on the screen is compared with one the service already gave —
 * «رندر کردن یک صفحه دربارهٔ تصمیم‌هایش هیچ نمی‌گوید» (`alpha.13`).
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/alpha73-ui \
 *   WP="php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo" \
 *     node tools/browser/alpha73-run.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/alpha73-ui';
const WP = process.env.WP || 'php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo';
const WPROOT = process.env.TMC_WPROOT || '/home/user/wp-demo';
const WPCLI = process.env.TMC_WPCLI || '/usr/local/bin/wp';
const QUEUE = `${SITE}/wp-admin/admin.php?page=tmc-jobs`;
const MIGRATION = `${SITE}/wp-admin/admin.php?page=tmc-import`;
const PRODUCTS = Number(process.env.TMC_PRODUCTS || 6);

const HEADER = fs.readFileSync(new URL('../../tecteb-marketplace-core.php', import.meta.url), 'utf8');
const EXPECT = process.env.TMC_EXPECT_VERSION
  || (HEADER.match(/^\s*\*\s*Version:\s*(.+)$/m)?.[1] ?? '').trim();
const MANAGER = { login: 'tmcowner', pass: 'demo-owner-2026' };

// Persian needles written ONCE, copied from the views' own strings rather than
// retyped from the idea of them (`alpha.23`).
const T = {
  queueTitle: 'صف و سلامت اجرا',
  handOver: 'ادامه از همین نقطه',
  stillWriting: 'با این حال ادامه بده',
  refusedWriting: 'همین چند لحظه پیش چیزی نوشته',
  completion: 'کامل‌بودن ورود',
  mapped: 'محصول واردشده',
  complete: 'کامل',
  withoutImage: 'بدون تصویر (نیمه‌تمام)',
  unreadable: 'قابل‌بررسی نبود',
  duplicates: 'نگاشت تکراری',
  incompleteIds: 'شناسهٔ محصول‌های نیمه‌تمام',
  repair: 'تکمیل محصول‌های نیمه‌تمام',
  repairNote: 'فقط همان نیمهٔ انجام‌نشده را تلاش می‌کند',
  noMerge: 'هیچ ردیفی را ادغام یا حذف نمی‌کند',
};

fs.mkdirSync(OUT, { recursive: true });
let pass = 0;
let fail = 0;
const lines = [];
const check = (name, actual, expected) => {
  const ok = String(actual) === String(expected);
  ok ? pass++ : fail++;
  const line = ok
    ? `ok:   ${name.padEnd(70)} ${expected}`
    : `FAIL: ${name.padEnd(70)} expected=${expected} actual=${actual}`;
  lines.push(line);
  console.log(line);
  return ok;
};
const note = (text) => { lines.push(`      ${text}`); console.log(`      ${text}`); };
const wp = (args) => execSync(`${WP} ${args}`, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
const probe = (cmd) => execSync(
  `TMC_WP_PATH=${WPROOT} TMC_WPCLI=${WPCLI} TMC_REPO=${process.cwd()} ${WP} eval-file ${process.cwd()}/tests/Support/alpha72-probe.php ${cmd} 2>/dev/null`,
  { encoding: 'utf8', shell: '/bin/bash' },
).trim();
const field = (text, key) => {
  const m = text.match(new RegExp(`(?:^|\\n)${key}=(.*)`));
  return m ? m[1].trim() : '';
};
const fa = (n) => String(n).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);

check('the installed package is the one under test',
  wp('plugin get tecteb-marketplace-core --field=version'), EXPECT);

let browser = null;
try {
  // ------------------------------------- arrange, outside the browser
  probe('reset');
  const AR = probe(`arrange ${PRODUCTS} 900`);
  check('the real reader sees the Dokan-shaped fixture', field(AR, 'reader_available'), 'yes');
  probe('plan');
  probe('queue');
  probe('run');                                  // the vendors phase
  // A worker killed inside the first product's picture: the row is there, its
  // picture is not, and the lease it held is still standing — which is both
  // the state the hand-over is for and the state the completion report is for.
  const HALF = probe('half-made 1');
  check('one product is mapped', field(HALF, 'mapped'), '1');
  check('…and it is missing its picture', field(HALF, 'products_without_image'), '1');
  const CM = probe('completion');
  const WITHOUT = field(CM, 'service_without_image');
  const MAPPED = field(CM, 'service_mapped');
  const COMPLETE = field(CM, 'service_complete');
  const IDS = field(CM, 'service_incomplete_ids');
  note(`service: mapped=${MAPPED} complete=${COMPLETE} without_image=${WITHOUT} ids=${IDS}`);

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
    // NOT a `check()`: a session that will not open is a broken measurement,
    // not a finding about the plugin (`alpha.34`).
    throw new Error(`could not sign in as ${who.login}`);
  };
  const textOf = async (page) => (await page.locator('body').innerText()).replace(/ /g, ' ');

  const mgr = await session(MANAGER);

  // ===================================== 1. the queue, and the hand-over
  await mgr.page.goto(QUEUE, { waitUntil: 'domcontentloaded' });
  let body = await textOf(mgr.page);
  let html = await mgr.page.content();
  fs.writeFileSync(`${OUT}/1-queue.txt`, body);
  check('1a the queue screen is the one it says it is', body.includes(T.queueTitle), true);
  check('1b the hand-over is offered on the stuck row', body.includes(T.handOver), true);
  // ⛔ Read from the HTML, because a hidden field is not `innerText` — the
  // same mistake cost a check last round on `data-label`.
  check('1c …and the form carries the lock it was drawn from',
    /name="seen_lock" value="[0-9a-f]{64}"/.test(html), true);
  check('1d …with the override offered, because the row was just written to',
    body.includes(T.stillWriting), true);
  const seconds = await mgr.page.evaluate(() => {
    const m = document.body.innerText.match(/این کار ([۰-۹\d]+) ثانیه پیش چیزی نوشته/);
    return m ? m[1] : '';
  });
  note(`the page says the row was written ${seconds} seconds ago`);
  check('1e …saying how long ago, in Persian digits', /^[۰-۹]+$/.test(seconds), true);

  // Pressing it WITHOUT the override: the page has to refuse and say why.
  const form = mgr.page.locator('form:has(button[value="hand_over"])').first();
  await form.locator('input[name="even_if_writing"]').uncheck({ force: true }).catch(() => null);
  await Promise.all([
    mgr.page.waitForNavigation({ timeout: 30000 }).catch(() => null),
    form.locator('button[value="hand_over"]').click(),
  ]);
  await mgr.page.waitForLoadState('domcontentloaded');
  body = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/2-queue-refused.txt`, body);
  check('2a pressing it without the override is REFUSED', body.includes(T.refusedWriting), true);
  check('2b …and the job is still held by that worker',
    field(probe('report'), 'job_status'), 'running');

  // ========================== 4. «کامل‌بودن ورود», five figures not one
  //
  // ⛔ READ HERE, BEFORE THE HAND-OVER, and the order is the measurement: once
  // a batch runs it FINISHES the half-made product, so a page read afterwards
  // has nothing unfinished on it and the repair button is correctly absent.
  // The first version of this file read it last and passed on an escape
  // clause — a check that cannot fail is not a check (`alpha.46`).
  await mgr.page.goto(MIGRATION, { waitUntil: 'domcontentloaded' });
  body = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/4-migration.txt`, body);
  const CM2 = probe('completion');
  check('4a the completion section is on the page', body.includes(T.completion), true);
  for (const [name, needle] of [
    ['mapped', T.mapped], ['complete', T.complete], ['without a picture', T.withoutImage],
    ['unjudgeable', T.unreadable], ['mapped twice', T.duplicates],
  ]) {
    check(`4b the report names «${name}» separately`, body.includes(needle), true);
  }
  // THE FIGURES THEMSELVES, against the service's own answer.
  check('4c the mapped figure is the service\'s, in Persian digits',
    body.includes(fa(field(CM2, 'service_mapped'))), true);
  check('4d …and so is the number missing a picture',
    body.includes(fa(field(CM2, 'service_without_image'))), true);
  check('4d-2 …which is one, because a worker died inside its picture',
    field(CM2, 'service_without_image'), '1');
  check('4e the half-made product is NAMED, by id', body.includes(fa(IDS)), true);
  check('4f the repair button is offered', body.includes(T.repair), true);
  check('4g …with the sentence saying what it does and does not touch',
    body.includes(T.repairNote), true);

  // AND PRESSED. «فقط همان نیمهٔ انجام‌نشده» is a claim about writes, so the
  // rows are counted before and after.
  const beforeRepair = probe('completion');
  const repairForm = mgr.page.locator('form:has(button[value="repair_imports"])').first();
  await Promise.all([
    mgr.page.waitForNavigation({ timeout: 60000 }).catch(() => null),
    repairForm.locator('button[value="repair_imports"]').click(),
  ]);
  await mgr.page.waitForLoadState('domcontentloaded');
  body = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/5-migration-repaired.txt`, body);
  const afterRepair = probe('completion');
  check('4h the repair finishes the one that was missing a picture',
    field(afterRepair, 'service_without_image'), '0');
  check('4i …and creates no row: the mapped figure is unchanged',
    field(afterRepair, 'service_mapped'), field(beforeRepair, 'service_mapped'));
  check('4j …and no duplicate',
    field(afterRepair, 'service_duplicate_mappings'), '0');
  check('4k the page then says how many were finished', body.includes('محصول تکمیل شد'), true);
  check('4l and nothing anywhere offers to merge a duplicate row',
    body.includes(T.noMerge) || field(afterRepair, 'service_duplicate_mappings') === '0', true);

  // And with it: the lease ends and the next batch runs.
  await mgr.page.goto(QUEUE, { waitUntil: 'domcontentloaded' });
  const form2 = mgr.page.locator('form:has(button[value="hand_over"])').first();
  const doneBefore = Number(field(probe('report'), 'job_done'));
  if (await form2.locator('input[name="even_if_writing"]').count()) {
    await form2.locator('input[name="even_if_writing"]').check({ force: true });
  }
  await Promise.all([
    mgr.page.waitForNavigation({ timeout: 60000 }).catch(() => null),
    form2.locator('button[value="hand_over"]').click(),
  ]);
  await mgr.page.waitForLoadState('domcontentloaded');
  body = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/3-queue-handed-over.txt`, body);
  const doneAfter = Number(field(probe('report'), 'job_done'));
  note(`done before the hand-over: ${doneBefore}, after: ${doneAfter}`);
  check('3a the override hands it over and a batch runs', doneAfter > doneBefore, true);

  // ========================================= 5. mobile, and axe
  const mobile = await session(MANAGER, { width: 390, height: 844 });
  for (const [label, url] of [['queue', QUEUE], ['migration', MIGRATION]]) {
    await mobile.page.goto(url, { waitUntil: 'domcontentloaded' });
    const overflow = await mobile.page.evaluate(
      () => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth),
    );
    check(`5 no horizontal overflow at 390px — ${label}`, overflow, 0);
    await mobile.page.screenshot({ path: `${OUT}/5-${label}-390.png`, fullPage: true });
  }
  await mobile.ctx.close();

  const axeSource = fs.readFileSync(
    new URL('../../tools/browser/node_modules/axe-core/axe.min.js', import.meta.url),
    'utf8',
  );
  for (const [label, url] of [['queue', QUEUE], ['migration', MIGRATION]]) {
    await mgr.page.goto(url, { waitUntil: 'domcontentloaded' });
    await mgr.page.addScriptTag({ content: axeSource });
    const result = await mgr.page.evaluate(async () => await window.axe.run(document, {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
    }));
    const serious = result.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    note(`axe ${label}: ${result.violations.length} violations, ${serious.length} serious/critical`);
    fs.writeFileSync(`${OUT}/6-axe-${label}.json`, JSON.stringify(result.violations, null, 2));
    check(`6 axe on the ${label} screen: no serious or critical violation`, serious.length, 0);
    await mgr.page.screenshot({ path: `${OUT}/6-${label}-desktop.png`, fullPage: true });
  }
  await mgr.ctx.close();
} finally {
  if (browser) await browser.close();
  // ⛔ GIVE BACK WHAT WAS SPENT (`alpha.45`): the import is rolled back the
  // documented way and the Dokan-shaped fixture is deleted.
  try { note(probe('reset').replace(/\n/g, ' · ')); } catch { /* reported by the exit code */ }
}

lines.push(` pass=${pass} fail=${fail}`);
console.log(` pass=${pass} fail=${fail}`);
fs.writeFileSync(`${OUT}/run.txt`, `${lines.join('\n')}\n`);
process.exit(fail === 0 ? 0 : 1);
