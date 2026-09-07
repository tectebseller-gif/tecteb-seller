# ADR-003 — نسخه schema مستقل، migration مرحله‌ای و قفل مالک‌دار

وضعیت: **پذیرفته‌شده** · ۷ سپتامبر ۲۰۲۶ · مرتبط: CORE-01، CORE-08، F-01،
اصلاح‌های ۱ و «نسخه مستقل» مالک.

## ۱. دو نسخه مستقل

| | نسخه انتشار | نسخه schema |
|---|---|---|
| شکل | semver در header | عدد صحیح از ۱ |
| محل | `TMC_PLUGIN_VERSION` | `SchemaVersion::TARGET` |
| ذخیره | فقط در کد | option `tmc_schema_version`، **پس از** migration موفق |
| تغییر | هر انتشار | فقط با تغییر ساختار |

**قاعده:** `MigrationRunner::run()` وقتی `stored >= TARGET` است بلافاصله
`UpToDate` برمی‌گرداند — **بدون گرفتن قفل و بدون هیچ SQL**. یک نسخه schema
می‌تواند چندین نسخه انتشار را پوشش دهد.

دلیل جدا نگه داشتن: نسخه انتشار به تصمیم تبار (DEC-06-d) وابسته است و ممکن است
جهش کند؛ نباید چنین جهشی migration بی‌مورد راه بیندازد.

## ۲. چرا مرحله‌ای و قابل resume

DDL در MySQL rollback تراکنشی ندارد. بنابراین:

1. هر مرحله `up()` **ایدمپوتنت** است (`CREATE TABLE IF NOT EXISTS`).
2. پس از `up()` تابع `verify()` ساختار را واقعاً بررسی می‌کند
   (شمارش ستون‌ها از `information_schema`).
3. نسخه فقط **پس از** verify موفق نوشته می‌شود.

پس اگر بین DDL و نوشتن نسخه سقوطی رخ دهد، اجرای بعدی همان مرحله را دوباره اجرا
می‌کند و چون ایدمپوتنت است ضرری ندارد، سپس نسخه را می‌نویسد.

شکست یک مرحله: مراحل بعدی اجرا نمی‌شوند، نسخه در آخرین مرحله verify‌شده
می‌ماند، و علت پاک‌سازی‌شده در `tmc_migration_last_error` ثبت و در صفحه سلامت
نشان داده می‌شود — نه موفقیت بی‌صدا.

## ۳. قفل مالک‌دار (اصلاح ۱)

مقدار قفل: `{"owner": <۳۲ hex تصادفی>, "acquired_at": …, "expires_at": …}`.

| عملیات | SQL | شرط |
|---|---|---|
| گرفتن | `INSERT IGNORE` | ردیف وجود نداشته باشد |
| تصاحب منقضی | `UPDATE … WHERE option_name = ? AND option_value = ?` | مقدار **دقیقاً** همان چیزی باشد که منقضی دیده شد |
| تمدید | همان | مقدار **دقیقاً** مقدار خودِ ما |
| آزادسازی | `DELETE … WHERE option_name = ? AND option_value = ?` | مقدار **دقیقاً** مقدار خودِ ما |

نتیجه: **اجرای قدیمی نمی‌تواند قفل اجرای جدید را حذف یا تمدید کند.** اگر
`compareAndSwap` در تمدید شکست بخورد، مالکیت داخلی هم رها می‌شود تا اجرای قدیمی
به‌اشتباه خود را مالک نداند.

نکته پیاده‌سازی: تمدید در همان ثانیه مقدار یکسان تولید می‌کند و MySQL برای
UPDATE بدون تغییر صفر ردیف گزارش می‌دهد؛ این حالت به‌عنوان موفقیت بی‌عملیات
تشخیص داده می‌شود تا با «قفل از دست رفت» اشتباه نشود.

قفل روی `wp_options` با SQL مستقیم کار می‌کند و cache را دور می‌زند: قفل هرگز
نباید از cache کهنه سرو شود. همان الگویی که هسته وردپرس در
`WP_Upgrader::create_lock()` به کار می‌برد.

## ۴. آزمون
- `MigrationLockTest` — ۱۰ تست در حافظه، از جمله
  `testOldRunCannotReleaseOrRefreshLockTakenOverByNewRun`.
- `MigrationRunnerTest` — ۱۰ تست، از جمله
  `testReleaseUpgradeWithoutStructuralChangeRunsNothing`،
  `testVersionIsWrittenOnlyAfterVerify`، `testLockedByAnotherRunDoesNothing`.
- `WpLockStoreTest` روی **MariaDB واقعی**، شامل
  `testExactlyOneOfManyConcurrentProcessesAcquiresTheLock` با هشت فرایند
  forkشده و اتصال جداگانه.
- `MigrationMariaDbTest` — DDL واقعی، ایندکس‌ها، resume، شکست، قفل.

## ۵. شرط بازنگری
اگر مرحله‌ای لازم شد که ایدمپوتنت نباشد (مثلاً backfill داده)، این ADR باید با
طرح checkpoint داخل خود مرحله به‌روز شود؛ صرفِ افزودن مرحله غیرایدمپوتنت مجاز
نیست.
