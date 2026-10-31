# شناسایی محتوای قالب هدر ۲۶۵۲ پیش از جایگزینی — فقط‌خواندنی

> هیچ‌کدام از این گام‌ها چیزی روی سایت نمی‌نویسد. ولی **هر کاری روی Staging مجوز جداگانهٔ مالک لازم
> دارد**، از جمله بارگذاری ZIP (حتی بی فعال‌سازی). تا امروز هیچ‌کدام اجرا نشده است.

چرا لازم است: با جایگزینی، قالب ۲۶۵۲ **رندر نمی‌شود**. پس هر چه جز هدر در آن است (اسکریپت، Popup،
نوار اعلان، کد رهگیری، shortcode ِ افزونه‌ای دیگر) دیگر در صفحه نمی‌آید. از آن طرف، Elementor Pro فایل
CSS ِ همین قالب را **باز هم** بارگذاری می‌کند (`Locations_Manager::enqueue_styles`، خط ۴۲ و ۱۶۹–۲۰۹ ِ
Pro 4.2.2)، پس قاعده‌هایی از آن که فقط به خودِ قالب محدود نیستند (مثل `body{padding-top:…}` برای جبران
هدر چسبان) روی صفحه می‌مانند.

## راه الف — ابزار همراه افزونه (پیشنهادی)

افزونه **لازم نیست فعال باشد**؛ فایل‌هایش کافی است.

```bash
wp eval-file wp-content/plugins/tecteb-header/tools/inspect-header-template.php 2652
wp eval-file wp-content/plugins/tecteb-header/tools/inspect-header-template.php 2652 json > inspect-2652.json
```

پس از فعال‌سازی، همان گزارش در پیشخوان هم هست: «تک‌طب ← مدیریت هدر ← جایگزینی هدر ← بررسی
فقط‌خواندنی قالب هدر ← بررسی #2652».

ابزار چه می‌خواند (فقط `get_post`، `get_post_meta`، `get_option`، `file_get_contents` و Reflection):

| بخش | منبع | چه چیزی را نشان می‌دهد |
|---|---|---|
| قالب و شرط | پست ۲۶۵۲، `_elementor_template_type`، `_elementor_conditions` | نوع، وضعیت، «include/general» |
| ابزارک‌ها | `_elementor_data` | تعداد هر نوع؛ ابزارک‌های «رفتار بیرون از هدر» با مسیرشان: `html`، `shortcode`، `nav-menu`، `jet-*`، سبد، جست‌وجو، فرم، popup، template |
| پرچم‌ها | تنظیمات هر عنصر | `<script>` در متن، پیوند `#elementor-action` (Popup)، sticky، motion effects، شناسهٔ HTML ِ عنصر، Custom CSS ِ عنصر |
| Custom CSS ِ قالب | `_elementor_page_settings` | قاعده‌هایی که به `selector` محدود نیستند |
| فایل CSS ِ قالب | `uploads/elementor/css/post-2652.css` | قاعده‌هایی که `.elementor-2652` ندارند و شمار `!important` — **این‌ها پس از جایگزینی روی صفحه می‌مانند** |
| Kit و سفارشی‌ساز | `elementor_active_kit`، `post-<kit>.css`، «CSS اضافی» | قاعده‌های سراسری سایت |
| قلاب‌ها | `elementor/theme/before_do_header`، `after_do_header`، `register_locations`، `get_header`، `wp_body_open`، `elementor/frontend/after_enqueue_styles` | اولویت، نام callback و فایل:خط |
| WP Rocket | `wp_rocket_settings` | **فقط** کلیدهای minify/RUCSS/async/defer/delay/lazyload/cache — کلید لایسنس و ایمیل چاپ نمی‌شود |
| افزونه‌ها | `active_plugins` | نسخهٔ Elementor، Jet، WP Rocket، ووکامرس، کش، جست‌وجو و علاقه‌مندی |

محدودیت‌ها، صریح:
- قلاب‌هایی که Elementor یا افزونهٔ دیگری **فقط در درخواست صفحهٔ عمومی** (مثلاً روی `wp` یا
  `template_redirect`) اضافه می‌کند، در WP-CLI و پیشخوان دیده نمی‌شوند. برای آن‌ها، توضیح HTML ِ
  `<!-- tecteb-header: … -->` در پیش‌نمایش مدیر جواب نهایی است.
- خواندن انتخابگرها ساده است (بی پارسر کامل CSS)؛ هدفش پیدا کردن قاعده‌های سراسری است، نه اعتبارسنجی CSS.
- Elementor ممکن است CSS ِ قالب را درون‌خطی چاپ کند (تنظیم «CSS Print Method: Internal Embedding»)؛ آن وقت
  فایل `post-2652.css` نیست و ابزار «not present» می‌گوید — آن وقت Custom CSS ِ عنصر و قالب همچنان خوانده می‌شود.

