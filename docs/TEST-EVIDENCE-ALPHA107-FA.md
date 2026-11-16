# شواهد آزمون — `0.1.0-alpha.107`

⛔ **هر چیزی که اجرا نشده `Not Run` اعلام می‌شود، نه PASS.** بخش ۵ همین سند
فهرست کاملش است.

⛔ **و هیچ آزمونی روی staging یا تولید اجرا نشد.** هر آزمون نوشتنی در
دیتابیس یکبارمصرف `tmc_test` و روی فیکسچر ساختگی اجرا شده، و هیچ شناسهٔ
واقعی — به‌ویژه #۱۱۹۵۵، #۱۱۹۶۵، #۱۱۹۶۶ و #۱۱۹۶۷ — در هیچ فیکسچری نیست.

---

## ۱. پنج سوییت، روی دو مفسر

| سوییت | ۸٫۳٫۶ | ۸٫۱٫۳۴ واقعی |
|---|---|---|
| unit | **۹۵۲** (۶۰۶٬۴۶۳) | **۹۵۲** (۶۰۶٬۴۶۳) |
| architecture | **۳۶۸** (۷٬۲۸۴) | **۳۶۸** (۷٬۲۸۴) |
| contract | **۵۰۲** (۲٬۹۵۲) | **۵۰۲** (۲٬۹۵۰) + **۲ skip با نام** |
| database | **۱۳۲۷** (۱۵٬۲۰۱) | **۱۳۲۷** (۱۵٬۲۰۱) |
| packaging | **۳۲** (۵۲٬۷۶۳) | ⛔ `Not Run` |

همه **۰ شکست**. سوییت database **تنها** دوانده شد، و پیش از هر اجرا
`pgrep -af phpunit` و شمارش اتصال‌های `tmc_test` و جدول‌های `%_away` خوانده
شد (`alpha.40` · `alpha.98` · `alpha.105`).

**مفسر ۸٫۱٫۳۴ واقعاً اجرا شد**، از ایمیج `php:8.1.34-cli` با `pdo_mysql` و
`pcntl`، با `--network host` روی همان MariaDB.
⛔ **دو skip با نام‌اند** و علتشان نبودِ `intl` در ایمیج است؛ خودِ predicate
در **هر دو جهت** assert می‌شود (`alpha.104`)، پس skip ای که پهن‌تر شود
آزمون خودش را می‌شکند.
⛔ **سوییت packaging روی ۸٫۱٫۳۴ `Not Run` است، با علتِ نام‌برده:** ایمیج
`git`/`zip`/`unzip` ندارد و آن سوییت **بیلد** را می‌سنجد نه مفسر را.

## ۲. بیرون از phpunit

| اندازه‌گیری | نتیجه |
|---|---|
| `php -l` روی **۶۸۳** فایل با ۸٫۳٫۶ | ۰ شکست |
| `php -l` روی **۶۸۳** فایل با **۸٫۱٫۳۴ واقعی** (داکر) | ۰ شکست |
| PHPCompatibility، `testVersion 8.1-`، روی همان ۶۸۳ | ۰ خطا |
| `tools/contrast.php` | **۳۲ جفت**، ۰ شکست |
| `tools/browser/alpha107-header.mjs` | **۵۳ بررسی**، ۰ شکست |
| falsification | **۱۵ جهش، همه falsify** — سه‌تا در پاس دوم |

⛔ **و `lint.sh` خودش می‌گوید که روی میزبان ۸٫۱ نیست:**
`NOT RUN on /opt/php81/bin/php — interpreter is not installed. This is NOT a
pass.` — عددِ ۸٫۱ از داکر می‌آید، نه از آن اسکریپت.

## ۳. آزمون‌های تازهٔ این دور، به تفکیک بند

