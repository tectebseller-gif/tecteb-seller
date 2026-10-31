# بازبینی سازگاری 0.1.0 ⇒ 0.2.0: Elementor Pro ِ واقعی، PHP 8.1 و WP Rocket

محیط Staging (اعلام مالک): Elementor 4.2.3 · Elementor Pro 4.2.2 · JetMenu 3.0.4 ·
WooCommerce 11.0.1 · WP Rocket 3.23.2.2 · قالب هدر «Header» ۲۶۵۲، منتشرشده، شرط «کل سایت».

## ۱. Elementor Pro 4.2.2 — از روی کد، نه حدس

**منبع خوانده‌شده** (فقط خواندن؛ نصب و اجرای آن‌ها در محیط محلی اجازه داده نشد):
- Pro Elements 4.2.2 (نسخهٔ GPL ِ همان کد Theme Builder، با همان شمارهٔ نسخهٔ Elementor Pro ِ Staging)،
  `github.com/proelements/proelements/releases/download/v4.2.2/pro-elements.zip`،
  SHA-256 `3f13d63c97506add64e7dbb61ae2166dd594d91e24425e3cfedfe19bcada3c7c`.
- Elementor 4.2.3، `github.com/elementor/elementor/releases/download/4.2.3/elementor-4.2.3.zip`،
  SHA-256 `a167404eb93e5568b79bc31cd560b67f7b6c150ce8e825a5ff4168089eb2133c`.

**آنچه کد می‌گوید** (`modules/theme-builder/classes/locations-manager.php`):
1. `do_location('header')` اسناد مکان را بر اساس شرط‌ها در صف می‌گذارد (`add_doc_to_location`)؛ اگر صف
   خالی باشد `false` برمی‌گرداند و قالب (Hello) هدر خودش را چاپ می‌کند.
2. `do_action( "elementor/theme/before_do_header", $this )` — آرگومان، خودِ Locations_Manager است.
3. `while ( ! empty( $this->locations_queue['header'] ) )` هر سند را چاپ می‌کند.
4. `do_action( "elementor/theme/after_do_header", $this )`؛ `return true`.

متدهای عمومی `get_documents_for_location( string )` (صف همان لحظه)، `remove_doc_from_location()` و
`add_doc_to_location()` در همین کلاس‌اند. چه قالب «سازگار» باشد (Hello، `elementor_theme_do_location`)
و چه Elementor Pro مکان را «overwrite» کند (`theme-support.php` ⇒ `views/theme-support-header.php`)،
مسیر همان `do_location` است. در کل کد Elementor و Pro Elements، تنها شنوندهٔ دیگر اکشن‌های
`before_do_*` ماژول «floating buttons» است و روی مکان header نیست.

شرط‌ها در متای `_elementor_conditions` ِ پست قالب (مثل `include/general`) و رونوشتی در option ِ
`elementor_pro_theme_builder_conditions` نگه داشته می‌شوند؛ نوع قالب در `_elementor_template_type`.

### ایرادهای 0.1.0 که این بازبینی پیدا کرد
| ایراد در 0.1.0 | پیامد | 0.2.0 |
|---|---|---|
| بافر خروجی از اولویت ۰ ِ `before_do_header` تا آخرین اولویت ِ `after_do_header` | خروجی **هر افزونهٔ دیگری** که روی همین دو اکشن چاپ می‌کند دور ریخته می‌شد | **بی هیچ output buffer**؛ قالب از صف برداشته می‌شود؛ شنونده‌ها: آخرین روی before و اولین روی after |
| بستن بافرهایی که قالب باز گذاشته بود | می‌توانست سازوکار افزونهٔ دیگری را بشکند | دیگر هیچ بافری لمس نمی‌شود |
| ۲۶۵۲ همچنان رندر می‌شد و فقط خروجی‌اش دور ریخته می‌شد | هزینهٔ رندر (و JetMenu) بی فایده | ۲۶۵۲ اصلاً رندر نمی‌شود |
| هر هدری که Elementor چاپ می‌کرد جایگزین می‌شد | هدر ویژهٔ یک صفحه هم ناخواسته عوض می‌شد | **فهرست مجاز**: فقط قالب(های) انتخاب‌شده (۲۶۵۲)؛ بقیه دست‌نخورده |
| هیچ نمایی از شرط‌ها | مدیر باید حدس می‌زد | جدول فقط‌خواندنی قالب‌های هدر، وضعیت و شرط‌ها (و هشدار اگر کش شرط‌ها با متا نخواند) |
| پاک‌سازی کش فقط برای LiteSpeed | روی WP Rocket هدر کهنه می‌ماند | `rocket_clean_domain()` پس از ذخیره، و پس از غیرفعال/فعال‌سازی وقتی جایگزینی روشن است |
| فونت با نشانی نسبی در فایل CSS | Minify ِ WP Rocket فایل را جابه‌جا می‌کند | `@font-face` درون‌خطی با نشانی مطلق |

