# Tecteb Marketplace Core — راهنمای کار در این مخزن

## محدوده فعلی: فاز ۱ + فروشندگان + محصولات + ووکامرس و سفارش + تسویه + **ارسال جزئی، مرجوعی، کوپن/B2B/تیکت و مهاجرت آزمایشی دکان**
زیرساخت و **چهار صفحه مدیریت** (پیشخوان، سلامت، تنظیمات، ماژول‌ها)، و از
۱۲ سپتامبر **بخش فروشندگان** به دستور مالک: درخواست فروشندگی، مدارک پویا،
بررسی مدیر و پیشخوان فروشنده روی مسیر `/vendor/`
(`docs/phase-2-vendor-delivery.md`).

هنوز ساخته نشده: اعلان‌ها، نظرات، گزارش‌ها، SEO خودکار، اعمال خودکار کوپن روی
سبد ووکامرس، نمایش نردبان عمده روی صفحهٔ محصول، پیوست فایل در تیکت، و انتقال
مالکیت واقعی محصول در مهاجرت دکان.
هیچ sender/gateway واقعی وجود ندارد؛ خروجی‌های افزونه در Alpha همیشه بسته‌اند و
**تأیید موبایل انجام نمی‌شود** تا وقتی Adapter واقعی و مستند وجود داشته باشد.

**وضعیت جاری:** فاز ۱ پیاده‌سازی شد، **چهار دور بازبینی سورس** روی آن اعمال شد،
و پروتکل پذیرش (G-01 تا G-09 به‌علاوه ارتقا/بازیابی) روی یک **WordPress
یکبارمصرف** با WooCommerce واقعی، دو بار — روی PHP 8.1.32 و 8.4.19 — اجرا و
قبول شد. دسترس‌پذیری و چیدمان روی همان wp-admin واقعی با **زبان مدیریت fa_IR و RTL**
اجرا شد (با **بسته ترجمه حداقلی آزمایشی**) — ۵ viewport، شبیه‌سازی فضای چیدمان،
device scale factor سطح مرورگر، کیبورد با Tab واقعی، و axe: دو اجرا، هرکدام
۲۵۲ بررسی، ۰ شکست. زوم صفحه از منوی مرورگر و ترجمه رسمی فارسی `Not Run`.
اجرای گیت‌ها یک اشکال واقعی پیدا کرد (بند ۱۴ اصلاح‌های بازبینی) که اصلاح شد.
نصب روی `tecteb.com` یا `staging.tecteb.com` **انجام نشده و در مجوز فعلی نیست**؛
PHP 8.1.34 خودِ سایت، سرور وب واقعی، تداخل با افزونه‌های موجود، screen reader
دستی و مرورگرهای غیر Chromium همچنان `Not Run`.
گزارش: `docs/phase-1-report.md` بند ۳ · شواهد: `docs/evidence/acceptance/` ·
اصلاح‌های بازبینی: `docs/review-fixes-phase-1.md` · تحویل بعدی: `docs/next-phase-handoff.md`.

**ارتقا و بازگشت (۱۴ سپتامبر):** دو مسیر روی همان وردپرس یکبارمصرف اجرا شد —
`alpha.1 → alpha.4` (همان بسته‌ای که روی staging نصب است) و `alpha.3 → alpha.4`
— هرکدام ۳۵ بررسی و ۰ شکست. migration فقط افزودنی است و نسخه قدیمی ساختار را
پایین نمی‌آورد، پس **برای بازگشت بازگرداندن ZIP قبلی کافی است و بازیابی
دیتابیس لازم نیست** (`docs/upgrade-and-rollback.md` ·
`docs/evidence/upgrade-alpha1/` · `docs/evidence/upgrade/`).

**`0.1.0-alpha.9` (۱۵ سپتامبر):** مرحله ۵ ترتیب مالک — **توقفِ ناتمام، ارسال
جزئی، مرجوعی، فاز ۷ و مهاجرت دکان**. ساختار داده **۹**. شش چیز که باید بدانید:

- **توقف ناتمام یک شکست است.** `withdraw()` وضعیت را دوباره می‌خواند و نوشتنِ
  بی‌اثر را می‌گیرد؛ `stopAsResult()` با یک محصول باقی‌مانده هم `false` است؛ و
  `StorefrontSwitch::markStuck()` فهرست را در گزینه‌ای می‌نویسد که یک اعلان روی
  هر صفحهٔ wp-admin می‌خواند.
- **لینک پرداخت سفارش پرداخت‌نشده با چرخاندن کلید سفارش بازنشسته می‌شود**، نه با
  فیلتر. اندازه‌گیری‌شده: `pay_action()` هرگز دوباره نمی‌پرسد کالا فروختنی هست یا
  نه. از سرگیری **همان** لینک قبلی را برمی‌گرداند. `on-hold` عمداً استفاده نشد
  چون موجودی را تکان می‌دهد.
- **ارسال جزئی ردیف است، نه ستون:** `tmc_shipments`، یک ردیف به ازای هر بسته با
  رهگیری خودش. وضعیت `partially_shipped` **مشتق** است و `move()` دیگر
  «ارسال‌شده» را نمی‌پذیرد — از `ShipItems::ship()` برو.
- **مرجوعی: `received → refunded` تنها گذارِ پول است و به جایی نمی‌رسد**، و
  نوشتنش `UPDATE … WHERE reversal_event_key IS NULL` پشت ایندکس یکتاست. معکوس،
  خط **اضافه** می‌کند و آخرین مرجوعیِ یک قلم «باقی‌مانده» را برمی‌گرداند نه سهم
  گردشدهٔ خودش. سهمِ قبلاً پرداخت‌شده به `vendor_debt` می‌رود.
- **آنچه حدس زده نمی‌شود، نام دارد:** `ReturnTerms` (DEC-03)،
  `ManageCoupons::GLOBAL_UNDECIDED` (DEC-04)، `ManageWholesale::OPEN_TERMS`
  و مدت نگهداری تیکت (DEC-05).
- **مهاجرت دکان فقط می‌خواند.** `DokanReaderInterface` هیچ متد نوشتنی ندارد؛
  اجرای آزمایشی تطبیق سطربه‌سطر می‌دهد، تعارض را رد می‌کند، ورودش پیش‌نویس و
  متصل به همان شناسهٔ ووکامرس است، و `rollback()` دقیقاً همان ردیف‌ها را برمی‌دارد.

تحویل: `docs/phase-7-shipping-returns-and-engagement.md` · شواهد:
`docs/evidence/stop-failure/`، `docs/evidence/returns/`،
`docs/evidence/dokan-migration/`، `docs/evidence/orders/`.

**دو قاعده تازه:**
- **لایهٔ Application حق صداکردن وردپرس را ندارد — از جمله `__()`.** صف اقدام
  کلید برمی‌گرداند و متن فارسی در Presentation است (`ActionQueueMessages`).
  آزمون معماری همین را گرفت.
- **اسلاگ هیچ صفحهٔ ما نام افزونهٔ دیگری را ندارد** (`tmc-import`، نه
  `tmc-dokan-migration`)؛ آزمون قرارداد منو این را می‌سنجد.

**`0.1.0-alpha.8` (۱۵ سپتامبر):** مرحله ۴ ترتیب مالک — **بازگشت امن، درهای
دیگر خرید، دکان، و تسویه**. ساختار داده **۷**. پنج چیز که باید بدانید:

- **غیرفعال‌کردن افزونه، محصولات بازارگاه را از فروش خارج می‌کند** و پرچم توقف
  را ثبت می‌کند — در تنها لحظه‌ای که هنوز کد ما اجرا می‌شود. **فعال‌سازی دوباره
  خودکار چیزی را برنمی‌گرداند**؛ «از سرگیری» صریح لازم است و آن هم فقط محصولی
  را برمی‌گرداند که شرایطش برقرار است (F-14). هیچ داده‌ای پاک نمی‌شود.
