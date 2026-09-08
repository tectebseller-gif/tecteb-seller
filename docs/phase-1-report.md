# گزارش آزمون فاز ۱

تاریخ اجرا: ۷ سپتامبر ۲۰۲۶ · نسخه انتشار `0.1.0-alpha.1` (موقت — F-01) ·
نسخه schema دیتابیس `1` · محیط: کانتینر اجرای از راه دور Claude Code

> **وضعیت بسته:** گیت‌های پذیرش فاز ۱ (G-01 تا G-09 به‌علاوه ارتقا و بازیابی)
> روی یک **WordPress یکبارمصرف** با WooCommerce واقعی، دو بار — روی PHP 8.1.32
> و PHP 8.4.19 — اجرا و **قبول** شدند. بند ۳ محیط، خروجی هر گیت و شکستی که این
> اجرا پیدا کرد را می‌آورد. **نصب روی سایت تک‌طب انجام نشده و در مجوز فعلی
> نیست.** هر سطر `Not Run` یعنی آزمون **اجرا نشده**، نه اینکه گذشته باشد.

> **اصلاح‌های بازبینی سورس** (۷ سپتامبر) در `docs/review-fixes-phase-1.md`
> آمده است؛ اعداد این گزارش پس از آن اصلاح‌ها به‌روزرسانی شده‌اند.

## ۰. خلاصه اجرا

| گیت | دستور | نسخه | exit | نتیجه | log |
|---|---|---|---|---|---|
| Unit خالص (بدون هیچ نماد WordPress) | `vendor/bin/phpunit --testsuite unit` | PHPUnit 10.5.64 / PHP 8.4.19 | 0 | **۱۳۴ تست، ۲۰٬۵۵۲ assertion** | `docs/evidence/unit.log` |
| معماری و قواعد ایستای امنیتی | `vendor/bin/phpunit --testsuite architecture` | همان | 0 | **۱۲ تست، ۶۰ assertion** | `docs/evidence/architecture.log` |
| قرارداد با stub وردپرس | `vendor/bin/phpunit --testsuite contract --bootstrap tests/bootstrap-contract.php` | همان | 0 | **۴۷ تست، ۴۸۰ assertion** | `docs/evidence/contract.log` |
| دیتابیس واقعی | `vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php` | MariaDB 10.11.14 | 0 | **۲۲ تست، ۲۰۸ assertion** | `docs/evidence/database.log` |
| lint نحوی | `bash tools/lint.sh` | PHP 8.4.19 | 0 | ۱۱۳ فایل، ۰ خطا | `docs/evidence/lint.log` |
| سازگاری ایستا با PHP 8.1 | `composer compat` | PHPCompatibility 10.0.0-alpha2 | 0 | ۱۱۳ فایل، ۰ خطا | `docs/evidence/phpcompat-8.1.log` |
| بسته‌بندی روی artefact واقعی | `vendor/bin/phpunit --testsuite packaging` | همان | 0 | **۱۲ تست، ۳٬۴۰۰ assertion** | `docs/evidence/packaging.log` |
| کنتراست WCAG | `php tools/contrast.php` | — | 0 | ۱۳ ترکیب، ۰ خطا | `docs/evidence/contrast.log` |
| مرورگر روی harness | `node tools/browser/check.mjs` | Playwright 1.63.0 / Chromium 141 / axe-core 4.13.0 | 0 | **۲۳۳ بررسی، ۰ خطا** | `docs/evidence/browser-checks.json` |
| نصب/فعال‌سازی واقعی WordPress | `tools/acceptance-gates.sh` | WP 7.1 · MariaDB 10.11.14 | 0 | **گیت‌های G-01..G-09 قبول** | `docs/evidence/acceptance/` (بند ۳) |
| یکپارچگی WP/WC و HPOS on/sync-on/sync-off/legacy | همان | WooCommerce 11.0.1 | 0 | **هر سه حالت قبول** | `acceptance/*/G-07-*` |
| اجرای واقعی روی PHP 8.1 | همان | **PHP 8.1.32** (ساخته‌شده از سورس) | 0 | **کل پروتکل قبول** | `acceptance/php81/` |
| مرورگر روی **wp-admin واقعی** | `node tools/browser/check-wpadmin.mjs` | Chromium 141 / axe-core 4.13.0 | 0 | **۲×۱۸۸ بررسی، ۰ خطا** | `acceptance/wpadmin-a11y*/` |
| **اجرا روی PHP 8.1.34 دقیقِ سایت مالک** | — | — | — | **Not Run** | بند ۳٫۵ |
| **سرور وب واقعی، افزونه‌های سایت** | — | — | — | **Not Run** | بند ۳٫۵ |
| **Screen reader دستی** | — | — | — | **Not Run** | بند ۳٫۵ |

بازتولید همه موارد اجراشدنی: `bash tools/run-all-tests.sh`

## ۱. سه سطح شاهد — تفکیک‌شده، نه مخلوط

| سطح | چه چیزی را ثابت می‌کند | چه چیزی را ثابت نمی‌کند |
|---|---|---|
| **Unit خالص** | منطق Domain/Core بدون هیچ تابع WordPress کار می‌کند. `tests/Unit/UnitSuiteGuardTest.php` صریحاً تأیید می‌کند که هیچ نماد WordPress در فرایند تعریف نشده است | رفتار داخل WordPress |
| **قرارداد با stub** | افزونه چه hookهایی ثبت می‌کند، چه capabilityای بررسی می‌کند، چه HTMLای تولید می‌کند و چه SQLای می‌فرستد | **معادل یکپارچگی واقعی نیست.** stubها WordPress نیستند |
| **دیتابیس واقعی** | DDL، ایندکس، prepared statement، قفل و هم‌زمانی روی MariaDB واقعی | معادل نصب WordPress نیست |

