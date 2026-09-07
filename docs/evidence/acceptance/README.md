# شواهد پذیرش عملی فاز ۱

خروجی خام اجرای پروتکل بند ۴ `docs/installation.md` روی یک **WordPress
یکبارمصرف** با داده مصنوعی. `tecteb.com` و `staging.tecteb.com` لمس نشدند.

| پوشه | چیست |
|---|---|
| `php81/` | اجرای کامل روی **PHP 8.1.32** (CLI، WP-CLI و SAPI وب، هر سه) |
| `php84/` | همان پروتکل روی **PHP 8.4.19** |
| `failure-617fcfc/` | شکست مشاهده‌شده گیت G-04 روی بسته‌ای که برای پذیرش ارائه شده بود |

بسته آزموده‌شده در `php81/` و `php84/`:
`c37f8902ef3152bfc897114f7044f546d521783528e2c9f38c1d3442227f46f1`
(`00-environment.txt` هر پوشه همین را همراه نسخه‌های محیط ثبت کرده است).

## نقشه فایل‌ها

| الگو | گیت |
|---|---|
| `00-environment.txt`، `00-debug-flags.txt`، `00-session-check.txt` | آماده‌سازی مشترک |
| `G-01-*` | نصب اولیه و فعال‌سازی |
| `G-02-*` | فعال‌سازی مجدد (ایدمپوتنت) |
| `G-03-matrix.txt` | دسترسی UI برای مهمان / فاقد مجوز / مدیرکل |
| `G-04-*` | ذخیره تنظیمات با چهار هویت/حالت |
| `G-05-*` | REST سلامت با پنج حالت + هدرها + بررسی نشتی |
| `G-06-*` | حفظ داده هنگام غیرفعال‌سازی و حذف |
| `G-07-*` | سه حالت HPOS + گزارش سازگاری خود WooCommerce |
| `G-08-assets.txt` | بارگذاری asset فقط در صفحه‌های افزونه |
| `G-09-php.txt` | نسخه PHP در CLI، WP-CLI و وب |
| `M-migration-and-recovery.txt` | ارتقا بدون فعال‌سازی مجدد، cooldown و retry، رکورد کهنه و بازیابی |
| `html-captures.tar.gz` | بدنه خام هر درخواست HTTP همان اجرا |

`*-plugin-errors.txt` در همه گیت‌ها صفر خط است: هیچ
`Fatal`/`Warning`/`Notice`/`Deprecated` مربوط به `tecteb-marketplace-core`
در `debug.log` دیده نشد.

## آنچه این شواهد ثابت نمی‌کنند

اجرا با SAPI `cli-server` بود، نه Apache/LiteSpeed + PHP-FPM؛ روی PHP 8.1.32
بود، نه 8.1.34 سایت مالک؛ و هیچ‌کدام از افزونه‌های موجود سایت (LiteSpeed،
Hello Elementor، Persian Woo، Rank Math، WP Rocket) حاضر نبودند.
`docs/compatibility-matrix.md` این سطرها را `Not Run` نگه می‌دارد.
