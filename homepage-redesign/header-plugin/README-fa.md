# Tecteb Header 0.1.0 — هدر سراسری تک‌طب (طرح B)

> **برای بررسی، نه نصب فوری.** هیچ نصب، فعال‌سازی یا تغییری روی Staging یا سایت اصلی انجام نشده
> است؛ هر کدام نیازمند تأیید جداگانهٔ مالک است.

افزونهٔ مستقل وردپرس که هدر B ِ تأییدشده را نمایش می‌دهد و پس از تأیید می‌تواند هدر Elementor
(قالب «Header»، ۲۶۵۲) را در همهٔ صفحه‌ها **جایگزین** کند — بی حذف یا ویرایش آن قالب.

- بسته: `dist/tecteb-header-0.1.0.zip` · SHA-256 در `dist/SHA256SUMS`
- سورس: `tecteb-header/` · آزمون‌ها: `tests/` · شواهد: `evidence/`
- اسناد: `docs/test-report-fa.md` (آزمون‌ها و اجرانشده‌ها) ·
  `docs/limitations-fa.md` (Elementor، JetEngine، LiteSpeed) ·
  `docs/staging-checklist-fa.md` · `docs/migration-rollback-fa.md` (مستند، اجرا نشده)

## چه چیزی نمایش می‌دهد
لوگو، جست‌وجوی محصولات، حساب کاربری، سبد خرید، علاقه‌مندی‌ها (اختیاری)، منوی اصلی، دکمهٔ «همکاری
تأمین‌کنندگان»، «دسته‌بندی کالاها» (Mega Menu دوستونه) و کشوی موبایل. **دسکتاپ و موبایل از یک منبع**
می‌خوانند. CSS و JS همان فایل‌های نمونهٔ B اند، بایت‌به‌بایت.

| بخش | منبع |
|---|---|
| دسته‌ها و زیردسته‌ها | `product_cat` ِ ووکامرس؛ تنظیمات فقط شناسه، ترتیب و روشن/خاموش |
| پیوندهای منو | یک فهرست وردپرس («نمایش ← فهرست‌ها»)، سطح اول |
| حساب | `wc_get_account_menu_items()`، `wc_logout_url()` |
| سبد | `WC()->cart` و `wc_get_cart_url()` |
| جست‌وجو | جست‌وجوی استاندارد محصولات ووکامرس (یا shortcode ِ افزونهٔ جست‌وجوی سایت) |
| علاقه‌مندی | پیش‌فرض: نمایش داده نمی‌شود؛ «پیوند» بی شمارنده — افزونهٔ فعلی شناسایی نشده |

## پیشخوان: تک‌طب ← مدیریت هدر
اگر منوی «تک‌طب» در پیشخوان هست، زیر همان؛ وگرنه ساخته می‌شود. مجوز `manage_options`. پنج زبانه:
**دسته‌بندی‌ها** (ترتیب، روشن/خاموش، آیکون PNG/WebP از کتابخانهٔ رسانه، زیردسته‌های منتخب فقط از
زیرمجموعه‌ها، پنهان‌کردن دستهٔ بی‌محصول — پیش‌فرض خاموش، پیش‌نمایش) · **انتقال از منوی فعلی**
(فقط‌خواندنی؛ ۱۳ دسته با شناسهٔ واقعی) · **تنظیمات** (منو، لوگو، جست‌وجو، علاقه‌مندی، سبد، فونت) ·
**جایگزینی هدر** · **دادهٔ ذخیره‌شده**. بی JavaScript هم کامل کار می‌کند.

پس از نصب **هیچ چیزی ذخیره و هیچ چیزی روی سایت عوض نمی‌شود**: بی دسته، بی جایگزینی.

## جایگزینی هدر ۲۶۵۲
گزینهٔ «جایگزینی هدر» (پیش‌فرض خاموش): هر جا Elementor Pro هدر Theme Builder را چاپ می‌کند، همان
خروجی کنار گذاشته و این هدر همان‌جا چاپ می‌شود. اگر Elementor هدری چاپ نکند، این افزونه هم نمی‌کند؛
پس **دو هدر هم‌زمان ممکن نیست**. فوتر، قالب ۲۶۵۲ و شرط نمایشش دست نمی‌خورند.
پیش‌نمایش فقط برای مدیر: `?tth_preview=1` (هدر افزونه) و `?tth_original=1` (هدر Elementor).
**بازگشت:** خاموش‌کردن گزینه یا غیرفعال‌کردن افزونه، و Purge کش. راه دوم: `[tecteb_header]` در یک
قالب هدر تازهٔ Theme Builder. جزئیات: `docs/migration-rollback-fa.md`.
⛔ با Elementor Pro ِ واقعی سنجیده نشده — فقط با شبیه‌ساز (`docs/limitations-fa.md`).

## داده و امنیت
- تنها نوشتن: option های `tecteb_header_settings` و `tecteb_header_gen` و transient ِ دسته‌ها.
  حذف افزونه فقط همین‌ها را پاک می‌کند. هیچ نوشتنی روی دسته، محصول، فهرست، Elementor یا ووکامرس.
- ذخیره از Settings API (nonce و مجوز را وردپرس می‌سنجد)؛ هر شناسه، آیکون، نشانی و shortcode پیش از
  ذخیره اعتبارسنجی می‌شود و دوباره در نمایش؛ هر خروجی escape می‌شود.
- CSS فقط زیر `.tecteb-header-v1` (جلو) و `.tth-hdr` (پیشخوان).
- بی وابستگی به Dokan، Tecteb Marketplace یا `tecteb-homepage` (که دست‌نخورده و مسئول صفحهٔ اصلی است).

## آزمون‌ها (همه روی وردپرس یکبارمصرف محلی)
ایستا ۵۷ · PHPCS (امنیت WordPress + سازگاری PHP 8.1) ۰ خطا · یکپارچه ۱۰۷ · مرورگر ۱۳۷ و axe ۰
تخلف در ۱۷ حالت · چرخهٔ عمر از ZIP ۱۹ · falsification ۲۴/۲۴. فهرست اجرانشده‌ها در `docs/test-report-fa.md`.

```bash
python3 tests/static/check.py
(cd ../plugin && vendor/bin/phpcs --standard=../header-plugin/phpcs.xml.dist)
wp eval-file tests/fixtures/seed.php seed && wp eval-file tests/integration/run.php
NODE_PATH=/opt/node-tools/node_modules node tests/browser.mjs
bash tools/build.sh && bash tests/lifecycle.sh
python3 tests/falsify.py
```

فیلترها: `tecteb_header_capability`، `tecteb_header_parent_slug`، `tecteb_header_replace`،
`tecteb_header_should_enqueue`، `tecteb_header_wishlist_count`، `tecteb_header_skip_target`.
اکشن: `tecteb_header_purge`. فونت Vazirmatn، SIL OFL 1.1.
