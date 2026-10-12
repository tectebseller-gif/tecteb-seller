<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Product\Application\ManageProducts;
use Tecteb\Marketplace\Modules\Product\Domain\ProductImagePolicy;
use Tecteb\Marketplace\Modules\Product\Domain\ProductSort;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\SpecFieldType;

/**
 * Every Persian sentence the product screens say.
 *
 * `notice()` returns null for a code it does not own, so the caller falls back
 * to the vendor area's vocabulary: each module keeps its own sentences, and a
 * shared code (forbidden, storage_failed) is still said the same way
 * everywhere.
 */
final class ProductMessages
{
    /**
     * What to call a product whose title is empty.
     *
     * **It is a display fallback and nothing else.** No title is written
     * anywhere to make this read better: the row keeps its empty `title`, and
     * `TitleSortRepair` keeps sorting it last (`PersianCollation` puts an empty
     * key in group 3). A placeholder saved as data would be a title somebody
     * has to notice and delete later, and the vendor never typed it.
     *
     * **One sentence, in every place.** Until `alpha.35` there were two: the
     * manager's list and detail said «محصول ۲» from the marketplace id, while
     * the vendor's list and the manager's own review card said «بدون عنوان» —
     * so the same untitled draft had two names, and on the review page it had
     * both AT ONCE. Neither half was wrong about the data; they were written in
     * different rounds and nothing compared them. Now the id is what tells two
     * untitled drafts apart and «بدون عنوان» is what says why they have no
     * name, and they are one string.
     */
    public static function displayTitle(string $title, int $productId): string
    {
        if (trim($title) !== '') {
            return $title;
        }
        return sprintf(
            /* translators: %s: the product's marketplace id, in Persian digits */
            __('بدون عنوان — محصول #%s', 'tecteb-marketplace-core'),
            PersianDigits::toPersian((string) $productId)
        );
    }

    public static function status(ProductStatus $status): string
    {
        return match ($status) {
            ProductStatus::Draft => __('پیش‌نویس', 'tecteb-marketplace-core'),
            // «ارسال‌شده برای بررسی» rather than «در انتظار بررسی», from
            // `alpha.48`. This is the product's own STATUS and the chip that
            // shows it counts products in that status — but the page also has
            // a «نیازمند بررسی» count, which is a different set: a published
            // product carrying a proposal needs review and is not submitted.
            // Two numbers under one phrase is how the owner came to read «در
            // انتظار بررسی ۰» above a product whose card said a proposal was
            // waiting. Naming the status after the EVENT — the vendor sent it
            // — leaves «نیازمند بررسی» free to mean the queue.
            ProductStatus::Submitted => __('ارسال‌شده برای بررسی', 'tecteb-marketplace-core'),
            ProductStatus::ChangesRequested => __('نیازمند اصلاح', 'tecteb-marketplace-core'),
            ProductStatus::Published => __('منتشرشده', 'tecteb-marketplace-core'),
            ProductStatus::Suspended => __('تعلیق‌شده', 'tecteb-marketplace-core'),
            ProductStatus::Archived => __('بایگانی', 'tecteb-marketplace-core'),
        };
    }

    /** success | warning | error | neutral */
    public static function statusTone(ProductStatus $status): string
    {
        return match ($status) {
            ProductStatus::Published => 'success',
            ProductStatus::Submitted, ProductStatus::ChangesRequested => 'warning',
            ProductStatus::Suspended => 'error',
            ProductStatus::Draft, ProductStatus::Archived => 'neutral',
        };
    }

    /**
     * WooCommerce's own post status, in Persian — the ONE mapping.
     *
     * It was written once inside the list's cell, and the product's own page
     * printed `get_post_status()` raw: the same product read «منتشرشده» in the
     * list and `publish` on its own page. Two readings of one value are two
     * chances to translate it differently, so there is one, and both screens
     * call it.
     *
     * `''` is the honest answer for a post that is gone — not «پیش‌نویس»,
     * which is a plausible word for a broken link to hide behind — and a
     * status this plugin does not know is shown as itself rather than guessed
     * at, because a WooCommerce extension may add one.
     */
    public static function shopStatus(string $status): string
    {
        return match ($status) {
            'publish' => __('منتشرشده', 'tecteb-marketplace-core'),
            'draft' => __('پیش‌نویس', 'tecteb-marketplace-core'),
            'pending' => __('در انتظار', 'tecteb-marketplace-core'),
            'private' => __('خصوصی', 'tecteb-marketplace-core'),
            'future' => __('زمان‌بندی‌شده', 'tecteb-marketplace-core'),
            'trash' => __('در زباله‌دان', 'tecteb-marketplace-core'),
            'auto-draft' => __('پیش‌نویس خودکار', 'tecteb-marketplace-core'),
            '' => __('پست پیدا نشد', 'tecteb-marketplace-core'),
            default => $status,
        };
    }

    /** The product has no WooCommerce row at all — a state, not a status. */
    public static function shopNotProjected(): string
    {
        return __('هنوز در ووکامرس ساخته نشده', 'tecteb-marketplace-core');
    }

    /**
     * One CSV column's Persian name — the same word the product form uses.
     *
     * Written here and not in the view so the guidance beside the file chooser
     * can be BUILT from `ProductCsv::COLUMNS` instead of listing the columns by
     * hand. A hand-written list is a second source of truth: it stays right
     * until the day a column is added, and then it quietly tells the vendor to
     * edit a column that no longer exists (or omits one that does).
     */
    public static function csvColumn(string $column): string
    {
        return match ($column) {
            'sku' => __('کد SKU', 'tecteb-marketplace-core'),
            'title' => __('عنوان محصول', 'tecteb-marketplace-core'),
            'type' => __('نوع محصول', 'tecteb-marketplace-core'),
            'category' => __('دسته', 'tecteb-marketplace-core'),
            'brand' => __('برند', 'tecteb-marketplace-core'),
            'short_description' => __('توضیح کوتاه', 'tecteb-marketplace-core'),
            'price' => __('قیمت (تومان)', 'tecteb-marketplace-core'),
            'sale_price' => __('قیمت با تخفیف', 'tecteb-marketplace-core'),
            'sale_from' => __('شروع تخفیف', 'tecteb-marketplace-core'),
            'sale_to' => __('پایان تخفیف', 'tecteb-marketplace-core'),
            'stock' => __('موجودی', 'tecteb-marketplace-core'),
            'min_purchase' => __('حداقل خرید', 'tecteb-marketplace-core'),
            'max_purchase' => __('حداکثر خرید', 'tecteb-marketplace-core'),
            'purchase_step' => __('گام تعداد', 'tecteb-marketplace-core'),
            'weight_grams' => __('وزن (گرم)', 'tecteb-marketplace-core'),
            'dimensions' => __('ابعاد', 'tecteb-marketplace-core'),
            'tax_class' => __('گروه مالیاتی', 'tecteb-marketplace-core'),
            'id' => __('شناسه', 'tecteb-marketplace-core'),
            'status' => __('وضعیت', 'tecteb-marketplace-core'),
            // A column added to the file and not named here shows as its own
            // key, which is honest and visibly unfinished — the alternative is
            // a column the vendor cannot see at all.
            default => $column,
        };
    }

    /** The order a list is read in — the labels for `ProductSort`. */
    public static function sort(ProductSort $sort): string
    {
        return match ($sort) {
            ProductSort::LastChanged => __('آخرین تغییر', 'tecteb-marketplace-core'),
            ProductSort::OldestChanged => __('قدیمی‌ترین تغییر', 'tecteb-marketplace-core'),
            ProductSort::Title => __('عنوان (الفبایی فارسی)', 'tecteb-marketplace-core'),
        };
    }

    /**
     * @return array<string,string> value => label, for a <select>
     *
     * Built from `ProductSort::cases()`, so a case the query knows and the
     * control does not cannot exist.
     */
    public static function sorts(): array
    {
        $labels = [];
        foreach (ProductSort::cases() as $case) {
            $labels[$case->value] = self::sort($case);
        }
        return $labels;
    }

    public static function type(string $type): string
    {
        return match ($type) {
            ProductType::SIMPLE => __('ساده', 'tecteb-marketplace-core'),
            ProductType::VARIABLE => __('متغیر', 'tecteb-marketplace-core'),
            default => $type,
        };
    }

    /** @return array<string,string> value => label, for a <select> */
    public static function types(): array
    {
        $labels = [];
        foreach (ProductType::all() as $type) {
            $labels[$type] = self::type($type);
        }
        return $labels;
    }

