# فرایند ثابت رگرسیون، و پوشش §۴ و §۵

نسخه: `0.1.0-alpha.108` · ساختار داده **۳۹**.

---

## الف) §۱۲ — ده گام، هر بار، به همین ترتیب

⛔ **این ده گام جایگزین «آزمون‌ها را بدوان» است، و دو جمله در خودش دارد که
دستور صریحاً خواسته:** «فقط اجرای تست‌های قدیمی کافی نیست. تست باید باگ جدید
را واقعاً بازتولید و کشف کند» و «آزمون‌های staging با آزمون‌های PHP داخل Docker
یکی نیستند».

| گام | فرمان | شرط قبولی | چرا این گام |
|---|---|---|---|
| ۰ | `pgrep -af phpunit` · شمارش اتصال‌های `tmc_test` · `SHOW TABLES LIKE "%_away"` | هیچ phpunit ای در اجرا نیست · هیچ جدولِ `_away` ِ جامانده‌ای نیست | ⛔ **«یک، صفر نیست»** (`alpha.105`): `pgrep -cf phpunit` جواب `1` می‌دهد و آن یکی می‌تواند خودِ حلقه باشد. و دو runner روی یک دیتابیس، همدیگر را می‌سنجند نه بیلد را (`alpha.40`، چهار بار) |
| ۱ | `php -l` روی **هر دو** مفسر، روی هر فایل | ۰ شکست | خطای کامپایل روی ۸٫۱ **هر صفحه** را می‌کشد، نه یک صفحه (`alpha.11`) |
| ۲ | PHPCompatibility با `testVersion 8.1-` | ۰ خطا | ⛔ **جایگزین اجرای ۸٫۱ نیست و خودِ اسکریپت می‌گوید** |
| ۳ | `vendor/bin/phpunit --testsuite unit` | ۰ شکست | خالص: هیچ نماد وردپرسی تعریف نمی‌شود |
| ۴ | `--testsuite architecture` | ۰ شکست | مرز Core/Contracts/Application، و هر گاردی که در فیکسچرِ سالم **قابل‌رسیدن نیست** |
| ۵ | `--testsuite contract --bootstrap tests/bootstrap-contract.php` | ۰ شکست | ⛔ هر صفحه **رندر** می‌شود؛ ثبت‌شدن یک صفحه دربارهٔ رندرش هیچ نمی‌گوید (`alpha.13`) |
| ۶ | `--testsuite database --bootstrap tests/bootstrap-database.php` — **تنها** | ۰ شکست | MariaDB واقعی · ⛔ **تنها**، به حکم گام ۰ |
| ۷ | امنیت و مجوز: `SecurityRulesTest` · `VendorPostsAreNonceGuardedTest` · `MoneyCarryingRoutesTakeTheMoneyPermissionTest` · `ReadOnlyInventoryTest` | ۰ شکست | مجوزِ مشترکِ دو مسیر، گاردِ هیچ‌کدام نیست (`alpha.102`) |
| ۸ | رگرسیون فرم: `ProductFlowTest` · `ProductFormPostTest` · `ConcurrentSaveTest` · `ProductViewsTest` | ۰ شکست | هر مسیر ذخیرهٔ محصول، با فیکسچر واقعی |
| ۹ | `--testsuite packaging` (پس از `tools/build.sh`) | ۰ شکست | نسخه، هش، برابریِ `src/` داخل ZIP با چک‌اوت، و دفتر تحویل |
| ۱۰ | `git diff --stat` و خواندنِ **هر** فایلِ تغییریافته | هیچ فایلی بیرون از دامنهٔ این دور نیست | ⛔ **قانون اولِ مالک:** «برای اصلاح یک قسمت، سایر قسمت‌های سالم را تغییر نده» |

### و دو گام که **جزء این ده نیستند** و همین‌طور گفته می‌شوند

| گام | چرا جداست |
|---|---|
| حلقهٔ falsification | ده گام بالا می‌گویند «چیزی نشکست»؛ falsification می‌گوید «آزمون‌ها چیزی را **می‌سنجند**». بازگرداندن در یک `trap` و تطبیق بایتی پس از آن (`alpha.106`) |
| اجرای واقعیِ staging | ⛔ **آزمون PHP داخل داکر، آزمون staging نیست.** ده گام بالا روی هیچ وردپرسی اجرا نمی‌شوند. هر ادعایی دربارهٔ وردپرس، ووکامرس، دکان، Min Max، قالب، کش یا مرورگر در `docs/STAGING-ACCEPTANCE-UNIFIED-FA.md` ردیفِ خودش را دارد و **`NOT RUN`** است |

