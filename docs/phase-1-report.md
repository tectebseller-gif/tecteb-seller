# گزارش آزمون فاز ۱

تاریخ اجرا: ۷ سپتامبر ۲۰۲۶ · نسخه انتشار `0.1.0-alpha.1` (موقت — F-01) ·
نسخه schema دیتابیس `1` · محیط: کانتینر اجرای از راه دور Claude Code

> **وضعیت بسته: «تأییدنشده — نصب نشود».** گیت نصب Staging اجرا نشده است.
> هر سطر `Not Run` در این گزارش یعنی آزمون **اجرا نشده**، نه اینکه گذشته باشد.

> **اصلاح‌های بازبینی سورس** (۷ سپتامبر) در `docs/review-fixes-phase-1.md`
> آمده است؛ اعداد این گزارش پس از آن اصلاح‌ها به‌روزرسانی شده‌اند.

## ۰. خلاصه اجرا

| گیت | دستور | نسخه | exit | نتیجه | log |
|---|---|---|---|---|---|
| Unit خالص (بدون هیچ نماد WordPress) | `vendor/bin/phpunit --testsuite unit` | PHPUnit 10.5.64 / PHP 8.4.19 | 0 | **۱۲۴ تست، ۲۰٬۵۰۳ assertion** | `docs/evidence/unit.log` |
| معماری و قواعد ایستای امنیتی | `vendor/bin/phpunit --testsuite architecture` | همان | 0 | **۱۲ تست، ۶۰ assertion** | `docs/evidence/architecture.log` |
| قرارداد با stub وردپرس | `vendor/bin/phpunit --testsuite contract --bootstrap tests/bootstrap-contract.php` | همان | 0 | **۴۲ تست، ۴۵۲ assertion** | `docs/evidence/contract.log` |
| دیتابیس واقعی | `vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php` | MariaDB 10.11.14 | 0 | **۱۸ تست، ۱۸۱ assertion** | `docs/evidence/database.log` |
| lint نحوی | `bash tools/lint.sh` | PHP 8.4.19 | 0 | ۱۱۱ فایل، ۰ خطا | `docs/evidence/lint.log` |
| سازگاری ایستا با PHP 8.1 | `composer compat` | PHPCompatibility 10.0.0-alpha2 | 0 | ۱۱۱ فایل، ۰ خطا | `docs/evidence/phpcompat-8.1.log` |
| بسته‌بندی روی artefact واقعی | `vendor/bin/phpunit --testsuite packaging` | همان | 0 | **۱۲ تست، ۲٬۶۳۶ assertion** | `docs/evidence/packaging.log` |
| کنتراست WCAG | `php tools/contrast.php` | — | 0 | ۱۳ ترکیب، ۰ خطا | `docs/evidence/contrast.log` |
| مرورگر روی harness | `node tools/browser/check.mjs` | Playwright 1.63.0 / Chromium 141 / axe-core 4.13.0 | 0 | **۲۳۳ بررسی، ۰ خطا** | `docs/evidence/browser-checks.json` |
| **نصب/فعال‌سازی واقعی WordPress** | — | — | — | **Not Run** | — |
| **یکپارچگی WP/WC و HPOS on/off/sync** | — | — | — | **Not Run** | — |
| **اجرای واقعی روی PHP 8.1** | — | — | — | **Not Run** | — |
| **Screen reader دستی** | — | — | — | **Not Run** | — |

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
| غیرفعال‌سازی داده را نگه می‌دارد | `Lifecycle/Deactivator.php` | `ActivationFlowTest::testDeactivationLeavesTableOptionsAndCapabilitiesInPlace` | **Passed** |
| `uninstall.php` هیچ داده‌ای حذف نمی‌کند | `uninstall.php` | `PackagingTest::testUninstallFileDeletesNothing` | **Passed** |
| Multisite با پیام فارسی رد می‌شود، بدون تغییر سراسری | `Lifecycle/MultisiteGuard.php` | `LifecycleTest` (۳ تست) | **Passed** |
| **نصب و فعال‌سازی روی WordPress واقعی** | — | — | **Not Run** |

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
| ۳۲۰/۳۷۵/۷۶۸/۱۰۲۴/۱۴۴۰ و zoom 200% | همان | `tools/browser/check.mjs` — ۲۳۳ بررسی | **نمونه رابط** |
| **همان موارد روی wp-admin واقعی** | — | — | **Not Run** |
| **screen reader دستی** | — | — | **Not Run** |

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
| lint با PHP 8.1 | — | runtime موجود نیست | **Not Run** (جایگزین ایستا: PHPCompatibility `Passed`) |
| **نصب در WordPress یکبارمصرف، بدون fatal/warning با WP_DEBUG** | — | پروتکل G-01/G-02 در `docs/installation.md` | **Not Run** |

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

## ۳. آنچه اجرا نشد و چرا

| گیت | دلیل دقیق | راه اجرا |
|---|---|---|
| نصب/فعال‌سازی واقعی (G-01، G-02) | `downloads.wordpress.org` و mirror گیت‌هاب WordPress هر دو با HTTP 403 از proxy مسدودند | `docs/installation.md` بند ۴ |
| یکپارچگی WP/WC، HPOS on/sync-on/sync-off/legacy (G-07) | WooCommerce قابل دانلود نیست | همان |
| اجرای واقعی روی PHP 8.1.34 (G-09 و تکرار کل پروتکل) | `ppa.launchpadcontent.net` مسدود است؛ فقط PHP 8.4.19 نصب‌شدنی بود | همان |
| رابط روی wp-admin واقعی، و asset روی Posts با نشست معتبر (G-03، G-08) | نیازمند WordPress | همان |
| screen reader دستی | نیازمند اپراتور انسانی | همان |
| تداخل با LiteSpeed / Hello Elementor / Persian Woo / Rank Math / WP Rocket | هیچ‌کدام در محیط ساخت در دسترس نیستند | همان |

## ۴. تحویل

| فایل | SHA-256 |
|---|---|
| `dist/tecteb-marketplace-core.zip` | `e32333ae9cf6c493ddda8f3bb3051c2d17261068f321968e3441cb008f5a670c` |

> این هش پس از اصلاح‌های دور دوم بازبینی (بندهای ۸ تا ۱۱ در
> `docs/review-fixes-phase-1.md`) تولید شده و جایگزین هش قبلی
> `c16961df…01eaa` است.

**تکرارپذیری.** timestamp بسته دیگر از تاریخ آخرین commit گرفته نمی‌شود، بلکه
یک epoch ثابت (`1757203200`، قابل override با `SOURCE_DATE_EPOCH`) است.
پیش از این هش ZIP با هر commit عوض می‌شد، پس هشِ ثبت‌شده در همین گزارش از
commitی که آن را ثبت می‌کرد جان سالم به در نمی‌برد: بازبین با build دوباره
هش دیگری می‌گرفت و راهی برای تفکیک «اختلاف timestamp» از «اختلاف محتوا»
نداشت. اکنون هش فقط به محتوا، نام و mode فایل‌ها وابسته است.

`dist/SHA256SUMS` هر دو بسته را پوشش می‌دهد. هش بسته سورس با هر تغییر
مستندات عوض می‌شود (چون مستندات داخل همان بسته‌اند)؛ مرجع، همان فایل
`SHA256SUMS` است، نه رونوشتی در متن.
`dist/READ-ME-BEFORE-INSTALL.txt` وضعیت «تأییدنشده» را کنار خود بسته تکرار می‌کند.