تصاویر `docs/evidence/screenshots/` **«نمونه رابط (خارج WordPress)»** هستند؛ در
هیچ CORE-ID به‌عنوان `Passed` شمرده نمی‌شوند.

## ۲. CORE-ID → فایل → آزمون → شاهد

### CORE-01 — Lifecycle

| مورد | فایل | آزمون | نتیجه |
|---|---|---|---|
| metadata، ABSPATH guard، بررسی نسخه PHP پیش از require | `tecteb-marketplace-core.php` | `MainFileTest::testMainFileRegistersLifecycleHooksAndAutoloader` | **Passed** |
| main file روی PHP قدیمی هم parse می‌شود (تا notice دیده شود) | همان | `composer compat:mainfile` با `testVersion 7.2-` | **Passed** (`docs/evidence/phpcompat-selfcheck.log`) |
| نبود WooCommerce: بدون fatal، notice فارسی، عدم راه‌اندازی وابسته‌ها | `Bootstrap.php`، `Notices.php` | `BootstrapTest::testWithoutWooCommerceLimitedModeBootsWithoutFatalAndReportsIt` | **Passed** |
| فعال‌سازی ایدمپوتنت، مقدار کاربر بازنشانی نمی‌شود | `Lifecycle/Activator.php` | `ActivationFlowTest::testReactivationIsIdempotentAndKeepsUserValues` | **Passed** (MariaDB) |
| نسخه schema فقط پس از migration موفق ثبت می‌شود | `Migration/MigrationRunner.php` | `MigrationRunnerTest::testVersionIsWrittenOnlyAfterVerify`، `MigrationMariaDbTest::testFailingStepLeavesVersionUnchangedRecordsErrorAndReleasesLock` | **Passed** |
| قفل اتمی با بازیابی قفل منقضی | `Migration/MigrationLock.php` | `MigrationLockTest` (۱۰ تست)، `WpLockStoreTest` | **Passed** |
| **اجرای هم‌زمان دوباره‌نویسی نمی‌کند** | `WpLockStore.php` | `WpLockStoreTest::testExactlyOneOfManyConcurrentProcessesAcquiresTheLock` — ۸ فرایند forkشده، دقیقاً یکی موفق | **Passed** |
| migration مرحله‌ای و قابل resume | `MigrationRunner.php` | `MigrationMariaDbTest::testResumeAfterCrashBetweenDdlAndVersionWrite` | **Passed** |
| **نوشتن نسخه/ثبت خطا/حذف خطا اتمیک مشروط به مالکیت قفل** | `Contracts/GuardedOptionStoreInterface.php`، `WpLockStore::setGuarded/deleteGuarded` | `MigrationTakeoverTest::testTakeoverBetweenTheOwnershipCheckAndTheVersionWrite`، `MigrationMariaDbTest::testGuardedWriteOnlyHappensWhileTheGuardValueMatches` و `…GuardedDeleteOnlyHappens…` | **Passed** (MariaDB) |
| اجرای منسوخ‌شده پس از تصاحب هیچ چیز نمی‌نویسد و خطای اجرای جدید را پاک نمی‌کند | `MigrationRunner.php` | `MigrationTakeoverTest` (۱۰ تست)، `MigrationMariaDbTest::testRunRefusesToWriteTheVersionAfterAnotherOwnerTookTheLock` | **Passed** |
| شکست ثبت خطا استثنای کنترل‌نشده نمی‌سازد | همان | `MigrationTakeoverTest::testFailureToRecordTheErrorDoesNotThrow` | **Passed** |
| **نتیجه پاک‌سازی رکورد خطا چهار حالت متمایز دارد** | `Core/Migration/CleanupOutcome.php` | `MigrationCleanupTest` (۱۰ تست)، `MigrationMariaDbTest::testCleanupOutcomesAreDistinguishedOnRealDatabase` | **Passed** (MariaDB) |
| نبود رکورد خطا شکست نیست؛ شکست پاک‌سازی موفقیت کامل نیست | `MigrationResult::isFullySuccessful()` | `testNothingRecordedIsNotAFailure`، `testCleanupFailureIsReportedAndNeverReadsAsACleanSuccess` | **Passed** |
| رکورد خطای کهنه مسیر بازیابی دارد و صفحه سلامت را قفل نمی‌کند | `MigrationRunner::clearStaleRecord()`، `HealthReportBuilder` | `HealthSchemaStateTest` (۳ تست)، `MigrationMariaDbTest::testReactivationClearsAStaleErrorRecordOnRealDatabase` | **Passed** (MariaDB) |
| غیرفعال‌سازی داده را نگه می‌دارد | `Lifecycle/Deactivator.php` | `ActivationFlowTest::testDeactivationLeavesTableOptionsAndCapabilitiesInPlace` | **Passed** |
| `uninstall.php` هیچ داده‌ای حذف نمی‌کند | `uninstall.php` | `PackagingTest::testUninstallFileDeletesNothing` | **Passed** |
| Multisite با پیام فارسی رد می‌شود، بدون تغییر سراسری | `Lifecycle/MultisiteGuard.php` | `LifecycleTest` (۳ تست) | **Passed** |
| **نصب و فعال‌سازی روی WordPress واقعی** | — | گیت‌های G-01/G-02 روی WordPress 7.1 یکبارمصرف | **Passed** — بند ۳٫۲ |