- **سه در دیگر خرید بسته شد:** سبد از قبل پُرشده، **Store API** (سبد و checkout
  بلوکی) و **لینک پرداخت سفارش پرداخت‌نشده**. در هر سه، محصول خود فروشگاه و
  محصول دکان دست‌نخورده می‌مانند.
- **دکان Lite ۵٫۱٫۱ واقعاً فعال است** روی وردپرس یکبارمصرف و همهٔ آزمون‌های این
  مرحله کنار آن اجرا شدند. **محدودیت:** فایل‌های JS ساخته‌شدهٔ دکان در سورس گیت
  نیستند و wordpress.org از این محیط ۴۰۳ می‌دهد، پس صفحه‌های JS-محور خود دکان
  `Not Run` مانده‌اند (`docs/phase-6-safe-stop-and-settlement.md` بند ۳).
- **تسویه و برداشت کامل ساخته شد** (FIN-05). `SettlementGate` فقط **ساختن
  درخواست** را تا بسته‌شدن DEC-02/FIN-04 می‌بندد؛ **مانده همچنان محاسبه و
  نمایش داده می‌شود** (F-16). «تکمیل برای تسویه» را **مدیر** ثبت می‌کند
  (ORDER-01)، نه یک فرایند خودکار.
- **پیام خریدار یک جملهٔ کوتاه دربارهٔ همان کالاست** و هرگز نام DEC، نرخ
  کمیسیون یا دفترکل را نمی‌آورد؛ جزئیات در صفحهٔ «وضعیت فروش بازارگاه» مدیر
  است (F-17). آزمون، نبودِ آن کلیدواژه‌ها را می‌سنجد، نه متن را.

**قاعده تازه:** `DatabaseInterface::execute()` روی شکست **null** برمی‌گرداند و
در موفقیت تعداد سطر — و **صفر یک موفقیت است**. هر بررسی باید `=== null` باشد؛
`if (!$db->execute(...))` هر DDL موفق را شکست می‌خواند (همین یک بار ساختار
دادهٔ ۷ را کامل زمین زد).

تحویل: `docs/phase-6-safe-stop-and-settlement.md` · شواهد:
`docs/evidence/safe-stop/`، `docs/evidence/settlement/`،
`docs/evidence/coexistence/`.

**`0.1.0-alpha.7` (۱۵ سپتامبر):** مرحله ۳ ترتیب مالک — **اتصال به ووکامرس،
سفارش و ارسال آزمایشی**. ساختار داده **۶**. سه چیز که باید بدانید:

- **مرجع اصلی هر فیلد در ADR-008 نوشته شده و در کد قفل است.** قیمت/انتشار/
  عنوان/تصویر/SEO مال بازارگاه؛ **موجودی پس از اولین projection مال ووکامرس**؛
  سفارش و مشتری و مالیات مال ووکامرس. `SyncCatalog` عمداً `syncEverything()`
  ندارد: موجودی فقط هنگام **ساخت** محصول و با **ویرایش صریح فروشنده** بیرون
  نوشته می‌شود، وگرنه «موجودی قدیمی برمی‌گردد».
- **هر پرسشی دربارهٔ محصول غیربازارگاهی `not_ours` می‌گیرد و هیچ نوشتنی رخ
  نمی‌دهد.** محصولات سایت و دکان — از جمله وقتی قفل مالی روشن است — دست
  نمی‌خورند. اندازه‌گیری‌شده تا `post_modified`.
- **کلید آزمایشی سفارش** (`tmc_order_trial_mode`) فقط روی staging/development/
  local پذیرفته می‌شود و فقط شرط «بسته‌بودن DEC-02/DEC-04» را waive می‌کند؛
  همان خطوط دفترکل نوشته می‌شود. نرخ نمونه در **دیتابیس یکبارمصرف** است، نه در
  بسته (F-13).

