# ماتریس P0/P1 — تک‌طب، نامزد انتشار

مبنا: شاخهٔ `claude/new-session-z1vafy` روی ساختار دادهٔ ۳۹.
این سند **از نام آزمون‌های واقعی** ساخته شده، نه از حافظه: هر ردیفِ `Pass`
نام کلاس و متدی را می‌برد که می‌توان همین حالا دواندش، و هر ردیفِ `Not Run`
می‌گوید **چرا** و **روی staging با چه گامی** سنجیده می‌شود.

⛔ **سه معنا، و با هم اشتباه نشوند:**

| نشان | یعنی |
|---|---|
| `Pass` | آزمونی در این مخزن آن را روی MariaDB واقعی یا روی بدل‌های وردپرس **می‌سنجد** و سبز است. |
| `Not Run` | **اجرا نشده.** ووکامرس واقعی، PHP 8.1.34، قالب، درگاه، دکان یا مرورگرِ داخل wp-admin لازم دارد و هیچ‌کدام در این محیط نیست. **«قبول» نیست.** |
| `Fail` | سنجیده شد و رد شد. امروز این ستون خالی است. |

⛔ **و «آزمون سبز» با «روی سایت شما کار می‌کند» یکی نیست.** بدل‌ها قرارداد
ووکامرس را بازی می‌کنند و قرارداد را از سورس خودش خوانده‌ایم؛ ولی تنها چیزی
که دربارهٔ نصب شما حرف می‌زند، گام‌های چک‌لیست staging است.

---

## ۱. سفارش و پرداخت

| سناریو | نتیجه | شاهد یا دلیل |
|---|---|---|
| تسویه کلاسیک (`WC_Checkout::process_checkout`) | **Not Run** | ووکامرس واقعی. گاردِ سبد روی `woocommerce_checkout_order_processed` ثبت است (`CommissionGuardRefusesRatherThanWaivesTest`) ولی مسیر کامل خرید اجرا نشده. staging: گام ۵. |
| تسویه بلوکی / Store API | **Not Run** | همان. قلاب `woocommerce_store_api_checkout_order_processed` از سورس خوانده و ثبت شده؛ اجرا نشده. staging: گام ۵. |
| خریدار مهمان و خریدار ثبت‌نام‌شده | **Not Run** | ووکامرس واقعی. سمت ما هیچ کدی به هویت خریدار نگاه نمی‌کند — جدول `tmc_order_items` **ستونی برای مشتری ندارد** (اندازه‌گیری‌شده از `information_schema`). staging: گام ۵. |
| محصول ساده | **Pass** | `OrderFlowTest::testTheOwnersWorkedExampleSurvivesTheRoundTripToTheRowAndTheLedger` |
| محصول متغیر (تنوع) | **Pass** | `TectebCommissionPanelTest` (قاعدهٔ تنوع و والد، `FROM_VARIATION`/`FROM_PRODUCT`) · `OrderLedgerReportTest` (فیلتر تنوع) |
| سبد تک‌فروشنده | **Pass** | `OrderFlowTest::testTheOwnersWorkedExampleSurvivesTheRoundTripToTheRowAndTheLedger` |
| سبد چندفروشنده | **Pass** | `OrderFlowTest::testAMultiVendorBasketBecomesOneLinePerVendorWithItsOwnShare` |
| محصول غیرِبازارگاهی در همان سبد | **Pass** | `OrderFlowTest::testAnOrderWithNoMarketplaceLinesIsSilent` · `testTheRefusalSentenceIsOursForOurProductsAndNobodyElsesEver` |
| پرداخت آزمایشی آفلاین | **Pass** | `OrderFlowTest::testWithDokanRunningTheModuleDoesNotOperateAndATrialCannotChangeThat` |
| پرداخت موفق | **Pass** | `LedgerTest::testOnePaidItemBecomesFourBalancedLines` |
| callback تکراری درگاه | **Pass** | `OrderFlowTest::testTwoCallbacksForOneOrderLeaveOneDocumentAndOneCompleteLine` · `LedgerTest::testTheSameEventTwiceRecordsOnce` |
| پرداخت ناموفق | **Pass** | `OrderFlowTest::testAnUnpaidOrFailedOrderKeepsItsRecordAndIsSettleableByNobody` |
| پرداخت دیرهنگام | **Pass** | همان ردیف: `failed` عمداً **باطل نمی‌کند** (وضعیتی که عقب و جلو می‌رود نباید پول را عقب و جلو ببرد). |
| لغو **پیش از** پرداخت | **Pass** | `OrderFlowTest::testCancellingAnOrderInWooCommerceVoidsTheShareAndTheLine` — **بازتولید اندازه‌گیری خودِ مالک روی ۱۲۳۰۰** |
| لغو **پس از** پرداخت/ارسال | **Pass** | `OrderFlowTest::testACancelAfterTheGoodsShippedIsReportedAndWritesNothing` — ابزارش مرجوعی است، نه لغو |
| بازگشایی سفارشِ لغوشده | **Pass** | `OrderFlowTest::testAnOrderThatLeavesCancelledIsReportedAndNotReinstated` |
| تکمیل سفارش | **Pass** | `SettlementFlowTest::testAShareIsNotWithdrawableUntilAManagerCallsTheSaleComplete` (ORDER-01: تکمیل را **مدیر** ثبت می‌کند) |
| درگاه پرداخت واقعی | **Not Run** | هیچ درگاهی نصب نیست و `canTransferMoney()` در هر build `false` است. staging: گام ۵ و ۸. |

