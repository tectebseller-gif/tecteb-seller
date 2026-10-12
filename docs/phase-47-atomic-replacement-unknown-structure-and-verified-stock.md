# فاز ۴۷ — جایگزینیِ اتمیک، ساختارِ نامعلوم، و موجودی‌ای که با نتیجهٔ معتبر ثبت می‌شود

بسته: `0.1.0-alpha.49` · ساختار داده **۲۵** (این دور **هیچ migration ای ندارد**) ·
شاخهٔ `claude/new-session-z1vafy`.

این سند تحویلِ دستور «پیش از نصب alpha.48، سه مورد بالا را در نسخهٔ مستقل بعدی
اصلاح کن» است. **هیچ بستهٔ قبلی بازسازی یا جایگزین نشد** و **هیچ اقدامی روی
`tecteb.com` یا `staging.tecteb.com` انجام نشده است**. هر عددی در این سند از
محیط یکبارمصرف آمده: WordPress 7.1، WooCommerce **۱۱٫۰٫۱** واقعی،
MariaDB 10.11، PHP **۸٫۴٫۱۹**. نصب روی سایت‌ها کار مالک است.

**دامنه عمداً باریک است**، همان‌طور که دستور خواست («اصلاحات را به همین موارد
محدود کن»): ده فایل کد، همه زیر `src/Modules/Product/`. چرخهٔ تأیید، برند،
صف بررسی، مالی، سفارش و دروازه‌های فروش لمس نشدند.

---

## ۰. خلاصهٔ یک‌بندی

سه موردِ گزارش مالک، **هر سه واقعی**، و هر سه یک شکل داشتند: **نیمهٔ اول یک کار
انجام می‌شد و نتیجه به‌عنوان موفقیت یا سلامت گزارش می‌شد.**

- **پیشنهاد بسته می‌شد و جایگزینش ساخته نمی‌شد.** دو statement جدا؛ insert ِ
  ردشده نیمهٔ اول را روی دیسک می‌گذاشت. اندازه‌گیری‌شده روی بایت‌های `alpha.48`:
  `pending_after=0`، `earlier_status=superseded`، `in_needs_review_after=no` —
  درخواستِ بی‌پاسخِ فروشنده **نابود** و محصول از صف بررسی **بیرون**.
- **ساختار نامعلوم با ساختار سالم یکی گرفته می‌شد.** `ProductTableShape`
  می‌دانست خواندن شکست خورده و «سالم» می‌گفت: `reads_as_sound=yes`،
  `problem=product_table_unreadable`، `wrote=1`. و **نوع ستون پرسیده نمی‌شد**،
  پس `stock` ِ باریک‌شده به `tinyint` اسم و nullability و پیش‌فرضش را نگه
  می‌داشت و ۵۰۰ را **۱۲۷** ذخیره می‌کرد با `save_code=inventory_saved`.
- **تأیید موجودی با نتیجهٔ نامعتبر ثبت می‌شد.** `pushStock()` هم روی
  «فروشگاه عدد دیگری نگه داشت» و هم روی «بازخوانی نشد» `true` می‌دهد، پس
  پیشنهاد `approved` می‌شد دربارهٔ فروشگاهی که عدد دیگری دارد:
  `proposed_stock=4 shop_after=3 revision_status=approved`. و **مجوز اعمال
  اجباری مقایسه را کامل دور می‌زد**، پس تغییری که **بعد از رندر صفحه** رسیده
  بود بی‌آنکه هرگز روی صفحه‌ای دیده شود بازنویسی می‌شد:
  `shop_when_page_rendered=2 shop_when_submitted=7 shop_after=4`.

هر سه اصلاح شد، و **هر اصلاح اول روی بایت‌های `alpha.48` بازتولید شد**:
`tools/alpha49-reproduction.sh` — **۱۱۶ بررسی، ۰ شکست**، با **پنج control** که
روی هر دو درخت **یک جواب** می‌دهند.

---

## ۱. بازتولید روی بایت‌های `alpha.48` — روش

