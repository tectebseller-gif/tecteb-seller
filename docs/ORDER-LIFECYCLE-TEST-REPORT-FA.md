# گزارش آزمون — چرخهٔ عمر سفارش، موجودی، پول و گزارش‌ها (`alpha.99`)

> مبنا: `0.1.0-alpha.98` (RC2)، commit `9c7aef3`، شاخهٔ `claude/new-session-z1vafy`.
> **هیچ اتصال یا تغییری روی staging یا production انجام نشد.** هر ردیفی که به
> وردپرس واقعی، ووکامرس واقعی، دکان، Min Max، قالب، کش، درگاه یا PHP 8.1 نیاز
> دارد **`Not Run`** است — نه «قبول». محیط این اجرا: PHP **۸٫۳٫۶**،
> MariaDB 10.11 (`tmc_test`)، بدون وردپرس، بدون ووکامرس، بدون دکان.

## ۰. خواندنِ این گزارش

سه حکم به کار می‌رود و هر سه معنای دقیق دارند:

| حکم | معنا |
|---|---|
| **Pass** | آزمونی وجود دارد، روی این درخت اجرا شد، سبز شد، و **با برگرداندن نقص قرمز می‌شود** (حلقهٔ falsification، `docs/evidence/alpha99-falsification/`). |
| **Fail** | آزمون وجود دارد و قرمز است. در این گزارش هیچ ردیفی `Fail` نیست؛ اگر بود، نسخه تحویل نمی‌شد. |
| **Not Run** | اجرا **نشده**. دلیلش در ستون آخر نوشته شده. این با «قبول» یکی نیست و هیچ‌جا به‌عنوان قبول گزارش نمی‌شود. |

«ورودی» همان فیکسچر است: شناسه‌ها و ارقامِ واقعیِ آزمون، نه توصیف.

---

## ۱. §۳ — چرخهٔ عمر سفارش (P0)

**علتِ نقصِ اصلی، اندازه‌گیری‌شده از سورس:** `DbOrderItemRepository::settlementView()`
و `totalsForVendor()` از روز اول `status <> 'cancelled'` را در `WHERE` دارند و
`Reports::marketplaceOperations()` عددِ «منتظر ارسال» را از
`countForVendor($v, OrderItemStatus::Placed)` می‌گیرد. **لایهٔ گزارش غلط نبود؛
هیچ‌چیز هرگز وضعیت قلم را `cancelled` نمی‌کرد.** `OrderHooks` به
`woocommerce_order_status_changed` گوش می‌داد و تنها کاری که آنجا می‌کرد
بازخوانی **آینهٔ موجودی** بود. پس هر چهار عددی که مالک روی staging دید — سهم
ثبت‌شده ۴۰۱٬۷۱۹٬۵۰۰، سهم در انتظار ۴۰۱٬۷۱۹٬۵۰۰، اقلام منتظر ارسال ۲، مرجوعیِ
باز صفر — **جوابِ درستِ یک پرسشِ بی‌پاسخ‌مانده** بود.

