# معماری فاز ۱

مرجع: سند مادر §۱۱ و §۲۲ (ARCH-01) · پرامپت فاز ۱ «ساختار و مرزبندی».

## ۱. لایه‌ها و جهت وابستگی

```
        ┌──────────────────────────── WordPress / WooCommerce ─┐
        │                                                       │
        ▼                                                       │
  Infrastructure/WordPress            Modules/*/Infrastructure  │
  (Bootstrap, Wp*Store, Wp*Probe,     (HealthHooks, REST,       │
   Lifecycle, Notices, HPOS)           SettingsRegistrar)       │
        │                                     │                 │
        │        Modules/*/Presentation ──────┤                 │
        │        (Pages, Views, Assets)       │                 │
        ▼                                     ▼                 │
  ┌──────────────────────────────────────────────────┐          │
  │  Modules/*/Application   (HealthReportBuilder,   │          │
  │                           SettingsSubmission)    │          │
  ├──────────────────────────────────────────────────┤          │
  │  Core  (Config, Migration, Modules, Environment, │          │
  │         Audit, Support, Container, Autoloader)   │          │
  ├──────────────────────────────────────────────────┤          │
  │  Contracts  (interfaces, enums, value objects)   │          │
  └──────────────────────────────────────────────────┘          │
        ▲                                                       │
        └── هیچ فلشی از این جعبه به بیرون نمی‌رود ───────────────┘
```

- **Contracts** و **Core** و **Application**: فقط PHP خالص. هیچ تابع
  WordPress، هیچ کلاس WooCommerce، هیچ `$wpdb`، هیچ `$GLOBALS`.
- **Infrastructure** و **Presentation**: تنها جایی که WordPress صدا زده می‌شود.
- ارتباط از طریق interfaceهای `Contracts` است؛ `Bootstrap` تنها نقطه‌ای است که
  پیاده‌سازی وردپرسی را به آن interfaceها می‌بندد.

## ۲. آنچه این مرز را تضمین می‌کند — و آنچه نمی‌کند

`tests/Architecture/ArchitectureRulesTest.php` هر فایل لایه خالص را
توکن‌به‌توکن می‌خواند و هر ارجاع به تابع/کلاس/ثابت/global بیرون از «PHP
داخلی + namespace خود افزونه» را رد می‌کند.

**این آزمون چه چیزی را ثابت می‌کند:** هیچ فایلی در `src/Core`،
`src/Contracts` و `Modules/*/Application` نمی‌تواند WordPress یا WooCommerce را
صدا بزند، چون نمادهایشان نه PHP داخلی‌اند و نه در namespace ما.

**چه چیزی را ثابت نمی‌کند:** استقلال کامل معماری. یک کلاس خالص همچنان
می‌تواند فرض‌های وردپرسی را در ساختار داده‌اش رمزگذاری کند. این شاهدِ همان
قاعده محدود است، نه بیشتر.

**اینکه خود آزمون کار می‌کند** با `testScannerActuallyDetectsViolations`
اثبات می‌شود: فایلی که عمداً `get_option()`، `$wpdb`، `$GLOBALS`، `\WC_Order`
و `ABSPATH` دارد به اسکنر داده می‌شود و هر پنج مورد باید گزارش شوند. نسخه اول
اسکنر همه فراخوانی‌های تابع را بی‌صدا رد می‌کرد (نام غیرمقید در فضای نام ما
حل می‌شد) و دقیقاً همین آزمون آن را گرفت.

## ۳. چرخه عمر یک request

```
plugins_loaded
  └─ Bootstrap::onPluginsLoaded()        (یک بار؛ flag ایدمپوتنت)
       ├─ load_plugin_textdomain()
       ├─ DependencyProbe → WooCommerce هست؟
       │    └─ نه → Notices::registerWooCommerceMissing()
       ├─ Notices::registerActivationResult()
       └─ Kernel::load($wooCommerceAvailable)
            ├─ فاز ۰: planned / وابستگی گمشده / چرخه   → Blocked یا Planned
            ├─ فاز ۱: register() به ترتیب توپولوژیک
            └─ فاز ۲: boot()   به همان ترتیب
```

