# Tecteb — Claude Code execution contract
نسخه پرامپت: ۰٫۲ | تاریخ: ۵ سپتامبر ۲۰۲۶ | خروجی هدف: فاز ۱، Alpha قابل بررسی

## برای مالک پروژه
این فایل را همراه Master Spec v0.3 و UX Spec v0.2 داخل پوشه docs پروژه مستقل قرار بده. Claude Code را از ریشه همان پروژه باز کن. ابتدا فقط بخش «بررسی و طرح» را اجرا کن؛ بعد از دریافت طرح قابل بررسی، اجرای فاز ۱ را درخواست کن. دستورهای این فایل مجوز اتصال به هاست یا نصب روی Staging نیستند. Skillها کمک به اجرای روش‌اند و تضمین امنیت یا کیفیت نیستند.

## نقش و قرارداد
تو مسئول پیاده‌سازی و ارائه شواهد آزمون هستی. محصول یک افزونه مستقل WordPress/WooCommerce است. کل بازارگاه را در یک نوبت نساز. دامنه این درخواست فقط چهار صفحه مدیریت و زیرساخت فاز ۱ است. «ساخته شد»، «تست شد» و «آماده نصب» سه ادعای متفاوت‌اند.

مرجع‌ها:
- `docs/Tecteb-Marketplace-Core-Master-Spec-v0.3.docx`
- `docs/Tecteb-Marketplace-Core-UX-Wireframe-Spec-v0.2.docx`
- همین پرامپت، نسخه ۰٫۲

ترتیب اعتبار: دستور صریح جاری مالک → تصمیم مصوب → Master → UX → دستور اجرایی فاز. اسناد قدیمی را مبنای متناقض قرار نده. برای سه فایل SHA-256 ثبت کن و متن کامل پاراگراف‌ها و جدول‌های DOCX را بخوان؛ استخراج ناقص یا فقط عنوان‌ها کافی نیست. نسخه Markdown وفادار در docs/generated ایجاد و با متن/جدول اصلی تطبیق بده؛ محتوای جدید را به‌جای متن مرجع جا نزن.

## بررسی و طرح (قبل از کدنویسی)
1. `CLAUDE.md` و دستورهای repository و git status را بخوان. سورس قبلی را inventory کن. اگر پروژه قبلی با slug متفاوت وجود دارد، یک افزونه رقیب هم‌نام نساز؛ نگاشت هویت و برنامه سازگاری را پیشنهاد بده و فقط تصمیم مربوط به آن را بپرس.
2. ابزارهای موجود PHP، Composer، Node و محیط تست WordPress را با نسخه گزارش کن. اطلاعات WP 7.1، WC 11.0.1، PHP 8.1.34، LiteSpeed، Hello Elementor 3.5.1 و Persian Woo 10.0.4 «گزارش کاربر» هستند؛ نصب یا سازگاری آن‌ها را بدون شواهد تأیید نکن.
3. تصمیم‌های باز DEC-01..DEC-06 را به فازهای متأثر وصل کن. هیچ‌کدام به‌تنهایی مانع scaffold فاز ۱ نیست. تصمیم تجاری جدید را حدس نزن.
4. طرح کوتاه شامل مسیر فایل‌ها، dependency graph، persistence، capabilityها، تست و بسته‌بندی بده. مالک ابتدا این طرح را بررسی می‌کند؛ پس از مجوز اجرای فاز، برای هر فایل دوباره سؤال نکن.

## هویت انتشار
- name: Tecteb Marketplace Core
- slug: tecteb-marketplace-core
- main file: tecteb-marketplace-core.php
- namespace: Tecteb\Marketplace
- prefix / text domain: tmc_ / tecteb-marketplace-core
- artifact: dist/tecteb-marketplace-core.zip
- نسخه جدید در repository خالی: 0.1.0-alpha.1؛ اگر نسخه قبلی وجود دارد، نسخه را عقب نبر.
- runtime هدف: PHP 8.1 بدون نصب Composer/Node روی هاست؛ build dependencies فقط محیط توسعه.

