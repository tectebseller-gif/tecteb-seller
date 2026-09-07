# نصب، بازگشت و راه رساندن گیت‌های Not Run به Passed — فاز ۱

> ## وضعیت بسته: تأییدنشده — روی سایت واقعی نصب نشود
> گیت نصب Staging اجرا **نشده** است. این سند دستور اجراست، نه گزارش اجرا.

## ۱. این بسته چیست و چه نیست

**هست:** زیرساخت فاز ۱ و چهار صفحه مدیریت، به‌همراه یک endpoint خصوصی سلامت.

**نیست:** فروشنده، محصول، سفارش، کمیسیون، تسویه، مهاجرت از دکان، احراز هویت
واقعی، و هر ارسال واقعی. هیچ ایمیل/پیامک/پرداختی از خود افزونه خارج نمی‌شود و
این قفل با هیچ گزینه‌ای باز نمی‌شود.

**درباره سایر افزونه‌ها هیچ ادعایی نمی‌کند.** «ارسال‌های TMC مسدود است» به
معنی «کل سایت امن است» نیست.

## ۲. پیش‌نیازها

| مورد | مقدار |
|---|---|
| PHP | ۸٫۱ یا بالاتر (اجرای واقعی روی ۸٫۱ هنوز آزموده نشده) |
| WordPress | ۶٫۳ یا بالاتر (هیچ نسخه‌ای آزموده نشده) |
| WooCommerce | لازم نیست؛ در نبودش افزونه در حالت محدود بالا می‌آید |
| Multisite | **پشتیبانی نمی‌شود**؛ فعال‌سازی رد می‌شود |
| Composer/Node روی هاست | لازم نیست |

## ۳. نصب روی یک WordPress **یکبارمصرف** (تنها نصب مجاز فعلی)

«یکبارمصرف» یعنی سایتی که فقط برای همین آزمون ساخته شده، داده مصنوعی دارد و
دور انداختنش هیچ هزینه‌ای ندارد. **Staging عملیاتی یا production در این مجوز
نیست.**

```bash
# ۱. یکپارچگی بسته
sha256sum -c SHA256SUMS

# ۲. نصب از پیشخوان: افزونه‌ها → افزودن → بارگذاری افزونه → tecteb-marketplace-core.zip
#    یا با WP-CLI:
wp plugin install tecteb-marketplace-core.zip
wp plugin activate tecteb-marketplace-core

# ۳. با WP_DEBUG روشن باید هیچ fatal و هیچ warning ای نباشد
#    در wp-config.php: define('WP_DEBUG', true); define('WP_DEBUG_LOG', true);
```

### چه چیزی باید ببینید
1. پیام موفقیت فارسی «بازارگاه تک‌طب فعال شد. ساختار داده آماده است.»
2. منوی «بازارگاه تک‌طب» با دقیقاً چهار زیرصفحه.
3. جدول `{prefix}tmc_audit_events` با یک ردیف `plugin.activated`.
4. `tmc_schema_version = 1` در `wp_options`.
5. بدون WooCommerce: هشدار فارسی و سالم ماندن هر چهار صفحه.

## ۴. رساندن گیت‌های Not Run به Passed

این دستورها را روی همان WordPress یکبارمصرف اجرا و **خروجی خام** را برگردانید.
تا وقتی خروجی ثبت نشده، وضعیت `Not Run` می‌ماند.

```bash
# G1 — نصب/فعال‌سازی واقعی بدون fatal/warning
wp plugin activate tecteb-marketplace-core && tail -50 wp-content/debug.log

# G2 — چهار capability فقط روی مدیرکل
wp cap list administrator | grep tmc_
wp cap list subscriber   | grep tmc_ || echo "subscriber: none (expected)"

# G3 — جدول و ایندکس‌ها
wp db query "SHOW CREATE TABLE $(wp db prefix)tmc_audit_events\G"

# G4 — ایدمپوتنت بودن: غیرفعال/فعال دوباره، داده باید بماند
wp db query "SELECT COUNT(*) FROM $(wp db prefix)tmc_audit_events"
wp plugin deactivate tecteb-marketplace-core && wp plugin activate tecteb-marketplace-core
wp db query "SELECT COUNT(*) FROM $(wp db prefix)tmc_audit_events"   # نباید کم شود
wp option get tmc_settings --format=json                              # نباید بازنشانی شود

# G5 — مجوز REST: بدون ورود ۴۰۱، با مدیر ۲۰۰
curl -i "$(wp option get siteurl)/wp-json/tmc/v1/health"

# G6 — HPOS در سه حالت (نیازمند WooCommerce)
wp option get woocommerce_custom_orders_table_enabled
wp option get woocommerce_custom_orders_table_data_sync_enabled

# G7 — asset فقط در صفحه‌های افزونه: منبع صفحه Posts و صفحه اصلی نباید tmc-admin داشته باشد
curl -s "$(wp option get siteurl)/wp-admin/edit.php" | grep -c tmc-admin   # باید 0 باشد

# G8 — نسخه‌های واقعی برای ماتریس سازگاری
wp core version && wp plugin get woocommerce --field=version && php -v | head -1
```

