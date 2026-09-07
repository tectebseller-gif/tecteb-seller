# طرح اجرای فاز ۱ — برای بررسی مالک

بازبینی ۴ · ۷ سپتامبر ۲۰۲۶ · وضعیت: **اجرا شد.**

فاز ۱ طبق همین طرح و با شش اصلاح مجوز اجرا پیاده‌سازی شد. آنچه واقعاً اجرا شد
و آنچه اجرا نشد در `docs/phase-1-report.md` سطربه‌سطر آمده است. بسته با برچسب
**«تأییدنشده — نصب نشود»** تحویل شده چون گیت نصب واقعی WordPress اجرا نشدنی
بود. این فایل به‌عنوان **طرح مصوب** نگه داشته می‌شود؛ برای وضعیت جاری به گزارش
مراجعه کنید.

### شش اصلاح مجوز اجرا و محل تحقق هرکدام
| # | اصلاح | کجا |
|---|---|---|
| ۱ | قفل migration با شناسه مالک؛ تصاحب/آزادسازی اتمی و وابسته به مالک | `Core/Migration/MigrationLock.php` · ADR-003 · `MigrationLockTest`، `WpLockStoreTest` (شامل ۸ فرایند forkشده) |
| ۲ | خرابی ماژول وابسته‌ها را متوقف می‌کند؛ مستقل‌ها ادامه؛ علت در سلامت ثبت | `Core/Modules/ModuleLoader.php` · ADR-002 · `ModuleLoaderTest` (۱۱ تست) |
| ۳ | مرز یکدست Core/Contracts/Application؛ AST شاهد قاعده محدود است، نه تضمین کامل | `tests/Architecture/` · `docs/architecture.md` §۲ |
| ۴ | ورودی درصد جدا از مقدار ذخیره‌شده؛ ۱۲٫۳۴ → ۱۲۳۴ بدون تبدیل دوباره؛ null ≠ صفر | `Core/Config/` · ADR-004 · `PercentToBasisPointsTest`، `SettingsApiTest` |
| ۵ | صفر یعنی بدون تأخیر زمانی اضافی؛ هیچ شرط پرداخت/تکمیل/hold حذف نمی‌شود؛ بدون مصرف عملیاتی | `SettingsSchema.php`، `Views/settings.php` · F-03 |
| ۶ | نبود WooCommerce: بدون fatal، بدون عملیات وابسته، پیام فارسی، تشخیص محدود در دسترس | `Bootstrap.php`، `WpDependencyProbe.php`، `Notices.php` · `BootstrapTest` |

مرجع: پرامپت فاز ۱ v0.2 · Master Spec v0.3 · UX Spec v0.2 (checksumها در
`docs/reference-checksums.txt`؛ فیدلیتی نسخه Markdown در
`docs/generated/EXTRACTION-FIDELITY.md`). فرض‌ها و تصمیم‌های باز در
`docs/decision-log.md` (بازبینی ۳).

### تغییرات بازبینی ۳ نسبت به بازبینی ۲ (طبق بازخورد مالک)
1. نسخه انتشار افزونه از نسخه schema دیتابیس **مستقل** شد؛ migration فقط
   با تغییر ساختار اجرا می‌شود (§۳، F-01).
2. الزام «عقب‌نرفتن نسخه» فقط برای **ادامه همان تبار** (م-۲) است؛ هم‌زیستی
   دو افزونه مستقل به‌تنهایی چنین الزامی ندارد (F-01).
3. هیچ دستوری درباره اسکلت قبلی (غیرفعال‌کردن یا غیر آن) تا بررسی
   فقط‌خواندنی محتوا، وابستگی‌ها و وضعیت نصب آن قطعی نیست (§۰، §۱۱).
4. `settlement_delay_days`: دامنه کامل `0..365`، حد پایین ۰، معنای صفر
   مشخص شد؛ پیش‌فرض ۴؛ **هیچ مصرف عملیاتی در فاز ۱** (§۶، F-03).
5. اعداد ۱۰۰ و ۳۶۵ «**حد فنی پیشنهادی**» نامیده شدند؛ وجودشان اثبات ظرفیت
   آزموده‌شده نیست (§۶).
