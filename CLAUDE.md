# Tecteb Marketplace Core — راهنمای کار در این مخزن

## محدوده فعلی: فاز ۱، Alpha قابل بررسی
فقط زیرساخت و **چهار صفحه مدیریت** (پیشخوان، سلامت، تنظیمات، ماژول‌ها) زیر
«بازارگاه تک‌طب». هیچ Vendor/Staff/Product/Order/Commission/Withdrawal/
Refund/Coupon/B2B/Ticket/SEO/Migration یا auth واقعی ساخته نمی‌شود. هیچ
sender/gateway واقعی وجود ندارد؛ خروجی‌های افزونه در Alpha همیشه بسته‌اند.

**وضعیت جاری:** طرح تأیید نشده؛ پیاده‌سازی شروع نشده. `docs/phase-1-plan.md`
منتظر مجوز مالک است.

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
sha256sum -c docs/reference-checksums.txt      # سلامت فایل‌های مرجع
python3 tools/verify-extraction.py             # تطبیق ترتیبی DOCX ↔ Markdown (exit 0 = یکسان)
```
فرمان‌های آزمون افزونه (PHPUnit، lint، PHPCompatibility، DB، مرورگر) **هنوز
وجود ندارند** و پس از پیاده‌سازی فاز ۱ اینجا اضافه می‌شوند. تا آن زمان هیچ
ادعای `Passed` برای CORE-01..10 معتبر نیست.

## قاعده عدم deploy
- هیچ نصب روی Staging/production، هیچ SSH/cPanel، هیچ credential واقعی.
- تنها مخزن محلی و DB آزمایشی disposable با داده مصنوعی قابل تغییر است.
- `git reset --hard`، clean مخرب و overwrite فایل کاربر ممنوع.
- هیچ افزونه موجودی (از جمله دکان) حذف/غیرفعال/ویرایش نمی‌شود.
- Skillهای حوزه‌ای نصب نیستند؛ قواعد پرامپت فاز ۱ لازم‌الاجرا هستند.
- محیط ساخت: PHP 8.4 فقط؛ WP/WC قابل دانلود نیست → آزمون‌های وابسته
  `Not Run` می‌مانند، نه `Passed`. تصاویر خارج از WordPress «نمونه رابط»
  هستند، نه اثبات کارکرد.