## مرز اختیار
- تنها repository محلی و دیتابیس آزمایشی disposable با داده مصنوعی قابل تغییر است. هیچ SSH/cPanel، نصب Staging، production، credential واقعی یا backup واقعی لازم نیست.
- دانلود مستندات رسمی و dependency توسعه مجاز به درخواست ابزار است؛ ممنوعیت outbound مربوط به runtime افزونه و ارسال واقعی است، نه جلوگیری از تحقیق و تست محلی.
- کد دکان/دروازه/لایسنس مبهم‌سازی‌شده کپی یا اجرا نشود. هیچ افزونه موجودی حذف، غیرفعال یا ویرایش نشود.
- حداقل تغییرات لازم را روی سورس موجود انجام بده؛ git reset --hard، clean مخرب و overwrite فایل کاربر ممنوع. دیتابیس تست را فقط وقتی disposable بودن و نام آن صریح مشخص است reset کن.
- session role / plan mode را خودکار برای دورزدن اجازه تغییر نده. Skill یا CLAUDE.md مرز امنیتی سخت نیست؛ هیچ bypass-permissions درخواست نکن.

## ساختار و مرزبندی
Core کوچک: bootstrap، container ساده، module loader، config، lifecycle و قراردادها. featureها در Modules؛ هر ماژول در صورت نیاز Domain/Application/Infrastructure/Presentation خودش را دارد. از directory خالی برای وانمودکردن تکمیل ماژول استفاده نکن.

ساختار هدف: main file، src/Core، src/Contracts، src/Modules/Admin، src/Modules/Health، src/Infrastructure/WordPress، assets، languages، docs، tests و tools.

از PSR-4 autoload قطعی و dependency injection استفاده کن. Composer autoload بسته‌بندی‌شده یا autoloader کوچک مجاز است؛ انتخاب و دلیل در ADR. Domain نباید تابع WP یا کلاس WC فراخوانی کند. معماری portable به معنی اجرای مستقیم افزونه روی Laravel نیست.

## الزامات فاز و تست متناظر

### CORE-01 — Lifecycle
Main file شامل metadata معتبر، ABSPATH guard و check نسخه پیش از require فایل‌های PHP 8.1 باشد. dependency WooCommerce در نقطه lifecycle درست بررسی شود؛ نبود آن notice فارسی و عدم راه‌اندازی featureها ایجاد کند، نه fatal.
Activation، reactivation و upgrade idempotent؛ version schema فقط پس از migration موفق ثبت شود. migration lock اتمی با بازیابی lock منقضی داشته باشد؛ اجرای concurrent دوباره‌نویسی نکند. DDL در MySQL الزاماً rollback تراکنشی ندارد؛ migration مرحله‌ای و قابل resume طراحی کن. در این فاز فقط جدول audit لازم است؛ جدول‌های تجاری آینده ساخته نشوند.
Deactivation فقط taskهای خود افزونه را متوقف کند؛ داده‌ها باقی بمانند. uninstall.php یا وجود نداشته باشد یا تنها guard و توضیح preservation، بدون حذف داده. Multisite network activation با پیام فارسی «پشتیبانی نشده» رد شود و هیچ تغییر سراسری ایجاد نکند.
تست: WooCommerce حاضر/غایب، فعال‌سازی دوباره، migration شکست‌خورده و concurrent، غیرفعال‌سازی و باقی‌ماندن داده.

### CORE-02 — Module Loader
Manifest: id، version، dependencies و lifecycle. دو مرحله register سپس boot در ترتیب dependency؛ چرخه، missing dependency، duplicate id و exception مدیریت شود. boot تکراری hook دوباره نسازد. وضعیت‌های planned/active/degraded مجزا؛ planned قابل فعال‌سازی نیست.
Core و Environment Guard زیرساخت‌اند؛ Admin و Health ماژول‌های عملیاتی این فاز. سایر قابلیت‌ها فقط در roadmap manifest، بدون fake controller یا toggle عملیاتی.
تست: ترتیب dependency، چرخه، فقدان وابستگی، duplicate و boot دوباره.

