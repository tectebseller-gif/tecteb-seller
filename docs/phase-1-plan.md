# طرح اجرای فاز ۱ — برای بررسی مالک

بازبینی ۲ · ۶ سپتامبر ۲۰۲۶ · وضعیت: **طرح، قبل از کدنویسی.** هیچ فایل PHP
افزونه ساخته نشده و پیاده‌سازی شروع نشده است.

مرجع: پرامپت فاز ۱ v0.2 · Master Spec v0.3 · UX Spec v0.2 (checksumها در
`docs/reference-checksums.txt`؛ فیدلیتی نسخه Markdown در
`docs/generated/EXTRACTION-FIDELITY.md`).

### تغییرات بازبینی ۲ نسبت به بازبینی ۱ (طبق بازخورد مالک)
1. DEC-06 به پنج جزء شکسته شد؛ فقط شناسایی محیط توسعه «انجام‌شده» است،
   تطبیق محیط سایت و آزمون سازگاری «باز — Not Run» هستند (`decision-log.md` بند ۲).
2. ادعای «هیچ سورس قبلی وجود ندارد» پس گرفته شد. وضعیت
   `tecteb-marketplace-v0.1.1.zip`: **تعیین‌نشده** (`decision-log.md` بند ۴).
3. `max_staff`: پیش‌فرض ۱۰ مصوب است؛ سقف فنی قانون تجاری نیست؛ محل تعریف و
   نحوه تغییر مشخص شد (§۶ و F-02).
4. autoloader: دلیل، محدودبودن به namespace افزونه و آزمون آن در
   `docs/adr/ADR-001-autoloader.md`.
5. فیدلیتی استخراج DOCX با تطبیق ترتیبی بند/سلول و فهرست صریح اختلاف‌ها
   مستند شد؛ استخراج‌کننده برای فهرست‌ها و سرفصل‌های با قالب‌بندی مستقیم
   اصلاح و دوباره تطبیق داده شد.
6. قاعده `Not Run` سخت شد؛ تصاویر خارج از WordPress فقط «نمونه رابط»
   محسوب می‌شوند (§۷).

---

## ۰. پیش‌شرط‌های هویتی که هنوز بسته نیستند

| مورد | وضعیت | اثر |
|---|---|---|
| سورس قبلی `tecteb-marketplace-v0.1.1.zip` | **تعیین‌نشده** | نسخه شروع (F-01) و ادعای «قابل نصب کنار سایت فعلی» به آن وابسته‌اند؛ scaffold در مخزن وابسته نیست |
| تطبیق محیط سایت (DEC-06-b) | باز — Not Run | ماتریس سازگاری تا اجرای واقعی خالی از `Passed` می‌ماند |
| آزمون سازگاری (DEC-06-c) | باز — Not Run | همان |
| Multisite (DEC-06-e) | بسته: پشتیبانی نمی‌شود | رد network activation با پیام فارسی |

---

## ۱. ساختار فایل هدف

دقیقاً همان ساختاری که پرامپت نام برده. هیچ پوشه خالی برای وانمود کردن
تکمیل ماژول ساخته نمی‌شود.

