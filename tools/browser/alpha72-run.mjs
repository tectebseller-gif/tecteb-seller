/**
 * «صف و سلامت اجرا», in the state staging was in.
 *
 * `alpha.71`'s queue page said three true things that together told a manager
 * nothing: «در حال اجرا ۱», «بی‌صاحب مانده ۰», and — on pressing the only
 * button there was — «چیزی در صف نبود». It also said «آخرین اجرا: هنوز اجرا
 * نشده» after every one of those presses, because the figure was only written
 * by the cron tick. This file reads the page in exactly that state and
 * measures what it says now.
 *
 * ⛔ Each round's evidence tool has its own name (`alpha.54`). Nothing under
 * `tools/browser/alpha7[01]-run.mjs` is edited.
 *
 * The state is arranged OUTSIDE the browser, through this round's probe, so
 * every figure on the screen is compared with one the service already gave —
 * «رندر کردن یک صفحه دربارهٔ تصمیم‌هایش هیچ نمی‌گوید» (`alpha.13`).
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/alpha72-ui \
 *   WP="php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo" \
 *     node tools/browser/alpha72-run.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/alpha72-ui';
const WP = process.env.WP || 'php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo';
const WPROOT = process.env.TMC_WPROOT || '/home/user/wp-demo';
const WPCLI = process.env.TMC_WPCLI || '/usr/local/bin/wp';
const QUEUE = `${SITE}/wp-admin/admin.php?page=tmc-jobs`;
const PRODUCTS = Number(process.env.TMC_PRODUCTS || 40);

const HEADER = fs.readFileSync(new URL('../../tecteb-marketplace-core.php', import.meta.url), 'utf8');
const EXPECT = process.env.TMC_EXPECT_VERSION
  || (HEADER.match(/^\s*\*\s*Version:\s*(.+)$/m)?.[1] ?? '').trim();
const MANAGER = { login: 'tmcowner', pass: 'demo-owner-2026' };

// Persian needles written ONCE, taken from the view's own strings rather than
// retyped from the idea of them (`alpha.23`).
const T = {
  title: 'صف و سلامت اجرا',
  lastTick: 'آخرین تیکِ زمان‌بند',
  lastRun: 'آخرین اجرای یک دسته',
  manualStarter: 'اجرای دستی',
  nothing: 'چیزی در صف نبود',
  notEmpty: 'چیزی اجرا نشد، ولی صف خالی نیست',
  handOver: 'ادامه از همین نقطه',
  stopped: 'جای توقف',
  phaseProducts: 'محصولات',
  triesSuffix: 'تلاش روی همین نقطه، بدون پیشرفت',
  stuckNotice: 'از همان نقطه تکان نخورده‌اند',
  refused: 'قلم‌های ردشده',
  overdue: 'زمان «اجرای بعدی» گذشته است',
};

fs.mkdirSync(OUT, { recursive: true });
let pass = 0;
let fail = 0;
const lines = [];
const check = (name, actual, expected) => {
  const ok = String(actual) === String(expected);
  ok ? pass++ : fail++;
  const line = ok
    ? `ok:   ${name.padEnd(66)} ${expected}`
    : `FAIL: ${name.padEnd(66)} expected=${expected} actual=${actual}`;
  lines.push(line);
  console.log(line);
  return ok;
};
const note = (text) => { lines.push(`      ${text}`); console.log(`      ${text}`); };
const wp = (args) => execSync(`${WP} ${args}`, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
const probe = (cmd) => execSync(
  `TMC_WP_PATH=${WPROOT} TMC_WPCLI=${WPCLI} ${WP} eval-file ${process.cwd()}/tests/Support/alpha71-probe.php ${cmd} 2>/dev/null`,
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
  const PL = probe('plan');
  const TOTAL = Number(field(PL, 'plan_total'));
  note(`plan: ${field(PL, 'plan_vendors')} shops, ${field(PL, 'plan_products')} products, total ${TOTAL}`);
  probe('queue');
  probe('run');                                   // the vendors phase
  // …and then the state staging reported: a worker that is gone, its lease
  // still standing, three attempts, nothing recorded since.
  // ⛔ The order matters, and getting it wrong cost a browser run: `poison`
  // RUNS a batch, and a batch that lands resets `tries` and releases the job
  // — so the page would have been read in a state the fixture had just
  // undone. The stalled state is written directly instead: three attempts,
  // two of them at this very position, and a lease nobody is using.
  const DEAD = probe('dead-worker 3 240 2');
  check('the job is running, with three attempts on it', field(DEAD, 'job_attempts'), '3');
  check('…and two units recorded, as on staging', field(DEAD, 'job_done'), '2');
  check('…two of those attempts stopped at this very position',
    field(DEAD, 'dead_worker_tries'), '2');

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

  // ================================ 1. the page, in staging's own state
  const mgr = await session(MANAGER);
  await mgr.page.goto(QUEUE, { waitUntil: 'domcontentloaded' });
  let body = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/1-queue-stuck.txt`, body);

  check('1 the queue page is open', body.includes(T.title), true);
  check('1a the two run facts are separate rows', body.includes(T.lastTick) && body.includes(T.lastRun), true);
  // ⛔ From the HTML, not from `innerText`: «جای توقف» is the row's
  // `data-label`, which only becomes visible text at mobile width. Asserting
  // it against the rendered text failed about a row that was plainly there —
  // `alpha.23`'s rule, in my own file again.
  check('1b «جای توقف» labels the row that says where the job stopped',
    (await mgr.page.content()).includes(T.stopped), true);
  check('1c …naming the phase it is in', body.includes(T.phaseProducts), true);
  check('1d …and that attempts there made no progress', body.includes(T.triesSuffix), true);
  check('1e the stuck job is called out above the list', body.includes(T.stuckNotice), true);
  check('1f …and a hand-over is offered on its row', body.includes(T.handOver), true);

  // ======================= 2. the button that used to say «nothing»
  await mgr.page.click('button[value="run_now"]');
  await mgr.page.waitForLoadState('domcontentloaded');
  body = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/2-queue-run-now.txt`, body);
  check('2 the manual run does NOT claim «چیزی در صف نبود»', body.includes(T.nothing), false);
  check('2a …it says the queue is not empty and the lease is held',
    body.includes(T.notEmpty), true);
  check('2b …and points at the hand-over', body.includes(T.handOver), true);
  // THE REPORT THE OWNER READ AS A DEAD CRON: a manual run now moves this.
  check('2c «آخرین اجرای یک دسته» no longer says «هنوز اجرا نشده»',
    body.slice(body.indexOf(T.lastRun)).includes('هنوز اجرا نشده'), false);
  check('2d …and says it was started by hand',
    body.slice(body.indexOf(T.lastRun), body.indexOf(T.lastRun) + 120).includes(T.manualStarter), true);

  // ============================ 3. the hand-over actually runs a batch
  const beforeDone = Number(field(probe('state'), 'job_done'));
  await mgr.page.click('button[value="hand_over"]');
  await mgr.page.waitForLoadState('domcontentloaded');
  body = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/3-queue-handed-over.txt`, body);
  const afterState = probe('state');
  const afterDone = Number(field(afterState, 'job_done'));
  note(`done before the hand-over: ${beforeDone}, after: ${afterDone}`);
  check('3 the hand-over ran a batch', afterDone > beforeDone, true);
  check('3a …and the page reports the figure the service has',
    body.includes(`از ${fa(TOTAL)}`), true);
  check('3b …with the refused row named, not just counted',
    body.includes(T.refused) || Number(field(afterState, 'job_failed')) === 0, true);

  // ================================================ 4. mobile, and axe
  const mobile = await session(MANAGER, { width: 390, height: 844 });
  await mobile.page.goto(QUEUE, { waitUntil: 'domcontentloaded' });
  const overflow = await mobile.page.evaluate(
    () => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth),
  );
  check('4 no horizontal overflow at 390px', overflow, 0);
  await mobile.page.screenshot({ path: `${OUT}/4-queue-390.png`, fullPage: true });
  await mobile.ctx.close();

  const axeSource = fs.readFileSync(
    new URL('../../tools/browser/node_modules/axe-core/axe.min.js', import.meta.url),
    'utf8',
  );
  await mgr.page.goto(QUEUE, { waitUntil: 'domcontentloaded' });
  await mgr.page.addScriptTag({ content: axeSource });
  const result = await mgr.page.evaluate(async () => await window.axe.run(document, {
    runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
  }));
  const serious = result.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
  note(`axe queue: ${result.violations.length} violations, ${serious.length} serious/critical`);
  fs.writeFileSync(`${OUT}/5-axe-queue.json`, JSON.stringify(result.violations, null, 2));
  check('5 axe on the queue screen: no serious or critical violation', serious.length, 0);
  await mgr.page.screenshot({ path: `${OUT}/1-queue-desktop.png`, fullPage: true });
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
