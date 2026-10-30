/*
 * داشبورد «تک‌طب ← مدیریت هدر» — نمونهٔ طراحی (نه افزونهٔ عملیاتی).
 *
 * هر قاعده‌ای که اینجا اجرا می‌شود، قاعده‌ای است که افزونهٔ واقعی در PHP اجرا می‌کند
 * (README بند ۴): فقط شناسه ذخیره می‌شود؛ زیردسته باید زیرمجموعهٔ واقعی همان دسته باشد؛
 * سقف ۱۶ دستهٔ اصلی و ۱۲ زیردسته؛ آیکون فقط پیوستِ تصویری؛ نشانی همکاری فقط مسیر نسبی یا http(s).
 * «ذخیره» اینجا فقط اعتبارسنجی را اجرا می‌کند و نتیجه را در زبانهٔ «دادهٔ ذخیره‌شده» نشان می‌دهد.
 */
(() => {
  const MAX_MAIN = 16;
  const MAX_KIDS = 12;
  const ICON_TYPES = ['image/png', 'image/webp', 'image/svg+xml'];
  const ICON_MAX_BYTES = 256 * 1024;

  const clone = (o) => JSON.parse(JSON.stringify(o));
  const esc = window.TTHMenu.esc;
  const fa = (n) => Number(n).toLocaleString('fa-IR');
  const $ = (s, r = document) => r.querySelector(s);

  const original = clone(window.TTH_SAMPLE);
  let data = clone(original);              // «ووکامرس» ِ نمونه (شبیه‌ساز تغییرش می‌دهد)
  let saved = clone(data.settings);        // آنچه «ذخیره» شده
  let state = clone(saved);                // فرم
  let terms = new Map();
  const ui = { open: new Set(), picker: 0, view: 'desktop', mediaFor: 0 };
  let nextUpload = 6001;

  const reindex = () => { terms = new Map(data.terms.map((t) => [t.id, t])); };
  reindex();

  const path = (id) => {
    const parts = [];
    for (let t = terms.get(id), g = 0; t && g < 32; t = terms.get(t.parent), g++) parts.unshift(t.name);
    return parts.join(' › ');
  };
  const descendants = (id, depth = 1, out = []) => {
    data.terms.filter((t) => t.parent === id).forEach((t) => { out.push({ t, depth }); descendants(t.id, depth + 1, out); });
    return out;
  };
  const media = (id) => data.media.find((m) => m.id === id);
  const catLink = (t) => `/product-category/${t.slug}/`;

  function mainIssues(c) {
    const t = terms.get(c.term_id);
    if (!t) return [['error', `دستهٔ شناسهٔ ${fa(c.term_id)} دیگر در ووکامرس نیست؛ در هدر نمایش داده نمی‌شود. آن را حذف کنید.`]];
    const out = [];
    if (t.default) out.push(['warning', 'این دستهٔ پیش‌فرض ووکامرس است.']);
    if (state.hide_empty && t.count < 1) out.push(['warning', 'محصول منتشرشده ندارد؛ تا وقتی «دستهٔ بی‌محصول» پنهان است، نمایش داده نمی‌شود.']);
    if (c.icon_id && !media(c.icon_id)) out.push(['warning', 'پیوست آیکون دیگر در کتابخانهٔ رسانه نیست؛ آیکون عمومی نشان داده می‌شود.']);
    return out;
  }
  function kidIssue(main, k) {
    const t = terms.get(k.term_id);
    if (!t) return ['error', 'دیگر در ووکامرس نیست؛ نمایش داده نمی‌شود'];
    if (!window.TTHMenu.isDescendant(terms, k.term_id, main.term_id)) return ['error', `دیگر زیرمجموعهٔ «${terms.get(main.term_id)?.name ?? ''}» نیست؛ نمایش داده نمی‌شود`];
    if (state.hide_empty && t.count < 1) return ['warning', 'بی‌محصول؛ پنهان'];
    return null;
  }

  // ------------------------------------------------------------------ رندر
  function icon(c) {
    const m = media(c.icon_id);
    return m ? `<img src="${esc(m.file)}" alt="" width="40" height="40">` : '<span class="dashicons dashicons-format-image" aria-hidden="true"></span>';
  }

  function card(c, i, n) {
    const t = terms.get(c.term_id);
    const name = t ? t.name : `دستهٔ حذف‌شده (شناسهٔ ${fa(c.term_id)})`;
    const kidsOn = c.children.filter((k) => k.enabled).length;
    const open = ui.open.has(c.term_id);
    const issues = mainIssues(c).map(([k, m]) => `<p class="tth-issue tth-issue--${k}">${esc(m)}</p>`).join('');
    const kids = c.children.map((k, j) => {
      const kt = terms.get(k.term_id);
      const issue = kidIssue(c, k);
      return `<li class="tth-kid${k.enabled ? '' : ' is-off'}">
        <span class="tth-order">
          <button type="button" class="button-link" data-act="kid-up" data-id="${c.term_id}" data-kid="${k.term_id}" data-key="kup-${k.term_id}" aria-label="بالا بردن ${esc(kt?.name ?? '')}" ${j === 0 ? 'disabled' : ''}><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
          <button type="button" class="button-link" data-act="kid-down" data-id="${c.term_id}" data-kid="${k.term_id}" data-key="kdown-${k.term_id}" aria-label="پایین بردن ${esc(kt?.name ?? '')}" ${j === c.children.length - 1 ? 'disabled' : ''}><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
        </span>
        <span class="tth-kid__name"><strong>${esc(kt ? kt.name : `حذف‌شده (شناسهٔ ${fa(k.term_id)})`)}</strong>
          <small>${kt ? `${esc(path(k.term_id))} · شناسهٔ ${fa(kt.id)} · ${fa(kt.count)} محصول` : ''}</small>
          ${issue ? `<small class="tth-issue tth-issue--${issue[0]}">${esc(issue[1])}</small>` : ''}</span>
        <label class="tth-switch"><input type="checkbox" role="switch" data-act="kid-toggle" data-id="${c.term_id}" data-kid="${k.term_id}" data-key="ktog-${k.term_id}" ${k.enabled ? 'checked' : ''}><span>نمایش</span></label>
        <button type="button" class="button-link button-link-delete" data-act="kid-remove" data-id="${c.term_id}" data-kid="${k.term_id}" data-key="krm-${k.term_id}">حذف<span class="screen-reader-text"> ${esc(kt?.name ?? '')}</span></button>
      </li>`;
    }).join('');
    const picker = ui.picker === c.term_id && t ? childPicker(c) : '';
    return `<li class="tth-card${c.enabled ? '' : ' is-off'}" data-id="${c.term_id}" draggable="true">
      <div class="tth-card__row">
        <span class="tth-handle dashicons dashicons-move" aria-hidden="true" title="کشیدن برای جابه‌جایی"></span>
        <span class="tth-order">
          <button type="button" class="button-link" data-act="up" data-id="${c.term_id}" data-key="up-${c.term_id}" aria-label="بالا بردن ${esc(name)}" ${i === 0 ? 'disabled' : ''}><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
          <span class="tth-pos" aria-hidden="true">${fa(i + 1)}</span>
          <button type="button" class="button-link" data-act="down" data-id="${c.term_id}" data-key="down-${c.term_id}" aria-label="پایین بردن ${esc(name)}" ${i === n - 1 ? 'disabled' : ''}><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
        </span>
        <button type="button" class="tth-icon" data-act="icon" data-id="${c.term_id}" data-key="icon-${c.term_id}" aria-label="تغییر آیکون ${esc(name)}">${icon(c)}</button>
        <span class="tth-card__title"><strong>${esc(name)}</strong>
          <small>${t ? `شناسهٔ ${fa(t.id)} · ${fa(t.count)} محصول · <bdi dir="ltr">${esc(catLink(t))}</bdi>` : 'در ووکامرس پیدا نشد'}</small></span>
        <label class="tth-switch"><input type="checkbox" role="switch" data-act="toggle" data-id="${c.term_id}" data-key="tog-${c.term_id}" ${c.enabled ? 'checked' : ''}><span>در هدر</span></label>
        <button type="button" class="button" data-act="kids" data-id="${c.term_id}" data-key="kids-${c.term_id}" aria-expanded="${open}" aria-controls="tth-kids-${c.term_id}">زیردسته‌ها <span class="tth-pill">${fa(kidsOn)}/${fa(c.children.length)}</span></button>
        <button type="button" class="button-link button-link-delete" data-act="remove" data-id="${c.term_id}" data-key="rm-${c.term_id}">حذف از هدر<span class="screen-reader-text"> ${esc(name)}</span></button>
      </div>
      ${issues}
      <div class="tth-kids" id="tth-kids-${c.term_id}" ${open ? '' : 'hidden'}>
        ${c.children.length ? `<ol class="tth-kids__list">${kids}</ol>` : '<p class="description">زیردسته‌ای انتخاب نشده؛ در هدر فقط «همهٔ محصولات این دسته» نمایش داده می‌شود.</p>'}
        ${picker || (t ? `<button type="button" class="button button-small" data-act="kid-add" data-id="${c.term_id}" data-key="kadd-${c.term_id}"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>افزودن زیردسته از زیرمجموعه‌های «${esc(t.name)}»</button>` : '')}
      </div>
    </li>`;
  }

  function childPicker(c) {
    const chosen = new Set(c.children.map((k) => k.term_id));
    const rows = descendants(c.term_id).map(({ t, depth }) => `<li style="--d:${depth}"><label><input type="checkbox" value="${t.id}" data-pick ${chosen.has(t.id) ? 'checked disabled' : ''}> ${esc(t.name)} <small>${fa(t.count)} محصول${chosen.has(t.id) ? ' · انتخاب‌شده' : ''}</small></label></li>`).join('');
    return `<div class="tth-kpick" role="group" aria-label="زیرمجموعه‌های ${esc(terms.get(c.term_id).name)}">
      <label class="screen-reader-text" for="tth-kq-${c.term_id}">جست‌وجو در زیرمجموعه‌ها</label>
      <input type="search" id="tth-kq-${c.term_id}" class="regular-text" placeholder="جست‌وجو در زیرمجموعه‌های همین دسته…" data-act="kid-q" data-key="kq-${c.term_id}">
      ${rows ? `<ul class="tth-kpick__list">${rows}</ul>` : '<p class="description">این دسته در ووکامرس زیرمجموعه‌ای ندارد.</p>'}
      <p><button type="button" class="button button-primary button-small" data-act="kid-pick" data-id="${c.term_id}" data-key="kpick-${c.term_id}">افزودن انتخاب‌شده‌ها</button>
      <button type="button" class="button button-small" data-act="kid-cancel" data-id="${c.term_id}" data-key="kcancel-${c.term_id}">انصراف</button></p>
    </div>`;
  }

  const isDirty = () => JSON.stringify(state) !== JSON.stringify(saved);

  function render() {
    const key = document.activeElement?.dataset?.key;
    const list = $('[data-tth-cards]');
    list.innerHTML = state.categories.map((c, i) => card(c, i, state.categories.length)).join('');
    const on = state.categories.filter((c) => c.enabled);
    const kidsOn = on.reduce((a, c) => a + c.children.filter((k) => k.enabled).length, 0);
    $('[data-tth-count]').textContent = `${fa(state.categories.length)} دسته · ${fa(on.length)} در هدر · ${fa(kidsOn)} زیردستهٔ فعال`;
    const dirty = isDirty();
    $('[data-tth-dirty]').hidden = !dirty;
    $('[data-tth-revert]').disabled = !dirty;
    $('[data-tth-version]').textContent = `نسخهٔ ذخیره‌شده: ${fa(saved.version)}`;
    $('[data-tth-hide-empty]').checked = !!state.hide_empty;
    if (document.activeElement !== $('[data-tth-supplier]')) $('[data-tth-supplier]').value = state.links.supplier;
    $('[data-tth-json]').textContent = JSON.stringify(saved, null, 2);
    renderImport();
    if (key) document.querySelector(`[data-key="${CSS.escape(key)}"]`)?.focus();
    schedulePreview();
  }

  function notice(type, html) {
    $('[data-tth-notices]').innerHTML = `<div class="notice notice-${type} is-dismissible inline"><p>${html}</p></div>`;
  }

  // ------------------------------------------------------------------ رویدادهای کارت
  const find = (id) => state.categories.find((c) => c.term_id === id);
  const move = (arr, i, d) => { const j = i + d; if (j < 0 || j >= arr.length) return; [arr[i], arr[j]] = [arr[j], arr[i]]; };

  $('[data-tth-cards]').addEventListener('click', (e) => {
    const b = e.target.closest('[data-act]');
    if (!b || b.tagName === 'INPUT') return;
    const id = Number(b.dataset.id);
    const kid = Number(b.dataset.kid);
    const c = find(id);
    const i = state.categories.indexOf(c);
    switch (b.dataset.act) {
      case 'up': move(state.categories, i, -1); break;
      case 'down': move(state.categories, i, 1); break;
      case 'remove': state.categories.splice(i, 1); ui.open.delete(id); notice('info', `«${esc(terms.get(id)?.name ?? fa(id))}» از هدر برداشته شد (دسته در ووکامرس دست نخورد). تا «ذخیره» نزنید ثبت نمی‌شود.`); break;
      case 'kids': ui.open.has(id) ? ui.open.delete(id) : ui.open.add(id); break;
      case 'icon': openMedia(id); return;
      case 'kid-up': case 'kid-down': {
        const j = c.children.findIndex((k) => k.term_id === kid);
        move(c.children, j, b.dataset.act === 'kid-up' ? -1 : 1);
        break;
      }
      case 'kid-remove': c.children = c.children.filter((k) => k.term_id !== kid); break;
      case 'kid-add': ui.picker = id; render(); $(`#tth-kq-${id}`)?.focus(); return;
      case 'kid-cancel': ui.picker = 0; render(); document.querySelector(`[data-key="kadd-${id}"]`)?.focus(); return;
      case 'kid-pick': {
        const picked = [...b.closest('.tth-kpick').querySelectorAll('[data-pick]:checked:not(:disabled)')].map((x) => Number(x.value));
        if (c.children.length + picked.length > MAX_KIDS) {
          notice('error', `هر دسته حداکثر ${fa(MAX_KIDS)} زیردسته می‌تواند داشته باشد؛ چیزی افزوده نشد.`);
          return;
        }
        picked.forEach((pid) => { if (window.TTHMenu.isDescendant(terms, pid, id)) c.children.push({ term_id: pid, enabled: true }); });
        ui.picker = 0;
        render();
        document.querySelector(`[data-key="kadd-${id}"]`)?.focus();
        return;
      }
      default: return;
    }
    render();
  });
  $('[data-tth-cards]').addEventListener('change', (e) => {
    const x = e.target;
    if (x.dataset.act === 'toggle') find(Number(x.dataset.id)).enabled = x.checked;
    else if (x.dataset.act === 'kid-toggle') find(Number(x.dataset.id)).children.find((k) => k.term_id === Number(x.dataset.kid)).enabled = x.checked;
    else return;
    render();
  });
  $('[data-tth-cards]').addEventListener('input', (e) => {
    if (e.target.dataset.act !== 'kid-q') return;
    const q = e.target.value.trim();
    e.target.closest('.tth-kpick').querySelectorAll('li').forEach((li) => { li.hidden = q !== '' && !li.textContent.includes(q); });
  });

  // کشیدن و رها کردن (برای ماوس؛ دکمه‌های بالا/پایین همان کار را با کیبورد می‌کنند)
  let dragId = 0;
  $('[data-tth-cards]').addEventListener('dragstart', (e) => {
    const li = e.target.closest('.tth-card');
    if (!li) return;
    dragId = Number(li.dataset.id);
    e.dataTransfer.effectAllowed = 'move';
  });
  $('[data-tth-cards]').addEventListener('dragover', (e) => { if (dragId) e.preventDefault(); });
  $('[data-tth-cards]').addEventListener('drop', (e) => {
    const li = e.target.closest('.tth-card');
    if (!li || !dragId) return;
    e.preventDefault();
    const from = state.categories.findIndex((c) => c.term_id === dragId);
    const to = state.categories.findIndex((c) => c.term_id === Number(li.dataset.id));
    const [it] = state.categories.splice(from, 1);
    state.categories.splice(to, 0, it);
    dragId = 0;
    render();
  });

  // ------------------------------------------------------------------ افزودن دستهٔ اصلی
  const mainModal = $('[data-tth-main-modal]');
  function renderMainList() {
    const q = $('[data-tth-main-q]').value.trim();
    const have = new Set(state.categories.map((c) => c.term_id));
    const rows = [...data.terms].sort((a, b) => path(a.id).localeCompare(path(b.id), 'fa')).filter((t) => !q || path(t.id).includes(q));
    $('[data-tth-main-list]').innerHTML = rows.map((t) => {
      const why = have.has(t.id) ? 'در هدر هست' : t.default ? 'دستهٔ پیش‌فرض ووکامرس' : '';
      return `<li><span><strong>${esc(t.name)}</strong> <small>${esc(path(t.id))} · شناسهٔ ${fa(t.id)} · ${fa(t.count)} محصول</small></span>
        <button type="button" class="button button-small" data-add="${t.id}" ${why ? 'disabled' : ''}>${why || 'افزودن'}<span class="screen-reader-text"> ${esc(t.name)}</span></button></li>`;
    }).join('') || '<li class="description">دسته‌ای با این نام نیست.</li>';
  }
  $('[data-tth-add-main]').addEventListener('click', () => {
    if (state.categories.length >= MAX_MAIN) { notice('error', `حداکثر ${fa(MAX_MAIN)} دستهٔ اصلی؛ اول یکی را حذف کنید.`); return; }
    $('[data-tth-main-q]').value = '';
    renderMainList();
    mainModal.showModal();
  });
  $('[data-tth-main-q]').addEventListener('input', renderMainList);
  $('[data-tth-main-list]').addEventListener('click', (e) => {
    const b = e.target.closest('[data-add]');
    if (!b) return;
    const id = Number(b.dataset.add);
    state.categories.push({ term_id: id, enabled: true, icon_id: 0, children: [] });
    mainModal.close();
    notice('info', `«${esc(terms.get(id).name)}» به انتهای فهرست افزوده شد. تا «ذخیره» نزنید ثبت نمی‌شود.`);
    render();
    document.querySelector(`[data-key="icon-${id}"]`)?.focus();
  });

  // ------------------------------------------------------------------ آیکون
  const mediaModal = $('[data-tth-media-modal]');
  function renderMedia() {
    const cur = find(ui.mediaFor)?.icon_id;
    $('[data-tth-media-list]').innerHTML = data.media.map((m) => `<li><button type="button" class="tth-media__item" data-media="${m.id}" aria-pressed="${m.id === cur}">
      <img src="${esc(m.file)}" alt="" width="64" height="64"><span>${esc(m.title)}</span><small dir="ltr">${esc(m.mime)} · #${m.id}</small></button></li>`).join('');
  }
  function openMedia(id) {
    ui.mediaFor = id;
    $('[data-tth-upload-msg]').textContent = '';
    renderMedia();
    mediaModal.showModal();
  }
  $('[data-tth-media-list]').addEventListener('click', (e) => {
    const b = e.target.closest('[data-media]');
    if (!b) return;
    find(ui.mediaFor).icon_id = Number(b.dataset.media);
    mediaModal.close();
    render();
  });
  $('[data-tth-icon-clear]').addEventListener('click', () => { find(ui.mediaFor).icon_id = 0; mediaModal.close(); render(); });
  $('[data-tth-upload]').addEventListener('change', (e) => {
    const f = e.target.files[0];
    e.target.value = '';
    const msg = $('[data-tth-upload-msg]');
    if (!f) return;
    if (!ICON_TYPES.includes(f.type)) { msg.textContent = `پذیرفته نشد: «${f.name}» تصویر PNG، WebP یا SVG نیست.`; return; }
    if (f.size > ICON_MAX_BYTES) { msg.textContent = `پذیرفته نشد: «${f.name}» بزرگ‌تر از ۲۵۶ کیلوبایت است.`; return; }
    const id = nextUpload++;
    data.media.push({ id, title: f.name, file: URL.createObjectURL(f), mime: f.type });
    find(ui.mediaFor).icon_id = id;
    msg.textContent = f.type === 'image/svg+xml'
      ? 'بارگذاری شد. ⛔ وردپرس به‌طور پیش‌فرض SVG نمی‌پذیرد؛ روی سایت فقط با یک بارگذار امنِ SVG ممکن است.'
      : `بارگذاری شد و برای این دسته انتخاب شد (شناسهٔ پیوست ${fa(id)}).`;
    renderMedia();
    render();
  });
  document.querySelectorAll('[data-tth-close]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));
  mediaModal.addEventListener('close', () => document.querySelector(`[data-key="icon-${ui.mediaFor}"]`)?.focus());

  // ------------------------------------------------------------------ تنظیمات
  $('[data-tth-hide-empty]').addEventListener('change', (e) => { state.hide_empty = e.target.checked; render(); });
  $('[data-tth-supplier]').addEventListener('input', (e) => { state.links.supplier = e.target.value; render(); });

  // ------------------------------------------------------------------ ذخیره
  function validate(s) {
    const errors = [];
    const warnings = [];
    const seen = new Set();
    s.categories = s.categories.filter((c) => !seen.has(c.term_id) && seen.add(c.term_id));
    if (s.categories.length > MAX_MAIN) errors.push(`حداکثر ${fa(MAX_MAIN)} دستهٔ اصلی.`);
    s.categories.forEach((c) => {
      const t = terms.get(c.term_id);
      if (!t) warnings.push(`دستهٔ شناسهٔ ${fa(c.term_id)} در ووکامرس نیست و نمایش داده نمی‌شود.`);
      if (c.icon_id && !(media(c.icon_id) && /^image\//.test(media(c.icon_id).mime))) { warnings.push(`آیکون «${t?.name ?? c.term_id}» تصویر معتبری نیست و برداشته شد.`); c.icon_id = 0; }
      const ks = new Set();
      c.children = c.children.filter((k) => !ks.has(k.term_id) && ks.add(k.term_id));
      if (c.children.length > MAX_KIDS) errors.push(`«${t?.name ?? c.term_id}» بیش از ${fa(MAX_KIDS)} زیردسته دارد.`);
      c.children.forEach((k) => {
        const issue = kidIssue(c, k);
        if (issue && issue[0] === 'error') warnings.push(`زیردستهٔ «${terms.get(k.term_id)?.name ?? k.term_id}» ${issue[1]}.`);
      });
    });
    const link = (s.links.supplier || '').trim();
    if (link && !/^\/(?!\/)\S*$/.test(link) && !/^https?:\/\/[^\s/]+\S*$/i.test(link)) errors.push('پیوند «همکاری تأمین‌کنندگان» باید مسیر نسبی (/…/) یا نشانی http(s) باشد.');
    s.links.supplier = link;
    // فقط همین کلیدها ذخیره می‌شوند — هیچ نام، نامک یا نشانی‌ای.
    const clean = {
      schema: 1,
      version: s.version,
      hide_empty: !!s.hide_empty,
      links: { supplier: s.links.supplier },
      categories: s.categories.map((c) => ({
        term_id: Math.trunc(c.term_id), enabled: !!c.enabled, icon_id: Math.trunc(c.icon_id || 0),
        children: c.children.map((k) => ({ term_id: Math.trunc(k.term_id), enabled: !!k.enabled })),
      })),
    };
    return { errors, warnings, clean };
  }
  $('[data-tth-save]').addEventListener('click', () => {
    const { errors, warnings, clean } = validate(clone(state));
    if (errors.length) { notice('error', `<strong>ذخیره نشد.</strong> ${errors.map(esc).join(' ')}`); return; }
    clean.version = saved.version + 1;
    saved = clean;
    state = clone(saved);
    notice(warnings.length ? 'warning' : 'success', `تنظیمات ذخیره شد (نسخهٔ ${fa(saved.version)}). در افزونهٔ واقعی: کش منو و کش LiteSpeed پاک می‌شد.${warnings.length ? '<br>' + warnings.map(esc).join('<br>') : ''}`);
    render();
  });
  $('[data-tth-revert]').addEventListener('click', () => { state = clone(saved); notice('info', 'به آخرین ذخیره برگشت.'); render(); });

  // ------------------------------------------------------------------ انتقال از منوی فعلی
  function renderImport() {
    $('[data-tth-import]').innerHTML = data.menu_import.map((m, i) => {
      const t = m.object === 'product_cat' ? terms.get(m.object_id) : null;
      const res = !t ? '<span class="tth-issue tth-issue--error">منتقل نمی‌شود — دسته پیدا نشد</span>'
        : t.name !== m.title ? `<span class="tth-issue tth-issue--warning">منتقل می‌شود؛ نام در ووکامرس «${esc(t.name)}» است</span>`
          : '<span class="tth-ok">منتقل می‌شود</span>';
      return `<tr><td>${fa(i + 1)}</td><td>${esc(m.title)}</td><td><code>${esc(m.object)}</code></td><td>${fa(m.object_id)}</td><td>${t ? esc(t.name) : '—'}</td><td>${res}</td></tr>`;
    }).join('');
  }
  $('[data-tth-import-go]').addEventListener('click', () => {
    const rows = data.menu_import.filter((m) => m.object === 'product_cat' && terms.get(m.object_id));
    state.categories = rows.map((m) => ({ term_id: m.object_id, enabled: true, icon_id: 0, children: [] }));
    ui.open.clear();
    notice('info', `${fa(rows.length)} دسته از منوی فعلی، به همان ترتیب، جایگزین فهرست این صفحه شد (بی آیکون و زیردسته). منوی فعلی دست نخورد. برای ثبت «ذخیره» یا برای برگشت «بازگردانی تغییرات» را بزنید.`);
    render();
  });

  // ------------------------------------------------------------------ زبانه‌ها
  document.querySelectorAll('[role="tab"]').forEach((tab) => tab.addEventListener('click', () => {
    document.querySelectorAll('[role="tab"]').forEach((t) => {
      const on = t === tab;
      t.classList.toggle('nav-tab-active', on);
      t.setAttribute('aria-selected', String(on));
      document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
    });
  }));

  // ------------------------------------------------------------------ شبیه‌ساز ووکامرس (فقط نمونه)
  document.querySelectorAll('[data-tth-sim]').forEach((b) => b.addEventListener('click', () => {
    const byName = (n) => data.terms.find((t) => t.name === n);
    const what = b.dataset.tthSim;
    if (what === 'reset') { data = Object.assign(clone(original), { media: data.media }); }
    if (what === 'rename') byName('جراحی') && (byName('جراحی').name = 'جراحی و اتاق عمل');
    if (what === 'delete') data.terms = data.terms.filter((t) => t.name !== 'هتلینگ');
    if (what === 'move') { const lamp = byName('چراغ جراحی'); const gen = byName('پزشکی جنرال'); if (lamp && gen) lamp.parent = gen.id; }
    reindex();
    notice('info', what === 'reset' ? 'دادهٔ نمونهٔ ووکامرس برگشت.' : 'دادهٔ «ووکامرس» عوض شد؛ تنظیمات هدر (شناسه‌ها) دست نخورد — زبانهٔ «دادهٔ ذخیره‌شده» را ببینید.');
    render();
  }));

  // ------------------------------------------------------------------ پیش‌نمایش
  let pending = 0;
  const schedulePreview = () => { clearTimeout(pending); pending = setTimeout(preview, 120); };
  function preview() {
    const m = window.TTHMenu;
    const supplier = esc(state.links.supplier || '/landing-seller/');
    const header = window.TTH_HEADER_TEMPLATE
      .replace('{{VARIANT}}', 'b').replace('{{PAGE}}', '')
      .replace('{{MEGA}}', m.mega(data, state, '')).replace('{{DRAWER_CATS}}', m.drawer(data, state, ''))
      .replaceAll('href="/landing-seller/"', `href="${supplier}"`);
    const open = ui.view === 'desktop'
      ? "document.querySelector('.tth-cats__btn.tth-js').click()"
      : "document.querySelector('.tth-burger').click();var d=document.querySelector('.tth-drawer__cat');if(d){d.open=true;d.scrollIntoView({block:'center'})}";
    const frame = $('[data-tth-frame]');
    frame.srcdoc = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">
      <link rel="stylesheet" href="../../shared/prototype-frame.css"><link rel="stylesheet" href="../shared/header-base.css"><link rel="stylesheet" href="../b/header-b.css"><link rel="stylesheet" href="../shared/proto.css">
      <script src="../shared/header.js" defer><\/script><script src="../shared/proto.js" defer><\/script></head>
      <body class="proto-page" style="margin:0">${header}<main id="main"><div class="proto-slot proto-slot--page" role="presentation">محتوای صفحه</div></main>
      <script>addEventListener('load',function(){setTimeout(function(){${open}},60)})<\/script></body></html>`;
    sizeFrame();
  }
  function sizeFrame() {
    const wrap = $('[data-tth-frame-wrap]');
    const frame = $('[data-tth-frame]');
    const w = ui.view === 'desktop' ? 1280 : 390;
    const h = ui.view === 'desktop' ? 820 : 760;
    const s = Math.min(1, wrap.clientWidth / w);
    frame.style.width = `${w}px`;
    frame.style.height = `${h}px`;
    frame.style.transform = `scale(${s})`;
    wrap.style.height = `${Math.round(h * s)}px`;
    wrap.dataset.view = ui.view;
  }
  document.querySelectorAll('[data-tth-view]').forEach((b) => b.addEventListener('click', () => {
    ui.view = b.dataset.tthView;
    document.querySelectorAll('[data-tth-view]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    preview();
  }));
  addEventListener('resize', sizeFrame);

  window.TTHAdmin = { state: () => clone(state), saved: () => clone(saved) }; // فقط برای آزمون
  render();
})();
