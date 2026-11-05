/*
 * تشخیص فقط‌خواندنی: «HTML ِ صفحهٔ اصلی از کدام نسخه و از کجا می‌آید؟» — Tecteb Homepage.
 *
 * روی صفحهٔ اصلی سایت (همان پنجرهٔ ناشناس، بی ورود)، DevTools ← Console، کل این فایل را بچسبانید و Enter.
 * هیچ چیزی نمی‌نویسد، هیچ فرمی نمی‌فرستد، هیچ کوکی‌ای نمی‌سازد: فقط چند درخواست GET از همان دامنه
 * (همین صفحه با یک پارامتر یکتا، و readme.txt و CSS ِ پوشهٔ همان افزونه که فایل‌های ایستا هستند).
 */
(async () => {
  const out = {};
  const marks = (doc) => ({
    // فقط قالب ۰٫۳٫۵ این کلاس را چاپ می‌کند؛ فقط قالب ۰٫۳٫۴ و قبل‌تر ردیف فیلتر را.
    head_shop_0_3_5: doc.querySelectorAll('.th-head--shop').length,
    filter_row_0_3_4: doc.querySelectorAll('.th-filter').length,
    stylesheet: [...doc.querySelectorAll('link[rel="stylesheet"]')].map((l) => l.getAttribute('href')).filter((h) => h && h.includes('tecteb-home')),
    rocket_comment: ((doc.documentElement.outerHTML.match(/<!--[^>]*WP Rocket[^>]*-->/i) || [''])[0]).slice(0, 200),
  });
  const page = location.origin + location.pathname;
  out.as_shown = marks(document);

  // ۱. همین صفحه، تازه از سرور: پارامتر یکتا کلید کش صفحه (WP Rocket، LiteSpeed، nginx، CDN) را عوض می‌کند.
  const fresh = await fetch(`${page}?tth_diag=${Date.now()}`, { cache: 'no-store', credentials: 'omit' });
  out.fresh = marks(new DOMParser().parseFromString(await fresh.text(), 'text/html'));
  const plain = await fetch(page, { cache: 'no-store', credentials: 'omit' });
  await plain.text();
  const h = (r) => Object.fromEntries(['age', 'x-cache', 'x-cache-status', 'cf-cache-status', 'x-litespeed-cache', 'x-proxy-cache', 'x-cache-enabled', 'x-rocket-nginx-serving-static', 'x-srcache-fetch-status', 'server', 'date']
    .map((k) => [k, r.headers.get(k)]).filter(([, v]) => v !== null));
  out.headers_plain_url = h(plain);
  out.headers_fresh_url = h(fresh);

  // ۲. پوشهٔ افزونه‌ای که واقعاً اجرا شده: از نشانی CSS ِ خودش (TECTEB_HOME_URL) — و نسخهٔ فایل‌های همان پوشه روی دیسک.
  const href = (out.fresh.stylesheet[0] || out.as_shown.stylesheet[0] || '');
  const m = href.match(/(\/wp-content\/plugins\/[^/]+\/)assets\/css\/tecteb-home\.css(?:\?ver=([^&"]+))?/);
  out.running_plugin_folder = m ? m[1] : '(CSS ترکیب/کوچک‌شده است؛ پوشه از نشانی پیدا نیست)';
  out.running_code_version_from_ver = m && m[2] ? m[2] : '(نامعلوم)';
  const folder = m ? m[1] : '/wp-content/plugins/tecteb-homepage/';
  const readme = await fetch(location.origin + folder + 'readme.txt', { cache: 'no-store' });
  out.disk_readme_stable_tag = readme.ok ? ((await readme.text()).match(/Stable tag:\s*(\S+)/) || [, '?'])[1] : `HTTP ${readme.status}`;
  const css = await fetch(location.origin + folder + 'assets/css/tecteb-home.css', { cache: 'no-store' });
  out.disk_css_has_0_3_5_rule = css.ok ? (await css.text()).includes('th-head--shop') : `HTTP ${css.status}`;

  // ۳. حکم.
  const f = out.fresh, s = out.as_shown;
  let verdict;
  if (f.head_shop_0_3_5 > 0 && f.filter_row_0_3_4 === 0 && s.filter_row_0_3_4 > 0) {
    verdict = 'A — کش صفحه: کد ۰٫۳٫۵ اجرا می‌شود (درخواست تازه درست است)، ولی نشانی معمولی HTML ِ قدیمی را از یک کش می‌گیرد. سربرگ‌های headers_plain_url می‌گویند کدام کش.';
  } else if (f.filter_row_0_3_4 > 0 && out.disk_readme_stable_tag === '0.3.5' && out.running_code_version_from_ver !== '0.3.5' && out.running_code_version_from_ver !== '(نامعلوم)') {
    verdict = 'B — OPcache کهنه: فایل‌های روی دیسک ۰٫۳٫۵اند ولی PHP هنوز کد کامپایل‌شدهٔ نسخهٔ ' + out.running_code_version_from_ver + ' را اجرا می‌کند.';
  } else if (f.filter_row_0_3_4 > 0 && out.disk_readme_stable_tag !== '0.3.5') {
    verdict = 'C — پوشهٔ دیگر: افزونه‌ای که اجرا می‌شود از ' + out.running_plugin_folder + ' است و روی دیسکِ همان پوشه نسخهٔ ' + out.disk_readme_stable_tag + ' است، نه ۰٫۳٫۵.';
  } else if (f.filter_row_0_3_4 > 0) {
    verdict = 'D — فایل‌ها یا کد ناهماهنگ: نسخه ۰٫۳٫۵ گزارش می‌شود ولی قالب قدیمی رندر می‌شود (نصب ناقص یا OPcache ِ فقط برخی فایل‌ها).';
  } else if (f.head_shop_0_3_5 > 0 && s.filter_row_0_3_4 === 0) {
    verdict = 'E — همه درست: هر دو درخواست HTML ِ ۰٫۳٫۵ دارند. اگر چیزی قدیمی دیده می‌شود، از کش مرورگر یا CSS ِ ترکیب‌شده است، نه از HTML.';
  } else {
    verdict = 'F — بخش محصولات Tecteb Homepage در این صفحه نیست (head_shop و filter_row هر دو صفر): این صفحه را افزونه رندر نکرده است.';
  }
  out.VERDICT = verdict;
  console.log(JSON.stringify(out, null, 2));
  return out;
})();