### CORE-02 — Module Loader

| مورد | فایل | آزمون | نتیجه |
|---|---|---|---|
| manifest: id/version/dependencies/lifecycle | `Contracts/ModuleManifest.php` | `ModuleLoaderTest` | **Passed** |
| دو مرحله register سپس boot به ترتیب وابستگی | `Modules/ModuleLoader.php` | `testRegistersAllThenBootsAllInDependencyOrder` | **Passed** |
| چرخه، وابستگی گمشده، id تکراری، exception | همان | ۴ تست مجزا با کد علت متمایز | **Passed** |
| **خرابی یک ماژول وابسته‌هایش را متوقف می‌کند؛ مستقل‌ها ادامه می‌دهند** | همان | `testRegisterExceptionDegradesModuleAndBlocksDependents`، `testBootExceptionBlocksDependentsAtBootPhase`، `testBlockingIsTransitive` | **Passed** |
| علت توقف در وضعیت سلامت ثبت می‌شود | `ModuleLoadState.php`، `Presentation/Messages.php` | `PagesTest::testHealthPageDegradesWhenHealthModuleIsNotActive` | **Passed** |
| boot تکراری hook دوباره نمی‌سازد | `ModuleLoader.php` | `testSecondLoadNeverBootsAgain`، `BootstrapTest::testPluginsLoadedTwiceNeverDuplicatesHooks` | **Passed** |
| planned قابل فعال‌سازی نیست و کد ندارد | `ModuleRegistry.php` | `testPlannedManifestsAreNeverRunAndCannotCarryCode`، `PagesTest::testModulesPageShowsReasonsAndNoActivationButtonForPlanned` | **Passed** |

### CORE-03 — Authorization

| مورد | فایل | آزمون | نتیجه |
|---|---|---|---|
| فقط چهار capability مصرف‌شده، فقط روی Administrator | `Core/Lifecycle/Capabilities.php`، `CapabilityInstaller.php` | `SupportTest`، `ActivationFlowTest::testActivationInstallsCapabilitiesDefaultsSchemaAndAuditRow` | **Passed** |
| مهمان/subscriber/فروشنده بدون capability/مدیر | `Pages/AbstractPage.php` | `PagesTest::testEveryPageDiesWith403ForGuestSubscriberAndSellerWithoutCapability` (۴ هویت × ۴ صفحه) | **Passed** |
| REST: 401 مهمان، 403 بدون مجوز | `Rest/HealthController.php` | `HealthRestTest::testGuestGets401AndSubscriberGets403` | **Passed** |
| **nonce به‌تنهایی مجوز نیست** | `SettingsRegistrar.php` | `SettingsApiTest::testValidNonceWithoutCapabilityChangesNothing` | **Passed** |
| CSRF: nonce نامعتبر پیش از هر تغییر رد می‌شود | همان | `testInvalidNonceIsRejectedBeforeAnythingChanges` | **Passed** |

### CORE-04 — Safe runtime

| مورد | فایل | آزمون | نتیجه |
|---|---|---|---|
| ترتیب constant → option → platform | `Environment/EnvironmentResolver.php` | `EnvironmentResolverTest` (۸ تست) | **Passed** |
| مقدار ناشناخته → unknown، نه حدس امن | همان | `testUnknownPlatformValueStaysUnknown` | **Passed** |
| hostname فقط شاهد کمکی | همان | `testHostnameIsOnlyAHint` | **Passed** |
| production روی staging و برعکس | همان | `testProductionOptionOnStagingPlatformAndViceVersa` | **Passed** |
| **خروجی در همه محیط‌ها بسته است و باز نمی‌شود** | `Environment/OutboundPolicy.php` | `testOutboundIsBlockedForEveryEnvironmentAndAssertThrows`، `BootstrapTest::testEnvironmentOptionNeverUnlocksOutbound` | **Passed** |
| «کل سایت امن» ادعا نمی‌شود | `Presentation/Messages.php` | `PagesTest::testHealthPageSeparatesEnabledFromTestedAndHidesTraces` | **Passed** |

### CORE-05 — چهار صفحه فارسی

| مورد | فایل | آزمون | نتیجه |
|---|---|---|---|
| فقط چهار صفحه زیر «بازارگاه تک‌طب» | `Presentation/MenuRegistrar.php` | `AdminMenuAndAssetsTest::testMenuHasExactlyFourCapabilityGatedPages` | **Passed** |
| بدون آمار تجاری ساختگی | `Views/dashboard.php` | `PagesTest::testDashboardIsPersianRtlWithRealLinksAndNoFabricatedStats` | **Passed** |
| asset فقط روی صفحه‌های افزونه، بدون CDN | `Presentation/AssetLoader.php` | `testAssetsLoadOnlyOnPluginScreensAndNeverFromCdn`، `testAssetFilesExistAndContainNoRemoteUrls` | **Passed** |
| کنتراست رنگ‌های برند | `assets/admin/tmc-admin.css` | `tools/contrast.php` — ۱۳ ترکیب | **Passed** |
| RTL، bdi، label، error summary، focus، reduced motion | views + CSS | axe + بررسی‌های سفارشی روی harness | **نمونه رابط** |
| ۳۲۰/۳۷۵/۷۶۸/۱۰۲۴/۱۴۴۰ و zoom 200% روی harness | همان | `tools/browser/check.mjs` — ۲۳۳ بررسی | **نمونه رابط** |
| **همان موارد روی wp-admin واقعی** | همان + هسته وردپرس | `tools/browser/check-wpadmin.mjs` — ۱۸۸ بررسی، با و بدون WooCommerce | **Passed** — بند ۳٫۴ |
| **screen reader دستی** | — | — | **Not Run** |
| **Firefox / Safari** | — | — | **Not Run** — هر دو اجرا روی Chromium 141 |

