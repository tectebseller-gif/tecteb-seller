# Tecteb Marketplace Core — راهنمای کار در این مخزن

## محدوده فعلی: فاز ۱ + فروشندگان + محصولات + ووکامرس و سفارش + تسویه + ارسال جزئی، مرجوعی، کوپن/B2B/تیکت و مهاجرت آزمایشی دکان + نظر و امتیاز و نمودار + **صف، API و رویداد، ممیزی و تجربهٔ فرم**
زیرساخت و **چهار صفحه مدیریت** (پیشخوان، سلامت، تنظیمات، ماژول‌ها)، و از
۱۲ سپتامبر **بخش فروشندگان** به دستور مالک: درخواست فروشندگی، مدارک پویا،
بررسی مدیر و پیشخوان فروشنده روی مسیر `/vendor/`
(`docs/phase-2-vendor-delivery.md`).

از `alpha.12` نظر و امتیاز و نمودار گزارش‌ها هم ساخته شده‌اند. آنچه باقی است
**تصمیم است نه کد**: DEC-03 (شرایط مرجوعی)، DEC-04 (کوپن سراسری)، DEC-05
(تعرفهٔ عمده و مدت نگهداری تیکت) و درگاه پرداخت. **SEO خودکار عمداً ساخته
نمی‌شود** — Master A.5 می‌گوید «فروشنده فیلد تخصصی SEO ندارد» و SEO مال مدیر است.
(پیوست خصوصی تیکت، اعلان‌های داخل پنل و گزارش‌های مصوب در `alpha.11` ساخته
شدند؛ کوپن روی سبد واقعی، نردبان عمده و انتقال صریح مالکیت در `alpha.10`.)
هیچ sender/gateway واقعی وجود ندارد؛ خروجی‌های افزونه در Alpha همیشه بسته‌اند و
**تأیید موبایل انجام نمی‌شود** تا وقتی Adapter واقعی و مستند وجود داشته باشد.

**وضعیت جاری:** فاز ۱ پیاده‌سازی شد، **چهار دور بازبینی سورس** روی آن اعمال شد،
و پروتکل پذیرش (G-01 تا G-09 به‌علاوه ارتقا/بازیابی) روی یک **WordPress
یکبارمصرف** با WooCommerce واقعی، دو بار — روی PHP 8.1.32 و 8.4.19 — اجرا و
قبول شد. دسترس‌پذیری و چیدمان روی همان wp-admin واقعی با **زبان مدیریت fa_IR و RTL**
اجرا شد (با **بسته ترجمه حداقلی آزمایشی**) — ۵ viewport، شبیه‌سازی فضای چیدمان،
device scale factor سطح مرورگر، کیبورد با Tab واقعی، و axe: دو اجرا، هرکدام
۲۵۲ بررسی، ۰ شکست. زوم صفحه از منوی مرورگر و ترجمه رسمی فارسی `Not Run`.
اجرای گیت‌ها یک اشکال واقعی پیدا کرد (بند ۱۴ اصلاح‌های بازبینی) که اصلاح شد.
نصب روی `tecteb.com` یا `staging.tecteb.com` **انجام نشده و در مجوز فعلی نیست**؛
PHP 8.1.34 خودِ سایت، سرور وب واقعی، تداخل با افزونه‌های موجود، screen reader
دستی و مرورگرهای غیر Chromium همچنان `Not Run`.
گزارش: `docs/phase-1-report.md` بند ۳ · شواهد: `docs/evidence/acceptance/` ·
اصلاح‌های بازبینی: `docs/review-fixes-phase-1.md` · تحویل بعدی: `docs/next-phase-handoff.md`.

**ارتقا و بازگشت (۱۴ سپتامبر):** دو مسیر روی همان وردپرس یکبارمصرف اجرا شد —
`alpha.1 → alpha.4` (همان بسته‌ای که روی staging نصب است) و `alpha.3 → alpha.4`
— هرکدام ۳۵ بررسی و ۰ شکست. migration فقط افزودنی است و نسخه قدیمی ساختار را
پایین نمی‌آورد، پس **بازیابی دیتابیس برای بازگشت لازم نیست**. ولی «فقط ZIP را
عوض کن» دستور کاملی **نیست**: پیش از تعویض باید فروش متوقف، جاماندگان بسته و
سفارش‌های نیازمند بررسی تعیین تکلیف شده باشند — ترتیب پنج‌گامی در
`docs/upgrade-and-rollback.md` بند ۵٫۲-ب (`docs/upgrade-and-rollback.md` ·
`docs/evidence/upgrade-alpha1/` · `docs/evidence/upgrade/`).

**`0.1.0-alpha.13` (۱۶ سپتامبر):** پاسخ به **گزارش بررسی بیرونی**. ساختار داده
**۱۳**. **هر یازده یافتهٔ گزارش درست بود**؛ نُه‌تا تکمیل شد، SEO/sitemap عمداً باز
ماند و نسخه‌بندی با همین بسته بسته شد
(`docs/review-response-alpha13.md` — یافته‌به‌یافته با مسیر فایل و شاهد).

- **صف کار زمان‌بر با نقطهٔ توقف ستونی.** `tmc_jobs`: `cursor` را کارگر بعد از
  هر دسته می‌نویسد و کارگر بعدی — که process دیگری دقایقی بعد است — از همان‌جا
  ادامه می‌دهد. `claim()` **یک `UPDATE`** است و برنده را تعداد سطر تعیین می‌کند؛
  SELECT-then-UPDATE همان مسابقه‌ای است که دو بازدیدکنندهٔ هم‌زمان می‌برند.
  `live_key` عمداً nullable است («یک کار **زندهٔ** هر کلید») و **در همان دستوری**
  پاک می‌شود که کار را terminal می‌کند.
- **اتصال cron در زمان اجرا تعیین می‌شود و گزارش می‌گوید کدام است.** Action
  Scheduler وقتی ووکامرس آورده باشدش، وگرنه WP-Cron. **WP-Cron ساعت نیست**: با
  بازدید اجرا می‌شود و `DISABLE_WP_CRON` خاموشش می‌کند — صفحهٔ صف همین را
  می‌گوید، کنار دکمهٔ «اجرای دستی» که دقیقاً به همین دلیل آنجاست.
- **ارسال بیرونی رویداد بسته است و اندازه‌گیری شد.** `tmc_event_outbox` با امضای
  HMAC روی بایت‌های canonical (کلید مرتب، فلگ ثابت، timestamp **داخل** مادهٔ
  امضاشده). `DeliverEventsJob` هر ردیف را `blocked` با دلیل `outbound_blocked`
  می‌کند: **۸ رویداد، ۸ بسته، ۰ خطا، payload و امضا دست‌نخورده**. `blocked`
  شکست نیست و terminal است.
- **`tecteb/v1` مجوز خودش را نمی‌سازد.** نه جدول کلید، نه bearer: کاربر وردپرس
  + `StaffAccess`. فروشندهٔ دیگر **۴۰۳** می‌گیرد نه فهرست خالی — فهرست خالی به
  مهاجم می‌گوید کدام شناسه‌ها هستند. هیچ مسیری نمی‌نویسد.
- **ممیزی از نوشتاری‌محض درآمد.** تا این نسخه `AuditRepositoryInterface` فقط
  `insert()` داشت: جدولی که فقط نوشته می‌شود یک وعده است نه یک قابلیت. حالا
  `search()` روی همان ایندکس‌ها، با مجوز **خودش** (`tmc_view_audit`) چون رد،
  کارِ هر مدیر را ثبت می‌کند.
