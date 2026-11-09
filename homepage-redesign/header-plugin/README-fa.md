# Tecteb Header 0.2.6 — هدر سراسری مستقل تک‌طب (طرح B)

> **0.2.6 (سرعت):** CSS، فونت‌ها و دارایی‌های ابزارک‌های قالب هدر Elementor که جایگزین می‌شود (و چاپ نمی‌شود) دیگر در
> `<head>` بارگذاری نمی‌شوند — روی Elementor ِ واقعی ۸ فایل CSS ِ مسدودکننده و یک JS کمتر. HTML ِ هدر بایت‌به‌بایت همان 0.2.5.
> جزئیات: **[`docs/release-0.2.6-fa.md`](docs/release-0.2.6-fa.md)** و گزارش کامل: **[`../perf/REPORT-0.2.6-0.3.7-fa.md`](../perf/REPORT-0.2.6-0.3.7-fa.md)**.

> **0.2.5 (سرعت):** هدر از اولین رسم در حالت نهایی است (پرش هدر موبایل و CLS ِ صفحهٔ اصلی برطرف)، لوگوی WebP ِ
> بی‌اتلاف با fetchpriority، `tecteb-header.css` درون‌خطی و `header.css` بی توضیح. ظاهر و رفتار بی‌تغییر. جزئیات:
> **[`docs/release-0.2.5-fa.md`](docs/release-0.2.5-fa.md)** و گزارش کامل سرعت: **[`../perf/REPORT-fa.md`](../perf/REPORT-fa.md)**.

> **0.2.4:** هدر چسبندهٔ دسکتاپ پس از اسکرول یک ردیف ۶۳ پیکسلی است — لوگو، «دسته‌بندی کالاها» با Mega Menu،
> جست‌وجو، «حساب من» و سبد — بی منوی همبرگری. حالت عادی، تبلت و موبایل بی تغییر. فقط CSS. جزئیات، آزمون‌ها و
> بازگشت: **[`docs/release-0.2.4-fa.md`](docs/release-0.2.4-fa.md)**.

> **0.2.3:** ردیف منوی دسکتاپ دیگر روی «همکاری تأمین‌کنندگان» نمی‌افتد — پیوندهایی که جا نمی‌شوند به سطر دوم
> همان ستون می‌روند (یک قاعدهٔ CSS؛ منویی که جا می‌شود بی تغییر). علت، اندازه‌ها، آزمون‌ها، آزمون ایمن و بازگشت:
> **[`docs/nav-overlap-0.2.3-fa.md`](docs/nav-overlap-0.2.3-fa.md)**.

> **برای تست Staging پس از مجوز جداگانه؛ نصب‌نشده.** هیچ نصب، فعال‌سازی، تغییر تنظیمات یا پاک‌سازی
> کش روی Staging یا Production انجام نشده است.

افزونهٔ مستقل وردپرس که هدر B را نمایش می‌دهد و می‌تواند قالب هدر Elementor ِ «Header» (۲۶۵۲) را در
همهٔ صفحه‌ها **جایگزین** کند — بی حذف یا تغییر آن قالب و شرط نمایشش، و بی وابستگی به JetMenu.

- بسته: `dist/tecteb-header-0.2.2.zip` · SHA-256 در `dist/SHA256SUMS`
- سورس: `tecteb-header/` · آزمون‌ها: `tests/` · شواهد: `evidence/`
- اسناد: `docs/test-report-fa.md` · `docs/compatibility-review-fa.md` (Elementor Pro 4.2.2، PHP 8.1،
  WP Rocket، JetMenu) · `docs/risks-fa.md` (ریسک‌ها و اطلاعات لازم) · `docs/staging-checklist-fa.md`
  (نصب، تست و بازگشت) · `docs/migration-rollback-fa.md` · `docs/inspect-2652-fa.md` (بررسی فقط‌خواندنی ۲۶۵۲)

## تغییرات 0.2.2 (تشخیص گزارش Staging: پیش‌نمایش با دو قالب «کل سایت»)
- منطق جایگزینی و پیش‌نمایش **عوض نشد**. علت قطعی روی Staging هنوز تأیید نشده؛ تشخیص و شواهد لازم:
  `docs/staging-preview-diagnosis-fa.md`.
- با دو قالب هدر «کل سایت»، Elementor Pro فقط یکی را چاپ می‌کند (مکان header «multiple» نیست). زبانهٔ
  «جایگزینی هدر» و ابزار بررسی حالا می‌گویند کدام قالب چاپ می‌شود و اگر انتخاب نشده، پیش‌نمایش و جایگزینی
  کاری نمی‌کنند؛ جملهٔ غلط 0.2.1 دربارهٔ این حالت برداشته شد. توضیح HTML ِ مدیر شناسه‌ها را می‌گوید:
  `not replaced: not_allowed (Elementor queue: 20; selected: 2652)`.

## تغییرات 0.2.1 (فقط رفع ریسک‌های ضروری پیش از Staging)
- **هرگز دو هدر:** اگر شنوندهٔ دیگری سند Elementor را پس از برداشتن دوباره به صف بیفزاید، یا مکان header
  پیش‌تر در همان درخواست چاپ شده باشد، افزونه با `did_location()` ِ Elementor می‌فهمد و هیچ چاپ نمی‌کند ⇒
  همان یک هدر Elementor. بازتولید روی 0.2.0: سه رد (headers=2)؛ روی 0.2.1: صفر.
