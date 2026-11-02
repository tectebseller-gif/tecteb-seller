/*
 * ۰٫۳٫۴ — مدیریت «برچسب‌های کنار تصویر Hero» در «ووکامرس ← صفحهٔ اصلی»، با فرم واقعی، و اثرش روی صفحهٔ اصلی.
 *
 *   SITE=… WP="wp --allow-root --path=…" AXE=<axe.min.js> NODE_PATH=… OUT=tests/evidence/admin-hero-labels \
 *   node tests/browser/admin-hero-labels.mjs
 *
 * ویرایش عنوان، ترتیب، فعال/غیرفعال، انتخاب دسته از جست‌وجو و با شناسه، شناسهٔ نامعتبر، دستهٔ بی‌محصول، حفظ
 * بقیهٔ تنظیمات (از جمله برندها)، nonce و مجوز؛ و روی صفحهٔ اصلی در ۱۴۴۰ و ۳۹۰: عنوان، پیوند، ترتیب، و اینکه
 * برچسب‌ها روی متن و جست‌وجو نمی‌افتند. پیش‌نیاز: seed.php seed، state.php full.
 */
import { createRequire } from 'module';
import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const HERE = path.dirname(new URL(import.meta.url).pathname);
const PLUGIN = path.resolve(HERE, '../..');
const SITE = process.env.SITE || 'http://127.0.0.1:8090';
const WP = process.env.WP || 'wp --allow-root --path=/home/user/wp/core';
const AXE = process.env.AXE ? fs.readFileSync(process.env.AXE, 'utf8') : '';
const OUT = process.env.OUT || path.join(PLUGIN, 'tests/evidence/admin-hero-labels');
fs.mkdirSync(OUT, { recursive: true });
// فقط فرمان‌های ثابتِ wp-cli روی وردپرس یکبارمصرف محلی؛ هیچ ورودی بیرونی.
const wp = (cmd) => execSync(`${WP} ${cmd} 2>/dev/null`, { cwd: PLUGIN }).toString().trim();
const results = [];
const check = (name, ok, detail = '') => results.push(`${ok ? 'PASS' : 'FAIL'} ${name}${!ok && detail ? '  — ' + detail : ''}`);
const opt = () => JSON.parse(wp('eval-file tests/fixtures/hero-brands.php normalized'));
const PAGE = `${SITE}/wp-admin/admin.php?page=tecteb-homepage`;
const F = (i, k) => `input[name="tecteb_home_settings[hero_items][${i}][${k}]"]`;

