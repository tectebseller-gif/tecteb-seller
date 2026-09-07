# ماتریس سازگاری — فاز ۱

بازبینی ۲ · ۷ سپتامبر ۲۰۲۶ · پس از پیاده‌سازی فاز ۱.

سطرهایی که **در این محیط اجراشدنی بودند** اکنون نتیجه واقعی دارند و به log
ارجاع می‌دهند. سطرهای وابسته به WordPress/WooCommerce پس از اجرای پروتکل پذیرش
روی یک WordPress یکبارمصرف به‌روز شده‌اند؛ آنچه هنوز اجرا نشده صریح `Not Run` است،
چون هیچ‌کدام قابل دانلود نبودند. هیچ سطری بدون log قابل بازتولید تغییر نکرده
است. شواهد: `docs/evidence/`، جمع‌بندی: `docs/phase-1-report.md`.

## واژگان وضعیت (COMP-01: «unsupported و not-tested از passed جدا باشند»)

| وضعیت | معنی |
|---|---|
| `Passed` | آزمون مشخص با دستور، نسخه و exit code ثبت‌شده اجرا شد و قبول شد |
| `Failed` | همان، ولی رد شد |
| `Not Run` | آزمون تعریف شده ولی در این محیط اجرا نشده؛ دلیل و روش اجرا ذکر می‌شود |
| `Not Tested` | برای این ترکیب آزمونی تعریف نشده |
| `Unsupported` | آگاهانه پشتیبانی نمی‌شود (مثل Multisite در فاز ۱) |
| `User-Reported` | فقط اعلام مالک؛ **شاهد نیست** |

## ۱. زمان اجرا (runtime)

| مؤلفه | نسخه | منبع نسخه | وضعیت | دلیل / شاهد |
|---|---|---|---|---|
| PHP | 8.4.19 | اندازه‌گیری در کانتینر | **`Passed`** — ۱۱۳ فایل lint، ۲۲۷ تست در پنج suite | همان پروتکل، اجرای دوم · `docs/evidence/acceptance/php84/` · lint و suiteها: `docs/evidence/lint.log`، `unit.log` |
| PHP | 8.1.32 (ساخته‌شده از سورس) | اندازه‌گیری در کانتینر | **`Passed`** (اجرا) | بسته‌های آماده مسدودند، پس PHP 8.1.32 از `github.com/php/php-src` (تگ `php-8.1.32`) کامپایل شد. کل پروتکل گیت‌ها با CLI، WP-CLI و SAPI وب همگی روی ۸٫۱٫۳۲ اجرا شد · `docs/evidence/acceptance/php81/` |
| PHP | 8.1.34 | **User-Reported** | `Not Run` | نسخه دقیق سایت مالک؛ آنچه آزموده شد ۸٫۱٫۳۲ است |
| PHP | 8.2 / 8.3 | — | `Not Tested` | خارج از دامنه اعلامی |
| WordPress | 7.1 | نصب واقعی در کانتینر | **`Passed`** | هسته از `github.com/WordPress/WordPress` تگ `7.1`؛ سایت یکبارمصرف با داده مصنوعی، `WP_DEBUG` روشن · `docs/evidence/acceptance/*/00-environment.txt` |
| WordPress | 6.3 (هدر `Requires at least`) | **حداقل پیشنهادی** | `Not Run` | آزموده‌نشده و بدون مبنای فنی مستند. تا محاسبه پایین‌ترین نسخه از روی APIهای مصرفی **و** اجرای پروتکل بند ۴ نصب، «حداقل پشتیبانی‌شده» خوانده نمی‌شود (`docs/installation.md` §۲٫۱) |
| WooCommerce | 11.0.1 | نصب واقعی در کانتینر | **`Passed`** | بسته رسمی release (sha256 `88837ea0…ae494c`)؛ خود WooCommerce افزونه را در فهرست «سازگار با HPOS» می‌آورد · `docs/evidence/acceptance/php81/G-07-compatibility-info.txt` |
| MySQL / MariaDB | MariaDB 10.11.14 | نصب و اجرا در کانتینر | **`Passed`** — ۲۲ تست، DDL/ایندکس/قفل/هم‌زمانی/نوشتن guarded/پاک‌سازی | فقط جدول audit؛ **معادل نصب WP نیست** · `docs/evidence/database.log` |
| MySQL سایت | ? | نامعلوم | `Not Tested` | نسخه DB سایت گزارش نشده |

## ۲. حالت‌های سفارش WooCommerce (COMP-01)

| حالت | وضعیت | یادداشت |
|---|---|---|
| HPOS روشن، sync روشن | **`Passed`** | وضعیت مؤثر از خود WooCommerce: `hpos_enabled=1 sync_enabled=1 table_exists=1`؛ REST همان را گزارش کرد · `G-07-hpos-sync-on-*` |
| HPOS روشن، sync خاموش | **`Passed`** | `1/0/1`؛ گزارش REST `True` · `G-07-hpos-sync-off-*` |
| ذخیره قدیمی سفارش (posts) | **`Passed`** | `wp wc hpos disable` → `0/0/1`؛ گزارش REST `False` · `G-07-legacy-*` |
| Checkout کلاسیک / Block | `Not Tested` | فاز ۱ هیچ checkout لمس نمی‌کند |

