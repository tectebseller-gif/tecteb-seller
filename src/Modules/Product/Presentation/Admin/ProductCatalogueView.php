<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation\Admin;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductMessages;

/**
 * Every product of every shop, as one list a manager can work through.
 *
 * The marketplace status is the column the state machine writes. The shop
 * status is read from WordPress on the spot — `get_post_status()` — rather
 * than copied into a column of our own, because a mirrored status is a second
 * truth that goes wrong silently the first time somebody edits the post.
 *
 * **`alpha.32` rebuilt the top of this list, and the first thing it needed was
 * the reason it looked the way it did.** The owner reported seven underlined
 * links stacked down the page with no visible current one. The markup was not
 * missing a class; the STYLESHEET was missing the class. `.tmc-tabs` and
 * `.tmc-tab` had no rule in `assets/admin/tmc-admin.css` — measured, not
 * guessed: zero matches in either stylesheet — so a `<nav><ul>` of links fell
 * back to the browser's own list layout (one block per line) and to this
 * plugin's own `.tmc-admin a { text-decoration: underline }`. `is-current`
 * styled nothing at all, which is why the active filter was invisible. The
 * classes below are this section's own (`tmc-filters`, `tmc-filter`), so the
 * rules that dress them cannot reach the nineteen other admin screens that
 * still write `.tmc-tab` — those are a finding of this round, not a change in
 * it.
 *
 * **Everything that narrows the list is a link or a GET form, never a script.**
 * A filter that needs JavaScript is a filter that is missing on the day
 * JavaScript fails, and this page is where a shop's catalogue is decided.
 */
