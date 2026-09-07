# ADR-002 — بارگذاری دومرحله‌ای ماژول و مهار خرابی

وضعیت: **پذیرفته‌شده** · ۷ سپتامبر ۲۰۲۶ · مرتبط: CORE-02، ARCH-01، اصلاح ۲ مالک.

## زمینه
CORE-02 دو مرحله `register` سپس `boot` به ترتیب وابستگی می‌خواهد و وضعیت‌های
`planned/active/degraded` را جدا می‌کند. مالک در مجوز اجرا افزود: خرابی یک
ماژول باید **مانع اجرای وابسته‌هایش** شود، تنها مستقل‌ها ادامه دهند، و علت
توقف در وضعیت سلامت ثبت شود.

## گزینه‌ها
| گزینه | ملاحظه |
|---|---|
| A — یک مرحله (`boot` تنها) | ماژول نمی‌تواند به binding ماژول دیگر تکیه کند مگر با ترتیب شکننده |
| **B — دو مرحله: register همه، سپس boot همه** | bindingها پیش از هر hook کامل‌اند؛ ترتیب توپولوژیک پایدار |
| C — دو مرحله + وضعیت خرابی مشترک | `degraded` هم برای «خودش خراب شد» و هم «وابسته‌اش خراب شد» → علت گم می‌شود |

## تصمیم
گزینه B، با **دو وضعیت خرابی مجزا**:

- `degraded` — خودِ ماژول در `register()` یا `boot()` استثنا داد.
- `blocked` — ماژول اجرا **نشد**، چون وابستگی‌اش degraded/blocked/گمشده بود،
  چرخه داشت، یا WooCommerce لازم داشت و نبود.

`ModuleStatus::Blocked` عمداً به قرارداد سلامت اضافه شد. «اجرا نشد» با «اجرا شد
و خراب بود» یکی نیست و برای عیب‌یابی باید قابل تشخیص باشد.

## قواعد
1. hookها فقط در `boot()`. پس ماژول blocked در زمان boot هیچ رفتار زنده ندارد.
2. مسدودسازی **گذرا** است: اگر a خراب شود و b به a و c به b وابسته باشد، هر دو
   blocked می‌شوند و هیچ‌کدام اجرا نمی‌شوند.
3. هر توقف کد علت (`dependency_cycle`, `missing_dependency`,
   `dependency_failed`, `requires_woocommerce`, `exception`)، مرحله
   (`resolve`/`register`/`boot`) و جزئیات (نام وابستگی و وضعیتش، یا مسیر چرخه)
   دارد.
4. متن استثنا از `TextSanitizer::exceptionSummary()` می‌گذرد: یک خط، بدون
   stack trace، حداکثر ۱۶۰ کاراکتر (UX-01).
5. `load()` ایدمپوتنت است: فراخوانی دوم همان گزارش را برمی‌گرداند و `boot()` را
   دوباره اجرا نمی‌کند، پس hook تکراری ساخته نمی‌شود.
6. ماژول `planned` هرگز اجرا نمی‌شود و ثبت کد زیر عنوان planned رد می‌شود
   (`planned_with_code`).

## آزمون
`tests/Unit/Core/Modules/ModuleLoaderTest.php` — ۱۱ تست، از جمله
`testRegisterExceptionDegradesModuleAndBlocksDependents`،
`testBootExceptionBlocksDependentsAtBootPhase`، `testBlockingIsTransitive`،
`testCycleIsBlockedWithPathWhileIndependentModuleRuns`،
`testSecondLoadNeverBootsAgain`،
`testPlannedManifestsAreNeverRunAndCannotCarryCode`.
نمایش علت: `PagesTest::testHealthPageDegradesWhenHealthModuleIsNotActive` و
سناریوی `modules-degraded` در harness رابط.

## پیامدها
ماژول‌های عملیاتی این فاز (`admin`, `health`) عمداً به یکدیگر وابسته نیستند و
هیچ‌کدام WooCommerce نمی‌خواهند، تا نبود WooCommerce یا خرابی یکی، دیگری و
صفحه‌های بررسی وضعیت را از دسترس خارج نکند.
