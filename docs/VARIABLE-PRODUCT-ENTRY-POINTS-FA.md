# `alpha.106` — ممیزی عرضی محصول متنوعِ واردشده: یازده نقطهٔ ورود

⛔ **این سند دربارهٔ کد است، نه دربارهٔ سایت.** هیچ ردیفی از آن روی
`staging.tecteb.com` یا `tecteb.com` اجرا نشده، و هر جا شاهد روی دادهٔ واقعی
لازم است **`Not Run`** نوشته شده. `Not Run` نه PASS است و نه FAIL.

**معنای ستون «وضعیت»:**

| علامت | معنا |
|---|---|
| **PASS** | یک آزمون در این مخزن همین را می‌سنجد، و با برداشتن گاردش **قرمز** می‌شود |
| **FAIL** | نقص تأییدشده، و تا این دور باز بود |
| **Not Run** | در این محیط قابل اجرا نیست — ووکامرس واقعی، دکان در حال اجرا، یا دادهٔ سایت لازم است |

---

## الف) یازده نقطهٔ ورود

| # | نقطهٔ ورود | مسیر | پیش از این دور | حالا | آزمون |
|---|---|---|---|---|---|
| ۱ | فهرست فروشنده و جست‌وجو | `ProductArea::listVariableRanges()` · `ProductListView` | **PASS** (از `alpha.105`) | **PASS** | `VariationFlowTest` |
| ۲ | فرم گام‌های ۱ تا ۴ و hidden carry-over | `ProductFormView::stepPrice/stepReview/carryOver/typeControl` | ⛔ **FAIL** — «۰» قابل ویرایش و قابل ارسال در گام ۲، و چاپ‌شده در گام ۴ | **PASS** | `ImportedParentFormTest` (۱۰ آزمون) |
| ۳ | درخواست پیشنهاد و تأیید مدیر | `ManageProducts::saveAsRevision()` · `ReviewProducts::approveRevision()` | ⛔ **FAIL** — `pushStock(0)` روی ذخیرهٔ بی‌تغییر؛ و تأیید می‌توانست نوع را عوض کند | **PASS** | `ImportedParentFiguresTest` (۱۹ آزمون) |
| ۴ | ویرایش ووکامرس توسط مدیر | `WooCommerceProjector::writeStock()` · `writeStockIfUnchanged()` | ⛔ **FAIL** — `set_manage_stock(true)` + `set_stock_quantity(0)` روی **والد** | **PASS** | `ImportedParentFiguresTest`، به‌علاوهٔ گاردِ خودِ projector |
| ۵ | AJAX autosave | `WpProductDraftStore` | بی‌تغییر | ⛔ **PASS از سورس، و هیچ آزمون رفتاری ندارد** — سنجیده‌شده با grep روی بایت‌های فایل: تنها نوشتن‌هایش `update_user_meta` و `delete_user_meta` روی کلید `tmc_product_drafts` اند و هیچ متای پست، ردیف محصول یا جدول تنوعی را لمس نمی‌کنند. این یک **شکافِ نام‌برده** است، نه یک قبولیِ آزموده | ⛔ **هیچ‌کدام** — بند «ت» |
| ۶ | فیلتر موجودی / فروش عمومی / Checkout | `PurchasePolicy::decisionFor()` | **PASS** | **PASS** — محصول متنوع **زودتر** از خواندن ستون موجودی `ALLOWED` می‌شود و پرسش به ووکامرس واگذار می‌شود (ADR-008). این دور یک بایتش عوض نشد | `tests/Database/OrderFlowTest.php` |
| ۷ | `SyncCatalog` و projection | `writeAndVerifyStock()` · `pushPrice()` · `priceAndStockOrigin()` | ⛔ **FAIL** — هر دو بی‌قیدوشرط می‌نوشتند | **PASS** | `ImportedParentFiguresTest` |
| ۸ | ورود و همگام‌سازی دکان | `ImportFromDokan` | ⛔ **بی‌تغییر، به‌عمد** — این دور یک بایتش عوض نشد | **Not Run** روی دادهٔ واقعی · کد دست‌نخورده | — |
| ۹ | CSV و اقدام گروهی | `ProductCsv::apply()` → `ManageProducts::save()` | ⛔ **FAIL** — همان مسیر `save()`، پس همان نقص | **PASS** — سنجیده‌شده: CSV از `ManageProducts::save()` می‌گذرد، پس گاردهای `parent_figures_live_in_shop` و `type_change_not_allowed` روی آن هم اعمال می‌شوند | `ImportedParentFiguresTest` (مسیر مشترک) |
| ۱۰ | REST فقط‌خواندنی محصول | `MarketplaceController::products()` | ⛔ **FAIL** — `price_minor: 0` بی هیچ قیدی | **PASS** | `ImportedParentFiguresTest` (سه آزمون REST) |
| ۱۱ | نام فارسی ویژگی‌ها در رابط | `WcStorefrontVariationReader::attributesOf()` · `VariationsView` | ⛔ **FAIL** — `%d8%a7%d9%86…` روی صفحه | **PASS** برای نیمهٔ نمایش · ⛔ **Not Run** برای نیمهٔ `wc_attribute_label($key, $parent)` — آن تابع در این محیط وجود ندارد | `AttributeLabelTest` (۸) · `VariablePriceSurfacesTest` (۲) |

