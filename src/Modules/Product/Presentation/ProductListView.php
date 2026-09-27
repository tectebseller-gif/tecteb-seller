<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Product\Application\ProductCsv;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorNotice;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * The vendor's product list (UX §5.1): status tabs, server-side paging with a
 * real count, and one row per product carrying identity, price, stock and
 * status — plus the reason, when the manager asked for a change.
 *
 * Written as one list of cards rather than a table: at 400px a table either
 * scrolls sideways or squeezes a Persian title into a column two words wide,
 * and the same markup has to work on both.
 */
final class ProductListView
{
    public const PER_PAGE = 20;

    /**
     * @param list<Product> $products
     * @param array<string,int> $counts status value => how many
     */
    public static function render(
        array $products,
        array $counts,
        string $currentStatus,
        int $page,
        int $total,
        VendorUrls $urls,
        string $nonceField,
        ?VendorNotice $notice = null,
        bool $mayEdit = true,
        bool $mayPublishDirectly = false,
        string $search = '',
        array $thumbnails = []
    ): string {
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $html = '';
        if ($notice !== null) {
            $html .= VendorUi::notice(
                ProductMessages::isErrorNotice($notice->code) ? 'warning' : 'success',
                ProductMessages::notice($notice->code, $notice->context)
                    ?? \Tecteb\Marketplace\Modules\Vendor\Presentation\VendorMessages::notice($notice->code, $notice->context)
            );
        }

        // The page in the order somebody works in it (`alpha.34`). The owner's
        // words: «بالای صفحه: نوار جست‌وجو و دکمهٔ افزودن محصول. سپس فیلتر
        // وضعیت‌ها و اقدامات گروهی. سپس محصولات و صفحه‌بندی. پایین همهٔ این‌ها:
        // بخش جمع‌شوندهٔ ورود و خروج گروهی».
        //
        // `alpha.33` had the CSV disclosure at the top, beside «افزودن محصول»,
        // and the search below it. Two things were wrong with that and only one
        // was the order: a `<details>` at the top pushes the search field and
        // every product down the moment somebody opens it, so the tool used
        // twice a year moved the tool used every day. Last in the document is
        // the one position from which opening it can push nothing.
        $html .= '<section class="tv-card"><div class="tv-card__head">'
            . '<h2 class="tv-card__title">' . esc_html__('محصولات فروشگاه', 'tecteb-marketplace-core') . '</h2>'
            . '</div>'
            . self::topBar($search, $currentStatus, $urls, $mayEdit);

        $html .= $mayPublishDirectly
            ? '<p class="tv-hint">' . esc_html__('فروشگاه شما مجوز انتشار مستقیم دارد: محصول با «ارسال» بی‌درنگ منتشر می‌شود.', 'tecteb-marketplace-core') . '</p>'
            : '<p class="tv-hint">' . esc_html__('محصول تازه و تغییرهای حساس پس از تأیید مدیر منتشر می‌شوند. موجودی همیشه فوری اعمال می‌شود.', 'tecteb-marketplace-core') . '</p>';

        $html .= self::tabs($counts, $currentStatus, $urls, $fa);

        if ($products === []) {
            // Three different empty lists, and they are not the same page. A
            // search that found nothing wants the filter cleared; a shop with
            // no products at all wants the «new product» button; a status tab
            // with nothing in it wants the tab that has something. One shared
            // sentence would send all three readers to the wrong place.
            $html .= match (true) {
                $search !== '' => VendorUi::state(
                    'empty',
                    sprintf(__('برای «%s» چیزی پیدا نشد', 'tecteb-marketplace-core'), $search),
                    __('عبارت دیگری را امتحان کنید، یا جست‌وجو را بردارید تا همهٔ محصولات این وضعیت را ببینید.', 'tecteb-marketplace-core'),
                    [['href' => $urls->products(), 'label' => __('برداشتن جست‌وجو', 'tecteb-marketplace-core'), 'primary' => true]]
                ),
                $currentStatus === '' => VendorUi::state(
                    'empty',
                    __('هنوز محصولی ثبت نکرده‌اید', 'tecteb-marketplace-core'),
                    __('اولین محصول را در چهار گام ثبت می‌کنید: مشخصات پایه، مشخصات پزشکی، تصویر و قیمت و موجودی. تا پیش از ارسال، هر گام به‌صورت پیش‌نویس ذخیره می‌ماند.', 'tecteb-marketplace-core'),
                    $mayEdit ? [['href' => $urls->product(0), 'label' => __('ثبت اولین محصول', 'tecteb-marketplace-core'), 'primary' => true]] : []
                ),
                default => VendorUi::state(
                    'empty',
                    __('در این وضعیت محصولی ندارید', 'tecteb-marketplace-core'),
                    __('محصولات شما در وضعیت دیگری هستند. از زبانه‌های بالا وضعیت دیگری را ببینید.', 'tecteb-marketplace-core'),
                    [['href' => $urls->products(), 'label' => __('دیدن همهٔ محصولات', 'tecteb-marketplace-core')]]
                ),
            };
            // The CSV tools stay reachable with an empty list: «در حالت فهرست
            // خالی نیز ابزار CSV برای کاربران مجاز در دسترس بماند». A shop with
            // no products yet is exactly the shop most likely to want to bring a
            // file in, and `alpha.33` printed the tools above the empty state by
            // accident of position rather than by decision.
            return $html . self::csvFold($urls, $nonceField, $mayEdit) . '</section>';
        }

        if ($mayEdit) {
            $html .= self::bulkBar($urls, $nonceField);
        }
        $html .= '<ul class="tv-products">';
        foreach ($products as $product) {
            $html .= self::row($product, $urls, $nonceField, $fa, $mayEdit, $thumbnails[$product->id] ?? '');
        }
        $html .= '</ul>';
        $html .= self::pager($page, $total, $currentStatus, $urls, $fa);
        return $html . self::csvFold($urls, $nonceField, $mayEdit) . '</section>';
    }