برای اجرای روی **PHP 8.1** همان مجموعه را روی میزبانی با PHP 8.1.34 تکرار کنید؛
تنها این کار سطر «اجرای واقعی روی 8.1» را از `Not Run` خارج می‌کند.

## ۵. بازگشت (rollback) — فقط فاز ۱

فاز ۱ **هیچ داده تجاری‌ای نمی‌نویسد**: نه محصول، نه سفارش، نه کاربر، نه سفارش
دکان. بنابراین بازگشت ساده است.

| گام | اثر |
|---|---|
| ۱. غیرفعال کردن افزونه از پیشخوان | همه hookها قطع می‌شوند. هیچ داده‌ای حذف نمی‌شود |
| ۲. حذف افزونه از پیشخوان | فایل‌ها می‌روند. `uninstall.php` عمداً **هیچ چیز پاک نمی‌کند** |
| ۳. اگر پاک‌سازی کامل می‌خواهید | باید **دستی** انجام شود (زیر) |

```sql
-- فقط اگر صریحاً می‌خواهید اثر افزونه صفر شود. برگشت‌ناپذیر است.
DELETE FROM wp_options WHERE option_name IN
  ('tmc_settings','tmc_schema_version','tmc_migration_last_error','tmc_migration_lock');
DROP TABLE IF EXISTS wp_tmc_audit_events;   -- prefix سایت خود را بگذارید
```
```bash
# و چهار capability از نقش مدیرکل
for c in tmc_view_dashboard tmc_view_health tmc_manage_settings tmc_view_modules; do
  wp cap remove administrator "$c"
done
```

هیچ‌کدام از این دستورها را افزونه اجرا نمی‌کند و هیچ‌کدام بخشی از نصب یا حذف
عادی نیست.

## ۶. درباره اسکلت قبلی

وضعیت `tecteb-marketplace-v0.1.1.zip` **تعیین‌نشده** است
(`docs/decision-log.md` بند ۴). تا بررسی فقط‌خواندنی محتوا، وابستگی‌ها و وضعیت
نصب آن، این سند **هیچ دستوری** درباره‌اش نمی‌دهد — نه غیرفعال‌کردن، نه حذف، نه
هم‌زیستی. اگر احتمال می‌دهید روی همان سایت نصب است، پیش از هر نصبی آن بررسی را
انجام دهید.

## ۷. عیب‌یابی

| نشانه | معنی | اقدام |
|---|---|---|
| هشدار «WooCommerce فعال نیست» | حالت محدود؛ عمدی است | WooCommerce را فعال کنید یا نادیده بگیرید |
| «آماده‌سازی ساختار داده ناموفق بود» | migration شکست خورده | صفحه سلامت مرحله و علت را نشان می‌دهد؛ فعال‌سازی دوباره از سر می‌گیرد |
| «اجرای دیگری در حال آماده‌سازی بود» | قفل در اختیار اجرای دیگری بود | چند لحظه بعد دوباره فعال کنید؛ قفل حداکثر ۵ دقیقه عمر دارد |
| صفحه سلامت: «ماژول سلامت فعال نیست» | ماژول degraded/blocked شده | علت زیر همان پیام آمده است |
| «نرخ کمیسیون هنوز تعیین نشده» | فقط پیام است | مانع کار افزونه نیست؛ در تنظیمات قابل تعیین است |
| ۴۰۳ روی `/wp-json/tmc/v1/health` | `tmc_view_health` ندارید | با مدیرکل وارد شوید؛ nonce به‌تنهایی کافی نیست |