### CORE-03 — Authorization
در فاز اول فقط capabilityهای مصرف‌شده بساز:
`tmc_view_dashboard`, `tmc_view_health`, `tmc_manage_settings`, `tmc_view_modules`.
در نصب single-site به Administrator افزوده شوند؛ به نقش seller یا staff موجود دست نزن. callbackهای UI، Settings API و REST همین capabilityها را بررسی کنند. تغییر تنظیمات nonce معتبر و tmc_manage_settings می‌خواهد؛ nonce به تنهایی مجوز نیست.
تست کاربر مهمان، subscriber، seller موجود بدون capability و مدیر؛ CSRF و direct URL. قابلیت‌های آینده صرفاً در docs ثبت شوند.

### CORE-04 — Safe runtime
در این Alpha همه خروجی‌های خارجی خود TMC همیشه بسته‌اند؛ گزینه محیط هرگز این قفل را باز نمی‌کند. هیچ sender یا gateway واقعی نساز. سایت‌های دیگر یا افزونه‌های دیگر را globally hook/block نکن.
EnvironmentResolver: constant معتبر TMC → option معتبر → wp_get_environment_type. مقدار ناشناخته unknown و safe. hostname تنها شاهد کمکی است. UI جداگانه environment و TMC outbound blocked را نشان دهد؛ ایمنی SMS/ایمیل/پرداخت سایر افزونه‌ها unknown است.
تست: production option روی staging و برعکس، constant اولویت‌دار، ورودی نامعتبر، نبود WC؛ همه همچنان بدون ارسال TMC. وضعیت «کل سایت امن» ممنوع.

### CORE-05 — چهار صفحه فارسی
فقط پیشخوان، سلامت، تنظیمات و ماژول‌ها زیر «بازارگاه تک‌طب». shell و کامپوننت‌ها مطابق UX-01؛ بدون آمار تجاری ساختگی، صفحات مالی، منوی فروشنده یا route دکان.
Styles فقط در hook suffix صفحات افزونه و scoped با .tmc-admin. vanilla JS ماژولار؛ هیچ CDN، frontend global CSS، mock income یا fake operational button.
رنگ‌ها: #6ABFE7، #143C4D، #21D483، #FFC658. متن سفید روی primary dark با contrast آزموده؛ success/warning متن/آیکن هم داشته باشند. RTL واقعی، bdi برای شناسه‌ها، keyboard، focus، label، error summary و reduced motion.
تست: 320/375/768/1024/1440، zoom 200%، screen reader دستی مشخص، عدم asset در صفحه Posts و homepage. بررسی دسترس‌پذیری خودکار جای بررسی دستی نیست.

### CORE-06 — Health REST
فقط `GET /wp-json/tmc/v1/health` با permission_callback برابر tmc_view_health. هیچ endpoint عمومی نسخه‌های سرور نساز. cookie+WP REST nonce در مدیریت؛ صرف داشتن nonce مجوز نیست.
قرارداد پاسخ:
- schema_version: "1"
- plugin: {version}
- environment: {resolved, source}
- dependencies: {woocommerce: {available, version}, hpos: {enabled: boolean|null}}
- outbound: {tmc: "blocked", other_plugins: "unknown"}
- modules: [{id, status}]
- checked_at: ISO-8601 UTC
HPOS enabled نشان‌دهنده تست سازگاری نیست. no-store، بدون path سرور، credential، user list، stack trace یا raw exception. null به معنی unknown؛ به false تبدیل نکن.
تست: 401/403 استاندارد WP برای فاقد دسترسی، 200 برای مجاز، nonce نامعتبر، schema و عدم اطلاعات حساس.