### CORE-06 — Health REST

| مورد | فایل | آزمون | نتیجه |
|---|---|---|---|
| فقط `GET /wp-json/tmc/v1/health` | `Rest/HealthController.php` | `HealthRestTest::testRouteIsRegisteredAsReadOnlyWithPermissionCallback` — دقیقاً یک route | **Passed** |
| قرارداد پاسخ عیناً مطابق پرامپت | `Application/HealthReport.php` | `testAuthorizedResponseMatchesContractExactly` | **Passed** |
| `no-store`، بدون path/کاربر/stack trace | `HealthController.php` | همان | **Passed** |
| `null` یعنی نامشخص و به `false` تبدیل نمی‌شود | `WpDependencyProbe.php` | `testHposEnabledIsReportedAsGivenNeverCoerced`، `WpDependencyProbeTest` | **Passed** |
| HPOS فعال ≠ HPOS آزموده (دو فیلد جدا) | `HealthReportBuilder.php` | `PagesTest::testHealthPageSeparatesEnabledFromTestedAndHidesTraces` | **Passed** |

### CORE-07 — Configuration

| مورد | فایل | آزمون | نتیجه |
|---|---|---|---|
| **درصد ورودی جدا از مقدار ذخیره‌شده؛ ۱۲٫۳۴٪ → ۱۲۳۴** | `Sanitizer/PercentToBasisPoints.php` | `PercentToBasisPointsTest` — رفت‌وبرگشت برای هر ۱۰٬۰۰۱ مقدار | **Passed** |
| **خواندن و ذخیره دوباره تبدیل دوباره نمی‌سازد** | `SettingsService.php` | `testReadingAndResavingNeverReconverts` (unit و contract) | **Passed** |
| بدون float | همان | `testNoFloatArithmeticEverHappens`، float به‌عمد reject می‌شود | **Passed** |
| `null` و صفر متمایز | `Settings.php` | `testNullAndZeroAreDistinct`، `testEmptyAndZeroCommissionAreDistinctAfterSave` | **Passed** |
| ارقام فارسی/عربی نرمال می‌شوند | `DigitNormalizer.php` | `BoundedIntegerTest`، `PercentToBasisPointsTest` | **Passed** |
| مقدار نامعتبر reject و مقدار قبلی حفظ | `SettingsService.php` | `testInvalidFieldKeepsPreviousValueWithPersianMessageWhileOthersSave` | **Passed** |
| `settlement_delay_days` = ۴، دامنه ۰..۳۶۵ | `SettingsSchema.php` | `testSettlementDelayRangeIncludesZeroAndRejectsAboveProposedBound` | **Passed** |
| `max_staff` = ۱۰، دامنه ۱..۱۰۰ | همان | `testMaxStaffRange` | **Passed** |
| نرخ unset فقط پیام دارد، مانع نیست | `HealthReportBuilder.php` | `blocking => false` در check `commission_rate` | **Passed** |
| فعال‌سازی دوباره مقدار کاربر را برنمی‌گرداند | `SettingsService::ensureStored` | `testReactivationDoesNotResetUserValues` | **Passed** |
| audit فقط allowlist، بدون raw POST | `AuditEventSanitizer.php` | `AuditTest` | **Passed** |
| **ممیزی در لحظه تأیید ذخیره نوشته می‌شود، نه در `finalizeOutcome()`** | `SettingsRegistrar::recordPersisted()` | `SettingsApiTest::testAuditHappensOnPersistenceEvenWhenFinalizeOutcomeNeverRuns` — نوشتن مستقیم option، بدون nonce و بدون `options.php` | **Passed** |
| یک نوشتن موفق = دقیقاً یک ردیف ممیزی (حتی با دو بار sanitize وردپرس) | همان | `SettingsApiTest::testASingleSaveProducesExactlyOneAuditRow` | **Passed** |
| خطای ثبت audit گزارش می‌شود، نه موفقیت بی‌صدا | `SettingsRegistrar.php` | `testAuditFailureIsReportedNotSilent` | **Passed** |

### CORE-08 — Audit

| مورد | فایل | آزمون | نتیجه |
|---|---|---|---|
| جدول prefix-safe با ستون‌ها و ایندکس‌های مقرر | `Migrations/M0001CreateAuditTable.php` | `MigrationMariaDbTest::testFreshRunCreatesPrefixSafeTableWithIndexesAndWritesVersion` | **Passed** |
| `actor_id` nullable، `created_at` UTC | همان | `WpAuditRepositoryTest::testRowsAreInsertedWithNullActorUtcAndCleanPayload` | **Passed** |
| SQL آماده (prepared) | `WpAuditRepository.php` | همان تست با ورودی حاوی `'; DROP TABLE` | **Passed** |
| allowlist و حذف داده حساس | `AuditEventSanitizer.php` | `AuditTest::testOnlyAllowlistedKeysSurviveAndNestedKeysAreRestricted`، `testSensitiveLookingValuesAreRedactedAndBounded` | **Passed** |
| شکست insert گزارش می‌شود | `AuditLogger.php` | `testInsertFailureIsReturnedNotSwallowed`، `WpAuditRepositoryTest::testInsertFailureIsReturnedWithMessage` | **Passed** |
| داده هنگام deactivate می‌ماند | `Deactivator.php` | `ActivationFlowTest::testDeactivationLeavesTableOptionsAndCapabilitiesInPlace` | **Passed** |