wp('eval-file tests/fixtures/hero-brands.php reset');
wp('eval-file tests/fixtures/hero-brands.php labels');
wp('eval-file tests/fixtures/hero-brands.php brands 3');
wp('eval-file tests/fixtures/hero-brands.php editor');
wp('eval-file tests/fixtures/state.php front');
const labels0 = JSON.parse(wp('eval-file tests/fixtures/hero-brands.php show')).labels;
const term = Object.fromEntries(labels0.map((l) => [l.index, l.term]));
const before = opt();
const others = (o) => JSON.stringify(Object.keys(before).filter((k) => !['hero_items', 'hero_labels'].includes(k)).map((k) => [k, o[k]]));

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const login = async (ctx, user, pass) => { const p = await ctx.newPage(); await p.goto(`${SITE}/wp-login.php`); await p.fill('#user_login', user); await p.fill('#user_pass', pass); await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]); return p; };
const save = async (a, button = '#submit') => { await Promise.all([a.waitForNavigation(), a.click(button)]); };
// صفحهٔ اصلی بی ورود (مثل بازدیدکننده): برچسب‌ها، به ترتیب DOM، با پیوند و جایگاه.
const front = async (w = 1440, h = 900, shot = '') => {
  // جای «در حال سکون» (مثل hero-brands.mjs): حرکت شناورِ ۶px ِ دو برچسب پایین ناحیهٔ تخمینیِ پالس‌اکسیمتر را تا
  // ۵px لمس می‌کند ولی روی خودِ تصویر به آن نمی‌رسد (tests/evidence/admin-hero-labels/drift-peak-1440.png).
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, isMobile: w < 800, hasTouch: w < 800, reducedMotion: 'reduce' });
  const p = await ctx.newPage();
  await p.goto(`${SITE}/`, { waitUntil: 'networkidle' });
  await p.evaluate(() => document.fonts.ready);
  await p.waitForTimeout(1200); // انیمیشن ورود Hero
  const r = await p.evaluate(() => {
    const box = (e) => { const b = e.getBoundingClientRect(); return { l: b.left, r: b.right, t: b.top, b: b.bottom }; };
    const hit = (a, b) => a.l < b.r - 1 && a.r > b.l + 1 && a.t < b.b - 1 && a.b > b.t + 1;
    const keep = ['.th-hero__title', '.th-hero__lead', '.th-search'].map((s) => document.querySelector(s)).filter(Boolean).map(box);
    const fig = box(document.querySelector('.th-hero__art'));
    const fw = fig.r - fig.l; const fh = fig.b - fig.t;
    // همان ناحیه‌های تخمینیِ hero-brands.mjs (کسری از figure): نمایشگر و پالس‌اکسیمتر.
    const zone = ([x1, y1, x2, y2]) => ({ l: fig.l + x1 * fw, r: fig.l + x2 * fw, t: fig.t + y1 * fh, b: fig.t + y2 * fh });
    const compact = getComputedStyle(document.querySelector('.th-floats')).position === 'static';
    const all = [...document.querySelectorAll('.th-floats a')];
    return all.map((a) => {
      const b = box(a); const t = a.querySelector('.th-float__text');
      return {
        text: a.textContent.trim(), href: a.getAttribute('href'), cls: a.className,
        over: keep.some((k) => hit(b, k)),
        device: !compact && (hit(b, zone([0.34, 0.22, 0.78, 0.8])) || hit(b, zone([0.06, 0.71, 0.25, 0.86]))),
        overlap: all.some((o) => o !== a && hit(b, box(o))),
        inside: compact || (b.l >= fig.l - 1 && b.r <= fig.r + 1),
        truncated: !!t && t.scrollWidth > t.clientWidth + 1,
      };
    });
  });
  if (shot) await p.locator('.th-hero').screenshot({ path: path.join(OUT, shot) });
  await ctx.close();
  return r;
};
try {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const a = await login(ctx, 'tthadmin', 'local-only-2026');
  await a.goto(PAGE, { waitUntil: 'load' });
  check('admin: four rows with title, category ID, order and «فعال» fields', await a.locator('tr[data-tth-hero-label]').count() === 4 && (await Promise.all([0, 1, 2, 3].map(async (i) => (await a.locator(F(i, 'title')).count()) + (await a.locator(F(i, 'term')).count()) + (await a.locator(F(i, 'order')).count()) + (await a.locator(F(i, 'active')).count())))).every((n) => n === 4));
  check('admin: defaults are the four 0.3.3 titles, in order, all active', JSON.stringify(await Promise.all([0, 1, 2, 3].map((i) => a.inputValue(F(i, 'title'))))) === JSON.stringify(['پزشکی جنرال', 'بیهوشی و تنفسی', 'آزمایشگاه', 'جراحی']) && (await Promise.all([0, 1, 2, 3].map((i) => a.isChecked(F(i, 'active'))))).every(Boolean));
  const f0 = await front(1440, 900, 'front-before-1440.png');
  check('front: the four default labels, in order, each to its category archive', JSON.stringify(f0.map((x) => x.text)) === JSON.stringify(['پزشکی جنرال', 'بیهوشی و تنفسی', 'آزمایشگاه', 'جراحی']) && f0.every((x) => x.href.includes('product-category') || x.href.includes('product_cat')), JSON.stringify(f0));

  // عنوان.
  await a.fill(F(0, 'title'), 'تجهیزات عمومی');
  await save(a);
  let f = await front();
  check('admin → front: a new title shows on the homepage, still linked to the same archive', f[0].text === 'تجهیزات عمومی' && f[0].href === f0[0].href, JSON.stringify(f[0]));
  check('admin: the WooCommerce category keeps its own name (the title is independent)', wp(`eval 'echo get_term(${term[0]})->name;'`) === 'پزشکی جنرال');

  // ترتیب.
  for (const [i, o] of [[0, 4], [1, 3], [2, 2], [3, 1]]) await a.fill(F(i, 'order'), String(o));
  await save(a);
  f = await front(1440, 900, 'front-reordered-1440.png');
  check('admin → front: reversed order in the panel ⇒ reversed on the homepage, positions one…four', JSON.stringify(f.map((x) => x.text)) === JSON.stringify(['جراحی', 'آزمایشگاه', 'بیهوشی و تنفسی', 'تجهیزات عمومی']) && JSON.stringify(f.map((x) => x.cls)) === JSON.stringify(['th-float th-float--one', 'th-float th-float--two', 'th-float th-float--three', 'th-float th-float--four']), JSON.stringify(f));
  check('admin: after saving, the order fields read 4, 3, 2, 1', JSON.stringify(await Promise.all([0, 1, 2, 3].map((i) => a.inputValue(F(i, 'order'))))) === JSON.stringify(['4', '3', '2', '1']));

  // فعال/غیرفعال.
  await a.uncheck(F(1, 'active'));
  await save(a);
  f = await front();
  check('admin → front: unticking «فعال» hides that label and leaves no hole', !f.some((x) => x.text === 'بیهوشی و تنفسی') && f.length === 3 && JSON.stringify(f.map((x) => x.cls)) === JSON.stringify(['th-float th-float--one', 'th-float th-float--two', 'th-float th-float--three']), JSON.stringify(f));
  check('admin: the inactive row says so', (await a.locator('tr[data-tth-hero-label="1"]').getAttribute('data-tth-status')) === 'inactive' && (await a.locator('tr[data-tth-hero-label="1"]').textContent()).includes('غیرفعال'));
  await a.check(F(1, 'active'));
  await save(a);
  check('admin → front: ticking it again brings it back', (await front()).length === 4);

  // انتخاب دسته از جست‌وجو.
  await a.fill('#tth-hq', 'آزمایشگاه');
  await Promise.all([a.waitForNavigation(), a.click('#tth-hq-go')]);
  const pickBtn = a.locator(`button[name="tecteb_home_settings[hero_pick]"][value="3:${term[2]}"]`);
  check('admin: category search lists matches with «برای برچسب ۱…۴» buttons', await a.locator('#tth-hero-results tr').count() >= 1 && await pickBtn.count() === 1);
  await a.fill(F(3, 'title'), 'آزمایشگاه تشخیص طبی');
  await Promise.all([a.waitForNavigation(), pickBtn.click()]);
  check('admin: «برای برچسب ۴» saves that category in row 4 (and keeps the title typed beside it)', await a.inputValue(F(3, 'term')) === String(term[2]) && await a.inputValue(F(3, 'title')) === 'آزمایشگاه تشخیص طبی');
  f = await front();
  check('admin → front: row 4 (first in order) now links to «آزمایشگاه» with its own title', f[0].text === 'آزمایشگاه تشخیص طبی' && f[0].href === f0[2].href, JSON.stringify(f[0]));

  // شناسهٔ نامعتبر.
  await a.fill(F(2, 'term'), '99999999');
  await save(a);
  const notice = (await a.locator('.notice, .error, .settings-error').allTextContents()).join(' ');
  check('admin: an ID that is not a product category is refused with a message; the row goes back to «by name»', notice.includes('پیدا نشد') && await a.inputValue(F(2, 'term')) === '', notice);
  f = await front();
  check('front: …so «آزمایشگاه» (row 3) is again its own category, nothing guessed', f.some((x) => x.text === 'آزمایشگاه' && x.href === f0[2].href), JSON.stringify(f));

  // دستهٔ بی‌محصول.
  const empty = wp(`eval '$t = wp_insert_term("دستهٔ خالی آزمایشی مرورگر", "product_cat"); echo $t["term_id"];'`);
  await a.fill(F(0, 'term'), empty);
  await save(a);
  f = await front();
  check('admin → front: a chosen category with no displayable products stays hidden (and the panel says why)', !f.some((x) => x.text === 'تجهیزات عمومی') && (await a.locator('tr[data-tth-hero-label="0"]').getAttribute('data-tth-status')) === 'empty', JSON.stringify(f));
  await a.fill(F(0, 'term'), '');
  await save(a);
  wp(`eval 'wp_delete_term(${empty}, "product_cat");'`);

  const now = opt();
  check('admin: every other homepage setting (brands, links, products, categories…) untouched', others(now) === others(before), JSON.stringify(Object.keys(before).filter((k) => !['hero_items', 'hero_labels'].includes(k) && JSON.stringify(now[k]) !== JSON.stringify(before[k]))));
  check('admin: hero_labels (read by 0.3.3) mirrors each row\'s category', JSON.stringify(now.hero_labels) === JSON.stringify(now.hero_items.map((r) => r.term)));

  // عنوان‌های بلند (۴۰ نویسه، سقف فرم) در هر چهار ردیف: کوتاه‌شده با «…»، نه روی هم، نه روی دستگاه و متن.
  // دقیقاً ۴۰ نویسه (سقف فرم؛ هیچ نویسه‌ای بیرون از BMP نیست، پس length همان mb_strlen است).
  const long = ['تجهیزات پزشکی عمومی و مصرفی بیمارستانی و', 'دستگاه‌های بیهوشی، تنفسی و مراقبت ویژه بی', 'تجهیزات آزمایشگاه تشخیص طبی و پاتولوژی م', 'ابزار و تجهیزات اتاق عمل و جراحی عمومی ت'].map((t) => t.slice(0, 40));
  for (let i = 0; i < 4; i++) await a.fill(F(i, 'title'), long[i]);
  await save(a);
  check('admin: 40-character titles are saved whole', JSON.stringify(await Promise.all([0, 1, 2, 3].map((i) => a.inputValue(F(i, 'title'))))) === JSON.stringify(long));
  for (const [w, h] of [[1440, 900], [1024, 768], [768, 1024], [600, 900], [390, 844], [320, 700]]) {
    const m = await front(w, h, `front-long-${w}.png`);
    const bad = m.filter((x) => x.over || x.device || x.overlap || !x.inside);
    check(`${w} long titles: none over the text/search/device, none over each other, all inside the image`, m.length === 4 && bad.length === 0, JSON.stringify(bad));
    check(`${w} long titles: the link text stays whole (what screen readers read)`, long.every((t) => m.some((x) => x.text === t)), JSON.stringify(m.map((x) => x.text)));
    // جایی که برچسب‌ها روی تصویرند و تصویر باریک است (۱۴۴۰: ستون تصویر ~۴۵۰px) عنوان بلند با «…» کوتاه می‌شود؛
    // در ۷۶۸ تصویر ~۶۴۰px است و در ۱۰۲۴ برچسب‌ها زیر تصویرند، پس جا هست و کوتاه نمی‌شود.
    if (w === 1440) check(`${w} long titles: shortened on screen with «…» (they would not fit half the image)`, m.every((x) => x.truncated), JSON.stringify(m.map((x) => x.truncated)));
  }
  for (let i = 0; i < 4; i++) await a.fill(F(i, 'title'), ['تجهیزات عمومی', 'بیهوشی و تنفسی', 'آزمایشگاه', 'آزمایشگاه تشخیص طبی'][i]);
  await save(a);

  // موبایل: روی متن و جست‌وجو نمی‌افتند.
  for (const [w, h] of [[390, 844], [320, 700], [768, 1024], [1024, 768], [1440, 900]]) {
    const m = await front(w, h, `front-${w}.png`);
    check(`${w}: ${m.length} labels, none over the title, the lead, the search or the device`, m.length === 4 && m.every((x) => !x.over && !x.device && !x.overlap), JSON.stringify(m.filter((x) => x.over || x.device || x.overlap)));
  }

  await a.locator('#tth-hero-labels').scrollIntoViewIfNeeded();
  await a.screenshot({ path: path.join(OUT, 'admin-hero-labels.png') });
  if (AXE) {
    await a.addScriptTag({ content: AXE });
    const v = await a.evaluate(async () => (await axe.run(document.querySelector('#wpbody-content'), { runOnly: ['wcag2a', 'wcag2aa'] })).violations.map((x) => x.id + ':' + x.nodes.map((nn) => nn.target.join(' ')).slice(0, 2).join('|')));
    check('admin page (with search results): axe wcag2a/aa — 0 violations', v.length === 0, v.join(','));
  }

  // nonce و مجوز.
  const snap = JSON.stringify(opt());
  const res = await a.request.post(`${SITE}/wp-admin/options.php`, { form: { option_page: 'tecteb_home', action: 'update', _wpnonce: 'bad', 'tecteb_home_settings[hero_items][0][title]': 'نفوذی', 'tecteb_home_settings[hero_items][0][active]': '1' }, maxRedirects: 0 });
  check('security: a POST with a bad nonce is refused and changes nothing', res.status() >= 400 && JSON.stringify(opt()) === snap, String(res.status()));
  await ctx.close();
  const ectx = await browser.newContext();
  const e = await login(ectx, 'tth_editor', 'local-only-editor');
  const r1 = await e.goto(PAGE);
  const denied = r1.status() >= 400 || (await e.content()).includes('اجازه');
  const r2 = await e.request.post(`${SITE}/wp-admin/options.php`, { form: { option_page: 'tecteb_home', action: 'update', _wpnonce: 'none', 'tecteb_home_settings[hero_items][0][title]': 'نفوذی', 'tecteb_home_settings[hero_items][0][active]': '1' }, maxRedirects: 0 });
  check('security: a user without manage_woocommerce can neither open the page nor save', denied && r2.status() >= 400 && JSON.stringify(opt()) === snap, `${r1.status()} ${r2.status()}`);
  await ectx.close();
} finally {
  await browser.close();
  wp('eval-file tests/fixtures/hero-brands.php reset');
  wp('eval-file tests/fixtures/state.php unfront');
}
const pass = results.filter((r) => r.startsWith('PASS')).length;
fs.writeFileSync(path.join(OUT, 'results.txt'), results.join('\n') + `\n\n${pass} passed, ${results.length - pass} failed\n`);
console.log(results.join('\n') + `\n\n${pass} passed, ${results.length - pass} failed`);
process.exit(pass === results.length ? 0 : 1);
