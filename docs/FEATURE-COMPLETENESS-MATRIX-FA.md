# ماتریس کامل‌بودن قابلیت‌ها — از سورس، نه از نام کلاس

**مبنا:** شاخهٔ `claude/new-session-z1vafy`، نسخهٔ `0.1.0-alpha.103`، ساختار دادهٔ
**۳۹**. هر ردیف این سند یکی از این سه برچسب را دارد و هیچ ردیفی بی برچسب نیست:

- **`Verified in source`** — از خواندن فایل و خط مشخص، همین دور.
- **`Verified in evidence`** — از اجرای آزمون یا ابزار روی محیط یکبارمصرف.
- **`Not Run`** — اندازه‌گیری نشده. نه «قبول»، نه «رد».

⛔ **و یک قاعده بر همهٔ جدول‌ها:** «وجود یک کلاس» وضعیت نیست. هر ردیف
`implemented` باید **آزمونی** داشته باشد که رفتارش را بدواند؛ ردیفی که فقط
کلاس دارد `partial` است، و ردیفی که فقط روی محیط دیگری دیده شده `Not Run`.

---

## ۰) خلاصهٔ مدیریتی — چه چیزی واقعاً باز است

| # | یافته | اولویت | وضعیت | اثر روی launch |
|---|---|---|---|---|
| ۱ | **پوشش کمیسیون پس از خروج دکان اندازه‌گیری نمی‌شد** | **P0** | **اصلاح شد (ابزار)** | تا اجرای ابزار روی تولید، تعداد محصولاتی که با کمیسیون **صفر** فروخته می‌شوند معلوم نیست |
| ۲ | **سفارش دستی wp-admin از سه قلاب capture رد نمی‌شود** | **P1** | **اندازه‌گیری‌شده در سورس؛ تورِ ایمنی موجود** | سفارش دستی سهم فروشنده ثبت نمی‌کند تا مدیر صفحهٔ «ممیزی سفارش» را ببیند |
| ۳ | دو آزمون قرارداد روی PHP ۸٫۱ واقعی شکست می‌خوردند و هیچ‌کدام نقص کد نبود | P2 | **اصلاح شد** | هیچ — ولی بی اصلاح، هر اجرای ۸٫۱ قرمز بود و قرمزِ بی‌علت یاد می‌دهد نادیده گرفته شود |
| ۴ | docblock ِ `OrderOperationsGate` با ثابت کنارش تناقض داشت | P2 | **اصلاح شد** | هیچ |
| ۵ | عدد «۱۸۴» در یک سند مثل عدد تولید نوشته شده بود | P2 | **اصلاح شد** | هیچ، ولی مدیر را به شمارشِ اشتباه عادت می‌داد |
| ۶ | پانزده گام پذیرش staging | **P0** | **`Not Run`** | **تنها علت NO-GO** |
| ۷ | پرداخت واقعی زرین‌پال | **P0** | **`Not Run`** | بی آن، فروش روز اول اثبات‌نشده است |

---

## ۱) فروشنده: ثبت‌نام، پذیرش، مدارک، پرسنل، تعلیق

| قابلیت | مسیر واقعی | آزمون | وضعیت | اولویت |
|---|---|---|---|---|
| درخواست فروشندگی و مدارک پویا | `src/Modules/Vendor/Application/SubmitApplication.php` · `ReviewApplication.php` | `tests/Database/StoreAndStaffFlowTest.php` | `implemented` · Verified in source | — |
| مدارک **بیرون از هر ریشهٔ وب** | ADR-007، `alpha.4` | `tools/check-private-access.sh` | `implemented` · Verified in evidence | — |
| پنج نقش مصوب پرسنل + `StaffAccess` به‌عنوان تنها مرجع | `src/Modules/Vendor/Application/StaffAccess.php` | `StoreAndStaffFlowTest` | `implemented` · Verified in source | — |
| دعوت با لینک یک‌بارمصرف؛ **ارسال خودکار بسته** | `StaffAccess` · `GrantImportedStaff` | `ShopRecordConfirmationTest` | `guarded` (نبودِ sender) · Verified in source | — |
| تعلیق فوری فروشگاه و همهٔ پرسنلش | `ReviewApplication` → `withdrawVendor()` | `StoreAndStaffFlowTest` | `implemented` · Verified in source | — |
| فروشگاه‌های **۴۰ و ۱۰۲** و حساب‌های **۲/۴/۱۶** | `ShopRecordScope` · `MigrationCompleteness` | `tests/Database/ShopRecordScopeTest.php` | `implemented` · Verified in evidence (شکل staging) | — |
| «فقط صاحب سابقه، نه فروشگاه» | `ShopRecordScope::recordOwnerVendorUserIds()` | همان | `implemented` · Verified in source | — |
| **اجازهٔ فروش فروشگاه واردشده، با تصمیم صریح** | `DecideImportedShopSelling` | `tests/Database/ImportedShopSellingTest.php` | `implemented` · Verified in source | — |
| احراز موبایل (OTP) | — | — | **`absent` به‌عمد** — Adapter واقعی و مستند ندارد | P2 |

