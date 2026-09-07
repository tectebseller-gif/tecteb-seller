# اصلاح‌های بازبینی سورس فاز ۱

تاریخ: ۷ سپتامبر ۲۰۲۶ · مبنای قبل از اصلاح: commit `4f4faa6` ·
دامنه: **فقط فاز ۱**. فاز ۲ شروع نشده و هیچ نصبی روی هیچ سایتی انجام نشده است.

> هر مورد یک **اصلاح کد** است. برای هرکدام تست رگرسیونی نوشته شده که روی کد
> قبلی می‌شکند و روی کد اصلاح‌شده قبول می‌شود؛ ستون «اثبات» می‌گوید چطور.

## خلاصه

| # | ایراد | اصلاح | تست رگرسیون | اثبات روی کد قبلی |
|---|---|---|---|---|
| ۱ | sanitize هم‌زمان موفقیت اعلام می‌کرد و audit می‌نوشت، پیش از آنکه ذخیره واقعاً انجام شود | تفکیک اعتبارسنجی از ذخیره و از گزارش نتیجه | `SettingsApiTest` ×۴ | ✅ شکست رفتاری |
| ۲ | تشخیص خروجی داخلی sanitizer از روی ساختار (`schema_version`+`values`) قابل جعل بود | token یک‌بارمصرف بسته به مقدار دقیق | `testForgedCanonicalPayloadCannotBypassValidationOrAudit` | ✅ شکست رفتاری |
| ۳ | انقضای قفل وسط migration و تصاحب توسط اجرای دوم پوشش نداشت؛ نسخه پس از گرفتن قفل دوباره خوانده نمی‌شد | خواندن دوباره نسخه پس از acquire + اثبات مالکیت پیش از هر مرحله و هر نوشتن | `MigrationTakeoverTest` ×۴ و تست MariaDB | ✅ شکست رفتاری + شاهد قبل/بعد |
| ۴ | استثنای `verify()` و استثنای نوشتن نسخه به بیرون نشت می‌کرد | تبدیل به شکست کنترل‌شده، بدون ثبت نسخه نامعتبر | `MigrationRunnerTest` ×۲ | ✅ استثنا از `run()` بیرون می‌زد |
| ۵ | مهاجرت فقط به activation وابسته بود؛ schema جلوتر Healthy اعلام می‌شد | `UpgradeGate` روی `admin_init` + cooldown + وضعیت `Ahead` | `UpgradeGateTest` ×۵ | ✅ رفتار وجود نداشت |

## خلاصه — دور دوم بازبینی

بازخورد دوم گفت اصلاح دور اول کافی نیست: «`refresh` قبل از نوشتن کافی نیست».
درست است. بررسی مالکیت و سپس نوشتن **دو عمل** است و فاصله بین آن دو با هیچ
`if` دیگری بسته نمی‌شود.

| # | ایراد | اصلاح | تست رگرسیون | اثبات روی کد قبلی |
|---|---|---|---|---|
| ۸ | نوشتن نسخه، ثبت خطا و حذف خطا فقط با بررسی جداگانه مالکیت محافظت می‌شدند | هر سه از `GuardedOptionStoreInterface` عبور می‌کنند؛ شرط مالکیت **داخل همان دستور SQL** | `MigrationTakeoverTest::testTakeoverBetweenTheOwnershipCheckAndTheVersionWrite` | ✅ نسخه ۱ روی ۳ نشست |
| ۹ | مسیرهای استثنای `up()`، شکست `verify()`، فاصله بررسی‌تا‌نوشتن، و حذف خطای اجرای جدید توسط اجرای قدیمی پوشش نداشتند | چهار تست takeover تازه + سه تست guarded روی MariaDB واقعی | `MigrationTakeoverTest` ×۵، `MigrationMariaDbTest` ×۴ | ✅ (بند ۸ و ۱۰) |
| ۱۰ | شکست ثبت خطا می‌توانست استثنای کنترل‌نشده بسازد | `recordErrorIfOwned`/`clearRecordedErrorIfOwned` هر `Throwable` را می‌گیرند؛ متن نتیجه `(not recorded)` می‌گیرد | `testFailureToRecordTheErrorDoesNotThrow` | ✅ `RuntimeException` از `run()` بیرون زد |
| ۱۱ | ثبت audit تنظیمات عملاً به اجرای `finalizeOutcome()` وابسته بود | ممیزی به لحظه تأیید ذخیره منتقل شد؛ `resolveOutcome()` فقط گزارش می‌دهد | `testAuditHappensOnPersistenceEvenWhenFinalizeOutcomeNeverRuns`، `testASingleSaveProducesExactlyOneAuditRow` | ✅ صفر ردیف ممیزی |