- **wizard هیچ تنظیمی از خودش ندارد.** هر گام وضعیتش را از همان مقداری می‌خواند
  که صفحهٔ عادی می‌نویسد؛ پس نصبی که بدون این صفحه تنظیم شده کامل است، و مقداری
  که پاک شود گامش را ناقص می‌کند. «منتظر تصمیم» جواب درجه‌یک است.
- **ذخیرهٔ خودکار ≠ حفظ دادهٔ POST خطادار.** تبِ بسته و لپ‌تاپِ خوابیده POST
  نمی‌کنند. draft در user meta است (transient منقضی می‌شود)، **پیشنهاد** می‌شود
  و اعمال نمی‌شود، و فقط با ذخیرهٔ **موفق** پاک می‌شود.
- **تعارض رد می‌شود، merge نمی‌شود.** فرم `updated_at` را حمل می‌کند؛ مهرِ کهنه
  ⇒ `stale_revision` با هر دو مهر. merge باید حدس بزند کدام قیمت درست است، و
  حدسِ غلط دربارهٔ قیمت پول است. مهرِ **خالی** هنوز ذخیره می‌شود (فرم نسخهٔ قدیمی).
- **`LIMIT 500` بی‌صدا حذف شد.** فروشگاهی با ۶۰۰ سفارش دکان، ۵۰۰تا را می‌دید و
  گزارش می‌گفت کامل بود. حالا keyset تا صفحهٔ کوتاه.

تحویل: `docs/phase-11-queue-api-and-product-ux.md` · تصمیم‌های باز یک‌جا:
`docs/open-decisions.md` · مرجع درگاه: `docs/otp-gateway-contract.md` ·
شواهد: `docs/evidence/operations/` (۵۹)، `docs/evidence/product-ux/` (۲۶)،
`operations/wpadmin-a11y/` (۲۸۰).

**سه قاعدهٔ تازه — هر سه را خودِ شواهد پیدا کردند، نه آزمون واحد:**
- **آمادگی یک hook است، نه یک تابع.** `as_has_scheduled_action()` روی
  `plugins_loaded` یک `_doing_it_wrong` واقعی داد: توابع Action Scheduler
  به‌محض ووکامرس اعلان می‌شوند، ولی انبارشان روی `init` ساخته می‌شود — پس جواب
  از انباری می‌آمد که نبود و action روی **هر درخواست** دوباره زمان‌بندی می‌شد.
  حالا `ActionScheduler_Store` **و** `did_action('action_scheduler_init')`.
- **در `UPDATE`، صفر هم «موفق» است و هم «مبهم».** MySQL سطرهای **تغییرکرده** را
  می‌شمارد و wpdb پرچم `CLIENT_FOUND_ROWS` را ست نمی‌کند، پس checkpointی که
  همان cursor را در همان ثانیه نوشت هیچ ستونی عوض نکرد و `> 0` آن را «مالکیت را
  از دست دادی» خواند. ادامهٔ قاعدهٔ `alpha.8`: اگر تصمیمی به صفر وابسته است، از
  خودِ سطر بپرسید.
- **آزمونی که ثبت را می‌سنجد، کارکرد را نمی‌سنجد.** چهار صفحهٔ تازه با slug و
  مجوز درست ثبت شده بودند و **هر چهارتا لحظهٔ باز شدن استثنا می‌دادند**
  (`Request` سازندهٔ private دارد). `tests/Database/AdminPagesRenderTest.php`
  حالا هر صفحهٔ ثبت‌شده را **رندر** می‌کند — و بلافاصله دو نقص دیگر هم پیدا کرد.

**و یک قاعدهٔ ابزار:** *شاهدی که چیز غلطی را می‌سنجد، بدتر از نبودنش است.*
اولین «دو کارگر، یک برنده» روی صفی با کار باقی‌مانده `winners=2` داد — که نقص
نبود: دو کارگر **دو کار متفاوت** گرفته بودند. سؤال درست «آیا یک کار دوبار claim
شد» است، و برای پرسیدنش صف باید اول خالی شود.

**`0.1.0-alpha.12` (۱۶ سپتامبر):** چهار بند مالک. ساختار داده **۱۲**.
ایراد موجودی از «محدودیت مستند» به **اصلاح‌شده** رسید.

- **حالا هیچ موجودی اضافه‌ای ساخته نمی‌شود.** `hold()` پیش از حرکت
  `_tmc_stop_stock_was_reduced` را ثبت می‌کند و `release()` فقط برای همان یک
  گذار `wc_maybe_increase_stock_levels` را برمی‌دارد و در `finally` برش
  می‌گرداند. **هیچ عدد مطلقی نوشته نمی‌شود**، پس فروش هم‌زمان محفوظ می‌ماند:
  اندازه‌گیری‌شده `concurrent: 4999 → 4995 → 4996` (فروش ۳تایی زنده ماند) و
  `prereduced: 4999 4999 4999` (دیگر +۱ نمی‌دهد). ۵۲ بررسی.
- **آنچه تطبیق نشود «موفق» اعلام نمی‌شود:** فهرست `reconcile` جدا از
  `released`، متای بازگردانی **پاک نمی‌شود** تا تلاش دوباره پیدایش کند، و
  سفارشی که وسط کار پرداخت یا لغو شده در `moved_on` می‌آید.
- **استثنا، «قطع اجرا» نیست.** `wc_create_refund()` هر استثنایی را می‌گیرد و
  خودش `$refund->delete(true)` می‌زند — یعنی استثنا رکورد را **جمع می‌کند**. آزمون
  با `exit` روی `woocommerce_order_partially_refunded` انجام شد. تلاش دوباره
  رکورد یتیم را با مهر `_tmc_return_id` پیدا و adopt می‌کند: `refund_recovered`،
  بدون refund دوم، بدون موجودی اضافه، بدون خط مالی دوم. ۲۷ بررسی.
- **مهر روی `woocommerce_create_refund` نوشته می‌شود، نه بعد از آن** — آن هوک
  **پیش از** `$refund->save()` اجرا می‌شود، پس شناسه و مهر یک insert اند.
- **نظر محصول، دیدگاه خودِ ووکامرس است.** جدول دوم ساخته نشد. قاعدهٔ «فقط
  خریدار واقعی» فقط روی محصولات بازارگاه اعمال می‌شود — گزینهٔ سراسری ووکامرس
  عمداً دست نخورد چون مال خودِ فروشگاه است. امتیاز **فروشگاه** جدول ماست
  (`tmc_vendor_ratings`, `UNIQUE(order_item_id)`).
- **«فروشنده فقط پاسخ می‌دهد» با نبودِ متد اجرا می‌شود، نه با گارد.** در
  `ManageReviews` هیچ متد تأیید/رد/ویرایش برای فروشنده وجود ندارد؛ آزمون شواهد
  نبودش را با grep روی امضاها می‌سنجد. ۴۵ بررسی.
- **نمودارها HTML و CSS اند، نه SVG.** نسخهٔ اول SVG بود و سوییت دسترس‌پذیری
  ردش کرد: SVG متن خودش را همراه نقاشی کوچک می‌کند، و روی ۳۲۰px برچسب‌ها ~۹px
  پهنا داشتند (فونت ~۵px). حالا میله یک `<span>` با پهنای درصدی است و هر کلمه
  متن معمولی HTML. میله‌ها **افقی** اند چون ستون روی موبایل ~۴۵px است.
  بدون کتابخانه، بدون CDN، بدون `<script>`. **ارقام پولی نمودار نمی‌گیرند.**
  اندازه‌گیری: ۲۴۰ بررسی a11y، ۰ شکست، باریک‌ترین برچسب ۹۶px.