### CORE-09 — OTP contract

| مورد | فایل | آزمون | نتیجه |
|---|---|---|---|
| interface مستقل با نتایج typed و متمایز | `Contracts/Otp/` | `OtpProvidersTest::testStatusesAreDistinct` | **Passed** |
| NullProvider همیشه unavailable، بدون شبکه/session/cookie | `Infrastructure/Otp/NullOtpProvider.php` | `testNullProviderIsUnavailableForEveryInput` | **Passed** |
| FakeProvider فقط در tests، با داده مصنوعی و clock تزریقی | `tests/Support/FakeOtpProvider.php` | `testFakeProviderOnlyAcceptsSyntheticSubjectsAndHonoursClock` | **Passed** |
| FakeProvider در ZIP نیست | — | `testFakeProviderIsNotPartOfTheShippedSource`، `PackagingTest` | **Passed** |
| هیچ کد ثابت login در UI | — | `PackagingTest::testNoLoginBypassOrHardcodedCodeInShippedSource` | **Passed** |

### CORE-10 — Packaging and compatibility

| مورد | فایل | آزمون | نتیجه |
|---|---|---|---|
| HPOS declaration با FeaturesUtil در `before_woocommerce_init` با guard | `HposDeclaration.php` | `BootstrapTest::testHposDeclarationIsGuardedAndUsesFeaturesUtilWhenPresent` | **Passed** |
| runtime بدون Composer/Node | `Core/Autoloader.php` (ADR-001) | `AutoloaderTest` T1..T4، `ArchitectureRulesTest` T5/T6، `PackagingTest` | **Passed** |
| ZIP فقط یک پوشه با main file داخلش | `tools/build.sh` | `PackagingTest::testZipHasExactlyOneTopLevelDirectoryWithTheMainFile` | **Passed** |
| `.git`/`.env`/DOCX/tests/dev vendor داخل ZIP نیست | همان | `PackagingTest::testZipContainsNoForbiddenPaths` | **Passed** |
| unzip integrity | همان | `PackagingTest::testZipIsIntactAndEveryShippedClassLoadsFromItAlone` | **Passed** |
| build تکرارپذیر | همان | `PackagingTest::testBuildIsReproducible` | **Passed** |
| source archive جدا با tests/docs/lockfile | همان | `PackagingTest::testSourceArchiveCarriesTestsDocsAndLockfile` | **Passed** |
| lint با PHP 8.1 | — | PHP 8.1.32 ساخته‌شده از سورس؛ `php -l` و کل پروتکل روی همان | **Passed** (به‌علاوه شاهد ایستای PHPCompatibility) |
| **نصب در WordPress یکبارمصرف، بدون fatal/warning با WP_DEBUG** | — | پروتکل G-01/G-02، دو اجرا | **Passed** — `*-plugin-errors.txt` همه صفر خط |

## ۲٫۱ قواعد ایستای امنیتی (روی کد ارسالی)

`tests/Architecture/SecurityRulesTest.php` — شش قاعده که هر رگرسیون را در همان
لحظه نوشتن می‌گیرند، به‌جای اتکا به بازبینی دستی:

| قاعده | چه چیزی را می‌بندد |
|---|---|
| بدون superglobal در کد ارسالی | ورودی فقط از مسیر sanitize کالبک Settings API می‌آید که وردپرس **پس از** بررسی nonce و capability صفحه صدا می‌زند |
| هر `echo` در view یا escape شده یا خروجی کامپوننتِ escape‌کننده است | تحلیل با حذف پرانتزهای متوازنِ توابع escape؛ باقی‌ماندن هر متغیر = خطا |
| هر فراخوانی دیتابیس prepared است | هیچ literal SQL چیزی جز نام جدولِ متعلق به `$wpdb` را درون‌ریزی نمی‌کند |
| هر صفحه و route با capability محافظت شده | چهار صفحه + گیت مشترک با ۴۰۳ خنثی + `permission_callback` |
| audit فقط از مسیر sanitizer | payload خام هرگز به رکورد نمی‌رسد |
| قفل خروجی با پیکربندی باز نمی‌شود | هیچ شاخه‌ای `true` برنمی‌گرداند |

## ۳. پذیرش عملی روی WordPress واقعی

پروتکل بند ۴ `docs/installation.md` روی یک **WordPress یکبارمصرف با داده
مصنوعی** اجرا شد. `tecteb.com` و `staging.tecteb.com` لمس نشدند.

> **قاعده هش در این سند:** هرجا هش کوتاه می‌آید، **۸ نویسه اول … ۶ نویسه آخر**
> است. فهرست کامل بسته‌ها در بند ۴٫۱ آمده تا هیچ هش کوتاهی مبهم یا قابل
> اشتباه‌گرفتن با دیگری نباشد.

### ۳٫۱ محیط اجرا