---

## ب) §۴ — هشت اصلاح `alpha.107`، و اینکه کدام آزمون هر کدام را نگه می‌دارد

⛔ **هیچ‌کدام بازطراحی نشد. این جدول اثبات است، نه کار تازه.**

| § | چه چیزی | آزمون‌ها | تعداد |
|---|---|---|---|
| ۴-۱ صفحه‌بندی | `tmc_page` مالِ این افزونه است؛ هیچ query var هستهٔ وردپرس خوانده نمی‌شود | `Architecture/VendorAreaOwnsItsQueryVarsTest` · `WordPressContract/VendorPagerAddressTest` · `Database/VendorProductPagingTest` | ۲ + ۶ + ۷ = **۱۵** |
| ۴-۲ تصویر | fallback **هیچ فعل نوشتنی ندارد** — از بایت‌های **هر دو** پیاده‌سازی | `Architecture/StorefrontImageReaderIsReadOnlyTest` · `Database/StorefrontImageFallbackTest` | ۳ + ۷ = **۱۰** |
| ۴-۳ تنوعِ واقعی | دو درِ **انحصاری** با دو پرسشِ مثبت · گاردِ دوم موجودی · والد دست‌نخورده | `Unit/Modules/Product/ShopOwnedVariationDoorsAreExclusiveTest` · `WordPressContract/StorefrontVariationWriteTest` · `WordPressContract/ImportedVariationsAreReadOnlyTest` · `Database/StorefrontVariationEditTest` | ۱۵ + ۱۱ + ۱۴ = **۴۰+** |
| ۴-۴ ترکیب‌ها | رکورد سومِ adopt · هیچ فرزند دوم | `WordPressContract/VendorBuiltVariationsTest` · `Database/VariationFlowTest` | ۷ + ۱۳ = **۲۰** |
| ۴-۵ برند | تاکسونومیِ **کشف‌شده** با ترتیب تقدم · `BrandName::key()` با ۲۹ ردیف pin‌شده | `Unit/Modules/Product/BrandTaxonomyTest` · `WordPressContract/BrandListSourceTest` · `Unit/BrandNameTest` | **۴۰+** |
| ۴-۶ پلکان | **منتقل‌شده** با همان واژه‌ها · `SupportArea::ACTIONS` کامل · فهرست مرحله‌ها **مشتق** | `WordPressContract/WholesaleTiersMovedTest` · `Database/ProductFormPostTest` | ۱۰ + ۲ = **۱۲** |
| ۴-۷ پرسنل | جبرانِ **خوانده‌شده** · کفِ `subscriber` و تصمیم از capability · `activate()` با تعداد سطر | `Architecture/StaffAccountRemovalIsReadBackTest` · `WordPressContract/StaffDirectoryTest` · `Database/StaffAccountIntegrityTest` | ۳ + ۱۳ + ۷ = **۲۳** |
| ۴-۸ هدر | سه نشانی به هدر **داده** می‌شود، آنجا ساخته نمی‌شود · کلاس‌ها قاعده دارند | `Architecture/VendorHeaderAccountIsGivenToItTest` · `Architecture/StyledClassesHaveRulesTest` · `WordPressContract/VendorHeaderTest` | ۴ + ۲ + ۱۰ = **۱۶** |

⛔ **و آنچه این جدول اثبات نمی‌کند، صریحاً:** هیچ‌کدام از این آزمون‌ها روی
وردپرس واقعی، ووکامرس واقعی یا مرورگر اجرا نمی‌شود. ردیف‌های ۱۸ تا ۴۵ سند
پذیرش همان هشت بند را روی سایت می‌سنجند و همه **`NOT RUN`** اند. سوییت
مرورگری این دور **اجرا نشد**: این محیط وردپرس ندارد و `wordpress.org` از آن
۴۰۳ می‌دهد.

---

## پ) §۵ — مسیرهای فرم، و اینکه هر کدام کجا سنجیده می‌شود

