/*
 * Concept C — تنها اسکریپت صفحه، و اختیاری: نور نرمی که در Hero دنبال اشاره‌گر
 * می‌رود. بی این فایل صفحه کامل است و هیچ محتوایی پنهان نمی‌ماند.
 * فقط روی دستگاه با اشاره‌گر دقیق و فقط وقتی کاربر حرکت را کم نکرده باشد.
 * یک شنونده، با requestAnimationFrame تا در هر فریم حداکثر یک بار بنویسد.
 */
(function () {
  if (!window.matchMedia) return;
  if (!matchMedia('(pointer: fine)').matches) return;
  if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  document.querySelectorAll('.tecteb-home-v1 [data-th-light]').forEach(function (hero) {
    var frame = 0, x = 0, y = 0;
    hero.addEventListener('pointermove', function (e) {
      var r = hero.getBoundingClientRect();
      x = e.clientX - r.left;
      y = e.clientY - r.top;
      if (frame) return;
      frame = requestAnimationFrame(function () {
        frame = 0;
        hero.style.setProperty('--th-mx', x + 'px');
        hero.style.setProperty('--th-my', y + 'px');
        hero.classList.add('is-lit');
      });
    }, { passive: true });
    hero.addEventListener('pointerleave', function () { hero.classList.remove('is-lit'); });
  });
})();