---

## ۸. نوشتن وضعیت، به‌صورت اتمیک مشروط به مالکیت

**ایراد.** اصلاح دور اول مالکیت را با `stillOwned()` بررسی می‌کرد و بعد
می‌نوشت. بین آن بررسی و رسیدن مقدار به دیتابیس، process می‌تواند بیش از TTL
قفل معلق شود؛ تصاحبی که دقیقاً در همان فاصله رخ دهد با نوشتن اجرای
منسوخ‌شده بازنویسی می‌شود. این پنجره با هیچ بررسی دیگری در PHP بسته نمی‌شود.

**اصلاح.** `Contracts/GuardedOptionStoreInterface` با دو عمل:

```php
setGuarded(string $key, mixed $value, string $guardKey, string $guardValue): bool
deleteGuarded(string $key, string $guardKey, string $guardValue): bool
```

پیاده‌سازی وردپرسی (`WpLockStore`) هرکدام را با **یک دستور** انجام می‌دهد:

```sql
INSERT INTO wp_options (option_name, option_value, autoload)
SELECT %s, %s, 'no' FROM wp_options AS guard
 WHERE guard.option_name = %s AND guard.option_value = %s
ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)

DELETE target FROM wp_options AS target
 INNER JOIN wp_options AS guard
    ON guard.option_name = %s AND guard.option_value = %s
 WHERE target.option_name = %s
```

اگر سطر قفل دیگر مقدار این اجرا را نداشته باشد، هیچ سطری برای نوشتن تولید
نمی‌شود. `MigrationRunner` هر سه نوشتن وضعیت را از این مسیر عبور می‌دهد:
`writeVersionIfOwned()`، `recordErrorIfOwned()`، `clearRecordedErrorIfOwned()`.
`refresh()`/`stillOwned()` می‌مانند اما فقط بهینه‌سازی‌اند، نه تضمین ایمنی.

**اثبات.** با جایگزینی موقت این سه متد با پیاده‌سازی قبلی «بررسی، سپس
نوشتن» و اجرای همان تست‌ها:

```
1) …MigrationTakeoverTest::testTakeoverBetweenTheOwnershipCheckAndTheVersionWrite
version 1 must not land on top of 3
Failed asserting that 1 is identical to 3.
```

خروجی کامل: `docs/evidence/regression-guarded-writes-vs-previous.log`
(سورس بلافاصله بازگردانده شد؛ `diff` بازگردانی در همان گزارش).

تست فاصله، تصاحب را روی **هر دو** store ثبت می‌کند (guarded و ساده) تا نقطه
تزریق در پیاده‌سازی قدیم و جدید یکی باشد؛ وگرنه مقایسه منصفانه نبود.

## ۹. پوشش سناریوهای takeover

`MigrationTakeoverTest` اکنون ۱۰ تست دارد. پنج مورد این دور:

| تست | چه چیزی را می‌سنجد |
|---|---|
| `testTakeoverThenUpThrowsRecordsNothing` | پس از تصاحب، استثنای `up()` نباید خطای اجرای جدید را بازنویسی کند |
| `testTakeoverThenVerifyReturnsFalseRecordsNothing` | `verify()` نادرست پس از تصاحب → `LockLost`، بدون ثبت |
| `testTakeoverThenVerifyThrowsRecordsNothing` | استثنای `verify()` پس از تصاحب → همان |
| `testTakeoverBetweenTheOwnershipCheckAndTheVersionWrite` | تصاحب دقیقاً در فاصله بررسی تا نوشتن |
| `testSupersededRunCannotDeleteTheNewRunsRecordedError` | اجرای قدیمی نمی‌تواند خطای ثبت‌شده اجرای جدید را پاک کند |

روی MariaDB واقعی چهار تست تازه اضافه شد: معنای `setGuarded`/`deleteGuarded`،
گردش مقدار از نوشتن guarded تا `get_option()`، و رد شدن نوشتن نسخه پس از
تصاحب واقعی قفل. suite دیتابیس اکنون optionها را به همان جدول واقعی
`wp_options` وصل می‌کند (`State::$optionsBackedByWpdb`)؛ پیش از این قفل در
جدول واقعی بود ولی optionها در آرایه‌ای درون‌پردازه‌ای، و آن دو می‌توانستند
با هم اختلاف داشته باشند بی‌آنکه تستی متوجه شود.

