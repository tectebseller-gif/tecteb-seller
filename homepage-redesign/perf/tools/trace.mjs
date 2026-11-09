/*
 * ردگیری بارگذاری صفحهٔ اصلی — LCP (کدام عنصر، کِی)، هر layout-shift با منبع و مختصات قبل/بعد،
 * زمان FCP، و لحظه‌ای که هدر به حالت JS می‌رود (data-js). فقط می‌خواند.
 *
 *   NODE_PATH=… node perf/tools/trace.mjs <url> <out.json> [mobile|desktop] [runs=3] [reduce]
 *   WARM=1 …  کش گرم: در همان context یک بار صفحه بارگذاری می‌شود (کش HTTP ِ مرورگر روشن) و بار دوم اندازه گرفته می‌شود.
 *
 * موبایل: ۴۱۲×۸۲۳، DPR 1.75، UA موبایل، CPU ×۴، شبکهٔ «Slow 4G» ِ Lighthouse (۱۵۰ms RTT ×۳٫۷۵،
 * ۱٫۶ Mbps ×۰٫۹) — همان مقادیر DevTools-throttling ِ Lighthouse. دسکتاپ: ۱۳۵۰×۹۴۰، بی throttle ِ CPU، ۴۰ms RTT.
 * Trace ِ کامل Chrome هم در <out>.trace.json ذخیره می‌شود (اجرای اول).
 */
import { createRequire } from 'module';
import fs from 'fs';

const { chromium } = createRequire(process.env.NODE_PATH + '/')('playwright');
const [url, out, mode = 'mobile', runsArg = '3', reduce] = process.argv.slice(2);
const runs = Number(runsArg);
const MOBILE = mode === 'mobile';