`tools/alpha49-reproduction.sh` ده فایل را با `git show 19d735f:<path>` به
بایت‌های بستهٔ تحویل‌شدهٔ `alpha.48` برمی‌گرداند، probe را می‌دواند، و بعد
بایت‌های این چک‌اوت را برمی‌گرداند و **همان** probe را دوباره می‌دواند. هیچ
ادعایی دربارهٔ اصلاح نیست که اول دربارهٔ نقص اندازه‌گیری نشده باشد.

قاعده‌هایی که این اسکریپت رعایت می‌کند، هرکدام خریداری‌شده در دوری قبل:

- فهرست هش **پیش از دست‌زدن** گرفته می‌شود و بازگردانی با آن سنجیده می‌شود —
  `git diff` شاهد بازگشت فایل نیست (`alpha.29`)، و
  `git checkout <commit> -- <path>` ایندکس را هم می‌نویسد (`alpha.39`)، پس
  بایت‌های قدیمی با `git show` گرفته می‌شوند.
- بازگردانی از `trap` و با مسیر مطلق اجرا می‌شود (`alpha.37`).
- هر verb در **درخواست خودش** (`alpha.46`).
- خروجی هر verb برای `probe=ran` بررسی می‌شود؛ probe ای که بار نشود هیچ
  نمی‌گوید و «بازتولید شد» جمله‌ای دربارهٔ هیچ است (`alpha.32`).
- control ای که اندازه‌گیری نکند **شکست** است، نه قبولی (`alpha.46`).

و هر شکست با مکانیزمی تزریق می‌شود که **خودِ وردپرس یا ووکامرس** می‌دهد، نه با
mock: فیلتر `query` ِ `wpdb`، فیلتر `woocommerce_stock_amount`، و فیلتر
`woocommerce_product_get_stock_quantity`.

---

## ۲. مورد اول — جایگزینی پیشنهاد باید اتمیک باشد

### ۲٫۱ آنچه بود

`ManageProducts::proposeStock()` و `saveAsRevision()` هر دو این شکل را داشتند:

```php
if ($existing !== null) {
    $this->revisions->decide($existing->id, ProductRevision::SUPERSEDED, 0, '');
}
$revisionId = $this->revisions->create(...);
if ($revisionId <= 0) {
    return OperationResult::failure('storage_failed');
}
```

دو نوشتن، بی هیچ واحد کاری. `create()` ِ ردشده نیمهٔ اول را روی دیسک می‌گذاشت.

**اندازه‌گیری روی بایت‌های `alpha.48`** (`before-proposal-lost.txt`)، با insert
ِ جدول `tmc_product_revisions` از طریق فیلتر `query` ِ خودِ `wpdb` به جدولی که
وجود ندارد هدایت‌شده:

```
pending_before=118
in_needs_review_before=yes
save_code=storage_failed
pending_after=0
earlier_status=superseded
earlier_title_still_asked=no
in_needs_review_after=no
```

یعنی: فروشنده‌ای که منتظر تأیید **عنوان تازه‌اش** بود، با یک ذخیرهٔ موجودیِ
ناموفق درخواستش را از دست داد، محصول از صف بررسی مدیر بیرون رفت، و تنها چیزی که
به کسی گفته شد «ذخیره نشد» بود.

### ۲٫۲ آنچه شد

`ProductRevisionRepositoryInterface::replacePending()` — **یک واحد کار**، با سه
جواب که هر سه پیشنهاد را سر جایش می‌گذارند:

| جواب | چه وقت | حالت پیشنهاد |
|---|---|---|
| `replaced` | هر دو نوشتن انجام شد | پیشنهاد تازه، قبلی `superseded` |
| `created` | چیزی برای بستن نبود | پیشنهاد تازه |
| `not_atomic` | تراکنش باز نشد ⇒ **هیچ‌چیز تلاش نشد** | دست‌نخورده |
| `superseded_elsewhere` | کسی دیگر وسط کار تصمیم گرفت | تصمیم او سر جایش |
| `storage_failed` | insert رد شد ⇒ rollback | دست‌نخورده |

