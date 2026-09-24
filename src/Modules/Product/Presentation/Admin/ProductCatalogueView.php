<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation\Admin;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDecision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductMessages;

/**
 * Every product of every shop, in whatever state it is in.
 *
 * The manager's only list used to be «در انتظار بررسی», so a product they had
 * just approved, rejected or suspended left the marketplace admin entirely —
 * while its WooCommerce post sat there, findable, editable and unexplained.
 * The owner's words: «محصول پس از خروج از صف بررسی، از صفحهٔ مدیریت بازارگاه
 * ناپدید می‌شود، درحالی‌که نسخهٔ ووکامرس وجود دارد.»
 *
 * **Two statuses, side by side, and neither of them is invented.** The
 * marketplace status is the column the state machine writes. The shop status
 * is read from WordPress on the spot — `get_post_status()` — rather than
 * copied into a column of our own, because a mirrored status is a second
 * truth that goes wrong silently the first time somebody edits the post.
 */
final class ProductCatalogueView
{
    public const PER_PAGE = 20;

    /**
     * @param list<Product>               $products
     * @param array<string,int>           $counts     status value => how many, under the same filter
     * @param array<int,string>           $shopStatus product id => WooCommerce's own status ('' when none)
     * @param array<int,string>           $editorUrls product id => the WooCommerce editor
     * @param array<int,list<ProductDecision>> $history product id => newest decisions first
     * @param array<int,string>           $vendors    product id => the shop's name
     */
    public static function render(
        array $products,
        array $counts,
        string $currentStatus,
        string $search,
        int $page,
        int $total,
        string $baseUrl,
        array $shopStatus = [],
        array $editorUrls = [],
        array $history = [],
        array $vendors = []
    ): string {
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);

        $html = '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('همهٔ محصولات بازارگاه', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tmc-hint">' . esc_html__('وضعیت بازارگاه تصمیم این افزونه است و وضعیت ووکامرس همان چیزی است که وردپرس همین حالا دربارهٔ پست محصول می‌گوید. این دو عمداً جدا نشان داده می‌شوند و هیچ‌کدام از روی آن یکی ساخته نمی‌شود.', 'tecteb-marketplace-core') . '</p>';

        $html .= self::filters($counts, $currentStatus, $search, $baseUrl, $fa);

        if ($products === []) {
            return $html . '<p>' . esc_html(
                $search !== ''
                    ? __('چیزی با این عبارت پیدا نشد.', 'tecteb-marketplace-core')
                    : __('در این وضعیت محصولی نیست.', 'tecteb-marketplace-core')
            ) . '</p></section>';
        }

