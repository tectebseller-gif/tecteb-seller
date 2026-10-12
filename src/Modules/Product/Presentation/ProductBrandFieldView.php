<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation;

use Tecteb\Marketplace\Modules\Product\Domain\Brand;
use Tecteb\Marketplace\Modules\Product\Domain\BrandName;
use Tecteb\Marketplace\Modules\Product\Domain\BrandProposal;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;

/**
 * The brand: ONE field, typed, with the existing names suggested.
 *
 * **What this replaces, and why.** `alpha.48` built a picker — a radio list,
 * a search box with its own submit button, a separate «my brand is missing»
 * text box with its own submit button, and three explanatory paragraphs. Every
 * piece of it was answering a real question and the owner read the result and
 * said no: two fields, two buttons and a wall of prose for one word. So this
 * round is a subtraction, and the shape is the one the owner asked for — «یک
 * فیلد «برند» با امکان تایپ و پیشنهاد نام‌های موجود».
 *
 * **One field cannot mean two things, so the save decides.** Typing a name
 * that is in the list CHOOSES that brand; typing one that is not RECORDS a
 * request for it and leaves the product's brand alone. The vendor presses the
 * ordinary «ذخیره و ادامه» either way and is told which of the two happened.
 * `ProductArea::brandFromPost()` is where that decision is written down, and
 * `ManageBrands::refusalFor()` is still the one rule every other door asks —
 * a vendor naming an unapproved brand does not put it in anybody's list.
 *
 * **Suggestions without JavaScript.** A `<datalist>` is the browser's own
 * typeahead: the names are in the page, the filtering as you type is the
 * browser's, and nothing here needs a script to work. The script that IS here
 * only widens that list — `BrandSuggest` returns the options for what has
 * been typed, so a shop with more brands than one page of the datalist can
 * hold still finds them. Turn the script off and the seeded names and the
 * typing both still work.
 *
 * **A brand the row already holds is simply the value in the box.** Every
 * product on the owner's shop has a free-text brand today. It is rendered as
 * what it is, saving without touching it is unchanged (`refusalFor()` never
 * re-judges a value equal to the stored one), and the vendor can read it —
 * which the radio list made oddly hard.
 */
final class ProductBrandFieldView
{
    /** How many names the datalist carries on first paint. */
    public const SUGGEST = 40;

    /** The datalist's id, referenced by the input's `list` attribute. */
    private const LIST_ID = 'tv-brand-names';

    /**
     * @param string          $value       what the box shows: the vendor's own
     *                                     typing after a refused save, else the
     *                                     name they have asked for, else the
     *                                     brand the row holds
     * @param list<Brand>     $suggestions names for the datalist
     * @param bool            $listMissing WooCommerce is not running, so there
     *                                     is no list to check a name against
     * @param ?BrandProposal  $open        the request waiting on this product
     */
    public static function render(
        string $value,
        array $suggestions,
        bool $listMissing = false,
        ?BrandProposal $open = null,
        string $suggestUrl = '',
        string $suggestNonce = '',
        string $suggestAction = ''
    ): string {
        $html = '<div class="tv-field">'
            . '<label class="tv-label" for="f-brand">' . esc_html__('برند', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tv-input" type="text" id="f-brand" name="brand"'
            . ' value="' . esc_attr($value) . '"'
            . ' list="' . self::LIST_ID . '"'
            . ' maxlength="' . esc_attr((string) BrandName::MAX_LENGTH) . '"'
            . ' autocomplete="off"'
            // Read by the script, and by the browser test that proves the
            // no-script path is the same path.
            . ' data-tmc-brand-field="1"'
            . ($suggestUrl === '' ? '' : ' data-suggest-url="' . esc_attr($suggestUrl) . '"'
                . ' data-suggest-nonce="' . esc_attr($suggestNonce) . '"'
                . ' data-suggest-action="' . esc_attr($suggestAction) . '"')
            . '>'
            . '<datalist id="' . self::LIST_ID . '" data-tmc-brand-list="1">'
            . self::options($suggestions)
            . '</datalist>';

        $status = self::status($listMissing, $open);
        return $html . ($status === '' ? '' : $status) . '</div>';
    }

    /**
     * The datalist's options on their own, so the endpoint and the first paint
     * share one renderer.
     *
     * Two renderers of one list are two lists, and the day they disagree the
     * one nobody tested wins (`alpha.26`, about this exact kind of list).
     *
     * @param list<Brand> $suggestions
     */
    public static function options(array $suggestions): string
    {
        $html = '';
        foreach (array_slice($suggestions, 0, self::SUGGEST) as $brand) {
            $html .= '<option value="' . esc_attr($brand->name) . '"></option>';
        }
        return $html;
    }

    /**
     * ONE short sentence, and only when there is something to say.
     *
     * The order is the order of what a vendor needs to know: that there is no
     * list at all beats what is waiting, and what is waiting beats the general
     * explanation. Four paragraphs of prose were what the owner rejected, so
     * the general case is one line and the rest are each one line.
     */
    private static function status(bool $listMissing, ?BrandProposal $open): string
    {
        if ($listMissing) {
            return VendorUi::notice('warn', __('فهرست برندها از ووکامرس خوانده می‌شود و ووکامرس فعال نیست. برند فعلی این محصول دست‌نخورده می‌ماند.', 'tecteb-marketplace-core'));
        }
        if ($open !== null) {
            return VendorUi::notice('info', sprintf(
                $open->outstanding() === 'apply_to_product'
                    /* translators: %s: the brand name */
                    ? __('برند «%s» تأیید و به فهرست اضافه شده؛ اعمال آن روی این محصول کامل نشده و مدیر دوباره تلاش می‌کند.', 'tecteb-marketplace-core')
                    /* translators: %s: the brand name */
                    : __('برند «%s» در انتظار تأیید مدیر است؛ تا آن موقع برند فعلی این محصول تغییر نمی‌کند.', 'tecteb-marketplace-core'),
                $open->name
            ));
        }
        return '<p class="tv-hint">'
            . esc_html__('نام‌های موجود هنگام تایپ پیشنهاد می‌شوند. نام تازه پس از تأیید مدیر به فهرست اضافه می‌شود.', 'tecteb-marketplace-core')
            . '</p>';
    }
}