این تغییر یک ناهم‌خوانی واقعی را هم آشکار کرد: وردپرس مقدار option را در ستون
متنی نگه می‌دارد، پس عدد صحیح به‌صورت **رشته** برمی‌گردد. stub قبلی عدد
برمی‌گرداند و بنابراین با وردپرس واقعی همسان نبود. کد قبلاً درست بود
(`currentVersion()` عددی‌بودن را بررسی و cast می‌کند)، اما assertionهای تست
اصلاح شدند تا واقعیت را بسنجند نه ساده‌سازی stub را.

## ۱۰. شکست ثبت خطا، بدون استثنای کنترل‌نشده

`recordErrorIfOwned()` و `clearRecordedErrorIfOwned()` هر `Throwable` را
می‌گیرند و `false` برمی‌گردانند. اگر ثبت ممکن نشد ولی قفل هنوز در اختیار
ماست، نتیجه `Failed` است و متن خطا `(not recorded)` را حمل می‌کند؛ «ثبت شد»
و «نشد» از هم قابل تشخیص می‌مانند. اگر قفل را از دست داده باشیم، نتیجه
`LockLost` است.

روی کد قبلی، `testFailureToRecordTheErrorDoesNotThrow` با
`RuntimeException: the option store is down too` از `run()` بیرون می‌زند —
یعنی شکست دوم روی مسیر خطا.

## ۱۱. ممیزی تنظیمات، مستقل از `finalizeOutcome()`

**ایراد.** ممیزی داخل `resolveOutcome()` نوشته می‌شد و آن متد فقط از فیلتر
`pre_set_transient_settings_errors` صدا زده می‌شود؛ یعنی فقط روی مسیر
`options.php`. هر نوشتن دیگری روی `tmc_settings` — کد افزونه‌ای دیگر، WP-CLI،
`update_option()` برنامه‌ای — بدون هیچ ردپایی به دیتابیس می‌رسید.

**اصلاح.** ممیزی به `recordPersisted()` منتقل شد: همان لحظه‌ای که وردپرس با
`update_option_tmc_settings` / `add_option_tmc_settings` تأیید می‌کند مقدار
ذخیره شده است. یک latch (`$audited`) تضمین می‌کند یک نوشتن موفق دقیقاً یک
ردیف بسازد، حتی وقتی وردپرس برای option تازه دو بار sanitize را صدا می‌زند.
`resolveOutcome()` فقط نتیجه ثبت‌شده را **گزارش** می‌کند.

**اثبات.** با برگرداندن ممیزی به `resolveOutcome()`:

```
1) …SettingsApiTest::testAuditHappensOnPersistenceEvenWhenFinalizeOutcomeNeverRuns
the change is audited without finalizeOutcome()
Failed asserting that actual size 0 matches expected size 1.
```

تست، مقدار را با یک `update_option()` ساده می‌نویسد: بدون nonce، بدون
`options.php`، و بررسی می‌کند که transient `settings_errors` اصلاً ساخته
نشده باشد.

## خلاصه — دور سوم بازبینی

| # | ایراد | اصلاح | تست رگرسیون | اثبات روی کد قبلی |
|---|---|---|---|---|
| ۱۲ | `run()` خروجی `clearRecordedErrorIfOwned()` را دور می‌ریخت و همیشه `Applied` برمی‌گرداند | نتیجه پاک‌سازی یک نوع صریح (`CleanupOutcome`) با چهار حالت است و در `MigrationResult` حمل می‌شود | `MigrationCleanupTest` ×۱۰، `MigrationMariaDbTest` ×۳ | ✅ ۸ از ۱۰ تست unit و ۲ تست دیتابیس شکست |
| ۱۳ | رکورد خطای قدیمی، صفحه سلامت را برای همیشه قرمز می‌کرد و هیچ مسیر بازیابی نداشت | خطای ثبت‌شده وقتی `stored >= target` است **کهنه** شناخته می‌شود؛ پیام جدا، وضعیت سالم، و پاک‌سازی هنگام فعال‌سازی دوباره | `HealthSchemaStateTest` ×۳، `MigrationCleanupTest::testAnUpToDateRunClearsAStaleRecordLeftByAnEarlierRun` | ✅ شکست رفتاری |