6. تعیین تبار **وابسته به بررسی فقط‌خواندنی** سورس قبلی ماند؛ **هیچ گزینه‌ای
   انتخاب نشده** (§۰، §۱۱).

### تغییرات بازبینی ۲ نسبت به بازبینی ۱
DEC-06 پنج‌جزئی؛ سورس قبلی «تعیین‌نشده»؛ `max_staff` پیش‌فرض ۱۰ مصوب و حد
بالا غیرتجاری؛ ADR-001 برای autoloader؛ فیدلیتی استخراج با تطبیق ترتیبی و
فهرست اختلاف‌ها؛ قاعده `Not Run` و «نمونه رابط».

---

## ۰. پیش‌شرط‌های هویتی که هنوز بسته نیستند

| مورد | وضعیت | اثر |
|---|---|---|
| سورس قبلی `tecteb-marketplace-v0.1.1.zip` | **تعیین‌نشده** — دریافت نشده، محتوا/وابستگی/وضعیت نصب نامعلوم | تعیین تبار (م-۱/م-۲/م-۳) **فقط پس از بررسی فقط‌خواندنی** آن انجام می‌شود؛ فعلاً هیچ گزینه‌ای انتخاب نشده. نسخه انتشار (F-01) و هر دستور درباره آن اسکلت (غیرفعال‌کردن، هم‌زیستی، مهاجرت option) به همین بررسی وابسته است. scaffold در مخزن وابسته نیست |
| تطبیق محیط سایت (DEC-06-b) | باز — Not Run | ماتریس سازگاری تا اجرای واقعی خالی از `Passed` می‌ماند |
| آزمون سازگاری (DEC-06-c) | باز — Not Run | همان |
| Multisite (DEC-06-e) | بسته: پشتیبانی نمی‌شود | رد network activation با پیام فارسی |

---

## ۱. ساختار فایل هدف

دقیقاً همان ساختاری که پرامپت نام برده. هیچ پوشه خالی برای وانمود کردن
تکمیل ماژول ساخته نمی‌شود.

```
tecteb-marketplace-core.php      metadata (نسخه انتشار)، ABSPATH guard، بررسی نسخه PHP پیش از require
uninstall.php                    فقط guard + توضیح preservation؛ حذف نمی‌کند
src/
  Core/
    Autoloader.php               PSR-4 اختصاصی محدود به Tecteb\Marketplace\ (ADR-001)
    Container.php                DI کوچک
    Plugin.php                   bootstrap
    Lifecycle/                   Activator، Deactivator، Requirements، MultisiteGuard
    Modules/                     ModuleLoader، ModuleRegistry، ModuleManifest، ModuleStatus
    Migration/                   MigrationRunner، MigrationLock، SchemaVersion (نسخه schema، مستقل از نسخه انتشار)، Migrations/
    Config/                      SettingsSchema (حدود فنی پیشنهادی اینجا)، SettingsService، Sanitizer/
    Environment/                 EnvironmentResolver، OutboundPolicy
    Audit/                       AuditEvent، AuditEventSanitizer، AuditLogger
  Contracts/                     ModuleInterface، ClockInterface، AuditRepositoryInterface،
                                 SettingsStoreInterface، OtpProviderInterface، OtpResult
  Modules/
    Admin/                       AdminModule + Presentation/{MenuRegistrar, AssetLoader,
                                 Pages/, Views/, Components/} + Application/SettingsController
    Health/                      HealthModule + Application/HealthReportBuilder
                                 + Infrastructure/Rest/HealthController
  Infrastructure/
    WordPress/                   WpSettingsStore، WpAuditRepository، WpCapabilities،
                                 WpClock، HposDeclaration، WpEnvironmentProbe
    Otp/NullOtpProvider.php
assets/admin/                    tmc-admin.css، tmc-admin.rtl.css، tmc-admin.js
languages/                       tecteb-marketplace-core-fa_IR.po/.mo
tests/                           Unit/، Database/، WordPressContract/، Architecture/، Browser/
tools/                           build.sh، docx-to-markdown.py، verify-extraction.py، wp-stubs/
docs/                            (موجود) + adr/ + خروجی‌های تحویل فاز ۱
```

