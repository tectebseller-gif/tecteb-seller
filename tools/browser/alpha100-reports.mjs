/**
 * The owner's §3, measured in a real browser.
 *
 * ⛔ WHY THIS SCRIPT EXISTS. Two of the six defects §3 lists cannot be seen
 * from PHP at all:
 *   - `.tmc-table` is `display:block` until `@container tmccard (min-width:
 *     44rem)` says there is room, and in `alpha.99` the report's table had no
 *     card above it, so the query NEVER matched and a thirteen-column
 *     financial table rendered as very tall stacked cards at every width;
 *   - `.tmc-filter-form` was written by two views and matched by ZERO rules,
 *     so thirteen filters laid out one per line.
 * A stylesheet rule that fails to match is invisible to every PHP suite in
 * this repository. `getComputedStyle` is the only thing that can answer, so
 * that is what this asks — at the four widths the owner named, with a
 * wp-admin-shaped menu column in the document, because the container query is
 * about the width the CARD gets and the menu takes 160 pixels of it.
 *
 * Reads:  docs/evidence/alpha100-reports/*.html — written by
 *         tests/Database/ReportLayoutEvidenceTest.php, which is the ONLY
 *         place that markup comes from, so these screenshots cannot be of a
 *         page the suite did not check.
 * Writes: docs/evidence/alpha100-reports/ (screenshots + measurements.json).
 *
 * WHAT THIS IS: a sample of the interface. WHAT IT IS NOT: proof the plugin
 * works inside real wp-admin, which stays Not Run.
 */
import { chromium } from 'playwright';
import { AxeBuilder } from '@axe-core/playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const dir = path.join(root, 'docs/evidence/alpha100-reports');
const executablePath = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';

const WIDTHS = [
  { name: '1440', width: 1440, height: 900, table: 'table', rows: 2, columns: 3 },
  { name: '1280', width: 1280, height: 860, table: 'table', rows: 2, columns: 3 },
  // 768 minus the 160px menu is 608px of content; a thirteen-column table has
  // no business being a table there, and the stacked layout WITH labels is
  // the better answer. Asserted as `block` on purpose, not tolerated.
  { name: '768', width: 768, height: 1024, table: 'block', rows: 3, columns: 2 },
  { name: '390', width: 390, height: 844, table: 'block', rows: 6, columns: 1 },
];

const PAGES = ['order-ledger', 'order-ledger-product', 'order-audit'];