const INIT = () => {
  window.__tt = { lcp: [], cls: [], fcp: null, dataJs: null };
  const d = (n) => {
    if (!n || n.nodeType !== 1) return n ? String(n.nodeName) : null;
    return n.tagName.toLowerCase() + (n.id ? '#' + n.id : '') + (n.className && typeof n.className === 'string' ? '.' + n.className.trim().split(/\s+/).slice(0, 3).join('.') : '');
  };
  new PerformanceObserver((l) => l.getEntries().forEach((e) => window.__tt.lcp.push({ t: Math.round(e.startTime), render: Math.round(e.renderTime), load: Math.round(e.loadTime), size: e.size, el: d(e.element), url: (e.url || '').split('/').pop() }))).observe({ type: 'largest-contentful-paint', buffered: true });
  new PerformanceObserver((l) => l.getEntries().forEach((e) => window.__tt.cls.push({ t: Math.round(e.startTime), v: +e.value.toFixed(4), input: e.hadRecentInput, src: (e.sources || []).map((s) => ({ el: d(s.node), from: [s.previousRect.y, s.previousRect.height].map(Math.round), to: [s.currentRect.y, s.currentRect.height].map(Math.round) })) }))).observe({ type: 'layout-shift', buffered: true });
  new PerformanceObserver((l) => l.getEntries().forEach((e) => { if (e.name === 'first-contentful-paint') window.__tt.fcp = Math.round(e.startTime); })).observe({ type: 'paint', buffered: true });
  new MutationObserver((m) => m.forEach((r) => { if (r.attributeName === 'data-js' && window.__tt.dataJs === null) window.__tt.dataJs = Math.round(performance.now()); })).observe(document, { attributes: true, subtree: true, attributeFilter: ['data-js'] });
};

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--disable-features=PaintHolding'] });
const results = [];
try {
  for (let i = 0; i < runs; i++) {
    const ctx = await browser.newContext(MOBILE
      ? { viewport: { width: 412, height: 823 }, deviceScaleFactor: 1.75, isMobile: true, hasTouch: true, userAgent: 'Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Mobile Safari/537.36', reducedMotion: reduce ? 'reduce' : 'no-preference' }
      : { viewport: { width: 1350, height: 940 }, deviceScaleFactor: 1, reducedMotion: reduce ? 'reduce' : 'no-preference' });
    const page = await ctx.newPage();
    const cdp = await ctx.newCDPSession(page);
    await cdp.send('Network.enable');
    await cdp.send('Network.setCacheDisabled', { cacheDisabled: !process.env.WARM });
    await cdp.send('Network.emulateNetworkConditions', MOBILE
      ? { offline: false, latency: 562.5, downloadThroughput: 1474.56 * 1024 / 8, uploadThroughput: 675 * 1024 / 8 }
      : { offline: false, latency: 40, downloadThroughput: 10240 * 1024 / 8, uploadThroughput: 10240 * 1024 / 8 });
    if (MOBILE) await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
    await page.addInitScript(INIT);
    if (i === 0) await browser.startTracing(page, { path: out.replace(/\.json$/, '') + '.trace.json', screenshots: true, categories: ['devtools.timeline', 'disabled-by-default-devtools.timeline', 'disabled-by-default-devtools.timeline.frame', 'loading', 'blink.user_timing', 'disabled-by-default-devtools.screenshot'] });
    if (process.env.WARM) { await page.goto(url, { waitUntil: 'load', timeout: 180000 }); await page.waitForTimeout(1500); await page.goto('about:blank'); }
    const reqs = [];
    page.on('requestfinished', async (r) => { try { const s = await r.sizes(); const t = r.timing(); reqs.push({ url: r.url().replace(/^https?:\/\/[^/]+/, ''), type: r.resourceType(), bytes: s.responseBodySize + s.responseHeadersSize, end: Math.round(t.responseEnd), start: Math.round(t.startTime) }); } catch { /* */ } });
    await page.goto(url, { waitUntil: 'load', timeout: 180000 });
    await page.waitForTimeout(3500);
    if (i === 0) await browser.stopTracing();
    const tt = await page.evaluate(() => {
      const nav = performance.getEntriesByType('navigation')[0];
      const blocking = performance.getEntriesByType('resource').filter((r) => r.renderBlockingStatus === 'blocking').map((r) => ({ url: r.name.replace(/^https?:\/\/[^/]+/, ''), kb: +(r.transferSize / 1024).toFixed(1), end: Math.round(r.responseEnd) }));
      return { ...window.__tt, ttfb: Math.round(nav.responseStart), dcl: Math.round(nav.domContentLoadedEventEnd), load: Math.round(nav.loadEventEnd), blocking, header: (() => { const b = document.querySelector('[data-tth-header] .tth-bar'); return b ? Math.round(b.getBoundingClientRect().height) : null; })() };
    });
    tt.cls_total = +tt.cls.filter((e) => !e.input).reduce((a, e) => a + e.v, 0).toFixed(4);
    tt.requests = reqs.length;
    tt.kb = +(reqs.reduce((a, r) => a + r.bytes, 0) / 1024).toFixed(1);
    results.push(tt);
    await ctx.close();
  }
} finally {
  await browser.close();
}
fs.writeFileSync(out, JSON.stringify({ url, warm: !!process.env.WARM, mode, reduce: !!reduce, runs: results }, null, 1));
const med = (k) => { const v = results.map((r) => (typeof k === 'function' ? k(r) : r[k])).filter((x) => x != null).sort((a, b) => a - b); return v.length ? v[Math.floor(v.length / 2)] : null; };
const last = (r) => r.lcp[r.lcp.length - 1] || {};
console.log(JSON.stringify({ warm: !!process.env.WARM, mode, reduce: !!reduce, fcp: med('fcp'), lcp: med((r) => last(r).t), lcp_el: last(results[0]).el, lcp_candidates: results[0].lcp.map((c) => `${c.t}ms ${c.el} ${c.size}`), cls: med('cls_total'), dataJs: med('dataJs'), ttfb: med('ttfb'), header_h: results[0].header, requests: med('requests'), kb: med('kb') }, null, 1));
