/**
 * THE CHANGED MONEY SCREENS, IN A REAL BROWSER — §4 of the brief.
 *
 * Two screens changed this round and both are about money, so both are
 * pressed rather than read: the returns queue (a refusal became a note, and
 * the two buttons now go through) and the Min Max exit page (a verdict card
 * that did not exist). The reason a browser is here at all is on the record:
 * `alpha.48` shipped a page whose every suite was green and whose only POST
 * branch called a method `Request` does not have, and only a browser found
 * it.
 *
 * What is asserted is what only a request can answer — what the page draws,
 * what a click does, and what the row says AFTERWARDS — and the figures are
 * read back from the DATABASE as well as from the screen, so «the page shows
 * ۳۳٫۶۶۶۶» is checked against the row rather than against another part of
 * the same page (`alpha.26`).
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/alpha69-ui \
 *   WP="php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo" \
 *     node tools/browser/alpha69-run.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/alpha69-ui';
const WP = process.env.WP || 'php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo';
const WPROOT = process.env.TMC_WPROOT || '/home/user/wp-demo';
const RETURNS = `${SITE}/wp-admin/admin.php?page=tmc-returns`;
const MINMAX = `${SITE}/wp-admin/admin.php?page=tmc-minmax-exit`;
const HEALTH = `${SITE}/wp-admin/admin.php?page=tmc-health`;

const HEADER = fs.readFileSync(new URL('../../tecteb-marketplace-core.php', import.meta.url), 'utf8');
const EXPECT = process.env.TMC_EXPECT_VERSION
  || (HEADER.match(/^\s*\*\s*Version:\s*(.+)$/m)?.[1] ?? '').trim();
const MANAGER = { login: 'tmcowner', pass: 'demo-owner-2026' };

// Persian needles written ONCE and compared with `includes` (`alpha.23`).
const T = {
  // The returns screen: the note that replaced a refusal.
  // Written ONCE and compared with `includes` (`alpha.23`) — and taken from
  // the sentence the view actually prints, not retyped from the idea of it:
  // the first two needles here were a paraphrase and failed against a page
  // that was perfectly correct.
  noteFloor: 'در ریزترین واحدِ همین قلم حساب می‌شود',
  noteLast: 'مرجوعیِ این قلم می‌رسد تا جمع کل دقیقاً برابر مبلغ قلم بماند',
  noteNotRefused: 'این مرجوعی رد نمی‌شود',
  oldRefusal: 'این مرجوعی دقیق تقسیم نمی‌شود',
  receiveButton: 'ثبت تحویل کالا',
  refundButton: 'ثبت بازگشت مالی',
  refundedDone: 'بازپرداخت انجام شده',
  // The Min Max exit verdict card.
  verdictTitle: 'اجازهٔ غیرفعال‌کردن Min Max',
  verdictBlocked: 'غیرفعال‌کردن Min Max مجاز نیست',
  countUnknown: 'نامعلوم',
  countIncompatible: 'ناسازگار',
  countMigratedUnenforced: 'منتقل‌شده و اعمال‌نشده',
  noLoosening: 'با سست‌کردن قاعده برطرف نمی‌شود',
  // The health page's corrected rollback sentence.
  healthRestore: 'بازگرداندن پشتیبان کل دیتابیس را برمی‌گرداند',
  healthClause: 'بند ۴۹',
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
const probe = (cmd, local = true) => {
  execSync(`cp tests/Support/alpha68-probe.php ${WPROOT}/`, { stdio: 'ignore' });
  const env = local ? `--exec='define("WP_ENVIRONMENT_TYPE","local");'` : '';
  return execSync(
    `cd ${WPROOT} && ${WP} ${env} eval-file ${WPROOT}/alpha68-probe.php ${cmd} 2>/dev/null`,
    { encoding: 'utf8', shell: '/bin/bash' },
  ).trim();
};
const field = (text, key) => {
  const m = text.match(new RegExp(`(?:^|\\n)${key}=(.*)`));
  return m ? m[1].trim() : '';
};
const row = (id, column) => wp(
  `db query "SELECT ${column} FROM wp_tmc_returns WHERE id = ${id}" --skip-column-names`,
).trim();

check('the installed package is the one under test',
  wp('plugin get tecteb-marketplace-core --field=version'), EXPECT);
const SCHEMA = (fs.readFileSync(
  new URL('../../src/Core/Migration/SchemaVersion.php', import.meta.url),
  'utf8',
).match(/TARGET\s*=\s*(\d+)/)?.[1] ?? '');
check('and the structure is the one it targets', wp('option get tmc_schema_version'), SCHEMA);

// --------------------------------------------------- arrange, outside the browser
probe('trial-on');
const ARRANGED = probe('split-arrange');
note(ARRANGED.replace(/\n/g, ' · '));
const ITEM = field(ARRANGED, 'item_id');
if (!ITEM || ITEM === '__none__') {
  console.log('could not arrange the ۱۰۱-تومان line — every later assertion would be void');
  probe('trial-off');
  process.exit(2);
}
const HELD = probe('split-hold');
note(HELD.replace(/\n/g, ' · '));
const RETURN_ID = field(HELD, 'hold_return_id');
check('a return of one of three is waiting, approved', field(HELD, 'hold_status'), 'approved');

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
async function session(who, viewport = { width: 1360, height: 1000 }) {
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
}
const textOf = async (page) => (await page.locator('body').innerText()).replace(/ /g, ' ');

// ======================================= 1. the returns queue, and the note
const mgr = await session(MANAGER);
await mgr.page.goto(RETURNS, { waitUntil: 'domcontentloaded' });
let body = await textOf(mgr.page);
fs.writeFileSync(`${OUT}/1-returns-note.txt`, body);
check('1 the returns queue opens for the manager',
  await mgr.page.locator('table.tmc-table').count() > 0, true);
check('1a the uneven split is said as a NOTE, before the goods arrive',
  body.includes(T.noteFloor), true);
check('1b …naming where the remainder goes', body.includes(T.noteLast), true);
check('1c …and saying out loud that nothing is refused',
  body.includes(T.noteNotRefused), true);
// ⛔ THE SENTENCE THAT MUST BE GONE. A page that still carries `alpha.68`'s
// refusal would be telling a manager the opposite of what the code now does.
check('1d the old refusal sentence is gone', body.includes(T.oldRefusal), false);
check('1e the «goods received» button is there',
  await mgr.page.locator(`button:has-text("${T.receiveButton}")`).count() > 0, true);

// ========================================== 2. press both buttons, in order
const form = mgr.page.locator(`form:has(input[name="return_id"][value="${RETURN_ID}"])`).first();
check('2 the waiting return has its own form on the page', await form.count(), 1);
await Promise.all([
  mgr.page.waitForNavigation({ timeout: 30000 }).catch(() => null),
  form.locator(`button:has-text("${T.receiveButton}")`).first().click(),
]);
body = await textOf(mgr.page);
fs.writeFileSync(`${OUT}/2-received.txt`, body);
check('2a accepting the goods goes through', row(RETURN_ID, 'status'), 'received');

const form2 = mgr.page.locator(`form:has(input[name="return_id"][value="${RETURN_ID}"])`).first();
await Promise.all([
  mgr.page.waitForNavigation({ timeout: 30000 }).catch(() => null),
  form2.locator(`button:has-text("${T.refundButton}")`).first().click(),
]);
body = await textOf(mgr.page);
fs.writeFileSync(`${OUT}/3-refunded.txt`, body);
check('2b and the money is recorded', row(RETURN_ID, 'status'), 'refunded');
// The figures, from the ROW — the owner's own example, floored in the line's
// own smallest unit.
check('2c the base came back floored, not rounded', row(RETURN_ID, 'refund_minor'), '336666');
check('2d the commission the same way', row(RETURN_ID, 'commission_reversed_minor'), '42083');
check('2e the share derived from the two', row(RETURN_ID, 'vendor_share_reversed_minor'), '294583');
// …and the SCREEN says the same number, at the row's own scale.
check('2f the screen prints the same figure in Persian digits',
  body.includes('۳۳٫۶۶۶۶'), true);
check('2g …and the commission under it', body.includes('۴٫۲۰۸۳'), true);
check('2h a refunded return offers no further action',
  body.includes(T.refundedDone), true);

// ============================================ 3. the Min Max exit verdict
await mgr.page.goto(MINMAX, { waitUntil: 'domcontentloaded' });
body = await textOf(mgr.page);
fs.writeFileSync(`${OUT}/4-minmax-verdict.txt`, body);
check('3 the verdict card is the first thing on the page',
  body.includes(T.verdictTitle), true);
const verdict = field(probe('rollback-hazard'), 'has_rollback_gate');
note(`rollback gate present: ${verdict}`);
check('3a this environment is BLOCKED, and the page says so',
  body.includes(T.verdictBlocked), true);
check('3b the four counts are named apart — unknown among them',
  body.includes(T.countUnknown), true);
check('3c …incompatible', body.includes(T.countIncompatible), true);
check('3d …migrated and unenforced', body.includes(T.countMigratedUnenforced), true);
check('3e and the page says a rule is not loosened to reach a yes',
  body.includes(T.noLoosening), true);
check('3f at least one blocker is named with its code',
  /rule_would_not_be_enforced|migrated_but_not_enforced|convertible_rules_not_migrated/.test(body), true);

// ================================== 4. the health page's rollback sentence
await mgr.page.goto(HEALTH, { waitUntil: 'domcontentloaded' });
body = await textOf(mgr.page);
fs.writeFileSync(`${OUT}/5-health.txt`, body);
check('4 the health page says what restoring a backup really costs',
  body.includes(T.healthRestore), true);
check('4a …and points at the clause that lists it', body.includes(T.healthClause), true);

// ===================================================== 5. mobile, and axe
const mobile = await session(MANAGER, { width: 390, height: 844 });
for (const [label, url] of [['returns', RETURNS], ['minmax', MINMAX]]) {
  await mobile.page.goto(url, { waitUntil: 'domcontentloaded' });
  const overflow = await mobile.page.evaluate(
    () => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth),
  );
  check(`5 ${label}: no horizontal overflow at 390px`, overflow, 0);
  await mobile.page.screenshot({ path: `${OUT}/mobile-${label}.png`, fullPage: true });
}
await mobile.ctx.close();

const axeSource = fs.readFileSync(
  new URL('../../tools/browser/node_modules/axe-core/axe.min.js', import.meta.url),
  'utf8',
);
const axeRun = async (scope) => {
  await mgr.page.addScriptTag({ content: axeSource });
  return mgr.page.evaluate(async (sel) => await window.axe.run(
    sel ? { include: [[sel]] } : document,
    { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] } },
  ), scope);
};
for (const [label, url] of [['returns', RETURNS], ['minmax', MINMAX]]) {
  await mgr.page.goto(url, { waitUntil: 'domcontentloaded' });
  const result = await axeRun(null);
  const serious = result.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
  note(`axe ${label}: ${result.violations.length} violations, ${serious.length} serious/critical`);
  for (const v of serious) note(`  ${v.id}: ${v.nodes.length} node(s)`);
  check(`6 axe on the ${label} screen: no serious or critical violation`, serious.length, 0);
  fs.writeFileSync(`${OUT}/axe-${label}.json`, JSON.stringify(result.violations, null, 2));
}

await mgr.page.screenshot({ path: `${OUT}/desktop-minmax.png`, fullPage: true });
await mgr.ctx.close();
await browser.close();

// ------------------------------------------------------------- give it back
const RESET = probe('reset');
note(RESET.replace(/\n/g, ' · '));
probe('trial-off');

lines.push('', `pass=${pass} fail=${fail}`);
console.log('', `pass=${pass} fail=${fail}`);
fs.writeFileSync(`${OUT}/run.txt`, lines.join('\n'));
process.exit(fail === 0 ? 0 : 1);