### Fail-safe ها (هر کدام آزمون دارد)
آرگومانی که Locations_Manager نیست یا متدها را ندارد ⇒ `no_manager` · صف خالی ⇒ `empty_queue` ·
سندی بیرون از فهرست مجاز ⇒ `not_allowed` · صف پس از برداشتن خالی نشد ⇒ اسناد به صف **برمی‌گردند**،
`queue_not_emptied`. در همهٔ این‌ها افزونه **هیچ** چاپ نمی‌کند و Elementor همان قالب خودش را. دو بار
اجرای مکان header یا shortcode ِ پیشین ⇒ باز هم یک هدر. ویرایشگر/پیش‌نمایش Elementor ⇒ هرگز.
برای مدیر، منبع صفحه توضیح `<!-- tecteb-header: … -->` دارد؛ بازدیدکننده (و کش صفحه) چیزی نمی‌بیند.

## ۲. PHP 8.1
- PHPCompatibilityWP با `testVersion 8.1-` روی همهٔ فایل‌ها: **۰ خطا، ۰ هشدار**. هیچ نحو یا تابع
  PHP 8.2+ (readonly class، `true` به‌عنوان نوع، DNF، `json_validate` …) به کار نرفته.
- همهٔ آزمون‌ها روی PHP 8.3.6 با `WP_DEBUG` و `WP_DEBUG_LOG` اجرا شدند و **هیچ** پیام
  notice/warning/deprecated از tecteb-header در debug.log نبود؛ هشدارهای منسوخ‌شدنی که از 8.1 آمدند
  (مثل null به پارامتر رشته‌ای توابع داخلی) در 8.3 هم دیده می‌شوند.
- ⛔ اجرای مستقیم روی PHP 8.1: یک بستهٔ PHP 8.1 دریافت شد ولی **اجرای آن در این محیط اجازه داده نشد**.

## ۳. WP Rocket 3.23.2.2
| موضوع | وضعیت |
|---|---|
| Minify CSS (روشن) | CSS ِ هدر با `matthiasmullie/minify` 1.3.75 (همان کتابخانه‌ای که WP Rocket بسته‌بندی می‌کند؛ نسخهٔ دقیق داخل WP Rocket سنجیده نشده) کوچک شد؛ چیدمان در ۱۴۴۰ و ۳۹۰ **یکسان**، Mega Menu و کشو کار می‌کنند، axe ۰ تخلف. |
| Combine CSS/JS، Defer JS، Delay JS (خاموش) | header.js با `strategy: defer` ِ خودِ وردپرس؛ اگر روزی Delay JS روشن شود، `tecteb-header` را مستثنا کنید (وگرنه منو تا اولین تعامل باز نمی‌شود). |
| Lazyload (خاموش) | لوگو و آیکون‌ها `width/height` دارند؛ آیکون‌ها `loading="lazy"` خودشان. |
| کش صفحه برای مهمان | سبد با cart fragments ِ ووکامرس (پیش‌فرض **روشن**): روی رونوشت کش‌شدهٔ صفحه، شمار واقعی نشان داده شد؛ هزینه: یک درخواست `wc-ajax` در هر نشست. |
| کاربر واردشده | WP Rocket به‌طور پیش‌فرض برای کاربر واردشده کش نمی‌کند؛ وضعیت گزینهٔ «کش برای کاربران واردشده» روی Staging باید گفته شود. |
| `?tth_preview=1` | فقط مدیر (واردشده) و با پارامتر — WP Rocket به‌طور پیش‌فرض نه برای واردشده کش می‌سازد نه برای پارامتر ناشناخته. |
| پاک‌سازی | `rocket_clean_domain()` پس از ذخیرهٔ تنظیمات هدر یا ویرایش/حذف یک دستهٔ **انتخاب‌شده**، و هنگام غیرفعال/فعال‌سازی وقتی جایگزینی روشن است. با بدل آزموده شد، نه با WP Rocket ِ واقعی. |
| Remove Unused CSS | وضعیتش اعلام نشده؛ اگر روشن است، حالت‌های باز (Mega، کشو، حساب) را روی Staging ببینید. |

## ۴. JetMenu 3.0.4
هدر تازه هیچ تابع یا دادهٔ JetMenu را صدا نمی‌زند و به آن وابسته نیست. وقتی ۲۶۵۲ جایگزین می‌شود،
رندر نمی‌شود، پس Mega Menu ِ JetMenu (و قالب‌های Elementor ِ درونش) هم رندر نمی‌شوند. اسکریپت/CSS ِ
سراسری JetMenu اگر جدا از ابزارک بارگذاری شود همچنان می‌آید — سنجیده‌نشده. در انتقال دسته‌ها،
پیوندهای سفارشی «منوی اصلی» فقط با **نشانی** به دسته‌های واقعی نگاشته می‌شوند؛ متای
`jet_menu_settings` خوانده نمی‌شود.

## ۵. WooCommerce 11.0.1
همان نسخهٔ Staging، واقعی، در محیط محلی: حساب (`wc_get_account_menu_items`، `wc_logout_url`)، سبد
(`WC()->cart`، fragments)، جست‌وجو (`?s=…&post_type=product`) و `product_cat` آزموده شد. هیچ نوشتنی
روی داده‌های ووکامرس (اثرانگشت جدول‌ها پیش و پس یکسان).