**اندازه‌گیری روی این درخت** (`after-proposal-lost.txt`):

```
pending_before=129
save_code=storage_failed
pending_after=129
proposal_kept=yes
earlier_status=pending
earlier_title_still_asked=yes
in_needs_review_after=yes
```

و نیمهٔ دیگرِ همین قاعده، با `START TRANSACTION` ِ ردشده
(`after-proposal-not-atomic.txt`):

```
save_code=revision_not_atomic
reason=not_atomic
context_proposal_kept=130
pending_after=130
```

روی `alpha.48` این پرسش **وجود نداشت**: آن تراکنشی نمی‌خواست، پس همان آزمایش
`save_code=stock_revision_requested` و `proposal_kept=NO` داد — یعنی پیشنهاد
قبلی رفت.

### ۲٫۳ سه چیز که عمداً این‌طور نوشته شدند

- **صفر سطر از supersede یک رقابتِ باخته‌شده است، نه «از قبل همین بود».**
  `WHERE` شرط `status = pending` را حمل می‌کند، پس صفر یعنی کسی دیگر بین خواندن
  ما و این statement تصمیم گرفته. `decide()` این دو را از هم تشخیص نمی‌دهد
  (`alpha.8` و نیمهٔ دومش که `alpha.39` بهایش را داد)، پس `replacePending()`
  `UPDATE` خودش را می‌نویسد و تعداد سطر را می‌سنجد.
- **`not_atomic` پیش از هر نوشتنی پرسیده می‌شود.** قاعدهٔ `alpha.39`: واحد کاری
  که باز نشود یک **رد** است، نه دلیلی برای اجرای نیمهٔ اول و امید.
- **«پیشنهاد سر جایش ماند» خوانده می‌شود، نه ادعا.** `keptProposal()` ردیف را
  دوباره می‌خواند و یا شناسه‌اش را نام می‌برد یا `lost` می‌گوید — قاعدهٔ
  `alpha.44` دربارهٔ هر دفتری که تصمیمی به آن آویزان است.

### ۲٫۴ محدودیتِ نام‌برده

در `saveAsRevision()`، `bumpVersion()` **پیش از** جایگزینی اجرا می‌شود و در
واحد کار نیست. پس یک جایگزینیِ ردشده نسخهٔ ردیف را جابه‌جا کرده و فرم‌های باز
دیگر باید دوباره بخوانند. **این از دست‌رفتن داده نیست** — پیشنهاد سر جایش است و
ذخیرهٔ دوباره کار می‌کند — و کشیدن تراکنش تا آن نوشتن یعنی بردن واحد کار به
لایهٔ Application، که بیرون از دامنهٔ این دستور بود. نوشته شد تا پنهان نماند.

---

## ۳. مورد دوم — ساختار نامعلوم با سالم یکی نیست، و نوع ستون پرسیده می‌شود

### ۳٫۱ نیمهٔ «نامعلوم»

`alpha.48` این را با استدلال نوشته بود: رد کردن هر ذخیرهٔ محصول به‌خاطر یک
خواندنِ شکست‌خورده، سایتی را می‌خواباند که مشکلش فقط این است که چیزی خوانده
نشد. بهای آن استدلال، روی بایت‌های خودش
(`before-structure-unreadable.txt`):

```
reads_as_sound=yes
problem=product_table_unreadable
save_code=inventory_saved
wrote=1
```

کلاس **می‌دانست** خواندن شکست خورده، «سالم» گفت، و نوشتن انجام شد. دستور مالک
همین را برمی‌گرداند — «ساختار نامعلوم با سالم یکی نباشد» — و حق با اوست، چون
تمام دلیلِ پرسیدن در لحظهٔ **نوشتن** این است که نوشتن روی یک فرض انجام نشود.

حالا سه جواب هست و `isSound()` فقط برای یکی‌شان درست است:

- **sound** — هر ستون همان شکلی است که نوشتن لازم دارد.
- **unsound** — یکی یا چندتا نیستند، و **نام برده می‌شوند**.
- **unknown** — خواندن شکست خورد. `isKnown()` غلط است، `isSound()` غلط است، و
  رد **کد خودش** را دارد (`schema_unknown`) تا «این ستون‌ها غلط‌اند» هرگز
  دربارهٔ پرسشی که کسی نتوانسته بپرسد گفته نشود.

خواندنِ شکست‌خورده **memoise نمی‌شود**، پس نوشتنِ بعدیِ همان درخواست دوباره
می‌پرسد: یک شکست گذرا نباید سایتی را تا پایان یک page load بخواباند، و شکست
پایدار روی سایتی که از قبل نوشتن را رد می‌کند یک کوئری به ازای هر نوشتن است.

**اندازه‌گیری روی این درخت** (`after-structure-unreadable.txt`):

```
reads_as_sound=no
problem=product_table_unreadable
save_code=schema_unknown
wrote=0
```

### ۳٫۲ نیمهٔ «نوع غلط»

`alpha.48` nullability و پیش‌فرض را می‌پرسید و **نوع را نه**. `stock` که از
`int(11)` به `tinyint` باریک شود، اسمش و `NOT NULL` بودنش و پیش‌فرض صفرش را
نگه می‌دارد — هر سه پرسشی که پرسیده می‌شد — و
(`before-wrong-type.txt`):

```
column_now=tinyint(4)
reads_as_sound=yes
save_code=inventory_saved
asked_stock=500
row_stock=127
```

هیچ خطایی بالا نیامد چون `sql_mode` این سرور `STRICT_TRANS_TABLES` ندارد —
اندازه‌گیری‌شده:
`ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION` — و این
پیکربندی **عادیِ وردپرس** است: خودِ `wpdb` حالت‌های strict را برمی‌دارد.

حالا `types` بخشی از شکل است، و **فهرست** است نه یک مقدار، چون **باریک‌تر از
دست رفتن است و پهن‌تر نیست**: `bigint` جایی که migration `int` اعلام کرده همهٔ
آنچه `int` نگه می‌دارد را نگه می‌دارد، پس رد کردنش سایتی را می‌خواباند که کسی
عمداً پهنش کرده («مسیر سالم را بی‌دلیل متوقف نکن»). فقط **داخل یک خانواده**:
عددی که به‌صورت متن ذخیره شود «جای بیشتر» نیست، ستون دیگری است. و برای ستون‌های
متنی همان پرسش با `min_length` پرسیده می‌شود، چون آنجا از دست رفتن **بُرش** است:
`varchar(5)` برای `title` اسمش و `NOT NULL` و پیش‌فرض خالی‌اش را نگه می‌دارد و
بی‌صدا پنج نویسه را.

| ستون | نوع لازم | حداقل طول |
|---|---|---|
| `title` | `varchar` | ۱۹۰ |
| `brand` | `varchar` | ۱۲۰ |
| `sku` | `varchar` | ۶۴ |
| `stock` | `int` / `bigint` | — |
| `min_purchase` | `int` / `bigint` | — |
| `max_purchase` | `int` / `bigint` | — |
| `purchase_step` | `int` / `bigint` | — |
| `description` | `longtext` | — |
| `brand_term_id` | `bigint` | — |

**اندازه‌گیری روی این درخت** (`after-wrong-type.txt`):

```
column_now=tinyint(4)
unsound_columns=stock
reads_as_sound=no
save_code=schema_unsound
row_stock=31      ← مقدار قبلی، دست‌نخورده
```

و `MalformedColumnGuardTest` با DDL واقعی هر دو جهت را می‌سنجد: `bigint` سالم
است و نوشتن انجام می‌شود، `varchar(20)` برای `stock` نیست، `varchar(255)` برای
`title` سالم است و `varchar(5)` نیست.

### ۳٫۳ یک آزمون که قاعدهٔ غلط را نوشته بود