## ۲. مالی

| سناریو | نتیجه | شاهد یا دلیل |
|---|---|---|
| کمیسیون صفر | **Pass** | `OrderFlowTest::testAProductWithNoCommissionIsCapturedWithTheWholeLineAsTheVendorsShare` |
| کمیسیون درصدی | **Pass** | `OrderFlowTest::testAPercentageIsTakenOnWooCommercesOwnAfterDiscountTotal` |
| کمیسیون ثابت (به ازای واحد) | **Pass** | `OrderFlowTest::testAFixedCommissionIsCapturedPerUnitAndSurvivesALaterRuleChange` |
| کمیسیون روی والد و روی تنوع | **Pass** | `TectebCommissionPanelTest` — تنوعِ بی‌قاعده از والد می‌خوانَد و snapshot می‌گوید از کجا |
| تغییر نرخ **پس از** سفارش | **Pass** | `OrderFlowTest::testAFixedCommissionIsCapturedPerUnitAndSurvivesALaterRuleChange` · `testARecordedEventIsRecoverableAfterItsRateIsRemoved` |
| نرخ عمومی به‌جای کمیسیون محصول | **Pass (رد می‌شود)** | `OrderFlowTest::testAGeneralRateDoesNotStandInForAProductWithNoCommission` |
| تخفیف و کوپن | **Pass** | `OrderFlowTest::testAPercentageIsTakenOnWooCommercesOwnAfterDiscountTotal` — مبنا همان عددی است که ووکامرس **پس از** تخفیف می‌دهد و دو بار کم نمی‌شود |
| مالیات بیرون از مبنای کمیسیون | **Pass** | `LedgerTest::testOnePaidItemBecomesFourBalancedLines` (۹۰۰٬۰۰۰ + ۸۱٬۰۰۰ مالیات ⇒ کمیسیون ۱۰٪ روی ۹۰۰٬۰۰۰) · `CommissionBaseExcludesTaxAndShippingTest` |
| حمل بیرون از مبنای کمیسیون | **Pass (با نبودِ قابلیت)** | `CommissionBaseExcludesTaxAndShippingTest` — `CaptureOrder` **یازده کلید** از هر قلم می‌خوانَد و هیچ‌کدام حمل نیست؛ مجموعه به‌صورت **set** قید شده تا کلید تازه شکست بدهد |
| گردکردن و دقت | **Pass** | `NoRoundingInCommissionTest` · `OrderFlowTest::testSeveralFractionalSharesAddUpExactlyInBothTotals` — مثال مالک: ۱۰۱ تومان، ۱۲٫۵٪ ⇒ ۱۲٫۶۲۵ و ۸۸٫۳۷۵ |
| تراز دفترکل | **Pass** | `LedgerTest::testOnePaidItemBecomesFourBalancedLines` (جمع چهار خط صفر است) |
| فقط‌افزودنی بودن دفترکل | **Pass** | `LedgerTest::testLedgerRowsAreOnlyEverAdded` |
| مرجوعی کامل | **Pass** | `ShipmentAndReturnFlowTest::testTheWholeLineCanAlwaysBeGivenBackExactly` |
| مرجوعی جزئی | **Pass** | `ShipmentAndReturnFlowTest::testTheOwnersInexactExampleIsAllocatedAndClosesExactly` · `testPartialRefundsAddUpToTheLineAndNoFurther` |
| سند معکوس | **Pass** | `OrderFlowTest::testTheAccrualStaysInTheLedgerAndAReversingDocumentIsAddedBesideIt` |
| سند معکوسِ دوباره (یک‌بارمصرفی) | **Pass** | `OrderFlowTest::testARepeatedCancelHookWritesNothingTwice` · `ShipmentAndReturnFlowTest::testTheSameReturnCannotBeRefundedTwice` · `testTwoRefundsRacingOnTheSameReturnProduceExactlyOneReversal` |
| مرجوعی روی قلم لغوشده | **Pass (رد می‌شود)** | `ShipmentAndReturnFlowTest::testAReturnCannotBeOpenedOnACancelledLine` |
| جلوگیری از تسویهٔ مبلغ پرداخت‌نشده | **Pass** | `OrderFlowTest::testAnUnpaidOrFailedOrderKeepsItsRecordAndIsSettleableByNobody` · `SettlementFlowTest::testAShareIsNotWithdrawableUntilAManagerCallsTheSaleComplete` |
| جلوگیری از دوباره‌شماری (دکان) | **Pass** | `OrderGateRefusesWhileDokanRunsTest` · `OrderFlowTest::testWithDokanRunningTheModuleDoesNotOperateAndATrialCannotChangeThat` |
| چند ارز | **Pass (رد می‌شود)** | `LedgerTest::testTwoCurrenciesInOneAccountAreRefusedRatherThanAveraged` · `OrderLedgerSummaryTest::testTwoCurrenciesAreReportedAndNeverAdded` |
| اختلاف مقیاس یک ارز | **Pass** | `LedgerTest::testTwoExponentsInOneAccountAddUpExactly` · `OrderLedgerSummaryTest::testTwoScalesOfOneCurrencyAreReportedAndNeverAdded` |
| سرریز جمع | **Pass (رد می‌شود)** | `LedgerTest::testASumWiderThanAPhpIntegerIsRefusedRatherThanSaturated` · `testTheMostNegativeAmountIsRefusedByNameRatherThanThrowing` |
| سهم ثبت‌نشده ≠ صفر (FIN-02) | **Pass** | `OrderFlowTest::testWithoutAResolvableRateTheLineIsRecordedAsUNRECORDEDRatherThanAsZero` · `SettlementFlowTest::testAnUnrecordedShareIsNeverCountedAsZero` · `OrderLedgerSummaryTest::testLinesWithNoShareAreCountedRatherThanSummedAsNought` |