        $html .= '<div class="tmc-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('فهرست محصولات', 'tecteb-marketplace-core') . '">'
            . '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('محصول', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('فروشگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت بازارگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('وضعیت ووکامرس', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('آخرین تصمیم و سابقه', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($products as $product) {
            $html .= '<tr>'
                . '<th scope="row" data-label="' . esc_attr__('محصول', 'tecteb-marketplace-core') . '">'
                . esc_html($product->details->title !== '' ? $product->details->title : sprintf(
                    /* translators: %s: the product's id */
                    __('محصول %s', 'tecteb-marketplace-core'),
                    $fa($product->id)
                ))
                . '<br><span class="tmc-card__note">' . esc_html(sprintf(
                    /* translators: 1: marketplace id, 2: sku */
                    __('شناسهٔ بازارگاه: %1$s · SKU: %2$s', 'tecteb-marketplace-core'),
                    $fa($product->id),
                    $product->details->sku !== '' ? $product->details->sku : '—'
                )) . '</span></th>'
                . '<td data-label="' . esc_attr__('فروشگاه', 'tecteb-marketplace-core') . '">'
                . esc_html($vendors[$product->id] ?? ('#' . $fa($product->vendorUserId))) . '</td>'
                . '<td data-label="' . esc_attr__('وضعیت بازارگاه', 'tecteb-marketplace-core') . '">'
                . esc_html(ProductMessages::status($product->status)) . '</td>'
                . '<td data-label="' . esc_attr__('وضعیت ووکامرس', 'tecteb-marketplace-core') . '">'
                . self::shopCell($product, $shopStatus[$product->id] ?? '', $editorUrls[$product->id] ?? '')
                . '</td>'
                . '<td data-label="' . esc_attr__('آخرین تصمیم و سابقه', 'tecteb-marketplace-core') . '">'
                . self::historyCell($history[$product->id] ?? [], $fa)
                . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        return $html . self::pager($page, $total, $currentStatus, $search, $baseUrl, $fa) . '</section>';
    }

    /**
     * What WordPress says about the post, and a way into it.
     *
     * «پیوند ندارد» is a real and useful answer: a product waiting for review
     * has no storefront row until somebody prepares one, and saying so beats
     * an empty cell that reads like a bug.
     */
    private static function shopCell(Product $product, string $status, string $editorUrl): string
    {
        if (!$product->isProjected()) {
            return '<span class="tmc-card__note">' . esc_html__('هنوز در ووکامرس ساخته نشده', 'tecteb-marketplace-core') . '</span>';
        }
        $label = match ($status) {
            'publish' => __('منتشرشده', 'tecteb-marketplace-core'),
            'draft' => __('پیش‌نویس', 'tecteb-marketplace-core'),
            'pending' => __('در انتظار', 'tecteb-marketplace-core'),
            'private' => __('خصوصی', 'tecteb-marketplace-core'),
            'trash' => __('در زباله‌دان', 'tecteb-marketplace-core'),
            // The honest answer when the post the link points at is gone. It
            // is not the same as «پیش‌نویس», and reading it as one would hide
            // a broken link behind a plausible word.
            '' => __('پست پیدا نشد', 'tecteb-marketplace-core'),
            default => $status,
        };
        $out = esc_html($label);
        if ($editorUrl !== '') {
            $out .= '<br><a href="' . esc_url($editorUrl) . '">'
                . esc_html__('ویرایش در ووکامرس', 'tecteb-marketplace-core') . '</a>';
        }
        return $out;
    }

    /**
     * The trail, newest first, inside a `<details>` so the table stays a table.
     *
     * Native `<details>` rather than a script: the whole admin here renders no
     * executable markup, and a history that needs JavaScript to be read is a
     * history that is missing on the day JavaScript fails.
     *
     * @param list<ProductDecision> $rows
     * @param callable(string|int):string $fa
     */
    private static function historyCell(array $rows, callable $fa): string
    {
        if ($rows === []) {
            return '<span class="tmc-card__note">' . esc_html__('تصمیمی ثبت نشده', 'tecteb-marketplace-core') . '</span>';
        }
        $latest = $rows[0];
        $out = esc_html(self::decisionLabel($latest))
            . '<br><span class="tmc-card__note">' . esc_html($fa(substr($latest->createdAt, 0, 16))) . '</span>';
        if (trim($latest->note) !== '') {
            $out .= '<br><span class="tmc-card__note">«' . esc_html(self::shorten($latest->note)) . '»</span>';
        }
        if (count($rows) > 1) {
            $out .= '<details class="tmc-history"><summary>' . esc_html(sprintf(
                /* translators: %s: how many earlier decisions there are */
                __('%s تصمیم پیش از این', 'tecteb-marketplace-core'),
                $fa(count($rows) - 1)
            )) . '</summary><ul>';
            foreach (array_slice($rows, 1) as $row) {
                $out .= '<li>' . esc_html($fa(substr($row->createdAt, 0, 16)) . ' — ' . self::decisionLabel($row))
                    . (trim($row->note) !== '' ? ': «' . esc_html(self::shorten($row->note)) . '»' : '')
                    . '</li>';
            }
            $out .= '</ul></details>';
        }
        return $out;
    }

    private static function decisionLabel(ProductDecision $decision): string
    {
        return match ($decision->decision) {
            ProductDecision::SUBMITTED => __('ارسال برای بررسی', 'tecteb-marketplace-core'),
            ProductDecision::APPROVED => __('تأیید و انتشار', 'tecteb-marketplace-core'),
            ProductDecision::CHANGES_REQUESTED => __('نیازمند اصلاح', 'tecteb-marketplace-core'),
            ProductDecision::REJECTED => __('رد و بایگانی', 'tecteb-marketplace-core'),
            ProductDecision::SUSPENDED => __('تعلیق', 'tecteb-marketplace-core'),
            ProductDecision::RESTORED => __('بازگشت به پیش‌نویس', 'tecteb-marketplace-core'),
            ProductDecision::CORRECTED => __('اصلاح توسط مدیر', 'tecteb-marketplace-core'),
            ProductDecision::FIELD_KEPT => __('نسخهٔ ووکامرس ماند', 'tecteb-marketplace-core'),
            ProductDecision::FIELD_ACCEPTED => __('مقدار بازارگاه اعمال شد', 'tecteb-marketplace-core'),
            // Derived by migration 19 from the single old column. Labelled as
            // such because its DATE is inferred, not recorded.
            ProductDecision::IMPORTED => __('یادداشت قبلی (تاریخش دقیق نیست)', 'tecteb-marketplace-core'),
            default => $decision->decision,
        };
    }

    private static function shorten(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        return mb_strlen($text) > 120 ? mb_substr($text, 0, 120) . '…' : $text;
    }

    /** @param array<string,int> $counts @param callable(string|int):string $fa */
    private static function filters(array $counts, string $current, string $search, string $baseUrl, callable $fa): string
    {
        $html = '<form method="get" class="tmc-inline">'
            . '<input type="hidden" name="page" value="' . esc_attr(ProductReviewPage::SLUG) . '">'
            . '<label class="tmc-field__label" for="tmc-cat-q">'
            . esc_html__('جست‌وجو در عنوان، SKU و برند', 'tecteb-marketplace-core') . '</label> '
            . '<input class="tmc-input" type="search" id="tmc-cat-q" name="q" value="' . esc_attr($search) . '"> '
            . '<button type="submit" class="tmc-button">' . esc_html__('جست‌وجو', 'tecteb-marketplace-core') . '</button>'
            . ($search !== ''
                ? ' <a class="tmc-button tmc-button--ghost" href="' . esc_url($baseUrl) . '">'
                    . esc_html__('برداشتن جست‌وجو', 'tecteb-marketplace-core') . '</a>'
                : '')
            . '</form>';

        $all = array_sum($counts);
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
            $known += $counts[$status->value] ?? 0;
        }
        $unknown = $all - $known;

        $html .= '<nav class="tmc-tabs" aria-label="' . esc_attr__('وضعیت محصول', 'tecteb-marketplace-core') . '"><ul>';
        foreach ($tabs as $value => $label) {
            $n = $value === '' ? $all : ($counts[$value] ?? 0);
            $url = $value === '' ? $baseUrl : add_query_arg('status', $value, $baseUrl);
            if ($search !== '') {
                $url = add_query_arg('q', $search, $url);
            }
            $html .= '<li><a class="tmc-tab' . ($value === $current ? ' is-current' : '') . '"'
                . ($value === $current ? ' aria-current="page"' : '')
                . ' href="' . esc_url($url) . '">' . esc_html($label)
                . ' <span class="tmc-tab__count">' . esc_html($fa($n)) . '</span></a></li>';
        }
        if ($unknown > 0) {
            $html .= '<li><span class="tmc-tab is-warning">'
                . esc_html__('وضعیت ناشناخته', 'tecteb-marketplace-core')
                . ' <span class="tmc-tab__count">' . esc_html($fa($unknown)) . '</span></span></li>';
        }
        return $html . '</ul></nav>';
    }

    /** @param callable(string|int):string $fa */
    private static function pager(int $page, int $total, string $status, string $search, string $baseUrl, callable $fa): string
    {
        $pages = (int) ceil($total / self::PER_PAGE);
        if ($pages <= 1) {
            return '';
        }
        $link = static function (int $target) use ($status, $search, $baseUrl): string {
            $url = $baseUrl;
            if ($status !== '') {
                $url = add_query_arg('status', $status, $url);
            }
            if ($search !== '') {
                $url = add_query_arg('q', $search, $url);
            }
            return add_query_arg('paged', $target, $url);
        };
        $html = '<nav class="tmc-pager" aria-label="' . esc_attr__('صفحه‌بندی', 'tecteb-marketplace-core') . '">';
        if ($page > 1) {
            $html .= '<a class="tmc-button tmc-button--ghost" href="' . esc_url($link($page - 1)) . '">'
                . esc_html__('صفحهٔ قبل', 'tecteb-marketplace-core') . '</a> ';
        }
        $html .= '<span>' . esc_html(sprintf(
            /* translators: 1: current page, 2: how many pages, 3: how many rows in total */
            __('صفحهٔ %1$s از %2$s — %3$s محصول', 'tecteb-marketplace-core'),
            $fa($page),
            $fa($pages),
            $fa($total)
        )) . '</span>';
        if ($page < $pages) {
            $html .= ' <a class="tmc-button tmc-button--ghost" href="' . esc_url($link($page + 1)) . '">'
                . esc_html__('صفحهٔ بعد', 'tecteb-marketplace-core') . '</a>';
        }
        return $html . '</nav>';
    }
}