`MalformedColumnGuardTest::testATableThatCannotBeReadDoesNotStopTheWrite` دقیقاً
همان چیزی را assert می‌کرد که اکنون غلط است. **شل نشد، حذف شد** و جایش دو
آزمون آمد (`…IsUnknownAndNotSound` و
`…RefusesTheWriteUnderItsOwnName`). قاعدهٔ `alpha.46`: آزمونی که نقص را
به‌صراحت می‌نویسد سوییت را سبز نگه می‌دارد و نویسنده را کور — و این یکی یک دور
کامل دوام آورد.

---

## ۴. مورد سوم — تأیید موجودی فقط با نتیجهٔ معتبر

### ۴٫۱ بازخوانیِ متفاوت و بازخوانیِ ناموفق

`approveRevision()` خروجی boolean ِ `pushStock()` را می‌خواند، و آن boolean
وقتی فروشگاه عدد را کلمپ کند `true` است (مقدار mirror می‌شود و کسی خبردار
نمی‌شود) و وقتی بازخوانی شکست بخورد هم `true` است.

**روی بایت‌های `alpha.48`**، با فیلتر خودِ ووکامرس
(`woocommerce_stock_amount`) که به ۳ کلمپ می‌کند
(`before-readback-differs.txt`):

```
proposed_stock=4
shop_after=3
row_after=3
revision_status=approved      ← بسته شد، روی عددی که کسی تأیید نکرده
```

و با `woocommerce_product_get_stock_quantity` که `null` می‌دهد
(`before-readback-failed.txt`): `revision_status=approved` روی نوشتنی که
هیچ‌چیز تأییدش نکرده.

حالا `SyncCatalog::pushApprovedStock()` یک موفقیت و سه شکستِ نام‌دار دارد:

| کد | معنا | چه می‌شود |
|---|---|---|
| `stock_written` | فروشگاه همان عدد تأییدشده را دارد | پیشنهاد `approved` |
| `stock_mirrored_different` | عدد دیگری دارد | mirror اصلاح، پیشنهاد **باز**، دو عدد در پیام |
| `stock_unverified` | بازخوانی نشد | هیچ mirror ای، پیشنهاد **باز** |
| `stock_not_written` | نوشتن رد شد | دلیل نام‌برده، پیشنهاد **باز** |

و `pushStock()` **عوض نشد** و دلیلش نوشته شد: فراخوانش ذخیرهٔ خودِ فروشنده روی
محصولی است که زنده نیست، و آنجا رسیدنِ mirror به هر عددی که فروشگاه تصمیم گرفته
**جوابِ درست** است. یک قاعدهٔ بازخوانی، دو حکم روی آن.

**اندازه‌گیری روی این درخت**: `approve_code=stock_mirrored_different`،
`row_after=3`، `revision_status=pending` · و
`approve_code=stock_unverified`، `revision_status=pending`.

> ### ⛔ تصحیح — این بند غلط بود (`alpha.50`)
>
> متن اصلی این بند می‌گفت «تغییر موجودی بین بررسی و نوشتن با همین گارد گرفته
> می‌شود» و استدلال می‌کرد که بازخوانیِ پس از نوشتن آن پنجره را می‌پوشاند.
> **نمی‌پوشاند، و مالک درست رد کرد.** `writeStock()` عدد تأییدشده را
> **بی‌قیدوشرط** می‌نویسد، پس بازخوانی‌ای که دقیقاً همان عددِ خواسته‌شده را
> پیدا می‌کند تنها ثابت می‌کند نوشتن انجام شد و **هیچ** نمی‌گوید چه چیزی را
> جایگزین کرده. فروشِ دو عددی در آن فاصله بازنویسی می‌شد و هر بررسیِ مسیر با
> خودش موافق بود.
>
> اندازه‌گیری‌شده روی بایت‌های همین `alpha.49`، با نویسندهٔ دومِ واقعی در
> پروسهٔ جدا و از مسیر خودِ ووکامرس (سفارشی که به `processing` می‌رود):
> `second_writer_before=9 second_writer_after=7 approve_code=revision_approved
> shop_after=5 revision_status=approved` — **دو عددِ فروخته‌شده رفت**.
>
> و استدلالِ «نویسندهٔ دوم روی دو پروسه یک شیر یا خط است» هم غلط بود: روی
> `woocommerce_update_product` — قلاب خودِ ووکامرس که `project()` به آن
> می‌رسد — پروسهٔ دوم **هر بار** و به‌صورت قطعی داخل همان پنجره می‌نشیند.
>
> اصلاح در `alpha.50`: بررسی و نوشتن **یک عملیات محافظت‌شده** شدند —
> compare-and-set داخل همان یک statementی که `decrease` ِ خودِ ووکامرس روی آن
> رقابت می‌کند. جزئیات و شواهد:
> `docs/phase-48-one-protected-stock-write.md`.

