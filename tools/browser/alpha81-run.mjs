/**
 * The category-relationship path in a browser: preview → apply → result, and
 * the reported search.
 *
 * ⛔ The bytes under test are the INSTALLED ones (`alpha.61`), and the state
 * is arranged OUTSIDE the browser by a seeding script, because rendering a
 * page says nothing about its decisions (`alpha.13`). Each round's evidence
 * tool has its own name (`alpha.54`): nothing under
 * `tools/browser/alpha[0-7]*-run.mjs` is edited.
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/alpha81-ui \
 *   WP="php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo" \
 *     node tools/browser/alpha81-run.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/alpha81-ui';
const WP = process.env.WP || 'php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo';
const HANDOVER = `${SITE}/wp-admin/admin.php?page=tmc-handover`;
const MANAGER = { login: 'tmcowner', pass: 'demo-owner-2026' };

const HEADER = fs.readFileSync(new URL('../../tecteb-marketplace-core.php', import.meta.url), 'utf8');
const EXPECT = process.env.TMC_EXPECT_VERSION
  || (HEADER.match(/^\s*\*\s*Version:\s*(.+)$/m)?.[1] ?? '').trim();

// Persian needles copied from the views' own strings, never retyped from the
// idea of them (`alpha.23`), and never a substring of a longer sentence on
// the same page (`alpha.77`).
const T = {
  handoverTitle: 'تحویل مهاجرت',
  card: 'دستهٔ محصولات از ارتباط فعلی ووکامرس',
  readsOnly: 'تا زدن دکمه هیچ‌چیز نوشته نمی‌شود',
  multiHeading: 'محصولات چنددسته‌ای — نیازمند تصمیم انسان',
  multiRule: 'هیچ‌کدام خودسرانه انتخاب نمی‌شود',
  applyHeading: 'اتصال‌های قطعی — آمادهٔ اعمال',
  applyButton: 'اعمال اتصال‌های قطعی روی محصولات واردشده',
  wrote: 'از ارتباط خودِ ووکامرس نوشته شد',
  alreadySet: 'از قبل دسته داشت و دست نخورد',
  heldMulti: 'بیش از یک دسته دارد، پس هیچ‌کدام خودسرانه انتخاب نشد',
  defaultTerm: 'تنها دستهٔ پیش‌فرض ووکامرس را دارد',
  noCategory: 'در ووکامرس هیچ دسته‌ای ندارد',
};

const toPersian = (s) => String(s).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);

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
const state = (verb) => execSync(`${WP} eval-file tools/alpha81-ui-state.php ${verb}`, { encoding: 'utf8' }).trim();
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
  const wcMany = field(seeded, 'wc_many');
  const wcOne = field(seeded, 'wc_one');
  // ⛔ Read from the SITE, not from a seed line. `seed` is idempotent, so on
  // a second run it prints the product ids and skips the `_terms` lines — and
  // a needle that is absent parses as `[]`, which made «every relationship
  // survived» compare the real three terms against nothing and fail about a
  // path that works (`alpha.34`: never carry a value from another run).
  const termsOf = (wcId) => execSync(
    `${WP} eval 'clean_post_cache(${wcId}); echo implode(",", wp_get_object_terms(${wcId}, "product_cat", ["fields" => "ids"]));'`,
    { encoding: 'utf8' },
  ).trim();
  const parseIds = (csv) => (csv === '' ? [] : csv.split(',').map(Number).sort((a, b) => a - b));
  check('0a the fixture landed five products', field(seeded, 'rows') !== '', true);
  const before = state('report');
  note(`before: ${before.replace(/\n/g, ' · ')}`);
  check('0b and not one of them carries a category key yet',
    ['key_one', 'key_many', 'key_persian', 'key_default', 'key_none']
      .map((k) => field(before, k)).join(','),
    '__empty__,__empty__,__empty__,__empty__,__empty__');

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

  // ------------------------------------------------------------- 1 preview
  const mgr = await session(MANAGER);
  await mgr.page.goto(HANDOVER, { waitUntil: 'domcontentloaded' });
  let body = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/1-preview.txt`, body);

  check('1a the handover screen is the one it says it is', body.includes(T.handoverTitle), true);
  check('1b the category-link card is there', body.includes(T.card), true);
  check('1c …and says it writes nothing until the button', body.includes(T.readsOnly), true);
  check('1d the multi-category group has its own heading', body.includes(T.multiHeading), true);
  check('1e …and says no category is picked for anybody', body.includes(T.multiRule), true);
  check('1f the definite links are offered apart', body.includes(T.applyHeading), true);
  // Every bucket the fixture creates is NAMED on the page, not folded away.
  check('1g the default-term product is named', body.includes(T.defaultTerm), true);
  check('1h the product with no category is named', body.includes(T.noCategory), true);

  // The ambiguous group shows every id with its FULL path — the short list a
  // human picks from.
  const manyTerms = parseIds(termsOf(wcMany));
  check('1i0 the fixture really gave that product three categories', manyTerms.length, 3);
  check('1i the ambiguous group names every one of its term ids',
    manyTerms.every((id) => body.includes(`#${toPersian(id)}`)), true);
  check('1j …with the full ancestry, not a bare leaf', body.includes('›'), true);

  // ⛔ Nothing written by LOOKING. The page was rendered twice above; the
  // keys must still be empty.
  const afterLook = state('report');
  check('1k rendering the preview wrote nothing at all',
    ['key_one', 'key_many', 'key_persian'].map((k) => field(afterLook, k)).join(','),
    '__empty__,__empty__,__empty__');

  // --------------------------------------------------------------- 2 apply
  await mgr.page.click(`button:has-text("${T.applyButton}")`);
  await mgr.page.waitForLoadState('domcontentloaded');
  body = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/2-applied.txt`, body);

  check('2a the result says what was written', body.includes(T.wrote), true);
  check('2b …and names the rows that were not', body.includes(T.heldMulti), true);

  const after = state('report');
  note(`after: ${after.replace(/\n/g, ' · ')}`);
  check('2c the single-category product got its own category',
    field(after, 'key_one') !== '__empty__', true);
  check('2d the Persian-category product too', field(after, 'key_persian') !== '__empty__', true);
  // ⛔ THE FINDING. A multi-category product gets no key at all.
  check('2e the multi-category product still has NO key', field(after, 'key_many'), '__empty__');
  check('2f the default-term product has no key either', field(after, 'key_default'), '__empty__');
  check('2g and neither does the one with no category', field(after, 'key_none'), '__empty__');

  // Its WooCommerce relationship is untouched — all three terms still there.
  check('2h every WooCommerce relationship of the multi product survived',
    JSON.stringify(parseIds(termsOf(wcMany))), JSON.stringify(manyTerms));
  check('2i and the single-category product kept its one relationship',
    parseIds(termsOf(wcOne)).length, 1);

  // ------------------------------------------------------ 3 applying twice
  await mgr.page.goto(HANDOVER, { waitUntil: 'domcontentloaded' });
  body = await textOf(mgr.page);
  const stillOffered = body.includes(T.applyHeading);
  note(`a second pass still offers rows: ${stillOffered}`);
  if (stillOffered) {
    await mgr.page.click(`button:has-text("${T.applyButton}")`);
    await mgr.page.waitForLoadState('domcontentloaded');
    body = await textOf(mgr.page);
    fs.writeFileSync(`${OUT}/3-twice.txt`, body);
    check('3a a second application writes nothing new', body.includes(T.wrote), false);
  } else {
    check('3a nothing is left to apply, which is the same answer', stillOffered, false);
  }
  const twice = state('report');
  check('3b and no key changed on the second pass',
    ['key_one', 'key_many', 'key_persian'].map((k) => field(twice, k)).join(','),
    ['key_one', 'key_many', 'key_persian'].map((k) => field(after, k)).join(','));

  // --------------------------------------------------------- 4 mobile 390px
  const small = await session(MANAGER, { width: 390, height: 844 });
  await small.page.goto(HANDOVER, { waitUntil: 'domcontentloaded' });
  await small.page.waitForLoadState('domcontentloaded');
  const overflow = await small.page.evaluate(() =>
    Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth));
  await small.page.screenshot({ path: `${OUT}/4-mobile-390.png`, fullPage: true });
  check('4a no horizontal overflow at 390px', overflow, 0);
  await small.ctx.close();

  // --------------------------------------------------------------- 5 axe
  const axe = fs.readFileSync(
    new URL('./node_modules/axe-core/axe.min.js', import.meta.url),
    'utf8',
  );
  await mgr.page.goto(HANDOVER, { waitUntil: 'domcontentloaded' });
  await mgr.page.addScriptTag({ content: axe });
  const violations = await mgr.page.evaluate(async () => {
    // Scoped to OUR markup: the WordPress admin bar and core landmarks carry
    // violations of their own, and counting them as ours hides ours
    // (`alpha.57`).
    const result = await window.axe.run('#wpbody-content', {
      runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] },
    });
    return result.violations
      .filter((v) => v.impact === 'serious' || v.impact === 'critical')
      .map((v) => `${v.id}:${v.nodes.length}`);
  });
  note(`axe serious/critical: ${violations.length === 0 ? 'none' : violations.join(', ')}`);
  check('5a no serious or critical accessibility violation in our markup', violations.length, 0);

  await mgr.ctx.close();
} catch (error) {
  fail++;
  const line = `FAIL harness — ${error.message}`;
  lines.push(line);
  console.log(line);
} finally {
  if (browser) {
    await browser.close();
  }
  try {
    note(`reset: ${state('reset').replace(/\n/g, ' · ')}`);
  } catch (error) {
    note(`reset failed: ${error.message}`);
  }
  lines.push(`checks: ${pass} passed, ${fail} failed`);
  console.log(`checks: ${pass} passed, ${fail} failed`);
  fs.writeFileSync(`${OUT}/run.txt`, `${lines.join('\n')}\n`);
}
process.exit(fail === 0 ? 0 : 1);