تحویل: `docs/phase-5-catalog-and-orders.md` · شواهد: `docs/evidence/catalog/`
و `docs/evidence/orders/`.

**دو قاعده تازه:**
- **`php -l` روی هر نسخه PHP که افزونه ادعای اجرا رویش دارد** اجرا می‌شود
  (`tools/lint.sh`، امروز ۸٫۴ و ۸٫۱). `EnumCase->value` داخل `const` از ۸٫۲
  است و روی ۸٫۱ فاتالِ **کامپایل** می‌دهد، یعنی کل سایت می‌میرد نه یک صفحه —
  و lint روی ۸٫۴ چیزی نمی‌دید (F-11).
- **بازگشت امن از `alpha.8` خودکار است:** فقط افزونه را غیرفعال کنید (یا دکمهٔ
  توقف را بزنید) و بعد بستهٔ قبلی را بازگردانید — محصول‌ها خودشان از فروش خارج
  می‌شوند. خطر فقط وقتی می‌ماند که کسی عمداً فروش را از سر بگیرد و بعد بسته را
  برگرداند (`docs/upgrade-and-rollback.md` بند ۵٫۱ ·
  `tools/rollback-hazard-check.sh`).

**`0.1.0-alpha.6` (۱۴ سپتامبر):** مرحله ۲ ترتیب مالک — **محصولات**: فرم
چهارمرحله‌ای با حفظ داده در خطا، مالکیت scope‌شده در هر پرس‌وجو، تأیید انتشار و
مجوز جدای انتشار مستقیم، **نسخه پیشنهادی** برای تغییر حساس محصول منتشرشده
(نسخه فعلی روی سایت می‌ماند)، موجودی فوری در هر وضعیت، بارگذاری واقعی تصویر با
مالک مشخص، **الگوی مشخصات پزشکی دسته‌محور** با بازنشستگی به‌جای حذف، و CSV با
پیش‌نمایش و خنثی‌سازی فرمول. همراهش: تعلیق/بازگردانی فروشنده که دسترسی خودش و
همه پرسنلش را فوری قطع می‌کند، موتور کمیسیون + دفترکل فقط‌افزودنی، و **دروازه
عملیات سفارش** که ماژول سفارش را تا تعیین نرخ و بسته‌شدن DEC-02/DEC-04 اجرا
نمی‌کند. ساختار داده **۵**. تحویل: `docs/phase-4-products.md` ·
شواهد: `docs/evidence/products/`.
**قاعده تازه:** قابلیت‌های تازه‌ای که capability جدید می‌سازند باید در
`Core\Lifecycle\Capabilities::all()` بیایند؛ `Bootstrap::ensureCapabilities()`
روی هر درخواست مدیر فهرست را با یک امضای ذخیره‌شده می‌سنجد، چون **جایگزینی
فایل‌های افزونه hook فعال‌سازی را اجرا نمی‌کند** — همان حفره‌ای که UpgradeGate
برای migration می‌بندد.

**`0.1.0-alpha.5` (۱۴ سپتامبر):** مرحله ۱ ترتیب مالک — تنظیمات فروشگاه با پنج
زبانه، صف تغییر نام و حساب بانکی برای مدیر، پرسنل با پنج نقش مصوب بند ۳٫۱،
دعوت با لینک یک‌بارمصرف (ارسال خودکار در Alpha بسته است)، تعلیق فوری، و
`StaffAccess` به‌عنوان **تنها** مرجع «چه کسی در کدام فروشگاه چه اجازه‌ای دارد» —
مرحله‌های بعد از همین می‌پرسند. ساختار داده ۳.
فهرست وضعیت همه امکانات: **`docs/feature-inventory.md`** (ساخته‌شده/ناقص/باقی‌مانده)
· تحویل: `docs/phase-3-vendor-completion.md`.
محل مدارک حالا از **هر** ریشه وب بالاتر می‌رود، نه یک پوشه بالاتر: نصب
`public_html/staging/` والدش را هم سایت اصلی سرو می‌کند.

