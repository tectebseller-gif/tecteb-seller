# شواهد تمرین ارتقا و بازگشت — schema ۱ ↔ ۲

اجرای `tools/upgrade-rollback-check.sh` روی یک **WordPress یکبارمصرف** با دادهٔ
مصنوعی. راهنمای خواندنی این کار: `docs/upgrade-and-rollback.md`.

```
bash tools/upgrade-rollback-check.sh /opt/php81/bin/php docs/evidence/upgrade \
     dist/tecteb-marketplace-core-0.1.0-alpha.2.zip \
     dist/tecteb-marketplace-core-0.1.0-alpha.3.zip
```

نتیجه: **۳۲ بررسی، ۰ شکست** (`summary.txt`).
محیط و sha256 هر دو بسته: `00-environment.txt`.

| فایل | چیست |
|---|---|
| `01-before-upgrade.txt` | وضعیت کامل سایت روی `alpha.2` با schema ۱ |
| `02-after-upgrade.txt` | همان وضعیت پس از ارتقا به `alpha.3` |
| `03-diff-upgrade.txt` | تفاوت این دو — همه‌اش **افزودنی** است |
| `04-seed.txt` | ساخت یک درخواست واقعی با مدرک، از طریق سرویس‌های خود افزونه |
| `05-with-vendor-data.txt` | وضعیت با دادهٔ فروشنده |
| `06-after-rollback.txt` · `07-diff-rollback.txt` | پس از بازگشت به `alpha.2` |
| `08-health-ahead-message.txt` | متن فارسی هشدار «دیتابیس جلوتر از افزونه است» |
| `09-after-reupgrade.txt` | ارتقای دوباره؛ همان داده سر جایش |
| `10-inplace-upgrade.txt` | جایگزینی فایل بدون فعال‌سازی |
| `11-final-state.txt` | وضعیت پایانی سایت آزمایشی |
| `*-tmc-*.html` · `*-vendor.html` | خودِ صفحه‌های دریافت‌شده در هر مرحله |

## دو نکته که در فایل‌ها دیده می‌شوند و توضیح می‌خواهند

**۱. پس از بازگشت، ردیف audit «فعال‌سازی» ثبت نمی‌شود.**
در `07-diff-rollback.txt` فقط `plugin.deactivated` اضافه شده است. علت: فعال‌سازی
فقط وقتی ممیزی می‌شود که migration موفق گزارش شود، و نسخهٔ قدیمی در این حالت
`ahead` برمی‌گرداند نه `success`. یعنی نبودِ آن ردیف، خودش نشانهٔ همان وضعیت
«دیتابیس جلوتر است» است — نه گم‌شدن داده.

**۲. شمار فایل‌های خصوصی بین اجراها بالا می‌رود.**
هر اجرا یک مدرک نمونهٔ تازه در `uploads/tmc-private/` می‌گذارد و هیچ اجرایی
فایل‌های اجرای قبل را پاک نمی‌کند — همان رفتاری که در سایت واقعی هم انتظار
داریم: حذف افزونه فایل خصوصی را پاک نمی‌کند.

## محدودهٔ اعتبار

این شواهد دربارهٔ **محیط یکبارمصرف ما**ست: WordPress 7.1، WooCommerce 11.0.1،
MariaDB 10.11.14، PHP 8.1.32، بدون هیچ افزونهٔ دیگر. دربارهٔ `staging.tecteb.com`،
PHP 8.1.34، سرور وب واقعی یا هم‌زیستی با دکان چیزی ثابت نمی‌کند.