### CORE-07 — Configuration
Settings API با schema version. درصد عمومی مقدار null پیش‌فرض و صفر صریح معتبر؛ decimal string با حداکثر دو رقم اعشار به basis points 0..10000 تبدیل شود؛ float استفاده نشود. ارقام فارسی/عربی نرمال شوند، مقدار نامعتبر reject و مقدار قبلی حفظ شود.
settlement_delay_days=4 و max_staff=10 پیش‌فرض‌اند؛ integer مثبت/نامنفی مطابق schema با سقف فنی مستند، نه قانون تجاری مخفی. نرخ unset در این فاز فقط پیام دارد؛ مانع فعال‌سازی skeleton نیست.
محیط خودکار/آزمایشی/اصلی تنظیم می‌شود ولی outbound Alpha قابل خاموش‌کردن نیست. تنظیمات ماژول آینده با توضیح «هنوز مصرف عملیاتی ندارد» نمایش داده شوند.
Audit تغییر فقط allowlisted key، actor ID، old/new غیرحساس و UTC؛ logging raw POST ممنوع. error ثبت audit در عملیات حساس به‌وضوح گزارش شود، نه موفقیت بی‌صدا.
تست null/0/100/100.01/منفی/متن/array و ارقام فارسی؛ nonce و capability؛ reactivation مقدار کاربر را به default برنگرداند.

### CORE-08 — Audit
جدول prefix-safe با schema migration. ستون‌های id، event_type، actor_id nullable، object_type/object_id، safe payload، correlation_id و created_at UTC؛ ایندکس متناسب query. actor ID داده مستعارشده است، نه «فاقد داده شخصی».
Repo/Logger interface قابل تست؛ sanitize ساختاری و allowlist metadata. SQL prepared. هیچ raw request، email/phone، OTP، token، cookie یا کلید در log. UI exporter یا retention job خارج فاز؛ نگهداری نهایی در DEC-05 باز است.
تست allowlist، ورودی حساس، insert failure و retention داده هنگام deactivate.

### CORE-09 — OTP contract
Interface مستقل با sendChallenge و verifyChallenge و typed result؛ unavailable/invalid/expired/rate_limited/success از هم جدا. NullOtpProvider برای send و verify فقط unavailable، بدون شبکه، challenge معتبر یا session.
FakeProvider تنها در tests با داده مصنوعی و clock مشخص، هرگز در ZIP runtime ثبت نشود. اتصال دروازه بدون مستند API ممنوع. «نصب هست» به معنی «متصل و آزموده» نیست. هیچ کد ثابت login در UI یا fallback ورود اضافه نکن.
تست NullProvider هر ورودی را غیرموفق برگرداند و هیچ auth cookie یا ارسال ایجاد نکند.

### CORE-10 — Packaging and compatibility
HPOS declaration با FeaturesUtil در before_woocommerce_init و guard درست؛ این declaration تضمین تست نیست. runtime Composer/Node اجباری نباشد؛ dependencyهای لازم با مجوز و lockfile در source باشند.
Build از source مشخص و تکرارپذیر؛ ZIP فقط یک پوشه tecteb-marketplace-core و main file درون آن. .git، .env، backup، فایل DOCX، test credential و dev vendor داخل install ZIP نباشند. source archive جدا شامل tests/docs/lockfile باشد. هیچ فایل تجاری مرجع در هیچ تحویل نباشد.
تست unzip integrity، PHP lint با PHP 8.1، نصب در WordPress disposable و no fatal/warning با WP_DEBUG. حداقل runtime local tested مشخص؛ PHP/WP/WC گزارش‌شده کاربر که در دسترس نیستند Not Run بمانند، نسخه جعلی دانلود یا جایگزین پنهانی نشود.

