/**
 * The exit card, with this round's two additions on it.
 *
 * `alpha70-run.mjs` already measures the card, the counts `alpha.70` added,
 * the fifty-five grants, 390px and axe — and it was re-run on this tree as a
 * regression (29 checks, 0 failures). What it cannot know about is the row
 * and the sentence THIS round adds, so this file measures those, and nothing
 * else: a browser suite that re-measures last round's page tells you about
 * last round.
 *
 * ⛔ Each round's evidence tool has its own name (`alpha.54`). `alpha70-run.mjs`
 * is not edited.
 *
 * The state is arranged OUTSIDE the browser, through this round's probe, so
 * the page is read in a state whose numbers are already known from the
 * service — «رندر کردن یک صفحه دربارهٔ تصمیم‌هایش هیچ نمی‌گوید» (`alpha.13`).
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/alpha71-ui \
 *   WP="php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo" \
 *     node tools/browser/alpha71-run.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/alpha71-ui';
const WP = process.env.WP || 'php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo';
const WPROOT = process.env.TMC_WPROOT || '/home/user/wp-demo';
const MINMAX = `${SITE}/wp-admin/admin.php?page=tmc-minmax-exit`;

const HEADER = fs.readFileSync(new URL('../../tecteb-marketplace-core.php', import.meta.url), 'utf8');
const EXPECT = process.env.TMC_EXPECT_VERSION
  || (HEADER.match(/^\s*\*\s*Version:\s*(.+)$/m)?.[1] ?? '').trim();
const MANAGER = { login: 'tmcowner', pass: 'demo-owner-2026' };

// Persian needles written ONCE and taken from the view's own strings rather
// than retyped from the idea of them (`alpha.23`).
const T = {
  verdictTitle: 'اجازهٔ غیرفعال‌کردن Min Max',
  verdictBlocked: 'غیرفعال‌کردن Min Max مجاز نیست',
  unprovable: 'برابری قاعده اثبات‌پذیر نیست',
  decidedNotSampled: 'برابری از حداقل، حداکثر، مبنای گام و گام به‌صورت کامل تعیین می‌شود',
  notSamples: 'نه با آزمودن چند تعداد نمونه',
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
  execSync(`cp tests/Support/alpha70-probe.php ${WPROOT}/`, { stdio: 'ignore' });
  return execSync(
    `cd ${WPROOT} && ${WP} eval-file ${WPROOT}/alpha70-probe.php ${cmd} 2>/dev/null`,
    { encoding: 'utf8', shell: '/bin/bash' },
  ).trim();
};
const field = (text, key) => {
  const m = text.match(new RegExp(`(?:^|\\n)${key}=(.*)`));
  return m ? m[1].trim() : '';
};
// The page prints Persian digits, so a figure read off the screen is compared
// in the same alphabet the page uses.
const fa = (n) => String(n).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);

check('the installed package is the one under test',
  wp('plugin get tecteb-marketplace-core --field=version'), EXPECT);

let browser = null;
try {
  // ---------------------------------------- arrange, outside the browser
  const ARRANGED = probe('bound-arrange');
  note(ARRANGED.replace(/\n/g, ' · '));
  check('the fixture has one product and the site\'s other rules stashed',
    field(ARRANGED, 'arranged'), 'yes');

  // THE CASE THE OWNER NAMED: a ceiling that differs only above sixty.
  const CEIL = probe('bound-ceiling');
  note(CEIL.replace(/\n/g, ' · '));
  check('the two sides agree about the first sixty quantities',
    field(CEIL, 'ceiling_same_to_60'), 'yes');
  check('…and still do not allow the same quantities',
    field(CEIL, 'ceiling_same_to_bound'), 'no');
  check('so the service refuses the exit', field(CEIL, 'ceiling_verdict'), 'blocked');
  const DRIFTED = field(CEIL, 'ceiling_count_decided_drifted');

  // AND the «not provable» state, which is the row this round adds.
  const UNPROV = probe('bound-unprovable');
  note(UNPROV.replace(/\n/g, ' · '));
  check('a quantity no column can hold is «not provable»',
    field(UNPROV, 'unprovable_count_decided_unprovable'), '1');
  check('…and the service refuses', field(UNPROV, 'unprovable_verdict'), 'blocked');
  const UNPROVN = field(UNPROV, 'unprovable_count_decided_unprovable');

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

  // ======================================= 1. the card, with the new row
  const mgr = await session(MANAGER);
  await mgr.page.goto(MINMAX, { waitUntil: 'domcontentloaded' });
  const body = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/1-minmax-verdict.txt`, body);

  check('1 the verdict card is on the page', body.includes(T.verdictTitle), true);
  check('1a and it refuses, the way the service said', body.includes(T.verdictBlocked), true);
  check('1b the new row is on the card', body.includes(T.unprovable), true);

  // The FIGURE, read off the screen and compared with what the service said —
  // never with another part of this page.
  const figureAfter = (label) => {
    const idx = body.indexOf(label);
    if (idx < 0) return '__absent__';
    return (body.slice(idx + label.length).match(/[۰-۹]+/) ?? ['__none__'])[0];
  };
  check('1c …with the figure the service reports', figureAfter(T.unprovable), fa(UNPROVN));
  check('1d the blocker is named by its code',
    body.includes('migrated_rule_equality_unprovable'), true);

  check('1e the page says equality is decided from the four values',
    body.includes(T.decidedNotSampled), true);
  check('1f …and that it is not a matter of sampling quantities',
    body.includes(T.notSamples), true);

  // The drifted case the owner named, read on the same page.
  const CEIL2 = probe('bound-ceiling');
  note(CEIL2.replace(/\n/g, ' · '));
  await mgr.page.goto(MINMAX, { waitUntil: 'domcontentloaded' });
  const body2 = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/2-minmax-drifted.txt`, body2);
  check('2 the ceiling case is counted as drifted on the page',
    figureAfterIn(body2, 'منتقل‌شده ولی قاعده عوض شده'), fa(DRIFTED));
  check('2a …and named as a blocker', body2.includes('migrated_rule_differs_now'), true);

  // ================================================ 3. mobile, and axe
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
  fs.writeFileSync(`${OUT}/4-axe-minmax.json`, JSON.stringify(result.violations, null, 2));
  check('4 axe on the exit screen: no serious or critical violation', serious.length, 0);
  await mgr.page.screenshot({ path: `${OUT}/1-minmax-desktop.png`, fullPage: true });
  await mgr.ctx.close();
} finally {
  if (browser) await browser.close();
  // ⛔ GIVE BACK WHAT WAS SPENT: the product, and every other Min Max rule
  // the fixture stashed while it measured (`alpha.45`).
  try { note(probe('reset').replace(/\n/g, ' · ')); } catch { /* reported by the script's own exit */ }
}

function figureAfterIn(text, label) {
  const idx = text.indexOf(label);
  if (idx < 0) return '__absent__';
  return (text.slice(idx + label.length).match(/[۰-۹]+/) ?? ['__none__'])[0];
}

lines.push(` pass=${pass} fail=${fail}`);
console.log(` pass=${pass} fail=${fail}`);
fs.writeFileSync(`${OUT}/run.txt`, `${lines.join('\n')}\n`);
process.exit(fail === 0 ? 0 : 1);