const checks = [];
const record = (name, pass, detail = '') => {
  checks.push({ name, pass, detail });
  console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? `  — ${detail}` : ''}`);
};

for (const page of PAGES) {
  const file = path.join(dir, `${page}.html`);
  record(`${page}: input page exists`, fs.existsSync(file), file);
}

const browser = await chromium.launch({ executablePath, args: ['--no-sandbox'] });
const measurements = { chromium: browser.version(), pages: {} };

for (const name of PAGES) {
  const file = path.join(dir, `${name}.html`);
  if (!fs.existsSync(file)) continue;
  measurements.pages[name] = {};

  for (const vp of WIDTHS) {
    const context = await browser.newContext({
      viewport: { width: vp.width, height: vp.height },
      deviceScaleFactor: 2,
    });
    const page = await context.newPage();
    const consoleErrors = [];
    page.on('pageerror', (e) => consoleErrors.push(String(e)));
    await page.goto(`file://${file}`);
    await page.waitForLoadState('load');

    const m = await page.evaluate(() => {
      const out = {};
      const table = document.querySelector('.tmc-table');
      out.tableDisplay = table ? getComputedStyle(table).display : null;
      out.tableRows = table ? table.querySelectorAll('tbody tr').length : 0;
      const head = table ? table.querySelector('thead') : null;
      out.theadDisplay = head ? getComputedStyle(head).display : null;

      // Every cell must carry a label, and in the stacked layout the label
      // must actually be painted — `content: attr(data-label)` with no
      // attribute prints a bare «: ».
      const cells = table ? [...table.querySelectorAll('tbody td')] : [];
      out.cells = cells.length;
      out.cellsWithoutLabel = cells.filter((c) => !c.getAttribute('data-label')).length;
      const first = cells[0];
      out.firstCellBefore = first
        ? getComputedStyle(first, '::before').content
        : null;

      const form = document.querySelector('.tmc-filter-form');
      out.filterDisplay = form ? getComputedStyle(form).display : null;
      out.filterColumns = form
        ? getComputedStyle(form).gridTemplateColumns.split(' ').filter(Boolean).length
        : 0;
      // The visible fields' own boxes, to count how many share a row.
      // The PRIMARY fields only: the ones before the folded block. `sort` and
      // `per_page` are printed after it on purpose, so counting them as
      // «filters» would make the answer «three rows» at every width and say
      // nothing about the defect — which was six filters on six rows.
      const more = form ? form.querySelector(':scope > .tmc-filter-form__more') : null;
      const all = form ? [...form.querySelectorAll(':scope > .tmc-field')] : [];
      const primary = more
        ? all.filter((f) => f.compareDocumentPosition(more) & Node.DOCUMENT_POSITION_FOLLOWING)
        : all;
      // `align-items: end` bottom-aligns a row, so two fields of different
      // heights in ONE row have different tops. Bottoms are what line up.
      const bands = new Set(primary.map((f) => Math.round(f.getBoundingClientRect().bottom)));
      out.filterFields = all.length;
      out.filterPrimary = primary.length;
      out.filterRows = bands.size;
      out.foldedOpen = more ? more.hasAttribute('open') : null;
      out.foldedFields = all.length - primary.length;

      const nav = document.querySelector('.tmc-reportnav');
      out.reportNav = !!nav;
      out.reportNavLinks = nav ? nav.querySelectorAll('a').length : 0;

      const quiet = document.querySelector('.tmc-button--quiet');
      out.quietButtonBackground = quiet ? getComputedStyle(quiet).backgroundColor : null;
      out.quietButtonBorderWidth = quiet ? getComputedStyle(quiet).borderTopWidth : null;

      out.docScrollWidth = document.documentElement.scrollWidth;
      out.docClientWidth = document.documentElement.clientWidth;

      // The tallest empty gap between the top of the content and the first
      // table row — the owner's «فضای خالی بلند» from alpha.32.
      const body = document.getElementById('wpbody');
      out.contentHeight = body ? Math.round(body.getBoundingClientRect().height) : 0;
      return out;
    });

    measurements.pages[name][vp.name] = m;

    if (name === 'order-ledger') {
      record(
        `${name} @${vp.name}: the table renders as ${vp.table}`,
        m.tableDisplay === vp.table,
        `display: ${m.tableDisplay}`
      );
      record(
        `${name} @${vp.name}: every cell carries a label`,
        m.cells > 0 && m.cellsWithoutLabel === 0,
        `${m.cells} cells, ${m.cellsWithoutLabel} unlabelled`
      );
      if (vp.table === 'block') {
        record(
          `${name} @${vp.name}: the stacked cell prints its label`,
          typeof m.firstCellBefore === 'string'
            && m.firstCellBefore.includes('سفارش')
            && m.firstCellBefore.trim() !== '": "',
          `::before = ${m.firstCellBefore}`
        );
        record(
          `${name} @${vp.name}: the heading row is hidden in the stacked layout`,
          m.theadDisplay !== 'table-header-group',
          `thead: ${m.theadDisplay}`
        );
      } else {
        record(
          `${name} @${vp.name}: the heading row is a real table head`,
          m.theadDisplay === 'table-header-group',
          `thead: ${m.theadDisplay}`
        );
      }
      record(
        `${name} @${vp.name}: the filter bar is a ${vp.columns}-column grid`,
        m.filterDisplay === 'grid' && m.filterColumns === vp.columns,
        `display: ${m.filterDisplay}, ${m.filterColumns} columns`
      );
      record(
        `${name} @${vp.name}: the six primary filters share ${vp.rows} row(s)`,
        m.filterPrimary === 6 && m.filterRows === vp.rows,
        `${m.filterPrimary} primary fields on ${m.filterRows} rows `
          + `(${m.filterFields} in all, ${m.foldedFields} after the fold)`
      );
      record(
        `${name} @${vp.name}: the advanced filters start folded`,
        m.foldedOpen === false,
        `open: ${m.foldedOpen}`
      );
      record(
        `${name} @${vp.name}: the quiet button is quieter than the primary one`,
        m.quietButtonBorderWidth === '1px',
        `border: ${m.quietButtonBorderWidth}, background: ${m.quietButtonBackground}`
      );
    }

    record(
      `${name} @${vp.name}: no horizontal page scroll`,
      m.docScrollWidth <= m.docClientWidth,
      `${m.docScrollWidth} vs ${m.docClientWidth}`
    );
    record(`${name} @${vp.name}: no script error`, consoleErrors.length === 0, consoleErrors.join(' | '));

    await page.screenshot({ path: path.join(dir, `${name}-${vp.name}.png`) });
    // The table's own first screenful. Scrolled to rather than clipped: a
    // clip is read against the VIEWPORT, and `fullPage` cannot help because
    // the stacked layout is tens of thousands of pixels tall and Chromium
    // refuses a capture past 16384.
    const sawTable = await page.evaluate(() => {
      const t = document.querySelector('.tmc-table');
      if (!t) return false;
      t.scrollIntoView({ block: 'start' });
      return true;
    });
    if (sawTable) {
      await page.screenshot({ path: path.join(dir, `${name}-${vp.name}-table.png`) });
      await page.evaluate(() => window.scrollTo(0, 0));
    }
    const tall = await page.evaluate(() => document.documentElement.scrollHeight);
    measurements.pages[name][vp.name].pageHeight = tall;
    const CAPTURE_CAP = 16000;
    if (tall <= CAPTURE_CAP) {
      await page.screenshot({
        path: path.join(dir, `${name}-${vp.name}-full.png`),
        fullPage: true,
        scale: 'css',
      });
      measurements.pages[name][vp.name].fullPage = true;
    } else {
      measurements.pages[name][vp.name].fullPage = false;
      measurements.pages[name][vp.name].fullPageReason =
        `page is ${tall}px tall; Chromium refuses a capture past 16384px`;
    }

    // axe on the widest and the narrowest only: the middle two render the
    // same markup and the run costs seconds each.
    if (vp.name === '1440' || vp.name === '390') {
      const axe = await new AxeBuilder({ page }).analyze();
      const serious = axe.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
      measurements.pages[name][vp.name].axe = {
        total: axe.violations.length,
        all: axe.violations.map((v) => `${v.id} [${v.impact}] (${v.nodes.length})`),
        serious: serious.map((v) => `${v.id} (${v.nodes.length})`),
      };
      record(
        `${name} @${vp.name}: axe finds no serious or critical violation`,
        serious.length === 0,
        serious.map((v) => v.id).join(', ') || `${axe.violations.length} minor`
      );
    }

    await context.close();
  }
}

await browser.close();

const failed = checks.filter((c) => !c.pass);
measurements.checks = checks.length;
measurements.failures = failed.length;
fs.writeFileSync(
  path.join(dir, 'measurements.json'),
  `${JSON.stringify(measurements, null, 2)}\n`
);
console.log(`\n${checks.length} checks, ${failed.length} failures`);
if (failed.length > 0) {
  for (const f of failed) console.log(`  FAIL ${f.name}: ${f.detail}`);
  process.exit(1);
}