⛔ **و صفحهٔ عمومی فروشگاه که دستور در بند P1 نام برده بود هم بسته شد**
(`StorePageView::productSection()`): تا این دور قیمتِ محصول متنوع **هیچ**
چاپ نمی‌شد، چون تنها شرطش `priceMinor > 0` بود.

---

## ب) مدل‌های مرزی اجباری

| مدل مرزی | کجا سنجیده شد | وضعیت |
|---|---|---|
| simple با موجودی **واقعیِ** صفر | `PriceAndStockOriginTest` · `ImportedParentFiguresTest::testASimpleProductsOwnInventoryEditStillGoesThrough` | **PASS** — ویرایش موجودیِ محصول سادهٔ خودمان همچنان انجام می‌شود |
| variable واردشده با موجودی والد `NULL` و فرزندِ `manage_stock=false` / `instock` | `ImportedParentFiguresTest` · `ImportedParentFormTest` | **PASS** — «نامشخص» چاپ می‌شود، نه «۰ عدد» و نه «ناموجود» |
| variable با سه تنوع و سه قیمت متفاوت | `ImportedParentFormTest::testStepTwoShowsTheRealVariationPriceRange` · `VariablePriceSurfacesTest` | **PASS** — دامنهٔ ۲٬۵۹۳٬۷۰۰ تا ۱۰٬۳۷۴٬۸۰۰ |
| variable با فرزندِ بی‌قیمت | `PriceAndStockOriginTest` · `ProductReadiness::shopVariationsAreSellable()` | **PASS** — `variable_needs_priced_variation`، با نام |
| فرزند غیرفعال | `ImportedParentFiguresTest::testAnImportedParentWhoseShopHoldsNothingBuyableIsStillRefused` | **PASS** |
| دو تنوع با موجودی صفر | `StorefrontVariationSet::totalStock()` · `ImportedParentFormTest` | **PASS** — شمارشِ «قابل خرید» جدا چاپ می‌شود |
| ووکامرس خوانده‌نشدنی | `PriceAndStockOriginTest::testAFailedReadIsNeverPermissionToWrite` · `ImportedParentFiguresTest::testAnUnreadableShopIsStillNotPermissionToWrite` | **PASS** — fail-closed روی نوشتن، و «نامشخص» روی صفحه |
| variable **TMC-native** با جدول تنوعِ داخلی | `VariationFlowTest::testAProductWhoseCombinationsAreOursStaysEditable` | **PASS** — و این همان کنترلی است که یک گاردِ **پهن‌ترِ** همین دور را رد کرد |
| محصول با pending revision | `ImportedParentFiguresTest::testAPendingRevisionCarryingATypeFlipCannotBeApproved` و دو آزمون دیگر | **PASS** |
| نوشتن هم‌زمان و replay | `ConcurrentSaveTest` (دست‌نخورده) · ⛔ گاردِ این دور **پیش از** هر نوشتن رد می‌کند، پس حالتِ نیمه‌نوشته ندارد | **PASS** برای رد · **Not Run** برای رقابتِ دو پروسه روی همین مسیر |
| فروشندهٔ #۱۰۲ | فیکسچر ساختگی با همان **شکل** (شناسهٔ فروشنده ۱۰۲، والد ۷۷۰۰) | ⛔ **Not Run** روی دادهٔ واقعی — به حکم §۳ هیچ آزمونی روی ۱۱۹۵۵/۱۱۹۶۵ نوشته نشد |
| فروشگاه منقضیِ #۴۰ | `can_sell = false` یک بایت عوض نشد | **Not Run** — و این دور به آن دست نزد |
| والد با `manage_stock=true` **صریح** | ⛔ **Not Run** | این حالت فقط روی ووکامرس واقعی ساخته می‌شود؛ گاردِ ما **پیش از** خواندن آن پرچم رد می‌کند، پس رفتارش از سورس قابل استنتاج است و **ادعای PASS نمی‌شود** |
| مقدار percent-encoded صفت فارسی | `AttributeLabelTest` (۸ آزمون) · `VariablePriceSurfacesTest` (۲) | **PASS** برای نمایش · **Not Run** برای `wc_attribute_label($key, $parent)` |

