# فاز ۲۲ — دسته از ووکامرس می‌آید، نه از الگوی مشخصات

**نسخه:** `0.1.0-alpha.25` · **ساختار داده:** ۱۸ (migration تازه‌ای اضافه نشد)

---

## ۱. علت، در کد

گزارش مالک درست بود، و علتش یک خط بود که خودش را توضیح هم داده بود:

```php
/** Categories are the manager's templates: the vendor picks from what exists. */
private function categories(): array
{
    foreach ($this->templates()->all() as $template) { … }
}
```
— `ProductArea::categories()` تا `alpha.24`

دراپ‌داون «دسته» از **الگوهای مشخصات** پر می‌شد، نه از `product_cat`. روی سایت
شما ۱٬۰۷۰ دسته هست و **صفر الگو**، پس فهرست خالی بود. و چون
`ProductDetails::missingFields()` نبودِ دسته را «ناقص» می‌شمارد، مرحلهٔ ۱ هرگز
کامل نمی‌شد و هیچ محصولی به بررسی نمی‌رسید. این همان زنجیره‌ای است که دیدید.

نیمهٔ دوم بدتر بود و از صفحه پیدا نمی‌شد:

```php
$slug = 'tmc-' . sanitize_title($categoryKey);
$term = get_term_by('slug', $slug, 'product_cat');
if (!$term) { wp_insert_term($label, 'product_cat', ['slug' => $slug]); }
```
— `WooCommerceProjector::categoryIds()` تا `alpha.24`

یعنی افزونه **دستهٔ تازه می‌ساخت**: یک تاکسونومی موازی کنار همان ۱٬۰۷۰ تا، که
منوها، فیلترها، قالب‌های Elementor و سایت‌مپ Rank Math شما هیچ خبری از آن
ندارند. همان چیزی که نوشتید نمی‌خواهید.

## ۲. آنچه عوض شد

| خواستهٔ شما | چه شد |
|---|---|
| منبع دسته‌ها `product_cat` همان محیط باشد | `ProductCategoryDirectoryInterface` + `WpProductCategoryDirectory`: یک `get_terms(['hide_empty' => false])`، و **مقدار ذخیره‌شده شناسهٔ ترم است** |
| حفظ شناسه، سلسله‌مراتب و دسته‌های بدون محصول | شناسه همان `term_id` است؛ مسیر والد در PHP از همان یک کوئری ساخته می‌شود؛ `hide_empty=false` |
| جست‌وجو و مسیر والد برای بیش از هزار مورد | جعبهٔ جست‌وجو + نتایج رادیویی با **کل مسیر**؛ سقف ۴۰ مورد و اعلام تعداد کل |
| الگو به شناسهٔ دستهٔ موجود وصل شود | صفحهٔ «الگوهای مشخصات» حالا دسته را **انتخاب** می‌کند؛ فیلد «کلید دسته (انگلیسی)» حذف شد |
| نبود الگو دسته را پنهان نکند | هر دسته انتخاب‌شدنی است؛ `ProductReadiness` از قبل هم الگوی غایب را مانع نمی‌دانست و همین سنجیده شد |
| ذخیرهٔ پیش‌نویس ممکن باشد | دکمهٔ جست‌وجو **خودش یک ذخیره است**، پس عنوان و برندِ تایپ‌شده از بین نمی‌رود |
| دسته‌ها، محصول‌ها و طراحی فعلی حفظ شوند | هیچ‌چیز در تاکسونومی نوشته نمی‌شود — آزمون معماری همین را می‌سنجد |

**سازگاری با داده‌ای که از قبل هست.** هیچ migration ای کلیدها را بازنویسی
نمی‌کند: بازنویسی یعنی حدس‌زدن اینکه هر واژه کدام ترم را نشان می‌داده، و حدسِ
غلط مشخصات پزشکیِ اشتباه را به محصول کسی می‌چسباند. به‌جایش
`AliasingSpecTemplateRepository` دو بار می‌پرسد — یک بار با مقدارِ ذخیره‌شده و
یک بار با نام دیگرِ همان ترم — پس الگوی قدیمی و محصول تازه همدیگر را پیدا
می‌کنند.

**بازگشت امن.** `WpProductCategoryDirectory::find()` یک کلید قدیمی را هم به
ترم `tmc-…` ای که نسخهٔ قبل ساخته بود می‌رساند، پس محصول‌های موجود شما دسته‌شان
را از دست نمی‌دهند. و اگر مقداری اصلاً قابل‌تبدیل نباشد، projector دستهٔ فعلیِ
محصول را **نگه می‌دارد**؛ پاک نمی‌کند.

## ۳. شواهد — روی ووکامرس واقعی

**الف) مسیر نصب تمیز، با کلیک: ۵۳ بررسی، ۰ شکست**
(`docs/evidence/owner-guide/01-checks.txt`)

درخت سه‌سطحی از خودِ wp-admin ساخته می‌شود — «تجهیزات پزشکی › بیهوشی و تنفسی ›
آمبوبگ» — و **هیچ الگویی برایش ساخته نمی‌شود**. سپس:

```
ok:   2-4. a three-level WooCommerce category tree exists            created
ok:   2-4. and the third level really is a grandchild, not a second root nested
ok:   2-4. a fresh install has no spec template                      none yet
ok:   2-4. and the template form offers real categories rather than a free-text key
      category chosen in the form: 18
ok:   4-1. WooCommerce lists the product under the category the vendor chose listed
ok:   4-1. and no tmc- category was invented beside it               none invented
```

**ب) خوانده‌شده از خودِ ووکامرس با WP-CLI: ۹ بررسی، ۰ شکست**
(`tools/category-source-check.sh` · `docs/evidence/category-source/checks.txt`)

```
ok:   a three-level category exists                              20
ok:   its own parent has a parent                                19
ok:   the deep leaf has no products at all                       0
ok:   two different branches end in the same name                2
ok:   no spec template exists for these categories               0
      product 22 carries product_cat ids: 18
ok:   the WooCommerce product is filed under the chosen term     18
ok:   and under exactly one term, not a duplicate beside it      1
ok:   no tmc- category was invented for it                       0
```

هیچ‌کدام از این‌ها کد ما را صدا نمی‌زند: ترم‌ها با `wp term create` ساخته
می‌شوند و جواب از `wp post term list` خوانده می‌شود — ابزار خودِ سایت.

**پ) آزمون‌ها.** ۹ بررسی قراردادی تازه (`ProductCategorySourceTest`) روی درخت
سه‌سطحی، دستهٔ خالی، دو برگ هم‌نام، ۱٬۰۷۰ دسته با سقف، و کلید قدیمی. و یک
**آزمون معماری** که هر `wp_insert_term`/`wp_update_term`/`wp_delete_term`/
`register_taxonomy` را در کل `src/` ممنوع می‌کند — با برگرداندن همان فراخوان،
آزمون شکست می‌خورد (سنجیده شد).

اجرا: unit ۲۸۵ · architecture **۲۲** · contract **۱۲۷** · database **۲۳۹** ·
packaging ۱۹ · lint ۴۶۴ فایل روی ۸٫۴ و ۸٫۱ — همه ۰ شکست.

## ۴. دو مورد دیگر

**لوگو و بنر.** هر دو از `alpha.5` فیلد عددی بودند، با راهنمایی که می‌گفت
«انتخابگر تصویر در مرحله محصولات اضافه می‌شود» — و هرگز اضافه نشد. فروشنده
wp-admin ندارد، پس راهی نداشت شناسهٔ پیوست را بداند: فیلد برای تنها کسی که
استفاده‌اش می‌کرد، غیرقابل‌استفاده بود. حالا انتخاب فایل + **پیش‌نمایش تصویر
فعلی**، از همان مسیر بارگذاری که فرم محصول از `alpha.6` دارد. فرم
`enctype="multipart/form-data"` گرفت — بدون آن مرورگر نام فایل را می‌فرستد و
بایت‌ها را نه، و ذخیره بی‌صدا هیچ‌کاری نمی‌کند.

**پنل «بدون درخواست» پس از ورود مجدد.** علت **قطعی نشده** و این‌طور هم گزارش
نمی‌شود. آنچه از کد می‌دانیم:

- `findApplicationByUser()` یک `SELECT … WHERE user_id = %d` بدون هیچ کشی است.
  پس ردیفِ کهنه‌ای در کار نیست.
- مسیر `/vendor/` کاربر خارج‌شده را به فرم ورود **هدایت** می‌کند؛ پنل خالی
  نشانش نمی‌دهد. پس آن HTML را این مسیر تولید نکرده.

این دو با هم می‌گویند صفحه‌ای که دیدید **خروجیِ آن درخواست نبوده** — که کارِ
یک page cache است، و سایت شما یکی دارد (WP Rocket در نوار مدیر پیداست).
**این فرضیه است، نه تشخیص.** آنچه فرضیه نیست: تا این نسخه افزونه فقط
`nocache_headers()` می‌زد، آن هم در آخرین لحظهٔ رندر — و page cacheها
`Cache-Control` نمی‌خوانند، ثابت‌ها را می‌خوانند. حالا
`DONOTCACHEPAGE`/`DONOTCACHEOBJECT`/`DONOTCACHEDB` **در لحظهٔ شناسایی مسیر**
تعریف می‌شوند، پیش از هر هدایت و هر رندر. این قرارداد را WP Rocket، W3 Total
Cache، LiteSpeed و WP Super Cache همگی رعایت می‌کنند.

اگر دوباره دیدید، این دو را بفرستید تا از فرضیه در بیاید: هدرهای همان پاسخ
(`curl -I` یا تب Network) و اینکه آیا `x-rocket-cache`/`x-cache` در آن هست.

## ۵. آنچه این دور ثابت نمی‌کند

- نصب روی `tecteb.com` یا `staging.tecteb.com` — **انجام نشده**.
- رفتار با **۱٬۰۷۰ دستهٔ واقعی شما** روی همان سایت: اینجا با ۱٬۰۷۰ دستهٔ
  ساختگی در آزمون و با درخت سه‌سطحی روی ووکامرس واقعی سنجیده شد.
- الگوهای قدیمیِ خودِ شما: aliasing آزمون دارد، ولی روی دادهٔ شما اجرا نشده.
- علت «بدون درخواست» — فرضیه است، و بالا صریح گفته شده.