تحویل: `docs/phase-10-stock-refund-reviews-and-charts.md` · شواهد:
`docs/evidence/guard-stock/`، `docs/evidence/refund-crash/`،
`docs/evidence/reviews/` (با `screens/` و `measurements.json`).

**سه قاعدهٔ تازه:**
- **متن داخل SVG با نقاشی کوچک می‌شود.** هر چیزی که با `viewBox` مقیاس می‌گیرد،
  فونتش هم مقیاس می‌گیرد — و SVG نه سطربندی دارد نه راهی که متن به اندازهٔ
  خواننده بماند. متن را در HTML بگذارید و فقط شکل را در CSS بکشید.
- **`.tmc-scroll` بدون CSS، اسکرول نیست.** کلاس روی `role="region"` بود و
  هیچ‌وقت `overflow-x` نگرفته بود، پس جدول عریض **کل صفحه** را افقی می‌کشید.
  در ۳۹۰px پیدا شد و اصلاح شد. هر ناحیهٔ اسکرول باید اندازه‌گیری شود، نه فرض.
- **آزمونی که چیزی را «خرج می‌کند» باید اول reset کند.** یک خرید یک‌بار امتیاز
  می‌گیرد و یک نظر یک‌بار پاسخ؛ اجرای دوم روی بازماندهٔ اجرای اول شکست می‌خورد.
  `review-state.php reset` هست، **و خریدارِ هر اجرا تازه ساخته می‌شود** چون
  `wc_customer_bought_product()` کل تاریخچهٔ سفارش را می‌خواند.
- **`cut -c` بایت می‌شمارد نه حرف.** هر حرف فارسی دو بایت است، پس برشِ بایتی
  «متن» هرگز با «متن» برابر نمی‌شود. در شواهد `grep -q` بزنید.

**بازبینی دوم `alpha.12` (۱۶ سپتامبر):** شش بند مالک؛ **سه‌تای اول نقص واقعی
بودند** و سومی ادعای خودِ ما را رد کرد.

- **نشانهٔ «نیازمند بررسی» اجرای بعدی را دوام نمی‌آورد.** سفارشِ تطبیق‌نشده
  وضعیتش **قبلاً برگشته بود**، پس `release()` بعدی آن را `moved_on` می‌گرفت و
  `forget()` نشانه و کل ردِ موجودی را پاک می‌کرد — همان یک اجرایی که مشکل را
  گزارش کرد، آخرین اجرایی بود که از آن خبر داشت. حالا تشخیص `RECONCILE_META`
  **پیش از** شاخهٔ `moved_on` است و فقط `resolveReconciliation()` پاکش می‌کند.
- **یادداشت مدیر روی خودِ سفارش ووکامرس نوشته می‌شود**، نه فقط در audit ما — چون
  تمام دلیلش این است که پس از تعویض بسته هم خوانده شود.
- **متای غایب «نامعلوم» است، نه `false`.** `=== '1'` یعنی سفارشی که `alpha.11`
  نگه داشته بود (پیش از وجود پرچم) یا توقفش نیمه‌ذخیره شده بود، «کم نشده» خوانده
  می‌شد و بازگرداندن یکی اضافه می‌کرد. حالا `stock_state_unknown` → تطبیق دستی.
- **«شناسه و متا یک insert اند» غلط بود.** از ترتیب هوک‌ها خوانده شده بود نه از
  data store. دو کشف: **این سایت HPOS دارد** (پس مسیر CPT اصلاً اجرا نمی‌شود)، و
  هر دو backend یک شکل دارند — `persist_order_to_db()` → `update_order_meta()`
  → `save_meta_data()` (مهر ما)، **بدون transaction**، و `post_excerpt` یک
  refund خالی است پس هیچ چیز در خودِ ردیف نام مرجوعی را نمی‌برد.
- **نشانهٔ تلاش (intent marker)** روی سفارش والد، پیش از `wc_create_refund()`.
  تلاش دوباره سه حالت دارد: مهرخورده ⇒ `refund_recovered`؛ نشانه + refund بی‌مهر
  ⇒ **`refund_reconcile_required`** با شماره‌ها به مدیر؛ نشانه بدون هیچ refund
  ⇒ ساختن امن. **adopt بر اساس شباهت انجام نمی‌شود** — یک refund بی‌مهر ممکن است
  refund دستیِ خود مدیر باشد و چیزی که حدس زده می‌شود پول است.
- **راهنمای بازگشت:** «فقط ZIP را عوض کن» حذف شد؛ پنج گام با شرط خروج
  (`docs/upgrade-and-rollback.md` ۵٫۲-ب) و «اگر بدون توقف عوض کردید» (۵٫۲-پ).
- **یک مسیر پذیرش یکپارچه:** `tools/acceptance-path.php` هفت مرحله را در **یک
  پاس PHP** اجرا می‌کند، `tools/acceptance-check.sh` ۲۷ بررسی، و
  `shoot-acceptance.mjs` ۱۲ صفحه × ۲ اندازه از چشمِ صاحبِ هر صفحه.
- **سه موردِ واقعاً ناقص بسته شد** (تطبیق با تمام اسناد، بند ۵ مالک):
  **صفحهٔ عمومی فروشگاه** (MS §۷ · A.5) با title/description/canonical/OG و
  JSON-LD `Store`، **نشان «فروشندهٔ تأییدشده»** — تنها نشان v1 — و **تعطیلی
  موقت که تا این دور فقط ذخیره می‌شد و هیچ اثری نداشت** (`isClosedOn()` هیچ
  فراخوانی نداشت). حالا خرید تازه را `vendor_closed` می‌کند و **سفارش‌های قبلی
  ادامه می‌یابند** — اندازه‌گیری‌شده، نه ادعا. `store-page-check.sh` ۳۲ بررسی.
- **آنچه صفحهٔ عمومی نشان نمی‌دهد، مشخصات آن است.** انبار مبدأ، شبا، ایمیل و
  موبایل و نشانی متقاضی **اصلاً خوانده نمی‌شوند** — نه فیلتر می‌شوند. شواهد
  هر پنج‌تا را با **مقدار واقعی** پر می‌کند و نبودشان را در بایت‌های صفحه
  می‌سنجد؛ صفحه‌ای که فیلد خالی را نشان ندهد هیچ چیزی ثابت نمی‌کند.
- **جدول نهایی چهارستونی** در `docs/feature-inventory.md` بند ۰-الف:
  **۴۵ کامل و آزموده · ۰ ناقص · ۱۰ منتظر تصمیم مالک · ۸ منتظر اتصال یا دسترسی**.

شواهد: guard-stock ۷۳ · refund-crash ۴۵ · acceptance-path ۲۷ · reviews ۴۸ ·
reviews a11y ۲۴۰ — همه ۰ شکست.

**چهار قاعدهٔ تازه:**
- **مسیر ذخیره‌سازی را از data store بخوانید، نه از ترتیب هوک‌ها.** و **اول
  بپرسید کدام backend اجرا می‌شود**: این سایت HPOS دارد و بخش زیادی از تحلیل
  اولیه مسیری را توصیف می‌کرد که هرگز اجرا نمی‌شود.
- **probe ای که اجرا نمی‌شود دقیقاً شبیه probe ای است که شرطش پیش نیامده.**
  سه حدسِ خاموش تا پیدا شدن `added_order_refund_meta`. بررسیِ کنار هر probe باید
  **کد خروج** را بسنجد، نه فقط نتیجه را.
