=== Tecteb Header ===
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.2.1
License: GPL-2.0-or-later

هدر سراسری تک‌طب (طرح B). فقط فارسی و راست‌به‌چپ.

== Description ==

لوگو، جست‌وجوی محصولات، حساب کاربری، سبد خرید، علاقه‌مندی‌ها، منوی اصلی، دسته‌بندی کالاها
(Mega Menu دوستونه) و کشوی موبایل — دسکتاپ و موبایل از یک منبع داده.

* shortcode: [tecteb_header]
* پیشخوان: تک‌طب ← مدیریت هدر (اگر منوی «تک‌طب» هست زیر همان؛ وگرنه ساخته می‌شود).
* جایگزینی سراسری هدر Elementor Theme Builder (پیش‌فرض خاموش؛ قالب ۲۶۵۲ دست نمی‌خورد).
* دسته‌ها: فقط شناسه ذخیره می‌شود؛ نام و نشانی هر بار از product_cat.
* آیکون: کتابخانهٔ رسانه، فقط PNG و WebP.
* هیچ دادهٔ ووکامرس، دکان، بازارگاه یا Elementor نوشته نمی‌شود؛ تنها نوشتن، option ِ خودِ افزونه است.

فیلترها: tecteb_header_capability, tecteb_header_parent_slug, tecteb_header_replace,
tecteb_header_should_enqueue, tecteb_header_wishlist_count, tecteb_header_skip_target.
اکشن: tecteb_header_purge.

فونت Vazirmatn تحت مجوز SIL OFL 1.1 (assets/fonts/OFL.txt).

ابزار بررسی فقط‌خواندنی قالب هدر Elementor (ابزارک‌ها، Custom CSS، فایل CSS ِ قالب، قلاب‌ها، WP Rocket):
wp eval-file wp-content/plugins/tecteb-header/tools/inspect-header-template.php 2652
و در پیشخوان: مدیریت هدر ← جایگزینی هدر ← «بررسی فقط‌خواندنی قالب هدر».

== Changelog ==

= 0.2.1 =
* هرگز دو هدر: اگر Elementor پس از برداشتن قالب، آن را دوباره به صف بیفزاید و چاپ کند (یا مکان
  header پیش‌تر در همان درخواست چاپ شده باشد)، هدر افزونه چاپ نمی‌شود و همان هدر Elementor می‌ماند.
* CSS ِ هدر با پیشوند #tth-root و یک لایهٔ ایزوله، تا CSS ِ سراسری سایت (Kit ِ Elementor، قالب،
  Custom CSS) ظاهر هدر را عوض نکند.
* ابزار بررسی فقط‌خواندنی قالب هدر Elementor.

= 0.2.0 =
* جایگزینی بی output buffer با فهرست قالب‌های مجاز؛ انتقال دسته‌ها از «منوی اصلی»؛ WP Rocket.