| مسیرِ خواسته‌شده | کجا | وضعیت |
|---|---|---|
| ذخیره در هر چهار مرحله | `ProductFormPostTest` (۱۹) · `ProductFlowTest` | ✅ |
| جابه‌جایی بین مرحله‌ها | `testCreatingAProductFromStepOneLandsOnStepTwo` · `testSavingTheLadderFromTheProductFormLandsBackOnStepFive` | ✅ |
| بازخوانیِ فرم پس از ذخیره | `testASearchAReorderAndARefusalAllStayWhereTheyWere` · `ProductViewsTest` | ✅ |
| فیلدهای مرحله‌های دیگر دست‌نخورده | `testAFormThatDoesNotMentionTheFieldLeavesItExactlyAsItIs` · `testAFormWithNoBrandFieldAtAllLeavesTheBrandAlone` | ✅ |
| پیشنهاد بی‌پاسخ | `AtomicProposalReplacementTest` · `ProductBaselineAndDecisionsTest` | ✅ |
| تأیید / رد / نیازمند اصلاح | `ProductFlowTest` · `ProductBaselineAndDecisionsTest` | ✅ |
| ذخیرهٔ دوباره با پیشنهاد باز | `AtomicProposalReplacementTest` (جایگزینیِ **اتمیک**) | ✅ |
| **نسخهٔ کهنه** | ⛔ **تازه در این دور** — `testTheLoserOfTwoSimultaneousSavesIsToldWhoWonRatherThanThatItFailed` + دو کنترل | ✅ **بسته شد** |
| توضیحات بلندِ HTML | ⛔ **تازه در این دور** — `Unit/FieldSummaryTest` (۹) · `ProductViewsTest` سه آزمون تازه | ✅ **بسته شد** |
| تصویر / چند دسته / برند | `CategoryLinkFlowTest` · `BrandRegistryTest` · `StorefrontImageFallbackTest` | ✅ |
| قیمت صفر | `testAProductWithNoPriceIsTakenInAndReadsAsHavingNone` · `testAProductWithNoPriceDoesNotGetANoughtWrittenToTheShop` | ✅ |
| قیمت معتبر | `ProductFlowTest` · `ImportedParentFiguresTest` | ✅ |
| موجودی صفر و موجودی نامعلوم | `ImportedParentFiguresTest` · `testAnUnreadableRangePrintsNoPriceAtAll` | ✅ |
| ساده / متنوع / تنوعِ واردشده | `VariationFlowTest` · `StorefrontVariationEditTest` · ⛔ **تازه** `ProductTypeChangeTest` + چهار آزمون `ImportedParentFiguresTest` | ✅ **تکمیل شد** |
| لغو ایمن و بازیابی پس از خطا | `SaveAndRestoreFailureTest` · ⛔ **تازه** `testASaveThatFailsWhileNobodyElseWroteIsNotCalledAConflict` · `testAnArchivedProductIsRefusedByNameRatherThanCalledMissing` | ✅ **تکمیل شد** |
| nonce / مجوز / مالکیت | `VendorPostsAreNonceGuardedTest` · `StaffAccess` در هر سرویس · `testStaffActInTheShopOnlyWithinTheirOwnPermissions` | ✅ |
| دو کاربر هم‌زمان | `ConcurrentSaveTest` (۷) | ✅ |
| **هیچ تغییری پس از ردِ درخواست** | ⛔ **تازه در این دور** — `testRejectingAProposalChangesNothingAboutTheProductAtAll` (کلِ ردیف، ستون‌به‌ستون، به‌علاوهٔ نوشته‌های projector) | ✅ **بسته شد** |

### و یک اندازه‌گیری که این دور انجام شد و یافته داد

⛔ **هفده کدِ ردی که هیچ آزمونی assert نمی‌کرد** — پیدا شده با استخراج هر
`failure('...')` از چهار سرویسِ روبه‌فروشنده و جست‌وجوی هر کد در **هر چهار**
سوییت:

| کد | فایل | این دور |
|---|---|---|
| `bad_sale_price` | `ManageProducts` | ✅ **بسته شد** |
| `bad_sale_window` | `ManageProducts` | ✅ **بسته شد** |
| `product_archived` | `ManageProducts` | ✅ **بسته شد** |
| `decision_not_recorded` | `ReviewProducts` | ✅ **بسته شد** — ⛔ **و یافتهٔ واقعی همین بود** |
| `bad_type` · `inventory_saved_limits_not_written` · `product_saved_incomplete` | `ManageProducts` | ⚠️ **باز، با دلیل** |
| `baseline_not_recorded` · `title_required` | `ReviewProducts` | ⚠️ **باز، با دلیل** |
| `attribute_needs_options` · `attributes_first` · `bad_sale_price` · `incomplete_attribute` · `product_archived` · `too_many_attributes` · `too_many_variations` · `unknown_attribute` | `ManageVariations` | ⚠️ **باز، با دلیل** |