---

## ۱۲. چهار حالت پاک‌سازی رکورد خطا

**ایراد.** پس از یک migration موفق، رکورد خطای اجرای قبلی پاک می‌شود. آن
پاک‌سازی خودش یک نوشتن است و چهار پایان ممکن دارد، اما `run()` خروجی را
نادیده می‌گرفت و در هر چهار حالت `Applied` برمی‌گرداند.

**اصلاح.** دو نوع صریح:

`Contracts/GuardedWriteOutcome` — پاسخ خود store:

| حالت | معنی |
|---|---|
| `Written` | نوشته یا حذف شد |
| `NoChangeNeeded` | مالک بودیم، ولی کاری نبود: مقدار از قبل همان بود، یا سطری برای حذف وجود نداشت. **موفقیت است، نه شکست** |
| `NotOwner` | guard مطابقت نکرد؛ هیچ چیز نوشته نشد |
| `Failed` | خود عملیات ذخیره‌سازی شکست خورد |

`Core/Migration/CleanupOutcome` — نتیجه در سطح Runner:

| حالت | نتیجه اجرا | `isFullySuccessful()` | چه چیزی در دیتابیس ماند |
|---|---|---|---|
| `NotNeeded` | `Applied` / `UpToDate` | ✅ | رکوردی نبود |
| `Cleared` | `Applied` / `UpToDate` | ✅ | رکورد حذف شد |
| `SkippedNotOwner` | `Applied` / `UpToDate` | ✅ | رکورد اجرای دیگر، دست‌نخورده |
| `Failed` | `Applied` + `error='cleanup_failed: …'` | ❌ | **رکورد کهنه باقی ماند** |

دو قاعده‌ای که بازخورد خواسته بود، صریح‌اند: نبودِ رکورد شکست نیست
(`NoChangeNeeded` → `NotNeeded`)، و شکست واقعی به موفقیتِ کامل تبدیل نمی‌شود
(`isSuccess()` همچنان true چون schema واقعاً مهاجرت کرد، ولی
`isFullySuccessful()` نادرست است و `error` پر می‌شود).

روی MySQL، «صفر سطر» ذاتاً مبهم است: هم وقتی guard مطابقت نکند و هم وقتی
دستور no-op باشد. `WpLockStore::classify()` این دو را با یک خواندنِ **پس از
نوشتن** جدا می‌کند. آن خواندن پنجره‌ای باز نمی‌کند: تصمیم قبلاً در دیتابیس
گرفته شده و مالکیت درون یک اجرا یک‌طرفه است — وقتی مقدار این اجرا از سطر قفل
برود، دیگر برنمی‌گردد.

**اثبات.** با برگرداندن `run()` به حالت قبلی (فراخوانی پاک‌سازی و دور ریختن
نتیجه): ۸ از ۱۰ تست `MigrationCleanupTest` و ۲ تست MariaDB شکست می‌خورند.
خروجی کامل: `docs/evidence/regression-cleanup-outcome.log`.

## ۱۳. هماهنگی صفحه سلامت و مسیر بازیابی

**ایراد.** `schemaStatus()` هر رکورد خطای ثبت‌شده را «نیازمند اقدام»
می‌دانست. اگر پاک‌سازی شکست می‌خورد، `stored == target` می‌ماند و
`UpgradeGate` هم دیگر اجرا نمی‌کرد (چون `needsMigration()` نادرست است).
نتیجه: صفحه سلامت برای همیشه قرمز، بدون هیچ مسیر خروجی.

**اصلاح.**

- رکورد خطا وقتی `stored >= target` است **کهنه** است: اجرایی که آن را نوشت
  توسط اجرای موفق بعدی جایگزین شده و فقط حذفِ رکورد ناموفق مانده. بررسی
  `schema` در این حالت **سالم** است، fact تازه‌ای به نام `last_error_stale`
  دارد، و پیام فارسی جداگانه می‌گوید ساختار کامل است و این رکورد وضعیت فعلی
  را توصیف نمی‌کند.