- **زیر HPOS، مبلغ و دلیل refund ستون‌اند نه متا** — پس هیچ‌وقت به hook متا
  نمی‌رسند.
- **مسیر چندمرحله‌ای را در یک پاس بنویسید، نه زنجیرهٔ shell.** زنجیره‌ای که هر
  شناسه را از stdout قبلی استخراج می‌کند، با یک خط خروجیِ عوض‌شده بی‌صدا یک مرحله
  را رد می‌کند.

**`0.1.0-alpha.11` (۱۵ سپتامبر):** پنج ایراد مالک روی `WcUnpaidOrderGuard` —
**هر پنج‌تا درست بود** — به‌علاوهٔ پیوست تیکت، اعلان و گزارش. ساختار داده **۱۱**.

- **متا دلیل موفقیت توقف نیست.** کد قبلی هر سفارشی را که متا داشت رد می‌کرد، پس
  توقفِ شکست‌خورده «موفق» بود و تلاش دوباره تا ابد از رویش می‌پرید. حالا هر
  تصمیم از **وضعیت** گرفته می‌شود، پس از خواندن دوباره.
- **متای بازگردانی فقط پس از تأیید گذار پاک می‌شود.** قبلاً اول پاک می‌شد و بعد
  وضعیت عوض؛ یک ذخیرهٔ ناموفق تنها رکورد «کجا بود» را می‌کشت.
- **هرگز کوئری‌ای را که داری تغییرش می‌دهی پیمایش نکن.** صفحه‌بندی ۲۰۰تایی روی
  کوئریِ در حال تغییر، صفحه‌ها را جا می‌انداخت. حالا اول snapshot شناسه‌ها.
  اندازه‌گیری‌شده: **۲۱۲ سفارش، همه در یک پاس، `stuck=-`**.
- **موجودی نامتقارن است و اندازه‌گیری شد:** `pending` متقارن و بدون drift؛
  ولی سفارشی که **از قبل** موجودی را کم کرده بود با بازگرداندن **+۱** می‌دهد،
  و سبد مخلوط موجودی کالای خود فروشگاه را هم تکان می‌دهد.
- **تناقض راهنما برطرف شد:** «بازگرداندن سفارش‌های نگه‌داشته» **پرداخت را باز
  می‌کند** (به `pending`/`failed` برمی‌گرداند) و پس از غیرفعال‌سازی هم باز
  می‌ماند. برای rollback لازم نیست و توصیه نمی‌شود.
- **پیوست خصوصی تیکت:** بیرون از هر ریشهٔ وب، نوع از روی **بایت‌ها**، ۵ مگابایت
  و ۳ فایل در هر پیام، یک route با nonce + `nosniff` + CSP sandbox، و
  پنهان‌کردن به‌جای حذف (پس از پنهان‌شدن حتی فرستنده هم نمی‌بیند).
- **اعلان داخل پنل:** هیچ `send()` ای وجود ندارد. آدرس‌دار (هر عضو ردیف خودش)،
  خوانده/نخوانده با `read_at`، یک‌بار با ایندکس یکتا، و صندوق فقط مالِ خودِ شخص.
- **گزارش‌های مصوب:** چهار کارت فروشنده و دو کارت مدیر، محاسبه‌شده نه ذخیره‌شده.
- **«ثبت refund ووکامرس» از «انتقال وجه» جدا شد** و هر دو وابستگی نام‌دار شد:
  `wc_create_refund()` با `refund_payment=false` **درگاه را صدا نمی‌زند**، پس
  رکورد صادقانه ساخته می‌شود؛ پول نیاز به درگاهِ نصب، `supports('refunds')` و
  `transaction_id` دارد که هیچ‌کدام نیست — و همان یک خروجی هر سه را نام می‌برد.

تحویل: `docs/phase-9-guard-attachments-notices-and-reports.md` · شواهد:
`docs/evidence/guard-stock/`، `docs/evidence/engagement/`.

**سه قاعدهٔ تازه:**
- **`WC_Order::save()` استثنای خودش را می‌بلعد** (`handle_exception`). موفق
  برگشتنش هیچ چیزی ثابت نمی‌کند؛ سفارش را دوباره بخوانید.
- **اول snapshot، بعد نوشتن** — برای هر پیمایشی که وضعیت را عوض می‌کند.
- **`bash tools/disposable-site.sh`** محیط یکبارمصرف را برمی‌گرداند. کانتینر
  تازه فایل‌ها را نگه می‌دارد ولی **کاربر دیتابیس** و وب‌سرور را نه؛ علامتش
  «Error establishing a database connection» است که شبیه دیتابیسِ ازدست‌رفته
  به‌نظر می‌رسد و نیست.

**`0.1.0-alpha.10` (۱۵ سپتامبر):** بازخورد پنج‌بندی مالک. ساختار داده **۱۰**.
**سه مورد از پنج، نقص واقعی بود** — اندازه‌گیری شد و اصلاح شد:

- **چرخاندن `order_key` توقف پرداخت نیست.** با افزونهٔ غیرفعال، مشتری از «حساب
  من» لینک **تازه** می‌گیرد و فرم پرداخت رندر می‌شود. حالا سفارش به **`on-hold`**
  می‌رود — تنها چیزی که خود ووکامرس «غیرقابل‌پرداخت» می‌فهمد
  (`needs_payment()` فقط `pending`/`failed`). هزینه‌اش: ووکامرس موجودی را با
  همین وضعیت تکان می‌دهد؛ گفته شده، پنهان نشده.
- **موفقیت توقف را با `needs_payment()` نسنجید.** فیلتر زندهٔ خودمان همان جواب
  را می‌دهد، پس توقفِ شکست‌خورده موفق و بازگرداندنِ درست «گیرکرده» گزارش می‌شد.
  **وضعیت** از انبار دوباره خوانده می‌شود، با پاک‌کردن cache.
- **بازگشت به «از سرگیری فروش» وابسته نیست.** `retry_stop` و `release_orders`
  دو کار جدا از `resume`اند. (**تصحیح `alpha.11`:** ولی `release_orders`
  «بدون بازکردن خرید» نیست — سفارش را به `pending`/`failed` برمی‌گرداند، یعنی
  پرداختش باز می‌شود و پس از غیرفعال‌سازی هم باز می‌ماند.)
- **ردیفِ مهاجرت مالکیت نمی‌آورد.** `link_ownership` با دو مقدار
  `marketplace`/`observed`؛ هر پرس‌وجوی عملیاتی فقط `marketplace` را می‌بیند.
  انتقال صریح `TransferOwnership::take()` هم پیش از تغییر ردیف، پست را
  claim می‌کند و **وضعیتی را که پیدا کرده** در `_tmc_claimed_from_status`
  نگه می‌دارد.
- **«بازپرداخت» چهار بخش دارد و دو تا انجام می‌شود:** `RefundScope` —
  دفترکل ✅، موجودی ✅، **refund ووکامرس ❌، انتقال وجه ❌** (درگاهی نیست).
  شکست میانه ⇒ `ReconciliationRequired`، بدون تلاش دوبارهٔ خودکار.
- **کوپن حالا کوپنِ خودِ ووکامرس است**، نه fee منفی: از
  `woocommerce_get_shop_coupon_data` با `product_ids` همان فروشنده، و مقدار
  به‌صورت **درصدی از زیرمجموع همان فروشنده**. اندازه‌گیری‌شده: سبد مخلوط
  ۲٬۸۵۰٬۰۰۰ ← ۲٬۳۷۰٬۰۰۰، یعنی دقیقاً سهم خود فروشنده.
