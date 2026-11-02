
/*
 * ۰٫۳٫۳ — دکمه‌های «قبلی/بعدی» ِ کاروسل «برندهای همکار». فهرست خودش یک اسکرول افقی بومی است (لمس،
 * چرخ، کلیدهای جهت بی این اسکریپت کار می‌کنند)؛ این‌جا فقط دو دکمه ظاهر می‌شوند، هر کدام به اندازهٔ
 * یک صفحهٔ دیدنی جلو/عقب می‌روند، و در دو انتها غیرفعال‌اند. هیچ حرکت خودکاری نیست. در RTL «بعدی»
 * یعنی به چپ: جهت از خودِ فهرست خوانده می‌شود، و scrollLeft در RTL منفی است.
 */
(function () {
  var lists = document.querySelectorAll('.tecteb-home-v1 [data-th-brands]');
  if (!lists.length) return;
  var still = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  lists.forEach(function (list) {
    var section = list.closest('section');
    var nav = section && section.querySelector('[data-th-brands-nav]');
    if (!nav) return;
    var buttons = nav.querySelectorAll('[data-th-brands-dir]');
    var rtl = getComputedStyle(list).direction === 'rtl';

    function update() {
      var room = list.scrollWidth - list.clientWidth;
      if (room <= 1) { nav.hidden = true; return; }
      nav.hidden = false;
      var done = Math.abs(list.scrollLeft);
      buttons.forEach(function (b) {
        var forward = b.getAttribute('data-th-brands-dir') === '1';
        b.disabled = forward ? done >= room - 1 : done <= 1;
      });
    }

    buttons.forEach(function (b) {
      b.addEventListener('click', function () {
        var step = Math.max(list.clientWidth * 0.85, 120) * Number(b.getAttribute('data-th-brands-dir'));
        list.scrollBy({ left: rtl ? -step : step, behavior: still ? 'auto' : 'smooth' });
      });
    });

    var frame = 0;
    list.addEventListener('scroll', function () {
      if (frame) return;
      frame = requestAnimationFrame(function () { frame = 0; update(); });
    }, { passive: true });
    if (window.ResizeObserver) new ResizeObserver(update).observe(list);
    else window.addEventListener('resize', update);
    list.querySelectorAll('img').forEach(function (img) { if (!img.complete) img.addEventListener('load', update, { once: true }); });
    update();
  });
})();
