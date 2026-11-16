<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Marketplace\Presentation;

use Tecteb\Marketplace\Modules\Marketplace\Domain\PriceTier;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;

/**
 * One product's wholesale ladder, on that product's own form.
 *
 * ⛔ **MOVED, NOT REBUILT.** Every sentence, every label, every hint and the
 * three-row shape are `SupportArea::tierSection()`'s own, lifted across — «از
 * صفحه «پشتیبانی» فقط رابط مربوط به قیمت‌گذاری پلکانی حذف شود» means the
 * interface travels, and «تکه کن، بازنویسی نکن» (`alpha.85`) means it travels
 * with the same words. `ManageWholesale` is not touched, `setTiers()` is not
 * touched, and nothing about how a ladder is stored or read changed, so there
 * is no old data to be compatible with: the rows are the same rows.
 *
 * **What is DELIBERATELY left behind.** The support page's product picker and
 * its «نمایش پلکان» button existed because that page is shop-wide and had to
 * ask which product it was editing. A product form already knows. Dropping
 * them is the whole point of the move, not a simplification of the feature.
 *
 * ⛔ **AND THE OBJECTION THE OLD PLACE RECORDED IS ANSWERED BY PLACEMENT.**
 * `SupportArea`'s docblock said the ladder belonged there because «a ladder is
 * replaced whole and the product form saves field by field — putting a
 * whole-replacement control inside a field-by-field form is how somebody ends
 * up deleting a step they never looked at». That is a real hazard and it is
 * about being INSIDE the form, not about being on the page. So this renders
 * its own `<form>`, outside the product's, exactly as the variations panel
 * does and for the reason written there: a product save cannot reach these
 * boxes, and saving the ladder still replaces it whole. An empty ladder form
 * still clears the ladder, and the hint still says so in words.
 */
final class WholesaleTiersView
{
    /** How many steps one ladder form offers — `SupportArea`'s own number. */
    public const ROWS = 3;

    /**
     * @param list<PriceTier>         $tiers
     * @param callable(string|int):string $fa
     */
    public static function render(
        int $productId,
        string $productTitle,
        int $priceMinor,
        array $tiers,
        string $nonceField,
        callable $fa,
        bool $mayEdit = true
    ): string {
        $html = '<section class="tv-card" id="tmc-wholesale-tiers"><h2 class="tv-card__title">'
            . esc_html__('قیمت پلکانی عمده', 'tecteb-marketplace-core') . '</h2>';

        // A product that has not been saved yet has no id to hang a ladder
        // on. The support page's equivalent branch said «هنوز محصولی ندارید»;
        // here the product exists in the vendor's head but not in the table,
        // so the sentence is about this one.
        if ($productId <= 0) {
            return $html . '<p class="tv-hint">'
                . esc_html__('ابتدا محصول را ذخیره کنید؛ پس از آن پلکان قیمتش را همین‌جا تعیین می‌کنید.', 'tecteb-marketplace-core')
                . '</p></section>';
        }

        $html .= '<p class="tv-hint">'
            . esc_html__('قیمت پلکانی را فقط خریدار عمدهٔ تأییدشده می‌بیند و فقط او در سبد خرید همان قیمت را می‌پردازد. هر پله از تعداد ۲ به بالا، و با افزایش تعداد باید قیمت هر واحد کمتر شود.', 'tecteb-marketplace-core')
            . '</p>'
            . '<p class="tv-hint">'
            . sprintf(
                /* translators: %s: the product's ordinary price, already formatted */
                esc_html__('قیمت عادی این محصول %s تومان است؛ هیچ پله‌ای نمی‌تواند از آن بیشتر باشد. ذخیرهٔ فرم خالی، پلکان این محصول را برمی‌دارد.', 'tecteb-marketplace-core'),
                esc_html($fa(number_format($priceMinor)))
            )
            . '</p>';

        if ($tiers !== []) {
            $html .= '<div class="tv-scroll" tabindex="0" role="region" aria-label="'
                . esc_attr__('پلکان فعلی قیمت', 'tecteb-marketplace-core') . '">'
                . '<table class="tv-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('از این تعداد به بالا', 'tecteb-marketplace-core') . '</th>'
                . '<th scope="col">' . esc_html__('قیمت هر واحد', 'tecteb-marketplace-core') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($tiers as $tier) {
                $html .= '<tr><td data-label="' . esc_attr__('از این تعداد به بالا', 'tecteb-marketplace-core') . '">'
                    . esc_html($fa((string) $tier->minQuantity)) . '</td>'
                    . '<td data-label="' . esc_attr__('قیمت هر واحد', 'tecteb-marketplace-core') . '">'
                    . esc_html($fa(number_format($tier->unitPriceMinor))) . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        // A reader sees the ladder and no boxes — the same split every other
        // vendor screen makes between `Read` and `Edit` on the Product area.
        if (!$mayEdit) {
            return $html . '<p class="tv-hint">'
                . esc_html__('برای تغییر پلکان قیمت، دسترسی ویرایش محصول لازم است.', 'tecteb-marketplace-core')
                . '</p></section>';
        }

        $html .= '<form method="post" class="tv-form">' . $nonceField
            . '<input type="hidden" name="tmc_vendor_action" value="set_tiers">'
            . '<input type="hidden" name="tier_product" value="' . esc_attr((string) $productId) . '">'
            . '<h3 class="tv-subtitle">' . esc_html($productTitle) . '</h3>';
        for ($row = 0; $row < self::ROWS; $row++) {
            $tier = $tiers[$row] ?? null;
            $html .= '<div class="tv-row">'
                . VendorUi::input(
                    'tier_qty[' . $row . ']',
                    sprintf(
                        /* translators: %s: the step's number, in Persian digits */
                        __('پلهٔ %s — از تعداد', 'tecteb-marketplace-core'),
                        $fa((string) ($row + 1))
                    ),
                    $tier !== null ? (string) $tier->minQuantity : '',
                    true,
                    'number',
                    'ltr'
                )
                . VendorUi::input(
                    'tier_price[' . $row . ']',
                    __('قیمت هر واحد (تومان)', 'tecteb-marketplace-core'),
                    $tier !== null ? (string) $tier->unitPriceMinor : '',
                    true,
                    'number',
                    'ltr'
                )
                . '</div>';
        }
        return $html . VendorUi::submit(__('ذخیرهٔ پلکان قیمت', 'tecteb-marketplace-core'))
            . '</form></section>';
    }
}