| مؤلفه | نسخه | چطور به دست آمد |
|---|---|---|
| WordPress | 7.1 | تگ `7.1` از `github.com/WordPress/WordPress` (CDN وردپرس مسدود است) |
| WooCommerce | 11.0.1 | بسته رسمی release، sha256 `88837ea0504544fdb835078d39c68c6c4abde00cdb4af8f67027255ee8ae494c` |
| PHP (اجرای اول) | 8.4.19 | بسته سیستم |
| PHP (اجرای دوم) | **8.1.32** | از سورس کامپایل شد (`github.com/php/php-src`، تگ `php-8.1.32`) چون PPA و `php.net` مسدودند |
| MariaDB | 10.11.14 | دیتابیس یکبارمصرف `tmc_wp_test` |
| WP-CLI | 2.12.0 | phar از `github.com/wp-cli/builds` |
| سرور وب | PHP built-in (`cli-server`) روی `127.0.0.1:8080` | — |

در اجرای ۸٫۱، **هر سه** لایه روی همان مفسر بودند: CLI، WP-CLI و SAPI وب
(`G-09-php.txt`: `cli_php=8.1.32`، `cli_wp_php=8.1.32`، `web_php=8.1.32`،
و Site Health هم `8.1.32`).

### ۳٫۲ نتیجه گیت‌ها — بسته فعلی `c37f8902…7f46f1`

| گیت | PHP 8.4.19 | PHP 8.1.32 | شاهد |
|---|---|---|---|
| G-01 نصب و فعال‌سازی | **Passed** | **Passed** | `G-01-*` — چهار صفحه ۲۰۰، `php_error=0`، schema=1، یک ردیف `plugin.activated`، چهار capability فقط روی مدیرکل، پیش‌فرض‌ها با `default_commission_rate_bp=null` |
| G-02 فعال‌سازی مجدد | **Passed** | **Passed** | `G-02-summary.txt` — تنظیمات غیرپیش‌فرض عیناً ماند، هیچ ردیفی گم نشد، +۲ ردیف، بدون DDL تازه |
| G-03 دسترسی UI سه هویت | **Passed** | **Passed** | `G-03-matrix.txt` — مهمان ۳۰۲، فاقد مجوز ۴۰۳ بدون هیچ نشتی، مدیرکل ۲۰۰ |
| G-04 ذخیره تنظیمات | **Passed** | **Passed** | `G-04-summary.txt` — سه ارسال غیرمجاز هیچ تغییری ندادند؛ ارسال مجاز یک ردیف با `{"changed":["max_staff"],"old":{"max_staff":33},"new":{"max_staff":77}}` |
| G-05 REST سلامت | **Passed** | **Passed** | `G-05-http.txt` — مهمان ۴۰۱، فاقد مجوز ۴۰۳، بدون nonce ۴۰۱، nonce نامعتبر ۴۰۳ (هسته)، مجاز ۲۰۰ با `no-store` و صفر نشتی |
| G-06 حفظ داده هنگام حذف | **Passed** | **Passed** | `G-06-summary.txt` — پس از `plugin delete`، جدول و همه ردیف‌ها، تنظیمات، schema و capabilityها سرجایشان |
| G-07 سه حالت HPOS | **Passed** | **Passed** | `G-07-*` — `1/1/1`، `1/0/1`، `0/0/1` از زبان خود WooCommerce و همان مقدار در REST؛ ردیف «HPOS آزموده شده» در هر سه حالت **نامشخص** |
| G-08 بارگذاری asset | **Passed** | **Passed** | `G-08-assets.txt` — صفر روی پنج صفحه wp-admin و صفحه اصلی، دو روی صفحه افزونه |
| G-09 نسخه PHP | **Passed** | **Passed** | `G-09-php.txt` |
| M-1 ارتقا بدون فعال‌سازی مجدد | — | **Passed** | `M-1-upgrade-without-activation.txt` — با schema=0 و جدول حذف‌شده، یک درخواست عادی wp-admin مهاجرت را اجرا کرد |
| M-2 cooldown و بازیابی retry | — | **Passed** | `M-2-retry-cooldown.txt` — خطای تازه ⇒ retry متوقف؛ خطای ۲۰ دقیقه‌ای ⇒ اجرا، موفقیت، و پاک شدن رکورد |
| M-3 رکورد کهنه و بازیابی | — | **Passed** | `M-3-stale-record-recovery.txt` — صفحه سلامت «ساختار داده کامل است» + یادداشت رکورد قدیمی، و فعال‌سازی دوباره آن را پاک کرد |

WooCommerce خودش افزونه را در فهرست سازگارها می‌آورد:
`wp wc hpos compatibility-info` → «1 compatible plugin found: Tecteb Marketplace Core»
(`docs/evidence/acceptance/php81/G-07-compatibility-info.txt`).

در هیچ گیتی، در هیچ‌کدام از دو اجرا، **حتی یک** سطر `Fatal`/`Warning`/`Notice`/
`Deprecated` مربوط به `tecteb-marketplace-core` در `debug.log` نبود
(`*-plugin-errors.txt` همه صفر خط).

### ۳٫۳ شکست بسته قبلی — تاریخچه، نه وضعیت فعلی

> این بند درباره بسته‌ای است که **دیگر تحویل نمی‌شود**. وضعیت بسته فعلی در
> بند ۳٫۲ است و آنجا همین گیت **Passed** است.

