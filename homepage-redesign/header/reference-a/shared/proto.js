/*
 * فقط برای پیش‌نمایش — به سایت نمی‌رود.
 *  • تغییر حالت «مهمان / واردشده» (در اجرا سرور فقط یکی را چاپ می‌کند).
 *  • هیچ پیوندی در نمونه جایی نمی‌رود: کلیک، مقصد و منبعِ آن را در یک پیام می‌گوید،
 *    تا هیچ پیوند نمایشی به‌عنوان پیوند واقعی و عملیاتی دیده نشود.
 */
(() => {
  const SOURCES = {
    owner: 'نشانیِ اعلام‌شده در پیام مالک',
    wc: 'مسیر استاندارد ووکامرس — روی سایت واقعی سنجیده نشده',
    demo: 'نامعلوم در این محیط — جای پیوند واقعی منوی وردپرس',
    data: 'دادهٔ آزمایشی محلی (صفحهٔ اصلی ۰٫۲٫۰)',
  };
  const OWNER = ['/shop/', '/https-tecteb-com-repair-request/', '/landing-seller/'];
  const classify = (a) => {
    if (a.dataset.src) return a.dataset.src;
    const path = new URL(a.getAttribute('href'), 'https://example.invalid').pathname;
    if (OWNER.includes(path)) return 'owner';
    if (path === '/') return 'wc';
    return 'data';
  };

  const toast = document.createElement('p');
  toast.className = 'proto-toast';
  toast.setAttribute('role', 'status');
  document.body.append(toast);
  let hide = 0;
  const say = (text) => {
    toast.textContent = text;
    toast.classList.add('is-on');
    clearTimeout(hide);
    hide = setTimeout(() => toast.classList.remove('is-on'), 4200);
  };

  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[href]');
    if (!a || a.closest('.proto-bar') || a.getAttribute('href') === '#main') return;
    e.preventDefault();
    say(`نمونهٔ نمایشی — مقصد: ${decodeURI(a.getAttribute('href'))} · ${SOURCES[classify(a)]}`);
  });
  document.addEventListener('submit', (e) => {
    e.preventDefault();
    const q = new FormData(e.target).get('s') || '';
    say(`نمونهٔ نمایشی — جست‌وجوی ووکامرس برای «${q}» (?s=…&post_type=product)`);
  });

  const header = document.querySelector('[data-tth-header]');
  document.querySelectorAll('[data-proto-user]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const member = btn.dataset.protoUser === 'member';
      header.dataset.user = member ? 'member' : 'guest';
      header.querySelectorAll('[data-tth-guest]').forEach((n) => { n.hidden = member; });
      header.querySelectorAll('[data-tth-member]').forEach((n) => { n.hidden = !member; });
      document.querySelectorAll('[data-proto-user]').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
    });
  });
})();