    /**
     * Search as a GET form, so a result page is a URL: bookmarkable, shareable
     * with a colleague, and reachable with the back button.
     */
    private static function searchForm(string $search, string $status, VendorUrls $urls): string
    {
        $html = '<form method="get" action="' . esc_url($urls->products()) . '" class="tv-search" role="search">';
        // The status tab travels with the search, or searching would silently
        // throw away the filter the vendor just chose.
        if ($status !== '') {
            $html .= '<input type="hidden" name="status" value="' . esc_attr($status) . '">';
        }
        foreach (self::queryCarryOver($urls) as $name => $value) {
            $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
        return $html
            . '<div class="tv-field"><label class="tv-label" for="f-product-search">'
            . esc_html__('جست‌وجو در عنوان، برند و کد SKU', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tv-input" type="search" id="f-product-search" name="q" value="' . esc_attr($search) . '"></div>'
            . '<p class="tv-form__actions">' . VendorUi::submit(__('جست‌وجو', 'tecteb-marketplace-core'), 'secondary')
            . ($search !== '' ? ' ' . VendorUi::button($urls->productsInStatus($status), __('پاک‌کردن جست‌وجو', 'tecteb-marketplace-core'), 'secondary') : '')
            . '</p></form>';
    }

    /**
     * The query arguments the vendor area itself needs when the site has no
     * pretty permalinks — without them a GET form would drop `tmc_vendor` and
     * land the vendor on the home page.
     *
     * @return array<string,string>
     */
    private static function queryCarryOver(VendorUrls $urls): array
    {
        $query = (string) wp_parse_url($urls->products(), PHP_URL_QUERY);
        if ($query === '') {
            return [];
        }
        parse_str($query, $parsed);
        $out = [];
        foreach ($parsed as $name => $value) {
            if (is_string($name) && is_scalar($value) && $name !== 'q' && $name !== 'status') {
                $out[$name] = (string) $value;
            }
        }
        return $out;
    }

    /** @param array<string,int> $counts @param callable(string|int):string $fa */
    private static function tabs(array $counts, string $current, VendorUrls $urls, callable $fa): string
    {
        $tabs = ['' => __('همه', 'tecteb-marketplace-core')];
        foreach (ProductStatus::cases() as $status) {
            $tabs[$status->value] = ProductMessages::status($status);
        }
        $all = array_sum($counts);
        // «همه» must equal the sum of the chips beside it. It is one query
        // and one array, so the only way they can disagree is a row whose
        // status this enum does not know — and the version this replaces drew
        // no chip for such a row, so «همه ۴» sat above «پیش‌نویس ۳» and four
        // zeros with nothing to explain the missing one. That is the shape of
        // the mismatch the owner reported, and whether or not it was the
        // cause, a page that can hide a row is a page that cannot be trusted
        // to report one.
        $known = 0;
        foreach (ProductStatus::cases() as $status) {
            $known += $counts[$status->value] ?? 0;
        }
        $unknown = $all - $known;

        $html = '<nav class="tv-tabs" aria-label="' . esc_attr__('وضعیت محصول', 'tecteb-marketplace-core') . '"><ul>';
        foreach ($tabs as $value => $label) {
            $n = $value === '' ? $all : ($counts[$value] ?? 0);
            $isCurrent = $value === $current;
            $html .= '<li><a class="tv-tab' . ($isCurrent ? ' is-current' : '') . '"'
                . ($isCurrent ? ' aria-current="page"' : '')
                . ' href="' . esc_url($urls->productsInStatus((string) $value)) . '">'
                . esc_html($label) . ' <span class="tv-tab__count">' . esc_html($fa($n)) . '</span></a></li>';
        }
        if ($unknown !== 0) {
            $html .= '<li><span class="tv-tab tv-tab--warning">'
                . esc_html__('وضعیت ناشناخته', 'tecteb-marketplace-core')
                . ' <span class="tv-tab__count">' . esc_html($fa($unknown)) . '</span></span></li>';
        }
        return $html . '</ul></nav>';
    }

    /**
     * The bulk bar, and the reason the checkboxes are not inside it.
     *
     * Each product card already carries its own «بایگانی» form, and a form
     * inside a form is not valid HTML — the browser silently drops the inner
     * one, so the per-row buttons would stop working. HTML's `form` attribute
     * solves exactly this: a control anywhere in the document can belong to a
     * form by id, with no nesting at all. So the bar is one form, the
     * checkboxes live in the cards and point at it, and both kinds of button
     * keep working.
     *
     * The select has no «انجام بده» default. A bulk action is the one control
     * where an accidental Enter should do nothing.
     */
    private static function bulkBar(VendorUrls $urls, string $nonce): string
    {
        return '<form method="post" id="tmc-bulk" action="' . esc_url($urls->products()) . '" class="tv-bulk">'
            . $nonce
            . '<label class="tv-bulk__label" for="tmc-bulk-action">'
            . esc_html__('اقدام گروهی روی موارد انتخاب‌شده', 'tecteb-marketplace-core') . '</label>'
            . '<select id="tmc-bulk-action" name="bulk_action" class="tv-input">'
            . '<option value="">' . esc_html__('انتخاب کنید', 'tecteb-marketplace-core') . '</option>'
            . '<option value="submit">' . esc_html__('ارسال برای بررسی', 'tecteb-marketplace-core') . '</option>'
            . '<option value="archive">' . esc_html__('بایگانی', 'tecteb-marketplace-core') . '</option>'
            . '<option value="restore">' . esc_html__('بازگشت به پیش‌نویس', 'tecteb-marketplace-core') . '</option>'
            . '</select> '
            // Two submits, one form, distinguished by `name` — so the whole
            // thing still works with JavaScript off. The preview is the
            // primary button because «ارسال برای بررسی» on forty products has
            // no undo; running straight away stays one click away for somebody
            // who already knows what is in their selection.
            . '<button type="submit" class="tv-btn tv-btn--primary" name="tmc_vendor_action" value="preview_bulk_products">'
            . esc_html__('پیش‌نمایش نتیجه', 'tecteb-marketplace-core') . '</button> '
            . '<button type="submit" class="tv-btn tv-btn--secondary" name="tmc_vendor_action" value="bulk_products">'
            . esc_html__('اجرا روی انتخاب‌شده‌ها', 'tecteb-marketplace-core') . '</button>'
            . '<p class="tv-hint">'
            . esc_html__('پیش‌نمایش چیزی را تغییر نمی‌دهد و فقط نشان می‌دهد هر مورد انتخاب‌شده چه می‌شود. در اجرا هم هر مورد جداگانه بررسی می‌شود: اگر یکی شرایطش را نداشته باشد، بقیه انجام می‌شوند و همان یکی با دلیلش گزارش می‌شود.', 'tecteb-marketplace-core')
            . '</p></form>';
    }

    /** @param callable(string|int):string $fa */
    /**
     * One product, as a card with its own picture.
     *
     * The list used to be text only, which on a catalogue of medical devices
     * means twelve rows that read alike: a vendor scanning for the right
     * oximeter had the title and nothing else to recognise it by. The
     * thumbnail is the product's own main image — the one they uploaded — and
     * the facts moved from a four-column definition grid onto one line, so a
     * phone shows three or four products at once instead of one and a half.
     *
     * `$thumbnail` is a URL the caller resolved, empty when the product has
     * no image yet. Empty renders a labelled placeholder rather than a broken
     * frame: «no picture» is a real state of a draft, and a product with no
     * image cannot be submitted, so saying so here saves a round trip.
     */
    private static function row(
        Product $product,
        VendorUrls $urls,
        string $nonce,
        callable $fa,
        bool $mayEdit,
        string $thumbnail = ''
    ): string {
        $d = $product->details;
        $price = $d->salePriceMinor !== null && $d->salePriceMinor < $d->priceMinor
            ? sprintf(
                /* translators: 1: discounted price, 2: original price */
                __('%1$s تومان (پیش از تخفیف %2$s)', 'tecteb-marketplace-core'),
                $fa(number_format($d->salePriceMinor)),
                $fa(number_format($d->priceMinor))
            )
            : sprintf(__('%s تومان', 'tecteb-marketplace-core'), $fa(number_format($d->priceMinor)));

        $stockTone = $d->stock <= 0 ? 'error' : ($d->stock <= 3 ? 'warning' : 'success');
        $stockText = $d->stock <= 0
            ? __('ناموجود', 'tecteb-marketplace-core')
            : sprintf(__('موجودی %s', 'tecteb-marketplace-core'), $fa($d->stock));

        $checkbox = $mayEdit
            // `form="tmc-bulk"` rather than nesting: see bulkBar().
            ? '<label class="tv-product__pick"><input type="checkbox" form="tmc-bulk" name="selected[]"'
                . ' value="' . esc_attr((string) $product->id) . '">'
                . '<span class="tv-sr-only">' . esc_html(sprintf(
                    /* translators: %s: product title */
                    __('انتخاب «%s» برای اقدام گروهی', 'tecteb-marketplace-core'),
                    ProductMessages::displayTitle($d->title, $product->id)
                )) . '</span></label>'
            : '';

        $title = ProductMessages::displayTitle($d->title, $product->id);
        // alt="" on purpose: the title is right beside it in the same link
        // target, so a screen reader announcing the picture too would read
        // the product's name twice.
        $media = $thumbnail !== ''
            ? '<img class="tv-product__thumb" src="' . esc_url($thumbnail) . '" alt="" width="72" height="72" loading="lazy" decoding="async">'
            : '<span class="tv-product__thumb tv-product__thumb--empty">'
                . '<span class="tv-sr-only">' . esc_html__('بدون تصویر', 'tecteb-marketplace-core') . '</span>'
                . '<span aria-hidden="true">—</span></span>';

        $html = '<li class="tv-product">'
            . '<div class="tv-product__media">' . $checkbox . $media . '</div>'
            . '<div class="tv-product__body">'
            . '<div class="tv-product__head">'
            . '<strong class="tv-product__title">' . esc_html($title) . '</strong> '
            . VendorUi::chip(ProductMessages::statusTone($product->status), ProductMessages::status($product->status))
            . '</div>'
            . '<dl class="tv-product__facts">'
            . '<div><dt>' . esc_html__('قیمت', 'tecteb-marketplace-core') . '</dt><dd>' . esc_html($price) . '</dd></div>'
            . '<div><dt>' . esc_html__('موجودی', 'tecteb-marketplace-core') . '</dt><dd>' . VendorUi::chip($stockTone, $stockText) . '</dd></div>'
            . '<div><dt>' . esc_html__('کد SKU', 'tecteb-marketplace-core') . '</dt><dd><bdi>' . esc_html($d->sku !== '' ? $d->sku : '—') . '</bdi></dd></div>'
            . '<div><dt>' . esc_html__('دسته', 'tecteb-marketplace-core') . '</dt><dd>' . esc_html($d->categoryKey !== '' ? $d->categoryKey : '—') . '</dd></div>'
            . '</dl>';

        if ($product->reviewNote !== '' && in_array($product->status, [ProductStatus::ChangesRequested, ProductStatus::Suspended, ProductStatus::Archived], true)) {
            $html .= '<p class="tv-product__note"><strong>' . esc_html__('یادداشت مدیر:', 'tecteb-marketplace-core') . '</strong> '
                . esc_html($product->reviewNote) . '</p>';
        }

        $html .= '<p class="tv-product__actions">'
            . VendorUi::button($urls->product($product->id), __('ویرایش', 'tecteb-marketplace-core'), 'secondary');
        if ($mayEdit && $product->status !== ProductStatus::Archived) {
            $html .= self::inlineForm($urls, $nonce, 'archive_product', $product->id, __('بایگانی', 'tecteb-marketplace-core'));
        }
        if ($mayEdit && $product->status === ProductStatus::Archived) {
            $html .= self::inlineForm($urls, $nonce, 'restore_product', $product->id, __('بازگشت به پیش‌نویس', 'tecteb-marketplace-core'));
        }
        return $html . '</p></div></li>';
    }

    private static function inlineForm(VendorUrls $urls, string $nonce, string $action, int $productId, string $label): string
    {
        return '<form method="post" action="' . esc_url($urls->products()) . '" class="tv-inline">'
            . $nonce
            . '<input type="hidden" name="tmc_vendor_action" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="product_id" value="' . esc_attr((string) $productId) . '">'
            . VendorUi::submit($label, 'secondary')
            . '</form>';
    }

    /** @param callable(string|int):string $fa */
    private static function pager(int $page, int $total, string $status, VendorUrls $urls, callable $fa): string
    {
        $pages = (int) ceil($total / self::PER_PAGE);
        if ($pages <= 1) {
            return '<p class="tv-hint">' . esc_html(sprintf(__('%s محصول', 'tecteb-marketplace-core'), $fa($total))) . '</p>';
        }
        $link = static function (int $target, string $label) use ($status, $urls): string {
            return '<a class="tv-btn tv-btn--secondary" href="'
                . esc_url(add_query_arg('paged', $target, $urls->productsInStatus($status))) . '">' . esc_html($label) . '</a>';
        };
        $html = '<nav class="tv-pager" aria-label="' . esc_attr__('صفحه‌بندی محصولات', 'tecteb-marketplace-core') . '">';
        if ($page > 1) {
            $html .= $link($page - 1, __('صفحه قبل', 'tecteb-marketplace-core'));
        }
        $html .= '<span class="tv-pager__status">' . esc_html(sprintf(
            /* translators: 1: current page, 2: page count, 3: total products */
            __('صفحه %1$s از %2$s — %3$s محصول', 'tecteb-marketplace-core'),
            $fa($page),
            $fa($pages),
            $fa($total)
        )) . '</span>';
        if ($page < $pages) {
            $html .= $link($page + 1, __('صفحه بعد', 'tecteb-marketplace-core'));
        }
        return $html . '</nav>';
    }

    /**
     * The two things somebody opens this page to DO, side by side.
     *
     * «افزودن محصول» is one product; CSV is all of them. Until `alpha.33` the
     * second was a full card at the very bottom, below the list and below the
     * pager — so on a shop with eighty products the bulk tool was three screens
     * past the thing it operates on, and it was the last thing on every page
     * whether anybody wanted it or not.
     *
     * A native `<details>`, for the reason this repository has now written down
     * twice: it opens with no script, its `<summary>` is reached by Tab and
     * announced as expandable, and both forms inside it are plain POSTs. A
     * vendor with JavaScript off loses nothing at all.
     *
     * **The forms are not rewritten.** Same actions, same field names, same
     * nonce, same two-step (preview, then an explicit «اعمال») — only where
     * they sit and what is said beside them.
     */
    /**
     * The top of the page: the search field and the one button.
     *
     * These two are what somebody opening the list came for — find a product,
     * or add one — so they are the first thing on it and they are on one row.
     * Nothing here expands: the row is the same height whatever is typed in it,
     * which is the property the CSV disclosure could not have and is why that
     * moved to the bottom.
     */
    private static function topBar(string $search, string $status, VendorUrls $urls, bool $mayEdit): string
    {
        $html = '<div class="tv-tools">' . self::searchForm($search, $status, $urls);
        if ($mayEdit) {
            $html .= '<p class="tv-tools__action">'
                . VendorUi::button($urls->product(0), __('افزودن محصول', 'tecteb-marketplace-core'))
                . '</p>';
        }
        return $html . '</div>';
    }

    private static function csvFold(VendorUrls $urls, string $nonce, bool $mayEdit): string
    {
        $html = '<details class="tv-bulk-csv tv-bulk-csv--foot"><summary class="tv-bulk-csv__summary">'
            . esc_html__('ورود و خروج گروهی محصولات', 'tecteb-marketplace-core')
            . '</summary><div class="tv-bulk-csv__body">'
            // Export first, and said as what it is FOR: the file that comes out
            // is the file that goes back in, so the vendor never builds a
            // header row by hand.
            . '<p>' . esc_html__('خروجی فقط محصول‌های همین فروشگاه را دارد و همان قالب ورود است: بگیرید، ستون‌ها را ویرایش کنید و همان فایل را برگردانید.', 'tecteb-marketplace-core') . '</p>'
            . '<form method="post" action="' . esc_url($urls->products()) . '" class="tv-inline">'
            . $nonce
            . '<input type="hidden" name="tmc_vendor_action" value="export_products">'
            . VendorUi::submit(__('دریافت فایل CSV', 'tecteb-marketplace-core'), 'secondary')
            . '</form>'
            . self::csvColumnGuide();
        if (!$mayEdit) {
            // Read-only staff may take the file and may not bring one back —
            // the same rule the service enforces, said on the page instead of
            // being discovered by a refusal.
            return $html . '<p class="tv-hint">'
                . esc_html__('ورود فایل به دسترسی ویرایش محصول نیاز دارد.', 'tecteb-marketplace-core')
                . '</p></div></details>';
        }
        return $html
            . '<form method="post" action="' . esc_url($urls->products()) . '" enctype="multipart/form-data" class="tv-form">'
            . $nonce
            . '<input type="hidden" name="tmc_vendor_action" value="import_products">'
            . '<div class="tv-field"><label class="tv-label" for="f-products-csv">'
            . esc_html__('فایل CSV', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tv-input" type="file" id="f-products-csv" name="products_csv" accept=".csv,text/csv">'
            . '<p class="tv-hint">' . esc_html__('اول پیش‌نمایش می‌بینید؛ تا وقتی «اعمال» را نزنید چیزی ذخیره نمی‌شود.', 'tecteb-marketplace-core') . '</p></div>'
            . VendorUi::submit(__('پیش‌نمایش ورود', 'tecteb-marketplace-core'), 'secondary')
            . '</form></div></details>';
    }

    /**
     * Which columns an edit actually reaches — read off `ProductCsv` itself.
     *
     * Built from the constants rather than typed out, so the list cannot drift
     * from the file the exporter writes. `READ_ONLY_COLUMNS` is named too, and
     * named as read-only: a vendor who changes `id` and sees nothing happen has
     * been told nothing, and a vendor who changes `status` expecting to publish
     * has been misled.
     */
    private static function csvColumnGuide(): string
    {
        $editable = [];
        foreach (ProductCsv::COLUMNS as $column) {
            $editable[] = ProductMessages::csvColumn($column);
        }
        $readOnly = [];
        foreach (ProductCsv::READ_ONLY_COLUMNS as $column) {
            $readOnly[] = ProductMessages::csvColumn($column);
        }
        return '<p class="tv-hint"><strong>'
            . esc_html__('ستون‌های قابل ویرایش:', 'tecteb-marketplace-core') . '</strong> '
            . esc_html(implode('، ', $editable))
            . '</p><p class="tv-hint"><strong>'
            . esc_html__('فقط خواندنی:', 'tecteb-marketplace-core') . '</strong> '
            . esc_html(implode('، ', $readOnly)) . ' — '
            . esc_html__('هنگام ورود نادیده گرفته می‌شوند؛ ورود فایل هیچ محصولی را منتشر نمی‌کند.', 'tecteb-marketplace-core')
            . '</p><p class="tv-hint">'
            . esc_html(sprintf(
                /* translators: 1: the spec column prefix, 2: how many rows one upload may carry */
                __('ستون‌هایی که با «%1$s» شروع می‌شوند مشخصات پزشکی همان دسته‌اند. هر فایل تا %2$s ردیف.', 'tecteb-marketplace-core'),
                ProductCsv::SPEC_PREFIX,
                PersianDigits::toPersian((string) ProductCsv::MAX_ROWS)
            ))
            . '</p>';
    }
}
