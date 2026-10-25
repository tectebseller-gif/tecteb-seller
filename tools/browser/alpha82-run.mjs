/**
 * The whole category SET in a browser: preview → apply → result.
 *
 * ⛔ The bytes under test are the INSTALLED ones (`alpha.61`), and the state
 * is arranged OUTSIDE the browser by a seeding script, because rendering a
 * page says nothing about its decisions (`alpha.13`). Each round's evidence
 * tool has its own name (`alpha.54`): nothing under
 * `tools/browser/alpha[0-8][0-1]-run.mjs` is edited.
 *
 * ⛔ AND EVERY COMPARISON HERE IS A SET COMPARISON. The owner's acceptance
 * rule says so in as many words: «برابری مجموعهٔ شناسه‌ها را قبل و بعد از
 * اعمال، ویرایش و پذیرش اندازه بگیر؛ برابری تعداد کافی نیست.» So the ids are
 * sorted and compared as lists of numbers, never as counts and never as the
 * strings the page happens to print them in.
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/alpha82-ui \
 *   WP="php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo" \
 *     node tools/browser/alpha82-run.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/alpha82-ui';
const WP = process.env.WP || 'php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo';
const HANDOVER = `${SITE}/wp-admin/admin.php?page=tmc-handover`;
const MANAGER = {
  login: process.env.ADMIN || 'tmcowner',
  pass: process.env.ADMIN_PASS || 'demo-owner-2026',
};

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
  setsHeading: 'محصولات چنددسته‌ای — مجموعهٔ دسته‌هایشان',
  setsRule: 'مجموعهٔ کاملشان همان‌طور که هست ثبت می‌شود',
  noRepick: 'از کسی خواسته نمی‌شود دسته‌های موجود را دوباره انتخاب کند',
  applyHeading: 'آمادهٔ ثبت — مجموعهٔ دسته‌های خودِ محصول',
  applyButton: 'ثبت دسته‌های فعلی ووکامرس روی محصولات واردشده',
  recorded: 'مجموعهٔ دسته‌هایش از ارتباط ووکامرس ثبت شد',
  alreadySet: 'از قبل ثبت شده بود و دست نخورد',
  defaultTerm: 'تنها دستهٔ پیش‌فرض ووکامرس را دارد',
  noCategory: 'در ووکامرس هیچ دسته‌ای ندارد',
  // ⛔ THE SENTENCE THAT MUST BE GONE. The owner asked for it by name:
  // «پیام «نیازمند انتخاب یک دسته» برای این موارد حذف شود.»
  retired: 'نیازمند تصمیم انسان',
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
const state = (verb) => execSync(`${WP} eval-file tools/alpha82-ui-state.php ${verb}`, { encoding: 'utf8' }).trim();
const field = (report, key) => (report.match(new RegExp(`^${key}=(.*)$`, 'm'))?.[1] ?? '').trim();
// One spelling for «this set», used on both sides of every comparison.
const parseIds = (csv) => (csv === '' || csv === '__empty__' || csv === '__none__'
  ? []
  : csv.split(',').map(Number).filter((n) => Number.isFinite(n) && n > 0).sort((a, b) => a - b));
const setOf = (report, name) => parseIds(field(report, `set_${name}`));
const wcSetOf = (report, name) => parseIds(field(report, `wc_terms_${name}`));
const sameSet = (a, b) => JSON.stringify(a) === JSON.stringify(b);

let browser;
try {
  const installed = execSync(
    `${WP} plugin list --name=tecteb-marketplace-core --field=version`,
    { encoding: 'utf8' },
  ).trim();
  check('0 the installed package is the one built here', installed, EXPECT);

  const seeded = state('seed');
  note(`seed: ${seeded.replace(/\n/g, ' · ')}`);
  check('0a the fixture landed its rows', field(seeded, 'rows') !== '', true);

  // ⛔ Read from the SITE, not from a seed line: `seed` is idempotent and on a
  // second run prints fewer lines, and an absent needle parses as an empty
  // set — which would make «the relationship survived» compare the real terms
  // against nothing and pass (`alpha.34`).
  const before = state('report');
  note(`before: ${before.replace(/\n/g, ' · ')}`);
  const pairTerms = wcSetOf(before, 'pair');
  const branchTerms = wcSetOf(before, 'branches');
  const oneTerms = wcSetOf(before, 'one');
  check('0b the fixture really gave one product a PARENT and its CHILD', pairTerms.length, 2);
  check('0c …and another three independent branches', branchTerms.length, 3);
  check('0d …and one product exactly one category', oneTerms.length, 1);
  check('0e not one relationship is recorded here yet',
    ['pair', 'branches', 'one', 'default', 'none'].map((n) => setOf(before, n).length).join(','),
    '0,0,0,0,0');
  check('0f and not one row carries a single key either',
    ['key_pair', 'key_branches', 'key_one'].map((k) => field(before, k)).join(','),
    '__empty__,__empty__,__empty__');

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
  check('1b the category card is there', body.includes(T.card), true);
  check('1c …and says it writes nothing until the button', body.includes(T.readsOnly), true);
  check('1d the multi-category group is described as a SET, not a question',
    body.includes(T.setsHeading), true);
  check('1e …and says the whole set is recorded as it stands', body.includes(T.setsRule), true);
  check('1f …and that nobody is asked to pick again', body.includes(T.noRepick), true);
  // ⛔ THE REMOVAL, measured as an ABSENCE rather than assumed.
  check('1g «نیازمند تصمیم انسان» is gone from the page', body.includes(T.retired), false);
  check('1h the rows ready to record are offered apart', body.includes(T.applyHeading), true);
  // Every bucket the fixture makes is NAMED, not folded away.
  check('1i the default-term product is named', body.includes(T.defaultTerm), true);
  check('1j the product with no category is named', body.includes(T.noCategory), true);

  // The group shows the count and EVERY id with its full ancestry.
  check('1k the group names every term id of the parent/child pair',
    pairTerms.every((id) => body.includes(`#${toPersian(id)}`)), true);
  check('1l …with the full ancestry, not a bare leaf', body.includes('›'), true);

  // ⛔ THE FORM CARRIES THE WHOLE SET. The hidden input is read from the
  // rendered HTML, because `proposed_key` would be `''` for a multi-category
  // product and the page would then post consent to nothing.
  const posted = await mgr.page.evaluate(() => Object.fromEntries(
    [...document.querySelectorAll('input[type=hidden][name^="link["]')]
      .map((el) => [el.name.replace(/^link\[|\]$/g, ''), el.value]),
  ));
  note(`the form posts: ${JSON.stringify(posted)}`);
  const pairRow = field(before, 'rows').match(/"pair":(\d+)/)?.[1] ?? '';
  check('1m the pair\'s row is in the form', Object.hasOwn(posted, pairRow), true);
  check('1n …and it carries BOTH ids, not one', parseIds(posted[pairRow] ?? '').length, 2);
  check('1o …and exactly the ids WooCommerce has',
    sameSet(parseIds(posted[pairRow] ?? ''), pairTerms), true);

  // ⛔ Nothing written by LOOKING. The page was rendered above; the sets must
  // still be empty.
  const afterLook = state('report');
  check('1p rendering the preview recorded nothing at all',
    ['pair', 'branches', 'one'].map((n) => setOf(afterLook, n).length).join(','), '0,0,0');

  // --------------------------------------------------------------- 2 apply
  await mgr.page.click(`button:has-text("${T.applyButton}")`);
  await mgr.page.waitForLoadState('domcontentloaded');
  body = await textOf(mgr.page);
  fs.writeFileSync(`${OUT}/2-applied.txt`, body);
  check('2a the result says the set was recorded', body.includes(T.recorded), true);

  const after = state('report');
  note(`after: ${after.replace(/\n/g, ' · ')}`);
  // ⛔ THE ACCEPTANCE RULE, on the owner's own case: SET equality.
  check('2b the parent/child pair records BOTH its categories',
    sameSet(setOf(after, 'pair'), pairTerms), true);
  check('2c the three independent branches are all recorded',
    sameSet(setOf(after, 'branches'), branchTerms), true);
  check('2d the single-category product records its one',
    sameSet(setOf(after, 'one'), oneTerms), true);
  check('2e the default-term product records nothing', setOf(after, 'default').length, 0);
  check('2f …and neither does the one with no category', setOf(after, 'none').length, 0);
  // The single key is DERIVED, never chosen: empty for a set of several.
  check('2g the pair gets no single key, because there is no single key',
    field(after, 'key_pair'), '__empty__');
  check('2h …and the single-category product does get one',
    field(after, 'key_one'), String(oneTerms[0]));

  // ⛔ AND NOTHING WAS REMOVED OR ADDED IN WOOCOMMERCE. «هیچ رابطه‌ای خودکار
  // حذف یا اضافه نشود» — compared as sets, before against after.
  check('2i every WooCommerce relationship of the pair is untouched',
    sameSet(wcSetOf(after, 'pair'), pairTerms), true);
  check('2j …of the three-branch product too',
    sameSet(wcSetOf(after, 'branches'), branchTerms), true);
  check('2k …and of the single-category one',
    sameSet(wcSetOf(after, 'one'), oneTerms), true);

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
    check('3a a second application records nothing new', body.includes(T.recorded), false);
  } else {
    check('3a nothing is left to record, which is the same answer', stillOffered, false);
  }
  const twice = state('report');
  check('3b and every recorded set is the one it was',
    ['pair', 'branches', 'one'].every((n) => sameSet(setOf(twice, n), setOf(after, n))), true);
  check('3c …with WooCommerce still untouched',
    sameSet(wcSetOf(twice, 'pair'), pairTerms), true);

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