- **B2B رابط پیدا کرد:** فرم درخواست روی «حساب من»، ویرایشگر پلکان روی
  `/vendor/support/`، و نردبان روی صفحهٔ محصول **فقط برای خریدار تأییدشده**.
  هیچ endpoint تازه‌ای ساخته نمی‌شود و permalink ای flush نمی‌شود.

تحویل: `docs/phase-8-payment-stop-ownership-and-connection.md` · شواهد:
`docs/evidence/pay-stop/`، `docs/evidence/cart-connection/`،
`docs/evidence/wholesale-ui/`، `docs/evidence/dokan-migration/`،
`docs/evidence/returns/`.

**سه قاعدهٔ تازه:**
- **`curl -L -X POST` در شواهد ممنوع.** `-X` متد را به کل زنجیره تحمیل می‌کند،
  پس curl مقصد redirect را دوباره POST می‌کند و صفحهٔ PRG تا کد ۴۷ حلقه
  می‌زند — ذخیره انجام شده بود و شواهد هیچ صفحه‌ای ندید. درست:
  `curl -L --data-urlencode …`.
- **نام جدول از migration می‌آید، نه از حافظه.** یک `DELETE` با نام دست‌نویس
  بی‌صدا هیچ سطری پاک نکرد و یک بررسی را بی‌اثر کرد.
- **هر جدولی که به سطر سفارش یا محصول آویزان است، در `order-evidence.php reset`
  می‌آید.** دوبار تکرار شد؛ بار دوم شانزده مرجوعیِ اجرای قبلی، سطر سفارش ۱ را
  گروگان گرفته بود و تسویه «قابل درخواست» نمی‌شد.

**`0.1.0-alpha.9` (۱۵ سپتامبر):** مرحله ۵ ترتیب مالک — **توقفِ ناتمام، ارسال
جزئی، مرجوعی، فاز ۷ و مهاجرت دکان**. ساختار داده **۹**. شش چیز که باید بدانید:

- **توقف ناتمام یک شکست است.** `withdraw()` وضعیت را دوباره می‌خواند و نوشتنِ
  بی‌اثر را می‌گیرد؛ `stopAsResult()` با یک محصول باقی‌مانده هم `false` است؛ و
  `StorefrontSwitch::markStuck()` فهرست را در گزینه‌ای می‌نویسد که یک اعلان روی
  هر صفحهٔ wp-admin می‌خواند.
- **لینک پرداخت سفارش پرداخت‌نشده با چرخاندن کلید سفارش بازنشسته می‌شود**، نه با
  فیلتر. اندازه‌گیری‌شده: `pay_action()` هرگز دوباره نمی‌پرسد کالا فروختنی هست یا
  نه. از سرگیری **همان** لینک قبلی را برمی‌گرداند. `on-hold` عمداً استفاده نشد
  چون موجودی را تکان می‌دهد.
- **ارسال جزئی ردیف است، نه ستون:** `tmc_shipments`، یک ردیف به ازای هر بسته با
  رهگیری خودش. وضعیت `partially_shipped` **مشتق** است و `move()` دیگر
  «ارسال‌شده» را نمی‌پذیرد — از `ShipItems::ship()` برو.
- **مرجوعی: `received → refunded` تنها گذارِ پول است و به جایی نمی‌رسد**، و
  نوشتنش `UPDATE … WHERE reversal_event_key IS NULL` پشت ایندکس یکتاست. معکوس،
  خط **اضافه** می‌کند و آخرین مرجوعیِ یک قلم «باقی‌مانده» را برمی‌گرداند نه سهم
  گردشدهٔ خودش. سهمِ قبلاً پرداخت‌شده به `vendor_debt` می‌رود.
- **آنچه حدس زده نمی‌شود، نام دارد:** `ReturnTerms` (DEC-03)،
  `ManageCoupons::GLOBAL_UNDECIDED` (DEC-04)، `ManageWholesale::OPEN_TERMS`
  و مدت نگهداری تیکت (DEC-05).
- **مهاجرت دکان فقط می‌خواند.** `DokanReaderInterface` هیچ متد نوشتنی ندارد؛
  اجرای آزمایشی تطبیق سطربه‌سطر می‌دهد، تعارض را رد می‌کند، ورودش پیش‌نویس و
  متصل به همان شناسهٔ ووکامرس است، و `rollback()` دقیقاً همان ردیف‌ها را برمی‌دارد.

تحویل: `docs/phase-7-shipping-returns-and-engagement.md` · شواهد:
`docs/evidence/stop-failure/`، `docs/evidence/returns/`،
`docs/evidence/dokan-migration/`، `docs/evidence/orders/`.

**دو قاعده تازه:**
- **لایهٔ Application حق صداکردن وردپرس را ندارد — از جمله `__()`.** صف اقدام
  کلید برمی‌گرداند و متن فارسی در Presentation است (`ActionQueueMessages`).
  آزمون معماری همین را گرفت.
- **اسلاگ هیچ صفحهٔ ما نام افزونهٔ دیگری را ندارد** (`tmc-import`، نه
  `tmc-dokan-migration`)؛ آزمون قرارداد منو این را می‌سنجد.

**`0.1.0-alpha.8` (۱۵ سپتامبر):** مرحله ۴ ترتیب مالک — **بازگشت امن، درهای
دیگر خرید، دکان، و تسویه**. ساختار داده **۷**. پنج چیز که باید بدانید:

- **غیرفعال‌کردن افزونه، محصولات بازارگاه را از فروش خارج می‌کند** و پرچم توقف
  را ثبت می‌کند — در تنها لحظه‌ای که هنوز کد ما اجرا می‌شود. **فعال‌سازی دوباره
  خودکار چیزی را برنمی‌گرداند**؛ «از سرگیری» صریح لازم است و آن هم فقط محصولی
  را برمی‌گرداند که شرایطش برقرار است (F-14). هیچ داده‌ای پاک نمی‌شود.
- **سه در دیگر خرید بسته شد:** سبد از قبل پُرشده، **Store API** (سبد و checkout
  بلوکی) و **لینک پرداخت سفارش پرداخت‌نشده**. در هر سه، محصول خود فروشگاه و
  محصول دکان دست‌نخورده می‌مانند.
- **دکان Lite ۵٫۱٫۱ واقعاً فعال است** روی وردپرس یکبارمصرف و همهٔ آزمون‌های این
  مرحله کنار آن اجرا شدند. **محدودیت:** فایل‌های JS ساخته‌شدهٔ دکان در سورس گیت
  نیستند و wordpress.org از این محیط ۴۰۳ می‌دهد، پس صفحه‌های JS-محور خود دکان
  `Not Run` مانده‌اند (`docs/phase-6-safe-stop-and-settlement.md` بند ۳).
- **تسویه و برداشت کامل ساخته شد** (FIN-05). `SettlementGate` فقط **ساختن
  درخواست** را تا بسته‌شدن DEC-02/FIN-04 می‌بندد؛ **مانده همچنان محاسبه و
  نمایش داده می‌شود** (F-16). «تکمیل برای تسویه» را **مدیر** ثبت می‌کند
  (ORDER-01)، نه یک فرایند خودکار.
- **پیام خریدار یک جملهٔ کوتاه دربارهٔ همان کالاست** و هرگز نام DEC، نرخ
  کمیسیون یا دفترکل را نمی‌آورد؛ جزئیات در صفحهٔ «وضعیت فروش بازارگاه» مدیر
  است (F-17). آزمون، نبودِ آن کلیدواژه‌ها را می‌سنجد، نه متن را.