| شناسه | سناریو | ورودی | انتظار | مشاهده | حکم | مسیر کد / شاهد |
|---|---|---|---|---|---|---|
| L-01 | لغو سفارش در ووکامرس روی قلمی که ارسال نشده | یک قلم ثبت‌شده، سهم و کمیسیون روی ردیف، سپس `woocommerce_order_status_changed → cancelled` | وضعیت قلم `cancelled`، سند معکوس نوشته می‌شود، سهم از «ثبت‌شده» و «در انتظار» و «منتظر ارسال» بیرون می‌رود | همان | **Pass** | `SyncOrderLifecycle::voidAccrual()` · `OrderFlowTest::testCancellingAnOrderInWooCommerceVoidsTheShareAndTheLine` |
| L-02 | مبلغ تاریخی می‌مانَد، تعهد نمی‌مانَد (§۳-۳) | همان | چهار خط اصلیِ accrual **دست‌نخورده** در دفترکل + چهار خط معکوس **کنارشان**؛ `forEvent(<accrual>)` همان جواب قبلی | همان؛ جمعِ خالصِ رویداد و معکوسش **صفر** | **Pass** | `OrderFlowTest::testTheAccrualStaysInTheLedgerAndAReversingDocumentIsAddedBesideIt` |
| L-03 | قلاب تکراری (webhook دوبار، cron، تلاش دوباره) | همان لغو، دو بار | بار دوم **صفر** نوشتن: سند روی کلید یکتا رد می‌شود و تغییر وضعیت no-op است | همان | **Pass** | `UNIQUE KEY tmc_ledger_event` · `OrderFlowTest::testARepeatedCancelHookWritesNothingTwice` |
| L-04 | سفارش تازه و پرداخت‌نشده / پرداخت ناموفق | `pending`, `on-hold`, `checkout-draft`, `failed` | **هیچ نوشتنی**؛ رکورد می‌مانَد و برای **هیچ‌کس** قابل تسویه نیست | همان | **Pass** | `WcOrderLifecycle::AWAITING_PAYMENT`/`PAYMENT_FAILED` · `OrderFlowTest::testAnUnpaidOrFailedOrderKeepsItsRecordAndIsSettleableByNobody` |
| L-05 | سفارش `refunded` | قلم ثبت‌شده، سپس `refunded` | **هیچ نوشتنی**، و `refund_needs_decision` گزارش می‌شود (§۳-۶: ثبت لغو ≠ استرداد واقعی) | همان | **Pass** | `WcOrderDisposition::needsADecision()` · `OrderFlowTest::testARefundedOrderIsReportedAndNothingIsWritten` |
| L-06 | وضعیتی که این نسخه نمی‌شناسد | `wc-some-gateway-hold` | **هیچ نوشتنی**، و `wc_status_unknown:<slug>` با نامِ پاک‌سازی‌شده | همان | **Pass** | `OrderFlowTest::testAnUnknownStatusWritesNothingAndNamesItself` |
| L-07 | لغو **پس از** ارسال کالا | قلمِ `shipped` با کد رهگیری، سپس `cancelled` | **هیچ نوشتنی**، و `cancel_after_shipping` گزارش می‌شود؛ ابزارِ این حالت **مرجوعی** است نه تغییر وضعیت | همان | **Pass** | `OrderItemStatus::hasShipped()` · `OrderFlowTest::testACancelAfterTheGoodsShippedIsReportedAndWritesNothing` |
| L-08 | سفارش از `cancelled` بیرون می‌رود (§۳-۹) | لغو، سپس `processing` | قلم `cancelled` می‌مانَد؛ `reopened_after_void` گزارش می‌شود و **هیچ تعهدی بازسازی نمی‌شود** | همان | **Pass** | `OrderFlowTest::testAnOrderThatLeavesCancelledIsReportedAndNotReinstated` |
| L-09 | سفارش چندفروشنده‌ای | دو فروشنده، سه قلم | هر قلم جداگانه لغو و معکوس می‌شود و `items === cancelled + untouched + failed` | همان | **Pass** | `OrderFlowTest::testAMultiVendorOrderVoidsEveryLineAndTheCountsAddUp` |
| L-10 | قلمی که هیچ‌وقت accrual نداشت (FIN-02) | قلم با نرخِ حل‌نشده ⇒ ارقام `NULL` | قلم `cancelled` می‌شود، **هیچ سندی ساخته نمی‌شود**، و `cancelled_unrecorded` گزارش می‌شود | همان | **Pass** | `VendorOrderItem::isRecorded()` · `OrderFlowTest::testALineThatNeverAccruedIsCancelledWithoutADocument` |
| L-11 | سفارشی که هیچ قلم بازارگاهی ندارد | سبد کالای خودِ فروشگاه | **سکوت کامل**: نه نوشتن، نه ردیف ممیزی | همان | **Pass** | `OrderFlowTest::testAnOrderWithNoMarketplaceLinesIsSilent` |
| L-12 | سند معکوس و وضعیت، **یک واحد کار** | شکست تزریقیِ نوشتنِ وضعیت پس از نوشتن سند | سند **پس گرفته می‌شود** (`rollback`) و `void_status_unwritten` گزارش می‌شود؛ هیچ نیمه‌ای نمی‌مانَد | همان | **Pass** | `LifecycleSyncHasNoPaymentPathTest::testAStatusThatWillNotWriteRollsTheDocumentBack` |
| L-13 | ترتیب داخل واحد کار | — | **دفترکل اول، وضعیت بعد**: ترتیب عکس، نقص را روی هر شکستی بازمی‌سازد | همان، با افستِ بایتی و استخراج بلوک با **تطبیق آکولاد** | **Pass** | `LifecycleSyncHasNoPaymentPathTest::testTheLedgerIsWrittenBeforeTheStatusAndBothAreInOneTransaction` |
| L-14 | ساختی بدون تراکنش | `SyncOrderLifecycle` بی `TransactionInterface` | **رد با نام** (`void_not_atomic`)؛ هیچ نیمه‌ای نوشته نمی‌شود | همان | **Pass** | `SyncOrderLifecycle::voidAccrual()` |
| L-15 | واحد پول سند معکوس | accrual با دو exponent متناقض | **رد** (`void_unit_inconsistent`)؛ معکوسی که واحدش را حدس بزند حسابی درست و بی‌معنا است (`alpha.39`) | همان | **Pass** | `SyncOrderLifecycle::accrual()` |
| L-16 | کلید سند معکوس | — | از کلید accrual **مشتق** و با آن **برابر نیست** (`:void`) — یک رویداد، یک سند، سه ابزار که هرگز تصادم نمی‌کنند (FIN-03) | همان | **Pass** | `LifecycleSyncHasNoPaymentPathTest::testTheVoidKeyIsDerivedFromTheAccrualKeyAndIsNotIt` |
| L-17 | **هیچ استرداد بانکی، پرداخت یا تسویهٔ خارجی** (§۳-۷) | — | با **نبودِ قابلیت** اجرا می‌شود: صفر ارجاع به درگاه، `wc_create_refund`، payout یا هر فعل پولیِ بیرونی در این کلاس | ۲۴ needle ممنوع، صفر هیت | **Pass** | `LifecycleSyncHasNoPaymentPathTest::testTheServiceCannotDoIt` (data provider) |
| L-18 | لایهٔ Application به وردپرس دست نمی‌زند | — | صفر فراخوان تابع وردپرس در این کلاس | همان | **Pass** | `LifecycleSyncHasNoPaymentPathTest::testItCallsNoWordPressFunction` |
| L-19 | نگاشتِ وضعیت ووکامرس | ۱۱ وضعیت + پیشوند `wc-` + حروف بزرگ + فاصله | هر وضعیتِ شناخته‌شده به یکی از شش معنا؛ ناشناخته **`Unknown`** نه حدس | همان | **Pass** | `WcOrderLifecycleTest` (۱۰ آزمون، ۴۷ assertion) |
| L-20 | `trash` | `woocommerce_trash_order` | یک گذارِ وردپرسی است که هیچ تغییر وضعیتی اعلامش نمی‌کند، پس **قلاب خودش** را دارد و تعهد را باطل می‌کند | همان | **Pass** | `OrderLifecycleHooksTest::testTrashingAnOrderReachesTheSyncAsTrash` |
| L-21 | قلاب‌ها واقعاً ثبت و اجرا می‌شوند | `do_action('woocommerce_order_status_changed', 12300, 'processing', 'cancelled')` | سرویس با **وضعیت تازه** صدا می‌شود؛ آرگومان سوم مهم است چون شیء سفارش ممکن است هنوز ذخیره نشده باشد | همان؛ و **آینهٔ موجودی روی همان قلاب سر جایش** (دو callback) | **Pass** | `OrderLifecycleHooksTest` (۷ آزمون) |
| L-22 | شکستِ sync کلیک مدیر را نمی‌شکند | sync ای که استثنا می‌دهد | اکشن بی‌استثنا برمی‌گردد؛ ردیف ممیزی می‌گوید چه شد | همان | **Pass** | `OrderLifecycleHooksTest::testAThrowingSyncDoesNotEscapeTheHook` |
| L-23 | مرجوعی روی قلمِ لغوشده | قلم `cancelled`، سپس `ManageReturns::open()` | **رد با نام** (`item_cancelled`) — وگرنه یک معکوسِ دوم روی همان قلم نوشته می‌شد | همان | **Pass** | `ShipmentAndReturnFlowTest::testAReturnCannotBeOpenedOnACancelledLine` |
| L-24 | پنل فروشنده، صفحهٔ مدیر و گزارش مالی یک جواب می‌دهند (§۳-۱۰) | پس از لغو | هر سه از **یک** شرط می‌خوانند (`status <> 'cancelled'` در `settlementView()`/`totalsForVendor()`، و `countForVendor(Placed)`) | همان | **Pass** | L-01 به‌علاوهٔ `SettlementFlowTest` |
| L-25 | لغو روی **سفارش واقعیِ پرداخت‌شده** با درگاه واقعی | — | — | — | **Not Run** | هیچ درگاهی در این محیط نیست؛ هر دو سفارش مالک آفلاین/آزمایشی بودند و **هیچ پرداخت موفق واقعی تأیید نشده** |

