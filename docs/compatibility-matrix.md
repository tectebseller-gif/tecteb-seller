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
| هر سه حالت روی **بسته تحویلی `0.1.0-alpha.2`** | **`Passed`** | اجرای تازه با PHP 8.1.32؛ در هر سه حالت `plugin_errors=0` · `acceptance/gates-0.1.0-alpha.2/G-07-summary.txt`. انگیزه: مالک گزارش کرده HPOS روی staging فعال است |
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
| **نصب روی `staging.tecteb.com`** | `0.1.0-alpha.1` | **User-Reported** (۱۱ سپتامبر، با تصاویر) | **`Installed by owner / Not Tested by us`** — مالک نصب و فعال کرده و محیط را گزارش کرده: PHP **8.1.34**، WordPress **7.1**، WooCommerce **11.0.1**، HPOS **فعال**. این‌ها شاهد ارائه‌شده مالک‌اند؛ ما هیچ آزمونی روی آن سایت اجرا نکرده‌ایم و هش بسته نصب‌شده هنوز گزارش نشده است (`docs/phase-1-report.md` بند ۵٫۱) |

## ۵. مرورگر و دسترس‌پذیری (CORE-05)

| آزمون | وضعیت | یادداشت |
|---|---|---|
| ۳۲۰ / ۳۷۵ / ۷۶۸ / ۱۰۲۴ / ۱۴۴۰ روی **wp-admin واقعی** | **`Passed`** | چهار صفحه با نشست معتبر مدیرکل، وردپرس روی **fa_IR و RTL**؛ در هیچ عرضی اسکرول افقی نبود · `acceptance/wpadmin-a11y/` |
| **شبیه‌سازی فضای چیدمان** ۶۴۰×۵۱۲ (`layout-space-640x512`) | **`Passed`** | emulation سطح CDP — فضای چیدمانِ ۱۲۸۰×۱۰۲۴ در ۲۰۰٪. **زوم نیست** و به این نام ثبت می‌شود |
| **device scale factor سطح مرورگر** (`browser-device-scale-2`) | **`Passed`** | Chromium جدا با `--force-device-scale-factor=2` و پنجره فیزیکی ۱۲۸۰×۱۰۲۴، `viewport: null` (بدون `Emulation.setDeviceMetricsOverride`)؛ شاهد: `devicePixelRatio=2 innerWidth=640 narrowMediaQuery=true rootZoom=1`. نام از روی مکانیزم است؛ «زوم ۲۰۰٪» نامیده نمی‌شود |
| **زوم واقعی صفحه از منوی مرورگر** (Ctrl+ / HostZoomMap) | `Not Run` | از Playwright/CDP قابل تنظیم نیست و اجرا نشد |
| **جعبه متن خفه‌نشده** (`no-starved-text-box`) | **`Passed`** | بررسی تازه پس از نقص staging: هر عنصر متنی باید دست‌کم حدود چهار نویسه فونت خودش پهنا داشته باشد. روی رابط قبلی `modules @ 1440` شکست می‌خورد — `redesign/regression-guard-on-old-ui.log` |
| **چیدمان بر پایه عرض ظرف** (`@container`) | **`Passed`** در Chromium ۱۴۱ | ADR-006. Firefox و Safari آزموده **نشده‌اند**؛ موتور بدون پشتیبانی container query چیدمان تک‌ستونی می‌گیرد (طراحی‌شده، اما اجرا نشده) |
| **ناحیه فروشنده `/vendor/`** — axe، چیدمان، کیبورد، اندازه لمسی | **`Passed`** | آزمون **مستقل** از صفحات مدیر: ۷۶ بررسی در پنج عرض، ۰ شکست · `docs/evidence/vendor/a11y/`. آزمون صفحات مدیر شاهد این ناحیه نیست و برعکس |
| axe-core (WCAG 2.2 AA) روی **wp-admin واقعی** | **`Passed`** | ۰ یافته داخل `.tmc-admin` و ۰ یافته در کل سند (`foreign_findings: []`) |
| کیبورد روی **wp-admin واقعی** | **`Passed`** | با **Tab واقعی**، بدون `focus()` و بدون تغییر `tabindex`: پس از ۵۸ توقف پوسته وردپرس اولین توقف افزونه لینک پرش است؛ Tab به **همه** کنترل‌های focusable می‌رسد؛ لینک پرش hash **و** فوکوس را به `#tmc-main` می‌برد |
| زبان و جهت واقعی مدیریت (fa_IR / RTL) **با بسته ترجمه حداقلی آزمایشی** | **`Passed`** | `html lang="fa-IR" dir="rtl"`، کلاس `rtl` روی body، شیوه‌نامه‌های `*-rtl.css` مدیریت — از سند سرو‌شده خوانده شد، نه از locale مرورگر. بسته: `tools/wp-lang/make-fa-ir-mo.py` |
| **ترجمه رسمی فارسی وردپرس** | `Not Run` | `translate.wordpress.org`/`downloads.wordpress.org` مسدودند. آزمون RTL با بسته حداقلی آزمایشی اجرا شد؛ رفتار افزونه با بسته رسمی **آزموده نشده** است |
| همان مجموعه با **WooCommerce فعال** | **`Passed`** | اجرای دوم، ۲۵۲ بررسی، ۰ شکست · `acceptance/wpadmin-a11y-with-wc/` |
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