**قاعده تازه:** `DatabaseInterface::execute()` روی شکست **null** برمی‌گرداند و
در موفقیت تعداد سطر — و **صفر یک موفقیت است**. هر بررسی باید `=== null` باشد؛
`if (!$db->execute(...))` هر DDL موفق را شکست می‌خواند (همین یک بار ساختار
دادهٔ ۷ را کامل زمین زد).

تحویل: `docs/phase-6-safe-stop-and-settlement.md` · شواهد:
`docs/evidence/safe-stop/`، `docs/evidence/settlement/`،
`docs/evidence/coexistence/`.

**`0.1.0-alpha.7` (۱۵ سپتامبر):** مرحله ۳ ترتیب مالک — **اتصال به ووکامرس،
سفارش و ارسال آزمایشی**. ساختار داده **۶**. سه چیز که باید بدانید:

- **مرجع اصلی هر فیلد در ADR-008 نوشته شده و در کد قفل است.** قیمت/انتشار/
  عنوان/تصویر/SEO مال بازارگاه؛ **موجودی پس از اولین projection مال ووکامرس**؛
  سفارش و مشتری و مالیات مال ووکامرس. `SyncCatalog` عمداً `syncEverything()`
  ندارد: موجودی فقط هنگام **ساخت** محصول و با **ویرایش صریح فروشنده** بیرون
  نوشته می‌شود، وگرنه «موجودی قدیمی برمی‌گردد».
- **هر پرسشی دربارهٔ محصول غیربازارگاهی `not_ours` می‌گیرد و هیچ نوشتنی رخ
  نمی‌دهد.** محصولات سایت و دکان — از جمله وقتی قفل مالی روشن است — دست
  نمی‌خورند. اندازه‌گیری‌شده تا `post_modified`.
- **کلید آزمایشی سفارش** (`tmc_order_trial_mode`) فقط روی staging/development/
  local پذیرفته می‌شود و فقط شرط «بسته‌بودن DEC-02/DEC-04» را waive می‌کند؛
  همان خطوط دفترکل نوشته می‌شود. نرخ نمونه در **دیتابیس یکبارمصرف** است، نه در
  بسته (F-13).

تحویل: `docs/phase-5-catalog-and-orders.md` · شواهد: `docs/evidence/catalog/`
و `docs/evidence/orders/`.

**دو قاعده تازه:**
- **`php -l` روی هر نسخه PHP که افزونه ادعای اجرا رویش دارد** اجرا می‌شود
  (`tools/lint.sh`، امروز ۸٫۴ و ۸٫۱). `EnumCase->value` داخل `const` از ۸٫۲
  است و روی ۸٫۱ فاتالِ **کامپایل** می‌دهد، یعنی کل سایت می‌میرد نه یک صفحه —
  و lint روی ۸٫۴ چیزی نمی‌دید (F-11).
- **بازگشت امن از `alpha.8` با غیرفعال‌سازی شروع می‌شود، ولی به آن ختم نمی‌شود:**
  غیرفعال‌سازی محصول‌ها را بیرون می‌برد؛ ولی پیش از تعویض بسته باید فهرست
  جاماندگان خالی و سفارش‌های «نیازمند بررسی دستی» تعیین تکلیف شده باشند
  (`docs/upgrade-and-rollback.md` بند ۵٫۲-ب، پنج گام با شرط خروج ·
  `tools/rollback-hazard-check.sh`).

**`0.1.0-alpha.6` (۱۴ سپتامبر):** مرحله ۲ ترتیب مالک — **محصولات**: فرم
چهارمرحله‌ای با حفظ داده در خطا، مالکیت scope‌شده در هر پرس‌وجو، تأیید انتشار و
مجوز جدای انتشار مستقیم، **نسخه پیشنهادی** برای تغییر حساس محصول منتشرشده
(نسخه فعلی روی سایت می‌ماند)، موجودی فوری در هر وضعیت، بارگذاری واقعی تصویر با
مالک مشخص، **الگوی مشخصات پزشکی دسته‌محور** با بازنشستگی به‌جای حذف، و CSV با
پیش‌نمایش و خنثی‌سازی فرمول. همراهش: تعلیق/بازگردانی فروشنده که دسترسی خودش و
همه پرسنلش را فوری قطع می‌کند، موتور کمیسیون + دفترکل فقط‌افزودنی، و **دروازه
عملیات سفارش** که ماژول سفارش را تا تعیین نرخ و بسته‌شدن DEC-02/DEC-04 اجرا
نمی‌کند. ساختار داده **۵**. تحویل: `docs/phase-4-products.md` ·
شواهد: `docs/evidence/products/`.
**قاعده تازه:** قابلیت‌های تازه‌ای که capability جدید می‌سازند باید در
`Core\Lifecycle\Capabilities::all()` بیایند؛ `Bootstrap::ensureCapabilities()`
روی هر درخواست مدیر فهرست را با یک امضای ذخیره‌شده می‌سنجد، چون **جایگزینی
فایل‌های افزونه hook فعال‌سازی را اجرا نمی‌کند** — همان حفره‌ای که UpgradeGate
برای migration می‌بندد.

**`0.1.0-alpha.5` (۱۴ سپتامبر):** مرحله ۱ ترتیب مالک — تنظیمات فروشگاه با پنج
زبانه، صف تغییر نام و حساب بانکی برای مدیر، پرسنل با پنج نقش مصوب بند ۳٫۱،
دعوت با لینک یک‌بارمصرف (ارسال خودکار در Alpha بسته است)، تعلیق فوری، و
`StaffAccess` به‌عنوان **تنها** مرجع «چه کسی در کدام فروشگاه چه اجازه‌ای دارد» —
مرحله‌های بعد از همین می‌پرسند. ساختار داده ۳.
فهرست وضعیت همه امکانات: **`docs/feature-inventory.md`** (ساخته‌شده/ناقص/باقی‌مانده)
· تحویل: `docs/phase-3-vendor-completion.md`.
محل مدارک حالا از **هر** ریشه وب بالاتر می‌رود، نه یک پوشه بالاتر: نصب
`public_html/staging/` والدش را هم سایت اصلی سرو می‌کند.

**`0.1.0-alpha.4` (۱۴ سپتامبر):** مقدار پیام‌های خطا پس از redirect حفظ می‌شود؛
مدارک خصوصی **بیرون از ریشه‌های قابل‌دسترس وب** ذخیره می‌شوند و اگر محل امنی
نباشد بارگذاری با خطای روشن متوقف می‌شود (ADR-007، F-08)؛ و نقص بسته‌بندی
`alpha.3` که `assets/vendor/tmc-vendor.css` را از ZIP بیرون می‌گذاشت اصلاح شد.
هرگز پوشه‌ای را فقط به‌خاطر نامش از بسته حذف نکنید — فقط ریشه payload.

**۱۱ سپتامبر — سه چیز عوض شد (جزئیات: `docs/phase-1-report.md` بند ۵):**
- مالک `0.1.0-alpha.1` را روی `staging.tecteb.com` **نصب و فعال کرده است**. این
  کار را مالک انجام داده؛ این مخزن هیچ دسترسی و هیچ آزمونی روی آن سایت ندارد و
  هیچ عددی از آنجا نمی‌آید.