- مسیر بازیابی واقعی و بی‌هزینه: `MigrationRunner::run()` در مسیر
  `UpToDate` وقتی — و فقط وقتی — رکوردی هست، قفل را می‌گیرد، دوباره نسخه را
  بررسی می‌کند و رکورد را guarded حذف می‌کند. `run()` فقط هنگام فعال‌سازی یا
  وقتی migration واقعاً معلق است صدا زده می‌شود، پس درخواست‌های عادی مدیریت
  هیچ ترافیک قفلی نمی‌بینند (`testAnUpToDateRunWithNothingStaleTouchesNoLockRow`
  روی MariaDB این را می‌سنجد). همان جمله‌ای که پیام سلامت می‌گوید — «فعال‌سازی
  دوباره افزونه آن را پاک می‌کند» — واقعاً کاری است که کد انجام می‌دهد.

اگر اجرای دیگری قفل را در دست داشته باشد، پاک‌سازی انجام **نمی‌شود**
(`SkippedNotOwner`): رکورد ممکن است متعلق به همان اجرای در جریان باشد.

---

## ۱. تفکیک اعتبارسنجی، ذخیره و ممیزی

**ایراد.** `SettingsRegistrar::sanitize()` هم اعتبارسنجی می‌کرد، هم پیام
«ذخیره شد» می‌داد، هم ردیف audit می‌نوشت. اما sanitize **پیش از** نوشتن در
پایگاه داده اجرا می‌شود و از نتیجه آن بی‌خبر است. اگر نوشتن شکست می‌خورد،
کاربر پیام موفقیت می‌دید و یک ردیف ممیزی برای تغییری ثبت می‌شد که هرگز رخ
نداده بود. مقادیر `old`/`new` هم از **قصد** می‌آمدند، نه از چیزی که واقعاً
ذخیره شد.

**اصلاح.** سه رویداد جدا:

| مرحله | چه زمانی | چه می‌کند |
|---|---|---|
| `sanitize()` | پیش از نوشتن | فقط اعتبارسنجی و پیام خطای فیلد. **هیچ ادعای موفقیت، هیچ audit** |
| `update_option_tmc_settings` / `add_option_tmc_settings` | وردپرس تأیید می‌کند مقدار به دیتابیس رسید | ردیف audit با **مقادیر واقعی قبل/بعد خود option** |
| `pre_set_transient_settings_errors` | پس از پایان همه نوشتن‌های options.php | دقیقاً **یک** پیام نتیجه |

چهار نتیجه از هم قابل تفکیک‌اند:

| وضعیت | پیام | audit |
|---|---|---|
| ذخیره موفق | `tmc_saved` (success) | بله، با مقادیر واقعی |
| هیچ تغییری نبود | `tmc_no_change` (info) | خیر |
| **ذخیره ناموفق** | `tmc_save_failed` (**error**) | خیر |
| ذخیره موفق ولی audit ناموفق | `tmc_audit_failed` (warning) | تلاش شد و شکست خورد؛ صریح گزارش می‌شود |

`SettingsSubmission` به `validate()` و `auditPersisted()` شکسته شد؛ دومی
مقادیر واقعی ذخیره‌شده را می‌گیرد و اگر در عمل چیزی تغییر نکرده باشد اصلاً
ردیفی نمی‌نویسد.

**تست.** `testFailedWriteIsReportedAsFailureAndIsNeitherAuditedNorCalledSaved`،
`testUnchangedSubmissionIsReportedAsNoChangeAndIsNotAudited`،
`testAuditRecordsTheValuesThatWereActuallyStored`،
`testAuditFailureIsReportedNotSilent`. برای مدل‌کردن شکست ذخیره، stub وردپرس
سوئیچ `State::$failOptionWrites` گرفت و اکنون actionهای واقعی
`update_option_*`/`add_option_*` را هم شلیک می‌کند.

## ۲. تشخیص پاس دوم sanitize بدون اتکا به ساختار

**ایراد.** پاس دوم وردپرس از روی شکل آرایه (`schema_version` + `values`)
تشخیص داده می‌شد. آن شکل **قابل جعل** است: یک درخواست می‌توانست همان بسته را
مستقیم POST کند و کل مسیر اعتبارسنجی و ممیزی را دور بزند.

**اصلاح.** تشخیص با **token یک‌بارمصرف** بسته به **مقدار دقیق**ی که خود
`sanitize()` تازه برگردانده (`hash_equals` روی اثر انگشت SHA-256). هر ورودی
دیگری — از جمله بسته‌ای با همان شکل — از مسیر کامل اعتبارسنجی می‌گذرد، جایی
که کلیدهای ناشناخته نادیده گرفته می‌شوند و **مقادیر قبلی حفظ می‌شوند**.
token پس از یک بار مصرف باطل می‌شود.

