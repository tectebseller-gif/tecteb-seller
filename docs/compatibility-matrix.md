# ماتریس سازگاری — فاز ۱

بازبینی ۱ · ۶ سپتامبر ۲۰۲۶ · **هیچ سطری هنوز `Passed` نیست** چون هنوز هیچ
کدی ساخته نشده و محیط WordPress در دسترس نیست. این فایل با اجرای واقعی
هر آزمون به‌روز می‌شود و هر به‌روزرسانی باید به log قابل بازتولید در
`docs/phase-1-report.md` ارجاع دهد.

## واژگان وضعیت (COMP-01: «unsupported و not-tested از passed جدا باشند»)

| وضعیت | معنی |
|---|---|
| `Passed` | آزمون مشخص با دستور، نسخه و exit code ثبت‌شده اجرا شد و قبول شد |
| `Failed` | همان، ولی رد شد |
| `Not Run` | آزمون تعریف شده ولی در این محیط اجرا نشده؛ دلیل و روش اجرا ذکر می‌شود |
| `Not Tested` | برای این ترکیب آزمونی تعریف نشده |
| `Unsupported` | آگاهانه پشتیبانی نمی‌شود (مثل Multisite در فاز ۱) |
| `User-Reported` | فقط اعلام مالک؛ **شاهد نیست** |

## ۱. زمان اجرا (runtime)

| مؤلفه | نسخه | منبع نسخه | وضعیت | دلیل / شاهد |
|---|---|---|---|---|
| PHP | 8.4.19 | اندازه‌گیری در کانتینر | `Not Run` (lint و unit در فاز ۱ اجرا می‌شود) | تنها PHP موجود |
| PHP | 8.1.34 | **User-Reported** | `Not Run` | PHP 8.1 در کانتینر قابل نصب نیست (PPA مسدود). فقط بررسی ایستای PHPCompatibility با `testVersion 8.1` ممکن است — که «شاهد ایستا» است، نه اجرا |
| PHP | 8.2 / 8.3 | — | `Not Tested` | خارج از دامنه اعلامی |
| WordPress | 7.1 | **User-Reported** | `Not Run` | WP core قابل دانلود نیست |
| WooCommerce | 11.0.1 | **User-Reported** | `Not Run` | WC قابل دانلود نیست |
| MySQL / MariaDB | MariaDB 10.11.14 | قابل نصب از apt در کانتینر | `Not Run` (آزمون DDL/migration در فاز ۱ برنامه‌ریزی شده) | فقط برای جدول audit؛ **معادل نصب WP نیست** |
| MySQL سایت | ? | نامعلوم | `Not Tested` | نسخه DB سایت گزارش نشده |

## ۲. حالت‌های سفارش WooCommerce (COMP-01)

| حالت | وضعیت | یادداشت |
|---|---|---|
| HPOS روشن، sync روشن | `Not Run` | نیازمند WC واقعی |
| HPOS روشن، sync خاموش | `Not Run` | نیازمند WC واقعی |
| ذخیره قدیمی سفارش (posts) | `Not Run` | نیازمند WC واقعی |
| Checkout کلاسیک / Block | `Not Tested` | فاز ۱ هیچ checkout لمس نمی‌کند |

**تذکر CORE-10:** اعلام سازگاری HPOS با `FeaturesUtil` در کد، «تست‌شدن»
نیست. صفحه سلامت «HPOS فعال» و «HPOS آزموده‌شده» را دو فیلد جدا نشان
می‌دهد؛ دومی تا اجرای این جدول `unknown/Not Run` است.

## ۳. WooCommerce غایب

| سناریو | وضعیت | یادداشت |
|---|---|---|
| فعال‌سازی بدون WooCommerce → notice فارسی، بدون fatal، feature بالا نمی‌آید | `Not Run` (نصب واقعی) / برنامه‌ریزی‌شده در تست stub | تست stub **معادل نصب واقعی نیست** و جدا گزارش می‌شود |

## ۴. سرور، قالب و افزونه‌های سایت (DEC-06-b)

| مؤلفه | نسخه | منبع | وضعیت |
|---|---|---|---|
| LiteSpeed | ? | User-Reported | `Not Run` |
| Hello Elementor | 3.5.1 | User-Reported | `Not Run` |
| Persian Woo | 10.0.4 | User-Reported | `Not Run` |
| Rank Math / WP Rocket | ? | سند مادر ۱۵ | `Not Tested` — inventory نشده |
| Multisite | — | — | **`Unsupported`** در فاز ۱؛ network activation با پیام فارسی رد می‌شود |

## ۵. مرورگر و دسترس‌پذیری (CORE-05)

| آزمون | وضعیت | یادداشت |
|---|---|---|
| ۳۲۰ / ۳۷۵ / ۷۶۸ / ۱۰۲۴ / ۱۴۴۰ روی **wp-admin واقعی** | `Not Run` | WP در دسترس نیست |
| همان روی harness رندر view (Chromium) | برنامه‌ریزی‌شده | **فقط «نمونه رابط»** — اثبات کارکرد افزونه نیست |
| Zoom 200% | `Not Run` | |
| Screen reader دستی | `Not Run` | ابزار خودکار جای بررسی دستی نیست |
| عدم بارگذاری asset در Posts و homepage | `Not Run` | نیازمند WP واقعی |

## ۶. نحوه رساندن سطرهای `Not Run` به `Passed`

یکی از دو راه:

1. **در محیط ساخت:** باز شدن دسترسی خروجی به `downloads.wordpress.org` (و
   PPA برای PHP 8.1)؛ سپس `tools/` اسکریپت نصب disposable را اجرا می‌کند.
2. **در محیط مالک:** اجرای دستورهای مستندشده در `docs/installation.md`
   (وقتی نوشته شد) روی یک WordPress **disposable با داده مصنوعی** و
   برگرداندن خروجی (log، نسخه‌ها، exit code، screenshot). نصب روی Staging
   یا production سایت **خارج از این درخواست** است.

هیچ سطری بدون log قابل بازتولید به `Passed` تغییر نمی‌کند.
