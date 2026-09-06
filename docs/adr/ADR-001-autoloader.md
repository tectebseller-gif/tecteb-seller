# ADR-001 — autoloader اختصاصی PSR-4 محدود به namespace افزونه

وضعیت: **پذیرفته‌شده** · تاریخ: ۶ سپتامبر ۲۰۲۶ · مرتبط: F-05 در
`docs/decision-log.md`، CORE-10، سند مادر A.6 و COMP-01.

## زمینه

- CORE-10: «runtime Composer/Node اجباری نباشد؛ dependencyهای لازم با مجوز و
  lockfile در source باشند.»
- سند مادر A.6: «نسخه نهایی یک ZIP قابل نصب بدون نیاز runtime به Node،
  Composer یا SSH خواهد بود.»
- COMP-01: «وابستگی مشترک مستعد برخورد namespace باید ایزوله شود.»
- پرامپت، «ساختار و مرزبندی»: «از PSR-4 autoload قطعی و dependency injection
  استفاده کن. Composer autoload بسته‌بندی‌شده یا autoloader کوچک مجاز است؛
  انتخاب و دلیل در ADR.»
- در فاز ۱ افزونه **هیچ وابستگی PHP زمان اجرا** ندارد. Composer فقط برای
  ابزار توسعه (PHPUnit، PHPCompatibility) استفاده می‌شود.

## گزینه‌ها