**`0.1.0-alpha.4` (۱۴ سپتامبر):** مقدار پیام‌های خطا پس از redirect حفظ می‌شود؛
مدارک خصوصی **بیرون از ریشه‌های قابل‌دسترس وب** ذخیره می‌شوند و اگر محل امنی
نباشد بارگذاری با خطای روشن متوقف می‌شود (ADR-007، F-08)؛ و نقص بسته‌بندی
`alpha.3` که `assets/vendor/tmc-vendor.css` را از ZIP بیرون می‌گذاشت اصلاح شد.
هرگز پوشه‌ای را فقط به‌خاطر نامش از بسته حذف نکنید — فقط ریشه payload.

**۱۱ سپتامبر — سه چیز عوض شد (جزئیات: `docs/phase-1-report.md` بند ۵):**
- مالک `0.1.0-alpha.1` را روی `staging.tecteb.com` **نصب و فعال کرده است**. این
  کار را مالک انجام داده؛ این مخزن هیچ دسترسی و هیچ آزمونی روی آن سایت ندارد و
  هیچ عددی از آنجا نمی‌آید.
- همان نصب یک نقص واقعی نشان داد: شناسه و نسخه در کارت‌های ماژول‌ها حرف‌به‌حرف
  زیر هم می‌افتادند. بازتولید شد، علت در CSS خودمان بود، اصلاح شد و گارد
  رگرسیون گرفت (`ADR-006`, `docs/evidence/redesign/`). بسته فعلی
  `12773052…035b20` است و گیت‌های G-01…G-05، G-08 و G-09 روی آن با PHP 8.1.32
  (CLI و وب) قبول شدند؛ G-06/G-07 دوباره اجرا نشدند.
- طرح بخش فروشندگان و پیشخوان اولیه: `docs/phase-2-vendor-plan.md` (نسخه ۲)
  — **طرح است، پیاده‌سازی شروع نشده**. پیش‌نمایش ناحیه فروشنده:
  `docs/evidence/preview/`.
- بسته تحویلی اکنون **`0.1.0-alpha.3`** است (`8a838e3c…5a68a4`) و همه
  گیت‌ها از G-01 تا G-09 — شامل هر سه حالت HPOS — روی همان بسته با
  PHP 8.1.32 قبول شدند. شواهد ناحیه فروشنده: `docs/evidence/vendor/`.

## مرجع‌ها (ترتیب اعتبار: دستور جاری مالک ← تصمیم مصوب ← Master ← UX ← پرامپت)
- `docs/Tecteb-Marketplace-Core-Master-Spec-v0.3.docx` (مرجع) → `docs/generated/…Master-Spec-v0.3.md`
- `docs/Tecteb-Marketplace-Core-UX-Wireframe-Spec-v0.2.docx` (مرجع) → `docs/generated/…UX-Wireframe-Spec-v0.2.md`
- `docs/Tecteb-Marketplace-Core-Claude-Code-Prompt-Phase-1-v0.2.md` (قرارداد اجرا)
- `docs/reference-checksums.txt` · `docs/decision-log.md` · `docs/adr/` ·
  `docs/environment-inventory.md` · `docs/compatibility-matrix.md`

## هویت
slug `tecteb-marketplace-core` · namespace `Tecteb\Marketplace` · prefix `tmc_` ·
text domain `tecteb-marketplace-core`. نسخه انتشار: به F-01 در `docs/decision-log.md`
وابسته است (تبار تعیین‌نشده)؛ نسخه schema دیتابیس مستقل از آن و از ۱ شروع می‌شود.

