/**
 * THE EXIT VERDICT CARD, IN A REAL BROWSER — what this round changed.
 *
 * `alpha69-run.mjs` is run unchanged as the regression: it asserts the card
 * this round did not touch. What is asserted HERE is only what moved — the
 * four counts a decided product is taken apart into, the two live recorded
 * states that `alpha.69` could not see, the paginated grant scan's own
 * accounting, and the sentence that says a migration record is not a present
 * equality.
 *
 * The reason a browser is here at all is on the record: `alpha.48` shipped a
 * page whose every suite was green and whose only POST branch called a method
 * `Request` does not have, and only a browser found it.
 *
 * The state is arranged OUTSIDE the browser, through this round's probe, and
 * the figures are read back from the probe as well as from the screen — so
 * «the page shows ۱» is checked against the verdict rather than against
 * another part of the same page (`alpha.26`).
 *
 * ⛔ And it gives back everything it spends: the probe's `reset`,
 * `grants-reset` and `coverage-restore` run in a `finally`.
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/alpha70-ui \
 *   WP="php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo" \
 *     node tools/browser/alpha70-run.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/alpha70-ui';
const WP = process.env.WP || 'php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo';
const WPROOT = process.env.TMC_WPROOT || '/home/user/wp-demo';
const MINMAX = `${SITE}/wp-admin/admin.php?page=tmc-minmax-exit`;

const HEADER = fs.readFileSync(new URL('../../tecteb-marketplace-core.php', import.meta.url), 'utf8');
const EXPECT = process.env.TMC_EXPECT_VERSION
  || (HEADER.match(/^\s*\*\s*Version:\s*(.+)$/m)?.[1] ?? '').trim();
const MANAGER = { login: 'tmcowner', pass: 'demo-owner-2026' };

// Persian needles written ONCE and compared with `includes` (`alpha.23`), and
// taken from the view's own strings rather than retyped from the idea of
// them — two of `alpha.69`'s first needles were a paraphrase and failed
// against a page that was perfectly correct.
const T = {
  verdictTitle: 'اجازهٔ غیرفعال‌کردن Min Max',
  verdictBlocked: 'غیرفعال‌کردن Min Max مجاز نیست',
  inForce: 'منتقل‌شده، برابر و اعمال‌شونده',
  drifted: 'منتقل‌شده ولی قاعده عوض شده',
  unenforced: 'منتقل‌شده ولی دیگر اعمال نمی‌شود',
  unreadable: 'مقصدِ تصمیم‌گرفته‌شده خوانده نشد',
  refusedRecorded: 'ردِ ثبت‌شدهٔ زنده',
  heldRecorded: 'در اختیار مدیر، ثبت‌شدهٔ زنده',
  grantsScanned: 'مجوزهای بررسی‌شده',
  notAProof: 'سابقهٔ انتقال «برابربودنِ امروز» را ثابت نمی‌کند',
  scanPaginated: 'بررسی مجوزهای اعمال صفحه‌بندی‌شده است',
  scanReachedEnd: '«به آخر رسید»',
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
const probe = (cmd) => {
  execSync(`cp tests/Support/alpha69-probe.php ${WPROOT}/`, { stdio: 'ignore' });
  return execSync(
    `cd ${WPROOT} && ${WP} eval-file ${WPROOT}/alpha69-probe.php ${cmd} 2>/dev/null`,
    { encoding: 'utf8', shell: '/bin/bash' },
  ).trim();
};
const field = (text, key) => {
  const m = text.match(new RegExp(`(?:^|\\n)${key}=(.*)`));
  return m ? m[1].trim() : '';
};
// The page prints Persian digits, so a figure read from the screen has to be
// compared in the same alphabet the page uses.
const fa = (n) => String(n).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);

check('the installed package is the one under test',
  wp('plugin get tecteb-marketplace-core --field=version'), EXPECT);

let browser = null;
try {
  // ------------------------------------------ arrange, outside the browser
  const ARRANGED = probe('exit-arrange');
  note(ARRANGED.replace(/\n/g, ' · '));
  check('a convertible rule was taken, so one product is in force',
    field(ARRANGED, 'ok_migrated'), 'migrated');
  // A LIVE refusal and a LIVE hold — the two states `alpha.69` scored as
  // «already decided» and dropped.
  const BOTH = probe('exit-both');
  note(BOTH.replace(/\n/g, ' · '));
  check('a live refusal stands', field(BOTH, 'step_status'), 'refused');
  check('and a live hold beside it', field(BOTH, 'held_status'), 'held_by_manager');
  check('the verdict refuses the exit', field(BOTH, 'both_verdict'), 'blocked');
  const REFUSED = field(BOTH, 'both_count_refused_recorded');
  const HELDN = field(BOTH, 'both_count_held_recorded');
  const INFORCE = field(BOTH, 'both_count_decided_in_force');
  const SCANNED = field(BOTH, 'both_count_grants_scanned');

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

  // =================================== 1. the card, with the new counts
  const mgr = await session(MANAGER);
  await mgr.page.goto(MINMAX, { waitUntil: 'domcontentloaded' });
  const body = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/1-minmax-verdict.txt`, body);
  check('1 the verdict card is on the page', body.includes(T.verdictTitle), true);
  check('1a and it refuses, the way the probe said', body.includes(T.verdictBlocked), true);

  check('1b the decided products are taken apart — in force',
    body.includes(T.inForce), true);
  check('1c …drifted', body.includes(T.drifted), true);
  check('1d …no longer enforced', body.includes(T.unenforced), true);
  check('1e …destination unreadable', body.includes(T.unreadable), true);
  check('1f the live recorded refusal has a row of its own',
    body.includes(T.refusedRecorded), true);
  check('1g …and the live recorded hold', body.includes(T.heldRecorded), true);

  // The FIGURES, read off the screen and compared with the verdict the probe
  // read from the service — not with another part of this page.
  const figureAfter = (label) => {
    const idx = body.indexOf(label);
    if (idx < 0) return '__absent__';
    return (body.slice(idx + label.length).match(/[۰-۹]+/) ?? ['__none__'])[0];
  };
  check('1h the recorded refusal is printed as one', figureAfter(T.refusedRecorded), fa(REFUSED));
  check('1i the recorded hold too', figureAfter(T.heldRecorded), fa(HELDN));
  check('1j and the product in force', figureAfter(T.inForce), fa(INFORCE));

  check('1k the page says a migration record is not a present equality',
    body.includes(T.notAProof), true);
  check('1l …and that the grant scan is paginated', body.includes(T.scanPaginated), true);
  check('1m …and whether it reached the end', body.includes(T.scanReachedEnd), true);
  check('1n the blockers are named with their codes',
    body.includes('incompatible_rules_present'), true);
  check('1o …including the hold', body.includes('rules_held_by_manager'), true);

  // ======================== 2. the grant scan, with more than one page
  const GA = probe('grants-arrange 55');
  note(GA.replace(/\n/g, ' · '));
  probe('grants-migrate');
  const GS = probe('grants-stale');
  note(GS.replace(/\n/g, ' · '));
  check('2 the drifted grant is the fifty-fifth in ledger order',
    field(GS, 'target_position_in_ledger_order'), '55');
  check('2a the default call now finds it', field(GS, 'stale_grants_default_limit'), '1');
  check('2b the scan saw every row the ledger counts', field(GS, 'scan_finished'), 'yes');
  await mgr.page.goto(MINMAX, { waitUntil: 'domcontentloaded' });
  const body2 = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/2-minmax-grants.txt`, body2);
  check('2c the page counts the grants it examined',
    body2.includes(T.grantsScanned), true);
  const examined = (() => {
    const idx = body2.indexOf(T.grantsScanned);
    return idx < 0 ? '__absent__' : (body2.slice(idx + T.grantsScanned.length).match(/[۰-۹]+/) ?? ['__none__'])[0];
  })();
  check('2d …and the figure is the one the scan reports', examined, fa(field(GS, 'scan_scanned')));
  check('2e the stale grant is named as a blocker',
    body2.includes('stale_enforcement_grants'), true);

  // =========================================== 3. mobile, and axe
  const mobile = await session(MANAGER, { width: 390, height: 844 });
  await mobile.page.goto(MINMAX, { waitUntil: 'domcontentloaded' });
  const overflow = await mobile.page.evaluate(
    () => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth),
  );
  check('3 no horizontal overflow at 390px', overflow, 0);
  await mobile.page.screenshot({ path: `${OUT}/3-minmax-390.png`, fullPage: true });
  await mobile.ctx.close();

  const axeSource = fs.readFileSync(
    new URL('../../tools/browser/node_modules/axe-core/axe.min.js', import.meta.url),
    'utf8',
  );
  await mgr.page.addScriptTag({ content: axeSource });
  const result = await mgr.page.evaluate(async () => await window.axe.run(document, {
    runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
  }));
  const serious = result.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
  note(`axe minmax: ${result.violations.length} violations, ${serious.length} serious/critical`);
  check('4 axe on the exit screen: no serious or critical violation', serious.length, 0);
  fs.writeFileSync(`${OUT}/axe-minmax-alpha70.json`, JSON.stringify(result.violations, null, 2));
  await mgr.ctx.close();
} finally {
  if (browser) {
    await browser.close();
  }
  // ⛔ EVERYTHING THIS SPENDS GOES BACK (`alpha.45`), including on a crash.
  try {
    note(probe('grants-reset').replace(/\n/g, ' · '));
    note(probe('coverage-restore').replace(/\n/g, ' · '));
    note(probe('reset').replace(/\n/g, ' · '));
  } catch (e) {
    note(`reset failed: ${e.message}`);
    fail++;
  }
  const summary = ` pass=${pass} fail=${fail}`;
  lines.push(summary);
  console.log(summary);
  fs.writeFileSync(`${OUT}/run-alpha70.txt`, `${lines.join('\n')}\n`);
}
process.exit(fail === 0 ? 0 : 1);
