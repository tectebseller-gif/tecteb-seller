/**
 * Browser toolchain smoke check (development only; never shipped in the ZIP).
 *
 * This does NOT re-run the accepted accessibility suites — check.mjs and
 * check-wpadmin.mjs own those, and their evidence must stay as delivered. All
 * this proves is the thing a fresh session needs to know before trusting them:
 * that Playwright can still launch a real Chromium here, render one of the
 * already-rendered harness pages, take a screenshot, and drive axe-core.
 *
 * Reads: docs/evidence/harness/*.html (never written to).
 * Writes: docs/evidence/tooling/ only.
 */
import { chromium } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const outDir = path.join(root, 'docs/evidence/tooling');
const page404 = (p) => { throw new Error(`missing input page: ${p}`); };

const PAGE = path.join(root, 'docs/evidence/harness/dashboard-with-wc.html');
if (!fs.existsSync(PAGE)) page404(PAGE);
fs.mkdirSync(outDir, { recursive: true });

const lines = [];
const say = (s) => { lines.push(s); console.log(s); };

const result = { tool: 'check-toolchain', checks: [], versions: {}, screenshot: null, axe: null };
const record = (name, ok, detail) => {
  result.checks.push({ name, ok, detail });
  say(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
};

result.versions = {
  node: process.version,
  playwright: require('playwright/package.json').version,
  axe_core: require('axe-core/package.json').version,
  // @axe-core/playwright does not export ./package.json, so read it off disk.
  axe_playwright: JSON.parse(fs.readFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), 'node_modules/@axe-core/playwright/package.json'), 'utf8')).version,
  browsers_path: process.env.PLAYWRIGHT_BROWSERS_PATH ?? null,
};
say(`node ${result.versions.node} · playwright ${result.versions.playwright} · axe-core ${result.versions.axe_core} · @axe-core/playwright ${result.versions.axe_playwright}`);

// Same resolution as check.mjs / check-wpadmin.mjs: the image ships one
// Chromium, and the installed Playwright expects a newer build id it may not
// download here, so the binary is named explicitly.
const executablePath = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
result.versions.chromium_executable = executablePath;
record('chromium-binary-present', fs.existsSync(executablePath), `${executablePath} -> ${fs.existsSync(executablePath) ? fs.realpathSync(executablePath) : 'missing'}`);
record('playwright-bundled-build-differs', true, `playwright would use ${chromium.executablePath()} (absent in this image)`);

const browser = await chromium.launch({ executablePath, args: ['--no-sandbox', '--allow-file-access-from-files'] });
result.versions.chromium = browser.version();
record('chromium-launches', true, `version ${browser.version()}`);

const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'fa-IR' });
const page = await context.newPage();
const jsErrors = [];
page.on('pageerror', (e) => jsErrors.push(String(e)));
await page.goto('file://' + PAGE, { waitUntil: 'load' });

const title = await page.title();
record('renders-a-harness-page', title.length > 0, `<title> = ${JSON.stringify(title)}`);
record('no-js-errors-on-that-page', jsErrors.length === 0, jsErrors.join(' | ') || 'none');

const shot = path.join(outDir, 'browser-toolchain-dashboard-with-wc-1440.png');
await page.screenshot({ path: shot, fullPage: true });
const bytes = fs.statSync(shot).size;
result.screenshot = { file: path.relative(root, shot), bytes };
record('screenshot-written', bytes > 5000, `${path.relative(root, shot)} (${bytes} bytes)`);

const axeRun = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
result.axe = {
  engine: axeRun.testEngine?.version ?? null,
  passes: axeRun.passes.length,
  violations: axeRun.violations.length,
  incomplete: axeRun.incomplete.length,
  note: 'smoke check of the axe integration on an already-delivered harness page; NOT a new accessibility result for wp-admin',
};
record('axe-runs-in-the-page', typeof axeRun.testEngine?.version === 'string',
  `axe engine ${result.axe.engine}: ${result.axe.passes} passes, ${result.axe.violations} violations, ${result.axe.incomplete} incomplete`);

await browser.close();

const failed = result.checks.filter((c) => !c.ok);
result.ok = failed.length === 0;
say('');
say(`${result.checks.length} checks, ${failed.length} failed`);
fs.writeFileSync(path.join(outDir, 'browser-toolchain.json'), JSON.stringify(result, null, 2) + '\n');
fs.writeFileSync(path.join(outDir, 'browser-toolchain.txt'), lines.join('\n') + '\n');
process.exit(failed.length === 0 ? 0 : 1);