⛔ **و یک تقارنِ عمدی:** باطل‌کردن یک تعهد جهتِ امن است و ساختنش نیست. پس
**تنها** `cancelled`/`trash` می‌نویسند و بقیه **نام برده می‌شوند**؛ و هیچ مسیری
تعهد را دوباره نمی‌سازد. `failed` عمداً باطل نمی‌کند: وضعیتی که عقب و جلو
می‌رود نباید پول را عقب و جلو ببرد.

---

## ۲. §۴ — موجودی

**قاعده: فقط به ووکامرس تکیه می‌شود و دقیقاً آینه می‌شود.**

| شناسه | سناریو | ورودی | انتظار | مشاهده | حکم | مسیر کد / شاهد |
|---|---|---|---|---|---|---|
| S-01 | مسیر سفارش هیچ مقدار مطلق موجودی نمی‌نویسد | — | صفر فراخوان `set_stock_quantity` / `wc_update_product_stock` در کلِ ماژول `Order` | **اندازه‌گیری‌شده: صفر.** تنها دو فراخوان، هر دو `pullStock()` — که از ووکامرس **می‌خواند** و در ردیف خودمان آینه می‌کند | **Pass** | `grep -rn 'set_stock_quantity\|wc_update_product_stock' src/Modules/Order/` → ۰ · `CaptureOrder.php:364,562` |
| S-02 | کاهش موجودی هنگام پرداخت | `woocommerce_reduce_order_stock` | آینه تازه می‌شود؛ خودِ عدد کارِ ووکامرس است | — | **Not Run** | نیازمند ووکامرس واقعی |
| S-03 | بازگرداندن موجودی هنگام لغو | `woocommerce_restore_order_stock` | همان | — | **Not Run** | همان |
| S-04 | قلاب تکراری | دو بار همان قلاب | آینه idempotent است (`pullStock()` فقط وقتی مقدار فرق دارد می‌نویسد) | از سورس خوانده شد | **Pass (سورس)** | `SyncCatalog::pullStock()` |
| S-05 | تنوع‌ها | محصول متنوع | موجودی هر تنوع جدا آینه می‌شود | از سورس خوانده شد | **Pass (سورس)** | `readVariationStock()` / `updateVariationStock()` |
| S-06 | لغو جزئی یک قلم | — | — | — | **Not Run** | نیازمند ووکامرس واقعی |
| S-07 | **موجودی ۱۸۴ محصول staging/production دست نمی‌خورد** | — | هیچ تغییری | **هیچ تغییری؛ هیچ اتصالی برقرار نشد** | **Pass (با نبودِ اقدام)** | §۰ این گزارش |

⛔ **آنچه موجودی ووکامرس را می‌نویسد، بیرون از مسیر سفارش است و دست نخورد:**
`WooCommerceProjector` (ویرایش صریح فروشنده، از `alpha.50` با compare-and-set
داخل statement خودِ ووکامرس) و `WcUnpaidOrderGuard` (که `wc_maybe_*_stock_levels`
خودِ ووکامرس را صدا می‌زند، هرگز یک مقدار مطلق).

---

## ۳. §۵ — پول، کمیسیون و مرجوعی

