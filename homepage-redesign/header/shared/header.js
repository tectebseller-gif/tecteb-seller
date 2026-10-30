/*
 * هدر سراسری تک‌طب — رفتار (نمونهٔ طراحی). بی وابستگی، بی کتابخانه، ~۳KB.
 *
 * بی این فایل هم هدر کامل است: «دسته‌بندی کالاها» و «حساب من» پیوند ساده‌اند،
 * و منو در موبایل باز و چندسطری نمایش داده می‌شود (header-base.css، بخش «بی جاوااسکریپت»).
 *
 * پنج کار:
 *   ۱. بازشوها (Mega Menu و منوی حساب): الگوی disclosure، نه نقش ARIA ِ menu —
 *      دکمه + aria-expanded + پنل؛ Esc می‌بندد و فوکوس را به دکمه برمی‌گرداند؛
 *      خروج فوکوس یا کلیک بیرون می‌بندد؛ هاور فقط روی ماوس و با تأخیر.
 *   ۲. کشوی موبایل: <dialog> با showModal() — بیرون از کشو inert می‌شود، Esc
 *      می‌بندد، فوکوس به دکمهٔ منو برمی‌گردد.
 *   ۳. حالت جمع‌وجور: ریشه ارتفاع کامل را نگه می‌دارد (--tth-reserve)، پس
 *      fixed شدنِ نوار هیچ چیزی را جابه‌جا نمی‌کند.
 *   ۵. دو ستون Mega Menu: دستهٔ فعال با فوکوس (فوری) یا ماوس (با تأخیر کوتاه).
 *   ۴. دکمهٔ جست‌وجو (فقط موبایلِ صفحهٔ اصلی، با CSS): همان فرم هدر را روی محتوا باز
 *      می‌کند و فوکوس را به کادر می‌برد؛ Esc می‌بندد و فوکوس را به دکمه برمی‌گرداند.
 *      فرم و رفتار ارسالش هیچ تغییری نمی‌کنند — فقط دیده‌شدنش.
 */
