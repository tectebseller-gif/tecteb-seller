/**
 * An answer to a question nobody is asking any more.
 *
 * The guard `alpha.26` shipped compared each answer against the newest one
 * already rendered. That is not the same as «still wanted»: after a keystroke
 * there is a quarter of a second of debounce during which the PREVIOUS
 * query's answer can arrive with nothing newer to compare it against. It is
 * the newest answer, so it renders — under a search box that says something
 * else — and if the request for what the vendor actually typed then fails,
 * that wrong list is what stays on screen.
 *
 * Reproduced here rather than argued: the first suggestion request is held
 * for two seconds by the test, the second is let through, and the question is
 * what the vendor is looking at when everything has settled.
 *
 * Runs the same three scenarios twice — once against the delivered script and
 * once with the generation counter taken out — and REQUIRES the second run to
 * fail. A stale-response guard that passes with the guard removed is not
 * testing the guard.
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/stale-search \
 *     node tools/browser/stale-search-run.mjs
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/stale-search';
const PLUGIN = process.env.TMC_PLUGIN_DIR
  || '/home/user/wp-demo/wp-content/plugins/tecteb-marketplace-core';
const SCRIPT = path.join(PLUGIN, 'assets/vendor/tmc-category-picker.js');
const VENDOR = { login: 'demo-vendor', pass: 'demo-vendor-2026' };

// How long the first answer is held, and how long the test then waits for it.
//
// Generous on purpose. The first version held for 2.2s and waited 3.2s, and
// one run in two had the held answer arrive after the test had stopped
// looking — so the falsification reported «the old code is fine», which is
// the one answer a falsification must never give by accident. A margin that
// costs six seconds and removes a coin flip is a good trade.
const HOLD_MS = Number(process.env.TMC_HOLD_MS || 3000);
const SETTLE_MS = Number(process.env.TMC_SETTLE_MS || 2500);

// Two terms that exist on the demo shop and share no letters, so «which
// answer is on screen» is a question about one word being present and the
// other absent rather than about a substring.
const FIRST = { typed: 'آمبو', term: 'آمبوبگ' };
const SECOND = { typed: 'اورولوژی', term: 'اورولوژی' };

fs.mkdirSync(OUT, { recursive: true });
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
const note = (t) => { lines.push(`      ${t}`); console.log(`      ${t}`); };

const original = fs.readFileSync(SCRIPT, 'utf8');

/** The pre-alpha.27 guard: compare against the last rendered answer, nothing else. */
function withoutTheGuard(src) {
  const needle = `        if (gen !== generation) {
            return;                         // answers a query nobody is asking
        }
`;
  if (!src.includes(needle)) {
    throw new Error('generation check not found in the installed script');
  }
  return src.replace(needle, '');
}

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });

async function session() {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 950 }, locale: 'fa-IR' });
  const page = await ctx.newPage();
  await page.goto(`${SITE}/wp-login.php?loggedout=true`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', VENDOR.login);
  await page.fill('#user_pass', VENDOR.pass);
  await page.click('#wp-submit');
  await page.waitForURL(/wp-admin|\/vendor\//, { timeout: 30000 }).catch(() => null);
  await page.goto(`${SITE}/vendor/products/?product=new&step=1`, { waitUntil: 'domcontentloaded' });
  return { ctx, page };
}

/**
 * Hold the nth suggestion request for `ms`, optionally failing a later one.
 *
 * Counted per request rather than per query text because the debounce means
 * the number of requests is not the number of keystrokes, and a rule written
 * against the text would silently stop matching the day the debounce changed.
 */
async function delayRequests(page, { holdFirstMs = 0, failFrom = 0 } = {}) {
  let seen = 0;
  await page.route('**/admin-ajax.php', async (route) => {
    const body = route.request().postData() || '';
    if (!body.includes('tmc_category_suggest')) {
      return route.continue();
    }
    seen += 1;
    const mine = seen;
    if (failFrom > 0 && mine >= failFrom) {
      return route.abort('failed');
    }
    if (mine === 1 && holdFirstMs > 0) {
      const response = await route.fetch();
      const text = await response.text();
      await new Promise((r) => setTimeout(r, holdFirstMs));
      return route.fulfill({ response, body: text });
    }
    return route.continue();
  });
}

const results = (page) => page.locator('#tv-catpick-results').innerText();

async function scenarioOutOfOrder(page) {
  await delayRequests(page, { holdFirstMs: HOLD_MS });
  // Type the first term and let its request leave, then replace it. The old
  // answer comes back while the new query is still in its debounce.
  await page.fill('#f-category_q', FIRST.typed);
  await page.waitForTimeout(600);
  await page.fill('#f-category_q', SECOND.typed);
  await page.waitForTimeout(HOLD_MS + SETTLE_MS);
  return results(page);
}

async function scenarioFailedNewRequest(page) {
  // The first answer is held, and every request after it fails. Nothing
  // trustworthy can be shown — and the answer to the abandoned query must
  // not be used to fill the gap.
  await delayRequests(page, { holdFirstMs: HOLD_MS, failFrom: 2 });
  await page.fill('#f-category_q', FIRST.typed);
  await page.waitForTimeout(600);
  await page.fill('#f-category_q', SECOND.typed);
  await page.waitForTimeout(HOLD_MS + SETTLE_MS);
  return {
    list: await results(page),
    status: await page.locator('#tv-catpick-status').innerText(),
  };
}

async function scenarioDeferredByFocus(page) {
  // A render held back because focus was inside the list, released long
  // after the box moved on. `alpha.26` applied it on blur without asking
  // whether it still belonged to what was typed.
  await page.fill('#f-category_q', FIRST.typed);
  await page.waitForTimeout(1200);
  const firstRadio = page.locator('#tv-catpick-results input[type="radio"]').first();
  if (await firstRadio.count()) {
    await firstRadio.focus();
  }
  await delayRequests(page, { holdFirstMs: HOLD_MS });
  await page.fill('#f-category_q', SECOND.typed);
  await page.waitForTimeout(600);
  await page.fill('#f-category_q', SECOND.typed + ' ');
  await page.waitForTimeout(HOLD_MS + SETTLE_MS);
  await page.focus('#f-category_q');
  await page.waitForTimeout(600);
  return results(page);
}

async function runAll(label) {
  const out = {};
  for (const [name, fn] of [
    ['outOfOrder', scenarioOutOfOrder],
    ['failedNew', scenarioFailedNewRequest],
    ['deferred', scenarioDeferredByFocus],
  ]) {
    const { ctx, page } = await session();
    try {
      out[name] = await fn(page);
    } finally {
      await ctx.close();
    }
  }
  // Printed, not summarised. When a falsification does not reproduce, the
  // first question is always «what did it actually render», and a boolean
  // cannot answer it.
  const head = (t) => String(t).replace(/\s+/g, ' ').slice(0, 90);
  note(`${label}: out-of-order  -> ${head(out.outOfOrder)}`);
  note(`${label}: failed-new    -> ${head(out.failedNew.list)} | status: ${head(out.failedNew.status)}`);
  note(`${label}: deferred      -> ${head(out.deferred)}`);
  return out;
}

// ---------------------------------------------------------- the guard as shipped
const good = await runAll('with the guard');
check('an out-of-order answer is not shown', good.outOfOrder.includes(FIRST.term), false);
check('and the answer that IS wanted is', good.outOfOrder.includes(SECOND.term), true);
check('a failed new request does not fall back to the old list', good.failedNew.list.includes(FIRST.term), false);
check('it says the search failed instead', /جست‌وجوی خودکار انجام نشد/.test(good.failedNew.status), true);
check('a render held for focus is dropped when the query moved on', good.deferred.includes(FIRST.term), false);

// ------------------------------------------------------------- falsification
let falsified = false;
try {
  fs.writeFileSync(SCRIPT, withoutTheGuard(original));
  falsified = true;
} catch (error) {
  check('the generation check was found and removed', `error: ${error.message}`, 'removed');
}
if (falsified) {
  const bad = await runAll('without the guard');
  // The sticky one is the one that matters: when the request the vendor is
  // actually waiting for fails, the stale list is what they are left looking
  // at, and nothing arrives later to correct it.
  check('without it, a stale answer survives a failed new request',
    bad.failedNew.list.includes(FIRST.term), true);
  check('and the screen stops admitting the search failed',
    /جست‌وجوی خودکار انجام نشد/.test(bad.failedNew.status), false);
  fs.writeFileSync(SCRIPT, original);
  check('and the installed script was put back',
    fs.readFileSync(SCRIPT, 'utf8') === original, true);
}

fs.writeFileSync(
  path.join(OUT, 'stale-search-run.txt'),
  `${lines.join('\n')}\n\nchecks: ${pass + fail}  pass: ${pass}  fail: ${fail}\n`
);
console.log(`\nchecks: ${pass + fail}  pass: ${pass}  fail: ${fail}`);
await browser.close();
process.exit(fail === 0 ? 0 : 1);