## ۲) پنل `/vendor/`، مسیر عمومی، `can_sell`

| قابلیت | مسیر واقعی | آزمون | وضعیت | اولویت |
|---|---|---|---|---|
| مسیر `/vendor/` و ده بخش | `src/Modules/Vendor/Infrastructure/WordPress/VendorRoutes.php` | `tools/browser/vendor-panel-run.mjs` | `implemented` · Verified in evidence | — |
| **کش صفحهٔ فروشنده** — چهار پرچم + سه فیلتر + LiteSpeed | `VendorCacheControl.php:59-95` | `AreaActionsAreRegisteredTest` | `guarded` · Verified in source | P1 |
| ⛔ `advanced-cache.php` **پیش از هر افزونه** اجرا می‌شود | همان docblock | — | **`partial` به‌اعتراف خود کد** — ورودی‌های کش قبلی یک پاک‌سازی دستی می‌خواهند | P1 |
| URL قدیمی `/store/...` | `DokanUrlRedirects.php` | — | `implemented`، با snapshot از درخواست مدیر (`alpha.102`) | — |
| `can_sell` تنها کلید عملیاتی | `VendorProfile` · `PurchasePolicy` | `tests/Database/ImportedShopSellingTest.php` | `implemented` · Verified in source | — |
| جداسازی کاتالوگ بین فروشندگان | `forVendor()` با scope در `WHERE` | `ProductFlowTest` | `implemented` · Verified in source | — |
| **مجوز مالی جدا از مجوز فهرست** | `tmc_view_vendor_finance` | `ImportedShopSellingTest` | `implemented` · Verified in source | — |

## ۳) فرم محصول، تأیید، revision، مالکیت فیلد

| قابلیت | مسیر واقعی | آزمون | وضعیت | اولویت |
|---|---|---|---|---|
| فرم چهارمرحله‌ای با حفظ داده در خطا | `src/Modules/Product/Presentation/...` · `ManageProducts` | `tests/Database/ProductFormPostTest.php` | `implemented` · Verified in evidence | — |
| ⛔ تایپِ **ذخیره‌نشده** با رفتن به زبانهٔ دیگر می‌رود، بی هشدار | `alpha.42` بند تصحیح | — | **`partial`، اعلام‌شده** (هشدار کار جاوااسکریپت است) | P2 |
| تأیید/رد با سابقهٔ تصمیم فقط‌افزودنی | `tmc_product_decisions` · `ReviewProducts` | `ProductBaselineAndDecisionsTest` | `implemented` · Verified in source | — |
| ویرایش پس از انتشار ⇒ **نسخهٔ پیشنهادی** | `SensitiveChange` · `ProductRevision` | `AtomicProposalReplacementTest` | `implemented` · Verified in source | — |
| قیمت از چرخهٔ تأیید بیرون (تصمیم مالک) | `alpha.83` · `APPLIED_ON_APPROVAL` | `ApprovalSyncAndBaselineTest` | `implemented` · Verified in source | — |
| مالکیت فیلد بسته به **مقداری که نوشته شد** | `ProjectedFieldOwnership` | `tests/WordPressContract/ProjectedFactsTest.php` | `implemented` · Verified in evidence | — |
| ورود محصولات موجود ووکامرس، اتمیک | `ImportExistingProducts` · `CatalogueIntake` | `tests/Database/CatalogueIntakeTest.php` | `implemented` · Verified in source | — |
| انتقال مالکیت دسته‌ای با جبران | `TransferOwnership` · `ApplyOwnershipTransfer` | `OwnershipTransferUnitOfWorkTest` | `implemented` · Verified in source | — |

