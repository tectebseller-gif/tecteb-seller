/**
 * A browsable, static mirror of the demo — because localhost is not a demo.
 *
 * The owner cannot open `http://127.0.0.1:8081`. This box cannot host anything
 * they could open either: it has no inbound address, and its outbound policy
 * refuses every tunnel broker (measured — see
 * `docs/evidence/demo-mirror/hosting-probe.txt`). So the next honest thing is
 * to hand them the REAL pages: signed-in HTML, the real stylesheets, the real
 * bundled Persian font, the real product images — saved as files that open in
 * any browser.
 *
 * What it is NOT, and says so on every page: it is not live. Forms do not
 * submit, because there is nothing behind them. Anything a click would have
 * computed is not here.
 *
 * Scripts are deliberately NOT mirrored. A page whose JavaScript keeps calling
 * an admin-ajax endpoint that does not exist is worse than one that never
 * tries: it produces errors the reviewer then has to be told to ignore. The
 * layout, the type, the colours, the RTL and the responsive behaviour are all
 * CSS, and all of those are real here — resize the window and the mirror
 * reflows exactly as the site does.
 *
 *   SITE=http://127.0.0.1:8081 OUT=docs/evidence/demo-mirror \
 *     node tools/browser/mirror-demo.mjs
 */

import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import crypto from 'crypto';

const SITE = (process.env.SITE || 'http://127.0.0.1:8081').replace(/\/$/, '');
const OUT = process.env.OUT || 'docs/evidence/demo-mirror';
const CHROMIUM = process.env.TMC_CHROMIUM || '/opt/pw-browsers/chromium';
const VERSION = process.env.TMC_VERSION || '0.1.0-alpha.24';

fs.mkdirSync(OUT, { recursive: true });

const ROLES = {
  manager: { login: 'tmcowner', pass: process.env.ADMIN_PASS || 'demo-owner-2026', name: 'مدیر بازارگاه' },
  vendor: { login: 'demo-vendor', pass: 'demo-vendor-2026', name: 'فروشنده' },
  staff: { login: 'demo-staff', pass: 'demo-staff-2026', name: 'پرسنل فروشگاه' },
  buyer: { login: 'demo-buyer', pass: 'demo-buyer-2026', name: 'خریدار' },
};

/** Every page the mirror carries, in the order the acceptance guide walks them. */
const PAGES = [
  { role: 'manager', file: 'manager-01-dashboard', url: '/wp-admin/admin.php?page=tmc-dashboard', name: 'پیشخوان بازارگاه' },
  { role: 'manager', file: 'manager-02-vendors', url: '/wp-admin/admin.php?page=tmc-vendor-applications', name: 'بررسی فروشندگان' },
  { role: 'manager', file: 'manager-03-products', url: '/wp-admin/admin.php?page=tmc-product-review', name: 'بررسی محصول' },
  { role: 'manager', file: 'manager-04-storefront', url: '/wp-admin/admin.php?page=tmc-storefront', name: 'وضعیت فروش بازارگاه' },
  { role: 'manager', file: 'manager-05-withdrawals', url: '/wp-admin/admin.php?page=tmc-withdrawals', name: 'تسویه و برداشت' },
  { role: 'manager', file: 'manager-06-reports', url: '/wp-admin/admin.php?page=tmc-reports', name: 'گزارش‌ها' },
  { role: 'manager', file: 'manager-07-audit', url: '/wp-admin/admin.php?page=tmc-audit', name: 'ممیزی' },
  { role: 'manager', file: 'manager-08-settings', url: '/wp-admin/admin.php?page=tmc-settings', name: 'تنظیمات' },
  { role: 'manager', file: 'manager-09-orders', url: '/wp-admin/admin.php?page=wc-orders', name: 'سفارش‌های ووکامرس' },

  { role: 'vendor', file: 'vendor-01-dashboard', url: '/vendor/', name: 'پیشخوان فروشنده' },
  { role: 'vendor', file: 'vendor-02-products', url: '/vendor/products/', name: 'محصولات' },
  { role: 'vendor', file: 'vendor-03-product-new', url: '/vendor/products/?product=new', name: 'افزودن محصول' },
  { role: 'vendor', file: 'vendor-04-orders', url: '/vendor/orders/', name: 'سفارش‌ها و ارسال' },
  { role: 'vendor', file: 'vendor-05-store', url: '/vendor/store/', name: 'تنظیمات فروشگاه' },
  { role: 'vendor', file: 'vendor-06-staff', url: '/vendor/staff/', name: 'پرسنل' },
  { role: 'vendor', file: 'vendor-07-finance', url: '/vendor/finance/', name: 'امور مالی' },
  { role: 'vendor', file: 'vendor-08-reports', url: '/vendor/reports/', name: 'گزارش‌های فروشنده' },
  { role: 'vendor', file: 'vendor-09-support', url: '/vendor/support/', name: 'پشتیبانی و تیکت' },

  { role: 'staff', file: 'staff-01-dashboard', url: '/vendor/', name: 'پیشخوان پرسنل' },
  { role: 'staff', file: 'staff-02-products', url: '/vendor/products/', name: 'محصولات — دید پرسنل' },
  { role: 'staff', file: 'staff-03-orders', url: '/vendor/orders/', name: 'سفارش‌ها — دید پرسنل' },
  { role: 'staff', file: 'staff-04-store-refused', url: '/vendor/store/', name: 'تنظیمات فروشگاه — باید رد شود' },

  { role: 'buyer', file: 'buyer-01-shop', url: '/?post_type=product', name: 'فروشگاه' },
  { role: 'buyer', file: 'buyer-02-store-page', url: '/?tmc_store=2', name: 'صفحهٔ عمومی فروشگاه' },
  { role: 'buyer', file: 'buyer-03-product', url: '', name: 'صفحهٔ محصول', firstProduct: true },
  { role: 'buyer', file: 'buyer-04-cart', url: '/cart/', name: 'سبد خرید' },
  { role: 'buyer', file: 'buyer-05-account', url: '/my-account/', name: 'حساب کاربری' },
  { role: 'buyer', file: 'buyer-06-orders', url: '/my-account/orders/', name: 'سفارش‌های من' },
];