    public static function fieldType(SpecFieldType $type): string
    {
        return match ($type) {
            SpecFieldType::Text => __('متن', 'tecteb-marketplace-core'),
            SpecFieldType::Number => __('عدد', 'tecteb-marketplace-core'),
            SpecFieldType::Boolean => __('بله / خیر', 'tecteb-marketplace-core'),
            SpecFieldType::Choice => __('انتخاب از فهرست', 'tecteb-marketplace-core'),
            SpecFieldType::Date => __('تاریخ', 'tecteb-marketplace-core'),
        };
    }

    /** @return array<string,string> */
    public static function fieldTypes(): array
    {
        $labels = [];
        foreach (SpecFieldType::cases() as $case) {
            $labels[$case->value] = self::fieldType($case);
        }
        return $labels;
    }

    /** The names the form and the error messages use for the same field. */
    public static function field(string $key): string
    {
        return match ($key) {
            'title' => __('عنوان', 'tecteb-marketplace-core'),
            'type' => __('نوع محصول', 'tecteb-marketplace-core'),
            'category', 'categoryKey' => __('دسته', 'tecteb-marketplace-core'),
            'price', 'priceMinor' => __('قیمت', 'tecteb-marketplace-core'),
            'image' => __('تصویر', 'tecteb-marketplace-core'),
            'brand' => __('برند', 'tecteb-marketplace-core'),
            'shortDescription' => __('توضیح کوتاه', 'tecteb-marketplace-core'),
            'description' => __('توضیحات کامل', 'tecteb-marketplace-core'),
            'salePriceMinor' => __('قیمت با تخفیف', 'tecteb-marketplace-core'),
            'saleFrom' => __('شروع تخفیف', 'tecteb-marketplace-core'),
            'saleTo' => __('پایان تخفیف', 'tecteb-marketplace-core'),
            'weightGrams' => __('وزن (گرم)', 'tecteb-marketplace-core'),
            'dimensions' => __('ابعاد', 'tecteb-marketplace-core'),
            'taxClass' => __('گروه مالیاتی', 'tecteb-marketplace-core'),
            'sku' => __('کد SKU', 'tecteb-marketplace-core'),
            'stock' => __('موجودی', 'tecteb-marketplace-core'),
            'minPurchase' => __('حداقل خرید', 'tecteb-marketplace-core'),
            'maxPurchase' => __('حداکثر خرید', 'tecteb-marketplace-core'),
            'purchaseStep' => __('گام تعداد', 'tecteb-marketplace-core'),
            default => $key,
        };
    }

    /**
     * The five fields the marketplace writes into WooCommerce.
     *
     * A separate list from `field()` on purpose: these are names of things on
     * the SHOP — «متن صفحهٔ محصول» is not the vendor's «توضیح کوتاه», and a
     * manager deciding who owns which one has to be told which is which.
     */
    public static function storefrontField(string $key): string
    {
        return match ($key) {
            'title' => __('عنوان محصول', 'tecteb-marketplace-core'),
            'short_description' => __('توضیح کوتاه', 'tecteb-marketplace-core'),
            'description' => __('متن صفحهٔ محصول', 'tecteb-marketplace-core'),
            'category' => __('دستهٔ ووکامرس', 'tecteb-marketplace-core'),
            'images' => __('تصویرها', 'tecteb-marketplace-core'),
            default => $key,
        };
    }