`register()` فقط binding می‌سازد؛ hookها در `boot()` وصل می‌شوند. به همین دلیل
ماژولی که در زمان boot متوقف شده باشد هیچ رفتار زنده‌ای ندارد، حتی اگر
bindingهایش ثبت شده باشد.

## ۴. مهار خرابی ماژول

| رخداد | وضعیت خودش | وضعیت وابسته‌ها | ماژول‌های مستقل |
|---|---|---|---|
| `register()` استثنا داد | `degraded` (phase=register) | `blocked` — **اجرا نمی‌شوند** | ادامه می‌دهند |
| `boot()` استثنا داد | `degraded` (phase=boot) | `blocked` (phase=boot) | ادامه می‌دهند |
| وابستگی وجود ندارد | `blocked` | به‌صورت زنجیره‌ای `blocked` | ادامه می‌دهند |
| چرخه وابستگی | `blocked` + مسیر چرخه | — | ادامه می‌دهند |
| نیازمند WooCommerce و نبودنش | `blocked` | زنجیره‌ای | ادامه می‌دهند |

علت هر توقف با کد ماشینی، مرحله و نام وابستگی خراب ثبت و در صفحه‌های سلامت و
ماژول‌ها به فارسی نمایش داده می‌شود. متن استثنا به **یک خط بدون stack trace**
کوتاه می‌شود.

## ۵. نسخه‌گذاری دوگانه

نسخه انتشار افزونه (رشته semver در header) و نسخه schema دیتابیس (عدد صحیح)
دو چیز مستقل‌اند. `MigrationRunner` فقط وقتی `stored < SCHEMA_VERSION` است کاری
می‌کند؛ ارتقای نسخه انتشار بدون تغییر ساختار **هیچ migrationی اجرا نمی‌کند و
حتی قفل هم نمی‌گیرد**. (`MigrationRunnerTest::testReleaseUpgradeWithoutStructuralChangeRunsNothing`)

## ۶. قفل migration

```
acquire():
  INSERT IGNORE ──موفق──► قفل در اختیار ماست
        │
      ناموفق
        ▼
  خواندن مقدار فعلی
        ├─ منقضی/خراب → UPDATE ... WHERE option_value = «همان مقدار دیده‌شده»
        │                 (تصاحب اتمی و مشروط)
        └─ زنده        → شکست

release(): DELETE ... WHERE option_value = «مقدار خودِ ما»
refresh(): UPDATE ... WHERE option_value = «مقدار خودِ ما»
```

هر عملیات مشروط به **مقدار دقیق مالک** است. بنابراین اجرای قدیمی نه می‌تواند
قفلی را که اجرای جدید تصاحب کرده حذف کند و نه تمدیدش کند. روی MariaDB واقعی با
هشت فرایند forkشده آزموده شد: دقیقاً یکی برنده می‌شود.

## ۷. تبدیل درصد

```
فرم:      "۱۲٫۳۴"  ──DigitNormalizer──►  "12.34"
                    ──PercentToBasisPoints──►  1234 (int)   ← ذخیره می‌شود
نمایش:    1234     ──BasisPoints::toPercentString──►  "12.34"
```

کلید فرم (`default_commission_rate`) و کلید ذخیره‌شده
(`default_commission_rate_bp`) عمداً متفاوت‌اند و تجزیه‌کننده هرگز مقدار
ذخیره‌شده را نمی‌بیند؛ به همین دلیل ذخیره دوباره نمی‌تواند تبدیل دوباره بسازد.
هیچ محاسبه اعشاری در مسیر نیست: تبدیل با حساب صحیح انجام می‌شود و `float`
ورودی عمداً reject می‌شود.

## ۸. انتقال‌پذیری

لایه‌های خالص بدون bootstrap وردپرس تست می‌شوند و همین قابلیت استفاده مجدد
قواعد دامنه را نشان می‌دهد. این **به معنی اجرای مستقیم افزونه روی Laravel
نیست** (سند مادر §۲۲)؛ مقصد دیگر به Adapter و frontend خودش نیاز دارد.

## ۹. ADRها

`docs/adr/` — ADR-001 (autoloader)، ADR-002 (بارگذاری دومرحله‌ای)،
ADR-003 (نسخه‌گذاری و migration)، ADR-004 (ذخیره تنظیمات)،
ADR-005 (لایه stub وردپرس در آزمون).