## ۴) تنوع، SKU، موجودی، حد خرید، Min Max، کوپن

| قابلیت | مسیر واقعی | آزمون | وضعیت | اولویت |
|---|---|---|---|---|
| محصول متنوع، ادغام نه بازنویسی | `WooCommerceProjector::projectVariations()` | `ProjectedFactsTest` | `implemented` · Verified in source | — |
| SKU یکتا و موجودی مال ووکامرس پس از اولین projection | ADR-008 | `CatalogProjectionTest` | `implemented` · Verified in source | — |
| حد خرید: یک قاعده، چهار در | `PurchaseQuantity` · `QuantityGuard` | `tests/Unit/PurchaseQuantityTest.php` (شبکهٔ ۱۴×۱۴×۶) | `implemented` · Verified in evidence | — |
| **خروج Min Max: حکم، چهار سطح** | `MigrateMinMax::exitVerdict()` | `MinMaxHandoverUnitOfWorkTest` | `implemented` · Verified in evidence | — |
| شاهد مالک: ۱۲ قاعده، ۱۲ برابر، صفر مانع | `MINMAX-EXIT-GO-NOGO` بند ۷ | — | **Verified in evidence (مالک، staging)** → خروج Min Max ✅ Go | — |
| کوپن بومی ووکامرس روی محصول بازارگاهی | `VendorFundedDiscount` روی `woocommerce_coupon_is_valid` | `OrderFlowTest` (هشت آزمون سبد) | `implemented` · Verified in evidence | — |
| کوپن سراسری بازارگاه | `ManageCoupons::GLOBAL_UNDECIDED` | `EngagementFlowTest` | **`guarded`** — DEC-04 بسته شد، سراسری هنوز تصمیم مالک | P2 |
| نردبان عمده (B2B) | `ManageWholesale` | `tools/wholesale-ui-check.sh` | `implemented`، شرایطش `OPEN_TERMS` | P2 |

## ۵) سفارش: یک/چندفروشنده، HPOS، لغو، idempotency، دکان

| قابلیت | مسیر واقعی | آزمون | وضعیت | اولویت |
|---|---|---|---|---|
| سه قلاب capture: کلاسیک، Store API، `woocommerce_new_order` | `OrderHooks.php:36-38` | `OrderFlowTest` | `implemented` · **Verified in source** | — |
| idempotency روی شناسهٔ قلم سفارش + کلید رویداد | `CaptureOrder` · `tmc_order_items` UNIQUE | `OrderFlowTest` · `LedgerTest` | `implemented` · Verified in source | — |
| ⛔ **سفارش دستی wp-admin** | هیچ قلابی — `woocommerce_new_order` پیش از افزودن قلم‌ها اجرا می‌شود (اندازه‌گیری‌شده در `tests/Support/alpha65-probe.php:758`) | — | **`partial`** — تورِ ایمنی `ReconcileOrders` آن را با نام می‌آورد (`OrderInconsistency::OrderWithoutLine`) | **P1** |
| سقفِ اعلام‌شدهٔ تورِ ایمنی | `ReconcileOrders::ORDER_WINDOW = 100`، **روی صفحه چاپ می‌شود** (`OrderAuditPage.php:172-185`) | `OrderReconciliationTest` | `implemented` · Verified in source (cross-check تمیز) | — |
| HPOS: اعلام‌شده، و هیچ کوئری سفارش از `wp_posts` | `HposDeclaration.php:18` | `LifecycleSyncHasNoPaymentPathTest` | `implemented` · Verified in source | — |
| HPOS روی نصب واقعی | — | — | **`Not Run`** | P0 |
| لغو ⇒ سند معکوس، accrual دست‌نخورده | `SyncOrderLifecycle` · `WcOrderLifecycle` | `OrderFlowTest` · `OrderLifecycleHooksTest` | `implemented` · Verified in evidence | — |
| ⛔ **تعارض با دکانِ فعال** | `OrderOperationsGate::DOKAN_RUNNING` **اول** پرسیده می‌شود، trial نمی‌تواند جوابش دهد، `WpDokanPresence` fail-closed | `DokanPresenceFailsClosedTest` | `implemented` · Verified in source | — |
| ⛔ نتیجهٔ عملیاتی‌اش | **روی تولید، اگر دکان روشن باشد هیچ فروشی ثبت نمی‌شود — با سکوت، نه با خطا** | — | **خطر اعلام‌شده** | **P0 در runbook** |