### مرز معماری که آزمون می‌شود
`src/Core/**` و `src/Contracts/**` حق فراخوانی هیچ تابع WP یا کلاس WC را
ندارند. این با تست خودکار (`tests/Architecture/`) بررسی می‌شود که AST هر
فایل را می‌خواند و فراخوانی توابع/کلاس‌های WP/WC را رد می‌کند — نه با
بازبینی چشمی. دسترسی به WP فقط از `src/Infrastructure/WordPress/**` و لایه
Presentation ماژول‌ها.

---

## ۲. Dependency graph ماژول‌ها

```
core (زیرساخت، همیشه)
  └── environment-guard (زیرساخت)
        ├── admin   (عملیاتی — این فاز)
        └── health  (عملیاتی — این فاز)
```

ماژول‌های صرفاً roadmap با وضعیت `planned` در manifest ثبت می‌شوند و **هیچ
controller، هیچ hook و هیچ دکمه فعال‌سازی ندارند**: `vendor`, `product`,
`order`, `commission`, `settlement`, `migration`.

بارگذاری دومرحله‌ای: `register()` برای همه، سپس `boot()` به ترتیب
توپولوژیک. چرخه وابستگی، وابستگی گمشده، id تکراری و exception هنگام boot
هرکدام مسیر خطای مشخص دارند؛ ماژول عملیاتی خراب `degraded` می‌شود و بقیه
بالا می‌آیند. `boot()` دوم hook تکراری نمی‌سازد (flag ایدمپوتنت).

---

## ۳. Persistence و نسخه‌گذاری

### دو نسخه مستقل

| | نسخه انتشار افزونه | نسخه schema دیتابیس |
|---|---|---|
| شکل | رشته semver در header فایل main | عدد صحیح، شروع از `1` |
| محل | header + ثابت `PLUGIN_VERSION` | ثابت `SCHEMA_VERSION` در `SchemaVersion.php` |
| ذخیره | فقط در کد | option `tmc_schema_version`، فقط پس از migration موفق |
| تغییر | هر انتشار | **فقط با تغییر ساختار** (جدول/ستون/ایندکس/شکل option) |

**قاعده:** `MigrationRunner` فقط وقتی `stored < SCHEMA_VERSION` است اجرا
می‌شود. ارتقای نسخه انتشار بدون تغییر ساختار **هیچ migrationی اجرا نمی‌کند
و قفل هم نمی‌گیرد.** در فاز ۱ `SCHEMA_VERSION = 1` است (ساخت جدول audit)،
مستقل از این‌که نسخه انتشار چه تعیین شود (F-01).

### wp_options
| کلید | محتوا |
|---|---|
| `tmc_schema_version` | عدد صحیح schema؛ فقط **پس از** migration موفق نوشته می‌شود |
| `tmc_settings` | یک آرایه با فیلد نسخه schema تنظیمات |
| `tmc_migration_lock` | قفل اتمی با `add_option` + timestamp انقضا و بازیابی قفل منقضی؛ فقط وقتی migration واقعاً لازم است گرفته می‌شود |

### جدول — فقط یکی در این فاز
`{$wpdb->prefix}tmc_audit_events`

| ستون | نوع | توضیح |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | |
| `event_type` | VARCHAR(64) | allowlist |
| `actor_id` | BIGINT UNSIGNED **NULL** | داده مستعارشده، نه «بدون داده شخصی» |
| `object_type` / `object_id` | VARCHAR(64) / VARCHAR(64) NULL | |
| `payload` | LONGTEXT | JSON فقط از کلیدهای allowlist‌شده |
| `correlation_id` | CHAR(36) NULL | |
| `created_at` | DATETIME | **UTC** |

ایندکس: `(event_type, created_at)`، `(actor_id, created_at)`،
`(object_type, object_id)`.

هیچ جدول تجاری آینده‌ای ساخته نمی‌شود. migration مرحله‌ای و قابل resume است
(DDL در MySQL rollback تراکنشی ندارد). deactivate هیچ داده‌ای پاک نمی‌کند.
هیچ retention job ساخته نمی‌شود (DEC-05).