| شناسه | سناریو | ورودی | انتظار | مشاهده | حکم | مسیر کد / شاهد |
|---|---|---|---|---|---|---|
| M-01 | کمیسیون صفر | محصول بی‌کمیسیون | کلِ مبلغ قلم سهم فروشنده | همان | **Pass** | `OrderFlowTest::testAProductWithNoCommissionIsCapturedWithTheWholeLineAsTheVendorsShare` |
| M-02 | درصدی | ۱۲٫۵٪ روی ۱۰۱ تومان | کمیسیون **۱۲٫۶۲۵** و سهم **۸۸٫۳۷۵** — **هیچ گردکردنی** | همان، در چهار رقم اعشار (`CommissionPrecision`) | **Pass** | `OrderFlowTest::testTheOwnersWorkedExampleSurvivesTheRoundTripToTheRowAndTheLedger` |
| M-03 | مقدار ثابت | ۲۰٬۰۰۰ × ۳ | ۶۰٬۰۰۰، **به ازای واحد** | همان | **Pass** | `OrderFlowTest::testAFixedCommissionIsCapturedPerUnitAndSurvivesALaterRuleChange` |
| M-04 | متنوع با نرخ والد | تنوع بی‌تنظیم | تنظیم والد مبنا، و snapshot می‌گوید از کجا آمد (`from=product`) | همان | **Pass** | `TectebCommission` · `OrderFlowTest` (بندهای کمیسیون) |
| M-05 | متنوع با نرخ خودش | تنوع با تنظیم | تنظیم تنوع (`from=variation`) | همان | **Pass** | همان |
| M-06 | **نرخ پس از سفارش عوض می‌شود** | ثبت، سپس تغییر قاعده به ۲۵٪ | snapshot قلم **دست‌نخورده** | همان | **Pass** | `OrderFlowTest::testAFixedCommissionIsCapturedPerUnitAndSurvivesALaterRuleChange` · `testARecordedEventIsRecoverableAfterItsRateIsRemoved` |
| M-07 | تخفیف | کوپن ۵۰٪ | درصد روی مبلغِ **پس از تخفیف** خودِ ووکامرس، و **دو بار** کم نمی‌شود | همان | **Pass** | `OrderFlowTest::testAPercentageIsTakenOnWooCommercesOwnAfterDiscountTotal` |
| M-08 | مالیات | `line_tax_minor` ناصفر | مالیات در مبنای کمیسیون **نیست** و حساب `TaxCollected` پیاده و متوازن است | همان؛ چهار خط دفترکل | **Pass** | `RecordCommission::accrue()` · L-02 |
| M-09 | حمل | — | در مبنای کمیسیون **نیست** | از سورس خوانده شد | **Pass (سورس)** | `RecordCommission` |
| M-10 | کوپن سراسری | — | **قابل‌رسیدن نیست** (`global_coupon_undecided`) تا تصمیم مالک | همان | **Pass (بسته)** | `ManageCoupons::GLOBAL_UNDECIDED` · جدول تصمیم‌ها |
| M-11 | چند قلم از یک فروشنده | — | هر قلم سند خودش؛ جمع دقیق | همان | **Pass** | `OrderFlowTest::testSeveralFractionalSharesAddUpExactlyInBothTotals` |
| M-12 | چند فروشنده در یک سفارش | — | یک قلم به ازای هر فروشنده با سهم خودش | همان | **Pass** | `OrderFlowTest::testAMultiVendorBasketBecomesOneLinePerVendorWithItsOwnShare` |
| M-13 | لغو **یک** قلم | — | همان مسیر L-01 به ازای آن قلم؛ بقیهٔ قلم‌ها دست‌نخورده | همان | **Pass** | L-09 |
| M-14 | بازپرداخت جزئی | ۱۰۱ تومان، سه عدد، ۱۲٫۵٪ | تخصیصِ کف‌شده در ریزترین واحد و باقیمانده روی **آخرین** مرجوعی؛ جمع نهایی دقیق | `336666`/`42083`/`294583` سپس `673334`/`84167`/`589167` و جمع `1010000`/`126250`/`883750` | **Pass** | `ReturnAllocation` · `ShipmentAndReturnFlowTest::testTheOwnersInexactExampleIsAllocatedAndClosesExactly` |
| M-15 | مرجوعیِ تعداد کمتر | ۱ از ۳ | دقیق، و جمعِ سه مرجوعیِ یکی‌یکی = کلِ قلم | همان | **Pass** | `testRefundingAThreeUnitLineOneAtATimeAddsUpToTheWholeLineExactly` |
| M-16 | عملیات مالی تکراری | همان مرجوعی، دو بار | `already_refunded`؛ **سند دوم ساخته نمی‌شود** | همان | **Pass** | `testTheSameReturnCannotBeRefundedTwice` · `testTwoRefundsRacingOnTheSameReturnProduceExactlyOneReversal` |
| M-17 | شکست نوشتن یک سند مالی | شکست تزریقی روی خط دفترکل | هیچ اثر نیمه‌کاره؛ برای یک انسان پارک می‌شود نه تلاش دوبارهٔ خودکار | همان | **Pass** | `testARefundWhoseLedgerWriteFailsIsParkedForAPersonRatherThanRetried` |
| M-18 | **دفترکل همیشه متوازن** | هر سناریوی بالا | جمع هر رویداد و معکوسش صفر | همان | **Pass** | L-02 · `LedgerTransaction` |
| M-19 | **هیچ عددی ۱۰ یا ۱۰۰ برابر نشود** | ۴۵۰٬۰۰۰ در `IRT/0` و ۸۸٫۳۷۵ در exponent 4 | هر مبلغ با واحد و exponent خودش؛ دو ارز **جمع نمی‌شوند** | همان؛ `mixed_currencies` رد می‌کند | **Pass** | `OrderLedgerFilterTest::testTwoExponentsCombineAndTwoCurrenciesRefuse` · `OrderFlowTest::testASecondUnitOfMoneyIsRefusedRatherThanAddedToTheBooks` |
| M-20 | شکست بین ثبت سفارش و دفترکل | شکست تزریقی | قلم نیمه‌تمام **نام برده** می‌شود و قلاب تکراری تعمیرش می‌کند، بی بازنویسی ارقام | همان | **Pass** | `OrderFlowTest::testAHalfWrittenLineIsRepairedFromItsOwnLedgerEventAndNotFromTodaysRate` (+۷ آزمون همسایه) |