- **CSS ِ ایزوله:** همهٔ قاعده‌ها با پیشوند `#tth-root` و یک لایهٔ `all: revert`، ساخته‌شده بی‌دست از CSS ِ
  تأییدشده (`tools/harden-css.py`). در برابر CSS ِ Kit و frontend ِ Elementor 4.2.3 و Custom CSS ِ رایج:
  ۴۶۹ تفاوت ⇒ ۰ (عادی و minify‌شده)، و ظاهر برابر نمونهٔ B.
- **ابزار بررسی فقط‌خواندنی ۲۶۵۲:** ابزارک‌ها، Custom CSS، فایل CSS ِ قالب، Kit، قلاب‌ها و WP Rocket —
  `wp eval-file wp-content/plugins/tecteb-header/tools/inspect-header-template.php 2652`، و در پیشخوان.

## تغییرات 0.2.0 نسبت به 0.1.0

- **جایگزینی بی output buffer:** قالب مجاز (۲۶۵۲) با متدهای عمومی Locations_Manager ِ Elementor Pro از
  صف چاپ برداشته می‌شود و رندر نمی‌شود؛ هدر افزونه همان‌جا چاپ می‌شود. خروجی افزونه‌های دیگر دست
  نمی‌خورد؛ هر وضعیت غیرمنتظره ⇒ همان ۲۶۵۲. (ادعای «هرگز دو هدر» ِ 0.2.0 یک استثنا داشت که 0.2.1 بست.)
- **فهرست قالب‌های مجاز:** فقط قالبی که مدیر تیک بزند جایگزین می‌شود؛ بی آن، جایگزینی روشن نمی‌شود.
- **شرط نمایش، فقط‌خواندنی:** جدول قالب‌های Header با وضعیت و شرط‌ها («شامل: کل سایت»).
- **انتقال دسته‌ها از «منوی اصلی» ِ JetMenu:** پیوندهای سفارشی با نشانی‌شان به دسته‌های واقعی
  `product_cat` نگاشته می‌شوند؛ هم‌نامی فقط پیشنهاد است. زیردسته با فهرست تیک‌دار؛ پیشنهاد تصویر دستهٔ
  ووکامرس به‌عنوان آیکون.
- **WP Rocket:** پاک‌سازی با `rocket_clean_domain()`؛ فونت درون‌خطی با نشانی مطلق (سازگار با Minify CSS)؛
  cart fragments پیش‌فرض روشن؛ غیرفعال‌سازی با جایگزینی روشن کش را پاک می‌کند (بازگشت فوری).
- **توضیح HTML فقط برای مدیر:** `<!-- tecteb-header: replaced … / not replaced: … -->`.

## آنچه نمایش می‌دهد
لوگو، جست‌وجوی محصولات ووکامرس، حساب، سبد، علاقه‌مندی (اختیاری، بی شمارندهٔ ساختگی)، منوی اصلی
(«نمایش ← فهرست‌ها»، سطح اول)، «همکاری تأمین‌کنندگان»، Mega Menu ِ دوستونه و کشوی موبایل — دسکتاپ و
موبایل از یک منبع. JS ِ هدر بایت‌به‌بایت نمونهٔ تأییدشدهٔ B؛ CSS همان قاعده‌ها با پیشوند `#tth-root`.
دسته‌ها **فقط** از `product_cat`؛ تنظیمات فقط شناسه، ترتیب، نمایش و آیکون را نگه می‌دارند؛ نام، پیوند
و سلسله‌مراتب هر بار از ووکامرس. هیچ دسته‌ای ساخته، ویرایش یا حذف نمی‌شود.

## پیشخوان: تک‌طب ← مدیریت هدر
دسته‌بندی‌ها · انتقال از منوی فعلی · تنظیمات · جایگزینی هدر · دادهٔ ذخیره‌شده. مجوز `manage_options`،
ذخیره با Settings API (nonce)، بی JavaScript هم کامل.

## آزمون‌ها (فقط محلی)
ایستا ۶۳ · PHPCS ۰ · یکپارچه ۱۵۹ · سناریوی دو هدر در مرورگر ۱۰ · بازتولید دو هدر (0.2.0: ۳ رد، 0.2.1: ۰) · ایزولگی CSS ۰ تفاوت ·
مرورگر ۱۵۸ و axe ۰ تخلف در ۲۰ حالت · چرخهٔ عمر از ZIP ۲۱ · falsification ۴۶/۴۶ · debug.log بی پیام. Elementor Pro، WP Rocket، JetMenu و PHP 8.1 ِ واقعی اجرا نشدند
(`docs/test-report-fa.md`).

```bash
python3 tests/static/check.py
(cd ../plugin && vendor/bin/phpcs --standard=../header-plugin/phpcs.xml.dist)
wp eval-file tests/fixtures/seed.php seed && wp eval-file tests/integration/run.php
php tools/minify-css.php <dir-with-matthiasmullie-minify> tecteb-header/assets/css <wp>/wp-content/uploads/tthh-min
NODE_PATH=/opt/node-tools/node_modules node tests/browser.mjs
NODE_PATH=/opt/node-tools/node_modules [MINIFIED=1] node tests/isolation.mjs <label>
python3 tools/harden-css.py --check
bash tools/build.sh && bash tests/lifecycle.sh
python3 tests/falsify.py
```
فیکسچرها فقط روی پایگاه دادهٔ `tth_disposable` اجرا می‌شوند؛ شبیه‌ساز Elementor و کمک‌های آزمون
(`tests/fixtures/*.php`) mu-plugin ِ همان محیط‌اند و در ZIP نیستند.