---

## ۴. Capabilityها

فقط چهار مورد مصرف‌شده، افزوده به **Administrator** در نصب single-site:
`tmc_view_dashboard`, `tmc_view_health`, `tmc_manage_settings`,
`tmc_view_modules`. به نقش seller/staff موجود دست زده نمی‌شود.

| نقطه ورود | بررسی |
|---|---|
| منو و رندر هر صفحه | capability متناظر |
| `register_setting` / ذخیره | `tmc_manage_settings` **و** nonce معتبر |
| `GET /tmc/v1/health` | `permission_callback` = `tmc_view_health` |

nonce به‌تنهایی مجوز نیست؛ هر دو بررسی جدا انجام می‌شوند.

---

## ۵. قرارداد پاسخ Health

دقیقاً همان schema پرامپت (`schema_version: "1"`، `plugin`, `environment`,
`dependencies.woocommerce`, `dependencies.hpos.enabled: boolean|null`,
`outbound.tmc: "blocked"`, `outbound.other_plugins: "unknown"`, `modules[]`,
`checked_at` ISO-8601 UTC). `plugin.version` نسخه انتشار است، نه نسخه schema.
پاسخ `no-store`. هیچ path سرور، credential، فهرست کاربر یا stack trace.
`null` یعنی نامشخص و هرگز به `false` تبدیل نمی‌شود. صفحه سلامت «HPOS فعال»
و «HPOS آزموده‌شده» را **دو فیلد جدا** نشان می‌دهد؛ دومی تا اجرای ماتریس
سازگاری `unknown` است.

---

## ۶. پیکربندی (CORE-07)

| کلید | پیش‌فرض | ماهیت پیش‌فرض | دامنه اعتبارسنجی | مصرف عملیاتی در فاز ۱ |
|---|---|---|---|---|
| `default_commission_rate_bp` | `null` | مصوب (FIN-02، UX-01) | decimal با حداکثر ۲ رقم اعشار → basis points صحیح `0..10000`؛ **بدون float**؛ `null` = «هنوز تعیین نشده»، `0` = «بدون کمیسیون» | هیچ — فقط ذخیره/نمایش/پیام «تعیین‌نشده» |
| `settlement_delay_days` | `4` | **مصوب** (سند مادر ۸.۳/A.2) | عدد صحیح، **`0 ≤ n ≤ 365`**؛ حد پایین **۰**؛ **صفر = بدون تأخیر** (واجد شرایط شدن در همان لحظه ثبت «تکمیل»؛ تعریف «تکمیل» خودش در DEC-02 باز است)؛ منفی/اعشار/متن/خالی → reject و حفظ مقدار قبلی؛ حد بالا **پیشنهادی** | **هیچ** — هیچ موتور، cron یا محاسبه‌ای آن را نمی‌خواند |
| `max_staff` | `10` | **مصوب** (سند مادر A.4، CORE-07) | عدد صحیح، **`1 ≤ n ≤ 100`**؛ صفر رد می‌شود (غیرفعال‌کردن پرسنل تصمیم تجاری است و در سند نیامده)؛ حد بالا **پیشنهادی** | هیچ |
| `environment_override` | `auto` | — | `auto` / `staging` / `production` — **قفل outbound را باز نمی‌کند** | فقط نمایش محیط |

### درباره حدود فنی پیشنهادی (F-02، F-03)
- ۱۰۰ و ۳۶۵ **حد فنی پیشنهادی** هستند: مرز اعتبارسنجی ورودی برای رد مقدار
  نامعقول، نه قانون تجاری. در هیچ متن کاربری «حداکثر مجاز کسب‌وکار» خوانده
  نمی‌شوند (CORE-07: «سقف فنی مستند، نه قانون تجاری مخفی»).
- **وجود این اعداد اثبات ظرفیت آزموده‌شده نیست.** هیچ آزمونی نشان نداده که
  ۱۰۰ پرسنل در یک فروشگاه یا تأخیر ۳۶۵ روزه رفتار درستی دارد. ظرفیت واقعی
  در فازی که آن قابلیت ساخته می‌شود آزموده خواهد شد.