---

## ۴. §۷ — گزارش تفصیلی

| شناسه | سناریو | ورودی | انتظار | مشاهده | حکم | مسیر کد / شاهد |
|---|---|---|---|---|---|---|
| R-01 | صفحه‌بندی و شمارش، **هر دو سمت سرور** | ۶۰ ردیف، `per_page=25` | `LIMIT` در statement و `COUNT(*)` روی **همان** `WHERE`؛ هیچ سفارش یا محصول ووکامرسی بارگذاری نمی‌شود | همان؛ **۴ کوئری** به ازای هر صفحه، هر اندازه که صفحه باشد | **Pass** | `DbOrderItemRepository::detailed()` · `OrderLedgerReportTest::testThePageAndTheCountAreBothServerSide` |
| R-02 | یک `WHERE`، دو خواننده | — | `detailedWhere()` **یک بار** صدا می‌شود | همان | **Pass** | `OrderLedgerReportHasNoCustomerDataTest::testTheCountAndThePageAskTheSameQuestion` |
| R-03 | شمارشِ ناخوانا | شکست `COUNT(*)` | `total = null` («نامعلوم») — **هرگز صفر** | همان؛ صفحه عددی چاپ نمی‌کند | **Pass** | `alpha.8` · `OrderLedgerReportTest` |
| R-04 | هر فیلتر | نام محصول، شناسهٔ والد، شناسهٔ تنوع، SKU، شناسهٔ سفارش، فروشنده، وضعیت سفارش، وضعیت پرداخت، وضعیت ارسال، لغو/مرجوعی، بازهٔ تاریخ، نوع کمیسیون، مبلغ/بازهٔ مبلغ | هر کدام به ردیف‌های خودش باریک می‌کند | همان | **Pass** | `OrderLedgerFilter` · `OrderLedgerReportTest::testEachFilterNarrowsToItsOwnRows` |
| R-05 | جست‌وجو و wildcard | `%` و `_` در عبارت | با `addcslashes($v, '_%\\')` — همان کاری که `wpdb::esc_like()` می‌کند | همان | **Pass** | `testASearchTermIsEscapedForTheLike` |
| R-06 | مرتب‌سازی | شش حالت | از یک `match` با `default`، و **هر شاخه به `id` ختم می‌شود** (مرز صفحه روی ties پایدار) | همان | **Pass** | `testTheSortsOrderTheRowsAndAreStableOnTies` · `testTheSortIsAMatchWithADefault` |
| R-07 | ایندکس | — | دو ایندکس افزودنی: `(vendor_user_id, wc_order_status)` و `(vendor_user_id, created_at)` | همان، خوانده از `information_schema` | **Pass** | `M0039WcOrderStatusOnRows` · `testTheStatusMirrorAndItsIndexesExist` |
| R-08 | وضعیت سفارش به‌عنوان فیلتر | — | از ستون آینه (migration 39) خوانده می‌شود، **نه** با بارگذاری سفارش به ازای هر ردیف (`alpha.96`) | همان | **Pass** | همان |
| R-09 | ردیفی که آینه ندارد | ردیف پیش از ارتقا | **`unknown`** نه حدس؛ `NULL` یعنی «کسی به ما نگفته» | همان | **Pass** | `testARowWithNoMirroredStatusIsUnknownRatherThanAGuess` |
| R-10 | وضعیت مالی و سند معکوس | قلمِ لغوشده | `financial_state` = `reversed` و `reversal_event` نام سند | همان | **Pass** | `OrderLedgerReport::describe()` |
| R-11 | هزینهٔ خواندنِ سند معکوس | ۶۰ ردیف | **یک** کوئری برای کلِ صفحه | همان | **Pass** | `eventsPresent()` · `testTheReversalStateIsReadForTheWholePageAtOnce` |
| R-12 | قابلیت تسویه | — | پنج جواب نام‌دار: `unrecorded`/`void`/`reserved`/`awaiting_completion`/`complete` | همان | **Pass** | `testTheSettlementStateNamesWhyALineIsNotPayable` |
| R-13 | سه عدد سرجمع | — | **تاریخی** / **خالصِ معتبر** / **در انتظار** جدا، با ارز و exponent | همان؛ قلمِ لغوشده از «معتبر» بیرون است | **Pass** | `OrderLedgerReport::totalsOf()` · `OrderLedgerFilterTest::testTheThreeTotalsSeparateWhatWasSoldFromWhatIsOwed` |
| R-14 | سهمِ ثبت‌نشده صفر نیست | نرخ حل‌نشده | `null` می‌مانَد و در جمع «معتبر» نمی‌آید | همان | **Pass** | `testAnUnrecordedShareIsNotAZero` |
| R-15 | دامنهٔ فروشنده | فروشنده با فیلترِ `vendor=<دیگری>` | دامنه **بازنویسی** می‌شود، نه اعتبارسنجی — پس فیلتر نمی‌تواند پهنش کند | همان | **Pass** | `forVendor()` · `testAVendorsScopeIsOverwrittenAndCannotBeWidened` |
| R-16 | کسی که به فروشگاه دسترسی ندارد | — | **هیچ ردیفی** | همان | **Pass** | `testSomebodyWithNoAccessToAShopReadsNothing` |
| R-17 | مجوز گزارش سراسری | مدیر بی `tmc_view_vendor_finance` | رد | همان | **Pass** | `OrderLedgerReport::forManager()` |
| R-18 | **هیچ دادهٔ مشتری** | — | جدول `tmc_order_items` **ستونی ندارد** که مشتری را نگه دارد، و گزارش به نام، ایمیل، موبایل، نشانی یا شبا نمی‌رسد | **اندازه‌گیری‌شده از `information_schema.COLUMNS`** به‌علاوهٔ ۱۶ needle ممنوع | **Pass** | `testTheOrderItemsTableHasNoColumnThatCouldHoldACustomer` · `OrderLedgerReportHasNoCustomerDataTest` |
| R-19 | خروجی CSV | فیلترهای فعال | **همان** فیلترها؛ پیشوندِ فرمول خنثی؛ nonce و مجوز؛ یک ردیف ممیزی | همان | **Pass** | `OrderLedgerPage::export()`/`cell()` |
| R-20 | بازهٔ تاریخ در منطقهٔ سایت | `from=2026-11-08` در زمان ایران | مرزهای UTC درست | همان | **Pass** | `SiteTime` · `testTheDateFilterIsReadInTheSitesZone` |
| R-21 | رابط فارسی و RTL روی مرورگر واقعی | — | — | — | **Not Run** | بدون وردپرس؛ سوییت مرورگری این دور اجرا نشد |
| R-22 | نمای تفصیلیِ **پنل فروشنده** | — | — | — | **به‌عمد ساخته نشد** | §۷ خودش اجازه داده: «آنچه توسعهٔ قابل‌توجه لازم دارد دقیق و جدا ثبت شود، نه اینکه نصفه‌کاره عرضه شود». ثبت‌شده در `docs/RELEASE-BLOCKERS-FA.md` |

