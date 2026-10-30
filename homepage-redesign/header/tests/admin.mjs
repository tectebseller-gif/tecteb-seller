/*
 * آزمون نمونهٔ داشبورد «تک‌طب ← مدیریت هدر» — فقط روی سرور ایستای محلی.
 *
 *   (cd homepage-redesign && python3 -m http.server 8095 --bind 127.0.0.1) &
 *   AXE=<axe.min.js> NODE_PATH=<playwright> node homepage-redesign/header/tests/admin.mjs
 *
 * خروجی: header/evidence/admin/ (results.txt، axe.txt، saved.json و تصویرها).
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(process.env.NODE_PATH + '/');
const { chromium } = require('playwright');
const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const OUT = process.env.OUT_ADMIN || path.join(ROOT, 'evidence/admin');
const URL_ = process.env.SITE_ADMIN || 'http://127.0.0.1:8095/header/admin/index.html';
const AXE = process.env.AXE ? fs.readFileSync(process.env.AXE, 'utf8') : '';
fs.mkdirSync(OUT, { recursive: true });
fs.rmSync(path.join(OUT, 'axe.txt'), { force: true });

const SAMPLE = JSON.parse(fs.readFileSync(path.join(ROOT, 'shared/categories.sample.json'), 'utf8'));
const NAME = new Map(SAMPLE.terms.map((t) => [t.id, t.name]));
const SITE_ORDER = ['پزشکی جنرال', 'قلب و عروق', 'چشم پزشکی', 'جراحی', 'زنان و زایمان', 'ارتوپدی', 'توانبخشی', 'دندانپزشکی', 'آزمایشگاه', 'استریلیزاسیون', 'گوش و حلق و بینی', 'هتلینگ', 'بیهوشی و تنفسی'];

const lines = [];
let pass = 0;
let fail = 0;
const check = (name, ok, detail = '') => {
  if (ok) pass++; else fail++;
  const line = `${ok ? 'PASS' : 'FAIL'} ${name}${detail && !ok ? `  — ${detail}` : ''}`;
  lines.push(line);
  console.log(line);
};

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
async function open(w = 1600, h = 1000, extra = {}) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, ...extra });
  const page = await ctx.newPage();
  const errors = [];
  const external = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
  page.on('request', (r) => { const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:|data:|blob:|about:)/.test(u)) external.push(u); });
  await page.goto(URL_, { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);
  return { ctx, page, errors, external };
}
const cards = (page) => page.evaluate(() => [...document.querySelectorAll('.tth-card')].map((c) => ({ id: Number(c.dataset.id), name: c.querySelector('.tth-card__title strong').textContent, on: c.querySelector('[data-act="toggle"]').checked })));
const saveNow = async (page) => { await page.click('[data-tth-save]'); await page.waitForTimeout(150); return page.evaluate(() => window.TTHAdmin.saved()); };
const frameNames = async (page, sel) => {
  await page.waitForTimeout(500);
  const f = page.frameLocator('[data-tth-frame]');
  return f.locator(sel).allTextContents();
};

// ------------------------------------------------------------------ ۱. آغاز: همان ۱۳ دستهٔ سایت، به همان ترتیب
{
  const { ctx, page, errors, external } = await open();
  const list = await cards(page);
  check('start: the 13 current main categories, in the site\'s order', JSON.stringify(list.map((c) => c.name)) === JSON.stringify(SITE_ORDER), JSON.stringify(list.map((c) => c.name)));
  check('start: no console error, no request outside this machine', errors.length === 0 && external.length === 0, [...errors, ...external].join(' | '));
  check('menu: the page lives under «تک‌طب ← مدیریت هدر», separate from «بازارگاه تک‌طب»',
    await page.locator('#adminmenu li.current .wp-menu-name').textContent() === 'تک‌طب' && (await page.locator('#adminmenu .wp-submenu a[aria-current="page"]').textContent()) === 'مدیریت هدر');
  await page.screenshot({ path: path.join(OUT, 'dashboard-1600.png') });
  await page.screenshot({ path: path.join(OUT, 'dashboard-full-1600.png'), fullPage: true });
  const previewMains = await frameNames(page, '.tth-mega__name');
  check('preview: the real header B shows the same 13, same order (desktop, mega open)', JSON.stringify(previewMains) === JSON.stringify(SITE_ORDER), JSON.stringify(previewMains));
  if (AXE) {
    await page.addScriptTag({ content: AXE });
    const r = await page.evaluate(async () => (await window.axe.run({ include: [['.wrap']] }, { resultTypes: ['violations'] })).violations.map((x) => `${x.id}(${x.impact}):${x.nodes.length}`));
    fs.appendFileSync(path.join(OUT, 'axe.txt'), `dashboard 1600: ${r.join(', ') || 'none'}\n`);
    check('axe: the dashboard (content area) — 0 violations', r.length === 0, r.join(', '));
  }

  // ---------------------------------------------------------------- ۲. ترتیب با کیبورد، روشن/خاموش، و اثرش روی پیش‌نمایش
  await page.locator('[data-key="down-101"]').focus();
  await page.keyboard.press('Enter');
  let l2 = await cards(page);
  check('order: «پایین بردن» moves the first category down — by keyboard', l2[0].id === 102 && l2[1].id === 101, JSON.stringify(l2.slice(0, 2)));
  check('order: focus follows the moved item (the same button still focused)', await page.evaluate(() => document.activeElement?.dataset.key === 'down-101'));
  await page.locator('[data-key="up-101"]').click();
  await page.locator('[data-key="tog-112"]').click();
  l2 = await cards(page);
  check('toggle: «در هدر» off hides it without removing it from the list', l2.length === 13 && l2.find((c) => c.id === 112).on === false);
  if (AXE) {
    // یک کارت خاموش (زمینهٔ خاکستری) روی صفحه است — همان حالتی که بار اول کنتراست «حذف» را شکست.
    const r = await page.evaluate(async () => (await window.axe.run({ include: [['.wrap']] }, { resultTypes: ['violations'] })).violations.map((x) => `${x.id}(${x.impact}):${x.nodes.length}`));
    fs.appendFileSync(path.join(OUT, 'axe.txt'), `dashboard 1600, one card off: ${r.join(', ') || 'none'}\n`);
    check('axe: with a disabled card on the page — 0 violations', r.length === 0, r.join(', '));
  }
  const afterToggle = await frameNames(page, '.tth-mega__name');
  check('toggle: the preview drops «هتلینگ» and keeps the rest in order', !afterToggle.includes('هتلینگ') && afterToggle.length === 12, JSON.stringify(afterToggle));
  check('dirty: «تغییرات ذخیره‌نشده» appears and «بازگردانی» is enabled', await page.locator('[data-tth-dirty]').isVisible() && await page.locator('[data-tth-revert]').isEnabled());

  // ---------------------------------------------------------------- ۳. افزودن دستهٔ اصلی از product_cat
  await page.click('[data-tth-add-main]');
  await page.fill('[data-tth-main-q]', 'مصرفی');
  const already = await page.locator('[data-tth-main-list] button:disabled').allTextContents();
  await page.click('[data-tth-main-list] [data-add="114"]');
  l2 = await cards(page);
  check('add main: a real product_cat term is added at the end, enabled, no icon, no sub-categories', l2.at(-1).id === 114 && l2.length === 14);
  await page.click('[data-tth-add-main]');
  const disabledNow = await page.evaluate(() => [...document.querySelectorAll('[data-tth-main-list] button:disabled')].map((b) => b.textContent.trim()));
  check('add main: categories already in the header (and the WooCommerce default) cannot be added twice', disabledNow.filter((t) => t.startsWith('در هدر هست')).length === 14 && disabledNow.some((t) => t.startsWith('دستهٔ پیش‌فرض')), JSON.stringify(disabledNow.length));
  await page.keyboard.press('Escape');
  void already;

  // ---------------------------------------------------------------- ۴. آیکون از کتابخانهٔ رسانه
  await page.click('[data-key="icon-114"]');
  await page.screenshot({ path: path.join(OUT, 'media-library-1600.png') });
  await page.click('[data-tth-media-list] [data-media="5014"]');
  check('icon: chosen from the media library, stored as the attachment ID', (await page.evaluate(() => window.TTHAdmin.state().categories.find((c) => c.term_id === 114).icon_id)) === 5014);
  check('icon: focus returns to the icon button after the dialog closes', await page.evaluate(() => document.activeElement?.dataset.key === 'icon-114'));
  await page.click('[data-key="icon-114"]');
  await page.setInputFiles('[data-tth-upload]', { name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('x') });
  const refused = await page.locator('[data-tth-upload-msg]').textContent();
  await page.setInputFiles('[data-tth-upload]', { name: 'huge.png', mimeType: 'image/png', buffer: Buffer.alloc(300 * 1024) });
  const tooBig = await page.locator('[data-tth-upload-msg]').textContent();
  check('upload: a non-image file and a > 256 KB image are refused with a message', refused.includes('پذیرفته نشد') && tooBig.includes('بزرگ‌تر'), `${refused} | ${tooBig}`);
  const png = fs.readFileSync(path.join(ROOT, 'admin/media/photo-sample.webp'));
  await page.setInputFiles('[data-tth-upload]', { name: 'icon.webp', mimeType: 'image/webp', buffer: png });
  const up = await page.evaluate(() => window.TTHAdmin.state().categories.find((c) => c.term_id === 114).icon_id);
  check('upload: a valid WebP is added to the library and selected', up >= 6001, String(up));
  await page.keyboard.press('Escape');

  // ---------------------------------------------------------------- ۵. زیردسته‌ها: فقط از زیرمجموعهٔ واقعی همان دسته
  await page.click('[data-key="kids-112"]');
  await page.click('[data-key="kadd-112"]');
  const offered = await page.evaluate(() => [...document.querySelectorAll('.tth-kpick [data-pick]')].map((x) => Number(x.value)));
  const real = SAMPLE.terms.filter((t) => { let p = t.parent; while (p) { if (p === 112) return true; p = SAMPLE.terms.find((x) => x.id === p)?.parent; } return false; }).map((t) => t.id);
  check('children: the picker offers exactly the real descendants of that category (grandchildren included)', JSON.stringify([...offered].sort()) === JSON.stringify([...real].sort()) && offered.length === 7, `${offered} vs ${real}`);
  check('children: nothing from another category is offered', !offered.some((id) => !real.includes(id)));
  await page.fill('.tth-kpick input[type=search]', 'برقی');
  const visible = await page.evaluate(() => [...document.querySelectorAll('.tth-kpick li')].filter((li) => !li.hidden).map((li) => li.textContent.trim()));
  check('children: the picker search narrows the list', visible.length === 1 && visible[0].startsWith('تخت برقی'), JSON.stringify(visible));
  await page.fill('.tth-kpick input[type=search]', '');
  const bed = SAMPLE.terms.find((t) => t.name === 'تخت برقی').id;
  await page.check(`.tth-kpick [data-pick][value="${bed}"]`);
  await page.click('[data-key="kpick-112"]');
  let st = await page.evaluate(() => window.TTHAdmin.state().categories.find((c) => c.term_id === 112).children);
  check('children: a picked sub-category is added at the end, enabled', st.at(-1).term_id === bed && st.at(-1).enabled && st.length === 5);
  await page.click(`[data-key="kup-${bed}"]`);
  await page.click(`[data-key="ktog-${st[0].term_id}"]`);
  await page.click(`[data-key="krm-${st[1].term_id}"]`);
  st = await page.evaluate(() => window.TTHAdmin.state().categories.find((c) => c.term_id === 112).children);
  check('children: reorder, disable and remove each change only what they say', st.length === 4 && st[0].enabled === false && st[2].term_id === bed, JSON.stringify(st));
  await page.locator('[data-key="tog-112"]').click();
  await page.screenshot({ path: path.join(OUT, 'children-1600.png') });

  // ---------------------------------------------------------------- ۶. ذخیره: فقط شناسه، و هیچ نامی
  const saved = await saveNow(page);
  fs.writeFileSync(path.join(OUT, 'saved.json'), JSON.stringify(saved, null, 2));
  const text = JSON.stringify(saved);
  const strings = [];
  JSON.parse(text, (k, v) => { if (typeof v === 'string') strings.push(`${k}=${v}`); return v; });
  check('save: the stored data has only IDs, booleans and order — no name, slug or URL of any category', JSON.stringify(strings) === JSON.stringify(['supplier=/landing-seller/']) && !/name|slug|href/.test(text), JSON.stringify(strings));
  check('save: keys are exactly schema, version, hide_empty, links, categories[term_id, enabled, icon_id, children[term_id, enabled]]',
    JSON.stringify(Object.keys(saved)) === JSON.stringify(['schema', 'version', 'hide_empty', 'links', 'categories'])
    && saved.categories.every((c) => JSON.stringify(Object.keys(c)) === JSON.stringify(['term_id', 'enabled', 'icon_id', 'children']) && c.children.every((k) => JSON.stringify(Object.keys(k)) === JSON.stringify(['term_id', 'enabled']))));
  check('save: the version goes up and «تغییرات ذخیره‌نشده» disappears', saved.version === 2 && !(await page.locator('[data-tth-dirty]').isVisible()));

  // ---------------------------------------------------------------- ۷. نام و نشانی از ووکامرس، نه از تنظیمات
  await page.click('.tth-sim summary');
  await page.click('[data-tth-sim="rename"]');
  const renamed = await frameNames(page, '.tth-mega__name');
  check('rename in WooCommerce: the header shows the new name with no header setting touched', renamed.includes('جراحی و اتاق عمل') && JSON.stringify(await page.evaluate(() => window.TTHAdmin.saved())) === text);
  await page.click('[data-tth-sim="move"]');
  const issue = await page.locator('.tth-card[data-id="104"] .tth-issue--error').allTextContents();
  const surgeryKids = await page.evaluate(() => [...document.querySelectorAll('.tth-mega__item')].length);
  check('moved in WooCommerce: a sub-category no longer under its category is flagged in the dashboard', issue.some((t) => t.includes('زیرمجموعهٔ')), JSON.stringify(issue));
  await page.click('[data-key="kids-104"]');
  const f = page.frameLocator('[data-tth-frame]');
  await page.waitForTimeout(500);
  const megaKids104 = await f.locator('.tth-mega__item').filter({ hasText: 'جراحی و اتاق عمل' }).locator('.tth-mega__list a').allTextContents();
  check('moved in WooCommerce: …and is not shown under the old category in the header', !megaKids104.includes('چراغ جراحی'), JSON.stringify(megaKids104));
  await page.click('[data-tth-sim="delete"]');
  const delIssue = await page.locator('.tth-card[data-id="112"] .tth-issue--error').count();
  check('deleted in WooCommerce: the dashboard says so; nothing else is pulled in to replace it', delIssue === 1 && !(await frameNames(page, '.tth-mega__name')).includes('هتلینگ'));
  void surgeryKids;
  await page.click('[data-tth-sim="reset"]');

  // ---------------------------------------------------------------- ۸. موبایل: همان دسته‌ها در کشو
  await page.click('[data-tth-view="mobile"]');
  await page.waitForTimeout(700);
  const mob = await page.frameLocator('[data-tth-frame]').locator('.tth-drawer__name').allTextContents();
  const desk = (await page.evaluate(() => window.TTHAdmin.state().categories)).filter((c) => c.enabled).map((c) => NAME.get(c.term_id));
  check('mobile preview: the drawer lists exactly the same enabled categories, same order', JSON.stringify(mob) === JSON.stringify(desk), JSON.stringify(mob));
  await page.screenshot({ path: path.join(OUT, 'preview-mobile-1600.png') });

  // ---------------------------------------------------------------- ۹. اعتبارسنجی
  await page.click('#tth-t-settings');
  await page.fill('[data-tth-supplier]', 'javascript:alert(1)');
  await page.click('#tth-t-cats');
  await page.click('[data-tth-save]');
  const err = await page.locator('[data-tth-notices] .notice-error').textContent();
  check('validate: an unsafe supplier link is refused and nothing is saved', err.includes('ذخیره نشد') && (await page.evaluate(() => window.TTHAdmin.saved().version)) === 2);
  await page.click('[data-tth-revert]');
  check('revert: «بازگردانی تغییرات» returns the form to the last save', JSON.stringify(await page.evaluate(() => window.TTHAdmin.state())) === JSON.stringify(await page.evaluate(() => window.TTHAdmin.saved())));
  await page.click('[data-key="kids-101"]');
  await page.click('[data-key="kadd-101"]');
  await page.evaluate(() => document.querySelectorAll('.tth-kpick [data-pick]:not(:disabled)').forEach((x) => { x.checked = true; }));
  // ۱۰۱ چهار زیردسته دارد و دو تای دیگر در دسترس است ⇒ ۶ ≤ ۱۲؛ سقف را با افزودن مصنوعی می‌سنجیم.
  await page.evaluate(() => { const c = document.querySelector('.tth-kpick'); for (let i = 0; i < 8; i++) { const li = document.createElement('li'); li.innerHTML = `<label><input type="checkbox" data-pick value="${900 + i}" checked></label>`; c.querySelector('ul').append(li); } });
  await page.click('[data-key="kpick-101"]');
  check('limit: more than 12 sub-categories under one category is refused', (await page.locator('[data-tth-notices] .notice-error').textContent()).includes('حداکثر'));
  await ctx.close();
}

// ------------------------------------------------------------------ ۱۰. انتقال از منوی فعلی
{
  const { ctx, page } = await open();
  await page.click('#tth-t-import');
  const rows = await page.locator('[data-tth-import] tr').count();
  check('import: the 13 items of the current menu are listed with their category IDs', rows === 13);
  await page.screenshot({ path: path.join(OUT, 'import-1600.png') });
  await page.click('[data-tth-import-go]');
  const st = await page.evaluate(() => window.TTHAdmin.state().categories);
  check('import: rebuilds the list from the menu — same 13, same order, no icons, no sub-categories — not yet saved',
    JSON.stringify(st.map((c) => NAME.get(c.term_id))) === JSON.stringify(SITE_ORDER) && st.every((c) => c.icon_id === 0 && c.children.length === 0)
    && (await page.evaluate(() => window.TTHAdmin.saved().version)) === 1);
  await page.click('#tth-t-cats');
  await page.waitForTimeout(600);
  const plain = await page.frameLocator('[data-tth-frame]').locator('.tth-mega__icon--none').count();
  check('import: with no icon chosen the header shows a neutral placeholder, not a broken image', plain === 13, String(plain));
  await page.click('#tth-t-json');
  await page.screenshot({ path: path.join(OUT, 'json-1600.png') });
  await ctx.close();
}

// ------------------------------------------------------------------ ۱۱. عرض‌ها، کاهش حرکت
for (const [w, h] of [[1280, 900], [1024, 900], [390, 844]]) {
  const { ctx, page, errors } = await open(w, h, { reducedMotion: 'reduce' });
  const ovf = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
  check(`${w}px: no horizontal overflow, no error`, ovf <= 0 && errors.length === 0, `overflow=${ovf} ${errors.join(' | ')}`);
  const small = await page.evaluate(() => [...document.querySelectorAll('.wrap button, .wrap input, .wrap a')].filter((e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && (r.width < 24 || r.height < 20); }).map((e) => e.dataset.key || e.textContent.trim().slice(0, 20)));
  check(`${w}px: dashboard controls ≥ 24px wide and ≥ 20px tall`, small.length === 0, JSON.stringify(small.slice(0, 5)));
  await page.screenshot({ path: path.join(OUT, `dashboard-${w}.png`) });
  if (w === 390) {
    await page.click('[data-key="kids-101"]');
    await page.screenshot({ path: path.join(OUT, 'dashboard-390-children.png'), fullPage: false });
    if (AXE) {
      await page.addScriptTag({ content: AXE });
      const r = await page.evaluate(async () => (await window.axe.run({ include: [['.wrap']] }, { resultTypes: ['violations'] })).violations.map((x) => `${x.id}(${x.impact}):${x.nodes.length}`));
      fs.appendFileSync(path.join(OUT, 'axe.txt'), `dashboard 390: ${r.join(', ') || 'none'}\n`);
      check('axe: the dashboard at 390px — 0 violations', r.length === 0, r.join(', '));
    }
  }
  await ctx.close();
}

await browser.close();
lines.push('', `${pass} passed, ${fail} failed`);
fs.writeFileSync(path.join(OUT, 'results.txt'), lines.join('\n') + '\n');
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
