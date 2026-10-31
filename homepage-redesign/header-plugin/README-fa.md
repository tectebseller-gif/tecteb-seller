# Tecteb Header 0.2.0 — هدر سراسری مستقل تک‌طب (طرح B)

> **برای تست Staging پس از مجوز جداگانه؛ نصب‌نشده.** هیچ نصب، فعال‌سازی، تغییر تنظیمات یا پاک‌سازی
> کش روی Staging یا Production انجام نشده است.

افزونهٔ مستقل وردپرس که هدر B را نمایش می‌دهد و می‌تواند قالب هدر Elementor ِ «Header» (۲۶۵۲) را در
همهٔ صفحه‌ها **جایگزین** کند — بی حذف یا تغییر آن قالب و شرط نمایشش، و بی وابستگی به JetMenu.

- بسته: `dist/tecteb-header-0.2.0.zip` · SHA-256 در `dist/SHA256SUMS`
- سورس: `tecteb-header/` · آزمون‌ها: `tests/` · شواهد: `evidence/`
- اسناد: `docs/test-report-fa.md` · `docs/compatibility-review-fa.md` (Elementor Pro 4.2.2، PHP 8.1،
  WP Rocket، JetMenu) · `docs/risks-fa.md` (ریسک‌ها و اطلاعات لازم) · `docs/staging-checklist-fa.md`
  (تست و بازگشت) · `docs/migration-rollback-fa.md`

## تغییرات نسبت به 0.1.0
- **جایگزینی بی output buffer:** قالب مجاز (۲۶۵۲) با متدهای عمومی Locations_Manager ِ Elementor Pro از
  صف چاپ برداشته می‌شود و رندر نمی‌شود؛ هدر افزونه همان‌جا چاپ می‌شود. خروجی افزونه‌های دیگر دست
  نمی‌خورد؛ هر وضعیت غیرمنتظره ⇒ همان ۲۶۵۲ (هرگز دو هدر).
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
موبایل از یک منبع. CSS و JS ِ هدر بایت‌به‌بایت همان نمونهٔ تأییدشدهٔ B.
دسته‌ها **فقط** از `product_cat`؛ تنظیمات فقط شناسه، ترتیب، نمایش و آیکون را نگه می‌دارند؛ نام، پیوند
و سلسله‌مراتب هر بار از ووکامرس. هیچ دسته‌ای ساخته، ویرایش یا حذف نمی‌شود.

## پیشخوان: تک‌طب ← مدیریت هدر
دسته‌بندی‌ها · انتقال از منوی فعلی · تنظیمات · جایگزینی هدر · دادهٔ ذخیره‌شده. مجوز `manage_options`،
ذخیره با Settings API (nonce)، بی JavaScript هم کامل.

## آزمون‌ها (فقط محلی)
ایستا ۵۹ · PHPCS ۰ · یکپارچه ۱۲۷ · مرورگر ۱۵۸ و axe ۰ تخلف در ۲۰ حالت · چرخهٔ عمر از ZIP ۲۱ ·
falsification ۳۵/۳۵ · debug.log بی پیام. Elementor Pro، WP Rocket، JetMenu و PHP 8.1 ِ واقعی اجرا نشدند
(`docs/test-report-fa.md`).

```bash
python3 tests/static/check.py
(cd ../plugin && vendor/bin/phpcs --standard=../header-plugin/phpcs.xml.dist)
wp eval-file tests/fixtures/seed.php seed && wp eval-file tests/integration/run.php
php tools/minify-css.php <dir-with-matthiasmullie-minify> tecteb-header/assets/css <wp>/wp-content/uploads/tthh-min
NODE_PATH=/opt/node-tools/node_modules node tests/browser.mjs
bash tools/build.sh && bash tests/lifecycle.sh
python3 tests/falsify.py
```
فیکسچرها فقط روی پایگاه دادهٔ `tth_disposable` اجرا می‌شوند؛ شبیه‌ساز Elementor و کمک‌های آزمون
(`tests/fixtures/*.php`) mu-plugin ِ همان محیط‌اند و در ZIP نیستند.