| گزینه | شرح | ملاحظه |
|---|---|---|
| A — بسته‌بندی autoloader تولیدی Composer | `vendor/autoload.php` + `vendor/composer/ClassLoader.php` + فایل‌های نگاشت داخل ZIP | استاندارد و آشنا؛ اما در فاز ۱ **تنها** محتوای `vendor/` همین loader خواهد بود (~۶۰۰ خط + چند فایل نگاشت) و کلاس سراسری `Composer\Autoload\ClassLoader` را وارد فضای سایت می‌کند که ده‌ها افزونه دیگر هم نسخه‌های متفاوتش را بسته‌بندی می‌کنند. Composer برای این هم‌زیستی طراحی شده، ولی «چند نسخه بسته‌بندی‌شده» دقیقاً همان کلاس ریسکی است که COMP-01 می‌گوید ایزوله شود، و در فاز ۱ هیچ فایده‌ای در برابر آن نمی‌دهد |
| **B — autoloader کوچک اختصاصی** | یک کلاس ~۵۰ خطی PSR-4 که **فقط** به پیشوند `Tecteb\Marketplace\` پاسخ می‌دهد و آن را به `src/` نگاشت می‌کند | صفر وابستگی، صفر کلاس سراسری، رفتار کاملاً قابل آزمون، بدون اثر بر autoloaderهای دیگر. هزینه: باید خودمان درست بودنش را با تست ثابت کنیم |
| C — `require` دستی فایل‌ها | فهرست ثابت require در main file | با رشد ماژول‌ها شکننده است؛ ترتیب بارگذاری دستی؛ رد می‌شود |

## تصمیم

**گزینه B.** یک autoloader اختصاصی در `src/Core/Autoloader.php`.

## محدوده و قیود (چیزهایی که autoloader *باید* رعایت کند)

1. **فقط namespace افزونه.** تطبیق پیشوند دقیق `Tecteb\Marketplace\`. هر
   کلاس با پیشوند دیگر: بازگشت فوری بدون هیچ عملی (`return`)، تا
   autoloaderهای دیگر زنجیره را ادامه دهند. هرگز برای namespace دیگران
   فایل جست‌وجو نمی‌کند.
2. **PSR-4 قطعی.** `Tecteb\Marketplace\Modules\Health\HealthModule` →
   `src/Modules/Health/HealthModule.php`. بدون classmap دستی، بدون
   fallback جادویی، بدون case-insensitivity.
3. **بدون خطا برای کلاس ناموجود در پیشوند خودی.** اگر فایل وجود ندارد، فقط
   `return`؛ خطای «class not found» را PHP در محل استفاده می‌دهد، نه
   autoloader. این جلوی fatal در مسیر بارگذاری را می‌گیرد.
4. **ثبت ایدمپوتنت.** `register()` با guard ایستا؛ فراخوانی دوباره
   (مثلاً از activation hook و سپس `plugins_loaded`) دو بار
   `spl_autoload_register` نمی‌کند.
5. **بدون prepend.** `spl_autoload_register($fn, true, false)` — انتهای
   زنجیره، نه ابتدای آن؛ در ترتیب autoloaderهای دیگر دخالت نمی‌کند.
6. **بدون وابستگی به WP.** کلاس autoloader خودش هیچ تابع WordPress صدا
   نمی‌زند تا در تست خالص و در خود main file (پیش از bootstrap WP-وابسته)
   قابل استفاده باشد.
7. **سازگار با PHP 8.1** (نحو و API)؛ فایل main پیش از `require` آن،
   نسخه PHP را بررسی می‌کند (CORE-01).

## آزمون (به‌عنوان بخشی از گیت فاز ۱؛ نه ادعای قبلی)

| # | آزمون | نوع | نتیجه مورد انتظار |
|---|---|---|---|
| T1 | نگاشت کلاس خودی به مسیر: `Tecteb\Marketplace\Core\Container` → `src/Core/Container.php` | unit خالص | مسیر دقیق برابر |
| T2 | پیشوند بیگانه (`Composer\Autoload\ClassLoader`، `WC_Order`، `Dokan\Foo`) | unit خالص | هیچ فایلی بررسی/بارگذاری نمی‌شود؛ بازگشت `null`/no-op |
| T3 | کلاس خودی بدون فایل (`Tecteb\Marketplace\Nope`) | unit خالص | بدون exception، بدون warning، بدون `require` |
| T4 | ثبت دوباره `register()` | unit خالص | `spl_autoload_functions()` فقط **یک** ورودی از این autoloader دارد |
| T5 | **انطباق PSR-4 کل `src/`**: هر فایل PHP دقیقاً یک class/interface/trait دارد و `namespace + name` آن با مسیر فایل منطبق است | تست معماری (AST) | صفر مغایرت. این تست خطای تایپی مسیر/نام را که در production به fatal تبدیل می‌شود، در CI می‌گیرد |
| T6 | بارگذاری واقعی همه کلاس‌های `src/` از طریق autoloader (`class_exists($fqcn, true)` برای هر فایل) | unit خالص | همه `true`؛ هیچ fatal |
| T7 | lint همه فایل‌ها با PHP موجود + PHPCompatibility با `testVersion 8.1` | ایستا | صفر خطا (اجرای واقعی روی PHP 8.1: Not Run — `docs/environment-inventory.md`) |

نتیجه این آزمون‌ها با دستور، exit code و log در `docs/phase-1-report.md`
ثبت می‌شود. تا اجرا و ثبت، این ADR «تصمیم» است نه «شاهد».

## پیامدها

- ZIP نصب هیچ `vendor/` ندارد. `composer.json` و `composer.lock` در source
  archive می‌مانند (CORE-10).
- هر ماژول جدید فقط با رعایت PSR-4 در `src/` قابل بارگذاری است؛ T5 این را
  اجباری می‌کند.
- اگر روزی کتابخانه شخص ثالث لازم شد، این ADR **کافی نیست** (بند بعد).

## شرط بازنگری

اگر فازی وابستگی PHP زمان اجرا (کتابخانه شخص ثالث) اضافه کند، این ADR
**superseded** می‌شود و ADR جدیدی باید: (۱) autoloader تولیدی Composer با
`--classmap-authoritative` را بسته‌بندی کند و (۲) کتابخانه‌ها را با ابزار
prefix (مثل PHP-Scoper یا Strauss) در namespace افزونه ایزوله کند تا COMP-01
رعایت شود. تا آن زمان، افزودن `vendor/` به ZIP بدون ADR جدید مجاز نیست.
