/*
 * ۰٫۳٫۳ — «انتخاب لوگو» در «ووکامرس ← صفحهٔ اصلی ← برندهای همکار»: کتابخانهٔ رسانهٔ خودِ وردپرس
 * (wp.media)، فقط تصویر. شناسهٔ پیوست در همان کادر عددی فرم نوشته می‌شود و پیش‌نمایش عوض می‌شود؛
 * ذخیره همچنان با دکمهٔ فرم است (nonce و مجوزِ همان صفحه). بی این اسکریپت، شناسه را دستی بنویسید.
 */
(function () {
  if (!window.wp || !wp.media) return;
  document.addEventListener('click', function (e) {
    var button = e.target.closest('.tth-pick-logo');
    if (!button) return;
    e.preventDefault();
    var input = document.getElementById(button.getAttribute('data-tth-target'));
    var preview = document.querySelector('[data-tth-preview="' + button.getAttribute('data-tth-target') + '"]');
    var frame = wp.media({ title: button.textContent, library: { type: 'image' }, multiple: false });
    // باز شدن روی «کتابخانهٔ رسانه»، نه «بارگذاری» (پیش‌فرض وردپرس وقتی کتابخانه هنوز خوانده نشده).
    frame.on('open', function () { frame.content.mode('browse'); });
    frame.on('select', function () {
      var a = frame.state().get('selection').first().toJSON();
      input.value = String(a.id);
      if (preview) {
        var url = (a.sizes && a.sizes.medium) ? a.sizes.medium.url : a.url; // بی برش، مثل پیش‌نمایش سمت سرور
        var img = document.createElement('img');
        img.src = url;
        img.alt = a.alt || '';
        img.style.maxHeight = '48px';
        img.style.maxWidth = '10rem';
        img.style.width = 'auto';
        preview.replaceChildren(img);
      }
    });
    frame.open();
  });
})();