## فرمان‌های آزمون واقعی (فقط آنچه امروز اجرا می‌شود)
```bash
sha256sum -c docs/reference-checksums.txt   # سلامت فایل‌های مرجع
python3 tools/verify-extraction.py          # تطبیق ترتیبی DOCX ↔ Markdown

bash tools/run-all-tests.sh                 # پنج suite + lint + سازگاری ۸٫۱ + کنتراست
vendor/bin/phpunit --testsuite unit         # خالص: هیچ نماد WordPress تعریف نمی‌شود
vendor/bin/phpunit --testsuite architecture # مرز Core/Contracts/Application
vendor/bin/phpunit --testsuite contract --bootstrap tests/bootstrap-contract.php
vendor/bin/phpunit --testsuite database --bootstrap tests/bootstrap-database.php  # نیازمند .env.testing
vendor/bin/phpunit --testsuite packaging    # پس از tools/build.sh

php  tools/render-harness/render.php        # رندر ۸ سناریو خارج از WordPress
node tools/browser/check.mjs                # ۲۳۳ بررسی viewport/axe/keyboard
php  tools/contrast.php                     # کنتراست WCAG رنگ‌های برند
bash tools/build.sh                         # dist/ZIP + SHA256SUMS + source archive

# ارتقای schema ۱→۲ و بازگشت، روی وردپرس یکبارمصرف (۳۵ بررسی در هر اجرا)
bash tools/upgrade-rollback-check.sh /opt/php81/bin/php docs/evidence/upgrade-alpha1 \
     <alpha.1-zip> dist/tecteb-marketplace-core-0.1.0-alpha.4.zip

node tools/browser/check-vendor-messages.mjs   # متن و مقدار پیام‌ها (۱۱ بررسی)
bash tools/check-private-access.sh docs/evidence/vendor  # دسترسی مستقیم وب

# مرحله محصولات، روی افزونه نصب‌شده از ZIP نهایی
SITE=… TMC_OUT=docs/evidence/products node tools/browser/check-products.mjs      # ۶۱ بررسی
SITE=… TMC_OUT=docs/evidence/products/a11y node tools/browser/check-products-a11y.mjs  # ۲۴۰ بررسی
TMC_PAGES="tmc-product-review:product-review,tmc-spec-templates:spec-templates" \
  TMC_OUT=docs/evidence/products/wpadmin-a11y node tools/browser/check-wpadmin.mjs  # ۱۴۲ بررسی
node tools/browser/check-vendor-staff.mjs      # مسیر کامل مرحله ۱ (۲۴ بررسی)
node tools/browser/check-vendor-staff-a11y.mjs # دو صفحه تازه (۱۲۰ بررسی)

# مرحله ووکامرس و سفارش، روی افزونه نصب‌شده از ZIP نهایی و WooCommerce واقعی
wp eval-file tools/order-trial-seed.php <vendor-a> <vendor-b>       # داده نمونه
wp eval-file tools/purchase-block-state.php state|decide|suspend|…  # وضعیت
wp eval-file tools/order-evidence.php trial-matrix|projection|…     # شواهد
SITE=… TMC_WC_A=… TMC_WC_B=… TMC_WC_SHOP=… TMC_OUT=docs/evidence/orders \
  node tools/browser/check-order-trial.mjs        # مسیر کامل سفارش (۱۴ بررسی)
SITE=… TMC_WC_A=… TMC_WC_B=… TMC_WC_SHOP=… TMC_PRODUCT_A=… \
  node tools/browser/check-purchase-blocks.mjs    # تعلیق/ناموجودی/قفل (۲۵ بررسی)
SITE=… TMC_VARIABLE_PRODUCT=… TMC_OUT=docs/evidence/orders/a11y \
  node tools/browser/check-orders-a11y.mjs        # دو صفحه تازه (۸۰ بررسی)
bash tools/rollback-hazard-check.sh <wc-id> <vendor> <old-zip> <new-zip>

# مرحله بازگشت امن، دکان و تسویه — همه با دکان Lite فعال
# مرحله توقفِ ناتمام، مرجوعی و مهاجرت دکان — همه با دکان Lite فعال
bash tools/stop-failure-check.sh   docs/evidence/stop-failure    # ۳۳ بررسی
bash tools/return-check.sh         docs/evidence/returns         # ۱۸ بررسی
bash tools/dokan-migration-check.sh docs/evidence/dokan-migration # ۲۰ بررسی
SITE=… TMC_OUT=docs/evidence/stop-failure node tools/browser/check-stop-failure.mjs  # ۸ بررسی
wp eval-file tools/return-state.php first-item|open|decide|refund|…
wp eval-file tools/dokan-migration.php plan|import|runs|rollback|fingerprint

bash tools/safe-stop-check.sh   docs/evidence/safe-stop    # ۲۳ بررسی
bash tools/deactivation-check.sh docs/evidence/safe-stop   # ۱۳ بررسی
bash tools/settlement-check.sh  docs/evidence/settlement   # ۲۰ بررسی
wp eval-file tools/dokan-coexistence.php seed|report       # هم‌زیستی با دکان
SITE=… TMC_PAGES="tmc-storefront:storefront,tmc-withdrawals:withdrawals" \
  TMC_OUT=docs/evidence/safe-stop/wpadmin-a11y node tools/browser/check-wpadmin.mjs
```