- **محل تعریف:** ثابت‌های نام‌دار در `src/Core/Config/SettingsSchema.php` با
  docblock «حد اعتبارسنجی فنی پیشنهادی؛ قاعده تجاری نیست؛ ظرفیت آزموده‌شده
  نیست»، و ثبت در `docs/public-contracts.md`.
- **تغییر بعدی:** ویرایش همان ثابت در یک انتشار نسخه‌دار طبق بخش ۲۰ سند مادر.
  در فاز ۱ فیلتر عمومی برایش ساخته نمی‌شود.
- **پیش‌فرض‌های ۱۰ و ۴** خودشان از صفحه تنظیمات و توسط مدیرکل (با
  `tmc_manage_settings` و nonce) قابل تغییرند — همان چیزی که A.4 می‌گوید.

ارقام فارسی/عربی نرمال می‌شوند. مقدار نامعتبر **reject** می‌شود و مقدار قبلی
دست‌نخورده می‌ماند. فعال‌سازی دوباره مقدار کاربر را به پیش‌فرض برنمی‌گرداند.

---

## ۷. ماتریس آزمون — با وضعیت واقعی، نه آرزو

محدودیت‌ها در `docs/environment-inventory.md` اندازه‌گیری شده‌اند و در
`docs/compatibility-matrix.md` سطربه‌سطر آمده‌اند.

| لایه | ابزار | وضعیت در این محیط |
|---|---|---|
| ۱. Unit خالص (Domain بدون WP) | PHPUnit | ✅ قابل اجرا |
| ۲. معماری (رد فراخوانی WP در Core؛ انطباق PSR-4) | تست AST خودکار | ✅ قابل اجرا |
| ۳. Migration روی MySQL واقعی (از جمله «ارتقای نسخه انتشار بدون تغییر schema → هیچ migration») | MariaDB 10.11 از apt | ✅ قابل اجرا — **معادل نصب WP نیست** |
| ۴. قرارداد WP با stub | PHPUnit + `tools/wp-stubs/` | ⚠️ اجرا می‌شود ولی **معادل integration واقعی نیست**؛ جدا گزارش می‌شود |
| ۵. lint با PHP 8.4 | `php -l` | ✅ قابل اجرا |
| ۶. سازگاری با PHP 8.1 | PHPCompatibility `testVersion 8.1` | ⚠️ شاهد **ایستا**؛ اجرای واقعی روی 8.1 **Not Run** |
| ۷. رندر view + دسترس‌پذیری خودکار | Playwright + axe روی harness | ⚠️ **فقط «نمونه رابط»** — بند زیر |
| ۸. نصب/فعال‌سازی واقعی WordPress | — | ❌ **Not Run** — WP core قابل دانلود نیست |
| ۹. WP/WC integration، HPOS on/off/sync | — | ❌ **Not Run** — WC قابل دانلود نیست |
| ۱۰. Screen reader دستی | — | ❌ **Not Run** |

### قواعد گزارش‌دهی (بدون استثنا)
- هر CORE-ID به فایل، نام تست، دستور، exit code و نتیجه وصل می‌شود.
- **`Not Run` هرگز به `Passed` تبدیل نمی‌شود** مگر با log قابل بازتولید از
  اجرای واقعی. «احتمالاً کار می‌کند» وضعیت نیست.
- تستی که فقط stub خودش را تأیید کند شاهد قبولی نیست.
- **تصاویر و نتایج مرورگر که خارج از WordPress گرفته شده‌اند، «نمونه رابط»
  هستند، نه اثبات کارکرد افزونه.** در `phase-1-report.md` ستون جدایی با
  عنوان «نمونه رابط (خارج WP)» دارند و در هیچ CORE-ID به‌عنوان `Passed`
  شمرده نمی‌شوند. CORE-05 تا اجرای روی wp-admin واقعی `Not Run` می‌ماند.