## ۶) کمیسیون، دفترکل، سهم فروشنده، تسویه

| قابلیت | مسیر واقعی | آزمون | وضعیت | اولویت |
|---|---|---|---|---|
| کمیسیون به ازای محصول/تنوع | `WpTectebCommissionSource::forItem()` (تنوع اول، بعد والد) | `TectebCommissionPanelTest` | `implemented` · Verified in source | — |
| مبنا: پس از تخفیف، **بی حمل و بی مالیات** | `TectebCommissionCalculator` | `CommissionBaseExcludesTaxAndShippingTest` | `implemented` · Verified in source | — |
| تخصیص تخفیف به سهم فروشنده | `VendorFundedDiscount` | `OrderFlowTest` | `implemented` · Verified in evidence | — |
| precision: کمیسیون در exponent ۴، **هیچ گردکردنی** | `CommissionPrecision` | `NoRoundingInCommissionTest` | `implemented` · Verified in source | — |
| واحد پول روی **خودِ ردیف** | migration ۳۳ · `MoneyUnitClaim` | `MoneyUnitContractTest` | `implemented` · Verified in evidence | — |
| دفترکل فقط‌افزودنی و متوازن | `LedgerTransaction::balances()` | `LedgerTest` | `implemented` · Verified in evidence | — |
| ⛔ **«بدون کمیسیون» یک نتیجه است، نه یک رد** | `RecordCommission.php:109-112` · `TectebCommission::NONE` | `TectebCommissionTest` | `implemented` **به تصمیم مالک** · Verified in source | — |
| ⛔ **و هیچ کدی در `src/` کلید کمیسیون دکان را نمی‌خواند** | جست‌وجو: صفر هیت | — | **یافتهٔ P0 بند ۰-۱** | **P0** |
| تسویهٔ دستی مدیر | `ManageOrderItems::recordSettlementCompletion()` | `SettlementFlowTest` | `implemented` · Verified in source | — |
| تسویهٔ خودکار و برداشت فروشنده | `SettlementGate::AUTOMATIC_SETTLEMENT_OPEN = false` · `VendorWithdrawalPolicy::OPEN = false` | `OwnerDecisionsAreEnforcedTest` | **`guarded` به تصمیم مالک — در v1 بسته** | — |

## ۷) ارسال، مرجوعی، سند معکوس

| قابلیت | مسیر واقعی | آزمون | وضعیت | اولویت |
|---|---|---|---|---|
| ارسال جزئی، ردیف به ازای هر بسته | `tmc_shipments` · `ShipItems` | `ShipmentAndReturnFlowTest` | `implemented` · Verified in evidence | — |
| مرجوعی کامل/جزئی با تخصیص دقیق | `ReturnAllocation` | `tests/Unit/ReturnAllocationTest.php` | `implemented` · Verified in evidence | — |
| بازگشت یک‌بارهٔ موجودی | `RefundScope` · `WcRefundRecorder` | `ShipmentAndReturnFlowTest` | `implemented` · Verified in evidence | — |
| سند معکوس **کنار** accrual، نه جایش | `…:void` · `forEvent()` | `LedgerTest` | `implemented` · Verified in source | — |
| idempotency مرجوعی + بازیابی از crash | `reversal_event_key IS NULL` + UNIQUE | `ShipmentAndReturnFlowTest` | `implemented` · Verified in evidence | — |
| ⛔ **تفکیک سند حسابداری از refund ووکامرس از پرداخت واقعی** | `RefundScope`: دفترکل ✅ موجودی ✅ refund ووکامرس ⛔ انتقال وجه ⛔ | `RefundScope` tests | `implemented` (تفکیک) · **پرداخت واقعی `absent`، درگاهی نیست** | P0 (پس از cutover) |

## ۸) اعلان، تیکت، نظر، گزارش، API