نصب دکان برای آزمون هم‌زیستی: کلون `getdokan/dokan`، `composer install` (که
mozart را هم اجرا می‌کند)، کپی در `wp-content/plugins/dokan-lite/`، و ساختن
`assets/js/frontend.asset.php` — چون `Assets.php` آن یکی را **بدون
`file_exists`** لازم دارد. JS ساخته نمی‌شود؛ همین در شواهد صریح گفته شده.
دیتابیس آزمون **یکبارمصرف** است و پیکربندی‌اش در `.env.testing` می‌آید؛ هرگز
به دیتابیس واقعی اشاره نکنید (suite در نبود نام `tmc_test` اجرا نمی‌شود).

آزمون‌های وابسته به WordPress/WooCommerce واقعی همچنان **Not Run** هستند؛
`docs/compatibility-matrix.md` سطربه‌سطر می‌گوید کدام و چرا.

## قاعده دائمی تحویل (دستور مالک، ۱۱ سپتامبر)
هر نوبتی که **کد اجرایی افزونه** تغییر کند، تحویل شامل این‌هاست — نه فقط patch:

- **ZIP کامل و مستقل** با شماره نسخه در نام فایل
  (`tecteb-marketplace-core-<version>.zip`). نسخه انتشار قبلی تکرار نمی‌شود؛
  دو بسته با محتوای متفاوت هرگز یک نام ندارند.
- بسته **تجمعی** است: همه تغییرهای قبلی و جدید داخل همان یک ZIP‌اند و نصبش
  به هیچ بسته قبلی نیاز ندارد. هرگز patch یا «فقط فایل‌های تغییریافته» تحویل
  نمی‌شود. (`tools/build.sh` از ابتدا کل payload را می‌سازد، نه دلتا.)
- `dist/SHA256SUMS` که همان ZIP (و هر بسته دیگر موجود در `dist/`) را پوشش دهد.
- خلاصه تغییرها، آزمون‌های اجراشده و محدودیت‌های باقی‌مانده.
- شناسه commit متناظر با همان بسته.
- وضعیت صریح: «آماده آزمایش روی staging» یا «تأییدنشده؛ فعلاً نصب نشود».
- لینک دانلود مستقیم فایل در پاسخ؛ اشاره به مسیر `dist/` یا push کافی نیست.

اگر تغییر فقط اسناد یا ابزار توسعه بود، صریح گفته شود **ZIP نصب تغییر نکرده**.
هیچ بسته‌ای توسط ما روی سایت مالک نصب یا فعال نمی‌شود.

## قاعده عدم deploy
- هیچ نصب روی Staging/production، هیچ SSH/cPanel، هیچ credential واقعی.
- تنها مخزن محلی و DB آزمایشی disposable با داده مصنوعی قابل تغییر است.
- `git reset --hard`، clean مخرب و overwrite فایل کاربر ممنوع.
- هیچ افزونه موجودی (از جمله دکان) حذف/غیرفعال/ویرایش نمی‌شود.
- Skillهای حوزه‌ای وردپرس از ۱۱ سپتامبر نصب‌اند (`docs/tooling-setup.md`)،
  اما جای قواعد پرامپت فاز ۱ را نمی‌گیرند؛ در تعارض، دستور مالک و پرامپت مقدم است.
