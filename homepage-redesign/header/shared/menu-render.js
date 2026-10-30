/*
 * سازندهٔ HTML ِ دسته‌های هدر (Mega Menu و کشوی موبایل) — نمونهٔ طراحی.
 *
 * یک سازنده، دو مصرف‌کننده: پیش‌نمایش داشبورد در مرورگر (window.TTHMenu) و ساخت صفحه‌های
 * نمونه با node (tools/build-pages.py آن را صدا می‌زند). پس هدر نمونه و پیش‌نمایش
 * داشبورد نمی‌توانند با هم اختلاف پیدا کنند. در نسخهٔ عملیاتی همین قواعد در PHP اجرا
 * می‌شوند (README بند ۴-۳).
 *
 * قواعد — همان که داشبورد ذخیره می‌کند و همان که نسخهٔ عملیاتی باید اجرا کند:
 *  • تنظیمات فقط شناسه نگه می‌دارند؛ نام و نشانی هر بار از «ووکامرس» (اینجا terms) خوانده می‌شود.
 *  • دستهٔ غیرفعال، حذف‌شده، یا (با hide_empty) بی‌محصول نمایش داده نمی‌شود — و هیچ دستهٔ
 *    دیگری خودکار جایش نمی‌آید.
 *  • زیردسته فقط وقتی نمایش داده می‌شود که فعال باشد، وجود داشته باشد و **هنوز** زیرمجموعهٔ
 *    همان دستهٔ اصلی باشد (اگر در ووکامرس جابه‌جا شده، کنار می‌رود).
 */
(function (root) {
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

  function index(data) {
    const terms = new Map(data.terms.map((t) => [t.id, t]));
    const media = new Map(data.media.map((m) => [m.id, m]));
    return { terms, media };
  }

  function isDescendant(terms, childId, ancestorId) {
    let t = terms.get(childId);
    for (let guard = 0; t && guard < 32; guard++) {
      if (t.parent === ancestorId) return true;
      t = terms.get(t.parent);
    }
    return false;
  }

  /** همان فهرستی که نمایش داده می‌شود؛ هر دو خروجی از همین می‌سازند. */
  function resolve(data, settings) {
    const { terms, media } = index(data);
    const shown = (t) => t && (!settings.hide_empty || t.count > 0);
    return settings.categories
      .filter((c) => c.enabled && shown(terms.get(c.term_id)))
      .map((c) => {
        const t = terms.get(c.term_id);
        const icon = media.get(c.icon_id);
        return {
          id: t.id,
          name: t.name,
          href: `#cat-${t.id}`,
          icon: icon && /^image\//.test(icon.mime) ? icon.file : '',
          children: (c.children || [])
            .filter((k) => k.enabled && shown(terms.get(k.term_id)) && isDescendant(terms, k.term_id, t.id))
            .map((k) => ({ id: k.term_id, name: terms.get(k.term_id).name, href: `#cat-${k.term_id}` })),
        };
      });
  }

  const iconHtml = (c, prefix, cls) => c.icon
    ? `<span class="${cls}"><img src="${esc(prefix + c.icon)}" width="24" height="24" alt="" loading="lazy" decoding="async"></span>`
    : `<span class="${cls} ${cls}--none"><svg class="tth-i" aria-hidden="true"><use href="#tth-i-grid"/></svg></span>`;

  function mega(data, settings, prefix) {
    const cats = resolve(data, settings);
    const items = cats.map((c, i) => {
      const kids = c.children.map((k) => `<li><a href="${esc(k.href)}" data-src="sample">${esc(k.name)}</a></li>`).join('');
      return `<li class="tth-mega__item${i === 0 ? ' is-active' : ''}">`
        + `<a class="tth-mega__main" href="${esc(c.href)}" data-src="sample">${iconHtml(c, prefix, 'tth-mega__icon')}<span class="tth-mega__name">${esc(c.name)}</span><svg class="tth-i tth-mega__chev" aria-hidden="true"><use href="#tth-i-back"/></svg></a>`
        + `<div class="tth-mega__sub"><a class="tth-mega__subhead" href="${esc(c.href)}" data-src="sample">همهٔ محصولات ${esc(c.name)}<svg class="tth-i" aria-hidden="true"><use href="#tth-i-back"/></svg></a>`
        + (kids ? `<ul class="tth-mega__list">${kids}</ul>` : '')
        + `</div></li>`;
    }).join('\n');
    return `<div class="tth-mega__body"><ul class="tth-mega__mains">\n${items}\n</ul></div>`;
  }

  function drawer(data, settings, prefix) {
    return resolve(data, settings).map((c) => {
      const kids = c.children.map((k) => `<li><a href="${esc(k.href)}" data-src="sample">${esc(k.name)}</a></li>`).join('');
      return `<details class="tth-drawer__cat"><summary>${iconHtml(c, prefix, 'tth-drawer__icon')}<span class="tth-drawer__name">${esc(c.name)}</span><svg class="tth-i tth-i--chev" aria-hidden="true"><use href="#tth-i-chev"/></svg></summary>`
        + `<ul><li><a href="${esc(c.href)}" data-src="sample">همهٔ ${esc(c.name)}</a></li>${kids}</ul></details>`;
    }).join('\n');
  }

  const api = { resolve, mega, drawer, isDescendant, esc };
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.TTHMenu = api;
})(typeof window !== 'undefined' ? window : globalThis);

// node shared/menu-render.js <data.json> <icon-prefix>  ⇒  {"mega": "...", "drawer": "..."}
if (typeof require === 'function' && typeof module === 'object' && require.main === module) {
  const fs = require('fs');
  const data = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
  const api = module.exports;
  const prefix = process.argv[3] || '';
  process.stdout.write(JSON.stringify({ mega: api.mega(data, data.settings, prefix), drawer: api.drawer(data, data.settings, prefix) }));
}