```
tecteb-marketplace-core.php      metadata، ABSPATH guard، بررسی نسخه PHP پیش از require
uninstall.php                    فقط guard + توضیح preservation؛ حذف نمی‌کند
src/
  Core/
    Autoloader.php               PSR-4 اختصاصی محدود به Tecteb\Marketplace\ (ADR-001)
    Container.php                DI کوچک
    Plugin.php                   bootstrap
    Lifecycle/                   Activator، Deactivator، Requirements، MultisiteGuard
    Modules/                     ModuleLoader، ModuleRegistry، ModuleManifest، ModuleStatus
    Migration/                   MigrationRunner، MigrationLock، SchemaVersion، Migrations/
    Config/                      SettingsSchema (ثابت‌های سقف فنی اینجا)، SettingsService، Sanitizer/
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

## ۳. Persistence

### wp_options
| کلید | محتوا |
|---|---|
| `tmc_schema_version` | فقط **پس از** migration موفق نوشته می‌شود |
| `tmc_settings` | یک آرایه schema-versioned |
| `tmc_migration_lock` | قفل اتمی با `add_option` + timestamp انقضا و بازیابی قفل منقضی |

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
`checked_at` ISO-8601 UTC). پاسخ `no-store`. هیچ path سرور، credential،
فهرست کاربر یا stack trace. `null` یعنی نامشخص و هرگز به `false` تبدیل
نمی‌شود. صفحه سلامت «HPOS فعال» و «HPOS آزموده‌شده» را **دو فیلد جدا**
نشان می‌دهد؛ دومی تا اجرای ماتریس سازگاری `unknown` است.

---

## ۶. پیکربندی (CORE-07)

| کلید | پیش‌فرض | ماهیت پیش‌فرض | قاعده اعتبارسنجی |
|---|---|---|---|
| `default_commission_rate_bp` | `null` | مصوب (FIN-02، UX-01) | decimal با حداکثر ۲ رقم اعشار → basis points صحیح ۰..۱۰۰۰۰؛ **بدون float**؛ `null` = «هنوز تعیین نشده»، `0` = «بدون کمیسیون» |
| `settlement_delay_days` | `4` | **مصوب** (سند مادر ۸.۳/A.2) | صحیح نامنفی؛ سقف فنی اولیه ۳۶۵ |
| `max_staff` | `10` | **مصوب** (سند مادر A.4، CORE-07) | صحیح مثبت؛ سقف فنی اولیه ۱۰۰ |
| `environment_override` | `auto` | — | `auto` / `staging` / `production` — **قفل outbound را باز نمی‌کند** |

### درباره سقف‌های فنی (F-02، F-03)
- **قانون تجاری نیستند** و در هیچ متن کاربری «حداکثر مجاز کسب‌وکار» خوانده
  نمی‌شوند. فقط ورودی نامعقول را رد می‌کنند (CORE-07: «سقف فنی مستند، نه
  قانون تجاری مخفی»).
- **محل تعریف:** ثابت‌های نام‌دار در `src/Core/Config/SettingsSchema.php` با
  docblock «حد اعتبارسنجی فنی؛ قاعده تجاری نیست»، و ثبت در
  `docs/public-contracts.md`.
- **تغییر بعدی:** ویرایش همان ثابت در یک انتشار نسخه‌دار طبق بخش ۲۰ سند مادر.
  در فاز ۱ فیلتر عمومی برایش ساخته نمی‌شود.
- **پیش‌فرض ۱۰ و ۴** خودشان از صفحه تنظیمات و توسط مدیرکل (با
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
| ۳. Migration روی MySQL واقعی | MariaDB 10.11 از apt | ✅ قابل اجرا — **معادل نصب WP نیست** |
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

---

## ۹. تحویل‌های مستند فاز ۱

| سند | وضعیت |
|---|---|
| `decision-log.md`، `environment-inventory.md`، `compatibility-matrix.md`، `adr/ADR-001` | ✅ نوشته شده (پیش از کد) |
| `generated/EXTRACTION-FIDELITY.md` | ✅ نوشته شده |
| `phase-1-report.md` (CORE-ID → فایل → test → evidence → Passed/Failed/Not Run) | پس از پیاده‌سازی |
| `architecture.md` + ADR-002..004 | همراه پیاده‌سازی |
| `installation.md` (rollback فقط فاز ۱) | پس از پیاده‌سازی |
| `public-contracts.md` (option/capability/table/hook/route/schema) | همراه پیاده‌سازی |
| تصاویر چهار صفحه با داده مصنوعی — **برچسب «نمونه رابط»** | پس از پیاده‌سازی |
| `next-phase-handoff.md` | پایان فاز |

---

## ۱۰. آنچه ساخته نمی‌شود

Vendor، Staff، Product، فرم‌ساز پزشکی، Order، Commission، Withdrawal،
Refund، Coupon، B2B، Ticket، SEO، importer دکان، auth واقعی، هر sender یا
gateway واقعی، منوی فروشنده، route دکان، آمار تجاری ساختگی، صفحه مالی،
retention job، UI exporter لاگ، فیلتر عمومی برای سقف‌های فنی.

---

## ۱۱. آنچه از مالک لازم است

1. **مجوز اجرای فاز ۱ طبق همین طرح.** پس از آن برای تک‌تک فایل‌ها دوباره
   سؤال نمی‌شود.
2. **یک تصمیم هویتی (DEC-06-d):** آیا `tecteb-marketplace-v0.1.1.zip`
   باید دریافت و فقط‌خواندنی بررسی شود، و کدام گزینه م-۱/م-۲/م-۳ در
   `decision-log.md` بند ۴ مبناست؟ این تصمیم scaffold را متوقف نمی‌کند، اما
   نسخه شروع (F-01) و هر ادعای «قابل نصب کنار نصب فعلی» به آن وابسته است.
   تا پاسخ، «تعیین‌نشده» می‌ماند.

هیچ تصمیم تجاری دیگری لازم نیست. DEC-01..05 مانع این فاز نیستند.
