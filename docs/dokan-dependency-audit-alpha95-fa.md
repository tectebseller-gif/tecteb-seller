# وابستگی‌های دکان — اندازه‌گیری‌شده، نه فهرست‌شده

مبنا: `0.1.0-alpha.95`، شاخهٔ `claude/new-session-z1vafy`.
این سند جواب بند ۴ دستور است: «تمام وابستگی‌های مستقیم و غیرمستقیم به Dokan Pro
را شناسایی کن» و برای هر مورد یکی از چهار حکم.

⛔ **و هیچ اقدامی روی staging یا تولید انجام نشد.** دکان و Min Max فعال
می‌مانند، و این سند دربارهٔ کد است نه دربارهٔ آن دو سایت.

## ۰. روش

فهرست از **شمارش** آمد نه از صورت‌مسئله — قاعدهٔ «دروازه‌ای که جملهٔ خودش را
نمی‌خواند، کارِ خودش را دو برابر می‌کند» (`alpha.92`). هر فایل زیر `src/` که
واژهٔ `dokan` را می‌برد tokenise شد و **کامنت‌ها خالی شدند**، چون یک docblock
که دربارهٔ دکان توضیح می‌دهد وابستگی نیست (قاعدهٔ `alpha.22`).

```
grep -rli dokan src/ --include=*.php        # ۱۰۴ فایل نام می‌برند
# …و پس از خالی‌کردن کامنت‌ها، بیرون از ماژول Migration:
```

| فایل | ارجاع در **کد** | چه چیزی |
|---|---:|---|
| `Core/Audit/AuditEventCatalog.php` | ۳۸ | **نامِ** رویدادهای ممیزی (`migration.dokan_*`) |
| `Vendor/.../DokanUrlRedirects.php` | ۱۰ | ریدایرکت نشانی فروشگاه دکان به مالِ ما |
| `Product/.../ProductCatalogueView.php` | ۵ | یک پیوند «ویرایش در دکان» روی ردیف سبد دکان |
| `Operations/.../QueuePage.php` | ۵ | توصیف پیشرفت کارِ ورود دکان |
| `Infrastructure/WordPress/Bootstrap.php` | ۴ | ثبت دو گام migration |
| `Operations/.../OperationsMessages.php` | ۳ | سه برچسب فارسی |
| `Product/.../ProductReviewPage.php` | ۲ | چاپ نشانهٔ «فروشندهٔ دکان» |
| `Vendor/.../StorePage.php` | ۱ | ثبت همان ریدایرکت |
| `Product/.../CatalogueIntakeControls.php` | ۱ | حمل همان نشانی |

**و ۵۰ فایل دیگر صفر ارجاع در کد دارند** — فقط کامنت. از جمله: کلِ مسیر سفارش
(`CaptureOrder`, `WcRefundRecorder`), کلِ مسیر مالی (`DbLedgerRepository`,
`CommissionCartGuard`, `WpTectebCommissionSource`), گاردهای خرید
(`PurchaseGuard`, `CartGuard`, `PurchasePolicy`), projector، مالکیت
(`LinkOwnership`), مجوز فروش (`DecideImportedShopSelling`), پرسنل
(`WpStaffUsers`, `VendorCapabilityPolicy`) و دسترسی‌های وردپرس
(`Core/Lifecycle/Capabilities.php`).

## ۱. `DokanReaderInterface` — تنها دری که به خودِ دکان می‌رسد

هر مصرف‌کنندهٔ این رابط شمرده شد. **همه‌شان در ماژول `Migration` اند**، به‌علاوهٔ
یک مورد که با `has()` گارد شده:

```
ImportFromDokan · ShopRecordPreview · ShopRecordScope · MigrationCompleteness
CategoryMapping · HandoverPage · WpDokanReader          ← همه در Migration
Operations/.../QueuePage.php:704  if (!$this->container->has(DokanReaderInterface::class))
```

و خودِ رابط **هیچ متد نوشتنی ندارد** — خاصیتی که از `alpha.9` آزمون دارد.

## ۲. حکمِ هر حوزه‌ای که دستور نام برد