**تذکر CORE-10:** اعلام سازگاری HPOS با `FeaturesUtil` در کد، «تست‌شدن»
نیست. صفحه سلامت «HPOS فعال» و «HPOS آزموده‌شده» را دو فیلد جدا نشان
می‌دهد؛ دومی همچنان `unknown/Not Run` است و در هر سه حالت HPOS همین را نشان
داد. آنچه اجرا شد، **رفتار افزونه** در سه حالت است، نه آزمون سازگاری سفارش‌ها.

## ۳. WooCommerce غایب

| سناریو | وضعیت | یادداشت |
|---|---|---|
| فعال‌سازی بدون WooCommerce → notice فارسی، بدون fatal، feature بالا نمی‌آید | **`Passed`** (نصب واقعی) | گیت G-01 روی سایت واقعی: چهار صفحه با HTTP 200، بدون خطای PHP، و notice «WooCommerce فعال نیست» روی پیشخوان · `G-01-pages.txt`، `G-01-wc-notice.txt` |

## ۴. سرور، قالب و افزونه‌های سایت (DEC-06-b)

| مؤلفه | نسخه | منبع | وضعیت |
|---|---|---|---|
| LiteSpeed | ? | User-Reported | `Not Run` |
| Hello Elementor | 3.5.1 | User-Reported | `Not Run` |
| Persian Woo | 10.0.4 | User-Reported | `Not Run` |
| Rank Math / WP Rocket | ? | سند مادر ۱۵ | `Not Tested` — inventory نشده |
| Multisite | — | — | **`Unsupported`** در فاز ۱؛ network activation با پیام فارسی رد می‌شود |

## ۵. مرورگر و دسترس‌پذیری (CORE-05)

| آزمون | وضعیت | یادداشت |
|---|---|---|
| ۳۲۰ / ۳۷۵ / ۷۶۸ / ۱۰۲۴ / ۱۴۴۰ روی **wp-admin واقعی** | **`Passed`** | چهار صفحه با نشست معتبر مدیرکل؛ در هیچ عرضی اسکرول افقی نبود · `acceptance/wpadmin-a11y/` |
| Zoom 200% روی **wp-admin واقعی** | **`Passed`** | ۱۲۸۰×۱۰۲۴ در مقیاس ۲ (≈۶۴۰×۵۱۲ CSS)؛ `scrollWidth=clientWidth=640` روی هر چهار صفحه |
| axe-core (WCAG 2.2 AA) روی **wp-admin واقعی** | **`Passed`** | ۰ یافته داخل `.tmc-admin` و ۰ یافته در کل سند (`foreign_findings: []`) |
| کیبورد روی **wp-admin واقعی** | **`Passed`** | لینک پرش اولین توقف افزونه است و واقعاً به `#tmc-main` می‌رسد؛ همه توقف‌ها حلقه focus دارند؛ خلاصه خطا هنگام بارگذاری focus می‌گیرد |
| همان مجموعه با **WooCommerce فعال** | **`Passed`** | اجرای دوم، ۱۸۸ بررسی، ۰ شکست · `acceptance/wpadmin-a11y-with-wc/` |
| همان روی harness رندر view (Chromium 141) | **۲۳۳ بررسی، ۰ خطا** | **فقط «نمونه رابط»** — اثبات کارکرد افزونه نیست · `browser-checks.json` |
| Screen reader دستی | `Not Run` | ابزار خودکار جای بررسی دستی نیست |
| مرورگرهای غیر Chromium (Firefox، Safari) | `Not Run` | هر دو اجرا روی Chromium 141 بودند |
| عدم بارگذاری asset در Posts و homepage | **`Passed`** | گیت G-08 با نشست معتبر مدیرکل: `edit.php`، `index.php`، `plugins.php`، `options-general.php`، `users.php` و صفحه اصلی → صفر ارجاع؛ صفحه افزونه → css و js هر دو · `G-08-assets.txt` |

## ۶. نحوه رساندن سطرهای `Not Run` به `Passed`

سطرهای نصب/HPOS/دسترس‌پذیری با اجرای واقعی پروتکل به `Passed` رسیده‌اند
(`docs/evidence/acceptance/`). برای سطرهایی که **هنوز** `Not Run` هستند،
«اجرا» یعنی همان پروتکل با مدرک خروجی هر گیت — نه اجرای چند دستور و ندیدن خطا:

1. **در همین محیط، برای آنچه قابل تهیه بود:** WordPress از تگ گیت‌هاب،
   WooCommerce از بسته release رسمی، و PHP 8.1.32 از سورس ساخته شد؛
   `tools/acceptance-gates.sh` کل پروتکل را بازتولید می‌کند و
   `tools/browser/check-wpadmin.mjs` بررسی‌های دسترس‌پذیری wp-admin را.
   آنچه در همین محیط ممکن نشد: PHP **8.1.34** (نه ۸٫۱٫۳۲)، سرور وب واقعی،
   و افزونه‌های تجاری سایت.
2. **در محیط مالک:** اجرای همان دستورها روی یک WordPress **disposable با
   داده مصنوعی** و برگرداندن خروجی (log، نسخه‌ها، exit code، screenshot).
   نصب روی Staging یا production سایت **خارج از این درخواست** است.

هیچ سطری بدون log قابل بازتولید به `Passed` تغییر نمی‌کند. برای گیت‌های نصب،
«log قابل بازتولید» یعنی فایل مدرک همان گیت (`G-0x-*`) به‌همراه نسخه‌های ثبت‌شده
محیط (WordPress، WooCommerce، PHP وب و CLI، MySQL/MariaDB).