- همان نصب یک نقص واقعی نشان داد: شناسه و نسخه در کارت‌های ماژول‌ها حرف‌به‌حرف
  زیر هم می‌افتادند. بازتولید شد، علت در CSS خودمان بود، اصلاح شد و گارد
  رگرسیون گرفت (`ADR-006`, `docs/evidence/redesign/`). بسته فعلی
  `12773052…035b20` است و گیت‌های G-01…G-05، G-08 و G-09 روی آن با PHP 8.1.32
  (CLI و وب) قبول شدند؛ G-06/G-07 دوباره اجرا نشدند.
- طرح بخش فروشندگان و پیشخوان اولیه: `docs/phase-2-vendor-plan.md` (نسخه ۲)
  — **طرح است، پیاده‌سازی شروع نشده**. پیش‌نمایش ناحیه فروشنده:
  `docs/evidence/preview/`.
- بسته تحویلی اکنون **`0.1.0-alpha.3`** است (`8a838e3c…5a68a4`) و همه
  گیت‌ها از G-01 تا G-09 — شامل هر سه حالت HPOS — روی همان بسته با
  PHP 8.1.32 قبول شدند. شواهد ناحیه فروشنده: `docs/evidence/vendor/`.

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

# ارتقای schema ۱→۲ و بازگشت، روی وردپرس یکبارمصرف (۳۵ بررسی در هر اجرا)
bash tools/upgrade-rollback-check.sh /opt/php81/bin/php docs/evidence/upgrade-alpha1 \
     <alpha.1-zip> dist/tecteb-marketplace-core-0.1.0-alpha.4.zip

node tools/browser/check-vendor-messages.mjs   # متن و مقدار پیام‌ها (۱۱ بررسی)
bash tools/check-private-access.sh docs/evidence/vendor  # دسترسی مستقیم وب

# مرحله محصولات، روی افزونه نصب‌شده از ZIP نهایی
SITE=… TMC_OUT=docs/evidence/products node tools/browser/check-products.mjs      # ۶۱ بررسی
SITE=… TMC_OUT=docs/evidence/products/a11y node tools/browser/check-products-a11y.mjs  # ۲۴۰ بررسی
TMC_PAGES="tmc-product-review:product-review,tmc-spec-templates:spec-templates" \
  TMC_OUT=docs/evidence/products/wpadmin-a11y node tools/browser/check-wpadmin.mjs  # ۱۴۲ بررسی
node tools/browser/check-vendor-staff.mjs      # مسیر کامل مرحله ۱ (۲۴ بررسی)
node tools/browser/check-vendor-staff-a11y.mjs # دو صفحه تازه (۱۲۰ بررسی)

# مرحله ووکامرس و سفارش، روی افزونه نصب‌شده از ZIP نهایی و WooCommerce واقعی
wp eval-file tools/order-trial-seed.php <vendor-a> <vendor-b>       # داده نمونه
wp eval-file tools/purchase-block-state.php state|decide|suspend|…  # وضعیت
wp eval-file tools/order-evidence.php trial-matrix|projection|…     # شواهد
SITE=… TMC_WC_A=… TMC_WC_B=… TMC_WC_SHOP=… TMC_OUT=docs/evidence/orders \
  node tools/browser/check-order-trial.mjs        # مسیر کامل سفارش (۱۴ بررسی)
SITE=… TMC_WC_A=… TMC_WC_B=… TMC_WC_SHOP=… TMC_PRODUCT_A=… \
  node tools/browser/check-purchase-blocks.mjs    # تعلیق/ناموجودی/قفل (۲۵ بررسی)
SITE=… TMC_VARIABLE_PRODUCT=… TMC_OUT=docs/evidence/orders/a11y \
  node tools/browser/check-orders-a11y.mjs        # دو صفحه تازه (۸۰ بررسی)
bash tools/rollback-hazard-check.sh <wc-id> <vendor> <old-zip> <new-zip>

# مرحله بازگشت امن، دکان و تسویه — همه با دکان Lite فعال
# مرحله توقفِ ناتمام، مرجوعی و مهاجرت دکان — همه با دکان Lite فعال
# بازخورد پنج‌بندی مالک: توقف پرداخت، مالکیت، دامنهٔ بازپرداخت و اتصال به خرید
bash tools/pay-stop-check.sh        docs/evidence/pay-stop          # ۱۸ بررسی
bash tools/dokan-ownership-check.sh docs/evidence/dokan-migration   # ۲۲ بررسی
bash tools/cart-connection-check.sh docs/evidence/cart-connection   # ۱۲ بررسی
bash tools/wholesale-ui-check.sh    docs/evidence/wholesale-ui      # ۲۶ بررسی
wp eval-file tools/marketplace-state.php seed|tiers|fresh-buyer|wholesale-account|…
SITE=… TMC_PRODUCT_URL=… TMC_OUT=docs/evidence/wholesale-ui/a11y \
  node tools/browser/check-wholesale-a11y.mjs      # ۱۲۰ بررسی، ۳ صفحهٔ تازه

# فاز ۱۱: صف، رویداد و API، ممیزی، wizard، و تجربهٔ فرم محصول
bash tools/ops-check.sh        docs/evidence/operations    # ۵۹ بررسی
bash tools/product-ux-check.sh docs/evidence/product-ux    # ۲۶ بررسی
wp eval-file tools/ops-state.php schema|queue-seed|queue-run|queue-race|queue-kill|queue-resume|outbox-record|outbox-verify|outbox-deliver|audit-search|setup-state|reader-page|reports-scale|reset
wp eval-file tools/product-ux-state.php ids|draft-put|draft-forget|conflict|bulk
SITE=… TMC_PAGES="tmc-setup:setup,tmc-jobs:jobs,tmc-events:events,tmc-audit:audit" \
  TMC_OUT=docs/evidence/operations/wpadmin-a11y node tools/browser/check-wpadmin.mjs  # ۲۸۰ بررسی

# فاز ۱۰: موجودیِ اصلاح‌شده، refund تکرارناپذیر، نظر و امتیاز، و نمودار
bash tools/refund-crash-check.sh docs/evidence/refund-crash   # ۴۵ بررسی
bash tools/review-check.sh       docs/evidence/reviews        # ۴۸ بررسی
bash tools/acceptance-check.sh   docs/evidence/acceptance-path # ۲۷ بررسی، هفت مرحله
wp eval-file tools/acceptance-path.php run|ids
SITE=… OUT=docs/evidence/acceptance-path/screens node tools/browser/shoot-acceptance.mjs
wp eval-file tools/review-state.php reset|buyer|buy|rate|moderate|standing|chart|…
SITE=… OUT=docs/evidence/reviews/screens node tools/browser/shoot-reviews.mjs

# فاز ۹: گارد سفارش پرداخت‌نشده، پیوست، اعلان، گزارش و دو نیمهٔ بازپرداخت
bash tools/disposable-site.sh                          # محیط را برمی‌گرداند
bash tools/guard-check.sh      docs/evidence/guard-stock  # ۷۳ بررسی (۲۱۲ سفارش)
bash tools/engagement-check.sh docs/evidence/engagement   # ۳۶ بررسی
wp eval-file tools/guard-state.php seed|hold|release|census|stock|probe|cleanup
wp eval-file tools/engagement-state.php attach|read-file|hide-file|inbox|report-manager|…

bash tools/stop-failure-check.sh   docs/evidence/stop-failure    # ۳۳ بررسی
bash tools/return-check.sh         docs/evidence/returns         # ۱۸ بررسی
bash tools/dokan-migration-check.sh docs/evidence/dokan-migration # ۲۰ بررسی
SITE=… TMC_OUT=docs/evidence/stop-failure node tools/browser/check-stop-failure.mjs  # ۸ بررسی
wp eval-file tools/return-state.php first-item|open|decide|refund|…
wp eval-file tools/dokan-migration.php plan|import|runs|rollback|fingerprint