### ۴٫۲ مجوز اعمال اجباری، بسته به عددی که مدیر دید

`alpha.48` اجازه می‌داد چک‌باکس مقایسه را **کامل** دور بزند. پس تغییری که بین
رندر صفحه و فرستادن فرم می‌رسید بازنویسی می‌شد بی‌آنکه هرگز روی صفحه‌ای ظاهر
شود (`before-override-unseen.txt`):

```
shop_when_page_rendered=2
shop_when_submitted=7
build_carries_seen_stock=no
approve_code=revision_approved
shop_after=4
```

مدیر موافقت کرده بود **۲** را بازنویسی کند. هیچ‌کس موافقت نکرده بود **۷** را
بازنویسی کند.

حالا صفحهٔ بررسی عددی را که **چاپ می‌کند** با همان مجوز حمل می‌کند
(`<input type="hidden" name="seen_stock" value="…">`)، و
`approveRevision($id, $seen, true, $seenStock)` وقتی فروشگاه دوباره تکان خورده
باشد رد می‌کند با **چهار عدد**:

```
approve_code=stock_moved_again
baseline_stock=5  seen_stock=2  current_stock=7  proposed_stock=9
revision_status=pending
```

و فرمی که این عدد را **حمل نکند** رد می‌شود نه مورد اعتماد — همان قاعده‌ای که
نسخهٔ ردیف از `alpha.15` دنبال می‌کند، و قاعدهٔ `alpha.46`: nonce ای که در هر
رندر چاپ می‌شود «از این فرم آمده» را ثابت می‌کند و «این فرم چه گفته» را نه.

دو آزمون رندر در `ProductAdminPagesTest` نیمهٔ markup را می‌سنجند — فیلد مخفی
با همان عددی که جمله نقلش می‌کند، و **نبودنش** وقتی تعارضی نیست — چون رندر کردن
یک صفحه دربارهٔ تصمیم‌هایش هیچ نمی‌گوید (`alpha.13`، و نسخهٔ مرورگریِ `alpha.48`).

---

## ۵. control ها — روی هر دو درخت یک جواب

| control | چه می‌سنجد | جواب (هر دو درخت) |
|---|---|---|
| `control-proposal-replaced` | جایگزینی‌ای که رد نشود باز هم جایگزین می‌کند | `pending_count=1` · `pending_is_new=yes` · `earlier_superseded=superseded` · `carries_title_forward=yes` |
| `control-sound` | جدول دست‌نخورده سالم است و نوشتن انجام می‌شود | `reads_as_sound=yes` · `unsound_columns=__none__` · `row_stock=31` |
| `control-approve` | تأیید سالم فروشگاه را تکان می‌دهد و پیشنهاد را می‌بندد | `revision_approved` · `shop_after=4` · `row_after=4` · `approved` |
| `control-override-seen` | اعمال اجباری روی عددی که **دیده شده** کار می‌کند | `revision_approved` · `shop_after=4` · `approved` |
| `control-refuses-moved` | مبنای جابه‌جاشده بی چک‌باکس باز هم رد می‌شود | `stock_moved` · `baseline=9` · `current=2` · `pending` |

---

## ۶. آزمون‌های اجراشده