---

## ۵. §۸ — دو نقص صفحهٔ ممیزی

| شناسه | سناریو | ورودی | انتظار | مشاهده | حکم | مسیر کد / شاهد |
|---|---|---|---|---|---|---|
| A-01 | انتخاب نوع رویدادِ نقطه‌دار | `event=order.captured` | ردیف پیدا می‌شود | **علت:** `Request::queryKey()` → `sanitize_key()` نقطه را **حذف می‌کند**، پس فیلتر `ordercaptured` بود. اصلاح: `queryText()` + عضویت در **فهرست مجازِ** `AuditEventCatalog::allowlist()` | **Pass** | `AuditPage::eventFilter()` · `AuditTrailReadabilityTest::testSelectingAnEventTypeWithADotInItFindsIt` |
| A-02 | رویدادی که این افزونه ندارد | `event=nonsense` | به «همه» برمی‌گردد، نه یک `WHERE` که هیچ‌چیز match نمی‌کند | همان | **Pass** | `testAnEventTypeThisPluginDoesNotHaveFallsBackToEverything` |
| A-03 | امنیت بقیهٔ کاربردهای `queryKey()` | — | **دست‌نخورده**: اصلاح در **یک** نقطهٔ فراخوان است، و `sanitize_key()` برای ~۱۹ فراخوان دیگر همان است | همان | **Pass** | `testTheKeySanitiserStillStripsDotsForEverybodyElse` |
| A-04 | زمان رویداد | رویداد ثبت‌شده در UTC | در منطقهٔ **پیکربندی‌شدهٔ وردپرس** رندر می‌شود؛ دیتابیس UTC می‌مانَد | همان (~۳ ساعت و ۳۰ دقیقه اختلافی که مالک دید) | **Pass** | `SiteTime::render()` · `testTheTimeIsPrintedInTheSitesZoneAndNotAsStored` |
| A-05 | فیلتر تاریخ محلی | `from`/`to` | به مرزهای UTC درست تبدیل می‌شود؛ صفحه و کوئری **از یک منطقه** می‌خوانند | همان | **Pass** | `testALocalDateFilterFindsTheLocalDay` · `testTheQueryBoundariesComeFromTheSameZoneAsTheScreen` |
| A-06 | مرز روز، تغییر منطقه، صفحه‌بندی | روزهای متوالی؛ یک سایت UTC؛ منطقهٔ دیگر؛ روزی که نیمه‌شب محلی‌اش وجود ندارد | بی شکاف و بی همپوشانی | همان | **Pass** | `SiteTimeTest` (۸ آزمون، ۳۰ assertion) |
| A-07 | تاریخی که روی تقویم نیست | `2026-02-30` | **رد** نه rollover؛ هیچ باریک‌شدنی | همان | **Pass** | `testADateThatCannotBePlacedDoesNotNarrowAnything` |
| A-08 | **منطقهٔ ایران ثابت در کد نیست** | — | صفر ارجاع به `Asia/Tehran` یا `+03:30` | **اندازه‌گیری‌شده: صفر.** منبع `wp_timezone()` است | **Pass** | `SiteTime` · `Bootstrap` |
| A-09 | ردیف ممیزیِ ثبت سفارش، کلیدهایش | — | `ORDER_CAPTURED` ده کلید دارد | ⛔ **نقص دوم که در دستور نبود:** فهرست مجاز **پنج** کلید داشت و `failed`، `refused_unit`، `repaired`، `incomplete` و **`reasons`** بی‌صدا دور ریخته می‌شدند — یعنی دلیلِ هر ردِ ثبت به ردِ ممیزی نمی‌رسید | **Pass** | `AuditEventCatalog` · `AuditTrailReadabilityTest` |

⛔ **و اینکه این نقص به staging رسید، علت داشت:** stub ِ `wpdb` متد `get_col()`
را نداشت، پس صفحهٔ ممیزی **هرگز** روی مخزن واقعی رندر نشده بود. متد به stub
اضافه شد و همان اجرا پنج شکست داد — یافته‌ای دربارهٔ آزمون‌های خودم، نه کد.

---

## ۶. §۹ — هم‌خوانیِ گزارش و کش