final class ProductCatalogueView
{
    /**
     * @param list<ProductCatalogueRow> $rows
     */
    public static function render(array $rows, ProductCatalogueState $state): string
    {
        $html = '<section class="tmc-card tmc-catalogue"><h2 class="tmc-card__title">'
            . esc_html__('همهٔ محصولات بازارگاه', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tmc-hint">' . esc_html__('وضعیت بازارگاه تصمیم این افزونه است و وضعیت ووکامرس همان چیزی است که وردپرس همین حالا دربارهٔ پست محصول می‌گوید. این دو عمداً جدا نشان داده می‌شوند و هیچ‌کدام از روی آن یکی ساخته نمی‌شود.', 'tecteb-marketplace-core') . '</p>';

        $html .= self::search($state)
            . self::filters($state)
            . self::revisionFilter($state)
            // The controls and the count are ONE row, not two stacked blocks.
            // Measured on the real install: as separate blocks they put 168px
            // of furniture between the filters and the first row, which is the
            // «فضای خالی بلند» this round was asked to remove — in a new shape.
            . '<div class="tmc-catalogue__bar">'
            . self::tools($state)
            . ($rows === [] ? '' : self::summary($state))
            . '</div>';

        if ($rows === []) {
            return $html . self::emptyState($state) . '</section>';
        }

        $html .= self::outOfRange($state)
            . '<div class="tmc-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('فهرست محصولات', 'tecteb-marketplace-core') . '">'
            . '<table class="tmc-table tmc-catalogue__table"><thead><tr>'
            . '<th scope="col">' . esc_html__('محصول', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('فروشگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت بازارگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت ووکامرس', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('آخرین تغییر', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('بررسی', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= self::row($row, $state);
        }
        $html .= '</tbody></table></div>';

        return $html . self::pager($state) . '</section>';
    }

    /** @param ProductCatalogueRow $row */
    private static function row(ProductCatalogueRow $row, ProductCatalogueState $state): string
    {
        $detail = $state->link(['product' => $row->id, 'paged' => $state->page]);
        $title = $row->title !== '' ? $row->title : sprintf(
            /* translators: %s: the product's marketplace id */
            __('محصول %s', 'tecteb-marketplace-core'),
            self::fa($row->id)
        );

        return '<tr>'
            . '<th scope="row" data-label="' . esc_attr__('محصول', 'tecteb-marketplace-core') . '">'
            . '<span class="tmc-catalogue__product">' . self::thumbnail($row)
            . '<span class="tmc-catalogue__name">'
            . '<a class="tmc-catalogue__link" href="' . esc_url($detail) . '">' . esc_html($title) . '</a>'
            . '<span class="tmc-card__note">' . esc_html(sprintf(
                /* translators: 1: marketplace id, 2: sku */
                __('شناسهٔ بازارگاه: %1$s · SKU: %2$s', 'tecteb-marketplace-core'),
                self::fa($row->id),
                $row->sku !== '' ? $row->sku : '—'
            )) . '</span>'
            . ($row->hasPendingRevision
                ? '<span class="tmc-catalogue__badge">'
                    . esc_html__('نسخهٔ پیشنهادی در انتظار', 'tecteb-marketplace-core') . '</span>'
                : '')
            . '</span></span></th>'
            . '<td data-label="' . esc_attr__('فروشگاه', 'tecteb-marketplace-core') . '">'
            . esc_html($row->storeName !== '' ? $row->storeName : '—') . '</td>'
            . '<td data-label="' . esc_attr__('وضعیت بازارگاه', 'tecteb-marketplace-core') . '">'
            // NOT `.tmc-state`: that class is the full-width empty/error panel
            // (`Components::state()`), and borrowing it for one word would draw
            // a bordered box inside every cell. This section styles its own.
            . '<span class="tmc-catalogue__status tmc-catalogue__status--'
            . esc_attr(ProductMessages::statusTone($row->status)) . '">'
            . esc_html(ProductMessages::status($row->status)) . '</span></td>'
            . '<td data-label="' . esc_attr__('وضعیت ووکامرس', 'tecteb-marketplace-core') . '">'
            . self::shopCell($row) . '</td>'
            . '<td data-label="' . esc_attr__('آخرین تغییر', 'tecteb-marketplace-core') . '">'
            . '<span class="tmc-catalogue__when">' . esc_html(self::fa(substr($row->updatedAt, 0, 16))) . '</span></td>'
            . '<td data-label="' . esc_attr__('بررسی', 'tecteb-marketplace-core') . '">'
            . '<a class="tmc-button tmc-button--primary tmc-catalogue__action" href="' . esc_url($detail) . '">'
            . esc_html__('مشاهده و بررسی', 'tecteb-marketplace-core') . '</a></td>'
            . '</tr>';
    }

    /**
     * A 48px picture, or a labelled box where one would be.
     *
     * `alt=""` on purpose: the title is the next thing in the same cell, and a
     * screen reader reading the product's name twice is worse than a picture
     * nobody announces.
     */
    private static function thumbnail(ProductCatalogueRow $row): string
    {
        if ($row->thumbnailUrl === '') {
            return '<span class="tmc-catalogue__thumb tmc-catalogue__thumb--empty">'
                . esc_html__('بدون تصویر', 'tecteb-marketplace-core') . '</span>';
        }
        return '<img class="tmc-catalogue__thumb" src="' . esc_url($row->thumbnailUrl)
            . '" alt="" width="48" height="48" loading="lazy" decoding="async">';
    }

    /**
     * What WordPress says about the post.
     *
     * «پیوند ندارد» is a real and useful answer: a product waiting for review
     * has no storefront row until somebody prepares one, and saying so beats
     * an empty cell that reads like a bug. The link INTO WooCommerce is not
     * here — it is on the product's own page, beside the Rank Math link, so a
     * row carries one action and not three.
     */
    private static function shopCell(ProductCatalogueRow $row): string
    {
        if (!$row->projected) {
            return '<span class="tmc-card__note">' . esc_html(ProductMessages::shopNotProjected()) . '</span>';
        }
        // The mapping lives in `ProductMessages` from `alpha.33`: the product's
        // own page used to print this value raw, so one product read
        // «منتشرشده» here and `publish` there.
        return esc_html(ProductMessages::shopStatus($row->shopStatus));
    }

    /**
     * The search box, directly above the filters.
     *
     * A GET form, so the browser builds the address: that is what makes the
     * page number reset by itself — a GET submit replaces the whole query
     * string with these fields, and `paged` is not one of them. The filter,
     * the row count and the sort ARE hidden fields, because searching inside
     * «منتشرشده» must stay inside «منتشرشده».
     */
    private static function search(ProductCatalogueState $state): string
    {
        return '<form method="get" class="tmc-catalogue__search" role="search">'
            . '<input type="hidden" name="page" value="' . esc_attr(ProductReviewPage::SLUG) . '">'
            . self::carried($state, ['status', 'per_page', 'orderby', 'revisions'])
            . '<label class="tmc-field__label" for="tmc-cat-q">'
            . esc_html__('جست‌وجوی محصول', 'tecteb-marketplace-core') . '</label>'
            . '<div class="tmc-catalogue__searchrow">'
            . '<input class="tmc-input tmc-catalogue__q" type="search" id="tmc-cat-q" name="q"'
            . ' value="' . esc_attr($state->search) . '"'
            . ' placeholder="' . esc_attr__('جست‌وجوی عنوان، SKU یا برند…', 'tecteb-marketplace-core') . '">'
            . '<button type="submit" class="tmc-button tmc-button--primary">'
            . esc_html__('جست‌وجو', 'tecteb-marketplace-core') . '</button>'
            . ($state->search !== ''
                ? '<a class="tmc-button tmc-button--ghost" href="' . esc_url($state->link(['q' => null])) . '">'
                    . esc_html__('برداشتن جست‌وجو', 'tecteb-marketplace-core') . '</a>'
                : '')
            . '</div></form>';
    }

    /**
     * The status filters: one row of buttons, right to left, wrapping.
     *
     * Each is a real link with a real address, so it works with JavaScript
     * off, opens in a new tab, and can be bookmarked. The active one is marked
     * twice — `aria-current="page"` for a screen reader and `is-current` for
     * the eye — because a filter whose state is only a colour is a filter a
     * colour-blind manager cannot read.
     */
    private static function filters(ProductCatalogueState $state): string
    {
        $all = array_sum($state->counts);
        $tabs = ['' => __('همه', 'tecteb-marketplace-core')];
        foreach (ProductStatus::cases() as $status) {
            $tabs[$status->value] = ProductMessages::status($status);
        }
        // A status the enum does not know is still a row in the table, and a
        // chip list that silently omits it makes «همه» disagree with the sum
        // of its parts — which is exactly the shape of the mismatch the owner
        // reported. So the leftovers get a chip of their own and the numbers
        // always add up.
        $known = 0;
        foreach (ProductStatus::cases() as $status) {
            $known += $state->counts[$status->value] ?? 0;
        }
        $unknown = $all - $known;

        $html = '<nav class="tmc-filters" aria-label="' . esc_attr__('وضعیت محصول', 'tecteb-marketplace-core') . '">'
            . '<ul class="tmc-filters__list">';
        foreach ($tabs as $value => $label) {
            $n = $value === '' ? $all : ($state->counts[$value] ?? 0);
            $current = $value === $state->status;
            $classes = 'tmc-filter'
                . ($current ? ' is-current' : '')
                // Nothing to show is still a place to stand: the chip is
                // quieter, and it stays a link. A disabled filter would leave
                // the manager no way back once the last row left a status.
                . ($n === 0 && !$current ? ' is-empty' : '');
            $html .= '<li><a class="' . $classes . '"'
                . ($current ? ' aria-current="page"' : '')
                . ' href="' . esc_url($state->link(['status' => $value === '' ? null : $value])) . '">'
                . '<span class="tmc-filter__label">' . esc_html($label) . '</span>'
                . '<span class="tmc-filter__count">' . esc_html(self::fa($n)) . '</span></a></li>';
        }
        if ($unknown > 0) {
            $html .= '<li><span class="tmc-filter is-warning">'
                . '<span class="tmc-filter__label">'
                . esc_html__('وضعیت ناشناخته', 'tecteb-marketplace-core') . '</span>'
                . '<span class="tmc-filter__count">' . esc_html(self::fa($unknown)) . '</span></span></li>';
        }
        return $html . '</ul></nav>';
    }

    /**
     * The one queue that is not a status: a published product with an
     * unanswered proposal.
     *
     * It is not an eighth chip, because the seven are the product's own
     * statuses and a proposal is a different question about the same product.
     * It is offered only when there is one — or when it is on, so there is a
     * way back off it.
     */
    private static function revisionFilter(ProductCatalogueState $state): string
    {
        if ($state->pendingRevisions === 0 && !$state->onlyRevisions) {
            return '';
        }
        if ($state->onlyRevisions) {
            return '<p class="tmc-catalogue__scope">'
                . esc_html__('فهرست به محصول‌هایی محدود شده که نسخهٔ پیشنهادی بی‌پاسخ دارند.', 'tecteb-marketplace-core')
                . ' <a href="' . esc_url($state->link(['revisions' => null])) . '">'
                . esc_html__('برداشتن این محدودیت', 'tecteb-marketplace-core') . '</a></p>';
        }
        return '<p class="tmc-catalogue__scope">'
            . esc_html(sprintf(
                /* translators: %s: how many published products have an unanswered proposal */
                __('%s محصول منتشرشده نسخهٔ پیشنهادی بی‌پاسخ دارد.', 'tecteb-marketplace-core'),
                self::fa($state->pendingRevisions)
            ))
            . ' <a href="' . esc_url($state->link(['revisions' => ProductCatalogueState::REVISIONS_PENDING])) . '">'
            . esc_html__('فقط همان‌ها را نشان بده', 'tecteb-marketplace-core') . '</a></p>';
    }

    /**
     * How many rows, and in what order.
     *
     * A second GET form rather than a script on the `<select>`: choosing 50
     * rows has to work with JavaScript off, and an `onchange` submit would
     * strand a keyboard user who arrows through the options.
     */
    private static function tools(ProductCatalogueState $state): string
    {
        $html = '<form method="get" class="tmc-catalogue__tools">'
            . '<input type="hidden" name="page" value="' . esc_attr(ProductReviewPage::SLUG) . '">'
            . self::carried($state, ['status', 'q', 'revisions'])
            . '<span class="tmc-catalogue__tool">'
            . '<label class="tmc-field__label" for="tmc-cat-per">'
            . esc_html__('تعداد ردیف', 'tecteb-marketplace-core') . '</label>'
            . '<select class="tmc-select" id="tmc-cat-per" name="per_page">';
        foreach (ProductCatalogueState::PER_PAGE_CHOICES as $choice) {
            $html .= '<option value="' . esc_attr((string) $choice) . '"'
                . ($choice === $state->perPage ? ' selected' : '') . '>'
                . esc_html(self::fa($choice)) . '</option>';
        }
        $html .= '</select></span>'
            . '<span class="tmc-catalogue__tool">'
            . '<label class="tmc-field__label" for="tmc-cat-sort">'
            . esc_html__('مرتب‌سازی', 'tecteb-marketplace-core') . '</label>'
            . '<select class="tmc-select" id="tmc-cat-sort" name="orderby">';
        foreach (ProductMessages::sorts() as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '"'
                . ($value === $state->sort->value ? ' selected' : '') . '>'
                . esc_html($label) . '</option>';
        }
        return $html . '</select></span>'
            . '<button type="submit" class="tmc-button">' . esc_html__('اعمال', 'tecteb-marketplace-core') . '</button>'
            . '</form>';
    }

    /**
     * «the page you asked for is gone, here is the last one» — said out loud.
     *
     * Without it, a manager who decides on the last product of page seven is
     * silently moved to page six and has to work out why the numbers changed.
     * This is not the empty-result message: that one is about the filter, this
     * one is about the page, and the reader needs to know which happened.
     */
    private static function outOfRange(ProductCatalogueState $state): string
    {
        if ($state->outOfRangePage === null) {
            return '';
        }
        return '<p class="tmc-catalogue__scope">' . esc_html(sprintf(
            /* translators: 1: the page that was asked for, 2: the page being shown */
            __('صفحهٔ %1$s دیگر وجود ندارد — احتمالاً پس از یک تصمیم، فهرست کوتاه‌تر شده است. صفحهٔ %2$s، آخرین صفحهٔ موجود، نشان داده شد.', 'tecteb-marketplace-core'),
            self::fa($state->outOfRangePage),
            self::fa($state->page)
        )) . '</p>';
    }

    /** «نمایش ۱ تا ۲۰ از ۲۳۴ محصول» — the sentence a pager is for. */
    private static function summary(ProductCatalogueState $state): string
    {
        return '<p class="tmc-catalogue__meta">' . esc_html(sprintf(
            /* translators: 1: first row's number, 2: last row's number, 3: how many rows in total */
            __('نمایش %1$s تا %2$s از %3$s محصول', 'tecteb-marketplace-core'),
            self::fa($state->firstRow()),
            self::fa($state->lastRow()),
            self::fa($state->total)
        )) . '</p>';
    }

    /**
     * Nothing to show, and why — with the way out of it.
     *
     * Four different sentences, because «چیزی پیدا نشد» about a search and
     * «هنوز محصولی نیست» about an empty marketplace send the manager to two
     * different places.
     */
    private static function emptyState(ProductCatalogueState $state): string
    {
        $message = match (true) {
            $state->search !== '' => __('چیزی با این عبارت پیدا نشد.', 'tecteb-marketplace-core'),
            $state->onlyRevisions => __('نسخهٔ پیشنهادی بی‌پاسخی وجود ندارد.', 'tecteb-marketplace-core'),
            $state->status !== '' => __('در این وضعیت محصولی نیست.', 'tecteb-marketplace-core'),
            default => __('هنوز محصولی در بازارگاه ثبت نشده است.', 'tecteb-marketplace-core'),
        };
        return '<p class="tmc-catalogue__empty">' . esc_html($message) . '</p>'
            . ($state->isFiltered()
                ? '<p><a class="tmc-button tmc-button--ghost" href="' . esc_url($state->baseUrl) . '">'
                    . esc_html__('پاک‌کردن فیلترها', 'tecteb-marketplace-core') . '</a></p>'
                : '');
    }

    /**
     * Previous, the page numbers, next.
     *
     * The current page is a `<span>`, not a link to where you already are, and
     * the numbers are windowed so page 7 of 40 is seven controls rather than
     * forty.
     */
    private static function pager(ProductCatalogueState $state): string
    {
        $pages = $state->pages();
        if ($pages <= 1) {
            return '';
        }
        $html = '<nav class="tmc-pager" aria-label="' . esc_attr__('صفحه‌بندی', 'tecteb-marketplace-core') . '">';
        if ($state->page > 1) {
            $html .= '<a class="tmc-button tmc-button--ghost" rel="prev" href="'
                . esc_url($state->link(['paged' => $state->page - 1])) . '">'
                . esc_html__('صفحهٔ قبل', 'tecteb-marketplace-core') . '</a>';
        }
        $html .= '<ul class="tmc-pager__pages">';
        $previous = 0;
        foreach (self::window($state->page, $pages) as $number) {
            if ($previous !== 0 && $number > $previous + 1) {
                $html .= '<li><span class="tmc-pager__gap" aria-hidden="true">…</span></li>';
            }
            $previous = $number;
            $html .= '<li>' . ($number === $state->page
                ? '<span class="tmc-pager__page is-current" aria-current="page">' . esc_html(self::fa($number)) . '</span>'
                : '<a class="tmc-pager__page" href="' . esc_url($state->link(['paged' => $number])) . '">'
                    . esc_html(self::fa($number)) . '</a>') . '</li>';
        }
        $html .= '</ul>';
        if ($state->page < $pages) {
            $html .= '<a class="tmc-button tmc-button--ghost" rel="next" href="'
                . esc_url($state->link(['paged' => $state->page + 1])) . '">'
                . esc_html__('صفحهٔ بعد', 'tecteb-marketplace-core') . '</a>';
        }
        return $html . '</nav>';
    }

    /**
     * Which page numbers to draw: the first, the last, and the neighbourhood.
     *
     * @return list<int> ascending, without duplicates
     */
    private static function window(int $page, int $pages): array
    {
        $numbers = [1, $pages];
        for ($n = $page - 2; $n <= $page + 2; $n++) {
            if ($n >= 1 && $n <= $pages) {
                $numbers[] = $n;
            }
        }
        $numbers = array_values(array_unique($numbers));
        sort($numbers);
        return $numbers;
    }

    /**
     * The current state as hidden fields, for a GET form.
     *
     * `paged` is never one of them: a form submit is a new question, and a new
     * question starts on page one.
     *
     * @param list<string> $keys
     */
    private static function carried(ProductCatalogueState $state, array $keys): string
    {
        $values = [
            'status' => $state->status,
            'q' => $state->search,
            'per_page' => $state->perPage === ProductCatalogueState::PER_PAGE ? '' : (string) $state->perPage,
            'orderby' => $state->sort->isDefault() ? '' : $state->sort->value,
            'revisions' => $state->onlyRevisions ? ProductCatalogueState::REVISIONS_PENDING : '',
        ];
        $html = '';
        foreach ($keys as $key) {
            $value = $values[$key] ?? '';
            if ($value === '') {
                continue;
            }
            $html .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
        }
        return $html;
    }

    private static function fa(string|int $value): string
    {
        return PersianDigits::toPersian((string) $value);
    }
}
