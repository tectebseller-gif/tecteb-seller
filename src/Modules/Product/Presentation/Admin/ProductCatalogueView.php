<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation\Admin;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Migration\Domain\CatalogueBucket;
use Tecteb\Marketplace\Modules\Migration\Domain\CatalogueFacets;
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
    public static function render(
        array $rows,
        ProductCatalogueState $state,
        ?CatalogueIntakeControls $intake = null
    ): string {
        $html = '<section class="tmc-card tmc-catalogue"><h2 class="tmc-card__title">'
            . esc_html__('همهٔ محصولات این فروشگاه', 'tecteb-marketplace-core') . '</h2>'
            // Three sentences, and no more. The long version — what an
            // «observed» row does and does not decide, what an intake copies,
            // which plugins have written meta on a product — is on the
            // product's own page: «توضیح فنی و تکراری به نمای جزئیات منتقل
            // شود».
            . '<p class="tmc-hint">' . esc_html__('این فهرست هم محصول‌های ووکامرس را نشان می‌دهد و هم ردیف‌های بازارگاه را، و هر محصول مرتبط یک بار. باز کردن این صفحه هیچ محصولی را وارد نمی‌کند و مالکیت هیچ محصولی را عوض نمی‌کند.', 'tecteb-marketplace-core') . '</p>'
            . '<p class="tmc-hint">' . esc_html__('«وضعیت ووکامرس» همان چیزی است که وردپرس همین حالا دربارهٔ پست محصول می‌گوید و «وضعیت مدیریت» می‌گوید این افزونه آن محصول را می‌گرداند یا نه. این دو یک چیز نیستند.', 'tecteb-marketplace-core') . '</p>';

        $html .= self::search($state)
            . self::filters($state)
            . self::sourceFilters($state)
            . self::managementFilters($state)
            . self::shopStatusFilters($state)
            . self::reviewFilter($state)
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

        $selectable = $intake !== null && self::anySelectable($rows);
        $html .= self::outOfRange($state);
        // The form wraps the TABLE, so the checkbox that selects a product and
        // the button that imports it are one submission. Two forms would be
        // two reads of one choice.
        if ($selectable) {
            $html .= '<form method="post" class="tmc-catalogue__intake">' . $intake->nonceField;
        }
        $html .= '<div class="tmc-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('فهرست محصولات', 'tecteb-marketplace-core') . '">'
            . '<table class="tmc-table tmc-catalogue__table"><thead><tr>'
            . ($selectable
                ? '<th scope="col">' . esc_html__('انتخاب', 'tecteb-marketplace-core') . '</th>'
                : '')
            . '<th scope="col">' . esc_html__('محصول', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('فروشگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت بازارگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت ووکامرس', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت مدیریت', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('آخرین تغییر', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('بررسی', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= self::row($row, $state, $selectable, $intake?->dokanUrl ?? '');
        }
        $html .= '</tbody></table></div>';
        if ($selectable) {
            $html .= self::intakeControls($intake) . '</form>';
        }

        return $html . self::pager($state) . '</section>';
    }

    /**
     * Whether any row on this page could be taken in at all.
     *
     * Without this the form, its shop picker and its two buttons are drawn on
     * every page of a catalogue that is entirely imported already — furniture
     * above the rows, which is the «فضای خالی بلند» this list was rebuilt to
     * remove.
     *
     * @param list<ProductCatalogueRow> $rows
     */
    private static function anySelectable(array $rows): bool
    {
        foreach ($rows as $row) {
            if (self::isSelectable($row)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether this row gets a checkbox.
     *
     * Exactly the two cases an intake can act on: a product whose vendor the
     * evidence already names, and the main shop's own product — which needs a
     * vendor NAMED BY THE MANAGER and so cannot be imported without the
     * picker below. Everything else is a question (`$blockers`), already has
     * a row, or belongs to Dokan and has its own path.
     */
    private static function isSelectable(ProductCatalogueRow $row): bool
    {
        if ($row->wcProductId <= 0 || $row->source !== CatalogueFacets::SOURCE_WOOCOMMERCE) {
            return false;
        }
        return $row->intakeReady || $row->bucket === CatalogueBucket::SHOP;
    }

    /**
     * One line of the one list.
     *
     * **Two halves of a catalogue, one shape.** A row can be a marketplace
     * product, a WooCommerce product nobody has imported, or a marketplace
     * row whose WooCommerce product is not there. They are drawn the same way
     * on purpose — «ظاهر مانند فهرست فعلی بازارگاه» — and what differs is
     * what each cell can honestly say: a product with no marketplace row has
     * no marketplace status, and inventing one would put a decision of this
     * plugin's on a product it does not run.
     *
     * **Where «بررسی» goes depends on which half it is.** A marketplace row
     * has a product page of its own; a WooCommerce product that has none is
     * opened by its WooCommerce id, and that page is where the evidence, the
     * other plugins' meta and the preservation risks are. A Dokan product
     * gets a third destination: its own transfer path, named, so a manager is
     * not left hunting for an unrelated screen
     * («محصول‌های دکان از همان رابط به مسیر انتقال درست هدایت شوند»).
     */
    private static function row(
        ProductCatalogueRow $row,
        ProductCatalogueState $state,
        bool $selectable = false,
        string $dokanUrl = ''
    ): string {
        $detail = $row->id > 0
            ? $state->link(['product' => $row->id, 'paged' => $state->page])
            : $state->link(['wc' => $row->wcProductId, 'paged' => $state->page]);
        $title = ProductMessages::displayTitle($row->title, $row->id > 0 ? $row->id : $row->wcProductId);

        $html = '<tr>';
        if ($selectable) {
            $html .= '<td data-label="' . esc_attr__('انتخاب', 'tecteb-marketplace-core') . '">'
                . (self::isSelectable($row)
                    ? '<input type="checkbox" name="wc_ids[]" value="'
                        . esc_attr((string) $row->wcProductId) . '" aria-label="'
                        . esc_attr(sprintf(
                            /* translators: %s: the product's title */
                            __('انتخاب %s برای ورود', 'tecteb-marketplace-core'),
                            $title
                        )) . '">'
                    : '<span aria-hidden="true">—</span>')
                . '</td>';
        }

        return $html
            . '<th scope="row" data-label="' . esc_attr__('محصول', 'tecteb-marketplace-core') . '">'
            . '<span class="tmc-catalogue__product">' . self::thumbnail($row)
            . '<span class="tmc-catalogue__name">'
            . '<a class="tmc-catalogue__link" href="' . esc_url($detail) . '">' . esc_html($title) . '</a>'
            . '<span class="tmc-card__note">' . esc_html(self::identity($row)) . '</span>'
            . ($row->variations > 0
                ? '<span class="tmc-card__note">' . esc_html(sprintf(
                    /* translators: %s: how many variations */
                    __('%s تنوع', 'tecteb-marketplace-core'),
                    self::fa($row->variations)
                )) . '</span>'
                : '')
            . ($row->hasPendingRevision
                ? '<span class="tmc-catalogue__badge">'
                    . esc_html__('نسخهٔ پیشنهادی در انتظار', 'tecteb-marketplace-core') . '</span>'
                : '')
            // A second badge rather than a combined one: a row can be waiting
            // on a change proposal AND on a brand request, and they are two
            // decisions on two screens' worth of context.
            . ($row->hasBrandRequest
                ? '<span class="tmc-catalogue__badge">'
                    . esc_html__('درخواست برند در انتظار', 'tecteb-marketplace-core') . '</span>'
                : '')
            // The one that answers «is this product actually ours». An
            // observed row is a note saying which WooCommerce product
            // corresponds to this one and nothing follows from it: the
            // purchase guard, the storefront stop, the projector and the
            // vendor's catalogue all ignore it. Without the badge a manager
            // reads a list in which an imported product and a managed one
            // look identical.
            . ($row->id > 0 && !$row->ownsStorefrontProduct
                ? '<span class="tmc-catalogue__badge">'
                    . esc_html__('فقط مشاهده‌شده — مدیریت بازارگاه پذیرفته نشده', 'tecteb-marketplace-core')
                    . '</span>'
                : '')
            . self::blockerNote($row)
            . '</span></span></th>'
            . '<td data-label="' . esc_attr__('فروشگاه', 'tecteb-marketplace-core') . '">'
            . esc_html($row->storeName !== '' ? $row->storeName : '—')
            . ($row->bucketLabel !== ''
                ? '<br><span class="tmc-card__note">' . esc_html($row->bucketLabel) . '</span>'
                : '')
            . '</td>'
            . '<td data-label="' . esc_attr__('وضعیت بازارگاه', 'tecteb-marketplace-core') . '">'
            . self::marketplaceCell($row) . '</td>'
            . '<td data-label="' . esc_attr__('وضعیت ووکامرس', 'tecteb-marketplace-core') . '">'
            . self::shopCell($row) . '</td>'
            . '<td data-label="' . esc_attr__('وضعیت مدیریت', 'tecteb-marketplace-core') . '">'
            . esc_html($row->management === ''
                ? '—'
                : CatalogueFacets::managementLabel($row->management)) . '</td>'
            . '<td data-label="' . esc_attr__('آخرین تغییر', 'tecteb-marketplace-core') . '">'
            . '<span class="tmc-catalogue__when">' . esc_html(self::fa(substr($row->updatedAt, 0, 16))) . '</span></td>'
            . '<td data-label="' . esc_attr__('بررسی', 'tecteb-marketplace-core') . '">'
            . '<a class="tmc-button tmc-button--primary tmc-catalogue__action" href="' . esc_url($detail) . '">'
            . esc_html__('مشاهده و بررسی', 'tecteb-marketplace-core') . '</a>'
            // A Dokan product's road out is Dokan's own transfer path, which
            // reads Dokan's tables and records the shop's past with them. A
            // second road into the same table would be the parallel path the
            // instruction forbids, so this row points AT the other one
            // instead of offering an intake that would be the wrong import.
            . ($dokanUrl !== '' && $row->bucket === CatalogueBucket::DOKAN
                ? '<br><a class="tmc-button tmc-button--ghost tmc-catalogue__action" href="'
                    . esc_url($dokanUrl) . '">'
                    . esc_html__('مسیر انتقال از دکان', 'tecteb-marketplace-core') . '</a>'
                : '')
            . '</td>'
            . '</tr>';
    }

    /**
     * Which ids a row has, said exactly.
     *
     * Both are printed when both exist, because the whole point of one list
     * is that a manager can see that marketplace row 31 and WooCommerce
     * product 1229 are the same thing — and that a row with only one of the
     * two is half a pair.
     */
    private static function identity(ProductCatalogueRow $row): string
    {
        $parts = [];
        if ($row->id > 0) {
            $parts[] = sprintf(
                /* translators: %s: the marketplace row's id */
                __('شناسهٔ بازارگاه: %s', 'tecteb-marketplace-core'),
                self::fa($row->id)
            );
        }
        if ($row->wcProductId > 0) {
            $parts[] = sprintf(
                /* translators: %s: the WooCommerce product id */
                __('شناسهٔ ووکامرس: %s', 'tecteb-marketplace-core'),
                self::fa($row->wcProductId)
            );
        }
        $parts[] = 'SKU: ' . ($row->sku !== '' ? $row->sku : '—');
        return implode(' · ', $parts);
    }

    /**
     * The marketplace state machine's own status — or the honest absence of
     * one.
     *
     * A WooCommerce product nobody has imported has no marketplace status,
     * and that cell says so in words. It does not say «draft»: a draft is a
     * decision somebody made.
     */
    private static function marketplaceCell(ProductCatalogueRow $row): string
    {
        if ($row->status === null) {
            return '<span class="tmc-card__note">'
                . esc_html__('ردیفی در بازارگاه ندارد', 'tecteb-marketplace-core') . '</span>';
        }
        // NOT `.tmc-state`: that class is the full-width empty/error panel
        // (`Components::state()`), and borrowing it for one word would draw
        // a bordered box inside every cell. This section styles its own.
        return '<span class="tmc-catalogue__status tmc-catalogue__status--'
            . esc_attr(ProductMessages::statusTone($row->status)) . '">'
            . esc_html(ProductMessages::status($row->status)) . '</span>';
    }

    /**
     * Why this row cannot simply be taken in — ONE short line.
     *
     * The list says the one sentence that decides what a manager does next;
     * the author's roles, the vendor paperwork's state, the other plugins'
     * meta keys and everything an intake could not carry are on the
     * product's own page. A list that printed all of it would be the page
     * `alpha.32` took apart, in a new shape.
     */
    private static function blockerNote(ProductCatalogueRow $row): string
    {
        if ($row->blockers === []) {
            return '';
        }
        return '<span class="tmc-card__note tmc-catalogue__blocker">'
            . esc_html((string) $row->blockers[0])
            . (count($row->blockers) > 1
                ? ' ' . esc_html(sprintf(
                    /* translators: %s: how many further reasons there are */
                    __('(و %s دلیل دیگر، در صفحهٔ محصول)', 'tecteb-marketplace-core'),
                    self::fa(count($row->blockers) - 1)
                ))
                : '')
            . '</span>';
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
        // Asked of the WooCommerce id rather than of our own «projected»
        // flag, because from `alpha.56` a row can have a WooCommerce product
        // and no marketplace row at all — and that product's status is the
        // one thing about it this list definitely knows.
        if ($row->wcProductId <= 0 && !$row->projected) {
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
            . self::carried($state, ['status', 'per_page', 'orderby', 'revisions', 'source', 'wc_status', 'management'])
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
     * منبع — which half of the catalogue a row is.
     *
     * Its own chip row rather than three more status chips, because it is a
     * different question from the product's marketplace status and the two
     * combine: «منتشرشده» AND «فقط در ووکامرس» is a real and interesting
     * pair, and a single chip row could not express it.
     */
    private static function sourceFilters(ProductCatalogueState $state): string
    {
        return self::facetRow(
            $state,
            'source',
            $state->source,
            __('منبع محصول', 'tecteb-marketplace-core'),
            CatalogueFacets::SOURCES,
            (array) ($state->facets['source'] ?? [])
        );
    }

    /** وضعیت مدیریت — whether this plugin runs the product. Never the publish status. */
    private static function managementFilters(ProductCatalogueState $state): string
    {
        return self::facetRow(
            $state,
            'management',
            $state->management,
            __('وضعیت مدیریت بازارگاه', 'tecteb-marketplace-core'),
            CatalogueFacets::MANAGEMENT,
            (array) ($state->facets['management'] ?? [])
        );
    }

    /**
     * وضعیت انتشار ووکامرس — the statuses the SITE actually has.
     *
     * Built from the counts rather than from a list written here, because
     * `post_status` is extensible: a plugin on the owner's site can register
     * one this file has never heard of, and a hand-written list would leave
     * its products reachable only by typing the URL. The labels come from
     * `ProductMessages::shopStatus()`, which is the same mapping the column
     * beside it prints — until `alpha.33` the list translated and the detail
     * page printed `publish` raw.
     */
    private static function shopStatusFilters(ProductCatalogueState $state): string
    {
        $counts = (array) ($state->facets['wc_status'] ?? []);
        if ($counts === []) {
            return '';
        }
        $labels = [];
        foreach (array_keys($counts) as $status) {
            $status = (string) $status;
            $labels[$status] = $status === CatalogueFacets::STATUS_ABSENT
                ? __('بدون محصول ووکامرس', 'tecteb-marketplace-core')
                : ProductMessages::shopStatus($status);
        }
        return self::facetRow(
            $state,
            'wc_status',
            $state->wcStatus,
            __('وضعیت انتشار در ووکامرس', 'tecteb-marketplace-core'),
            $labels,
            $counts
        );
    }

    /**
     * One row of facet chips, written once for all three.
     *
     * Three copies of this markup would be three chances for one of them to
     * drop a parameter, and dropping one silently resets a filter the manager
     * is standing in — which on a catalogue of hundreds means the row they
     * were about to decide on leaves the screen (`ProductCatalogueState`).
     *
     * @param array<string,string> $labels value => label
     * @param array<string,int>    $counts value => how many
     */
    private static function facetRow(
        ProductCatalogueState $state,
        string $key,
        string $current,
        string $legend,
        array $labels,
        array $counts
    ): string {
        $html = '<nav class="tmc-filters" aria-label="' . esc_attr($legend) . '">'
            . '<span class="tmc-filters__legend">' . esc_html($legend) . '</span>'
            . '<ul class="tmc-filters__list">';
        $all = ['' => __('همه', 'tecteb-marketplace-core')] + $labels;
        foreach ($all as $value => $label) {
            $value = (string) $value;
            $n = $value === '' ? array_sum($counts) : (int) ($counts[$value] ?? 0);
            $isCurrent = $value === $current;
            $html .= '<li><a class="tmc-filter'
                . ($isCurrent ? ' is-current' : '')
                . ($n === 0 && !$isCurrent ? ' is-empty' : '') . '"'
                . ($isCurrent ? ' aria-current="page"' : '')
                . ' href="' . esc_url($state->link([$key => $value === '' ? null : $value])) . '">'
                . '<span class="tmc-filter__label">' . esc_html((string) $label) . '</span>'
                . '<span class="tmc-filter__count">' . esc_html(self::fa($n)) . '</span></a></li>';
        }
        return $html . '</ul></nav>';
    }

    /**
     * The intake: a shop picker, a preview and an import — under the rows they
     * act on.
     *
     * **Three things this deliberately does not do.** It does not ask for a
     * user id: a manager knows a shop by its name and the wrong number is
     * somebody else's catalogue («انتخاب فروشنده با جست‌وجوی نام فروشگاه
     * باشد»). It does not search as you type: the search is a submit, so it
     * works with JavaScript off and so what was typed survives the round trip
     * — the same trick the category picker has used since `alpha.25`. And it
     * does not offer a «select all»: the intake takes an explicit list of
     * ids, never «everything that scanned clean».
     */
    private static function intakeControls(CatalogueIntakeControls $intake): string
    {
        $html = '<div class="tmc-catalogue__intakebox">'
            . '<h3 class="tmc-card__subtitle">'
            . esc_html__('ورود محصول‌های انتخاب‌شده به بازارگاه', 'tecteb-marketplace-core') . '</h3>'
            . '<p class="tmc-hint">' . esc_html__('«ورود» یک ردیف بازارگاه می‌سازد که به همان محصول ووکامرس اشاره می‌کند، با مالکیت «مشاهده‌شده». محصول ووکامرس عوض نمی‌شود: شناسه، نشانی، وضعیت انتشار، نویسنده، قیمت، موجودی، تصویرها و سفارش‌های قبلی دست‌نخورده می‌مانند. پذیرش مدیریت بازارگاه اقدام جداگانه‌ای روی صفحهٔ خودِ محصول است.', 'tecteb-marketplace-core') . '</p>';

        // The shop search: its own GET-like submit inside the same form, which
        // is why the button has a name of its own. A manager who types a name
        // and presses it gets the matches back with everything they had
        // already ticked still ticked, because the whole form is posted.
        $html .= '<div class="tmc-field">'
            . '<label class="tmc-field__label" for="tmc-intake-shop">'
            . esc_html__('فروشگاهی که این محصول‌ها به آن تعلق دارند — با نام جست‌وجو کنید:', 'tecteb-marketplace-core')
            . '</label>'
            . '<input class="tmc-input" type="search" id="tmc-intake-shop" name="shop_q"'
            . ' value="' . esc_attr($intake->shopQuery) . '"'
            . ' placeholder="' . esc_attr__('نام فروشگاه…', 'tecteb-marketplace-core') . '">'
            . ' <button type="submit" class="tmc-button" name="intake_action" value="find_shop">'
            . esc_html__('جست‌وجوی فروشگاه', 'tecteb-marketplace-core') . '</button></div>';

        if ($intake->shopQuery !== '' && $intake->shopMatches === []) {
            $html .= '<p class="tmc-catalogue__scope">'
                . esc_html__('فروشگاهی با این نام پیدا نشد. محصولی که فروشنده‌اش معلوم نیست وارد نمی‌شود و دلیلش گفته می‌شود.', 'tecteb-marketplace-core')
                . '</p>';
        }
        if ($intake->shopMatches !== []) {
            $html .= '<fieldset class="tmc-fieldset"><legend>'
                . esc_html__('کدام فروشگاه؟', 'tecteb-marketplace-core') . '</legend>';
            foreach ($intake->shopMatches as $shop) {
                $id = (int) $shop['vendor_user_id'];
                $html .= '<label class="tmc-choice"><input type="radio" name="assign_to"'
                    . ' value="' . esc_attr((string) $id) . '"'
                    . ($id === $intake->assignTo ? ' checked' : '') . '> '
                    . esc_html((string) $shop['store_name'])
                    . ' <span class="tmc-card__note">' . esc_html(sprintf(
                        /* translators: 1: user id, 2: where the name was found */
                        __('کاربر #%1$s · %2$s', 'tecteb-marketplace-core'),
                        self::fa($id),
                        self::shopSourceLabel((string) $shop['source'])
                    )) . '</span></label>';
            }
            if ($intake->tooManyShopMatches) {
                $html .= '<p class="tmc-card__note">'
                    . esc_html__('فروشگاه‌های بیشتری با این نام هست. عبارت را دقیق‌تر کنید.', 'tecteb-marketplace-core')
                    . '</p>';
            }
            $html .= '</fieldset>';
        } elseif ($intake->assignTo > 0) {
            // The choice survives a page that is not showing the search
            // results any more: without this the manager picks a shop, ticks
            // ten products, and the import arrives with no vendor.
            $html .= '<input type="hidden" name="assign_to" value="'
                . esc_attr((string) $intake->assignTo) . '">'
                . '<p class="tmc-card__note">' . esc_html(sprintf(
                    /* translators: %s: the chosen shop's name */
                    __('فروشگاه انتخاب‌شده: %s', 'tecteb-marketplace-core'),
                    $intake->assignName !== '' ? $intake->assignName : (string) self::fa($intake->assignTo)
                )) . '</p>';
        }

        return $html
            . '<p class="tmc-card__note">'
            . esc_html__('اگر فروشگاهی انتخاب نشود، محصولی که فروشنده‌اش از شواهد معلوم است وارد می‌شود و بقیه با ذکر دلیل رد می‌شوند. این افزونه هیچ‌وقت محصول فروشگاه اصلی را به مدیر واردشده یا یک فروشندهٔ تصادفی نسبت نمی‌دهد.', 'tecteb-marketplace-core')
            . '</p>'
            . '<p><button type="submit" class="tmc-button" name="intake_action" value="preview">'
            . esc_html__('پیش‌نمایش (هیچ نوشتنی)', 'tecteb-marketplace-core') . '</button> '
            . '<button type="submit" class="tmc-button tmc-button--primary" name="intake_action" value="import">'
            . esc_html__('ورود انتخاب‌شده‌ها', 'tecteb-marketplace-core') . '</button></p>'
            . '</div>';
    }

    private static function shopSourceLabel(string $source): string
    {
        return match ($source) {
            'settings' => __('تنظیمات فروشگاه', 'tecteb-marketplace-core'),
            'application' => __('پروندهٔ فروشندگی', 'tecteb-marketplace-core'),
            'profile' => __('پروفایل فروشگاه', 'tecteb-marketplace-core'),
            default => $source,
        };
    }

    /**
     * «نیازمند بررسی» — the one queue, and the one number.
     *
     * It is not an eighth chip, because the seven are the product's own
     * STATUS and this is a different question about the same product: a
     * published product carrying a proposal needs review and is still
     * published, and «وضعیت منتشرشدهٔ محصول را صرفاً برای نمایش در صف تغییر
     * نده» is exactly that distinction.
     *
     * The number and the link both come from `ReviewQueueSql::waiting()`, so
     * what the sentence counts is what the filter shows and what the red badge
     * counts. Until `alpha.48` the sentence counted pending REVISIONS and the
     * `submitted` chip counted submitted products, and the owner read the two
     * side by side: «در انتظار بررسی ۰» above a product whose own card said a
     * proposal was waiting. Both numbers were right about their own question;
     * there were two questions and the page did not say so.
     *
     * Rendered whenever there is something waiting — or when the filter is on,
     * so there is a way back off it.
     */
    private static function reviewFilter(ProductCatalogueState $state): string
    {
        if ($state->needsReview === 0 && !$state->onlyNeedsReview) {
            return '';
        }
        if ($state->onlyNeedsReview) {
            return '<p class="tmc-catalogue__scope">'
                . esc_html__('فهرست به محصول‌هایی محدود شده که نیازمند بررسی‌اند: ارسال‌شده برای بررسی، یا نسخهٔ پیشنهادی بی‌پاسخ، یا درخواست برند باز.', 'tecteb-marketplace-core')
                . ' <a href="' . esc_url($state->link(['review' => null, 'revisions' => null])) . '">'
                . esc_html__('برداشتن این محدودیت', 'tecteb-marketplace-core') . '</a></p>';
        }
        return '<p class="tmc-catalogue__scope">'
            . esc_html(sprintf(
                /* translators: %s: how many products need a decision */
                __('%s محصول نیازمند بررسی است — ارسال‌شده، نسخهٔ پیشنهادی بی‌پاسخ (شامل درخواست موجودی) یا درخواست برند باز. هر محصول یک‌بار شمرده می‌شود.', 'tecteb-marketplace-core'),
                self::fa($state->needsReview)
            ))
            . ' <a href="' . esc_url($state->link(['review' => ProductCatalogueState::REVIEW_PENDING])) . '">'
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
            . self::carried($state, ['status', 'q', 'revisions', 'source', 'wc_status', 'management'])
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
            $state->onlyNeedsReview => __('هیچ محصولی نیازمند بررسی نیست.', 'tecteb-marketplace-core'),
            $state->status !== '' => __('در این وضعیت محصولی نیست.', 'tecteb-marketplace-core'),
            // Said separately from the status, because the three facets are
            // three questions and «هیچ محصولی نیازمند بررسی نیست» about a
            // management filter would send the manager to the wrong place.
            $state->source !== '' || $state->management !== '' || $state->wcStatus !== ''
                => __('با این فیلترها محصولی پیدا نشد.', 'tecteb-marketplace-core'),
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
            'review' => $state->onlyNeedsReview ? ProductCatalogueState::REVIEW_PENDING : '',
            'source' => $state->source,
            'wc_status' => $state->wcStatus,
            'management' => $state->management,
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