رفتار واقعی وردپرس دست‌نخورده ماند: optionی که هنوز وجود ندارد دو بار
sanitize می‌شود (`update_option` سپس `add_option`) و مقادیر تازه‌ارسال‌شده
از بین نمی‌روند.

**تست.** `testForgedCanonicalPayloadCannotBypassValidationOrAudit`،
`testBrandNewOptionSurvivesWordPressDoubleSanitize`، `testReplayTokenIsSingleUse`.

## ۳. انقضای قفل وسط migration و تصاحب

**ایراد.** Runner نسخه را **پیش از** گرفتن قفل می‌خواند و بعد دیگر نمی‌خواند،
و مالکیت را فقط بین مرحله‌ها بررسی می‌کرد.

شاهد عینی قبل/بعد در `docs/evidence/regression-migration-takeover.txt`.
سناریو: اجرای A مرحله ۱ را اعمال می‌کند، داخل مرحله ۲ کند می‌شود تا قفلش
منقضی شود، اجرای B تصاحب می‌کند و کل مهاجرت را تا نسخه ۳ تمام می‌کند.

```
قبل از اصلاح:  status=failed   نسخه ذخیره‌شده=2   خطای ثبت‌شده=بله
بعد از اصلاح:  status=lock_lost نسخه ذخیره‌شده=3   خطای ثبت‌شده=خیر
```

یعنی پیش از اصلاح، اجرای منسوخ‌شده **هر دو** چیزی را که نباید تغییر می‌داد:
نسخه را از ۳ به ۲ **عقب برد** و برای دیتابیسی که B با موفقیت مهاجرت داده بود
یک شکست ثبت کرد.

**اصلاح.**
1. نسخه جاری **پس از** `acquire()` دوباره خوانده می‌شود؛ نسخه پیش از acquire
   ذاتاً کهنه است.
2. مالکیت **پیش از هر مرحله** و **دوباره پیش از هر نوشتن نسخه** اثبات
   می‌شود. `MigrationLock::refresh()` دیگر در همان ثانیه بی‌بررسی `true`
   برنمی‌گرداند؛ حالا `stillOwned()` یک خواندن واقعی از store انجام می‌دهد.
3. وضعیت جدید `LockLost` از `Failed` جداست: اجرای منسوخ **هیچ چیز نمی‌نویسد**
   — نه نسخه، نه option خطا.

**مرز این تضمین، مستند شده** (ADR-003 §۳٫۱): MySQL برای DDL rollback ندارد و
PHP نمی‌تواند دستور در حال اجرای سرور را لغو کند، پس ممکن است DDL یک اجرای
منسوخ پس از تصاحب هم به مقصد برسد. این فقط چون **هر مرحله موظف به ایدمپوتنت
بودن است** قابل تحمل است. پنجره خطر کوچک شده، ولی ادعای صفر بودن نمی‌شود.

**تست.** `MigrationTakeoverTest` (چهار تست، در سطح **خود Runner**) و
`MigrationMariaDbTest::testSupersededRunWritesNothingOverTheNewRunOnRealDatabase`
روی MariaDB واقعی.

## ۴. استثناها به شکست کنترل‌شده

**ایراد.** `verify()` و `options->set()` بیرون از `try` بودند؛ استثنای هرکدام
از `run()` بیرون می‌زد و در مسیر فعال‌سازی به خطای مرگبار تبدیل می‌شد.

**اصلاح.** هر دو در `try` قرار گرفتند و به شکست کنترل‌شده با کد متمایز
(`verify_threw:` و `version_persist_threw:`) تبدیل می‌شوند. در هیچ‌کدام نسخه
ثبت نمی‌شود، متن استثنا به یک خط بدون stack trace کوتاه می‌شود، و قفل در
`finally` آزاد می‌گردد.

**تست.** `testVerifyThrowingBecomesAControlledFailureNotALeakedException`،
`testVersionPersistenceThrowingBecomesAControlledFailure`.

## ۵. مسیر ارتقا، retry، و schema جلوتر

