<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
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
        bool $mayPublishDirectly = false
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

        $html .= '<section class="tv-card"><div class="tv-card__head">'
            . '<h2 class="tv-card__title">' . esc_html__('محصولات فروشگاه', 'tecteb-marketplace-core') . '</h2>';
        if ($mayEdit) {
            $html .= '<p class="tv-card__actions">'
                . VendorUi::button($urls->product(0), __('افزودن محصول', 'tecteb-marketplace-core'))
                . '</p>';
        }
        $html .= '</div>';

        $html .= $mayPublishDirectly
            ? '<p class="tv-hint">' . esc_html__('فروشگاه شما مجوز انتشار مستقیم دارد: محصول با «ارسال» بی‌درنگ منتشر می‌شود.', 'tecteb-marketplace-core') . '</p>'
            : '<p class="tv-hint">' . esc_html__('محصول تازه و تغییرهای حساس پس از تأیید مدیر منتشر می‌شوند. موجودی همیشه فوری اعمال می‌شود.', 'tecteb-marketplace-core') . '</p>';

        $html .= self::tabs($counts, $currentStatus, $urls, $fa);

        if ($products === []) {
            $html .= VendorUi::notice('info', $currentStatus === ''
                ? __('هنوز محصولی ثبت نکرده‌اید.', 'tecteb-marketplace-core')
                : __('در این وضعیت محصولی ندارید.', 'tecteb-marketplace-core'));
            return $html . '</section>' . self::csvCard($urls, $nonceField, $mayEdit);
        }

        $html .= '<ul class="tv-products">';
        foreach ($products as $product) {
            $html .= self::row($product, $urls, $nonceField, $fa, $mayEdit);
        }
        $html .= '</ul>';
        $html .= self::pager($page, $total, $currentStatus, $urls, $fa);
        return $html . '</section>' . self::csvCard($urls, $nonceField, $mayEdit);
    }

    /** @param array<string,int> $counts @param callable(string|int):string $fa */
    private static function tabs(array $counts, string $current, VendorUrls $urls, callable $fa): string
    {
        $tabs = ['' => __('همه', 'tecteb-marketplace-core')];
        foreach (ProductStatus::cases() as $status) {
            $tabs[$status->value] = ProductMessages::status($status);
        }
        $all = array_sum($counts);
        $html = '<nav class="tv-tabs" aria-label="' . esc_attr__('وضعیت محصول', 'tecteb-marketplace-core') . '"><ul>';
        foreach ($tabs as $value => $label) {
            $n = $value === '' ? $all : ($counts[$value] ?? 0);
            $isCurrent = $value === $current;
            $html .= '<li><a class="tv-tab' . ($isCurrent ? ' is-current' : '') . '"'
                . ($isCurrent ? ' aria-current="page"' : '')
                . ' href="' . esc_url($urls->productsInStatus((string) $value)) . '">'
                . esc_html($label) . ' <span class="tv-tab__count">' . esc_html($fa($n)) . '</span></a></li>';
        }
        return $html . '</ul></nav>';
    }

    /** @param callable(string|int):string $fa */
    private static function row(Product $product, VendorUrls $urls, string $nonce, callable $fa, bool $mayEdit): string
    {
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

        $html = '<li class="tv-product"><div class="tv-product__head">'
            . '<strong class="tv-product__title">' . esc_html($d->title !== '' ? $d->title : __('بدون عنوان', 'tecteb-marketplace-core')) . '</strong> '
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
        return $html . '</p></li>';
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

    private static function csvCard(VendorUrls $urls, string $nonce, bool $mayEdit): string
    {
        $html = '<section class="tv-card"><h2 class="tv-card__title">' . esc_html__('ورود و خروج CSV', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tv-hint">' . esc_html__('خروجی فقط محصول‌های همین فروشگاه را دارد. ستون شناسه و وضعیت فقط برای خواندن است و هنگام ورود نادیده گرفته می‌شود.', 'tecteb-marketplace-core') . '</p>'
            . '<form method="post" action="' . esc_url($urls->products()) . '" class="tv-inline">'
            . $nonce
            . '<input type="hidden" name="tmc_vendor_action" value="export_products">'
            . VendorUi::submit(__('دریافت فایل CSV', 'tecteb-marketplace-core'), 'secondary')
            . '</form>';
        if (!$mayEdit) {
            return $html . '</section>';
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
            . '</form></section>';
    }
}