| حوزه | حکم | اندازه‌گیری |
|---|---|---|
| مالکیت فروشنده و محصولات | **مستقل** | `LinkOwnership`, `TransferOwnership`, `DbProductRepository` صفر ارجاع در کد |
| داشبورد فروشنده | **مستقل** | `/vendor/` مسیر خودمان است؛ `VendorRoutes` صفر ارجاع |
| سفارش‌ها | **مستقل** | `CaptureOrder`, `OrderHooks`, `WcOrderReader` صفر ارجاع؛ سفارش از ووکامرس می‌آید |
| کمیسیون | **مستقل** | `TectebCommission`, `CommissionRate`, `WpTectebCommissionSource` صفر ارجاع |
| تسویه‌حساب | **مستقل** | `DbLedgerRepository`, `VendorBalance` صفر ارجاع |
| درخواست برداشت | **مستقل** | `DbWithdrawalRepository`, `ReviewWithdrawals` صفر ارجاع |
| موجودی و محدودیت خرید | **مستقل از دکان**، وابسته به Min Max تا خروجش | `QuantityGuard`, `PurchaseQuantity` صفر ارجاع دکان؛ Min Max مسیر خروج خودش را دارد (`tmc-minmax-exit`) |
| مجوز فروش | **مستقل** | `can_sell` را فقط `ReviewApplication` و `DecideImportedShopSelling` می‌نویسند |
| وضعیت فروشگاه | **مستقل** | `ApplicationStatus`, `DbVendorRepository` صفر ارجاع |
| اعلان‌ها | **مستقل** | `tmc_notifications` جدول خودمان؛ صفر ارجاع |
| API | **مستقل** | `tecteb/v1` مجوزش کاربر وردپرس + `StaffAccess` است؛ صفر ارجاع |
| صفحهٔ فروشگاه و محصول | **مستقل، و یک قطعه دائمی** | صفحهٔ عمومی مالِ ماست؛ `DokanUrlRedirects` باید **بمانَد** (زیر) |
| نقش‌ها و دسترسی‌های وردپرس | **مستقل** | `Capabilities::all()` هیچ نقش دکانی نمی‌سازد و نمی‌خواند |
| هوک‌ها و شورت‌کدها | **مستقل** | `[tmc_vendor_dashboard]`, `[tmc_vendor_box]` مالِ ما؛ هیچ هوک دکانی ثبت نمی‌شود |

## ۳. چهار موردی که حکمشان «مستقل» نیست

### ۳-۱. `DokanUrlRedirects` — **دائمی، و مستقل از اجرای دکان**

نشانی `/store/<slug>/` که مردم bookmark کرده‌اند با `301` به صفحهٔ همان
فروشگاه اینجا می‌رود. docblock خودش سه حالت **اندازه‌گیری‌شده** روی دکان Lite
واقعی دارد، و بدترینشان این است: **غیرفعال‌کردن دکان قاعده‌های rewrite اش را
پاک نمی‌کند**، پس شش قاعدهٔ `store/…` می‌مانند و نشانی `200` صفحهٔ اول سایت
می‌دهد — محتوای تکراری روی دهها نشانی.

**حکم: حذف نمی‌شود، هرگز.** این کد پس از رفتن دکان **بیشتر** لازم است، نه
کمتر؛ و `isDokanRunning()` را می‌پرسد تا هر سه حالت را درست جواب بدهد.

### ۳-۲. دو گام migration — **دائمی، غیرقابل‌حذف**

`M0015DokanOrderHistory` و `M0017DokanShopRecords` در `Bootstrap.php` ثبت‌اند.

**حکم: حذف نمی‌شود.** گامی که یک بار تحویل شده هرگز unship نمی‌شود: برداشتنش
زنجیرهٔ ۳۸ گامی را می‌شکند و هر نصبِ موجود را روی نسخهٔ خودش قفل می‌کند. نامشان
دکان را می‌برد و کارشان ساختن **جدول‌های خودمان** است.

### ۳-۳. سی‌وهشت نام رویداد ممیزی — **دائمی، غیرقابل‌حذف**

`migration.dokan_imported` و بقیه، **مقدارهای ذخیره‌شده** در
`tmc_audit_events.event_type` اند. **حکم: حذف نمی‌شود** — عوض‌کردنشان ردیف‌های
ممیزیِ موجود را ناخوانا می‌کند، و ممیزی دربارهٔ گذشته است.

### ۳-۴. پنج تکهٔ نمایشی — **فقط برای مهاجرت، قابل حذف پس از آن**

| تکه | کار | پس از مهاجرت |
|---|---|---|
| `ProductCatalogueView` + `CatalogueIntakeControls` | پیوند «ویرایش در دکان» روی ردیف سبد `DOKAN` | قابل حذف |
| `ProductReviewPage` | نشانهٔ «این نویسنده فروشندهٔ دکان است» | قابل حذف |
| `QueuePage` | توصیف پیشرفت کارِ `dokan_records` | قابل حذف با خودِ کار |
| `OperationsMessages` | سه برچسب فارسی | قابل حذف با آن‌ها |
| `AdminNavigation` گروه `MIGRATION` | سه صفحهٔ مهاجرت | قابل حذف، **یک ویرایش** |

## ۴. تنها دروازهٔ ساختاری که اجرای دکان را لازم دارد

