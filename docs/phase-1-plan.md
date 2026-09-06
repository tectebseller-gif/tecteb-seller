# طرح اجرای فاز ۱ — برای بررسی مالک

وضعیت: **طرح، قبل از کدنویسی.** هیچ فایل PHP افزونه هنوز ساخته نشده است.
مرجع: پرامپت فاز ۱ v0.2 · Master Spec v0.3 · UX Spec v0.2 (checksumها در
`docs/reference-checksums.txt`).

---

## ۱. ساختار فایل هدف

ساختار دقیقاً همان چیزی است که پرامپت نام برده. هیچ پوشه خالی برای وانمود
کردن تکمیل ماژول ساخته نمی‌شود.

```
tecteb-marketplace-core.php      metadata، ABSPATH guard، بررسی نسخه PHP
uninstall.php                    فقط guard + توضیح preservation؛ حذف نمی‌کند
src/
  Core/
    Autoloader.php               PSR-4 اختصاصی (ADR-002)
    Container.php                DI کوچک
    Plugin.php                   bootstrap
    Lifecycle/                   Activator، Deactivator، Requirements، MultisiteGuard
    Modules/                     ModuleLoader، ModuleRegistry، ModuleManifest، ModuleStatus
    Migration/                   MigrationRunner، MigrationLock، SchemaVersion، Migrations/
    Config/                      SettingsSchema، SettingsService، Sanitizer/
    Environment/                 EnvironmentResolver، OutboundPolicy
    Audit/                       AuditEvent، AuditEventSanitizer، AuditLogger
  Contracts/                     ModuleInterface، ClockInterface، AuditRepositoryInterface،
                                 SettingsStoreInterface، OtpProviderInterface، OtpResult
  Modules/
    Admin/                       AdminModule + Presentation/{MenuRegistrar,AssetLoader,
                                 Pages/,Views/,Components/} + Application/SettingsController
    Health/                      HealthModule + Application/HealthReportBuilder
                                 + Infrastructure/Rest/HealthController
  Infrastructure/
    WordPress/                   WpSettingsStore، WpAuditRepository، WpCapabilities،
                                 WpClock، HposDeclaration، WpEnvironmentProbe
    Otp/NullOtpProvider.php
assets/admin/                    tmc-admin.css، tmc-admin.rtl.css، tmc-admin.js
languages/                       tecteb-marketplace-core-fa_IR.po/.mo
tests/                           Unit/، Database/، WordPressContract/، Architecture/، Browser/
tools/                           build.sh، docx-to-markdown.py، wp-stubs/
docs/                            (موجود) + خروجی‌های تحویل فاز ۱
```

### مرز معماری که آزمون می‌شود
`src/Core/**` و `src/Contracts/**` حق فراخوانی هیچ تابع WP یا کلاس WC را
ندارند. این با یک تست خودکار (`tests/Architecture/`) بررسی می‌شود که AST هر
فایل را می‌خواند و فراخوانی توابع WP را رد می‌کند — نه با بازبینی چشمی.
دسترسی به WP فقط از `src/Infrastructure/WordPress/**` و لایه Presentation.

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

بارگذاری دو مرحله‌ای است: `register()` برای همه، سپس `boot()` به ترتیب
توپولوژیک. چرخه وابستگی، وابستگی گمشده، id تکراری و exception هنگام boot
هرکدام مسیر خطای مشخص خودشان را دارند؛ ماژول عملیاتی خراب `degraded`
می‌شود و بقیه بالا می‌آیند. `boot()` دوم hook تکراری نمی‌سازد (flag ایدمپوتنت).

---

## ۳. Persistence

### wp_options
| کلید | محتوا |
|---|---|
| `tmc_schema_version` | فقط **پس از** migration موفق نوشته می‌شود |
| `tmc_settings` | یک آرایه schema-versioned |
| `tmc_migration_lock` | قفل اتمی با `add_option` + timestamp انقضا |

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
`checked_at` ISO-8601 UTC). پاسخ `no-store`. هیچ path سرور، credential،
فهرست کاربر یا stack trace. `null` یعنی نامشخص و هرگز به `false` تبدیل
نمی‌شود. صفحه سلامت «HPOS فعال» و «HPOS آزموده‌شده» را **دو فیلد جدا**
نشان می‌دهد.

---

## ۶. پیکربندی (CORE-07)

| کلید | پیش‌فرض | قاعده |
|---|---|---|
| `default_commission_rate_bp` | `null` | ورودی decimal با حداکثر ۲ رقم اعشار → basis points صحیح ۰..۱۰۰۰۰. **بدون float.** `null` = «هنوز تعیین نشده»، `0` = بدون کمیسیون |
| `settlement_delay_days` | `4` | صحیح نامنفی، سقف فنی ۳۶۵ |
| `max_staff` | `10` | صحیح مثبت، سقف فنی ۱۰۰ |
| `environment_override` | `auto` | `auto` / `staging` / `production` — **قفل outbound را باز نمی‌کند** |

ارقام فارسی/عربی نرمال می‌شوند. مقدار نامعتبر **reject** می‌شود و مقدار قبلی
دست‌نخورده می‌ماند. فعال‌سازی دوباره مقدار کاربر را به پیش‌فرض برنمی‌گرداند.