بسته‌ای که برای پذیرش ارائه شده بود (`617fcfc4…94f30a`) گیت **G-04 را رد کرد**:
ذخیره‌ای که فقط `max_staff` را عوض می‌کرد، ردیف ممیزی‌اش هر چهار فیلد را
«تغییرکرده» و مقادیر پیش‌فرض schema را «قبلی» ثبت می‌کرد. علت:
`register_setting()` مقادیر **جاری** را به‌عنوان default ثبت می‌کرد و وردپرس با
همان مقایسه تصمیم می‌گیرد که option اصلاً وجود دارد یا نه، پس هر ذخیره از مسیر
`add_option()` می‌رفت. جزئیات و بازتولید:
`docs/evidence/acceptance/failure-617fcfc/README.txt`. اصلاح در بند ۱۴
`docs/review-fixes-phase-1.md`؛ بسته اصلاح‌شده همان گیت را پاس می‌کند.

اجرای گیت‌ها روی هش نهایی تکرار شد: هر دو اجرای ۸٫۱ و ۸٫۴ در جدول بند ۳٫۲ با
بسته `c37f8902…7f46f1` انجام شده‌اند و `00-environment.txt` هر پوشه همان هش را
ثبت کرده است. شواهد شکست قدیمی **حذف نشده‌اند**: در
`docs/evidence/acceptance/failure-617fcfc/` می‌مانند تا تاریخچه قابل بازبینی
بماند.

### ۳٫۴ دسترس‌پذیری و چیدمان روی wp-admin واقعی

`tools/browser/check-wpadmin.mjs` با نشست واقعی مدیرکل روی همان سایت اجرا شد،
و **زبان مدیریت وردپرس روی `fa_IR` با جهت راست‌به‌چپ**. این با
`browser-checks.json` فرق دارد: آن یکی harness را **بیرون** از وردپرس می‌سنجد
و «نمونه رابط» است.

**زبان و جهت از خود سند خوانده می‌شود، نه از locale مرورگر.** بررسی
`wp-admin-is-fa-IR-rtl` در هر ۲۸ بارگذاری صفحه این‌ها را الزام می‌کند:
`html lang="fa-IR"`، `dir="rtl"`، کلاس `rtl` روی body، جهت محاسبه‌شده `rtl`،
و بارگذاری شیوه‌نامه‌های `*-rtl.css` مدیریت. بسته زبان چون
`translate.wordpress.org` و `downloads.wordpress.org` مسدودند، با
`tools/wp-lang/make-fa-ir-mo.py` به‌صورت **حداقلی** ساخته شد: ورودی جهت متن
به‌علاوه حدود بیست رشته پوسته مدیریت. این ترجمه رسمی فارسی **نیست** و شواهد
همین را می‌گویند؛ آنچه تضمین می‌کند همان شرط تحت آزمون است.

| بررسی | WooCommerce غیرفعال | WooCommerce فعال |
|---|---|---|
| ۳۲۰ / ۳۷۵ / ۷۶۸ / ۱۰۲۴ / ۱۴۴۰ — اسکرول افقی سند | ۲۰/۲۰ | ۲۰/۲۰ |
| **شبیه‌سازی فضای چیدمان** `layout-space-640x512` | ۴/۴ | ۴/۴ |
| **زوم واقعی مرورگر** `zoom200-browser` | ۴/۴ | ۴/۴ |
| اثبات مقیاس واقعی (`browser-really-scaled`) | ۴/۴ | ۴/۴ |
| بیرون‌نزدن عنصر افزونه، هدف لمسی ≥ ۴۴×۴۴ | ۵۶/۵۶ | ۵۶/۵۶ |
| خطای JavaScript، بارگذاری asset افزونه | ۵۶/۵۶ | ۵۶/۵۶ |
| axe WCAG 2.2 AA روی `.tmc-admin` | ۲۹/۲۹ | ۲۹/۲۹ |
| axe روی **کل سند** (ثبت‌شده) | ۰ یافته | ۰ یافته |
| `wp-admin-is-fa-IR-rtl` | ۲۸/۲۸ | ۲۸/۲۸ |
| کیبورد (۵ بررسی در هر صفحه) | ۲۰/۲۰ | ۲۰/۲۰ |
| خلاصه خطا (وجود، focus، لینک‌ها) | ۳/۳ | ۳/۳ |
| **جمع** | **۲۵۲ بررسی، ۰ شکست** | **۲۵۲ بررسی، ۰ شکست** |

**زوم — دو چیز متفاوت با دو نام متفاوت.**
`layout-space-640x512` شبیه‌سازی فضای چیدمان با emulation سطح CDP است و زوم
نیست. `zoom200-browser` یک Chromium جداست با
`--force-device-scale-factor=2` و پنجره ۶۴۰×۵۱۲ DIP — پنجره فیزیکی واقعی
۱۲۸۰×۱۰۲۴ — و context با `viewport: null`، پس هیچ
`Emulation.setDeviceMetricsOverride` فرستاده نمی‌شود؛ مقیاس‌دهی کار خود مرورگر
است. شاهد ثبت‌شده: `devicePixelRatio=2 innerWidth=640 outerWidth=640
screen=400x300 narrowMediaQuery=true rootZoom=1`. زوم `Ctrl+` خود مرورگر
(HostZoomMap) از Playwright/CDP قابل تنظیم نیست و **`Not Run`** است.

**کیبورد با Tab واقعی.** هیچ `focus()` و هیچ تغییر `tabindex` در کار نیست؛
پیمایش از بالای سند شروع می‌شود. پس از **۵۸ توقف پوسته وردپرس**، اولین توقف
داخل `.tmc-admin` لینک پرش است. فهرست کنترل‌های focusable از خود صفحه استخراج
می‌شود و Tab به همه آن‌ها می‌رسد (۱۰/۱۰، ۶/۶، ۱۰/۱۰، ۶/۶). لینک پرش علاوه بر
`location.hash === '#tmc-main'`، فوکوس را هم واقعاً منتقل می‌کند:
`activeElement=<main id="tmc-main">`.