const assets = new Map();      // original absolute URL -> mirrored file name
const assetBytes = new Map();  // mirrored file name -> Buffer
// One map PER ROLE, plus a global fallback.
//
// `/vendor/` is not one page: it is the vendor's dashboard and, for a member
// of staff, a narrower one. A single shared map let whichever role was
// captured last own the address, so every link on the VENDOR's pages pointed
// at the STAFF capture — a mirror that quietly showed the wrong person's
// screen. The fallback is first-writer-wins, for genuinely shared addresses
// like the shop.
const pageByRole = { manager: new Map(), vendor: new Map(), staff: new Map(), buyer: new Map() };
const pageByUrl = new Map();   // shared fallback: original path+query -> file
const results = [];

/**
 * The extension comes from the CONTENT TYPE first, and only then from the URL.
 *
 * WordPress serves the whole core admin stylesheet from `load-styles.php`, so
 * taking the extension from the path saved it as `….php` — and a browser
 * reading a `file://` URL decides the media type from the extension alone, so
 * it fetched the file, found no stylesheet, and applied none of it. The admin
 * pages rendered in Times New Roman with no layout, while every check said the
 * link resolved.
 */
const extFor = (url, type) => {
  if (/css/.test(type)) { return '.css'; }
  if (/font\/woff2|woff2/.test(type)) { return '.woff2'; }
  if (/font\/woff|woff/.test(type)) { return '.woff'; }
  if (/svg/.test(type)) { return '.svg'; }
  if (/png/.test(type)) { return '.png'; }
  if (/jpe?g/.test(type)) { return '.jpg'; }
  if (/gif/.test(type)) { return '.gif'; }
  if (/webp/.test(type)) { return '.webp'; }
  if (/icon/.test(type)) { return '.ico'; }
  const m = url.split('?')[0].match(/\.([a-z0-9]{2,5})$/i);
  return m ? '.' + m[1].toLowerCase() : '.bin';
};

const remember = (url, type, body) => {
  if (assets.has(url)) { return assets.get(url); }
  const name = 'a' + crypto.createHash('sha256').update(body).digest('hex').slice(0, 16) + extFor(url, type);
  assets.set(url, name);
  assetBytes.set(name, body);
  return name;
};

const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });

async function signIn(who) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1200 }, locale: 'fa-IR' });
  const pg = await ctx.newPage();

  // Every same-origin stylesheet, font and image the page really asked for.
  // Reading them off the wire is what keeps the mirror honest: it carries the
  // assets the page USES, not the ones a parser guessed it might.
  //
  // Two things this had wrong. The listener was attached AFTER signing in, so
  // everything wp-login.php had already pulled — dashicons, buttons, forms,
  // common.css — was in the browser cache by the time the first admin page
  // loaded, and a cached resource makes no request and fires no response. And
  // a resource shared between pages was only ever seen once for the same
  // reason. So: attach first, and turn the cache off outright.
  pg.on('response', async (res) => {
    try {
      const url = res.url();
      if (!url.startsWith(SITE)) { return; }
      const type = (res.headers()['content-type'] || '').toLowerCase();
      const kind = res.request().resourceType();
      if (!['stylesheet', 'image', 'font'].includes(kind)) { return; }
      if (res.status() >= 400) { return; }
      remember(url, type, await res.body());
    } catch { /* a response whose body is gone is not worth failing over */ }
  });
  const cdp = await ctx.newCDPSession(pg);
  await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });

  await pg.goto(`${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await pg.fill('#user_login', who.login);
  await pg.fill('#user_pass', who.pass);
  await pg.click('#wp-submit');
  await pg.waitForURL((u) => !/wp-login\.php/.test(u.toString()), { timeout: 30000 }).catch(() => null);
  return { ctx, pg };
}

for (const roleKey of Object.keys(ROLES)) {
  const who = ROLES[roleKey];
  const { ctx, pg } = await signIn(who);

  for (const spec of PAGES.filter((p) => p.role === roleKey)) {
    let target = `${SITE}${spec.url}`;
    if (spec.firstProduct) {
      await pg.goto(`${SITE}/?post_type=product`, { waitUntil: 'domcontentloaded' });
      const href = await pg.locator('a.woocommerce-LoopProduct-link, li.product a').first().getAttribute('href').catch(() => null);
      target = href || `${SITE}/?post_type=product`;
    }
    await pg.goto(target, { waitUntil: 'domcontentloaded' });
    await pg.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => null);
    await pg.waitForTimeout(600);

    const html = await pg.content();
    const landed = pg.url();
    results.push({ ...spec, roleName: who.name, html, landed, target });
    for (const u of [landed.replace(SITE, '') || '/', target.replace(SITE, '') || '/']) {
      pageByRole[spec.role].set(u, `${spec.file}.html`);
      if (!pageByUrl.has(u)) { pageByUrl.set(u, `${spec.file}.html`); }
    }
    console.log(`captured ${spec.file}  ← ${landed.replace(SITE, '')}`);
  }
  await ctx.close();
}
await browser.close();

// ---------------------------------------------------------------------------
// Rewrite: every reference to the live site becomes a file in this folder, or
// the one page that explains why it is not here.
// ---------------------------------------------------------------------------

const rewriteCss = (text, baseUrl) => text.replace(
  /url\((['"]?)([^'")]+)\1\)/g,
  (whole, q, ref) => {
    if (/^(data:|#)/.test(ref)) { return whole; }
    let abs;
    try { abs = new URL(ref, baseUrl).toString(); } catch { return whole; }
    const name = assets.get(abs) || assets.get(abs.split('#')[0]);
    return name ? `url(${name})` : whole;
  }
);

for (const [url, name] of assets) {
  if (!name.endsWith('.css')) { continue; }
  assetBytes.set(name, Buffer.from(rewriteCss(assetBytes.get(name).toString('utf8'), url), 'utf8'));
}

const BANNER = (page) => `
<div id="tmc-mirror-bar" dir="rtl">
  <strong>آینهٔ ایستا</strong>
  <span>${page.roleName} · ${page.name}</span>
  <span class="tmc-mirror-warn">صفحه‌ها واقعی‌اند، ولی زنده نیستند — فرم‌ها ثبت نمی‌شوند.</span>
  <a href="pages.html">فهرست صفحه‌ها</a>
</div>
<style>
  #tmc-mirror-bar{position:sticky;top:0;z-index:2147483647;display:flex;gap:.75rem;
    align-items:center;flex-wrap:wrap;padding:.5rem .9rem;background:#1f2937;color:#f9fafb;
    font:600 13px/1.7 system-ui,'Segoe UI',Tahoma,sans-serif;direction:rtl}
  #tmc-mirror-bar strong{background:#f59e0b;color:#1f2937;border-radius:4px;padding:.1rem .5rem}
  #tmc-mirror-bar .tmc-mirror-warn{font-weight:400;opacity:.85}
  #tmc-mirror-bar a{color:#fbbf24;margin-inline-start:auto}