| بند | فایل | سوییت | تعداد |
|---|---|---|---|
| ۱ | `VendorPagerAddressTest` | contract | ۶ |
| ۱ | `VendorProductPagingTest` | database | ۷ |
| ۱ | `VendorAreaOwnsItsQueryVarsTest` | architecture | ۲ (هر دو جهت) |
| ۲ | `StorefrontImageFallbackTest` | database | ۷ |
| ۲ | `StorefrontImageReaderIsReadOnlyTest` | architecture | ۳ (از بایت‌های **هر دو** پیاده‌سازی) |
| ۳ | `StorefrontVariationEditTest` | database | ۱۴ |
| ۳ | `StorefrontVariationWriteTest` | contract | ۱۵ |
| ۳ | `ShopOwnedVariationDoorsAreExclusiveTest` | unit | ۱۰ |
| ۳ | `ImportedVariationsAreReadOnlyTest` | contract | گسترش‌یافته |
| ۴ | `VendorBuiltVariationsTest` | contract | ۷ (۶۷ assertion) |
| ۴ | `VariationFlowTest` | database | +۱ ردیف: هر پنج فعل روی محصول **ساده** رد می‌شود |
| ۵ | `BrandTaxonomyTest` | unit | ۹ (۲۲ assertion) |
| ۵ | `BrandListSourceTest` | contract | ۱۱ (۲۷ assertion) |
| ۶ | `WholesaleTiersMovedTest` | contract | ۱۰ |
| ۶ | `ProductFormPostTest` | database | +۲ ردیف از مسیر **واقعی** router |
| ۷ | `StaffAccountIntegrityTest` | database | ۷ (۴۶ assertion) |
| ۷ | `StaffDirectoryTest` | contract | ۱۳ (۲۵ assertion) |
| ۷ | `StaffAccountRemovalIsReadBackTest` | architecture | ۳ (۱۳ assertion) |
| ۸ | `VendorHeaderTest` | contract | ۱۰ (۴۳ assertion) |
| ۸ | `VendorHeaderAccountIsGivenToItTest` | architecture | ۴ |

**جمع: ۱۳۸ آزمون در این جدول، از **شانزده** فایل آزمونِ کلاً تازه و نُه فایلِ گسترش‌یافته** (به‌علاوهٔ یک بدلِ تازه و دو بدلِ گسترش‌یافته زیر `tests/Support/`).

⛔ **و سه مورد در `architecture` یا `unit` نشستند نه در رفتار، با دلیل:**
`StorefrontImageReaderIsReadOnlyTest` از **بایت‌ها** می‌پرسد چون «هیچ فعل
نوشتنی ندارد» را فقط نبودِ آن می‌تواند اثبات کند ·
`StaffAccountRemovalIsReadBackTest` در **سورس** assert می‌کند چون جهشی که
بازخوانی را بردارد در فیکسچرِ **سالم** قابل‌رسیدن نیست —
`wp_delete_user()` فقط برای کاربری که وجود ندارد `false` می‌دهد و آن را
همان متد دو statement بالاتر رد کرده (`alpha.105`) ·
`VendorHeaderAccountIsGivenToItTest` سیم‌کشی را pin می‌کند چون `if (false)`
متنِ فراخوان را سر جایش نگه می‌دارد (`alpha.79`).

## ۴. falsification — پانزده جهش

روی درختِ **آرام**، با بازگرداندن **بایت‌به‌بایت** و بازگرداندن در یک `trap`
(`alpha.106`): `docs/evidence/alpha107-falsification/falsification.txt`.

```
bash tools/alpha107-falsification.sh docs/evidence/alpha107-falsification [start]
bash tools/alpha107-remeasure.sh     docs/evidence/alpha107-falsification 11 12 14
python3 -I tools/alpha107-mutations.py count|name <n>|file <n>|expect <n>|apply <n>
```

⛔ **سه جهش در پاس اول جان سالم بردند، و هر سه جهشِ ضعیف بودند نه حفرهٔ
کد** — که خودش قاعدهٔ `alpha.82` است، و هر سه بازنویسی شدند:

| جهش | چرا جان سالم برد | بازنویسی |
|---|---|---|
| M11 «هدر بلند برگردد» | ارتفاع **دو** علت دارد (padding و پشته‌شدنِ نام/نقش)؛ برگرداندن **یکی** باز هم کمتر از ۲۰۶px اندازه می‌گرفت | هر دو را برمی‌گرداند |
| M12 «برچسب منو بشکند» | برداشتنِ `nowrap` روی یک ستونِ **تمام‌عرض** هیچ عوض نمی‌کند؛ علت گریدِ **دو‌ستونه** بود | گرید **و** `nowrap`، با هم |
| M14 «صلیبِ نشان سفید شود» | `tools/contrast.php` جفت‌های hex را **pin** می‌کند و شیوه‌نامه را نمی‌خواند (`alpha.92`) | به یک بررسی مرورگری منتقل شد که رنگِ **محاسبه‌شدهٔ** `::before` را می‌خواند |

⛔ **و `remeasure` حکمِ غایب را `NO_VERDICT` می‌خواند، نه سبز.**

## ۵. `Not Run` — نه «قبول»

| چیز | وضعیت |
|---|---|
| نصب این بسته روی staging | ⛔ `Not Run` |
| ووکامرس واقعی روی نصب مالک | ⛔ `Not Run` |
| HPOS روی نصب واقعی | ⛔ `Not Run` |
| دکان Lite و Pro | ⛔ `Not Run` — در این محیط اجراناپذیرند (`alpha.53`) |
| Min Max روی تولید | ⛔ `Not Run` |
| قالب و page builder فعلی | ⛔ `Not Run` |
| افزونه‌های کش و کشِ شیءِ پایدار | ⛔ `Not Run` |
| درگاه پرداخت | ⛔ `Not Run` — و **هیچ پرداخت موفق واقعی تا امروز تأیید نشده** |
| wp-admin واقعی | ⛔ `Not Run` |
| یک رفت‌وبرگشت واقعی `wp_logout_url()` | ⛔ `Not Run` |
| ورودِ واقعیِ یک پرسنل در مرورگر خودش | ⛔ `Not Run` |
| ویرایش تنوعِ واقعیِ #۱۱۹۶۵ به‌دست فروشندهٔ واقعی | ⛔ `Not Run`، **و به‌عمد**: §۳ دستور نوشتن روی آن شناسه‌ها را منع کرده |
| صفحهٔ دوم فهرست محصولات روی وردپرس واقعی | ⛔ `Not Run` |
| سوییت packaging روی ۸٫۱٫۳۴ | ⛔ `Not Run`، با علتِ نام‌برده |
| اجرای واقعی ارتقا و بازگشت روی وردپرس | ⛔ `Not Run` — این دور migration ندارد و وردپرس یکبارمصرفی در محیط نبود |
| هدر در wp-admin واقعی و روی قالب مالک | ⛔ `Not Run` — صفحه‌ها با `php -S` و از markup و شیوه‌نامهٔ **واقعی** سرو شدند، **و این وردپرس مالک نیست** |

## ۶. و یک تصحیح دربارهٔ اندازه‌گیریِ خودِ این دور

⛔ **کامیت هر بند یک عددِ database دارد که دربارهٔ درختِ همان لحظه است، نه
دربارهٔ این بسته.** نسخه **پس از** آن اجراها بالا رفت، و
`ReportLayoutEvidenceTest::testTheEvidenceDirectoryIsNamedForThisVersion`
همان را در اجرای ۸٫۱٫۳۴ **قرمز** کرد: پوشهٔ شواهد به نسخهٔ خودِ بسته گره
خورده است (`alpha.101`) و ثابت همچنان `alpha106-reports` بود.

**یک گاردِ کارکننده، نه یک نقص** — و `alpha.106` همین قاعده را در بند ۸۶-۳
نوشته بود. هیچ فایلِ تحویل‌شده‌ای خراب نشد (`git status docs/evidence/` تمیز،
چون از `alpha.104` مسیرهای داخل آن HTML نسبی‌اند)، ثابت به
`alpha107-reports` رفت، و عددهای بخش ۱ **پس از** بالا بردن نسخه و روی **هر
دو** مفسر از نو گرفته شدند.