**ایراد.** مهاجرت فقط در activation اجرا می‌شد. وردپرس هنگام به‌روزرسانی
فایل‌های افزونه hook فعال‌سازی را **دوباره اجرا نمی‌کند**، پس نسخه schema
جدید منتشر می‌شد و هرگز اعمال نمی‌گشت. مهاجرت شکست‌خورده هم راه بازیابی
خودکار نداشت. و `stored >= target` در صفحه سلامت **Healthy** بود، یعنی
دیتابیسی که نسخه جدیدتر افزونه نوشته بود «سالم» اعلام می‌شد.

**اصلاح.** `Core/Migration/UpgradeGate.php` روی `admin_init` (نه فرانت):

| وضعیت | رفتار |
|---|---|
| `stored == target` | هیچ کاری؛ یک خواندن option |
| `stored < target`، بدون خطای قبلی | اجرا |
| `stored < target`، با خطای ثبت‌شده | اجرای دوباره فقط پس از ۳۰۰ ثانیه |
| `stored > target` | **هرگز** — این نسخه ساختار جدیدتر را نمی‌شناسد |

cooldown فقط retry را محدود می‌کند؛ خطا در تمام مدت روی صفحه سلامت دیده
می‌شود. صفحه سلامت برای `stored > target` اکنون **نیازمند اقدام** است با
توضیح فارسی روشن، نه Healthy. `MigrationResult::ahead()` و
`MigrationRunner::isAhead()` این حالت را صریح می‌کنند.

**تست.** `UpgradeGateTest` (پنج تست)،
`MigrationRunnerTest::testStoredVersionAboveTargetIsReportedAheadAndNeverTouched`،
`MigrationTakeoverTest::testSchemaAheadOfThisBuildIsNeverMigratedDown`.

## ۶. راهنمای نصب — exit code دستورهای لوله‌شده

در `cmd | tee file` مقدار `$?` متعلق به `tee` است، نه `cmd`؛ یعنی دستور
شکست‌خورده «موفق» به نظر می‌رسید. در بند ۴ راهنما اکنون `set -o pipefail`
روشن است، هرجا عدد جدا ثبت می‌شود از `${PIPESTATUS[0]}` استفاده می‌شود، و
تابع کمکی `run_ev` خروجی و exit code واقعی دستور را با هم نگه می‌دارد.

## ۷. بسته سورس — بازگشت خاموش به tar ضعیف‌تر

هنگام همین اصلاح‌ها معلوم شد `tools/build.sh` وقتی فهرست فایلش قابل استفاده
نبود (مثلاً فایلی که حذف شده ولی هنوز در index است) **بی‌صدا** به یک
`tar .` ساده برمی‌گشت. آن مسیر جایگزین `tools/browser/node_modules` را هم
داخل بسته سورس می‌کشید: ۸٫۱ مگابایت Playwright به‌جای ۳٫۶ مگابایت سورس، بدون
هیچ هشداری.

اصلاح: فهرست فایل‌ها صریح ساخته می‌شود، فقط مسیرهای موجود روی دیسک نگه داشته
می‌شوند، و مسیر جایگزین خاموش **حذف شد** — build در صورت خرابی فهرست با خطا
متوقف می‌شود. تست `PackagingTest::testSourceArchiveCarriesNoVendoredDependencies`
نبود `node_modules`/`vendor`/`dist` و کران اندازه را بررسی می‌کند؛ روی بسته
تولیدشده با روش قدیمی این تست می‌شکند (بررسی شد).

## وضعیت آزمون‌ها پس از اصلاح

| گیت | نتیجه |
|---|---|
| Unit | ۱۳۴ تست، ۲۰٬۵۵۲ assertion |
| معماری و قواعد امنیتی | ۱۲ تست، ۶۰ assertion |
| قرارداد با stub | ۴۵ تست، ۴۶۶ assertion |
| دیتابیس واقعی MariaDB | ۲۲ تست، ۲۰۸ assertion |
| بسته‌بندی | ۱۲ تست، ۲٬۶۸۰ assertion |
| `php -l` | ۱۱۳ فایل، ۰ خطا |
| PHPCompatibility 8.1 | ۰ خطا |
| کنتراست | ۱۳ ترکیب، ۰ خطا |
| مرورگر روی harness | ۲۳۳ بررسی، ۰ خطا |

**همچنان Not Run** و بدون تغییر: نصب و فعال‌سازی واقعی WordPress، یکپارچگی
WP/WC، حالت‌های HPOS، اجرای واقعی روی PHP 8.1، و screen reader دستی. اصلاح
مستندات هیچ‌کدام از این‌ها را Passed نمی‌کند.
