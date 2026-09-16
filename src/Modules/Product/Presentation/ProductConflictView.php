<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
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
    /**
     * @param ProductDetails $typed what the vendor submitted
     * @param ProductDetails $stored what the row holds right now
     */
    public static function render(ProductDetails $typed, ProductDetails $stored): string
    {
        $rows = self::differences($typed, $stored);
        if ($rows === []) {
            // Refused on the stamp, but the values match: nothing to weigh up,
            // and saying «compare these» over an empty table would be absurd.
            return '<p class="tv-hint">'
                . esc_html__('مقدارهای شما با آنچه روی محصول ثبت است فرقی ندارند؛ فقط دوباره ذخیره کنید.', 'tecteb-marketplace-core')
                . '</p>';
        }

        $html = '<div class="tv-conflict">'
            . '<h3 class="tv-conflict__title">' . esc_html(sprintf(
                /* translators: %s: how many fields differ */
                __('%s فیلد با آنچه الان روی محصول ثبت است فرق دارد', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) count($rows))
            )) . '</h3>'
            . '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
            . esc_attr__('تفاوت مقدارهای شما با مقدارهای ثبت‌شده', 'tecteb-marketplace-core') . '">'
            . '<table class="tv-table"><caption class="tv-visually-hidden">'
            . esc_html__('برای هر فیلد، مقدار ثبت‌شده و مقداری که شما نوشته‌اید', 'tecteb-marketplace-core')
            . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('فیلد', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('الان روی محصول', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('آنچه شما نوشته‌اید', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $field => [$storedValue, $typedValue]) {
            $html .= '<tr>'
                . '<td>' . esc_html(ProductMessages::field($field)) . '</td>'
                . '<td>' . esc_html($storedValue === '' ? '—' : $storedValue) . '</td>'
                . '<td><strong>' . esc_html($typedValue === '' ? '—' : $typedValue) . '</strong></td>'
                . '</tr>';
        }
        return $html . '</tbody></table></div>'
            . '<p class="tv-hint">'
            . esc_html__('فرم پایین مقدارهای شماست. اگر درست‌اند، ذخیره کنید؛ اگر مقدار ثبت‌شده را می‌خواهید نگه دارید، همان را در فرم بنویسید و بعد ذخیره کنید.', 'tecteb-marketplace-core')
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
            'category' => [$stored->categoryKey, $typed->categoryKey],
            'brand' => [$stored->brand, $typed->brand],
            'price' => [$money($stored->priceMinor), $money($typed->priceMinor)],
            'salePriceMinor' => [$money($stored->salePriceMinor), $money($typed->salePriceMinor)],
            'sku' => [$stored->sku, $typed->sku],
            'stock' => [$number($stored->stock), $number($typed->stock)],
            'shortDescription' => [$stored->shortDescription, $typed->shortDescription],
        ];

        $out = [];
        foreach ($pairs as $field => [$storedValue, $typedValue]) {
            if ($storedValue !== $typedValue) {
                $out[$field] = [$storedValue, $typedValue];
            }
        }
        return $out;
    }
}
