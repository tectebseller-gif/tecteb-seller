=== Tecteb Homepage ===
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.2.0
License: GPL-2.0-or-later

صفحهٔ اصلی تک‌طب (Concept C، Hero تیره). فقط فارسی و راست‌به‌چپ.

== Description ==

لایهٔ نمایش صفحهٔ اصلی، با خواندن از ووکامرس. هیچ دادهٔ ووکامرس، دکان یا
Tecteb Marketplace را نمی‌نویسد و به هیچ‌کدام از آن دو وابسته نیست.

* shortcode: [tecteb_home] — هر چهار بخش (Hero با سه مسیر، دسته‌ها، محصولات، همکاری). [tecteb_home sections="hero,products"] — فقط همان‌ها.
* قالب صفحه: «تک‌طب — تمام‌عرض (هدر و پاورقی سایت)».
* تنظیمات: ووکامرس ← صفحهٔ اصلی.
* سه مسیر اصلی در Hero: فروشگاه (صفحهٔ فروشگاه ووکامرس)، درخواست تعمیرات تجهیزات پزشکی، همکاری تأمین‌کنندگان.
* محصولات منتخب = فهرست دستی مدیر (جست‌وجو با نام، کد کالا یا شناسه)، با داده‌های زندهٔ ووکامرس.
* هر دکمه‌ای که مقصد تعیین‌شده ندارد نمایش داده نمی‌شود.

ثابت‌های اختیاری wp-config.php (بر تنظیمات مقدم‌اند):
TECTEB_HOME_REPAIR_URL, TECTEB_HOME_SUPPLIER_URL, TECTEB_HOME_SUPPLIER_TERMS_URL,
TECTEB_HOME_CATEGORIES_URL.

فیلترها: tecteb_home_should_enqueue, tecteb_home_capability. اکشن: tecteb_home_purge.

فونت Vazirmatn تحت مجوز SIL OFL 1.1 (assets/fonts/OFL.txt).

== Changelog ==

= 0.2.0 =
* سه مسیر اصلی: فروشگاه، درخواست تعمیرات تجهیزات پزشکی، همکاری تأمین‌کنندگان.
* محصولات صفحهٔ اصلی با انتخاب دستی مدیر، جایگزین محصولات «ویژه».
* «درخواست تأمین»، «استعلام سازمانی» و بخش خدمات عمومی برداشته شدند.

= 0.1.0 =
* نسخهٔ اول، از Concept C دور ۳.