**دلیل، یکجا:** این سیزده، ردِ **اعتبارسنجیِ یک فیلد فرم** اند و فرم مقداری
را که از آن‌ها رد شود اصلاً نمی‌فرستد — یک `<select>` با چهار گزینه
`bad_type` تولید نمی‌کند، و سقفِ تعداد ویژگی در خودِ markup است. ⛔ **ولی
«نمی‌رسد» با «سنجیده شد» یکی نیست، و این جدول همین را می‌گوید**: یک دورِ بعد
که یکی از این‌ها را به یک مسیرِ قابل‌رسیدن ببرد، آزمونی نخواهد داشت.

### ⛔ و یافتهٔ واقعیِ آن اندازه‌گیری: گاردی که **قابل‌رسیدن نبود**

`ReviewProducts` دفترِ تصمیم را به‌صورت یک آرگومانِ **nullable** می‌گیرد، و
**هر** آزمون این مخزن — اندازه‌گیری‌شده، همه‌شان — `null` می‌فرستد. پس

```php
if ($this->decisions !== null && !$this->decisions->record(...)) {
```

نیمهٔ دومش هرگز اجرا نمی‌شد و `decision_not_recorded` فقط assert‌نشده نبود،
**قابل‌رسیدن نبود** — همان شکلی که `alpha.105` و `alpha.80` دربارهٔ آن یک چیز
می‌گویند: گاردِ قابل‌رسیدن‌نبوده از گاردِ غایب قابل تشخیص نیست.

آزمون تازه یک دفترِ **واقعی** روی گاردی می‌گذارد که دقیقاً یک نوشتن را رد
می‌کند، و کدی را که مدیر می‌گیرد می‌خواند.
⛔ **و context می‌گوید تصمیم خودش انجام شد** (`applied => yes`) — یک حالتِ
نیمه‌کارهٔ **عمدی و مستند**، نه شکلِ `alpha.31`. نسخهٔ اولِ همین آزمون خلافش
را فرض کرد و برای کنترل **همان محصول** را به کار برد، و جوابِ درستِ
`invalid_transition` را گرفت — دربارهٔ محصولی که از قبل جابه‌جا شده بود.

---

## ت) ⛔ یافتهٔ خودِ حلقهٔ falsification: یک نامِ کلاس که دو جزء برداشتندش

**این نقصِ همین دور است و خواندنِ کد پیدایش نکرد — حلقهٔ falsification کرد.**

§۲ برای سلولِ مقایسه یک `<details>` ساخت و ریشهٔ بلوکش را `tv-fold` گذاشت —
نامی که `DashboardView` از `alpha.33` مالکش است (کارتِ «اطلاعات و مدارک
فروشگاه»). پس شیوه‌نامه **دو** بلوکِ تک‌کلاسیِ `.tv-fold` گرفت:

| خط | قاعده | مالکش |
|---|---|---|
| ۳۰۸ | `.tv-card { … margin-block-end: var(--tv-s4) }` | primitive مشترک، ریتمِ عمودی بین کارت‌ها |
| ۴۰۷ (این دور) | `.tv-fold { margin: 0 }` | بلوکِ تازهٔ سلولِ مقایسه |
| ۱۴۰۱ (`alpha.33`) | `.tv-fold { padding: 0 }` | کارتِ مدارک فروشگاه |

اختصاصیتِ هر سه `(0,1,0)` است و **دیرتر برنده است**، پس عنصری که
`class="tv-card tv-fold"` دارد — دقیقاً همان کارتِ مدارک در داشبورد فروشنده —
**فاصلهٔ پایینی‌اش را از دست داد**. یک جزءِ تازه، فاصلهٔ یک صفحهٔ سالم و
بی‌ربط را برداشت: همان چیزی که **قانون اول** منع می‌کند.

⛔ **و هیچ آزمونی نمی‌توانست ببیندش.** `StyledClassesHaveRulesTest` می‌پرسد
«آیا این کلاس **قاعده‌ای** دارد؟» — داشت. نمی‌پرسد «آیا قاعده‌ای که دارد مالِ
خودش است؟»

**اصلاح، و اینکه چرا این سمت از اصلاح است نه آن سمت.** بلوکِ **تازهٔ خودم**
به `tv-long` تغییر نام داد (ریشه، `--mine`، `__head`، `__peek`، `__size`،
`__body`) — یعنی کدِ همین دور، نه کدِ سالمِ `alpha.33`. داشبورد یک بایت هم
عوض نشد و `.tv-fold { padding: 0 }` سر جایش است.

