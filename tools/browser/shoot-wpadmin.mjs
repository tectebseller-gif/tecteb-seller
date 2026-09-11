/**
 * Screenshots + layout measurements of the four plugin screens in a REAL
 * wp-admin (development only; never shipped).
 *
 * Purpose: the accepted a11y suite answers "are there violations"; this
 * answers "what does it look like, and how wide is the box the text had to
 * fit in". It is the before/after evidence for the layout work, so it writes
 * to whatever directory you give it and never touches the accepted evidence.
 *
 *   OUT=docs/evidence/redesign/before LABEL=before node shoot-wpadmin.mjs
 *
 * Env: SITE (http://127.0.0.1:8080), TMC_USER (tmcadmin),
 *      TMC_PASS_FILE (/root/.wp_pass), TMC_CHROMIUM, WIDTHS (csv).
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const SITE = process.env.SITE || 'http://127.0.0.1:8080';
const USER = process.env.TMC_USER || 'tmcadmin';
const PASS = fs.readFileSync(process.env.TMC_PASS_FILE || '/root/.wp_pass', 'utf8').trim();
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const OUT = process.env.OUT || 'docs/evidence/redesign/run';
const LABEL = process.env.LABEL || 'run';
const WIDTHS = (process.env.WIDTHS || '375,412,600,768,1024,1440').split(',').map(Number);

const PAGES = [
  { slug: 'tmc-dashboard', name: 'پیشخوان' },
  { slug: 'tmc-health', name: 'سلامت' },
  { slug: 'tmc-settings', name: 'تنظیمات' },
  { slug: 'tmc-modules', name: 'ماژول‌ها' },
];

fs.mkdirSync(OUT, { recursive: true });

/** Runs in the page: what box did each piece of text actually get? */
const MEASURE = () => {
  const px = (v) => Math.round(v * 10) / 10;
  const root = document.querySelector('.tmc-admin');
  const lineCount = (el) => {
    const cs = getComputedStyle(el);
    let lh = parseFloat(cs.lineHeight);
    if (!Number.isFinite(lh)) lh = parseFloat(cs.fontSize) * 1.2;
    return Math.max(1, Math.round(el.getBoundingClientRect().height / lh));
  };
  const shortest = [];
  // Every leaf element that carries text: how many characters fit per line?
  for (const el of document.querySelectorAll('.tmc-admin *')) {
    if (el.children.length > 0) continue;
    const text = (el.textContent || '').trim();
    if (text.length < 4) continue;
    const r = el.getBoundingClientRect();
    if (r.width === 0) continue;
    const lines = lineCount(el);
    const perLine = text.length / lines;
    shortest.push({
      tag: el.tagName.toLowerCase(),
      cls: el.className || null,
      text: text.length > 40 ? text.slice(0, 40) + '…' : text,
      chars: text.length,
      width: px(r.width),
      height: px(r.height),
      lines,
      chars_per_line: Math.round(perLine * 10) / 10,
      stacked: perLine <= 2.2 && text.length >= 5,
    });
  }
  shortest.sort((a, b) => a.chars_per_line - b.chars_per_line);
  const cards = document.querySelector('.tmc-cards');
  const firstCard = document.querySelector('.tmc-card');
  const row = document.querySelector('.tmc-datalist__row');
  return {
    doc: { lang: document.documentElement.lang, dir: document.documentElement.dir },
    admin_width: root ? px(root.clientWidth) : null,
    wpbody_width: (() => { const b = document.querySelector('#wpbody-content'); return b ? px(b.clientWidth) : null; })(),
    cards_columns: cards ? getComputedStyle(cards).gridTemplateColumns : null,
    card_width: firstCard ? px(firstCard.getBoundingClientRect().width) : null,
    row_columns: row ? getComputedStyle(row).gridTemplateColumns : null,
    value_width: (() => { const v = document.querySelector('.tmc-datalist__row > :last-child'); return v ? px(v.getBoundingClientRect().width) : null; })(),
    horizontal_overflow: px(document.documentElement.scrollWidth - document.documentElement.clientWidth),
    worst: shortest.slice(0, 6),
    stacked_count: shortest.filter((s) => s.stacked).length,
  };
};

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
const loginCtx = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR' });
const loginPage = await loginCtx.newPage();
await loginPage.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
await loginPage.fill('#user_login', USER);
await loginPage.fill('#user_pass', PASS);
await Promise.all([loginPage.waitForURL(/wp-admin/, { timeout: 30000 }), loginPage.click('#wp-submit')]);
const storageState = await loginCtx.storageState();
await loginCtx.close();

const report = { label: LABEL, site: SITE, generated_at: new Date().toISOString(), runs: [] };
const lines = [];
for (const width of WIDTHS) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'fa-IR', storageState, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  for (const p of PAGES) {
    await page.goto(`${SITE}/wp-admin/admin.php?page=${p.slug}`, { waitUntil: 'load' });
    await page.waitForSelector('.tmc-admin', { timeout: 15000 });
    const shot = path.join(OUT, `${p.slug}-${width}.png`);
    await page.screenshot({ path: shot, fullPage: true });
    const m = await page.evaluate(MEASURE);
    report.runs.push({ page: p.slug, width, screenshot: path.basename(shot), ...m });
    const worst = m.worst[0];
    const line = `${String(width).padStart(4)}px  ${p.slug.padEnd(14)} card=${String(m.card_width).padStart(6)} value=${String(m.value_width).padStart(6)} overflow=${m.horizontal_overflow}  stacked=${m.stacked_count}` +
      (worst ? `  worst: "${worst.text}" ${worst.chars}ch in ${worst.lines} lines (${worst.chars_per_line}/line, ${worst.width}px)` : '');
    lines.push(line);
    console.log(line);
  }
  await ctx.close();
}
await browser.close();

report.stacked_total = report.runs.reduce((n, r) => n + r.stacked_count, 0);
fs.writeFileSync(path.join(OUT, `measure-${LABEL}.json`), JSON.stringify(report, null, 2) + '\n');
fs.writeFileSync(path.join(OUT, `measure-${LABEL}.txt`), lines.join('\n') + `\n\nstacked elements in total: ${report.stacked_total}\n`);
console.log(`\nstacked elements in total: ${report.stacked_total}`);
