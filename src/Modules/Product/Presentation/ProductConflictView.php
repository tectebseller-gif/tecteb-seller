<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Product\Domain\FieldSummary;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;

/**
 * What is on the row now, beside what the vendor typed — for the two refusals
 * that ask them to look before they press save again.
 *
 * «تفاوت را ببینید» was advice until this existed. The vendor was told their
 * save had been refused and that they should open another tab and compare
 * forty fields by eye; the ones that actually differed were not named. A
 * refusal that cannot be acted on is a refusal that gets pressed through.
 *
 * Only fields that DIFFER are listed. A table of thirty identical rows with
 * two changed ones buried in it hides the thing it was built to show.
 */
final class ProductConflictView
{
    /** A refused save: «these are the values you are about to overwrite». */
    public const PURPOSE_REFUSAL = 'refusal';

    /**
     * A pending proposal: «this is what you asked for, this is what buyers
     * still see».
     *
     * One renderer with one branch rather than a second table, because two
     * renderers of one list are two lists — and the day they disagree is the
     * day nobody can tell which is right (`alpha.26`). The branch covers the
     * heading and the empty sentence only; the difference logic is shared
     * exactly as it stands.
     */
    public const PURPOSE_PROPOSAL = 'proposal';

    /**
     * @param ProductDetails $typed what the vendor submitted, or proposed
     * @param ProductDetails $stored what the row holds right now
     */
    public static function render(
        ProductDetails $typed,
        ProductDetails $stored,
        string $purpose = self::PURPOSE_REFUSAL
    ): string {
        $proposal = $purpose === self::PURPOSE_PROPOSAL;
        $rows = self::differences($typed, $stored);
        if ($rows === []) {
            if ($proposal) {
                // A proposal whose every field now matches the published row.
                // It happens: a manager can make the same change by hand
                // while the proposal waits. Saying «just save again» here
                // would be advice about somebody else's screen.
                return '<p class="tv-hint">'
                    . esc_html__('نسخه پیشنهادی شما با نسخه منتشرشده هیچ تفاوتی ندارد؛ تا تصمیم مدیر همین‌طور می‌ماند.', 'tecteb-marketplace-core')
                    . '</p>';
            }
            // Refused on the stamp, but the values match: nothing to weigh up,
            // and saying «compare these» over an empty table would be absurd.
            return '<p class="tv-hint">'
                . esc_html__('مقدارهای شما با آنچه روی محصول ثبت است فرقی ندارند؛ فقط دوباره ذخیره کنید.', 'tecteb-marketplace-core')
                . '</p>';
        }

        $html = '<div class="tv-conflict">'
            . '<h3 class="tv-conflict__title">' . esc_html(sprintf(
                /* translators: %s: how many fields differ */
                $proposal
                    ? __('%s فیلد در نسخه پیشنهادی شما با نسخه منتشرشده فرق دارد', 'tecteb-marketplace-core')
                    : __('%s فیلد با آنچه الان روی محصول ثبت است فرق دارد', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) count($rows))
            )) . '</h3>'
            . '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('تفاوت مقدارهای شما با مقدارهای ثبت‌شده', 'tecteb-marketplace-core') . '">'
            . '<table class="tv-table"><caption class="tv-visually-hidden">'
            . esc_html__('برای هر فیلد، مقدار ثبت‌شده و مقداری که شما نوشته‌اید', 'tecteb-marketplace-core')
            . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('فیلد', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html($proposal
                ? __('نسخه منتشرشده (آنچه خریدار می‌بیند)', 'tecteb-marketplace-core')
                : __('الان روی محصول', 'tecteb-marketplace-core')) . '</th>'
            . '<th scope="col">' . esc_html($proposal
                ? __('نسخه پیشنهادی شما', 'tecteb-marketplace-core')
                : __('آنچه شما نوشته‌اید', 'tecteb-marketplace-core')) . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $field => [$storedValue, $typedValue]) {
            $html .= '<tr>'
                . '<td>' . esc_html(ProductMessages::field($field)) . '</td>'
                . '<td>' . self::cell($storedValue, false) . '</td>'
                . '<td>' . self::cell($typedValue, true) . '</td>'
                . '</tr>';
        }
        // ⛔ «اقدام بعدی به زبان ساده» — AND IT WAS THE REFUSAL'S SENTENCE ON
        // BOTH. «The form below holds your values; if you want to keep the
        // stored one, type it and save» is true of a refused save and false of
        // a pending proposal: nothing is waiting to be re-saved, the manager
        // has the request, and saving again REPLACES it rather than resolving
        // it. One branch, the same shape as the heading above.
        return $html . '</tbody></table></div>'
            . '<p class="tv-hint">'
            . esc_html($proposal
                ? __('تا تصمیم مدیر، نسخهٔ منتشرشده برای خریدار تغییر نمی‌کند. اگر این درخواست را نمی‌خواهید، مقدار منتشرشده را در فرم بنویسید و ذخیره کنید؛ ذخیرهٔ دوباره جای همین درخواست را می‌گیرد.', 'tecteb-marketplace-core')
                : __('فرم پایین مقدارهای شماست. اگر درست‌اند، ذخیره کنید؛ اگر مقدار ثبت‌شده را می‌خواهید نگه دارید، همان را در فرم بنویسید و بعد ذخیره کنید.', 'tecteb-marketplace-core'))
            . '</p></div>';
    }

    /**
     * @return array<string,array{0:string,1:string}> field => [stored, typed]
     */
    private static function differences(ProductDetails $typed, ProductDetails $stored): array
    {
        $money = static fn (?int $minor): string => $minor === null || $minor <= 0
            ? ''
            : PersianDigits::toPersian(number_format($minor)) . ' ' . __('تومان', 'tecteb-marketplace-core');
        $number = static fn (int $n): string => PersianDigits::toPersian((string) $n);

        $pairs = [
            'title' => [$stored->title, $typed->title],
            // ⛔ THE FIELD THE VENDOR ACTUALLY CHANGED HAS TO BE IN THE LIST.
            //
            // The owner flipped a published product from simple to variable
            // and this table named one field: the long description. `type` is
            // a property of `ProductDetails`, it travels in the proposal
            // payload, and `ManageProducts::typeChangeRefusal()` lets it
            // through when the figures are ours — so a type change IS a
            // pending request. Worse than unnamed: with nothing else moved,
            // the table said «هیچ تفاوتی ندارد» about a proposal that asked
            // to restructure the product.
            'type' => [ProductMessages::type($stored->type), ProductMessages::type($typed->type)],
            'category' => [$stored->categoryKey, $typed->categoryKey],
            'brand' => [$stored->brand, $typed->brand],
            'price' => [$money($stored->priceMinor), $money($typed->priceMinor)],
            'salePriceMinor' => [$money($stored->salePriceMinor), $money($typed->salePriceMinor)],
            'sku' => [$stored->sku, $typed->sku],
            'stock' => [$number($stored->stock), $number($typed->stock)],
            'shortDescription' => [$stored->shortDescription, $typed->shortDescription],
            // `null` renders as empty here: this view is «yours versus theirs»
            // for a refused save, and «never had one» and «empty» look the
            // same to a person comparing two boxes of text.
            'description' => [$stored->description ?? '', $typed->description ?? ''],
        ];

        $out = [];
        foreach ($pairs as $field => [$storedValue, $typedValue]) {
            if ($storedValue !== $typedValue) {
                $out[$field] = [$storedValue, $typedValue];
            }
        }
        return $out;
    }

    /**
     * One value, in a form a person can read in a table cell.
     *
     * Short and plain goes in as it always did. Anything long, or anything
     * carrying markup, is FOLDED: a bounded line of its readable text, the
     * stored value's length, and the rest behind a native `<details>` that
     * needs no JavaScript. The markup itself is never printed on this screen.
     *
     * ⛔ `esc_html()` IS STILL THE BOUNDARY, on every path through here —
     * including the folded body, which is escaped and only then given its line
     * breaks. `FieldSummary` decides how much to show; it does not decide what
     * is safe, and the stored value is not altered by either of them. The
     * manager's screen keeps the full value, in wp-admin, where it belongs.
     */
    private static function cell(string $value, bool $mine): string
    {
        if ($value === '') {
            return '—';
        }
        if (!FieldSummary::needsFolding($value)) {
            return $mine ? '<strong>' . esc_html($value) . '</strong>' : esc_html($value);
        }
        $peek = FieldSummary::preview($value);
        $size = PersianDigits::toPersian(number_format(FieldSummary::characters($value)));
        return '<details class="tv-long' . ($mine ? ' tv-long--mine' : '') . '">'
            . '<summary class="tv-long__head">'
            . '<span class="tv-long__peek">' . esc_html($peek === ''
                ? __('متنی برای نمایش ندارد؛ فقط نشانه‌گذاری است.', 'tecteb-marketplace-core')
                : $peek) . '</span>'
            . '<span class="tv-long__size">' . esc_html(sprintf(
                FieldSummary::carriesMarkup($value)
                    /* translators: %s: how many characters the stored value has */
                    ? __('%s کاراکتر، شامل نشانه‌گذاری HTML', 'tecteb-marketplace-core')
                    : __('%s کاراکتر', 'tecteb-marketplace-core'),
                $size
            )) . '</span>'
            . '</summary>'
            . '<div class="tv-long__body">' . nl2br(esc_html(FieldSummary::text($value))) . '</div>'
            . '<p class="tv-hint">' . esc_html__('این متن برای خواندن و بدون نشانه‌گذاری نشان داده می‌شود. آنچه ذخیره شده دست‌نخورده است و مدیر نسخهٔ کامل را می‌بیند.', 'tecteb-marketplace-core') . '</p>'
            . '</details>';
    }
}