| سوییت | تعداد | شکست |
|---|---|---|
| unit | **۴۴۹** | ۰ |
| architecture | **۳۱** | ۰ |
| contract | **۲۸۳** | ۰ |
| database | **۴۹۵** (۴۷۸ ِ `alpha.48` + **۱۷ آزمون تازه**: ۶ `AtomicProposalReplacementTest`، ۶ `MalformedColumnGuardTest`، ۳ `ProductFlowTest`، ۲ `ProductAdminPagesTest`) | ۰ |
| packaging | **۲۲** | ۰ |
| `php -l` روی ۵۲۵ فایل، PHP ۸٫۴٫۱۹ | ۵۲۵ | ۰ |
| PHPCompatibility، `testVersion 8.1-` | ۵۲۵ | ۰ |

اسکریپت‌ها روی ووکامرس ۱۱٫۰٫۱ واقعی، از نصبِ خودِ `alpha.49.zip`:

| اسکریپت | بررسی | شکست |
|---|---|---|
| `alpha49-reproduction.sh` (قبل/بعد + ۵ control) | **۱۱۶** | ۰ |
| `alpha48-reproduction.sh` (رگرسیون) | ۱۰۳ | ۰ |
| `alpha47-reproduction.sh` (رگرسیون) | ۶۲ | ۰ |
| `upgrade-alpha47-check.sh` با **هر دو مبنا** | **۹۸** | ۰ |
| `alpha48-run.mjs` — مسیر مرورگری فروشنده → مدیر → ووکامرس | **۳۷** | ۰ |

**هر دو مسیر ارتقا اندازه‌گیری شد**، و مهم‌ترینش مسیر واقعیِ مالک است: او گفت
`alpha.48` را نصب نمی‌کند، پس مسیر او `alpha.47 → alpha.49` است و **ساختاری**
است (۲۴ → ۲۵، `M0025BrandRegistry` همان‌جا اجرا می‌شود).

| مبنا | ساختار | گام | نتیجه |
|---|---|---|---|
| `alpha.48` | ۲۵ → ۲۵ | `structural=no` | ۰ شکست |
| `alpha.47` | ۲۴ → ۲۵ | `structural=yes` — شکستِ واقعیِ تزریقی، ازسرگیری، بازگشت و جلو رفتن دوباره | ۰ شکست |

**و یک درس روشِ اندازه‌گیری، نوشته‌شده چون هزینه داد:** اولین اجرای این اسکریپت
۳۸ شکست داد و **هیچ‌کدام دربارهٔ بیلد نبود** — وب‌سرور بالا نبود، پس هر بررسیِ
HTTP محور `actual=000` گرفت و دروازهٔ ارتقا هرگز اجرا نشد (ساختار روی ۲۴ ماند).
تله `alpha.34` در لباس تازه: `pgrep -f "php -S …"` دستور خودِ من را match کرد.
پیش از هر اسکریپتی که HTTP می‌زند، **با `curl` بپرسید سایت جواب می‌دهد یا نه**.

### ۶٫۱ مسیر مرورگری

`tools/browser/alpha48-run.mjs` — همان مسیر دورِ قبل، روی این بسته:
**۳۷ بررسی، ۰ شکست**. این دور لازم بود چون شاخهٔ dispatch ِ «تأیید تغییر» عوض
شده (آرگومان چهارم و فیلد مخفی) و درسِ `alpha.48` این بود که **فقط مرورگر**
نقصی را پیدا کرد که هر سوییتی سبز می‌دید. تأییدِ واقعی از فرم واقعی انجام شد و
فروشگاه عدد تأییدشده را گرفت.

**`php -l` روی PHP 8.1 اجرا نشد و «قبول» نیست**: مفسر ۸٫۱ در این محیط نیست و
سیاست شبکه بسته‌اش را نمی‌دهد (`ppa.launchpadcontent.net` و
`codeload.github.com` ۴۰۳، `www.php.net` در CONNECT رد). جایش
PHPCompatibility با `testVersion 8.1-` اجرا شد که **جایگزین است نه معادل**.

**دکان، Min Max Control، قالب واقعی سایت و غیرفعال‌کردن افزونهٔ فعالِ دارای
داده `Not Run` اند — نه «قبول».**

---

## ۷. جدول تحویل