---

## پ) آنچه این دور **عوض نکرد**، به‌عمد

- منوها و نثرِ `alpha.105` — ۹ گروه و ۲۷ صفحه دست‌نخورده، و هیچ هشدار زنده‌ای
  تا نشد.
- `ImportFromDokan`، `MigrateMinMax`، `QuantitySet`، `QuantityGuard`.
- `OrderOperationsGate::OPEN_DECISIONS`،
  `SettlementGate::AUTOMATIC_SETTLEMENT_OPEN`، `VendorWithdrawalPolicy::OPEN`.
- ساختار داده: **۳۹ می‌مانَد، هیچ migration ای**.
- `can_sell` ِ فروشگاه #۴۰.

---

## ت) شکاف‌های نام‌برده — چیزهایی که این دور **نساخت**

⛔ این فهرست کوتاه است و عمداً کوتاه: دستور این دور «دامنه را بزرگ نکن»
می‌گوید، و هر ردیفِ زیر یک **آزمونِ غایب** است نه یک نقصِ باز.

| شکاف | چرا این دور بسته نشد | ریسک امروز |
|---|---|---|
| `WpProductDraftStore` هیچ آزمونی ندارد | خارج از یازده نقطه‌ای که دستور نام برد نیست، ولی دامنهٔ این دور «محصول متنوعِ واردشده» است و این کلاس به آن دست نمی‌زند. ساختنِ آزمون **پس از** پایان حلقهٔ falsification یعنی آزمونی که هیچ جهشی آن را نسنجیده (`alpha.77`) | کم — سنجیده‌شده از سورس که فقط user meta می‌نویسد |
| `wc_attribute_label($key, $parent)` روی ووکامرس واقعی | آن تابع در این محیط وجود ندارد؛ `function_exists()` در آزمون‌ها `false` است و شاخهٔ `$key` اجرا می‌شود | کم — نیمهٔ نمایش (`AttributeLabel`) مستقل از آن کار می‌کند و آزمون دارد |
| والدِ `manage_stock=true` ِ صریح | فقط روی ووکامرس واقعی ساختنی است | کم — گاردِ ما **پیش از** خواندن آن پرچم رد می‌کند |
| رقابتِ دو پروسه روی همین مسیر | گاردِ این دور **پیش از** اولین نوشتن رد می‌کند، پس پنجره‌ای برای نیمه‌نوشتن نمی‌مانَد | کم — ولی `Not Run` است، نه PASS |