(() => {
  const DESKTOP = window.matchMedia('(min-width: 1080px)');
  const MOUSE = window.matchMedia('(hover: hover) and (pointer: fine)');

  document.querySelectorAll('[data-tth-header]').forEach((root) => {
    root.setAttribute('data-js', '');
    const bar = root.querySelector('[data-tth-bar]');

    // --- ۱. بازشوها
    const items = [...root.querySelectorAll('[data-tth-disclosure]')].map((wrap) => {
      const btn = wrap.querySelector('button[data-tth-toggle]');
      return { wrap, btn, panel: btn && root.querySelector('#' + btn.getAttribute('aria-controls')), timer: 0 };
    }).filter((d) => d.btn && d.panel);

    const isOpen = (d) => d.btn.getAttribute('aria-expanded') === 'true';
    const close = (d, focus) => {
      clearTimeout(d.timer);
      if (!isOpen(d)) return;
      d.btn.setAttribute('aria-expanded', 'false');
      d.panel.hidden = true;
      if (focus) d.btn.focus();
    };
    const open = (d) => {
      clearTimeout(d.timer);
      items.forEach((o) => o !== d && close(o));
      d.btn.setAttribute('aria-expanded', 'true');
      d.panel.hidden = false;
    };

    items.forEach((d) => {
      d.btn.addEventListener('click', () => (isOpen(d) ? close(d) : open(d)));
      d.wrap.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && isOpen(d)) { e.stopPropagation(); close(d, true); }
      });
      d.wrap.addEventListener('focusout', (e) => {
        if (!d.wrap.contains(e.relatedTarget)) close(d);
      });
      if (d.wrap.hasAttribute('data-tth-hover')) {
        d.wrap.addEventListener('pointerenter', (e) => {
          if (e.pointerType !== 'mouse' || !MOUSE.matches) return;
          clearTimeout(d.timer);
          d.timer = setTimeout(() => open(d), 140);
        });
        d.wrap.addEventListener('pointerleave', (e) => {
          if (e.pointerType !== 'mouse' || !MOUSE.matches) return;
          clearTimeout(d.timer);
          d.timer = setTimeout(() => close(d), 260);
        });
      }
    });
    document.addEventListener('click', (e) => {
      items.forEach((d) => { if (!d.wrap.contains(e.target)) close(d); });
    });

    // دو ستون Mega Menu: فوکوس یا ماوس روی یک دستهٔ اصلی، زیردسته‌های همان را نشان می‌دهد.
    // فوکوس فوری است (کیبورد)؛ ماوس با تأخیر کوتاه، تا حرکت مورب به‌سوی ستون زیردسته‌ها از
    // روی دسته‌های دیگر، دستهٔ فعال را عوض نکند.
    const mains = [...root.querySelectorAll('.tth-mega__item')];
    let aim = 0;
    const activate = (it) => mains.forEach((o) => o.classList.toggle('is-active', o === it));
    mains.forEach((it) => {
      it.addEventListener('focusin', () => { clearTimeout(aim); activate(it); });
      it.querySelector('.tth-mega__main').addEventListener('pointerenter', (e) => {
        clearTimeout(aim);
        if (e.pointerType === 'mouse') aim = setTimeout(() => activate(it), 120);
        else activate(it);
      });
      it.querySelector('.tth-mega__main').addEventListener('pointerleave', () => clearTimeout(aim));
    });

    // --- ۲. کشوی موبایل
    const drawer = root.querySelector('#tth-drawer');
    const burger = root.querySelector('[data-tth-burger]');
    if (drawer && burger && typeof drawer.showModal === 'function') {
      burger.addEventListener('click', () => {
        items.forEach((d) => close(d));
        setSearch(false);
        drawer.showModal();
        burger.setAttribute('aria-expanded', 'true');
      });
      drawer.addEventListener('close', () => {
        burger.setAttribute('aria-expanded', 'false');
        if (burger.offsetParent !== null) burger.focus();
      });
      drawer.querySelector('[data-tth-close]').addEventListener('click', () => drawer.close());
      // کلیک روی پس‌زمینه: پنل کل دیالوگ را پر می‌کند، پس فقط ::backdrop هدفش خودِ dialog است.
      drawer.addEventListener('click', (e) => { if (e.target === drawer) drawer.close(); });
    }

    // --- ۴. دکمهٔ جست‌وجو
    const searchBtn = root.querySelector('[data-tth-search-toggle]');
    const form = root.querySelector('#' + (searchBtn ? searchBtn.getAttribute('aria-controls') : ''));
    const setSearch = (open, focusBack) => {
      if (!searchBtn || !form) return;
      root.classList.toggle('is-search-open', open);
      searchBtn.setAttribute('aria-expanded', String(open));
      if (open) form.querySelector('input[type="search"]').focus();
      else if (focusBack) searchBtn.focus();
    };
    if (searchBtn && form) {
      searchBtn.addEventListener('click', () => setSearch(!root.classList.contains('is-search-open')));
      form.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && root.classList.contains('is-search-open')) { e.stopPropagation(); setSearch(false, true); }
      });
      form.addEventListener('focusout', (e) => {
        if (root.classList.contains('is-search-open') && !form.contains(e.relatedTarget) && e.relatedTarget !== searchBtn) setSearch(false);
      });
      document.addEventListener('click', (e) => {
        if (root.classList.contains('is-search-open') && !form.contains(e.target) && !searchBtn.contains(e.target)) setSearch(false);
      });
    }

    // --- ۳. حالت جمع‌وجور
    let reserve = 0;
    let ticking = false;
    const measure = () => {
      if (root.classList.contains('is-compact')) return;
      reserve = bar.offsetHeight;
      root.style.setProperty('--tth-reserve', reserve + 'px');
    };
    const update = () => {
      ticking = false;
      const y = window.scrollY;
      const compact = root.classList.contains('is-compact');
      // پسماند: پس از گذشتن از کل ارتفاع جمع می‌شود و فقط وقتی برمی‌گردد که جای نوار جمع‌وجور
      // دوباره دیده شود؛ پس هیچ‌وقت فضای خالی بالای صفحه نمی‌ماند.
      if (!compact && y > reserve) root.classList.add('is-compact');
      else if (compact && y < Math.max(0, reserve - bar.offsetHeight)) {
        root.classList.remove('is-compact');
        items.forEach((d) => close(d));
      }
    };
    measure();
    update();
    window.addEventListener('scroll', () => {
      if (!ticking) { ticking = true; requestAnimationFrame(update); }
    }, { passive: true });
    window.addEventListener('resize', () => {
      measure();
      update();
    });
    DESKTOP.addEventListener('change', () => {
      if (drawer && drawer.open) drawer.close();
      setSearch(false);
      root.classList.remove('is-compact');
      measure();
      update();
    });
  });
})();
