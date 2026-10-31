/*
 * مقایسهٔ چند سبک محاسبه‌شده با نمونهٔ تأییدشدهٔ B (8095) — همان ۲۳ جایی که «تمیز» ِ 0.2.0 با 0.2.1 فرق داشت
 * (CSS ِ قالب Hello که همیشه بارگذاری است روی 0.2.0 اثر می‌گذاشت). NODE_PATH=/opt/node-tools/node_modules node tests/prototype-parity.mjs
 */
import { createRequire } from 'module';
const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const probe = async (url, w, act) => { const p = await b.newPage({ viewport: { width: w, height: 900 } }); await p.goto(url, { waitUntil: 'networkidle' }); if (act) await act(p);
  const r = await p.evaluate(() => { const g = (s, k) => { const e = document.querySelector(s); return e ? getComputedStyle(e).getPropertyValue(k) : null; };
    return { mark_maxw: g('.tth-logo__mark', 'max-width'), input_radius: g('.tth-search__input', 'border-radius'), input_font: g('.tth-search__input', 'font-size'), form_radius: g('#tth-search-form', 'border-radius'), title_lh: g('.tth-drawer__title', 'line-height'), title_tag: document.querySelector('.tth-drawer__title')?.tagName, menu_ow: g('.tth-menu', 'outline-width') }; });
  await p.close(); return r; };
for (const [n, u] of [['proto', 'http://127.0.0.1:8095/header/b-inner.html'], ['plugin', 'http://127.0.0.1:8090/tthh-about/']]) {
  console.log(n, 'desk', JSON.stringify(await probe(u, 1440)));
  console.log(n, 'focus', JSON.stringify(await probe(u, 1440, (p) => p.focus('#tth-s'))));
  console.log(n, 'drawer', JSON.stringify(await probe(u, 390, async (p) => { await p.click('[data-tth-burger]'); await p.waitForTimeout(300); })));
}
await b.close();
