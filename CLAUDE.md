# Tecteb Marketplace Core — راهنمای کار در این مخزن

## محدوده فعلی: فاز ۱، Alpha قابل بررسی
فقط زیرساخت و **چهار صفحه مدیریت** (پیشخوان، سلامت، تنظیمات، ماژول‌ها) زیر
«بازارگاه تک‌طب». هیچ Vendor/Staff/Product/Order/Commission/Withdrawal/
Refund/Coupon/B2B/Ticket/SEO/Migration یا auth واقعی ساخته نمی‌شود. هیچ
sender/gateway واقعی وجود ندارد؛ خروجی‌های افزونه در Alpha همیشه بسته‌اند.

**وضعیت جاری:** فاز ۱ پیاده‌سازی شد، **چهار دور بازبینی سورس** روی آن اعمال شد،
و پروتکل پذیرش (G-01 تا G-09 به‌علاوه ارتقا/بازیابی) روی یک **WordPress
یکبارمصرف** با WooCommerce واقعی، دو بار — روی PHP 8.1.32 و 8.4.19 — اجرا و
قبول شد. دسترس‌پذیری و چیدمان (۵ viewport، زوم ۲۰۰٪، کیبورد، axe) هم روی همان
wp-admin واقعی اجرا شد: دو اجرا، هرکدام ۱۸۸ بررسی، ۰ شکست.
اجرای گیت‌ها یک اشکال واقعی پیدا کرد (بند ۱۴ اصلاح‌های بازبینی) که اصلاح شد.
نصب روی `tecteb.com` یا `staging.tecteb.com` **انجام نشده و در مجوز فعلی نیست**؛
PHP 8.1.34 خودِ سایت، سرور وب واقعی، تداخل با افزونه‌های موجود، screen reader
دستی و مرورگرهای غیر Chromium همچنان `Not Run`.
گزارش: `docs/phase-1-report.md` بند ۳ · شواهد: `docs/evidence/acceptance/` ·
اصلاح‌های بازبینی: `docs/review-fixes-phase-1.md` · تحویل بعدی: `docs/next-phase-handoff.md`.

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
```
دیتابیس آزمون **یکبارمصرف** است و پیکربندی‌اش در `.env.testing` می‌آید؛ هرگز
به دیتابیس واقعی اشاره نکنید (suite در نبود نام `tmc_test` اجرا نمی‌شود).

آزمون‌های وابسته به WordPress/WooCommerce واقعی همچنان **Not Run** هستند؛
`docs/compatibility-matrix.md` سطربه‌سطر می‌گوید کدام و چرا.

## قاعده عدم deploy
- هیچ نصب روی Staging/production، هیچ SSH/cPanel، هیچ credential واقعی.
- تنها مخزن محلی و DB آزمایشی disposable با داده مصنوعی قابل تغییر است.
- `git reset --hard`، clean مخرب و overwrite فایل کاربر ممنوع.
- هیچ افزونه موجودی (از جمله دکان) حذف/غیرفعال/ویرایش نمی‌شود.
- Skillهای حوزه‌ای نصب نیستند؛ قواعد پرامپت فاز ۱ لازم‌الاجرا هستند.
- محیط ساخت: PHP 8.4 فقط؛ WP/WC قابل دانلود نیست → آزمون‌های وابسته
  `Not Run` می‌مانند، نه `Passed`. تصاویر خارج از WordPress «نمونه رابط»
  هستند، نه اثبات کارکرد.