| شناسه | سناریو | انتظار | مشاهده | حکم | مسیر کد / شاهد |
|---|---|---|---|---|---|
| C-01 | پس از ثبت، لغو، مرجوعی و معکوس | سرجمع و تفصیلی هم‌خوان | هر دو از **یک** شرط می‌خوانند؛ قلم لغوشده در هیچ‌کدام «معتبر» نیست | **Pass** | R-13 · L-24 |
| C-02 | ابطال کش | به‌روزرسانی یک سفارش فقط کش مرتبط را باطل کند | ابطال از **نوشتن** آویزان است (`tmc_vendor_figures_changed` از داخل `figuresChanged()`)، شمارندهٔ نسخه به‌جای جاروی کلید | **Pass** | `ReportInvalidationTest` (`alpha.17`) |
| C-03 | خطای خواندن ≠ صفر واقعی | `total = null`، `exponent = null`، `resolved = false` | همان | **Pass** | R-03 · R-14 |
| C-04 | دو ارز بی تبدیل تأییدشده جمع نمی‌شوند | `mixed_currencies` و `minor = null` | همان | **Pass** | M-19 |
| C-05 | کش **پایدار** شیء روی سایت واقعی | — | — | **Not Run** | این محیط کش پایدار ندارد |

---

## ۷. §۱۰ — پوشش آزمون سفارش

| شناسه | مسیر | حکم | دلیل |
|---|---|---|---|
| T-01 | Classic Checkout | **Not Run** | بدون ووکامرس |
| T-02 | Block Checkout (Store API) | **Not Run** | همان |
| T-03 | مهمان و کاربر واردشده | **Not Run** | همان |
| T-04 | محصول ساده | **Pass (دیتابیس)** | `OrderFlowTest` |
| T-05 | محصول متنوع | **Pass (دیتابیس)** | M-04/M-05 |
| T-06 | سبد تک‌فروشنده | **Pass** | `OrderFlowTest` |
| T-07 | سبد چندفروشنده | **Pass** | M-12 · L-09 |
| T-08 | محصولی بیرون از مالکیت بازارگاه | **Pass** | `testTheRefusalSentenceIsOursForOurProductsAndNobodyElsesEver` · L-11 |
| T-09 | محصول ناموجود / غیرفعال | **Pass** | `testASuspendedShopAndAnEmptyShelfBothStopTheSale` |
| T-10 | مالیات | **Pass** | M-08 |
| T-11 | تخفیف | **Pass** | M-07 |
| T-12 | حمل | **Pass (سورس)** | M-09 |
| T-13 | پرداخت آفلاین | **Not Run** | بدون ووکامرس |
| T-14 | پرداخت موفق | **Not Run** | همان؛ **و هیچ پرداخت موفق واقعی روی staging تأیید نشده** |
| T-15 | پرداخت ناموفق | **Pass (دیتابیس)** | L-04 |
| T-16 | پرداخت دیرهنگام | **Pass (دیتابیس)** | L-04 → L-08 |
| T-17 | لغو پیش از پرداخت | **Pass** | L-01 · L-10 |
| T-18 | لغو پس از پرداخت | **Pass (دیتابیس)** | L-01؛ پرداخت واقعی `Not Run` |
| T-19 | بازپرداخت کامل و جزئی | **Pass** | M-14/M-15 |
| T-20 | قلاب/webhook تکراری | **Pass** | L-03 · M-16 |
| T-21 | دو callback هم‌زمان | **Pass** | `testTwoCallbacksForOneOrderLeaveOneDocumentAndOneCompleteLine` · `testTwoRefundsRacingOnTheSameReturnProduceExactlyOneReversal` |
| T-22 | شکست دیتابیس بین ثبت سفارش و دفترکل | **Pass** | M-20 · L-12 |
| T-23 | بازیابی ثبت ناقص | **Pass** | `incompleteCaptures()` + هشت آزمون تعمیر |
| T-24 | **فروشگاه ۴۰ فعال نشد** | **Pass (با نبودِ اقدام)** | هیچ اتصالی برقرار نشد |

---

## ۸. §۱۱ — امنیت و دسترسی

| شناسه | سناریو | انتظار | مشاهده | حکم | مسیر کد / شاهد |
|---|---|---|---|---|---|
| X-01 | فروشنده سفارش فروشندهٔ دیگر را نمی‌بیند | هیچ ردیفی | همان؛ و دامنه **بازنویسی** می‌شود نه اعتبارسنجی | **Pass** | R-15 · `testEachVendorSeesOnlyTheirOwnItems` |
| X-02 | پرسنل فروشگاه در نقش خودش | فقط سفارش‌های همان فروشگاه و فقط در نقش خودش | همان | **Pass** | `testStaffSeeTheirShopsOrdersOnlyWithinTheirOwnRole` |
| X-03 | قلم فروشگاه دیگر از «وجود ندارد» قابل تشخیص نیست | — | همان | **Pass** | `testAnotherShopsLineIsIndistinguishableFromOneThatDoesNotExist` |
| X-04 | هیچ دادهٔ شخصی یا مالیِ غیرمجاز | — | ستونی برای مشتری وجود ندارد؛ ارقام مالیِ سراسری مجوز خودش را دارد | **Pass** | R-18 · R-17 |
| X-05 | تغییر وضعیت با nonce و capability | — | هر دو | **Pass** | `OrderLedgerPage::export()` · `OrderArea` |
| X-06 | API و AJAX | دسترسی اعمال می‌شود | `tecteb/v1` مجوز خودش را نمی‌سازد؛ فروشندهٔ دیگر **۴۰۳** می‌گیرد نه فهرست خالی | **Pass (`alpha.13`)** | `docs/evidence/operations/` |
| X-07 | CSV قابل سوءاستفاده نیست | nonce + capability + دامنهٔ فروشنده + پیشوند فرمول | همان | **Pass** | R-19 |
| X-08 | **حالت آزمایشی روی production روشن نمی‌مانَد** | مجوز نشانیِ سایتش را حمل می‌کند و با کپیِ دیتابیس سفر نمی‌کند | `host_changed` / `host_unrecorded` (از `alpha.98`) | **Pass** | `docs/ORDER-GATE-AUDIT-FA.md` |
| X-09 | `DOKAN_RUNNING` با دکانِ واقعی fail-closed | ماژول **ثبت نمی‌شود**، پس `OrderHooks` وصل نمی‌شود | همان؛ و مالک روی staging دیدش: `dokan_running` → `decisions_open` → `ready_on_trial` | **Pass (کد)** / **Not Run (دکان واقعی)** | `OrderGateRefusesWhileDokanRunsTest` · دکان در این محیط اجراناپذیر است |