`foreign_findings: []` یعنی axe حتی در markup خود وردپرس هم چیزی پیدا نکرد.
تنها میزبان خارجی ردشده `secure.gravatar.com` بود — آواتار نوار مدیریت هسته.

**تصاویر:** `docs/evidence/acceptance/wpadmin-a11y/screenshots/` — هر صفحه در
۳۷۵ و ۱۴۴۰، در شبیه‌سازی فضای چیدمان، و در زوم واقعی مرورگر؛ به‌علاوه صفحه
تنظیمات در حالت خطا. نوار مدیریت وردپرس در آن‌ها **سمت راست** است، چون خود
وردپرس RTL شده است.

> اجرای نخست این بررسی‌ها سه نقص روشی داشت — نام‌گذاری زوم، اثبات کیبورد با
> `focus()`، و wp-admin انگلیسی/LTR. هر سه اصلاح و اجرا از صفر تکرار شد؛
> JSON و log آن اجرا در `acceptance/superseded-en_US-ltr/` برای تاریخچه مانده
> است. کد افزونه در این اصلاح **تغییر نکرد** و هش بسته همان است.

### ۳٫۵ آنچه هنوز اجرا نشده

| گیت | دلیل دقیق |
|---|---|
| اجرا روی PHP **8.1.34** (نسخه دقیق سایت مالک) | فقط ۸٫۱٫۳۲ از سورس ساخته شد؛ PPA و `php.net` از proxy مسدودند |
| نصب روی Staging/production تک‌طب | خارج از مجوز؛ عمداً انجام نشد |
| screen reader دستی | نیازمند اپراتور انسانی؛ ابزار خودکار جایش را نمی‌گیرد |
| زوم `Ctrl+` خود مرورگر (HostZoomMap) | از Playwright/CDP قابل تنظیم نیست؛ زوم واقعی سطح مرورگر با device scale factor اجرا شد |
| ترجمه رسمی فارسی وردپرس | `translate.wordpress.org` و `downloads.wordpress.org` مسدودند؛ بسته حداقلی محلی ساخته شد |
| مرورگرهای غیر Chromium (Firefox، Safari) | هر دو اجرای دسترس‌پذیری روی Chromium 141 بودند |
| تداخل با LiteSpeed / Hello Elementor / Persian Woo / Rank Math / WP Rocket | هیچ‌کدام در محیط در دسترس نیستند |
| سرور وب واقعی (Apache/LiteSpeed + PHP-FPM) | اجرا با SAPI `cli-server` بود؛ رفتار rewrite و هدرهای سرور واقعی آزموده نشده |

## ۴. تحویل

| فایل | SHA-256 |
|---|---|
| `dist/tecteb-marketplace-core.zip` | `c37f8902ef3152bfc897114f7044f546d521783528e2c9f38c1d3442227f46f1` |

### ۴٫۱ تبار بسته‌ها — کدام هش، کِی، و چرا

هر سطر یک بسته واقعی است که ساخته و تحویل شده. تنها سطر آخر بسته فعلی است.

| # | SHA-256 بسته | چه زمانی | وضعیت |
|---|---|---|---|
| ۱ | `c16961df…601eaa` | پس از بازبینی دور اول | جایگزین شد |
| ۲ | `e32333ae…5a670c` | پس از بازبینی دور دوم (نوشتن‌های guarded) | جایگزین شد |
| ۳ | `617fcfc4…94f30a` | پس از بازبینی دور سوم (نتیجه پاک‌سازی) | **گیت G-04 را رد کرد** — بند ۳٫۳ |
| ۴ | `6116950d…25eaed` | اصلاح بند ۱۴ | میان‌مرحله‌ای؛ تحویل نشد |
| ۵ | **`c37f8902…7f46f1`** | + متن وضعیت در سربرگ افزونه | **بسته فعلی**؛ همه گیت‌ها روی همین اجرا شدند |

هش کامل بسته فعلی:
`c37f8902ef3152bfc897114f7044f546d521783528e2c9f38c1d3442227f46f1`

**تکرارپذیری.** timestamp بسته دیگر از تاریخ آخرین commit گرفته نمی‌شود، بلکه
یک epoch ثابت (`1757203200`، قابل override با `SOURCE_DATE_EPOCH`) است.
پیش از این هش ZIP با هر commit عوض می‌شد، پس هشِ ثبت‌شده در همین گزارش از
commitی که آن را ثبت می‌کرد جان سالم به در نمی‌برد: بازبین با build دوباره
هش دیگری می‌گرفت و راهی برای تفکیک «اختلاف timestamp» از «اختلاف محتوا»
نداشت. اکنون هش فقط به محتوا، نام و mode فایل‌ها وابسته است.

`dist/SHA256SUMS` هر دو بسته را پوشش می‌دهد. هش بسته سورس با هر تغییر
مستندات عوض می‌شود (چون مستندات داخل همان بسته‌اند)؛ مرجع، همان فایل
`SHA256SUMS` است، نه رونوشتی در متن.
`dist/READ-ME-BEFORE-INSTALL.txt` همین وضعیت و فهرست `Not Run`های باقی‌مانده را کنار خود بسته تکرار می‌کند.