این ابزار در محیط محلی روی یک ۲۶۵۲ ِ ساختگی با JetMenu، html با `<script>`، سبد، shortcode، دکمهٔ
Popup، sticky، motion effects، Custom CSS و فایل CSS آزموده شد. هیچ نوشتنی دیده نشد: پست‌ها، متاها،
دسته‌ها، option ها و فایل‌های uploads (با تاریخ تغییر) پیش و پس یکسان بودند
(`tests/integration/run.php` بخش ۵-ب، و `tests/static/check.py` که نبودِ هر تابع نوشتنی را از بایت‌های فایل می‌سنجد).

## راه ب — بی بارگذاری هیچ فایلی

### در پیشخوان (فقط دیدن؛ چیزی را ذخیره نکنید)
1. «قالب‌ها ← Theme Builder ← Header» ⇒ «ویرایش با Elementor» ⇒ **Navigator** (Ctrl/⌘+I): فهرست همهٔ
   ابزارک‌ها. برای هر کدام: نوع، و اگر HTML/Shortcode/Text است، محتوایش.
2. هر عنصر ⇒ «پیشرفته ← CSS سفارشی» و «پیشرفته ← Motion Effects»؛ برای بخش بالایی «Sticky».
3. چرخ‌دندهٔ پایین چپ (تنظیمات صفحه) ⇒ «پیشرفته ← CSS سفارشی».
4. هر دکمه/پیوندی که «Popup» باز می‌کند (Dynamic ← Actions ← Popup) — نام Popup را یادداشت کنید.
5. **بی ذخیره خارج شوید** («بازگشت به پیشخوان»؛ اگر پرسید، «ذخیره نشود»).
6. «Elementor ← تنظیمات سایت ← CSS سفارشی» (Kit).
7. «نمایش ← سفارشی‌سازی ← CSS اضافی».
8. «تنظیمات ← WP Rocket ← بهینه‌سازی فایل»: Minify CSS، Remove Unused CSS، Load CSS asynchronously،
   Delay JS؛ «کش»: Enable caching for logged-in WordPress users.

### با WP-CLI (فقط خواندن)
```bash
wp post get 2652 --fields=ID,post_title,post_status,post_modified
wp post meta get 2652 _elementor_template_type
wp post meta get 2652 _elementor_conditions --format=json
wp post meta get 2652 _elementor_data | grep -o '"widgetType":"[^"]*"' | sort | uniq -c
wp post meta get 2652 _elementor_data | grep -o '#elementor-action[^"]*' | head
wp post meta get 2652 _elementor_page_settings --format=json
wp option get elementor_active_kit
wp eval 'print_r( array_intersect_key( (array) get_option( "wp_rocket_settings" ), array_flip( array( "minify_css", "remove_unused_css", "async_css", "delay_js", "defer_all_js", "cache_logged_user" ) ) ) );'
wp eval 'global $wp_filter; foreach ( array( "elementor/theme/before_do_header", "elementor/theme/after_do_header", "get_header", "wp_body_open" ) as $h ) { echo $h, ": ", isset( $wp_filter[ $h ] ) ? count( $wp_filter[ $h ]->callbacks ) : 0, "\n"; }'
```
⚠️ `wp option get wp_rocket_settings` را **کامل** چاپ یا ارسال نکنید: کلید لایسنس و ایمیل دارد. فقط
همان خط `array_intersect_key` بالا.

### فایل CSS ِ قالب (FTP یا File Manager، فقط دانلود)
`wp-content/uploads/elementor/css/post-2652.css` — هر قاعده‌ای که `.elementor-2652` ندارد را یادداشت کنید،
به‌ویژه روی `body`، `html`، `header`، `.site-header`، `.elementor-location-header + …` یا با `!important`.

## چه کنیم با نتیجه
| یافته | معنا | کار پیش از روشن‌کردن جایگزینی |
|---|---|---|
| html/shortcode/اسکریپت/کد رهگیری | پس از جایگزینی اجرا نمی‌شود | انتقال به جای دیگر (فوتر، تنظیمات همان افزونه) — تصمیم مالک |
| Popup ِ باز‌شونده از هدر | دکمه‌اش دیگر نیست؛ خودِ Popup اگر شرط دیگری دارد می‌ماند | تصمیم مالک |
| قاعدهٔ سراسری در `post-2652.css` روی `body`/`html` (مثلاً `padding-top`) | می‌ماند و جای خالی یا جابه‌جایی می‌سازد | دیدن در پیش‌نمایش مدیر؛ اصلاح در Elementor فقط با تصمیم جدا — این افزونه آن را حذف یا dequeue نمی‌کند |
| قاعدهٔ سراسری با `!important` روی عنصرهای عمومی (`a`، `button`، `input`، `ul`) | ممکن است با وجود ایزوله‌سازی روی هدر اثر بگذارد (`risks-fa.md` ردیف ۱۷) | دیدن در پیش‌نمایش مدیر |
| شنوندهٔ ناشناخته روی `elementor/theme/before_do_header` | می‌تواند صف را عوض کند | 0.2.1 در این حالت هرگز دو هدر چاپ نمی‌کند؛ توضیح HTML را بخوانید |