### پیامد قراردادی
گیت نصب Staging نیاز به «نصب/فعال‌سازی واقعی» دارد. چون ردیف ۸ اجراشدنی
نیست، ZIP فاز ۱ با برچسب **«unverified — نصب نشود»** تحویل می‌شود. source،
تست‌ها و گزارش برای بررسی قابل تحویل‌اند. دو راه خروج در
`docs/compatibility-matrix.md` بند ۶.

---

## ۸. بسته‌بندی

`tools/build.sh` از سورس مشخص و تکرارپذیر:

- `dist/tecteb-marketplace-core.zip` — **یک** پوشه `tecteb-marketplace-core`
  با main file داخل آن.
- خارج از ZIP نصب: `.git`, `.env`, `docs/*.docx`, `tests/`, `tools/`,
  `vendor/` (ADR-001)، backup، هر فایل تجاری مرجع.
- `dist/SHA256SUMS`.
- source archive جدا شامل `tests/`, `docs/`, `composer.lock` و `tools/build`.
- HPOS با `FeaturesUtil::declare_compatibility` در `before_woocommerce_init`
  با guard وجود کلاس — این declaration **ادعای تست‌شدن نیست**.
- runtime بدون Composer و بدون Node (ADR-001).
- نسخه در header ZIP = نسخه انتشار (F-01)؛ تا تعیین تبار، مقدار موقت با
  TODO و برچسب ZIP «unverified».

---

## ۹. تحویل‌های مستند فاز ۱

| سند | وضعیت |
|---|---|
| `decision-log.md`، `environment-inventory.md`، `compatibility-matrix.md`، `adr/ADR-001` | ✅ نوشته شده (پیش از کد) |
| `generated/EXTRACTION-FIDELITY.md` | ✅ نوشته شده |
| `phase-1-report.md` (CORE-ID → فایل → test → evidence → Passed/Failed/Not Run) | پس از پیاده‌سازی |
| `architecture.md` + ADR-002..004 | همراه پیاده‌سازی |
| `installation.md` (rollback فقط فاز ۱؛ **بدون دستور درباره اسکلت قبلی تا بررسی آن**) | پس از پیاده‌سازی |
| `public-contracts.md` (option/capability/table/hook/route/schema + حدود فنی پیشنهادی) | همراه پیاده‌سازی |
| تصاویر چهار صفحه با داده مصنوعی — **برچسب «نمونه رابط»** | پس از پیاده‌سازی |
| `next-phase-handoff.md` | پایان فاز |

---

## ۱۰. آنچه ساخته نمی‌شود

Vendor، Staff، Product، فرم‌ساز پزشکی، Order، Commission، Withdrawal،
Refund، Coupon، B2B، Ticket، SEO، importer دکان، auth واقعی، هر sender یا
gateway واقعی، منوی فروشنده، route دکان، آمار تجاری ساختگی، صفحه مالی،
retention job، UI exporter لاگ، فیلتر عمومی برای حدود فنی، هر مصرف‌کننده
`settlement_delay_days`، هر منطقی که به اسکلت قبلی وابسته یا معطوف باشد.

---

## ۱۱. آنچه از مالک لازم است

1. **مجوز اجرای فاز ۱ طبق همین طرح.** پس از آن برای تک‌تک فایل‌ها دوباره
   سؤال نمی‌شود.
2. **در اختیار گذاشتن `tecteb-marketplace-v0.1.1.zip` برای بررسی
   فقط‌خواندنی.** تعیین تبار (م-۱/م-۲/م-۳) **پس از** آن بررسی انجام می‌شود، نه
   الان؛ فعلاً هیچ گزینه‌ای انتخاب نشده. تا آن زمان: نسخه انتشار مقدار موقت
   طبق قاعده مخزن خالی پرامپت دارد (که انتخاب تبار نیست)، نسخه schema
   مستقل از آن `1` است، و هیچ دستوری درباره اسکلت قبلی قطعی نیست. این مورد
   scaffold را متوقف نمی‌کند، اما هر ادعای «قابل نصب کنار نصب فعلی» به آن
   وابسته است.

هیچ تصمیم تجاری دیگری لازم نیست. DEC-01..05 مانع این فاز نیستند.