| قابلیت | مسیر واقعی | آزمون | وضعیت | اولویت |
|---|---|---|---|---|
| اعلان داخل پنل (هیچ `send()` ای) | `tmc_notices` | `EngagementFlowTest` | `implemented` · Verified in evidence | — |
| تیکت با پیوست خصوصی، نوع از بایت‌ها | `ManageTickets` | `EngagementFlowTest` | `implemented` · Verified in evidence | — |
| نظر = دیدگاه خودِ ووکامرس؛ امتیاز فروشگاه جدول ما | `ManageReviews` · `tmc_vendor_ratings` | `EngagementFlowTest` | `implemented` · Verified in evidence | — |
| گزارش‌ها + CSV امن (یک قاعده، دو خواننده) | `CsvCell` | `CsvCellTest` + گاردِ معماری | `implemented` · Verified in source | — |
| صفحهٔ «سفارش‌ها ردیف‌به‌ردیف»، ۴ کوئری/صفحه | `OrderLedgerPage` · `OrderLedgerReport` | `OrderLedgerReportTest` | `implemented` · Verified in evidence | — |
| ⛔ REST: مجوز **`Order/View` و `Finance/View`، هر دو** | `alpha.102` §۳ | `PagesTest` | `implemented` · Verified in source (اصلاح P1 دور قبل) | — |
| ممیزی: `search()` با مجوز خودش | `tmc_view_audit` | `AuditTrailReadabilityTest` | `implemented` · Verified in evidence | — |

## ۹) صف، cron، leasing

| قابلیت | مسیر واقعی | آزمون | وضعیت | اولویت |
|---|---|---|---|---|
| `tmc_run_jobs`، گروه `tecteb-marketplace-core` | `WpJobScheduler.php:82,113` | `JobQueueTest` | `implemented` · **Verified in source** | — |
| **`last_tick` ≠ `last_run`**، و هر دو روی صفحه | `WpJobScheduler.php:37-49` · `QueuePage.php:97-105` | `QueueProgressDisplayTest` | `implemented` · Verified in source (`alpha.71`) | — |
| Action Scheduler تنها پس از `action_scheduler_init` | `ensureScheduled` روی `init` اولویت ۲۰ | `JobQueueTest` | `implemented` · Verified in source (`alpha.13`) | — |
| leasing، cursor، ازسرگیری، دو کارگر | `claim()` یک `UPDATE` · `tmc_jobs.live_key` | `JobQueueTest` · `DokanImportJobTest` | `implemented` · Verified in evidence | — |
| **تعداد کارهای این افزونه در آن گروه** | **یک** قلاب (`tmc_run_jobs`): یک recurring + یک async | `JobQueueTest` | `implemented` · Verified in source — پس «۲ در برابر ۳۷» عددِ **انتظاری** است | P1 بسته |
| ⛔ `wp_remote_post` ِ ۴۰۳ و `rocket_saas_pending_jobs` | **صفر** فراخوان شبکه در کلِ `src/`، با گاردِ معماری روی **همهٔ** ۶۶۳ فایل | `ImportMakesNoOutboundRequestTest` | **این افزونه نیست** · Verified in source | P1 بسته |
| ارسال رویداد بیرونی | `tmc_event_outbox` → همیشه `blocked` | `JobQueueTest` | **`guarded` به‌عمد** | — |

## ۱۰) امنیت، کش، SEO، قالب

| قابلیت | مسیر واقعی | آزمون | وضعیت | اولویت |
|---|---|---|---|---|
| nonce + capability روی هر نوشتن | هر `Area`/`Page` | `AreaActionsAreRegisteredTest` (هر دو جهت) | `implemented` · Verified in source | — |
| هیچ دادهٔ شخصی روی صفحهٔ عمومی | `StorePage` | `StorePublicSurfaceTest` | `implemented` · Verified in evidence | — |
| Rank Math: sitemap و canonical تحویل می‌شود | `RankMathStoreSitemap` · `SeoHandover` | `tools/seo-check.sh` | `implemented` با ایستادهٔ جایگزین · **Rank Math واقعی `Not Run`** | P1 |
| Elementor / JetWooBuilder | `[tmc_vendor_box]` (همان رندرکننده) | `VendorAccountLinkTest` | `guarded` · **قالب واقعی `Not Run`** | P1 |
| سازگاری قالب، خودثبت‌شده روی صفحهٔ سلامت | `alpha.56` | — | `implemented` (ثبت) · **قالب واقعی `Not Run`** | P1 |
| ابزارهای فقط‌خواندنی، با گارد از فایل | `tools/*.php` × ۴ | `tests/Unit/ReadOnlyInventoryTest.php` | `implemented` · Verified in evidence | — |