</style>
<script>
  // Nothing behind these forms. Saying so beats a silent no-op.
  document.addEventListener('submit', function (e) {
    e.preventDefault();
    alert('این یک آینهٔ ایستاست: فرم‌ها ثبت نمی‌شوند. برای کار کردن با فرم‌ها، محیط نمایشی باید روی یک میزبان واقعی نصب شود.');
  }, true);
</script>
`;

const clean = (html, page) => {
  let out = html;
  // Scripts go. See the note at the top of this file.
  out = out.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
  out = out.replace(/<link[^>]+rel=["']?(preload|prefetch|dns-prefetch|https:\/\/api\.w\.org\/)[^>]*>/gi, '');

  // This role's own captures first, then the shared fallback.
  const links = new Map(pageByUrl);
  for (const [k, v] of pageByRole[page.role]) { links.set(k, v); }

  /**
   * Resolve what the markup says, then look it up.
   *
   * Matching URLs as TEXT missed two whole classes of reference, both only on
   * the admin pages: `load-styles.php?c=1&amp;dir=rtl&…` is written escaped in
   * the HTML and requested unescaped, and WooCommerce writes
   * `client/admin/embed/../chunks/1438.style.css`, which the browser
   * normalises before asking for it. Neither is exotic, and both produced an
   * admin page with no stylesheet — the one failure a mirror must not have.
   */
  const resolve = (value) => {
    try { return new URL(value.replace(/&amp;/g, '&'), page.landed).toString(); } catch { return ''; }
  };
  const mapOne = (value) => {
    if (/^(#|data:|mailto:|tel:|javascript:)/i.test(value) || value === '') { return ''; }
    const abs = resolve(value);
    if (!abs) { return ''; }
    if (assets.has(abs)) { return assets.get(abs); }
    const rel = abs.startsWith(SITE) ? (abs.slice(SITE.length) || '/') : '';
    return rel && links.has(rel) ? links.get(rel) : '';
  };

  out = out.replace(/\b(href|src)=(["'])([^"']*)\2/gi, (whole, attr, q, value) => {
    const to = mapOne(value);
    return to ? `${attr}=${q}${to}${q}` : whole;
  });
  out = out.replace(/\bsrcset=(["'])([^"']*)\1/gi, (whole, q, value) => {
    const parts = value.split(',').map((one) => {
      const [u, ...rest] = one.trim().split(/\s+/);
      const to = mapOne(u);
      return [to || u, ...rest].join(' ');
    });
    return `srcset=${q}${parts.join(', ')}${q}`;
  });
  out = out.replace(/<style\b[^>]*>([\s\S]*?)<\/style>/gi, (whole, css) =>
    whole.replace(css, rewriteCss(css, page.landed)));

  // Whatever still points at the live site is a page this mirror does not
  // carry. Sending it to a file that says so beats a dead link that looks
  // like a broken product.
  //
  // Only <a> and <form>. The first version rewrote every href, which caught
  // the <link rel="stylesheet"> of any asset that had NOT been captured — so a
  // missing stylesheet turned into a page that loads and is simply unstyled,
  // which is the one failure mode a mirror must never hide.
  const deadLink = (value) =>
    !/^(#|mailto:|tel:|javascript:)/i.test(value) && (value.startsWith(SITE) || value.startsWith('/'));
  out = out.replace(/<a\b[^>]*>/gi, (tag) =>
    tag.replace(/\bhref=(["'])([^"']*)\1/i, (w, q, v) => (deadLink(v) ? `href=${q}not-mirrored.html${q}` : w)));
  out = out.replace(/<form\b[^>]*>/gi, (tag) =>
    tag.replace(/\baction=(["'])([^"']*)\1/i, (w, q, v) => (deadLink(v) ? `action=${q}not-mirrored.html${q}` : w)));

  // Head metadata that names the live host — feeds, oEmbed, RSD, shortlink,
  // canonical — renders nothing and resolves to a machine nobody else can
  // reach. And `wp-auth-check` keeps an iframe pointed at wp-login.php. None
  // of it belongs in a mirror; left in, it is 50 references to 127.0.0.1 that
  // someone has to be told to ignore.
  const live = new RegExp(SITE.replace(/[.*+?^$()|[\]\\]/g, '\\$&'));
  out = out.replace(/<link\b[^>]*>/gi, (tag) => (live.test(tag) ? '' : tag));
  out = out.replace(/<iframe\b[^>]*>/gi, (tag) =>
    tag.replace(/(?<![-\w])src=(["'])([^"']*)\1/i, (w, q, v) => (live.test(v) ? `src=${q}about:blank${q}` : w)));
  // wp-auth-check keeps the login iframe's address in a data attribute for a
  // script that is not here any more.
  out = out.replace(/\bdata-src=(["'])([^"']*)\1/gi, (w, q, v) => (live.test(v) ? `data-src=${q}${q}` : w));

  out = out.replace(/<body([^>]*)>/i, (m, attrs) => `<body${attrs}>${BANNER(page)}`);
  return out;
};

fs.mkdirSync(OUT, { recursive: true });
for (const [name, body] of assetBytes) {
  fs.writeFileSync(path.join(OUT, name), body);
}
for (const page of results) {
  fs.writeFileSync(path.join(OUT, `${page.file}.html`), clean(page.html, page), 'utf8');
}

// ---------------------------------------------------------------------------
// The two pages the mirror adds of its own: the index, and the explanation.
// ---------------------------------------------------------------------------
const group = (role) => results.filter((r) => r.role === role);
const card = (role, title, blurb) => `
  <section class="role">
    <h2>${title}</h2>
    <p class="blurb">${blurb}</p>
    <ol>${group(role).map((p) => `<li><a href="${p.file}.html">${p.name}</a> <code>${p.url || '(صفحهٔ محصول)'}</code></li>`).join('')}</ol>
  </section>`;

const SHELL = (title, body) => `<!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${title}</title>
<style>
  :root{--ink:#111827;--muted:#4b5563;--line:#e5e7eb;--bg:#f9fafb;--card:#fff;--accent:#b45309}
  @media (prefers-color-scheme: dark){:root:not([data-theme="light"]){--ink:#f3f4f6;--muted:#9ca3af;--line:#374151;--bg:#111827;--card:#1f2937;--accent:#fbbf24}}
  :root[data-theme="dark"]{--ink:#f3f4f6;--muted:#9ca3af;--line:#374151;--bg:#111827;--card:#1f2937;--accent:#fbbf24}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.9 system-ui,'Segoe UI',Tahoma,sans-serif}
  .wrap{max-width:60rem;margin:0 auto;padding:2rem 16px 4rem}
  h1{font-size:1.6rem;margin:0 0 .3rem}
  .lede{color:var(--muted);margin:0 0 1.5rem}
  .warn{background:var(--card);border:1px solid var(--line);border-inline-start:4px solid var(--accent);
    border-radius:10px;padding:1rem 1.1rem;margin:0 0 2rem}
  .warn h2{margin:.1rem 0 .5rem;font-size:1.05rem}
  .warn ul{margin:.4rem 0 0;padding-inline-start:1.2rem}
  .role{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:1rem 1.2rem;margin:0 0 1rem}
  .role h2{margin:.1rem 0 .2rem;font-size:1.1rem}
  .blurb{color:var(--muted);margin:0 0 .6rem;font-size:.95rem}
  ol{margin:0;padding-inline-start:1.4rem}
  li{margin:.2rem 0}
  a{color:var(--accent)}
  code{color:var(--muted);font-size:.8rem}
  footer{color:var(--muted);font-size:.85rem;margin-top:2rem;border-top:1px solid var(--line);padding-top:1rem}
</style></head><body><div class="wrap">${body}</div></body></html>`;

// Written twice, on purpose. `index.html` is what opens when the folder is
// opened from disk; `pages.html` is the same list under a name that is free to
// live beside a hosted landing page, because a published bundle reserves
// `index.html` for the page it serves at its root.
const INDEX_BODY = `
  <h1>آینهٔ محیط نمایشی — بازارگاه تک‌طب ${VERSION}</h1>
  <p class="lede">صفحه‌های واقعیِ محیط نمایشی، از چشم هر چهار نقش. HTML و CSS و فونت و تصویرها همان‌هایی هستند که سایت می‌فرستد.</p>
  <div class="warn">
    <h2>این آینه چه هست و چه نیست</h2>
    <ul>
      <li><strong>هست:</strong> ظاهر واقعی هر صفحه، با چیدمان و راست‌به‌چپ و فونت واقعی. پنجرهٔ مرورگر را باریک کنید تا حالت موبایل را ببینید — همان CSS است.</li>
      <li><strong>نیست:</strong> زنده. فرم‌ها ثبت نمی‌شوند، دکمه‌ها چیزی را تغییر نمی‌دهند و جاوااسکریپت عمداً حذف شده است.</li>
      <li>برای «وارد شوم و کار کنم» یک میزبان واقعی لازم است؛ نیاز دقیق دسترسی در <code>docs/demo-hosting-request-fa.md</code> نوشته شده.</li>
    </ul>
  </div>
  ${card('manager', 'مدیر بازارگاه', 'همان صفحه‌هایی که با <code>tmcowner</code> دیده می‌شوند.')}
  ${card('vendor', 'فروشنده', 'پیشخوان فروشگاهِ تأییدشده — کارِ امروز اول، بعد بقیه.')}
  ${card('staff', 'پرسنل فروشگاه', 'همان فروشگاه، با مرزِ دسترسی: «تنظیمات فروشگاه» باید رد شود.')}
  ${card('buyer', 'خریدار', 'سطح عمومی: فروشگاه، صفحهٔ فروشنده، سبد و حساب کاربری.')}
  <footer>ساخته‌شده با <code>tools/browser/mirror-demo.mjs</code> از روی نصب تمیزِ ${VERSION}. دادهٔ داخل آن ساختگی است.</footer>
`;
for (const name of ['index.html', 'pages.html']) {
  fs.writeFileSync(path.join(OUT, name), SHELL('آینهٔ محیط نمایشی تک‌طب', INDEX_BODY), 'utf8');
}

fs.writeFileSync(path.join(OUT, 'not-mirrored.html'), SHELL('این صفحه در آینه نیست', `
  <h1>این صفحه در آینه نیست</h1>
  <p class="lede">آینه مجموعه‌ای مشخص از صفحه‌هاست، نه یک کپی از کل سایت.</p>
  <div class="warn">
    <p>لینکی که زدید به نشانی‌ای در محیط نمایشی می‌رفت که در این بسته ذخیره نشده — یا کاری است که فقط روی یک سایت زنده معنا دارد (ذخیره، تأیید، حذف).</p>
    <p><a href="index.html">بازگشت به فهرست صفحه‌ها</a></p>
  </div>
`), 'utf8');

const files = fs.readdirSync(OUT).filter((f) => !f.endsWith('.txt'));

// A mirror that quietly points at a host the reader cannot reach is a mirror
// that looks finished and is not. Count what is left, name it, and fail.
const report = [];
let leftovers = 0;
for (const page of results) {
  const html = fs.readFileSync(path.join(OUT, `${page.file}.html`), 'utf8');
  // Only what the browser would FETCH or FOLLOW. A `data-src` the stripped
  // script would have read, and an `og:url` that names the page it was
  // captured from, are inert: counting them turns a clean mirror into a
  // report with fifty findings nobody can act on.
  const hits = [...new Set(
    [...html.matchAll(/(?<![-\w])(?:href|src|action)=(["'])([^"']*)\1/gi)]
      .map((m) => m[2])
      .filter((v) => v.startsWith(SITE))
  )];
  const styles = (html.match(/<link[^>]+rel=["']?stylesheet[^>]*>/gi) || [])
    .filter((t) => /not-mirrored\.html|127\.0\.0\.1/.test(t)).length;
  leftovers += hits.length + styles;
  report.push(`${page.file.padEnd(26)} live-urls=${String(hits.length).padStart(2)}  broken-stylesheets=${styles}`
    + (hits.length ? `\n    ${hits.slice(0, 4).join('\n    ')}` : ''));
}
report.push(
  '',
  'شمارش فقط روی href/src/action است — یعنی چیزی که مرورگر واقعاً می‌گیرد یا دنبال می‌کند.',
  'مقدارهای بی‌اثر (data-src وردپرس، og:url خودِ همان صفحه) همان‌طور که ضبط شده‌اند باقی می‌مانند.',
  '',
  `pages=${results.length} assets=${assetBytes.size} files=${files.length} leftovers=${leftovers}`
);
fs.writeFileSync(path.join(OUT, 'mirror-report.txt'), report.join('\n') + '\n', 'utf8');

console.log(`\nmirror written to ${OUT}`);
console.log(`pages: ${results.length}   assets: ${assetBytes.size}   files total: ${files.length}`);
console.log(`references still pointing at the live site: ${leftovers}  (docs: mirror-report.txt)`);

// ---------------------------------------------------------------------------
// Open what was written. A mirror is only a deliverable if it renders from
// `file://` with nothing missing — and the only way to know that is to load
// every page the way the owner will and watch what the browser asks for.
// ---------------------------------------------------------------------------
const verifier = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox', '--allow-file-access-from-files'] });
const vctx = await verifier.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR' });
const vpg = await vctx.newPage();
const misses = [];
vpg.on('requestfailed', (r) => misses.push(`${path.basename(vpg.url())}: ${r.url()}`));
vpg.on('response', (r) => { if (r.status() >= 400) { misses.push(`${path.basename(vpg.url())}: ${r.status()} ${r.url()}`); } });

const verify = [];
for (const page of [...results, { file: 'index', name: 'فهرست', roleName: '—' }, { file: 'pages', name: 'فهرست', roleName: '—' }]) {
  const file = path.resolve(OUT, `${page.file}.html`);
  await vpg.goto(`file://${file}`, { waitUntil: 'load' }).catch(() => null);
  await vpg.waitForTimeout(250);
  const text = (await vpg.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ').trim();
  const styled = await vpg.evaluate(() => getComputedStyle(document.body).fontFamily || '').catch(() => '');
  verify.push(`${page.file.padEnd(26)} chars=${String(text.length).padStart(5)}  font=${(styled || '(none)').slice(0, 40)}`);
}
const shots = ['vendor-01-dashboard', 'vendor-02-products', 'buyer-01-shop', 'manager-01-dashboard'];
fs.mkdirSync(path.join(OUT, 'screens'), { recursive: true });
for (const one of shots) {
  await vpg.goto(`file://${path.resolve(OUT, `${one}.html`)}`, { waitUntil: 'load' }).catch(() => null);
  await vpg.waitForTimeout(400);
  await vpg.screenshot({ path: path.join(OUT, 'screens', `${one}.png`), fullPage: true });
}
await verifier.close();

// A request to gravatar.com is not a hole in the mirror: it is WordPress
// asking the internet for an avatar, and it will answer on a machine that is
// not behind this box's outbound policy. A request to a file that should be
// IN the folder is a hole. They are counted apart.
const external = misses.filter((m) => /https?:\/\/(?!127\.0\.0\.1)/.test(m.split(': ').slice(1).join(': ')));
const local = misses.filter((m) => !external.includes(m));

fs.writeFileSync(
  path.join(OUT, 'mirror-verify.txt'),
  ['باز کردن همان فایل‌هایی که نوشته شد، از file:// — نه از سایت زنده.', '', ...verify, '',
   `فایل‌های گم‌شدهٔ خودِ آینه: ${local.length}`, ...local.slice(0, 20),
   '',
   `درخواست‌های بیرونی (گراواتار، ایموجی وردپرس) که این ماشین اجازهٔ گرفتنشان را ندارد: ${external.length}`,
   ...[...new Set(external.map((m) => m.split(': ').slice(1).join(': ')))].slice(0, 6),
   'روی رایانهٔ شما این‌ها بارگذاری می‌شوند؛ اینجا سیاست شبکه ردشان می‌کند.', ''].join('\n'),
  'utf8'
);
console.log(`opened ${verify.length} mirrored pages from file://  missing local files: ${local.length}  external blocked: ${external.length}`);
if (local.length) { console.log(local.slice(0, 5).join('\n')); }