---

## ۹. §۱۲ — سازگاری با سایت واقعی

| مورد | حکم |
|---|---|
| PHP 8.1.34 | **Not Run** — این محیط ۸٫۳٫۶ دارد و `lint.sh` با صدای بلند می‌گوید. جایگزین اجرا شد: PHPCompatibility با `testVersion 8.1-` روی هر ۶۴۰ فایل، ۰ خطا |
| ووکامرس واقعی | **Not Run** |
| HPOS | **Not Run** |
| دکان Lite و Pro | **Not Run** |
| Min Max | **Not Run** |
| قالب و page builder فعلی | **Not Run** |
| افزونه‌های کش | **Not Run** |
| پرداخت‌های ووکامرس | **Not Run** |
| صفحهٔ سبد و تسویه | **Not Run** |
| تصاویر و تنوع‌ها | **Not Run** |
| صفحهٔ فروشگاه و پنل فروشنده | **Not Run** |
| لاگ خطای PHP | **Not Run** |

---

## ۱۰. §۱۳ — تضمین‌های حفظ داده

| مورد | وضعیت |
|---|---|
| هر ۱۸۴ محصول حفظ شده | **هیچ اقدامی انجام نشد** — نه اتصال، نه نصب، نه تغییر دیتابیس |
| شناسه‌ها دست‌نخورده | همان |
| دسته‌ها از جمله چنددسته‌ای | همان |
| قیمت و موجودی | همان؛ و S-01 می‌گوید مسیر سفارش **صفر** نوشتنِ موجودی دارد |
| SKU، ویژگی‌ها، تنوع‌ها | همان |
| محصول منتشرشده بی‌دلیل `draft` نمی‌شود | **migration 39 هیچ backfill ندارد** و ستونش `NULL` می‌آید؛ هیچ مسیری در این دور وضعیت انتشار را نمی‌نویسد |
| مالکیت فروشگاه ۱۰۲ | دست‌نخورده |
| فروشگاه ۴۰ غیرفعال و **حذف‌نشده** | هیچ اقدامی |
| حساب ۲ **حذف‌نشده** | هیچ اقدامی |
| سوابق مالی دکان **پاک نمی‌شوند** | هیچ اقدامی |
| تصمیم مالیِ سوابق قدیمی بدهیِ ساختگی اضافه نمی‌کند | دست‌نخورده |
| **۱۸۴ منتقل‌شده در برابر ۱۶۴ منتشرشده** | دو عدد، دو معیار — و **هیچ وضعیت محصولی برای برابر شدنشان عوض نشد**. ۱۸۴ شمارشِ ردیف‌های دارای مهر انتقال است؛ ۱۶۴ شمارشِ `post_status = publish` در ووکامرس. تطبیقِ عددیِ این دو روی دادهٔ واقعی **`Not Run`** است و در دستورالعمل staging یک گامِ **فقط‌خواندنی** دارد |

---

## ۱۱. حلقهٔ falsification

هر اصلاح این دور با **برگرداندن نقص** سنجیده شد: ۳۳ جهش، هر کدام روی درختِ
آرام، با بازگرداندن **بایت‌به‌بایت** و مقایسهٔ هش. جزئیات و خروجی کامل:
`docs/evidence/alpha99-falsification/falsification.txt`.

⛔ **و خودِ حلقه یک حفره در آزمون‌های من پیدا کرد:** جهشی که **هر دو**
`add_action` ِ مسیر چرخهٔ عمر را برمی‌داشت، همهٔ پنج سوییت را **سبز** گذاشت —
چون هر آزمون این دور `SyncOrderLifecycle` را **مستقیم** صدا می‌زد. کلِ اصلاح P0
به آن دو خط آویزان است. `tests/WordPressContract/OrderLifecycleHooksTest.php`
نوشته شد که اکشن را **می‌دوانَد** و از یک spy می‌خواند چه چیزی به سرویس رسید؛
و یکی از هفت آزمونش می‌سنجد که **آینهٔ موجودی روی همان قلاب سر جایش مانده**.
قاعدهٔ `alpha.80`، در بدترین جای ممکن.

⛔ **و جهشی دیگر یک گاردِ ضعیف را لو داد:** آزمون «مرتب‌سازی از یک `match` با
`default` می‌آید» با جهشی که `match` را سر جایش می‌گذاشت و
`return $sort !== '' ? $sort :` جلویش می‌نوشت **سبز مانْد** — یعنی `ORDER BY`
مستقیم از query string می‌آمد و گارد خبر نداشت. آزمون حالا می‌سنجد که
**`match` خودِ بدنه باشد**، نه فقط در آن حاضر (`alpha.98`).