## ۱۱) چرخهٔ عمر، migration ۳۹، توقف فروش، بازگشت

| قابلیت | مسیر واقعی | آزمون | وضعیت | اولویت |
|---|---|---|---|---|
| ساختار داده **۳۹**، و این دور **هیچ migration ای** | `SchemaVersion::TARGET` | `MigrationMariaDbTest` | `implemented` · Verified in source | — |
| دروازهٔ ارتقا روی درخواست مدیر + throttle ۳۰۰ ثانیه | `UpgradeGate` | `ActivationFlowTest` | `implemented` · Verified in source | — |
| ⛔ **غیرفعال‌سازی ≠ توقف فروش** | `DeactivationStopPolicy`: پاسخ ذخیره‌شده، **پیش‌فرض نه** | `LifecycleTest` + کنترل opt-in | `implemented` · Verified in source (`alpha.102` §۲) | — |
| ⛔ بهای آن پیش‌فرض | با افزونهٔ خاموش محصول خریدنی می‌مانَد و سهم ثبت نمی‌شود — **شمرده و گفته می‌شود** | `stopAsResult()` + `unseen` | `implemented` · Verified in source | — |
| توقف فروش از مکانیزم **خودش** | صفحهٔ «وضعیت فروش بازارگاه» | `AdminPagesRenderTest` | `implemented` · Verified in evidence | — |
| `MoneyScaleRollbackGate` سه جواب (`safe`/`blocked`/`unknown`) | `MoneyScaleRollbackGate` | `MoneyUnitColumnStateTest` | `implemented` · **جوابِ تولید `Not Run`** | P0 |
| آرشیو مالی دکان حفظ می‌شود | `DokanReaderInterface` بی هیچ متد نوشتنی | `RecordImportIsHistoryOnlyTest` | `implemented` · Verified in source | — |
| **اجرای واقعی ارتقا و بازگشت روی وردپرس** | `tools/upgrade-*-check.sh` | — | **`Not Run` این دور** (هیچ وردپرسی در محیط نیست) | P1 |

## ۱۲) تجربهٔ کاربری لازم برای فروش روز اول

| مورد | وضعیت | اولویت | چرا این‌جا |
|---|---|---|---|
| مدیر بتواند بفهمد کدام محصول کمیسیون ندارد | **اصلاح شد** (ستون و خلاصهٔ تازهٔ ابزار ممیزی) | **P0** | بی آن، مدیر عددی برای تصمیم ندارد |
| پیام رد خرید به خریدار، بی نام DEC و نرخ | `implemented` (`NoticeMessages`) | P0 | آزمون نبودِ آن کلیدواژه‌ها را می‌سنجد |
| «توقف فروش» با یک دکمه و بی ترمینال | `implemented` | P0 | runbook به آن تکیه دارد |
| صفحهٔ «ممیزی سفارش» با سقفِ اعلام‌شده | `implemented` | P0 | تنها جایی که سفارشِ بی‌سند با نام می‌آید |
| ارقام فارسی و واحد پول در همهٔ ارقام | `implemented` (`MoneyText`) | P1 | — |
| بازطراحی تزئینی صفحه‌ها | **خارج از دامنهٔ روز اول** | P2 | عمداً اینجا نیامده |

---

## ۱۳) آنچه این سند نمی‌گوید

- نمی‌گوید روی تولید چند محصول کمیسیون ندارند. آن عدد را فقط اجرای
  `wp eval-file tools/production-audit.php products` روی تولید می‌دهد.
- نمی‌گوید «۱۹۷ در برابر ۱۸۴» کدام است. «۱۸۴» یک اندازه‌گیریِ **staging** است و
  «۱۹۷/۹۸» شمارشِ **تولید** در ۱۴ نوامبر ۲۰۲۶؛ دو سایت، دو عدد، و تطبیقشان با
  شناسه از WXR **ممکن نیست** چون WXR جدول `tmc_products` را ندارد.
- نمی‌گوید staging قبول شده. صفر از پانزده گام اجرا شده است.
- نمی‌گوید پرداخت کار می‌کند. **هیچ پرداخت موفق واقعی تا امروز تأیید نشده.**