## ۳. عملیاتی

| سناریو | نتیجه | شاهد یا دلیل |
|---|---|---|
| کاهش موجودی هنگام فروش | **Not Run** | کارِ خودِ ووکامرس است. آینهٔ موجودی روی همان قلاب سر جایش مانده: `OrderLifecycleHooksTest::testTheStockMirrorIsStillRegisteredOnTheSameHook`. staging: گام ۵. |
| بازگشت موجودی با مرجوعی | **Pass** | `ShipmentAndReturnFlowTest::testAReceivedReturnPutsTheGoodsBackOnTheShelf` |
| عدم افزایش دوبارهٔ موجودی | **Pass** | `ShipmentAndReturnFlowTest::testARefundSaysWhichOfItsFourPartsActuallyHappened` (گاردِ `alpha.12`) |
| ارسال جزئی و رهگیری | **Pass** | `ShipmentAndReturnFlowTest::testALineOfThreeShipsInTwoParcelsWithItsOwnTrackingEach` · `testAVendorCannotShipMoreThanIsLeft` |
| ارسال‌شده و بعد لغو | **Pass** | `OrderFlowTest::testACancelAfterTheGoodsShippedIsReportedAndWritesNothing` |
| هوک تکراری ووکامرس | **Pass** | `OrderFlowTest::testARepeatedCancelHookWritesNothingTwice` · `OrderLifecycleHooksTest` (اکشن **دوانده** می‌شود، نه اینکه ثبتش assert شود) |
| خطای دیتابیس وسط عملیات | **Pass** | `WithdrawalIntegrityTest` (۲۶ آزمون، با شکست تزریقی) · `OrderFlowTest::testALedgerWriteThatSucceedsSurvivesAFailedLineWriteAndItsRetry` · `SaveAndRestoreFailureTest` |
| رقابت دو پروسه روی یک ردیف | **Pass** | `WithdrawalIntegrityTest::testASecondConnectionTakingTheRequestFirstGetsTheOnlyOne` · `SupplierPaymentCoordinationTest` |
| دسترسی فروشنده فقط به سفارش خودش | **Pass** | `OrderFlowTest::testEachVendorSeesOnlyTheirOwnItems` · `testStaffSeeTheirShopsOrdersOnlyWithinTheirOwnRole` · `SettlementFlowTest::testOneShopNeverSeesAnotherShopsMoney` |
| دامنهٔ مالیِ گزارش | **Pass** | `OrderLedgerSummaryTest::testTheSummaryNeedsTheFinanceCapability` · `testAVendorsSummaryIsRewrittenToTheirOwnShop` (دامنه **بازنویسی** می‌شود، نه اعتبارسنجی) |
| HPOS — پیوند ویرایش سفارش | **Pass (قرارداد)** | `OrderAdminUrlTest` (۸ آزمون): از `OrderUtil::get_order_admin_edit_url()` پرسیده می‌شود و `post.php` هیچ‌جا ساخته نمی‌شود |
| HPOS روی نصب واقعی | **Not Run** | ووکامرس واقعی. staging: گام ۴. |
| تطبیق سفارش و سند (هفت کلاس) | **Pass** | `OrderReconciliationTest` (۲۵ آزمون، هر هفت کلاس روی MariaDB واقعی) |
| «نتوانستم بررسی کنم» ≠ «سالم است» | **Pass** | `OrderReconciliationTest::testAWindowThatCouldNotBeReadIsNotACleanBill` · `testOneUnreadableOrderMakesTheWholeScanUnchecked` |
| پنل فروشندهٔ ۱۰۲ بدون دکان | **Not Run** | دکان در این محیط اجراناپذیر است (`abzarwp/` با SourceGuardian رمز است). staging: گام ۷. |
| لینک‌های قدیمی فروشگاه/فروشنده | **Not Run** | `DokanUrlRedirects` ثبت است و پس از رفتن دکان **بیشتر** لازم است؛ روی نصب واقعی سنجیده نشده. staging: گام ۷. |
| کش و تازگی گزارش | **Pass** | `ReportInvalidationTest` — ابطال از **نوشتن** آویزان است، نه از فراخوان |
| چیدمان گزارش روی ۱۴۴۰/۱۲۸۰/۷۶۸/۳۹۰ | **Pass (مرورگر واقعی)** | `tools/browser/alpha100-reports.mjs` — ۶۳ بررسی، ۰ شکست، Chromium 153. شواهد: `docs/evidence/alpha100-reports/` |
| چیدمان **داخل** wp-admin واقعی | **Not Run** | وردپرسی در این محیط نصب نیست؛ آنچه رندر شد سند مستقل با ستون منوی ۱۶۰ پیکسلی است. staging: گام ۴. |
| PHP 8.1.34 | **Not Run** | این محیط فقط ۸٫۳٫۶ دارد. جایگزینِ **ناقص**: PHPCompatibility با `testVersion 8.1-`. staging: گام ۱. |

---

## ۴. آنچه این ماتریس **نمی‌گوید**

- هیچ ردیفی دربارهٔ **سایت شما** حرف نمی‌زند. هر `Pass` دربارهٔ کدی است که
  امروز در این مخزن است.
- `Not Run` ها **تصادفی انتخاب نشده‌اند**: همه به چیزی نیاز دارند که این محیط
  ندارد — ووکامرس، دکان، درگاه، قالب، PHP 8.1.34، یا وردپرسی که بشود در آن
  وارد شد.
- دو دروازهٔ مالی همچنان بسته‌اند (`DEC-02`، `DEC-04`) و این ماتریس بازشان
  نمی‌کند: `docs/OWNER-DECISIONS-ALPHA100-FA.md`.