**و گاردش:** `tests/Architecture/CardRhythmIsNotResetTest.php` —
«هیچ کلاسی که با `tv-card` در **همان** `class="…"` می‌نشیند، `margin` را
reset نمی‌کند.» فهرست از خودِ view ها **مشتق** می‌شود (نه تایپ‌شده)، پس جزءِ
دورِ بعد از روزی که نوشته شود پوشیده است.
⛔ **و فقط `margin`، با دلیلِ نوشته‌شده:** `.tv-card` ‌`padding` هم می‌دهد و
`.tv-fold { padding: 0 }` از `alpha.33` **به‌عمد** کنارش می‌زند — padding
داخلی تصمیمِ بلوکِ درونی است، ولی فاصلهٔ **بین** کارت‌ها مالِ primitive است.
گاردِ پهن‌تر از نقص، نقصِ بزرگ‌تری است (`alpha.60`).

**دو اندازه‌گیری، به ترتیب:** گارد روی درختِ پیش از اصلاح **قرمز** شد و دقیقاً
`.tv-fold { margin: 0; }` را نام برد؛ پس از تغییر نام سبز است. و جهش ۱۹ همان
شکل را با دست برمی‌گرداند (`margin: 0` روی `.tv-shop`، که آن هم کنار
`tv-card` می‌نشیند) و سوییت معماری **یک شکست** می‌دهد.

### ⛔ و جهش ۷، که بار اول **اعمال نشد**

`.tv-fold__peek {` در شیوه‌نامه **دو بار** بود — خودِ قاعده، و سلکتورِ مرکبِ
`--mine` یک خط بعدتر — پس درایور ردش کرد. مطابق قاعدهٔ خودِ درایور این یک
**شکست** است نه گاردِ شکست‌ناپذیر (`alpha.88`).
⛔ **و اصلاحِ بدیهی‌اش چیزی را اندازه نمی‌گرفت:** گاردِ معماری می‌پرسد نام
**جایی** در شیوه‌نامه هست یا نه، پس تغییر نامِ یکی از دو نقطه نام را سر جایش
می‌گذارد و سوییت سبز می‌مانَد. جهش بازنویسی شد روی کلاسی که **یک بار** هست و
سقف ارتفاع را حمل می‌کند (`.tv-long__body`) — جهشی که **همان ادعا** را
بشکند، نه بخشی از آن (`alpha.82`).

---

## ث) §۱۳ — پشتیبان، و دو چیزی که یکی نیستند

| پرسش | جواب |
|---|---|
| پیش از نصب روی staging پشتیبان لازم است؟ | ⛔ **بله، الزامی** — ردیف ۰ سند پذیرش، و شرطِ **هر** ردیف دیگری |
| بازگشتِ `alpha.108` → `alpha.107` migration لازم دارد؟ | ❌ **نه** — ساختار **۳۹ → ۳۹**، صفر گام. بند **۸۸** `docs/upgrade-and-rollback.md` |
| پس تعویض ZIP داده را برمی‌گرداند؟ | ⛔ **نه، و این تفکیکِ اصلی بند ۱۳ است.** بازگشتِ **کد** با بازگرداندن **داده** یکی نیست |
| کدام ردیف‌ها داده می‌نویسند؟ | ستون «نوشتنی؟» سند پذیرش — ۲۴ ردیف از ۱۰۴ |
| هر کدام چطور برمی‌گردد؟ | ستون «بازگشت دادهٔ این ردیف»، به ازای هر ردیف |
| اگر آنجا «پشتیبان ردیف ۰» نوشته شده؟ | ⛔ یعنی **تنها** راهش بازگرداندن پشتیبان است — و بازگرداندن پشتیبان **هر چیزی را که پس از آن لحظه نوشته شده** هم با خودش می‌برد |

⛔ **سه ردیف که بازگشتشان گفته می‌شود چون برنمی‌گردند:**

| ردیف | چه چیزی برنمی‌گردد |
|---|---|
| ۵۷ — «اجرای دستی یک دسته» از صف | cursor جلو رفته؛ کار انجام شده است. تنها راه، پشتیبان ردیف ۰ |
| ۳۷ — پرسنل آزمایشی | ⛔ یک **حسابِ واقعیِ وردپرس** ساخته می‌شود. این سند حساب واقعی حذف نمی‌کند؛ غیرفعالش کنید و در گزارش نام ببرید — تصمیمِ حذف با مالک است |
| ۷۲ و ۶۰ — سفارش واقعی | ردیف دفترکل و قلم سفارش نوشته می‌شود. سفارش را به زباله بفرستید، و اگر دفترکل باید پاک شود، پشتیبان ردیف ۰ |