---

## ۷. ماتریس آزمون — با وضعیت واقعی، نه آرزو

محدودیت‌ها در `docs/environment-inventory.md` اندازه‌گیری شده‌اند.

| لایه | ابزار | وضعیت در این محیط |
|---|---|---|
| ۱. Unit خالص (Domain بدون WP) | PHPUnit | ✅ **قابل اجرا** |
| ۲. معماری (رد فراخوانی WP در Core) | تست AST خودکار | ✅ **قابل اجرا** |
| ۳. Migration روی MySQL واقعی | MariaDB 10.11 از apt | ✅ **قابل اجرا** |
| ۴. قرارداد WP با stub | PHPUnit + `tools/wp-stubs/` | ⚠️ اجرا می‌شود ولی **معادل integration واقعی نیست** و جدا گزارش می‌شود |
| ۵. lint با PHP 8.4 | `php -l` | ✅ **قابل اجرا** |
| ۶. سازگاری با PHP 8.1 | PHPCompatibility `testVersion 8.1` | ⚠️ شاهد **ایستا**؛ اجرای واقعی روی 8.1 `Not Run` |
| ۷. رندر view + دسترس‌پذیری | Playwright + axe روی harness | ⚠️ شاهد جزئی؛ **wp-admin واقعی نیست** |
| ۸. نصب/فعال‌سازی واقعی WordPress | — | ❌ **Not Run** — WP core قابل دانلود نیست |
| ۹. WP/WC integration، HPOS on/off/sync | — | ❌ **Not Run** — WC قابل دانلود نیست |
| ۱۰. Screen reader دستی | — | ❌ **Not Run** |

هر CORE-ID به فایل، نام تست و نتیجه وصل می‌شود. تستی که فقط stub خودش را
تأیید کند به‌عنوان شاهد قبولی شمرده نمی‌شود.

### پیامد قراردادی که باید از الان روشن باشد
گیت نصب Staging نیاز به «نصب/فعال‌سازی واقعی» دارد. چون ردیف ۸ در این محیط
اجراشدنی نیست، ZIP فاز ۱ با برچسب **«unverified — نصب نشود»** تحویل می‌شود.
source، تست‌ها و گزارش برای بررسی قابل تحویل‌اند. برای رسیدن به وضعیت
«قابل نصب» یکی از دو راه لازم است:

1. اجازه دسترسی خروجی به `downloads.wordpress.org` در محیط، یا
2. اجرای دستورهای مستندشده در `docs/installation.md` روی یک WordPress
   disposable در محیط خودتان و برگشت خروجی.

---

## ۸. بسته‌بندی

`tools/build.sh` از سورس مشخص و تکرارپذیر:

- `dist/tecteb-marketplace-core.zip` — **یک** پوشه `tecteb-marketplace-core`
  با main file داخل آن.
- خارج از ZIP نصب: `.git`, `.env`, `docs/*.docx`, `tests/`, `tools/`,
  dev vendor، backup، هر فایل تجاری مرجع.
- `dist/SHA256SUMS`.
- source archive جدا شامل `tests/`, `docs/`, lockfile و `tools/build`.
- HPOS با `FeaturesUtil::declare_compatibility` در `before_woocommerce_init`
  با guard وجود کلاس — و این declaration **ادعای تست‌شدن نیست**.
- runtime بدون Composer و بدون Node (ADR-002).

---

## ۹. تحویل‌های مستند فاز ۱

`phase-1-report.md` (CORE-ID → فایل → test → evidence → Passed/Failed/Not Run)،
`architecture.md` + ADRها، `compatibility-matrix.md`، `installation.md`
(rollback فقط فاز ۱)، `public-contracts.md`، `next-phase-handoff.md`،
و تصاویر چهار صفحه با داده مصنوعی. `decision-log.md` و
`environment-inventory.md` قبلاً commit شده‌اند.

---

## ۱۰. آنچه ساخته نمی‌شود

Vendor، Staff، Product، فرم‌ساز پزشکی، Order، Commission، Withdrawal،
Refund، Coupon، B2B، Ticket، SEO، importer دکان، auth واقعی، هر sender یا
gateway واقعی، منوی فروشنده، route دکان، آمار تجاری ساختگی، صفحه مالی،
retention job، UI exporter لاگ.

---

## ۱۱. آنچه از مالک لازم است

**هیچ تصمیم تجاری جدیدی لازم نیست.** DEC-01..DEC-06 هیچ‌کدام مانع این فاز
نیستند (`docs/decision-log.md`). تعارض هویتی سورس قبلی هم وجود ندارد چون
مخزن خالی بود.

تنها چیز موردنیاز: **مجوز اجرای فاز ۱ طبق همین طرح.** پس از آن برای تک‌تک
فایل‌ها دوباره سؤال نمی‌شود.

اگر با فرض‌های فنی F-01..F-05 در `docs/decision-log.md` مخالفید (مثلاً سقف
فنی `max_staff` یا انتخاب autoloader اختصاصی به‌جای Composer)، همین حالا
بگویید؛ تغییرشان بعداً پرهزینه‌تر است.
