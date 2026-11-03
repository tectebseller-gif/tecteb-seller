# منوی همبرگری در دسکتاپ هنگام اسکرول — Tecteb Header 0.2.3

**وضعیت:** بررسی و پیشنهاد. **نسخهٔ تازه ساخته نشد**؛ ZIP ِ 0.2.3 دست‌نخورده است
(`eac2ed22…`). هیچ اقدامی روی Production یا Staging انجام نشد.

## ۱. منو مال کیست
مال **همین افزونه** است، نه قالب یا Elementor: `<dialog class="tth-drawer">` که با
`.tth-burger[data-tth-burger]` باز می‌شود (`tecteb-header/templates/header.php`).

## ۲. علت
رفتار عمدی، نه باگ اجرا. در `header/shared/header-base.css` (و خروجی‌اش `assets/css/header.css`):

```css
@media (min-width: 1080px) {
  .tecteb-header-v1[data-js].is-compact .tth-nav { display: none; }
  .tecteb-header-v1[data-js].is-compact .tth-top { … "burger logo search tools"; }
  .tecteb-header-v1[data-js].is-compact .tth-burger { display: inline-flex; }
}
```

`header.js` پس از اسکرول از `--tth-reserve` کلاس `is-compact` می‌گذارد؛ این قاعده در دسکتاپ
ردیف دوم («دسته‌بندی کالاها»، «همکاری تأمین‌کنندگان») را پنهان و همبرگری را نشان می‌دهد، و کشو
— که برای موبایل طراحی شده — حساب و دسته‌ها را تکرار می‌کند.

## ۳. راه‌حل پیشنهادی
حذف همین یک بلوک (`docs/proposal-desktop-compact-no-burger.patch`). نتیجه:
- دسکتاپ (≥۱۰۸۰px): همبرگری نه در بالا و نه پس از اسکرول؛ ردیف دوم در نوار چسبان می‌ماند.
- «حساب من» و مگامنو همان دسترسی‌ها را می‌دهند؛ هیچ پیوندی حذف نمی‌شود.
- زیر ۱۰۸۰px هیچ تغییری: همبرگری و کشوی RTL با حساب و دسته‌ها.
- **بهای آن:** ارتفاع نوار چسبان دسکتاپ پس از اسکرول از **۶۳px به ۱۱۰px** می‌رسد. محتوای
  صفحه جابه‌جا نمی‌شود. اگر پذیرفته شود، assert ِ ۶۳px در `tests/browser.mjs` خط ۱۴۱ باید
  به ۱۱۰px تغییر کند.

## ۴. آزمون‌ها (`tests/compact-desktop.mjs`، محلی)
| | کد فعلی 0.2.3 | با پیشنهاد |
|---|---|---|
| `compact-desktop.mjs` (۱۰۸۰/۱۲۸۰/۱۴۴۰/۱۹۲۰ + مدیر واردشده + ۱۰۲۴/۷۶۸/۳۹۰/۳۲۰) | ۲۶ قبول، ۸ شکست | **۴۲ قبول، ۰ شکست** |
| `browser.mjs` | — | ۱۵۷ قبول، ۱ شکست (فقط assert ِ ۶۳px؛ اندازه‌گیری‌شده ۱۱۰px و محتوا ثابت) |
| `isolation.mjs` (CSS ِ سراسری بدخواه) | — | ۹ حالت، ۰ تفاوت |

هشت شکستِ کد فعلی همان علامت گزارش‌شده‌اند: در هر چهار عرض دسکتاپ پس از اسکرول همبرگری دیده
می‌شود و «دسته‌بندی کالاها» در نوار نیست. شواهد: `evidence/compact-desktop/`.
