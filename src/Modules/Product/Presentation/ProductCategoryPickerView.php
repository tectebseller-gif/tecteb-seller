<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation;

use Tecteb\Marketplace\Modules\Product\Domain\ProductCategory;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;

/**
 * Choosing one of a thousand categories.
 *
 * A `<select>` was fine while the list came from the manager's handful of
 * templates. Against the owner's real shop — 1,070 `product_cat` terms, most
 * of them leaves three levels down — it is unusable twice over: the markup is
 * ~90KB, and «لوازم جانبی» appears in six different branches with nothing on
 * screen to tell them apart.
 *
 * So: a search box, results carrying their whole ancestry, and a cap.
 *
 * **Two layers, and the lower one is the whole feature.** Without JavaScript
 * the search button submits the form like any other save, which is what keeps
 * the title and the brand the vendor already typed — the same trick
 * «جابه‌جایی تصویر» has used since `alpha.14`. With JavaScript, the same box
 * fetches suggestions as they type and replaces the list in place; the button
 * stays exactly where it was and still works. Nothing here is reachable only
 * through the script.
 *
 * **The choice is rendered apart from the results, always.** It is its own
 * block above the list, checked, and it stays there while the vendor searches
 * for something else — so «what is selected» never has to be read out of a
 * list of forty. Without it, a form submitted while a search was on screen
 * would post no `category` at all and silently clear a category the vendor
 * had already chosen.
 *
 * **A near match is labelled and comes last.** `fuzzy` arrives on the row
 * from `CategoryMatcher`; the view does not re-derive it, because the view
 * does not know what was typed. A guess that arrives looking like a certainty
 * is how the wrong category gets picked — and on a medical catalogue the
 * wrong category is the wrong specification sheet.
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
        bool $woocommerceMissing = false,
        string $suggestUrl = '',
        string $suggestNonce = '',
        string $suggestAction = ''
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

        // The current choice, above the search and outside it. It carries the
        // radio that is checked, so the form posts the right value whatever
        // the list below is showing.
        $html .= self::chosen($selected);

        $html .= '<div class="tv-catpick__search"'
            . ($suggestUrl !== '' ? ' data-suggest-url="' . esc_url($suggestUrl) . '"' : '')
            . ($suggestNonce !== '' ? ' data-suggest-nonce="' . esc_attr($suggestNonce) . '"' : '')
            . ($suggestAction !== '' ? ' data-suggest-action="' . esc_attr($suggestAction) . '"' : '')
            // Every Persian string the script needs, on the element it is
            // wired from. The vendor area renders no executable markup at
            // all, so there is no inline <script> to put them in — and a
            // string baked into a .js file is a string no translation
            // catalogue can reach.
            . ' data-text-searching="' . esc_attr__('در حال جست‌وجو…', 'tecteb-marketplace-core') . '"'
            . ' data-text-failed="' . esc_attr__('جست‌وجوی خودکار انجام نشد. دکمهٔ «جست‌وجوی دسته» را بزنید.', 'tecteb-marketplace-core') . '"'
            . '>'
            . '<label class="tv-sr-only" for="f-category_q">' . esc_html__('جست‌وجوی دسته', 'tecteb-marketplace-core') . '</label>'
            // No `role="combobox"`: the results below are a RADIO GROUP, and
            // a combobox is required to control a listbox. Claiming the role
            // would describe a widget that is not there — so the box stays an
            // ordinary search field, the results stay radios a keyboard
            // already knows how to walk, and the live region says what
            // changed.
            . '<input class="tv-input" type="search" id="f-category_q" name="category_q" value="' . esc_attr($query) . '"'
            . ' autocomplete="off" aria-describedby="tv-catpick-status"'
            . ' placeholder="' . esc_attr__('نام دسته را بنویسید…', 'tecteb-marketplace-core') . '">'
            // The button is not decoration and is not hidden when the script
            // loads: it is the no-JavaScript path, and it is also the way out
            // when a suggestion request fails.
            . '<button type="submit" name="search_category" value="1" class="tv-btn tv-btn--secondary">'
            . esc_html__('جست‌وجوی دسته', 'tecteb-marketplace-core') . '</button>'
            . '</div>';

        // One live region for both the count and the failure, so a screen
        // reader is told what happened rather than left with a list that
        // changed under it.
        $html .= '<p class="tv-hint tv-catpick__status" id="tv-catpick-status" role="status" aria-live="polite"></p>';

        $html .= '<div id="tv-catpick-results" class="tv-catpick__results">'
            . self::results($results, $selected, $query, $matched)
            . '</div>';

        return $html . '</div>';
    }

    /**
     * The result list on its own, so the script can replace exactly this.
     *
     * Rendered by the server on the first paint and by the browser after
     * that. Both produce the same markup, because a list that looks different
     * depending on how it arrived is a list nobody can test.
     *
     * @param list<ProductCategory> $results
     */
    public static function results(array $results, ?ProductCategory $selected, string $query, int $matched): string
    {
        // The selection is shown above; repeating it here would ask the
        // vendor to notice that two identical rows are one row.
        $rows = [];
        foreach ($results as $category) {
            if ($selected !== null && $selected->id === $category->id) {
                continue;
            }
            $rows[] = $category;
        }

        if ($rows === []) {
            return VendorUi::notice('info', $query === ''
                ? __('برای دیدن دسته‌ها جست‌وجو کنید.', 'tecteb-marketplace-core')
                : __('دسته‌ای با این نام پیدا نشد. بخشی از نام را بنویسید و دوباره جست‌وجو کنید.', 'tecteb-marketplace-core'));
        }

        $html = '<ul class="tv-catpick__list" role="group" aria-labelledby="tv-catpick-label">';
        $firstFuzzy = true;
        foreach ($rows as $category) {
            if ($category->fuzzy && $firstFuzzy) {
                // Said once, above the block, rather than argued row by row:
                // everything from here down was found by tolerating a typo.
                $html .= '<li class="tv-catpick__divider" role="presentation">'
                    . esc_html__('پیشنهاد نزدیک — نتیجهٔ دقیقی نیست، خودتان بررسی کنید:', 'tecteb-marketplace-core')
                    . '</li>';
                $firstFuzzy = false;
            }
            $html .= self::row($category, false);
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
        return $html;
    }

    /** The chosen category, or a plain statement that there is none yet. */
    private static function chosen(?ProductCategory $selected): string
    {
        $html = '<div class="tv-catpick__chosen" id="tv-catpick-chosen">'
            . '<span class="tv-catpick__chosen-label">' . esc_html__('دستهٔ انتخاب‌شده', 'tecteb-marketplace-core') . '</span>';
        if ($selected === null) {
            return $html . '<p class="tv-hint">'
                . esc_html__('هنوز دسته‌ای انتخاب نشده است. از فهرست زیر یکی را انتخاب کنید.', 'tecteb-marketplace-core')
                . '</p></div>';
        }
        return $html . '<ul class="tv-catpick__list" role="group" aria-label="'
            . esc_attr__('دستهٔ انتخاب‌شده', 'tecteb-marketplace-core') . '">'
            . self::row($selected, true)
            . '</ul></div>';
    }

    private static function row(ProductCategory $category, bool $isSelected): string
    {
        $id = 'cat-' . $category->id;
        return '<li class="tv-catpick__row' . ($isSelected ? ' is-selected' : '')
            . ($category->fuzzy ? ' is-fuzzy' : '') . '">'
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
            . ($category->fuzzy
                ? ' · <span class="tv-catpick__near">' . esc_html__('پیشنهاد نزدیک', 'tecteb-marketplace-core') . '</span>'
                : '')
            . '</span>'
            . '</label>'
            . '</li>';
    }
}