bash tools/safe-stop-check.sh   docs/evidence/safe-stop    # ۲۳ بررسی
bash tools/deactivation-check.sh docs/evidence/safe-stop   # ۱۳ بررسی
bash tools/settlement-check.sh  docs/evidence/settlement   # ۲۰ بررسی
wp eval-file tools/dokan-coexistence.php seed|report       # هم‌زیستی با دکان
SITE=… TMC_PAGES="tmc-storefront:storefront,tmc-withdrawals:withdrawals" \
  TMC_OUT=docs/evidence/safe-stop/wpadmin-a11y node tools/browser/check-wpadmin.mjs
```

نصب دکان برای آزمون هم‌زیستی: کلون `getdokan/dokan`، `composer install` (که
mozart را هم اجرا می‌کند)، کپی در `wp-content/plugins/dokan-lite/`، و ساختن
`assets/js/frontend.asset.php` — چون `Assets.php` آن یکی را **بدون
`file_exists`** لازم دارد. JS ساخته نمی‌شود؛ همین در شواهد صریح گفته شده.
دیتابیس آزمون **یکبارمصرف** است و پیکربندی‌اش در `.env.testing` می‌آید؛ هرگز
به دیتابیس واقعی اشاره نکنید (suite در نبود نام `tmc_test` اجرا نمی‌شود).

آزمون‌های وابسته به WordPress/WooCommerce واقعی همچنان **Not Run** هستند؛
`docs/compatibility-matrix.md` سطربه‌سطر می‌گوید کدام و چرا.

## قاعده دائمی تحویل (دستور مالک، ۱۱ سپتامبر)
هر نوبتی که **کد اجرایی افزونه** تغییر کند، تحویل شامل این‌هاست — نه فقط patch:

- **ZIP کامل و مستقل** با شماره نسخه در نام فایل
  (`tecteb-marketplace-core-<version>.zip`). نسخه انتشار قبلی تکرار نمی‌شود؛
  دو بسته با محتوای متفاوت هرگز یک نام ندارند.
- بسته **تجمعی** است: همه تغییرهای قبلی و جدید داخل همان یک ZIP‌اند و نصبش
  به هیچ بسته قبلی نیاز ندارد. هرگز patch یا «فقط فایل‌های تغییریافته» تحویل
  نمی‌شود. (`tools/build.sh` از ابتدا کل payload را می‌سازد، نه دلتا.)
- `dist/SHA256SUMS` که همان ZIP (و هر بسته دیگر موجود در `dist/`) را پوشش دهد.
- خلاصه تغییرها، آزمون‌های اجراشده و محدودیت‌های باقی‌مانده.
- شناسه commit متناظر با همان بسته.
- وضعیت صریح: «آماده آزمایش روی staging» یا «تأییدنشده؛ فعلاً نصب نشود».
- لینک دانلود مستقیم فایل در پاسخ؛ اشاره به مسیر `dist/` یا push کافی نیست.

اگر تغییر فقط اسناد یا ابزار توسعه بود، صریح گفته شود **ZIP نصب تغییر نکرده**.
هیچ بسته‌ای توسط ما روی سایت مالک نصب یا فعال نمی‌شود.

## قاعده عدم deploy
- هیچ نصب روی Staging/production، هیچ SSH/cPanel، هیچ credential واقعی.
- تنها مخزن محلی و DB آزمایشی disposable با داده مصنوعی قابل تغییر است.
- `git reset --hard`، clean مخرب و overwrite فایل کاربر ممنوع.
- هیچ افزونه موجودی (از جمله دکان) حذف/غیرفعال/ویرایش نمی‌شود.
- Skillهای حوزه‌ای وردپرس از ۱۱ سپتامبر نصب‌اند (`docs/tooling-setup.md`)،
  اما جای قواعد پرامپت فاز ۱ را نمی‌گیرند؛ در تعارض، دستور مالک و پرامپت مقدم است.
- محیط ساخت: PHP 8.4 فقط؛ WP/WC قابل دانلود نیست → آزمون‌های وابسته
  `Not Run` می‌مانند، نه `Passed`. تصاویر خارج از WordPress «نمونه رابط»
  هستند، نه اثبات کارکرد.

## ابزارهای دستیار (پلاگین و مهارت)
پیکربندی در `.claude/settings.json` (scope=project) و رونوشت مهارت‌ها در
`.claude/skills/`. گزارش کامل با شواهد: `docs/tooling-setup.md`.

- پلاگین‌های marketplace رسمی **`anthropics/claude-plugins-official`**:
  `frontend-design`، `pr-review-toolkit`، `security-guidance`. کدشان **در مخزن
  نیست**؛ هر محیط تازه باید از GitHub بگیردشان. (پیش‌تر از `anthropics/claude-code`
  نصب شده بود؛ دلیل تعویض در `docs/tooling-setup.md` بند ۲٫۱.)
- مهارت‌های `WordPress/agent-skills` @ `d87ee69`: `wordpress-router`،
  `wp-project-triage`، `wp-plugin-development`، `wp-rest-api`، `wp-performance`.
  رونوشت بالادست‌اند — دست‌نویس ویرایش نکنید (`.claude/skills/UPSTREAM.md`).
  پیش از استفاده، مهارت پروژه‌ای `tmc-wp-skills` را بخوانید: مسیر درست
  `.claude/skills/…` و تشخیص این مخزن به‌عنوان افزونه وردپرس
  (`node .claude/skills/tmc-wp-skills/scripts/triage.mjs`).
- Playwright ۱٫۶۳ + axe-core ۴٫۱۳ از قبل نصب‌اند و **نصب دوباره نمی‌شوند**.
  مرورگر باید با `executablePath` صریح اجرا شود (`/opt/pw-browsers/chromium`)؛
  بررسی سلامت: `cd tools/browser && node check-toolchain.mjs`.
- ناحیه فروشنده آزمون **مستقل** دارد: `node tools/browser/check-vendor.mjs`
  (۷۶ بررسی) و `node tools/browser/shoot-vendor.mjs` برای پیمایش کامل مسیر با
  تصویر. آزمون صفحات مدیر شاهد پذیرش آن ناحیه نیست. صفحه‌های محصول هم آزمون
  خودشان را دارند (`check-products*.mjs`)؛ `check-wpadmin.mjs` حالا با
  `TMC_PAGES` روی صفحه‌های تازه مدیر هم اجرا می‌شود — با `TMC_OUT` در پوشه
  دیگر، تا شواهد پذیرفته‌شده چهار صفحه بازنویسی نشود.
- داده نمونه مرحله محصولات با `tools/product-seed.php` و از مسیر سرویس‌های خود
  افزونه ساخته می‌شود (`wp eval-file`)، نه با SQL دستی.
- `check.mjs` و `check-wpadmin.mjs` را بی‌دلیل دوباره اجرا نکنید؛ شواهد
  پذیرفته‌شده را بازنویسی می‌کنند (`check-wpadmin.mjs` با `TMC_OUT` در پوشه دیگر
  می‌نویسد). اسکرین‌شات چهار صفحه در wp-admin واقعی:
  `OUT=… LABEL=… node tools/browser/shoot-wpadmin.mjs`.
- **چیدمان صفحات با `@container` نوشته می‌شود، نه `@media`** — ADR-006. هیچ
  تراکی نباید جعبه متن را خفه کند؛ بررسی `no-starved-text-box` این را می‌گیرد.
- Figma و PHP LSP عمداً نصب نشده‌اند.