    /**
     * @param array<string,scalar|null> $context
     * @return string|null null when this code belongs to another module
     */
    public static function notice(string $code, array $context = []): ?string
    {
        $fa = static fn (mixed $v): string => PersianDigits::toPersian((string) $v);
        // «images» is not a sentence a manager reads. This turns the parts a
        // failed restore names into the words the rest of the screen uses.
        $parts = static function (array $context): string {
            $raw = (string) ($context['restore_failed'] ?? '');
            if ($raw === '') {
                // Named or not, the sentence has to be about something. The
                // fallback lives HERE rather than at each call site: a ternary
                // at three call sites inside another ternary is the PHP 8.0
                // left-associativity error this repository lints for.
                return __('محصول', 'tecteb-marketplace-core');
            }
            $names = array_map(
                static fn (string $key): string => match (trim($key)) {
                    'details' => __('مقدارهای محصول', 'tecteb-marketplace-core'),
                    'specs' => __('مشخصات', 'tecteb-marketplace-core'),
                    'images' => __('تصویرها', 'tecteb-marketplace-core'),
                    'status' => __('وضعیت محصول', 'tecteb-marketplace-core'),
                    default => trim($key),
                },
                explode(',', $raw)
            );
            return implode('، ', array_filter($names));
        };
        $fields = static function (array $context): string {
            $raw = (string) ($context['fields'] ?? '');
            if ($raw === '') {
                return '';
            }
            $names = array_map(
                static fn (string $key): string => ProductMessages::field(trim($key)),
                explode('، ', $raw)
            );
            return implode('، ', $names);
        };
        return match ($code) {
            'product_created' => __('محصول به‌عنوان پیش‌نویس ساخته شد. مرحله‌های بعد را کامل کنید.', 'tecteb-marketplace-core'),
            // The specs or the gallery did not land, and the row did. Both
            // halves are said, because «ذخیره شد» about a half-written draft
            // is what `alpha.40` and earlier told the vendor — and the id is
            // in the sentence so they know which draft to go back into.
            'product_created_incomplete' => sprintf(
                /* translators: 1: which parts, 2: product id */
                __('محصول به‌عنوان پیش‌نویس ساخته شد، ولی %1$s ذخیره نشد. پیش‌نویس #%2$s را باز کنید و همان بخش را دوباره ثبت کنید؛ بقیهٔ اطلاعات سر جایش است.', 'tecteb-marketplace-core'),
                self::parts((string) ($context['missing'] ?? '')),
                PersianDigits::toPersian((string) ($context['product_id'] ?? 0))
            ),
            // A REPLAY, and the sentence says so rather than «ساخته شد». The
            // browser resent a submission that had already been processed, so
            // nothing was created and — the part that matters — nothing was
            // overwritten. What it cannot say is whether the first attempt
            // finished, because a draft with no pictures is what a failed
            // gallery write leaves AND what a vendor who emptied the gallery
            // leaves. So it states the counts and asks them to look.
            'product_created_replayed' => sprintf(
                /* translators: 1: product id, 2: how many pictures, 3: how many specifications */
                __('این فرم قبلاً همین محصول را ساخته بود، پس چیزی دوباره ساخته و چیزی بازنویسی نشد: پیش‌نویس #%1$s. همین حالا %2$s تصویر و %3$s مشخصهٔ ثبت‌شده دارد — بازش کنید و اگر چیزی جا مانده کاملش کنید.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) ($context['product_id'] ?? 0)),
                PersianDigits::toPersian((string) ($context['images'] ?? 0)),
                PersianDigits::toPersian((string) ($context['specs'] ?? 0))
            ),
            'product_saved_incomplete' => sprintf(
                /* translators: %s: which parts */
                __('اطلاعات محصول ذخیره شد، ولی %s ذخیره نشد. همان بخش را دوباره ثبت کنید؛ چیز دیگری از بین نرفته است.', 'tecteb-marketplace-core'),
                self::parts((string) ($context['missing'] ?? ''))
            ),
            'product_saved' => __('تغییرات محصول ذخیره شد.', 'tecteb-marketplace-core'),
            // Three sentences, because three things can happen to the selling
            // rule and «ذخیره شد» is only one of them. A limit a manager owns
            // did NOT move, and a vendor who is told it did will go on
            // believing the shop enforces a number it does not have.
            'inventory_saved' => ((string) ($context['limits_held'] ?? '')) !== ''
                ? sprintf(
                    /* translators: %s: which limits */
                    __('موجودی و مشخصات فروش ذخیره شد. ولی %s روی محصول در ووکامرس به‌دست مدیر تغییر کرده است، پس از پیشخوان شما بازنویسی نمی‌شود؛ برای تغییر آن از مدیر بخواهید.', 'tecteb-marketplace-core'),
                    self::limitNames((string) $context['limits_held'])
                )
                : __('موجودی و مشخصات فروش همین حالا ذخیره شد و حدود خرید روی فروشگاه هم اعمال شد.', 'tecteb-marketplace-core'),
            'inventory_saved_limits_not_written' => sprintf(
                /* translators: %s: which limits */
                __('موجودی و کد SKU ذخیره شد، ولی %s روی فروشگاه نوشته نشد و محدودیت قبلی سر جایش مانده است. دوباره ذخیره کنید؛ اگر تکرار شد به مدیر اطلاع دهید.', 'tecteb-marketplace-core'),
                self::limitNames((string) ($context['limits_failed'] ?? ''))
            ),
            'stock_revision_requested' => sprintf(
                /* translators: 1: the proposed stock */
                __('درخواست تغییر موجودی به %1$s ثبت شد و در صف بررسی مدیر است. تا تأیید، موجودی فعلی روی فروشگاه تغییر نمی‌کند. حدود خرید و کد SKU همین حالا اعمال شدند.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) ($context['proposed_stock'] ?? ''))
            ),
            'stock_revision_unchanged' => __('چیزی برای تغییر نبود: همین درخواست موجودی از قبل در صف بررسی مدیر است. حدود خرید و کد SKU در صورت تغییر، اعمال شدند.', 'tecteb-marketplace-core'),

            // ---- brands (§4) ------------------------------------------------
            // Each refusal says what to do next, because «برند تأیید نشده» on
            // its own leaves a vendor with a box they cannot satisfy.
            'brand_not_approved' => sprintf(
                /* translators: %s: the brand the vendor named */
                __('برند «%s» در فهرست برندهای تأییدشده نیست، پس ذخیره نشد و چیزی از محصول تغییر نکرد. برند را از فهرست انتخاب کنید، یا در همان بخش «برند من در فهرست نیست» افزودنش را درخواست کنید.', 'tecteb-marketplace-core'),
                (string) ($context['brand'] ?? '')
            ),
            'brand_list_unavailable' => __('فهرست برندها از ووکامرس خوانده می‌شود و ووکامرس در دسترس نیست، پس تغییر برند انجام نشد. برند فعلی محصول دست‌نخورده است؛ بقیهٔ فرم را می‌توانید ذخیره کنید.', 'tecteb-marketplace-core'),
            'brand_name_invalid' => __('نام برند خالی یا بیش از حد بلند است. یک نام کوتاه و خوانا بنویسید.', 'tecteb-marketplace-core'),
            'brand_already_approved' => sprintf(
                /* translators: %s: the brand as the list holds it */
                __('این نام از قبل در فهرست است: «%s». درخواستی لازم نیست؛ همان را از فهرست انتخاب کنید. (تفاوت فاصله، نیم‌فاصله و ی/ک عربی و فارسی نادیده گرفته می‌شود.)', 'tecteb-marketplace-core'),
                (string) ($context['brand'] ?? '')
            ),
            'brand_request_open' => sprintf(
                /* translators: %s: the brand already requested */
                __('برای این محصول از قبل درخواست برند «%s» ثبت شده و در انتظار بررسی مدیر است. تا تعیین تکلیف آن، درخواست تازه‌ای ثبت نمی‌شود.', 'tecteb-marketplace-core'),
                (string) ($context['brand'] ?? '')
            ),
            'brand_request_elsewhere' => sprintf(
                /* translators: %s: the brand somebody else requested */
                __('درخواست افزودن «%s» از قبل ثبت شده است — ممکن است فروشندهٔ دیگری آن را خواسته باشد. به‌محض تأیید مدیر، در فهرست شما هم دیده می‌شود.', 'tecteb-marketplace-core'),
                (string) ($context['brand'] ?? '')
            ),
            'brand_requested' => sprintf(
                /* translators: %s: the requested brand */
                __('درخواست افزودن برند «%s» ثبت شد و در انتظار بررسی مدیر است. تا تأیید، برند فعلی این محصول تغییر نمی‌کند.', 'tecteb-marketplace-core'),
                (string) ($context['brand'] ?? '')
            ),
            'brand_approved' => sprintf(
                /* translators: %s: the approved brand */
                __('برند «%s» به فهرست اضافه شد و روی همین محصول اعمال شد.', 'tecteb-marketplace-core'),
                (string) ($context['brand'] ?? '')
            ),
            'brand_rejected' => __('درخواست برند رد شد. برند فعلی محصول تغییر نکرده است و دلیل شما برای فروشنده ثبت شد.', 'tecteb-marketplace-core'),
            // The three half-finished states, each naming WHICH half, because
            // «انجام نشد» would send the manager to add a brand that is
            // already in the list.
            'brand_not_registered' => sprintf(
                /* translators: %s: the brand */
                __('افزودن «%s» به فهرست انجام نشد و هیچ‌چیز ثبت نشد. درخواست باز است؛ دوباره تلاش کنید.', 'tecteb-marketplace-core'),
                (string) ($context['brand'] ?? '')
            ),
            'brand_registered_not_recorded' => sprintf(
                /* translators: %s: the brand */
                __('برند «%s» به فهرست ووکامرس اضافه شد، ولی ثبت این تصمیم در بازارگاه انجام نشد. برند را دوباره اضافه نکنید؛ صفحه را تازه کنید و وضعیت درخواست را ببینید.', 'tecteb-marketplace-core'),
                (string) ($context['brand'] ?? '')
            ),
            'brand_not_applied' => sprintf(
                /* translators: %s: the brand */
                __('برند «%s» در فهرست هست و نوشتن آن روی این محصول انجام نشد. با «تلاش دوباره برای اعمال» ادامه دهید.', 'tecteb-marketplace-core'),
                (string) ($context['brand'] ?? '')
            ),
            'brand_not_synced' => sprintf(
                /* translators: %s: the brand */
                __('برند «%s» روی ردیف بازارگاه نوشته شد و به ووکامرس نرسید. درخواست باز مانده است؛ با «تلاش دوباره برای اعمال» ادامه دهید.', 'tecteb-marketplace-core'),
                (string) ($context['brand'] ?? '')
            ),
            'brand_applied_not_recorded' => __('برند روی محصول اعمال شد و بسته‌شدن درخواست ثبت نشد. صفحه را تازه کنید؛ اگر درخواست باز مانده، «تلاش دوباره برای اعمال» بی‌خطر است و چیزی را دو بار نمی‌نویسد.', 'tecteb-marketplace-core'),
            'brand_not_in_list' => __('برندی که این درخواست به آن اشاره می‌کند در فهرست ووکامرس نیست — ممکن است با دست حذف شده باشد. درخواست را رد کنید و از فروشنده بخواهید دوباره ثبت کند.', 'tecteb-marketplace-core'),
            // The structure is not the shape a write needs, so nothing was
            // written. Named columns, because «خطای ذخیره» sends somebody to
            // support and this sends them to the health page.
            'schema_unsound' => sprintf(
                /* translators: %s: the columns whose shape is wrong */
                __('ذخیره انجام نشد چون ساختار جدول محصول‌ها آن چیزی نیست که این نسخه لازم دارد: %s. هیچ دادهٔ غلطی نوشته نشد. صفحهٔ «سلامت» را باز کنید و ارتقای ساختار را کامل کنید؛ تا آن موقع محصولی ذخیره نکنید.', 'tecteb-marketplace-core'),
                (string) ($context['columns'] ?? '')
            ),
            // The structure could not be READ. Its own sentence, because
            // naming columns here would be naming an answer nobody has: this
            // is «پرسیده نشد», not «غلط است» (`alpha.49`).
            'schema_unknown' => __('ذخیره انجام نشد چون ساختار جدول محصول‌ها خوانده نشد، و ساختار نامعلوم با ساختار سالم یکی گرفته نمی‌شود. هیچ دادهٔ غلطی نوشته نشد. صفحهٔ «سلامت» را باز کنید؛ اگر ارتقای ساختار ناتمام است کاملش کنید و بعد دوباره ذخیره کنید.', 'tecteb-marketplace-core'),
            // The replacement could not be held together, so NOTHING was
            // closed. The sentence says that, because the whole finding was a
            // vendor told «ذخیره نشد» about a request that had been destroyed.
            'revision_not_atomic' => __('این ذخیره نسخهٔ پیشنهادی تازه‌ای نساخت، چون جایگزینی پیشنهاد باید یکجا انجام شود و اینجا نشد. پیشنهاد قبلی شما سر جایش و در صف بررسی مدیر است؛ هیچ‌چیز حذف نشد. چند لحظه بعد دوباره ذخیره کنید.', 'tecteb-marketplace-core'),
            'nothing_to_finish' => __('این درخواست چیزی برای کامل‌کردن ندارد.', 'tecteb-marketplace-core'),
            'product_submitted' => __('محصول برای بررسی مدیر فرستاده شد. تا تعیین تکلیف، نسخه فعلی تغییر نمی‌کند.', 'tecteb-marketplace-core'),
            'product_published' => __('محصول منتشر شد؛ فروشگاه شما مجوز انتشار مستقیم دارد.', 'tecteb-marketplace-core'),
            // «و موجودی همین حالا اعمال شد» was removed in `alpha.48`: on a
            // published product the stock is part of the proposal now, and a
            // vendor told it applied at once would believe they had taken a
            // sold-out product down. What IS immediate is named instead.
            'revision_requested' => __('تغییرهای حساس به‌صورت «نسخه پیشنهادی» ثبت شد و نسخه منتشرشده تا تأیید مدیر دست‌نخورده می‌ماند. کد SKU و حدود خرید همین حالا اعمال شدند؛ تغییر موجودی هم با همین پیشنهاد در صف بررسی است.', 'tecteb-marketplace-core'),
            // Nothing happened, and that is the message. A second «ثبت شد»
            // over an untouched proposal would tell the vendor they had just
            // changed something, and would have the manager looking for a new
            // request that does not exist.
            'revision_unchanged' => __('چیزی برای تغییر نبود: نسخه پیشنهادی شما همان است که بود و هنوز در صف بررسی مدیر است. کد SKU و حدود خرید در صورت تغییر، اعمال شدند.', 'tecteb-marketplace-core'),
            'product_archived_ok' => __('محصول بایگانی شد. سابقه و سفارش‌های قبلی آن پاک نشده است.', 'tecteb-marketplace-core'),
            'product_restored' => __('محصول از بایگانی به پیش‌نویس بازگشت.', 'tecteb-marketplace-core'),

            // A refused row is named, not counted. «۴ نرفت» sends somebody
            // hunting through forty rows; «۴ نرفت: ۱۲، ۱۹، ۲۳، ۴۱» does not.
            // Three outcomes, three sentences. One sentence with two numbers
            // in it made «همه رفت» and «هیچ‌کدام نرفت» look like the same
            // event — and the reasons, which existed all along, were dropped
            // on the way through the redirect, so the owner read
            // «۴ مورد انجام نشد:» and then nothing. They are a table under
            // this notice now, which is where a list of forty belongs.
            'bulk_done' => sprintf(
                /* translators: %s: how many rows succeeded */
                __('اقدام گروهی روی %s مورد انجام شد.', 'tecteb-marketplace-core'),
                $fa((string) ($context['ok'] ?? 0))
            ),
            'bulk_partial' => sprintf(
                /* translators: 1: succeeded, 2: refused */
                __('%1$s مورد انجام شد و %2$s مورد انجام نشد. دلیل هرکدام در جدول زیر آمده است.', 'tecteb-marketplace-core'),
                $fa((string) ($context['ok'] ?? 0)),
                $fa((string) ($context['failed'] ?? 0))
            ),
            'bulk_none' => sprintf(
                /* translators: %s: how many rows were refused */
                __('هیچ‌کدام از %s مورد انجام نشد. دلیل هرکدام در جدول زیر آمده است.', 'tecteb-marketplace-core'),
                $fa((string) ($context['failed'] ?? 0))
            ),
            'stock_moved' => sprintf(
                /* translators: 1: stock when proposed, 2: stock now, 3: proposed stock */
                __('موجودی مؤثر از زمان ثبت این پیشنهاد تغییر کرده است: هنگام پیشنهاد %1$s، اکنون %2$s، پیشنهاد %3$s. برای اینکه عدد تازه بی‌صدا بازنویسی نشود، تأیید انجام نشد؛ اگر مقدار پیشنهادی را می‌خواهید، گزینهٔ «با وجود این تغییر اعمال کن» را انتخاب و دوباره تأیید کنید.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) ($context['baseline_stock'] ?? '')),
                PersianDigits::toPersian((string) ($context['current_stock'] ?? '')),
                PersianDigits::toPersian((string) ($context['proposed_stock'] ?? ''))
            ),
            // The box was ticked about a number that is no longer the number.
            // Four of them, because the manager has to see what changed under
            // their decision — and «دوباره تأیید کنید» is only honest if the
            // page they go back to shows the new one.
            'stock_moved_again' => sprintf(
                /* translators: 1: stock when proposed, 2: stock the page showed, 3: stock now, 4: proposed stock */
                __('موجودی بعد از باز شدن این صفحه هم تغییر کرد: هنگام پیشنهاد %1$s، آنچه به شما نشان داده شد %2$s، اکنون %3$s، پیشنهاد %4$s. گزینهٔ «با وجود این تغییر اعمال کن» برای همان عددی است که دیده‌اید، پس تأیید انجام نشد. صفحه را تازه کنید، عدد تازه را ببینید و اگر باز هم موافقید دوباره تأیید کنید.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) ($context['baseline_stock'] ?? '')),
                PersianDigits::toPersian((string) ($context['seen_stock'] ?? '')),
                PersianDigits::toPersian((string) ($context['current_stock'] ?? '')),
                PersianDigits::toPersian((string) ($context['proposed_stock'] ?? ''))
            ),
            // SOMEBODY ELSE MOVED IT between the check and the write, so
            // nothing was written and their change survives. Three numbers,
            // because «دوباره تأیید کنید» is only honest if the manager can see
            // what happened under their decision — and the page they go back to
            // now shows the shop's own number.
            'stock_moved_during_write' => sprintf(
                /* translators: 1: the approved stock, 2: the stock this approval was checked against, 3: what the shop holds now */
                __('موجودی تأییدشده %1$s نوشته نشد، چون در همان لحظه کسِ دیگری آن را عوض کرد: این تأیید روی %2$s بررسی شده بود و فروشگاه اکنون %3$s دارد. تغییر او دست‌نخورده ماند و هیچ عددی بازنویسی نشد؛ پیشنهاد باز است. صفحه را تازه کنید، عدد تازه را ببینید و اگر باز هم موافقید دوباره تأیید کنید.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) ($context['proposed_stock'] ?? '')),
                PersianDigits::toPersian((string) ($context['expected_stock'] ?? '')),
                PersianDigits::toPersian((string) ($context['shop_stock'] ?? ''))
            ),
            // The shop would not say what it holds, so there is nothing to
            // guard the write against — and an unguarded write is the defect
            // this round removed. Nothing was written.
            'stock_unreadable' => sprintf(
                /* translators: %s: the proposed stock */
                __('موجودی فعلی این محصول از ووکامرس خوانده نشد، پس موجودی پیشنهادی %s نوشته نشد و پیشنهاد باز مانده است. نوشتن بدون دانستن عدد فعلی می‌تواند فروش یا مرجوعیِ همین لحظه را بازنویسی کند. وضعیت موجودی محصول را در ووکامرس ببینید (مدیریت موجودی روشن باشد) و بعد دوباره تأیید کنید.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) ($context['proposed_stock'] ?? ''))
            ),
            // The shop took the write and kept another number. Both numbers,
            // and the proposal stays open: closing it would have been
            // `alpha.48`'s answer, and it closed on a number nobody approved.
            'stock_mirrored_different' => sprintf(
                /* translators: 1: the approved stock, 2: what the shop holds */
                __('موجودی تأییدشده %1$s بود و ووکامرس %2$s را نگه داشت، پس تأیید ثبت نشد و پیشنهاد باز مانده است. عدد فروشگاه در پروندهٔ بازارگاه هم به همان %2$s اصلاح شد تا دو عدد در دو صفحه نباشد. اگر محدودیتی در ووکامرس این عدد را پایین می‌آورد، اول آن را بردارید.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) ($context['proposed_stock'] ?? '')),
                PersianDigits::toPersian((string) ($context['shop_stock'] ?? ''))
            ),
            // Written, and not confirmed. Not reported as applied, because a
            // number that could not be read back is not a number.
            'stock_unverified' => sprintf(
                /* translators: %s: the approved stock */
                __('موجودی %s روی ووکامرس نوشته شد ولی خوانده نشد، پس تأیید ثبت نشد و پیشنهاد باز مانده است. وضعیت موجودی این محصول را در ووکامرس ببینید و بعد دوباره تأیید کنید؛ تأیید دوباره چیزی را دو بار کم نمی‌کند.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) ($context['proposed_stock'] ?? ''))
            ),
            'stock_not_written' => sprintf(
                /* translators: %s: the proposed stock */
                __('بقیهٔ پیشنهاد اعمال شد، ولی موجودی %s روی ووکامرس نوشته نشد؛ پیشنهاد باز مانده است تا دوباره تأیید کنید. موجودی فعلی فروشگاه دست‌نخورده است.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) ($context['proposed_stock'] ?? ''))
            ),
            'storefront_moved' => __('یکی از فیلدهای این محصول در ووکامرس عوض شد در فاصله‌ای که این صفحه باز بود. برای اینکه ویرایش تازه پاک نشود، تأیید انجام نشد: صفحه را تازه کنید، مقدارهای تازه را ببینید و دوباره تصمیم بگیرید.', 'tecteb-marketplace-core'),
            // Three sentences for three things that used to be silent. The
            // first two say what state the product is in NOW, because that is
            // the only thing a manager can act on: «انجام نشد» without it
            // sends somebody to look at the shop and guess.
            'sync_failed' => ((string) ($context['applied'] ?? '')) === 'yes'
                ? sprintf(
                    /* translators: %s: the reason the storefront write refused */
                    __('مقدارها در پروندهٔ بازارگاه ذخیره شد، ولی روی ووکامرس اعمال نشد (دلیل: %s). چیزی روی فروشگاه عوض نشده است؛ پس از رفع اشکال یک بار دیگر ذخیره کنید.', 'tecteb-marketplace-core'),
                    (string) ($context['reason'] ?? '')
                )
                : (((string) ($context['restored'] ?? '')) === 'no'
                    ? sprintf(
                        /* translators: 1: the reason the storefront write refused, 2: what could not be put back */
                        __('همگام‌سازی با ووکامرس انجام نشد (دلیل: %1$s) و برگرداندن %2$s به حالت قبل هم انجام نشد. یعنی ممکن است این محصول بخشی از مقدارهای تازه — یا وضعیت «منتشرشده» — را داشته باشد در حالی که فروشگاه آن را ندارد. لطفاً همین محصول را در فهرست محصول‌ها بررسی کنید.', 'tecteb-marketplace-core'),
                        (string) ($context['reason'] ?? ''),
                        $parts($context)
                    )
                    : sprintf(
                        /* translators: %s: the reason the storefront write refused */
                        __('تصمیم شما اعمال نشد: همگام‌سازی با ووکامرس انجام نشد (دلیل: %s). وضعیت محصول به همان حالت قبل برگشت، پس چیزی نیمه‌کاره نمانده و می‌توانید پس از رفع اشکال دوباره تصمیم بگیرید.', 'tecteb-marketplace-core'),
                        (string) ($context['reason'] ?? '')
                    )),
            'baseline_not_recorded' => __('تصمیم شما اعمال شد، ولی «مبنای توافق» این محصول ثبت نشد. چیزی پاک نشده و مبنای غلطی هم ثبت نشده است؛ فقط ممکن است در ویرایش بعدی فروشنده، همان پرسش‌های قبلی دوباره پرسیده شوند. یک تأیید یا یک تعیین تکلیفِ فیلد، آن را ثبت می‌کند.', 'tecteb-marketplace-core'),
            'decision_not_recorded' => __('تصمیم شما اعمال شد، ولی متن آن در سابقهٔ محصول ثبت نشد — پس فروشنده فقط برچسب وضعیت را می‌بیند و جملهٔ شما را نمی‌بیند. متن را با یک تصمیم تازه یا از طریق تیکت به او برسانید.', 'tecteb-marketplace-core'),
            // The revision's own writes. «انجام نشد» is not enough here: the
            // manager needs to know that the product is the one the shopper was
            // already looking at, and that pressing again is safe.
            'revision_write_failed' => ((string) ($context['restored'] ?? '')) === 'yes'
                ? sprintf(
                    /* translators: %s: which part of the proposal did not save */
                    __('نسخهٔ پیشنهادی اعمال نشد: ذخیرهٔ %s انجام نشد. محصول به حالت قبل برگشت و نسخهٔ پیشنهادی هم در انتظار مانده است، پس پس از رفع اشکال می‌توانید دوباره «تأیید تغییر» را بزنید.', 'tecteb-marketplace-core'),
                    match ((string) ($context['part'] ?? '')) {
                        'specs' => __('مشخصات', 'tecteb-marketplace-core'),
                        'images' => __('تصویرها', 'tecteb-marketplace-core'),
                        default => __('مقدارهای محصول', 'tecteb-marketplace-core'),
                    }
                )
                : sprintf(
                    /* translators: 1: which part did not save, 2: what could not be put back */
                    __('نسخهٔ پیشنهادی اعمال نشد: ذخیرهٔ %1$s انجام نشد — و برگرداندن %2$s به حالت قبل هم انجام نشد. یعنی این محصول ممکن است بخشی از مقدارهای تازه را داشته باشد؛ نسخهٔ پیشنهادی در انتظار مانده و لازم است همین محصول را بررسی کنید.', 'tecteb-marketplace-core'),
                    match ((string) ($context['part'] ?? '')) {
                        'specs' => __('مشخصات', 'tecteb-marketplace-core'),
                        'images' => __('تصویرها', 'tecteb-marketplace-core'),
                        default => __('مقدارهای محصول', 'tecteb-marketplace-core'),
                    },
                    $parts($context)
                ),
            'revision_not_restored' => sprintf(
                /* translators: 1: why the proposal was refused, 2: what could not be put back */
                __('نسخهٔ پیشنهادی پذیرفته نشد (دلیل: %1$s) — و برگرداندن %2$s به حالت قبل انجام نشد. یعنی این محصول ممکن است بخشی از مقدارهای پیشنهادی را داشته باشد؛ نسخهٔ پیشنهادی در انتظار مانده و لازم است همین محصول را بررسی کنید.', 'tecteb-marketplace-core'),
                (string) ($context['reason'] ?? ''),
                $parts($context)
            ),
            // A forecast, and it says so. «۳۶ مورد انجام می‌شود» would be a
            // promise this page cannot keep, because another member of the
            // same store can save one of these rows in the meantime.
            'bulk_previewed' => (int) ($context['failed'] ?? 0) === 0
                ? sprintf(
                    /* translators: %s: how many of the selected products would go through */
                    __('هنوز چیزی انجام نشده. با این اقدام، پیش‌بینی می‌شود هر %s مورد انجام شود.', 'tecteb-marketplace-core'),
                    $fa((string) ($context['ok'] ?? 0))
                )
                : sprintf(
                    /* translators: 1: how many would go through, 2: how many would be refused */
                    __('هنوز چیزی انجام نشده. پیش‌بینی: %1$s مورد انجام می‌شود و %2$s مورد انجام نمی‌شود. دلیل هرکدام در ستون «توضیح» است.', 'tecteb-marketplace-core'),
                    $fa((string) ($context['ok'] ?? 0)),
                    $fa((string) ($context['failed'] ?? 0))
                ),
            'nothing_selected' => __('هیچ موردی انتخاب نشده بود.', 'tecteb-marketplace-core'),
            'unknown_bulk_action' => __('اقدام گروهی انتخاب نشده بود.', 'tecteb-marketplace-core'),
            'bulk_too_large' => sprintf(
                /* translators: 1: how many were selected, 2: the limit */
                __('%1$s مورد انتخاب شده و این بیش از سقف %2$s موردِ یک درخواست است. کمتر انتخاب کنید؛ نیمه‌کاره‌ماندن وسط کار بدتر از رد کردنِ درخواست است.', 'tecteb-marketplace-core'),
                $fa((string) ($context['selected'] ?? 0)),
                $fa((string) ($context['limit'] ?? 0))
            ),
            // The one message whose job is to stop somebody losing work.
            // A form with no stamp, or one from a build that stamped
            // differently. It is refused rather than written, and the sentence
            // has to carry the whole answer: nothing was lost, nothing was
            // overwritten, and pressing save once more is all that is needed.
            'revision_missing' => __('این فرم نشانهٔ نسخه ندارد — احتمالاً از نسخهٔ قبلی افزونه در مرورگر شما باز مانده است. برای اینکه کار کسی پاک نشود، این ذخیره انجام نشد. مقدارهایی که نوشته‌اید همین‌جا مانده‌اند و فرم با نسخهٔ فعلی محصول تازه شده است: یک بار تفاوت‌های زیر را ببینید و دوباره ذخیره کنید.', 'tecteb-marketplace-core'),
            'stale_revision' => __('این محصول از وقتی این صفحه باز شده تغییر کرده است — احتمالاً یکی دیگر از فروشگاه شما ذخیره‌اش کرده. برای اینکه کار او پاک نشود، این ذخیره انجام نشد. مقدارهای شما همین‌جا مانده‌اند: صفحه را در یک تب دیگر باز کنید، تفاوت را ببینید و بعد تصمیم بگیرید.', 'tecteb-marketplace-core'),
            'product_reviewed' => __('تصمیم شما ثبت شد.', 'tecteb-marketplace-core'),
            // Preparation and correction — the manager's own two verbs on the
            // review screen. Each one says what it did NOT do as well, because
            // «آماده شد» next to a publish button is a sentence somebody will
            // read as «منتشر شد».
            'prepared' => __('محصول در ووکامرس به‌صورت پیش‌نویس ساخته شد. در فروشگاه دیده نمی‌شود و قابل خرید نیست؛ حالا می‌توانید همان‌جا ویرایش و سئویش را تنظیم کنید.', 'tecteb-marketplace-core'),
            'already_published' => __('این محصول منتشر شده است و صفحه‌اش در ووکامرس وجود دارد؛ آماده‌سازی لازم نیست.', 'tecteb-marketplace-core'),
            'prepare_would_publish' => __('آماده‌سازی انجام نشد: محصول در ووکامرس منتشر می‌شد. برای اینکه چیزی ناخواسته روی سایت نرود، تغییر برگردانده شد.', 'tecteb-marketplace-core'),
            'product_corrected' => __('اصلاح شما روی پروندهٔ بازارگاه و روی ووکامرس ثبت شد.', 'tecteb-marketplace-core'),
            'nothing_changed' => __('چیزی تغییر نکرده بود، پس چیزی نوشته نشد.', 'tecteb-marketplace-core'),
            'title_required' => __('عنوان محصول نمی‌تواند خالی بماند.', 'tecteb-marketplace-core'),
            'proposal_accepted' => __('خواستهٔ فروشنده روی ووکامرس نشست و این فیلد دوباره در اختیار بازارگاه است.', 'tecteb-marketplace-core'),
            'storefront_kept' => __('نسخهٔ ووکامرس ماند. بازارگاه دیگر روی این فیلد چیزی نمی‌نویسد و پروندهٔ محصول هم با همین مقدار هماهنگ شد.', 'tecteb-marketplace-core'),
            'storefront_kept_all' => sprintf(
                /* translators: %s: how many fields were settled */
                __('برای %s فیلد، نسخهٔ ووکامرس ماند و پروندهٔ محصول با آن هماهنگ شد.', 'tecteb-marketplace-core'),
                $fa($context['settled'] ?? 0)
            ),
            'proposal_accepted_all' => sprintf(
                /* translators: %s: how many fields were settled */
                __('برای %s فیلد، مقدار بازارگاه روی ووکامرس نشست و آن فیلدها دوباره در اختیار بازارگاه‌اند.', 'tecteb-marketplace-core'),
                $fa($context['settled'] ?? 0)
            ),
            'nothing_unsettled' => __('فیلد بدون تکلیفی روی این محصول نبود.', 'tecteb-marketplace-core'),
            'unknown_decision' => __('این تصمیم شناخته نشد.', 'tecteb-marketplace-core'),
            'revision_approved' => __('نسخه پیشنهادی تأیید و روی محصول اعمال شد.', 'tecteb-marketplace-core'),
            'revision_rejected' => __('نسخه پیشنهادی رد شد. نسخه منتشرشده همچنان روی سایت است.', 'tecteb-marketplace-core'),
            'direct_publish_granted' => __('مجوز انتشار مستقیم برای این فروشنده صادر شد.', 'tecteb-marketplace-core'),
            'direct_publish_revoked' => __('مجوز انتشار مستقیم این فروشنده برداشته شد؛ محصول‌های بعدی از مسیر بررسی می‌گذرند.', 'tecteb-marketplace-core'),

            'incomplete_product' => $fields($context) !== ''
                ? sprintf(__('این فیلدها هنوز کامل نیستند: %s', 'tecteb-marketplace-core'), $fields($context))
                : __('چند فیلد اجباری هنوز کامل نیست.', 'tecteb-marketplace-core'),
            'missing_image' => __('دست‌کم یک تصویر لازم است؛ تصویر اصلی روی کارت محصول نشان داده می‌شود.', 'tecteb-marketplace-core'),
            'missing_specs' => ($context['fields'] ?? '') !== ''
                ? sprintf(__('این مشخصه‌های دسته اجباری‌اند و خالی‌اند: %s', 'tecteb-marketplace-core'), (string) $context['fields'])
                : __('مشخصه‌های اجباری این دسته هنوز پر نشده‌اند.', 'tecteb-marketplace-core'),
            'invalid_specs' => ($context['fields'] ?? '') !== ''
                ? sprintf(__('مقدار این مشخصه‌ها با نوع فیلد نمی‌خواند: %s', 'tecteb-marketplace-core'), (string) $context['fields'])
                : __('مقدار یکی از مشخصه‌ها با نوع فیلد نمی‌خواند.', 'tecteb-marketplace-core'),
            'bad_type' => __('نوع محصول باید ساده یا متغیر باشد. محصول خارجی و گروهی در این نسخه پشتیبانی نمی‌شود.', 'tecteb-marketplace-core'),
            'bad_price' => __('قیمت نمی‌تواند منفی باشد.', 'tecteb-marketplace-core'),
            'bad_sale_price' => __('قیمت تخفیف‌خورده نباید از قیمت اصلی بیشتر یا منفی باشد.', 'tecteb-marketplace-core'),
            'bad_sale_window' => __('تاریخ پایان تخفیف نباید پیش از تاریخ شروع باشد.', 'tecteb-marketplace-core'),
            'bad_stock' => __('موجودی نمی‌تواند منفی باشد. موجودی صفر یعنی «ناموجود» و فروش را متوقف می‌کند.', 'tecteb-marketplace-core'),
            'bad_quantity' => __('حداقل خرید دست‌کم ۱ است و حداکثر خرید نباید از آن کمتر باشد.', 'tecteb-marketplace-core'),
            // Named refusals, with the numbers in them. «مقدار خرید نامعتبر
            // است» is true of four different mistakes, and a vendor reading
            // it has to check four fields. Nothing is written before these
            // are returned, so no stored value is damaged by the refusal.
            'bad_quantity_max_below_min' => isset($context['min'], $context['max'])
                ? sprintf(
                    /* translators: 1: maximum purchase entered, 2: minimum purchase entered */
                    __('حداکثر خرید (%1$s) از حداقل خرید (%2$s) کمتر است. برای «بدون سقف» کادر حداکثر را خالی بگذارید؛ ۰ پذیرفته نمی‌شود. هیچ مقداری ذخیره نشد.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $context['max']),
                    PersianDigits::toPersian((string) $context['min'])
                )
                : __('حداکثر خرید از حداقل خرید کمتر است. برای «بدون سقف» کادر حداکثر را خالی بگذارید. هیچ مقداری ذخیره نشد.', 'tecteb-marketplace-core'),
            'bad_quantity_step' => __('گام تعداد دست‌کم ۱ است. برای «هر تعدادی» عدد ۱ را بگذارید یا کادر را خالی بگذارید. هیچ مقداری ذخیره نشد.', 'tecteb-marketplace-core'),
            // The window with no multiple in it. Refused rather than widened,
            // and the message names two maxima that WOULD work — a refusal
            // that cannot be acted on is a refusal that gets pressed through.
            //
            // In a METHOD, not a ternary arm: PHPCompatibility reads two
            // ternary arms of one `match` as a left-associative chain, which
            // PHP 8 removed, and parenthesising the arms does not quiet it
            // because the arms are one expression to the sniff. A named
            // method says the same thing with no `?` at all.
            'bad_quantity_unreachable' => self::unreachableQuantity($context),
            'sku_taken' => ($context['sku'] ?? '') !== ''
                ? sprintf(__('کد SKU «%s» روی محصول دیگری از همین فروشگاه ثبت شده است.', 'tecteb-marketplace-core'), (string) $context['sku'])
                : __('این کد SKU روی محصول دیگری از همین فروشگاه ثبت شده است.', 'tecteb-marketplace-core'),
            'in_review' => __('این محصول در صف بررسی مدیر است و تا تعیین تکلیف ویرایش نمی‌شود. موجودی همچنان قابل تغییر است.', 'tecteb-marketplace-core'),
            'product_archived' => __('این محصول بایگانی است. برای ویرایش، ابتدا آن را به پیش‌نویس برگردانید.', 'tecteb-marketplace-core'),
            'not_submittable' => __('این محصول در وضعیت فعلی ارسال‌شدنی نیست.', 'tecteb-marketplace-core'),
            'already_decided' => __('برای این نسخه پیشنهادی قبلاً تصمیم گرفته شده است.', 'tecteb-marketplace-core'),
            'invalid_transition' => __('این تغییر وضعیت مجاز نیست.', 'tecteb-marketplace-core'),
            'note_required' => __('برای اصلاح و رد، نوشتن دلیل اجباری است.', 'tecteb-marketplace-core'),

            'image_uploaded' => __('تصویر افزوده شد. برای ثبت روی محصول، فرم را ذخیره کنید.', 'tecteb-marketplace-core'),
            'image_too_large' => self::uploadRefusal(
                sprintf(
                    /* translators: %s: the largest image this installation accepts, in megabytes */
                    __('حجم این تصویر از %s مگابایت بیشتر است.', 'tecteb-marketplace-core'),
                    $fa(self::maxMb($context))
                ),
                __('آن را فشرده یا کوچک‌تر کنید و دوباره انتخاب کنید.', 'tecteb-marketplace-core'),
                $context
            ),
            'image_mime_not_allowed' => self::uploadRefusal(
                __('این فایل تصویر JPEG، PNG یا WebP نیست.', 'tecteb-marketplace-core'),
                // The type is read from the BYTES, so «ولی پسوندش jpg است» is
                // the commonest next thought and the answer belongs here.
                __('نوع فایل از محتوای آن خوانده می‌شود، نه از پسوندش؛ تصویر را با یکی از این سه قالب ذخیره کنید.', 'tecteb-marketplace-core'),
                $context
            ),
            'transfer_failed' => self::uploadRefusal(
                __('بارگذاری تصویر ناتمام ماند.', 'tecteb-marketplace-core'),
                __('معمولاً قطعی لحظه‌ای اینترنت است؛ همین فایل را دوباره انتخاب کنید.', 'tecteb-marketplace-core'),
                $context
            ),
            'empty_file' => self::uploadRefusal(
                __('فایل انتخاب‌شده خالی است.', 'tecteb-marketplace-core'),
                __('فایل دیگری انتخاب کنید.', 'tecteb-marketplace-core'),
                $context
            ),
            'no_file' => self::uploadRefusal(
                __('فایلی به سرور نرسید.', 'tecteb-marketplace-core'),
                __('دوباره از همین مرحله فایل را انتخاب کنید.', 'tecteb-marketplace-core'),
                $context
            ),

            // ---- variable products ---------------------------------------
            'attribute_saved' => __('ویژگی ذخیره شد. حالا برای هر ترکیب، قیمت و موجودی تعیین کنید.', 'tecteb-marketplace-core'),
            'attribute_deleted' => __('ویژگی و ترکیب‌های وابسته‌اش حذف شدند.', 'tecteb-marketplace-core'),
            'variation_added' => __('ترکیب تازه اضافه شد.', 'tecteb-marketplace-core'),
            'variation_saved' => __('ترکیب ذخیره شد.', 'tecteb-marketplace-core'),
            'variation_deleted' => __('ترکیب حذف شد.', 'tecteb-marketplace-core'),
            'not_variable' => __('این کار فقط برای محصول «متغیر» معنا دارد.', 'tecteb-marketplace-core'),
            'incomplete_attribute' => __('کلید و عنوان ویژگی هر دو لازم‌اند.', 'tecteb-marketplace-core'),
            'attribute_needs_options' => __('ویژگی بدون گزینه ساخته نمی‌شود؛ دست‌کم یک گزینه بنویسید.', 'tecteb-marketplace-core'),
            'attributes_first' => __('اول ویژگی‌ها (مثل اندازه یا رنگ) را تعریف کنید، بعد ترکیب‌ها را.', 'tecteb-marketplace-core'),
            'incomplete_combination' => ($context['fields'] ?? '') !== ''
                ? sprintf(__('برای این ویژگی‌ها گزینه‌ای انتخاب نشده است: %s', 'tecteb-marketplace-core'), (string) $context['fields'])
                : __('برای همه ویژگی‌ها باید یک گزینه انتخاب شود.', 'tecteb-marketplace-core'),
            'unknown_option' => sprintf(
                __('گزینه‌ای که انتخاب شده در فهرست ویژگی «%s» نیست.', 'tecteb-marketplace-core'),
                (string) ($context['attribute'] ?? '')
            ),
            'unknown_attribute' => __('این ویژگی برای این محصول تعریف نشده است.', 'tecteb-marketplace-core'),
            'too_many_attributes' => sprintf(__('سقف ویژگی‌های یک محصول %s است.', 'tecteb-marketplace-core'), $fa($context['limit'] ?? '')),
            'too_many_variations' => sprintf(__('سقف ترکیب‌های یک محصول %s است.', 'tecteb-marketplace-core'), $fa($context['limit'] ?? '')),
            'variable_needs_attributes' => __('محصول متغیر بدون ویژگی آمادهٔ فروش نیست: دست‌کم یک ویژگی با گزینه‌هایش تعریف کنید.', 'tecteb-marketplace-core'),
            'variable_needs_variations' => __('محصول متغیر بدون ترکیب آمادهٔ فروش نیست: دست‌کم یک ترکیب با قیمت بسازید.', 'tecteb-marketplace-core'),
            'variable_needs_priced_variation' => __('هیچ ترکیب فعالی با قیمت معتبر وجود ندارد.', 'tecteb-marketplace-core'),
            'variation_without_price' => sprintf(
                __('%s ترکیب فعال هنوز قیمت ندارد. تا وقتی قیمت هر ترکیب مشخص نشود، این محصول فروختنی نیست.', 'tecteb-marketplace-core'),
                $fa($context['count'] ?? '')
            ),

            // ---- category templates --------------------------------------
            'template_created' => __('الگوی مشخصات ساخته شد. حالا فیلدهای این دسته را اضافه کنید.', 'tecteb-marketplace-core'),
            'template_saved' => __('الگو ذخیره شد.', 'tecteb-marketplace-core'),
            'template_exists' => __('برای این دسته قبلاً الگویی ساخته شده است.', 'tecteb-marketplace-core'),
            'incomplete_template' => __('کلید دسته و عنوان الگو هر دو لازم‌اند.', 'tecteb-marketplace-core'),
            'field_added' => sprintf(
                __('فیلد اضافه شد. نسخه الگو به %s رسید؛ محصول‌های قبلی با نسخه خودشان معتبر می‌مانند.', 'tecteb-marketplace-core'),
                $fa($context['schema_version'] ?? '')
            ),
            'field_saved' => ($context['required'] ?? false)
                ? sprintf(
                    __('فیلد ذخیره شد و اکنون اجباری است. نسخه الگو %s شد؛ محصول‌های قبلی باطل نمی‌شوند و این قاعده از ویرایش بعدی آن‌ها خواسته می‌شود.', 'tecteb-marketplace-core'),
                    $fa($context['schema_version'] ?? '')
                )
                : sprintf(__('فیلد ذخیره شد. نسخه الگو %s شد.', 'tecteb-marketplace-core'), $fa($context['schema_version'] ?? '')),
            'field_deprecated' => __('این فیلد دیگر از فروشنده پرسیده نمی‌شود. پاسخ‌های ثبت‌شده حذف نشدند.', 'tecteb-marketplace-core'),
            'field_restored' => __('این فیلد دوباره پرسیده می‌شود.', 'tecteb-marketplace-core'),
            'incomplete_field' => __('کلید و برچسب فیلد هر دو لازم‌اند.', 'tecteb-marketplace-core'),
            'bad_field_type' => __('نوع فیلد معتبر نیست.', 'tecteb-marketplace-core'),
            'field_key_taken' => __('این کلید در همین الگو استفاده شده است. کلید هرگز تغییر نمی‌کند تا پاسخ‌های قبلی گم نشوند.', 'tecteb-marketplace-core'),
            'choice_needs_options' => __('فیلد «انتخاب از فهرست» بدون گزینه ساخته نمی‌شود.', 'tecteb-marketplace-core'),
            'too_many_fields' => sprintf(__('سقف فیلدهای یک الگو %s است.', 'tecteb-marketplace-core'), $fa($context['limit'] ?? '')),

            'seo_saved' => __('سئوی محصول ذخیره شد و روی صفحهٔ عمومی اعمال شد.', 'tecteb-marketplace-core'),
            'projected' => __('محصول در فروشگاه به‌روزرسانی شد.', 'tecteb-marketplace-core'),
            'withdrawn' => __('محصول از ویترین فروشگاه برداشته شد؛ حذف نشد.', 'tecteb-marketplace-core'),
            'woocommerce_missing' => __('WooCommerce فعال نیست، پس این محصول صفحهٔ عمومی ندارد. تصمیم بازارگاه ثبت شده و با فعال‌شدن WooCommerce نگاشت انجام می‌شود.', 'tecteb-marketplace-core'),

            // ---- CSV ------------------------------------------------------
            'csv_exported' => sprintf(__('%s ردیف در فایل CSV نوشته شد.', 'tecteb-marketplace-core'), $fa($context['rows'] ?? 0)),
            'csv_previewed' => __('این پیش‌نمایش است و هنوز چیزی ذخیره نشده. ردیف‌ها را ببینید و سپس «اعمال» را بزنید.', 'tecteb-marketplace-core'),
            'csv_imported' => sprintf(
                __('ورود CSV انجام شد: %1$s ساخته، %2$s به‌روزرسانی، %3$s رد شد.', 'tecteb-marketplace-core'),
                $fa($context['created'] ?? 0),
                $fa($context['updated'] ?? 0),
                $fa($context['skipped'] ?? 0)
            ),
            'csv_empty' => __('فایلی خوانده نشد یا خالی بود.', 'tecteb-marketplace-core'),
            'csv_missing_columns' => __('سطر عنوان فایل باید دست‌کم ستون title داشته باشد.', 'tecteb-marketplace-core'),
            'csv_too_many_rows' => __('تعداد ردیف‌ها از سقف یک بار ورود بیشتر است. فایل را تکه‌تکه کنید.', 'tecteb-marketplace-core'),
            'csv_row_ok' => __('آماده اعمال', 'tecteb-marketplace-core'),
            'csv_row_needs_title' => __('این ردیف نه SKU شناخته‌شده دارد و نه عنوان.', 'tecteb-marketplace-core'),
            default => null,
        };
    }

    /** The bulk verbs by the name the vendor picked them by. */
    public static function bulkAction(string $action): string
    {
        return match ($action) {
            ManageProducts::ACTION_SUBMIT => __('ارسال برای بررسی', 'tecteb-marketplace-core'),
            ManageProducts::ACTION_ARCHIVE => __('بایگانی', 'tecteb-marketplace-core'),
            ManageProducts::ACTION_RESTORE => __('بازگشت به پیش‌نویس', 'tecteb-marketplace-core'),
            default => __('اقدام گروهی', 'tecteb-marketplace-core'),
        };
    }

    public static function csvAction(string $action): string
    {
        return match ($action) {
            'create' => __('ساخت محصول تازه', 'tecteb-marketplace-core'),
            'update' => __('به‌روزرسانی', 'tecteb-marketplace-core'),
            'revision' => __('نسخه پیشنهادی برای محصول منتشرشده', 'tecteb-marketplace-core'),
            default => __('رد شد', 'tecteb-marketplace-core'),
        };
    }

    /** @return list<string> codes this module considers a success */
    public static function successCodes(): array
    {
        return [
            'product_created', 'product_created_replayed', 'product_saved', 'inventory_saved', 'product_submitted',
            'product_published', 'revision_requested', 'revision_unchanged',
            'stock_revision_requested', 'stock_revision_unchanged',
            'brand_requested', 'brand_approved', 'brand_rejected',
            'product_archived_ok', 'product_restored',
            'product_reviewed', 'revision_approved', 'revision_rejected',
            'direct_publish_granted', 'direct_publish_revoked',
            'template_created', 'template_saved', 'field_added', 'field_saved',
            'field_deprecated', 'field_restored',
            'csv_exported', 'csv_previewed', 'csv_imported', 'csv_row_ok', 'image_uploaded',
            // A preview is not a warning: nothing happened, and painting the
            // page red would make «هنوز چیزی انجام نشده» look like a failure.
            'bulk_previewed',
            'attribute_saved', 'attribute_deleted', 'variation_added', 'variation_saved', 'variation_deleted',
            'seo_saved', 'projected', 'withdrawn',
            'prepared', 'product_corrected', 'nothing_changed',
            'proposal_accepted', 'storefront_kept',
            'storefront_kept_all', 'proposal_accepted_all', 'nothing_unsettled',
            // A batch that RAN is a success, even when some rows were refused:
            // the refusals are in the message, and painting the whole thing red
            // would hide the thirty-six that went.
            'bulk_done',
        ];
    }

    /**
     * What an image refusal says, and why it says it in three parts.
     *
     * A multipart POST cannot be replayed: the bytes are gone the moment PHP
     * refuses them, and no browser hands them back. So «retry» here is not a
     * resend — it is the text save standing on its own, the vendor landing back
     * on the step that holds the file chooser, and the reason named clearly
     * enough that the second attempt is not the same file again. A message that
     * said only «بارگذاری نشد» would buy a second identical failure.
     *
     * @param array<string,scalar|null> $context
     */
    /**
     * «مشخصات» and «تصویرها», named in Persian rather than printed as keys.
     *
     * The caller hands a comma list because `OperationResult` context travels
     * through a redirect as scalars (`alpha.29`: an array was silently
     * dropped), and an unknown part is passed through rather than hidden — a
     * sentence naming something nobody recognises is still better than a
     * sentence that quietly says less than it knows.
     */
    /**
     * «No allowed quantity between the two» — with the step, both ends, and
     * the smallest change to ONE field that makes the other one's stated
     * value work. Named so that a vendor can act on it; a refusal that cannot
     * be acted on is a refusal that gets pressed through — and a refusal that
     * names a value which is itself refused is worse than one that names
     * nothing, which is why the second way out is printed only when it
     * exists.
     *
     * @param array<string,mixed> $context
     */
    private static function unreachableQuantity(array $context): string
    {
        if (!isset($context['min'], $context['max'], $context['step'], $context['workable_max'])) {
            return __('حداقل، حداکثر و گام تعداد با هم ناسازگارند: هیچ تعداد مجازی بین آن دو نیست. هیچ مقداری ذخیره نشد.', 'tecteb-marketplace-core');
        }
        if (isset($context['workable_min'])) {
            return sprintf(
                /* translators: 1: step, 2: minimum, 3: maximum, 4: a maximum that works, 5: a minimum that works */
                __('با گام %1$s، بین حداقل خرید %2$s و حداکثر خرید %3$s هیچ تعداد مجازی وجود ندارد. حداکثر را %4$s بگذارید، یا حداقل را %5$s. هیچ مقداری ذخیره نشد.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) $context['step']),
                PersianDigits::toPersian((string) $context['min']),
                PersianDigits::toPersian((string) $context['max']),
                PersianDigits::toPersian((string) $context['workable_max']),
                PersianDigits::toPersian((string) $context['workable_min'])
            );
        }
        return sprintf(
            /* translators: 1: step, 2: minimum, 3: maximum, 4: a maximum that works */
            __('با گام %1$s، بین حداقل خرید %2$s و حداکثر خرید %3$s هیچ تعداد مجازی وجود ندارد. حداکثر را %4$s بگذارید. هیچ مقداری ذخیره نشد.', 'tecteb-marketplace-core'),
            PersianDigits::toPersian((string) $context['step']),
            PersianDigits::toPersian((string) $context['min']),
            PersianDigits::toPersian((string) $context['max']),
            PersianDigits::toPersian((string) $context['workable_max'])
        );
    }

    private static function parts(string $missing): string
    {
        $names = [
            'specs' => __('مشخصات', 'tecteb-marketplace-core'),
            'images' => __('تصویرها', 'tecteb-marketplace-core'),
        ];
        $out = [];
        foreach (array_filter(explode(',', $missing)) as $part) {
            $out[] = $names[$part] ?? $part;
        }
        return $out === [] ? __('بخشی از اطلاعات', 'tecteb-marketplace-core') : implode(' و ', $out);
    }

    /**
     * The three quantity rows, named the way the form names them.
     *
     * A list of meta keys is a message for a developer. «حداکثر خرید» is a
     * message for the person who typed the number — and naming WHICH limit is
     * the difference between a sentence they can act on and one they cannot.
     */
    private static function limitNames(string $keys): string
    {
        $names = [
            '_tmc_min_purchase' => __('حداقل خرید', 'tecteb-marketplace-core'),
            '_tmc_max_purchase' => __('حداکثر خرید', 'tecteb-marketplace-core'),
            '_tmc_purchase_step' => __('گام تعداد', 'tecteb-marketplace-core'),
        ];
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $keys))) as $key) {
            $out[] = $names[$key] ?? $key;
        }
        return $out === [] ? __('حدود خرید', 'tecteb-marketplace-core') : implode(' و ', $out);
    }

    private static function uploadRefusal(string $why, string $whatToDo, array $context): string
    {
        $parts = [];
        if ((int) ($context['saved'] ?? 0) === 1) {
            // Said FIRST, because it is the thing the vendor is afraid of.
            $parts[] = __('بقیهٔ فرم ذخیره شد و فقط تصویر افزوده نشد.', 'tecteb-marketplace-core');
        }
        $parts[] = $why;
        $parts[] = $whatToDo;
        return implode(' ', $parts);
    }

    /**
     * The size cap to quote. The caller knows the installation's real ceiling
     * — which may be the host's, not ours — and when it is absent (a refusal
     * repeated inside a bulk list) our own constant is the honest fallback.
     *
     * @param array<string,scalar|null> $context
     */
    private static function maxMb(array $context): int
    {
        $given = (int) ($context['max_mb'] ?? 0);
        return $given > 0 ? $given : (int) floor(ProductImagePolicy::MAX_BYTES / 1048576);
    }


    public static function isErrorNotice(string $code): bool
    {
        return !in_array($code, self::successCodes(), true);
    }
}