## Skillها و تنظیمات پروژه
فهرست تخصص‌ها به معنی وجود Skill نصب‌شده نیست. در preflight مسیر و نام Skillهای واقعاً موجود را فهرست کن. در نبود آن‌ها همین قواعد اجرایی لازم‌الاجرا هستند؛ ادعای «Skill اجرا شد» نکن. نصب plugin/MCP ناشناس شرط کدنویسی نیست.
پنج حوزه موردنیاز: wordpress-architecture (CORE-01/02)، woocommerce-hpos (CORE-10)، security-privacy (CORE-03/04/06/08/09)، rtl-accessibility (CORE-05)، testing-release (CORE-01..10).
برای Skill پروژه مسیر استاندارد `.claude/skills/<name>/SKILL.md` است و frontmatter name/description و trigger و steps و acceptance لازم دارد. این پرامپت فایل Skill نصب‌شده نیست.
CLAUDE.md ریشه باید فقط محدوده فاز، مرجع اسناد، فرمان‌های تست واقعی و قاعده عدم deploy را خلاصه کند. تنظیمات `.claude/settings.json` با نسخه نصب‌شده validate شوند؛ deny چند فرمان shell تضمین جلوگیری از شبکه یا دسترسی غیرمستقیم نیست. به پروژه credential هاست نده.
ابتدا Plan mode؛ بعد از تأیید طرح، حالت معمول با درخواست مجوز ابزار. model/effort از موارد واقعاً موجود حساب انتخاب شود؛ نام نسخه مدل حدسی نوشته نشود. تغییر مدل کیفیت را تضمین نمی‌کند؛ شواهد تست مبنای قبول است.

## خارج از این فاز
هیچ عملیات Vendor/Staff/Product/Medical builder/Order/Commission/Withdrawal/Refund/Coupon/B2B/Ticket/SEO/Migration یا auth واقعی نساز. این قابلیت‌ها در v1 باقی‌اند و در فاز بعد اجرا می‌شوند. تغییر wp-config، route فعلی، دکان، قالب، داده واقعی یا حساب آزمایشی روی هاست خارج اختیار است.

## تست و تحویل
هر CORE-ID باید به فایل اجرا و نام تست و نتیجه وصل شود. ساخت test که فقط hardcoded stub خودش را تأیید می‌کند کافی نیست. pure unit و WordPress integration و مرورگر جدا گزارش شوند.
ماتریس: PHP 8.1 + WP/WC آزموده، WC غایب، HPOS on sync-on/on sync-off/legacy در محیط disposable؛ ستون user-reported environment جدا. اگر runner موجود نیست، روش دقیق اجرای آن و وضعیت Not Run ارائه کن، نه Passed.
گیت نصب Staging: PHP lint، نصب/فعال‌سازی واقعی، مجوز/nonce REST و Settings، migration idempotency و outbound/NullOTP باید Passed باشند. فقدان هرکدام ZIP را «unverified — نصب نشود» می‌کند؛ می‌توان source و گزارش را برای بررسی تحویل داد.
گزارش شامل test command، version، exit code، نتیجه، محدودیت و artifact evidence؛ summary تزئینی بدون log قابل بازتولید کافی نیست. از ادعای بدون تداخل یا امنیت کامل اجتناب کن.

تحویل:
1. dist/tecteb-marketplace-core.zip + SHA256SUMS
2. source archive با lockfile، tests و tools/build
3. docs/phase-1-report.md با CORE-ID → فایل → test → evidence → Passed/Failed/Not Run
4. docs/architecture.md و ADR تصمیم‌های فنی؛ docs/decision-log.md برای DECهای باز
5. docs/compatibility-matrix.md و docs/installation.md با rollback فقط فاز اول
6. docs/public-contracts.md: option/capability/table/hook/route و schema
7. تصاویر چهار صفحه با داده مصنوعی و نتیجه keyboard/mobile
8. docs/next-phase-handoff.md با status واقعی؛ phase بعد را خودکار شروع نکن.

## توقف هدفمند
کار مستقل را ادامه بده. تنها برای تعارض مؤثر در همان قابلیت، هویت repository قبلی، تصمیم تجاری جدید یا action روی هاست متوقف شو و دلیل دقیق بگو. کمبود WordPress runner گزارش Not Run می‌خواهد، نه جعل قبولی. نصب روی Staging/production در این درخواست انجام نشود.

## منابع رسمی بررسی‌شده
- https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/
- https://code.claude.com/docs/en/skills
- https://code.claude.com/docs/en/permissions
- https://code.claude.com/docs/en/settings
این منابع برای قواعد فنی‌اند؛ سیاست کسب‌وکار از اسناد و مالک محصول می‌آید.