از **شش** شرط `MigrationCompleteness::GATES`، پنج‌تا فقط جدول‌های خودمان و
ووکامرس را می‌خوانند:

| شرط | منبع | وابسته به اجرای دکان؟ |
|---|---|---|
| `records_imported` | `ShopRecordPreview` → `DokanReaderInterface` | **بله** — یا با تأیید صریح مدیر (`alpha.91`، گزینهٔ ب) |
| `categories_mapped` | `countImportedWithoutCategory()` روی جدول خودمان | نه |
| `ownership_taken` | `ownershipTallyForVendor()` | نه |
| `vendor_can_sell` | `tmc_vendor_profiles.can_sell` | نه |
| `staff_decided` | `staffForVendor()` + `StaffRoleMap` | نه |
| `finance_decided` | `tmc_dokan_handover` (آرشیو خودمان) | نه |

⛔ **پس یک تصمیم باید پیش از خاموش‌کردن دکان گرفته شود و بس:** تأیید سوابق
فروشگاه. گام دومِ Migration Wizard همین را می‌گوید
(`dokan_not_running` ⇒ «پیش از خاموش‌کردن دکان این گام را انجام بدهید»).

## ۵. آنچه این سند **نمی‌گوید**

* **رفتار افزونه در نبود دکان روی سایت واقعی `Not Run` است.** آنچه اندازه‌گیری
  شد سورس است، به‌علاوهٔ سوییت‌ها روی محیط یکبارمصرف. دکان در این محیط
  **اجراشدنی نیست** و دلیلش از `alpha.53` تغییر نکرده: خط اول `dokan.php` یک
  `require_once "abzarwp/abzarwp.php"` دارد و هر ۳۷ فایل آن پوشه با
  SourceGuardian رمز شده‌اند. **نه آن باینری نصب شد و نه آن `require`
  برداشته شد.**
* **هیچ کدی حذف نشد.** این دور فقط طبقه‌بندی کرد.
* **Min Max** موضوع این سند نیست؛ مسیر خروجش `tmc-minmax-exit` است و حکم
  خودش را چاپ می‌کند.

---

## ۵. و ارجاع‌های **غیرمستقیم**، شمرده نه فهرست‌شده

جدول بالا به‌ازای هر حوزه کلاس‌هایش را نام می‌برد. این بند پرسش معکوس را
می‌پرسد: **کدام فایلِ بیرون از ماژول `Migration` اصلاً واژهٔ دکان را می‌آورد؟**
اندازه‌گیری‌شده روی بایت‌های همین درخت، با **tokenise** (پس کامنت شمرده
نمی‌شود — قاعدهٔ `alpha.22`):

| فایل | تعداد | چیست |
|---|---:|---|
| `Core/Audit/AuditEventCatalog.php` | ۱۲ | **نام رویداد ممیزی** — دائمی؛ حذفشان ردِ گذشته را ناخوانا می‌کند |
| `Infrastructure/WordPress/Bootstrap.php` | ۴ | ثبت دو گام migration (`M0015`, `M0017`) — **دائمی** |
| `Modules/Operations/.../QueuePage.php` | ۵ | نام مرحلهٔ کارِ ورود روی صفحهٔ صف |
| `Modules/Operations/.../OperationsMessages.php` | ۳ | متن فارسی همان مرحله‌ها |
| `Modules/Product/.../ProductCatalogueView.php` | ۴ | چیپ «منبع: دکان» روی فهرست محصول |
| `Modules/Product/.../ProductReviewPage.php` | ۱ | همان برچسب روی کارت بررسی |
| `Modules/Product/.../CatalogueIntakeControls.php` | ۱ | یک پارامتر نشانی که فراخوان می‌دهد |
| `Modules/Vendor/.../DokanUrlRedirects.php` | ۱۰ | **دائمی** — بند ۳-۱ |
| `Modules/Vendor/.../StorePage.php` | ۱ | ثبت همان redirect |

**۹ فایل، و هیچ‌کدام جدول دکان را نمی‌خواند.** سه‌تا از آن‌ها همان سه چیز
دائمی بند ۳ اند و شش‌تای دیگر **برچسب** اند: متنی که می‌گوید این محصول از کجا
آمده. در ماژول `Migration` **۳۲ فایل** نام دکان را می‌آورند، و تنها راهِ
رسیدنشان به دادهٔ دکان رابط `DokanReaderInterface` است که **هیچ متد نوشتنی
ندارد**.

⛔ **و این جمله را با «پس حذفشان بی‌خطر است» اشتباه نگیرید.** برچسبی که
می‌گوید «منبع: دکان» پس از رفتن دکان هم **درست** است — دربارهٔ گذشتهٔ آن ردیف
حرف می‌زند، نه دربارهٔ افزونه‌ای که اجرا می‌شود.
