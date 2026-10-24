/**
 * The queue screen, read in the state the owner's report describes.
 *
 * One row this round added: «زمان مرحله‌ها» — which step a worker is in, how
 * long ago it started, the slowest step ever recorded, and a number against
 * every step that finished. Plus the sentence a batch leaves when it ends
 * ITSELF rather than being cut off.
 *
 * ⛔ The state is arranged OUTSIDE the browser, through a seeding script, so
 * every figure on the screen is one the repository already wrote — «رندر
 * کردن یک صفحه دربارهٔ تصمیم‌هایش هیچ نمی‌گوید» (`alpha.13`). And each round's
 * evidence tool has its own name (`alpha.54`): nothing under
 * `tools/browser/alpha7[0123]-run.mjs` is edited.
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/alpha75-ui \
 *   WP="php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo" \
 *     node tools/browser/alpha75-run.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/alpha75-ui';
const WP = process.env.WP || 'php /usr/local/bin/wp --allow-root --path=/home/user/wp-demo';
const SEED = process.env.TMC_SEED || '';
const QUEUE = `${SITE}/wp-admin/admin.php?page=tmc-jobs`;
const MANAGER = { login: 'tmcowner', pass: 'demo-owner-2026' };

const HEADER = fs.readFileSync(new URL('../../tecteb-marketplace-core.php', import.meta.url), 'utf8');
const EXPECT = process.env.TMC_EXPECT_VERSION
  || (HEADER.match(/^\s*\*\s*Version:\s*(.+)$/m)?.[1] ?? '').trim();

// Persian needles copied from the views' own strings, not retyped from the
// idea of them (`alpha.23`).
const T = {
  queueTitle: 'صف و سلامت اجرا',
  stepsLabel: 'زمان مرحله‌ها',
  inStep: 'در مرحلهٔ «ساخت اندازه‌های تصویر»',
  slowest: 'کُندترین مرحلهٔ ثبت‌شده',
  copyStep: 'کپی فایل تصویر',
  judgeStep: 'بررسی شرایط ورود',
  privacy: 'فقط نام مرحله، شمارهٔ محصول و زمان ثبت می‌شود',
  stoppedForTime: 'وقتِ این درخواست رو به پایان بود',
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

let browser;
try {
  // ⛔ The bytes under test are the INSTALLED ones, or this says nothing
  // about the package (`alpha.61`).
  const installed = execSync(
    `${WP} plugin list --name=tecteb-marketplace-core --field=version`,
    { encoding: 'utf8' },
  ).trim();
  check('0 the installed package is the one built here', installed, EXPECT);

  if (SEED !== '') {
    note(execSync(`${WP} eval-file ${SEED}`, { encoding: 'utf8' }).trim().replace(/\n/g, ' · '));
  }

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

  const mgr = await session(MANAGER);
  await mgr.page.goto(QUEUE, { waitUntil: 'domcontentloaded' });
  const body = await textOf(mgr.page);
  const html = await mgr.page.content();
  fs.writeFileSync(`${OUT}/1-queue.txt`, body);

  check('1a the queue screen is the one it says it is', body.includes(T.queueTitle), true);
  // ⛔ `data-label` is an attribute, not innerText — ask the HTML (`alpha.72`).
  check('1b the step row is on the page', html.includes(T.stepsLabel), true);
  check('1c …naming the step the worker is in', body.includes(T.inStep), true);
  check('1d …and which product', body.includes('۹۳۴۴'), true);
  check('1e …and the slowest step ever recorded', body.includes(T.slowest), true);
  check('1f …with the two halves of the picture work named apart',
    body.includes(T.copyStep) && body.includes(T.judgeStep), true);
  check('1g …and the four-thousand-millisecond figure, in Persian digits',
    body.includes('۴٬۰۸۹') || body.includes('۴۰۸۹'), true);
  check('1h the batch says it ended ITSELF, not that it was cut off',
    body.includes(T.stoppedForTime), true);
  // ⛔ Said on the page, because a table of numbers invites the reading that
  // the numbers are everything.
  check('1i …and the page says what is recorded and what is not',
    body.includes(T.privacy), true);

  // And nothing a product carries is on the screen.
  for (const forbidden of ['uploads/', 'http://', '.jpg', 'SKU']) {
    check(`1j no «${forbidden}» anywhere in the step row`,
      /زمان مرحله‌ها[\s\S]{0,900}/.exec(body)?.[0].includes(forbidden) ?? false, false);
  }

  const mobile = await session(MANAGER, { width: 390, height: 844 });
  await mobile.page.goto(QUEUE, { waitUntil: 'domcontentloaded' });
  const overflow = await mobile.page.evaluate(
    () => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth),
  );
  check('2 no horizontal overflow at 390px', overflow, 0);
  await mobile.page.screenshot({ path: `${OUT}/2-queue-390.png`, fullPage: true });
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
  fs.writeFileSync(`${OUT}/3-axe-queue.json`, JSON.stringify(result.violations, null, 2));
  check('3 axe on the queue screen: no serious or critical violation', serious.length, 0);
  await mgr.page.screenshot({ path: `${OUT}/3-queue-desktop.png`, fullPage: true });
  await mgr.ctx.close();
} finally {
  if (browser) await browser.close();
}

lines.push(` pass=${pass} fail=${fail}`);
console.log(` pass=${pass} fail=${fail}`);
fs.writeFileSync(`${OUT}/run.txt`, `${lines.join('\n')}\n`);
process.exit(fail === 0 ? 0 : 1);
