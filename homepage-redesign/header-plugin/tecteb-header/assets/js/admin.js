/*
 * «مدیریت هدر» — فقط بهبود تدریجی. بی این فایل صفحه کامل کار می‌کند (ترتیب با عدد،
 * آیکون با شناسهٔ پیوست). این فایل سه کار می‌کند:
 *   ۱. کتابخانهٔ رسانهٔ وردپرس (wp.media) برای آیکون و لوگو، فقط PNG و WebP؛
 *   ۲. دکمه‌های «بالاتر/پایین‌تر» که کارت‌ها را جابه‌جا و عدد ترتیب را بازنویسی می‌کنند؛
 *   ۳. اندازهٔ پیش‌نمایش (دسکتاپ/موبایل).
 * هیچ چیزی را ذخیره نمی‌کند؛ ذخیره فقط با دکمهٔ فرم و از options.php است.
 */
(() => {
  const L = window.TTHAdminL10n || { mimes: ['image/png', 'image/webp'] };
  const root = document.querySelector('.tth-hdr');
  if (!root) return;

  root.querySelectorAll('[data-tth-js], [data-tth-move], [data-tth-media], [data-tth-media-clear], [data-tth-media-set]').forEach((el) => { el.hidden = false; });
  root.querySelectorAll('small[data-tth-nojs]').forEach((el) => { el.hidden = true; });

  // ۱. رسانه
  root.querySelectorAll('[data-tth-media]').forEach((btn) => {
    const input = document.getElementById(btn.dataset.tthMedia);
    const preview = root.querySelector(`[data-tth-preview="${btn.dataset.tthMedia}"]`);
    if (!input || !window.wp || !wp.media) return;
    input.type = 'hidden';
    let frame = null;
    btn.addEventListener('click', () => {
      if (!frame) {
        frame = wp.media({ title: L.title, button: { text: L.button }, multiple: false, library: { type: L.mimes } });
        frame.on('select', () => {
          const a = frame.state().get('selection').first().toJSON();
          if (!L.mimes.includes(a.mime)) { window.alert(L.badMime); return; }
          input.value = String(a.id);
          const src = (a.sizes && (a.sizes.thumbnail || a.sizes.full) || a).url;
          if (preview) { preview.textContent = ''; const img = document.createElement('img'); img.src = src; img.alt = ''; preview.append(img); }
        });
      }
      frame.open();
    });
  });
  root.querySelectorAll('[data-tth-media-clear]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.tthMediaClear);
      const preview = root.querySelector(`[data-tth-preview="${btn.dataset.tthMediaClear}"]`);
      if (input) input.value = '0';
      if (preview) preview.textContent = '—';
    });
  });

  // پیشنهاد «تصویر دسته در ووکامرس»: همان شناسه را در کادر می‌گذارد؛ ذخیره با دکمهٔ فرم.
  root.querySelectorAll('[data-tth-media-set]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.tthMediaSet);
      const preview = root.querySelector(`[data-tth-preview="${btn.dataset.tthMediaSet}"]`);
      if (input) input.value = btn.dataset.id;
      if (preview) { preview.textContent = ''; const img = document.createElement('img'); img.src = btn.dataset.url; img.alt = ''; preview.append(img); }
    });
  });

  // ۲. ترتیب
  const list = root.querySelector('.tth-cards');
  const renumber = () => {
    const cards = [...list.querySelectorAll(':scope > [data-tth-card]')];
    cards.forEach((card, i) => {
      card.querySelector('[data-tth-order]').value = String(i + 1);
      card.querySelector('[data-tth-move="up"]').disabled = i === 0;
      card.querySelector('[data-tth-move="down"]').disabled = i === cards.length - 1;
    });
  };
  if (list) {
    list.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-tth-move]');
      if (!btn) return;
      const card = btn.closest('[data-tth-card]');
      const other = btn.dataset.tthMove === 'up' ? card.previousElementSibling : card.nextElementSibling;
      if (!other) return;
      if (btn.dataset.tthMove === 'up') list.insertBefore(card, other); else list.insertBefore(other, card);
      renumber();
      const same = card.querySelector(`[data-tth-move="${btn.dataset.tthMove}"]`);
      (same.disabled ? card.querySelector('[data-tth-move]:not(:disabled)') : same)?.focus();
    });
  }

  // ۳. پیش‌نمایش
  const wrap = root.querySelector('[data-tth-frame-wrap]');
  root.querySelectorAll('[data-tth-view]').forEach((btn) => {
    btn.addEventListener('click', () => {
      wrap.dataset.view = btn.dataset.tthView;
      root.querySelectorAll('[data-tth-view]').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
    });
  });
})();