- محیط ساخت: PHP 8.4 فقط؛ WP/WC قابل دانلود نیست → آزمون‌های وابسته
  `Not Run` می‌مانند، نه `Passed`. تصاویر خارج از WordPress «نمونه رابط»
  هستند، نه اثبات کارکرد.

## ابزارهای دستیار (پلاگین و مهارت)
پیکربندی در `.claude/settings.json` (scope=project) و رونوشت مهارت‌ها در
`.claude/skills/`. گزارش کامل با شواهد: `docs/tooling-setup.md`.

- پلاگین‌های marketplace رسمی **`anthropics/claude-plugins-official`**:
  `frontend-design`، `pr-review-toolkit`، `security-guidance`. کدشان **در مخزن
  نیست**؛ هر محیط تازه باید از GitHub بگیردشان. (پیش‌تر از `anthropics/claude-code`
  نصب شده بود؛ دلیل تعویض در `docs/tooling-setup.md` بند ۲٫۱.)
- مهارت‌های `WordPress/agent-skills` @ `d87ee69`: `wordpress-router`،
  `wp-project-triage`، `wp-plugin-development`، `wp-rest-api`، `wp-performance`.
  رونوشت بالادست‌اند — دست‌نویس ویرایش نکنید (`.claude/skills/UPSTREAM.md`).
  پیش از استفاده، مهارت پروژه‌ای `tmc-wp-skills` را بخوانید: مسیر درست
  `.claude/skills/…` و تشخیص این مخزن به‌عنوان افزونه وردپرس
  (`node .claude/skills/tmc-wp-skills/scripts/triage.mjs`).
- Playwright ۱٫۶۳ + axe-core ۴٫۱۳ از قبل نصب‌اند و **نصب دوباره نمی‌شوند**.
  مرورگر باید با `executablePath` صریح اجرا شود (`/opt/pw-browsers/chromium`)؛
  بررسی سلامت: `cd tools/browser && node check-toolchain.mjs`.
- ناحیه فروشنده آزمون **مستقل** دارد: `node tools/browser/check-vendor.mjs`
  (۷۶ بررسی) و `node tools/browser/shoot-vendor.mjs` برای پیمایش کامل مسیر با
  تصویر. آزمون صفحات مدیر شاهد پذیرش آن ناحیه نیست. صفحه‌های محصول هم آزمون
  خودشان را دارند (`check-products*.mjs`)؛ `check-wpadmin.mjs` حالا با
  `TMC_PAGES` روی صفحه‌های تازه مدیر هم اجرا می‌شود — با `TMC_OUT` در پوشه
  دیگر، تا شواهد پذیرفته‌شده چهار صفحه بازنویسی نشود.
- داده نمونه مرحله محصولات با `tools/product-seed.php` و از مسیر سرویس‌های خود
  افزونه ساخته می‌شود (`wp eval-file`)، نه با SQL دستی.
- `check.mjs` و `check-wpadmin.mjs` را بی‌دلیل دوباره اجرا نکنید؛ شواهد
  پذیرفته‌شده را بازنویسی می‌کنند (`check-wpadmin.mjs` با `TMC_OUT` در پوشه دیگر
  می‌نویسد). اسکرین‌شات چهار صفحه در wp-admin واقعی:
  `OUT=… LABEL=… node tools/browser/shoot-wpadmin.mjs`.
- **چیدمان صفحات با `@container` نوشته می‌شود، نه `@media`** — ADR-006. هیچ
  تراکی نباید جعبه متن را خفه کند؛ بررسی `no-starved-text-box` این را می‌گیرد.
- Figma و PHP LSP عمداً نصب نشده‌اند.