| | |
|---|---|
| بسته | `tecteb-marketplace-core-0.1.0-alpha.49.zip` |
| SHA-256 | `33d1850feed955fe4f2601c3479ab2c2947db096a52639f35c409abb352a9b15` |
| ساختار داده | **۲۵** (بی تغییر، **هیچ migration ای**) |
| مبنای بازتولید | `19d735f` (بستهٔ تحویل‌شدهٔ `alpha.48`) |
| فایل‌های کدِ عوض‌شده | **۱۰**، همه زیر `src/Modules/Product/` |

فایل‌های کد:

1. `Application/ManageProducts.php` — `replacePending()` در دو جا، `keptProposal()`، `schema_unknown`
2. `Application/ProductRevisionRepositoryInterface.php` — `replacePending()`
3. `Application/ProductRepositoryInterface.php` — `structureKnown()`
4. `Application/ReviewProducts.php` — `$seenStock`، `stock_moved_again`، تأیید سنجیده
5. `Application/SyncCatalog.php` — `pushApprovedStock()` و یک قاعدهٔ بازخوانی
6. `Infrastructure/DbProductRevisionRepository.php` — واحد کار
7. `Infrastructure/DbProductRepository.php` — `structureKnown()`
8. `Infrastructure/ProductTableShape.php` — سه جواب، و نوع و طول
9. `Presentation/Admin/ProductReviewPage.php` — فیلد مخفی و dispatch
10. `Presentation/ProductMessages.php` — پنج متن تازه

**هیچ ستون، جدول یا migration ای اضافه نشد** — سنجیده‌شده: `SchemaVersion::TARGET`
همان ۲۵ است و هیچ فایلی زیر `src/**/Migrations/` عوض نشده.

---

## ۸. آنچه باز است

- **سه تصمیم انتقال همچنان مسدود**، با همان دلایل دقیق بند ۹ تحویل `alpha.48`:
  نصب روی سایت اصلی (اجرای واقعی PHP 8.1 انجام نشده)، غیرفعال‌کردن دکان و
  Min Max (نسخه و کلیدها از سایت مالک شناسایی نشده‌اند)، و باز کردن فروش واقعی
  (تصمیم‌های DEC و درگاه).
- **محدودیت بند ۲٫۴**: نسخهٔ ردیف پیش از جایگزینی جابه‌جا می‌شود و در واحد کار
  نیست.
- DEC-02…05، درگاه پرداخت، FIN-02 و OTP از جدول آمادگی حذف نشدند.

---

## ۹. پنج قاعدهٔ تازه

- **نیمهٔ اولِ یک کار که تنها انجام شود، از انجام‌نشدن هر دو نیمه بدتر است.**
  هر جا دو نوشتن یک معنا دارند، واحد کار را بپرسید **پیش از** نوشتن اول — و
  اگر نبود، **هیچ‌کدام** را ننویسید.
- **«نمی‌دانم» یک جواب است و با «سالم» یکی نیست.** هر گاردی که روی یک خواندن
  بنشیند باید سه حالت داشته باشد؛ دو حالت یعنی یکی از آن دو، شکستِ خواندن را
  می‌بلعد.
- **ستونی که نامش و nullability و پیش‌فرضش درست باشد می‌تواند نوعش غلط باشد —
  و در غیابِ `STRICT_TRANS_TABLES` بی‌صدا عدد دیگری ذخیره می‌کند.** هر شکلی که
  می‌سنجید، بپرسید SQL **کدام** نیمه‌اش را به شما نمی‌گوید.
- **boolean ای که «نوشتم» و «همان را نوشتم» را یکی کند، هر تصمیمی که به آن
  آویزان شود را خراب می‌کند** — و دو فراخوان با دو حکم، بهتر از یک فراخوان با
  حکم اشتباه برای یکی از دو جاست.
- **مجوزی که مدیر درباره یک عدد می‌دهد، مجوزِ «هر عددی که وقت رسیدن فرم آنجا
  باشد» نیست.** هر override باید **چیزی را که نشان داده شد** حمل کند، و فرمی که
  حملش نکند رد شود نه مورد اعتماد.
