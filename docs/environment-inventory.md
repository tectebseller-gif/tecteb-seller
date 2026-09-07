# گزارش inventory محیط — Preflight فاز ۱ (DEC-06-a)

بازبینی ۲ · اصلاح‌شده طبق بازخورد مالک.

تاریخ اجرا: ۶ سپتامبر ۲۰۲۶ · محیط: کانتینر اجرای از راه دور Claude Code
(Linux 6.18، Ubuntu noble) · مخزن: `tectebseller-gif/tecteb-seller`

> این گزارش فقط چیزی را ثبت می‌کند که **در همین کانتینر اندازه‌گیری شده**.
> نسخه‌های گزارش‌شده مالک محصول در ستون جداگانه‌اند و تأیید نشده‌اند.

## ۱. وضعیت مخزن

| مورد | مقدار مشاهده‌شده |
|---|---|
| مسیر | `/home/user/tecteb-seller` |
| شاخه جاری | `claude/new-session-z1vafy` |
| تعداد commit | **صفر** (`No commits yet`) |
| فایل‌های موجود | هیچ؛ فقط `.git/` |
| سورس افزونه قبلی | **وجود ندارد** |
| `CLAUDE.md` | وجود ندارد |
| `.claude/` | وجود ندارد |

**نتیجه inventory سورس قبلی (بازبینی ۲):** مخزن کاملاً خالی است. این فقط
ثابت می‌کند که **در همین مخزن** سورسی وجود ندارد؛ **اثبات نمی‌کند** که هیچ
سورس قبلی در جای دیگری نیست. مالک سابقه اسکلت `tecteb-marketplace-v0.1.1.zip`
را گزارش کرده که در این جلسه دریافت نشده و محتوایش نامعلوم است. وضعیت
دریافت و استفاده از آن: **تعیین‌نشده** — جزئیات، پیامدها و گزینه‌های نگاشت
هویت در `docs/decision-log.md` بند ۴. تا تعیین تکلیف، فرض «هیچ سورس قبلی
وجود ندارد» قطعی نیست. slug افزونه طبق A.6 سند مادر `tecteb-marketplace-core`
است و نام مخزن (`tecteb-seller`) لازم نیست با آن یکی باشد.

## ۲. ابزارهای اندازه‌گیری‌شده

| ابزار | وضعیت در این کانتینر | نسخه گزارش‌شده مالک |
|---|---|---|
| PHP | ✅ **۸٫۴٫۱۹** (`/usr/bin/php8.4`, NTS) | ۸٫۱٫۳۴ |
| PHP 8.1 | ❌ **قابل نصب نیست** — مخزن `ppa.launchpadcontent.net` با HTTP 403 از proxy مسدود است (index کش شده، اما دانلود `.deb` رد می‌شود) | — |
| Composer | ✅ نصب‌شده؛ `repo.packagist.org` → HTTP 200 | — |
| Node.js / npm | ✅ ۲۲٫۲۲٫۲ / ۱۰٫۹٫۷؛ `registry.npmjs.org` → HTTP 200 | — |
| Python | ✅ ۳٫۱۱٫۱۵ | — |
| Git | ✅ ۲٫۴۳٫۰ | — |
| zip / unzip | ✅ Info-ZIP 3.0 | — |
| Chromium + Playwright | ✅ از پیش نصب (`/opt/pw-browsers`) | — |
| MySQL / MariaDB | ✅ نصب و اجرا شد — MariaDB 10.11.14 از apt | — |
| WordPress core | ✅ **تهیه شد** — `downloads.wordpress.org` همچنان 403، اما git ناشناس روی `github.com/WordPress/WordPress` باز است؛ تگ `7.1` کلون شد | WP 7.1 |
| WooCommerce | ✅ **تهیه شد** — بسته release رسمی از گیت‌هاب (`88837ea0…ae494c`) | WC 11.0.1 |
| WP-CLI | ✅ نصب شد — phar 2.12.0 از `github.com/wp-cli/builds` | — |
| PHPUnit | ✅ نصب شد — 10.5.64 از packagist | — |
| LiteSpeed / Hello Elementor / Persian Woo | ❌ خارج از دسترس این کانتینر | گزارش کاربر |

### افزونه‌های PHP موجود
`curl gd intl json mbstring mysqli mysqlnd openssl pdo pdo_mysql pdo_sqlite
sqlite3 sodium tokenizer xml xmlreader xmlwriter xsl zip zlib` و بقیه پیش‌فرض.

## ۳. اثر مستقیم بر ماتریس آزمون

این جدول از ۷ سپتامبر ۲۰۲۶ به‌روزرسانی شده است. آنچه در نخستین ثبت
«قابل دور زدن نیست» بود، بعداً از راه دیگری تهیه شد (git ناشناس گیت‌هاب برای
WordPress و WooCommerce، و کامپایل PHP 8.1 از سورس). آنچه هنوز ممکن نشده،
همچنان `Not Run` است — نه `Passed`:

| گیت | امکان اجرا در این کانتینر | نتیجه |
|---|---|---|
| PHP lint با **PHP 8.1** | ✅ PHP 8.1.32 از سورس ساخته شد (`php/php-src`, تگ `php-8.1.32`) | **`Passed`** — کل پروتکل گیت‌ها روی ۸٫۱٫۳۲ اجرا شد |
| PHP lint با PHP 8.4 | ✅ | قابل اجرا |
| سازگاری نحوی/API با PHP 8.1 | ✅ به‌صورت **ایستا** با `PHPCompatibility` و `--runtime-set testVersion 8.1` | شاهد ایستا، نه اجرای واقعی |
| Unit test خالص (Domain بدون WP) | ✅ PHPUnit از packagist | قابل اجرا |
| DDL/migration روی MySQL واقعی | ✅ با نصب mariadb از apt | قابل اجرا |
| **نصب و فعال‌سازی واقعی WordPress** | ✅ WordPress یکبارمصرف با داده مصنوعی | **`Passed`** — `docs/evidence/acceptance/` |
| **WordPress integration test** | ✅ گیت‌های G-01..G-09 روی همان سایت | **`Passed`** |
| HPOS on/off/sync در محیط واقعی | ✅ WooCommerce 11.0.1 فعال | **`Passed`** — هر سه حالت، `G-07-*` |
| مرورگر/دسترس‌پذیری روی wp-admin واقعی | ✅ Chromium با نشست واقعی مدیرکل | **`Passed`** — ۲×۱۸۸ بررسی، `acceptance/wpadmin-a11y*/` |
| مرورگر/دسترس‌پذیری روی harness رندر view | ✅ Chromium + axe | شاهد جزئی، **نه** wp-admin واقعی |

**پیامد قراردادی:** طبق بند «تست و تحویل» پرامپت، گیت نصب Staging نیاز به
«نصب/فعال‌سازی واقعی» دارد. این مورد از ۷ سپتامبر ۲۰۲۶ **اجرا و قبول شده**
است (`docs/evidence/acceptance/`)؛ برچسب ZIP به همان وضعیت به‌روزرسانی شد و
همچنان می‌گوید روی سایت مالک نصب نشده است.

**قاعده تصاویر:** هر screenshot یا نتیجه مرورگر که خارج از WordPress (روی
harness رندر view) گرفته شود فقط **«نمونه رابط»** است، نه اثبات کارکرد
افزونه؛ در گزارش ستون جدا دارد و به هیچ CORE-ID `Passed` نمی‌دهد. این قاعده
سر جایش است و به `docs/evidence/screenshots/` مربوط می‌شود. تصاویر
`docs/evidence/acceptance/wpadmin-a11y*/screenshots/` از **خود wp-admin** با
نشست معتبر گرفته شده‌اند و نوار مدیریت وردپرس در آن‌ها پیداست؛ آن‌ها نمونه
رابط نیستند و شاهد همان سطرهای `Passed` در ماتریس‌اند.

**جایگاه این گزارش در DEC-06:** این فایل فقط جزء **DEC-06-a (شناسایی محیط
توسعه)** را می‌بندد. DEC-06-b (تطبیق محیط سایت) و DEC-06-c (آزمون سازگاری)
همچنان **باز و Not Run** هستند — `docs/decision-log.md` بند ۲.

## ۴. Skillهای واقعاً نصب‌شده

مسیر پروژه‌ای `.claude/skills/` **وجود ندارد**. Skillهای فعال حساب کاربر در
زمان preflight اینها بودند:

`import-memory`, `morning`, `skill-creator`, `xlsx`, `pptx`, `pdf`, `docx`

هیچ‌یک از پنج حوزه موردنیاز پرامپت نصب نیست:

| حوزه موردنیاز | وضعیت |
|---|---|
| `wordpress-architecture` (CORE-01/02) | ❌ نصب نیست |
| `woocommerce-hpos` (CORE-10) | ❌ نصب نیست |
| `security-privacy` (CORE-03/04/06/08/09) | ❌ نصب نیست |
| `rtl-accessibility` (CORE-05) | ❌ نصب نیست |
| `testing-release` (CORE-01..10) | ❌ نصب نیست |

طبق بند «Skillها و تنظیمات پروژه»: در نبود Skill، **قواعد خود پرامپت
لازم‌الاجرا هستند** و هیچ ادعای «Skill اجرا شد» ثبت نمی‌شود.

## ۵. دسترسی شبکه اندازه‌گیری‌شده

| مقصد | نتیجه |
|---|---|
| `repo.packagist.org` | ✅ 200 |
| `registry.npmjs.org` | ✅ 200 |
| `api.github.com` | ✅ 200 |
| archive اصلی Ubuntu | ✅ در دسترس |
| `downloads.wordpress.org` | ❌ 403 |
| `codeload.github.com/WordPress/WordPress` | ❌ 403 |
| `ppa.launchpadcontent.net` (PHP 8.1) | ❌ 403 |

این محدودیت‌ها مربوط به **محیط ساخت** هستند و ربطی به قاعده CORE-04
(بسته‌بودن خروجی‌های runtime افزونه) ندارند؛ آن قاعده جداگانه در کد اعمال
می‌شود.
