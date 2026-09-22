<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation;

use Tecteb\Marketplace\Modules\Product\Domain\ProductCategory;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;

/**
 * Choosing one of a thousand categories, without JavaScript.
 *
 * A `<select>` was fine while the list came from the manager's handful of
 * templates. Against the owner's real shop — 1,070 `product_cat` terms, most
 * of them leaves three levels down — it is unusable twice over: the markup is
 * ~90KB, and «لوازم جانبی» appears in six different branches with nothing on
 * screen to tell them apart.
 *
 * So: a search box, results carrying their whole ancestry, and a cap. The
 * search button submits the form like any other save, which is what keeps the
 * title and the brand the vendor already typed — the same trick «جابه‌جایی
 * تصویر» has used since `alpha.14`.
 *
 * The current choice is ALWAYS rendered as the first radio, checked, even
 * when it matches no query. Without that, a form submitted while a search was
 * on screen would post no `category` at all and silently clear a category the
 * vendor had already chosen.
 */
final class ProductCategoryPickerView
{
    /** How many results one page of the picker shows. */
    public const SHOW = 40;

    /**
     * @param list<ProductCategory> $results
     */
    public static function render(
        ?ProductCategory $selected,
        array $results,
        string $query,
        int $matched,
        int $total,
        bool $woocommerceMissing = false
    ): string {
        $html = '<div class="tv-field tv-catpick">'
            . '<span class="tv-label" id="tv-catpick-label">' . esc_html__('دسته', 'tecteb-marketplace-core') . '</span>';

        if ($woocommerceMissing) {
            return $html . VendorUi::notice(
                'warn',
                __('ووکامرس فعال نیست، پس دسته‌ای برای انتخاب وجود ندارد. تا فعال‌شدن ووکامرس، محصول را می‌توانید ذخیره کنید ولی برای بررسی ارسال نمی‌شود.', 'tecteb-marketplace-core')
            ) . '</div>';
        }
        if ($total === 0) {
            return $html . VendorUi::notice(
                'warn',
                __('هیچ دستهٔ محصولی در ووکامرس ساخته نشده است. مدیر بازارگاه باید دسته‌ها را در «محصولات ← دسته‌بندی‌ها» بسازد.', 'tecteb-marketplace-core')
            ) . '</div>';
        }

        $html .= '<p class="tv-hint">'
            . esc_html(sprintf(
                /* translators: %s: number of categories */
                __('دستهٔ محصول از خودِ ووکامرس خوانده می‌شود — همین حالا %s دسته. مشخصه‌های پزشکی مرحلهٔ ۳ از روی همین دسته می‌آیند؛ اگر دسته الگو نداشته باشد، مرحلهٔ ۳ خالی می‌ماند و ارسال شما بسته نمی‌شود.', 'tecteb-marketplace-core'),
                number_format_i18n($total)
            ))
            . '</p>';

        $html .= '<div class="tv-catpick__search">'
            . '<label class="tv-sr-only" for="f-category_q">' . esc_html__('جست‌وجوی دسته', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tv-input" type="search" id="f-category_q" name="category_q" value="' . esc_attr($query) . '"'
            . ' placeholder="' . esc_attr__('نام دسته را بنویسید…', 'tecteb-marketplace-core') . '">'
            . '<button type="submit" name="search_category" value="1" class="tv-btn tv-btn--secondary">'
            . esc_html__('جست‌وجوی دسته', 'tecteb-marketplace-core') . '</button>'
            . '</div>';

        // The selection first, then results that are not it.
        $rows = [];
        if ($selected !== null) {
            $rows[$selected->id] = $selected;
        }
        foreach ($results as $category) {
            $rows[$category->id] ??= $category;
        }

        if ($rows === []) {
            $html .= VendorUi::notice('info', $query === ''
                ? __('برای دیدن دسته‌ها جست‌وجو کنید.', 'tecteb-marketplace-core')
                : __('دسته‌ای با این نام پیدا نشد. بخشی از نام را بنویسید و دوباره جست‌وجو کنید.', 'tecteb-marketplace-core'));
            return $html . '</div>';
        }

        $html .= '<ul class="tv-catpick__list" role="group" aria-labelledby="tv-catpick-label">';
        foreach ($rows as $category) {
            $isSelected = $selected !== null && $selected->id === $category->id;
            $id = 'cat-' . $category->id;
            $html .= '<li class="tv-catpick__row' . ($isSelected ? ' is-selected' : '') . '">'
                . '<input type="radio" id="' . esc_attr($id) . '" name="category" value="' . esc_attr($category->value()) . '"'
                . ($isSelected ? ' checked' : '') . '>'
                . '<label for="' . esc_attr($id) . '">'
                . '<span class="tv-catpick__path">' . esc_html($category->path) . '</span>'
                . '<span class="tv-catpick__meta">'
                . esc_html(sprintf(
                    /* translators: %s: term id */
                    __('شناسهٔ ووکامرس: %s', 'tecteb-marketplace-core'),
                    number_format_i18n($category->id)
                ))
                . ' · '
                . esc_html($category->productCount > 0
                    ? sprintf(
                        /* translators: %s: number of products */
                        __('%s محصول', 'tecteb-marketplace-core'),
                        number_format_i18n($category->productCount)
                    )
                    : __('بدون محصول', 'tecteb-marketplace-core'))
                . ' · '
                . esc_html($category->hasTemplate
                    ? __('الگوی مشخصات دارد', 'tecteb-marketplace-core')
                    : __('بدون الگوی مشخصات', 'tecteb-marketplace-core'))
                . '</span>'
                . '</label>'
                . '</li>';
        }
        $html .= '</ul>';

        if ($matched > count($results)) {
            $html .= '<p class="tv-hint">' . esc_html(sprintf(
                /* translators: 1: shown 2: total matches */
                __('%1$s مورد از %2$s مورد یافته نمایش داده شد. برای باریک‌کردن نتیجه، نام دقیق‌تری بنویسید.', 'tecteb-marketplace-core'),
                number_format_i18n(count($results)),
                number_format_i18n($matched)
            )) . '</p>';
        }
        return $html . '</div>';
    }
}
