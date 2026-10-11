<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Product\Domain\Brand;
use Tecteb\Marketplace\Modules\Product\Domain\BrandName;
use Tecteb\Marketplace\Modules\Product\Domain\BrandProposal;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;

/**
 * Choosing a brand from the manager's list, or asking for one that is missing.
 *
 * The same two-layer shape as the category picker, and for the same reason:
 * the search button SUBMITS THE FORM, so a vendor who searches does not lose
 * the title they have just typed, and nothing here needs JavaScript to work.
 * There is no suggest endpoint for brands in this round; a brand list is tens
 * of names, not the 1,070 categories that made one worth building.
 *
 * **Free text is not an option, and that is the feature.** §4: «متن آزاد نباید
 * برند رسمی تکراری بسازد». So the only way to name a brand is to pick one, and
 * the only way to add one is to ASK — a box that records a request and changes
 * nothing about the product until a manager agrees.
 *
 * **A brand the row already holds is shown even when it is not in the list.**
 * Every product on the owner's shop has a free-text brand today, and a picker
 * that silently dropped it would make «save» mean «remove the brand». It is
 * rendered as the current choice, labelled as from before the list, and it is
 * what the form posts unless the vendor picks something else.
 */
final class ProductBrandPickerView
{
    /** How many brands one page of the picker shows. */
    public const SHOW = 40;

    /**
     * @param list<Brand> $results
     */
    public static function render(
        string $currentBrand,
        ?Brand $selected,
        array $results,
        string $query,
        int $total,
        bool $listMissing = false,
        ?BrandProposal $openRequest = null
    ): string {
        $html = '<div class="tv-field tv-brandpick">'
            . '<span class="tv-label" id="tv-brandpick-label">' . esc_html__('برند', 'tecteb-marketplace-core') . '</span>';

        if ($listMissing) {
            // Honest rather than silent: with no list there is nothing to
            // check a name against, and accepting one would create exactly
            // the duplicate official brands this screen exists to prevent.
            return $html . VendorUi::notice(
                'warn',
                __('فهرست برندها از ووکامرس خوانده می‌شود و ووکامرس فعال نیست، پس برند قابل انتخاب یا درخواست نیست. برند فعلی این محصول دست‌نخورده می‌ماند.', 'tecteb-marketplace-core')
            ) . self::keepCurrent($currentBrand) . '</div>';
        }

        $html .= '<p class="tv-hint">'
            . esc_html(sprintf(
                /* translators: %s: how many approved brands there are */
                __('برند را از فهرست مدیر انتخاب کنید — همین حالا %s برند تأییدشده. نوشتن نام آزاد ممکن نیست؛ اگر برند شما در فهرست نیست، پایین همین بخش درخواست افزودنش را ثبت کنید.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian(number_format_i18n($total))
            ))
            . '</p>';

        $html .= self::chosen($currentBrand, $selected);

        $html .= '<div class="tv-brandpick__search">'
            . '<label class="tv-sr-only" for="f-brand_q">' . esc_html__('جست‌وجوی برند', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tv-input" type="search" id="f-brand_q" name="brand_q" value="' . esc_attr($query) . '"'
            . ' autocomplete="off" placeholder="' . esc_attr__('نام برند را بنویسید…', 'tecteb-marketplace-core') . '">'
            // The no-JavaScript path, and also the way out when nothing else
            // works. It is a submit, so the rest of the form is saved with it.
            . '<button type="submit" name="search_brand" value="1" class="tv-btn tv-btn--secondary">'
            . esc_html__('جست‌وجوی برند', 'tecteb-marketplace-core') . '</button>'
            . '</div>';

        $html .= '<div id="tv-brandpick-results" class="tv-brandpick__results">'
            . self::results($results, $selected, $currentBrand, $query)
            . '</div>';

        return $html . self::requestBox($openRequest) . '</div>';
    }

    /**
     * The result list on its own.
     *
     * @param list<Brand> $results
     */
    public static function results(array $results, ?Brand $selected, string $currentBrand, string $query): string
    {
        $rows = [];
        foreach ($results as $brand) {
            // The selection is shown above; repeating it here would ask the
            // vendor to notice that two identical rows are one row.
            if ($selected !== null && $selected->termId === $brand->termId) {
                continue;
            }
            $rows[] = $brand;
        }
        if ($rows === []) {
            return VendorUi::notice('info', $query === ''
                ? __('برای دیدن برندها جست‌وجو کنید.', 'tecteb-marketplace-core')
                : __('برندی با این نام در فهرست مدیر نیست. بخشی از نام را بنویسید و دوباره جست‌وجو کنید، یا پایین همین بخش افزودنش را درخواست کنید.', 'tecteb-marketplace-core'));
        }
        $html = '<ul class="tv-brandpick__list" role="group" aria-labelledby="tv-brandpick-label">';
        foreach (array_slice($rows, 0, self::SHOW) as $brand) {
            $html .= self::row($brand, false);
        }
        return $html . '</ul>';
    }

    /**
     * What is chosen right now, outside the search and always rendered.
     *
     * Three cases, and the third is the one the owner's shop is full of: a
     * brand that is on the row and in no list. It keeps its radio, so saving
     * while a search is on screen cannot clear it, and it says what it is.
     */
    private static function chosen(string $currentBrand, ?Brand $selected): string
    {
        if ($selected !== null) {
            return '<div class="tv-brandpick__chosen">'
                . self::row($selected, true)
                . '</div>';
        }
        if (BrandName::display($currentBrand) === '') {
            return '<div class="tv-brandpick__chosen">'
                . '<label class="tv-brandpick__row"><input type="radio" name="brand_term" value="0" checked> '
                . esc_html__('بدون برند', 'tecteb-marketplace-core') . '</label></div>';
        }
        return '<div class="tv-brandpick__chosen">' . self::keepCurrent($currentBrand) . '</div>';
    }

    /**
     * The row that keeps a brand from before the registry.
     *
     * `value="keep"` rather than a term id, because there is no term: the
     * instruction is «leave the brand alone», which is the only instruction
     * that can be honest about a name nobody has approved.
     */
    private static function keepCurrent(string $currentBrand): string
    {
        $name = BrandName::display($currentBrand);
        if ($name === '') {
            return '';
        }
        return '<label class="tv-brandpick__row"><input type="radio" name="brand_term" value="keep" checked> '
            . '<strong>' . esc_html($name) . '</strong> <span class="tv-hint">'
            . esc_html__('(برند فعلی این محصول، از پیش از فهرست برندها — دست‌نخورده می‌ماند)', 'tecteb-marketplace-core')
            . '</span></label>';
    }

    private static function row(Brand $brand, bool $checked): string
    {
        return '<li class="tv-brandpick__item"><label class="tv-brandpick__row">'
            . '<input type="radio" name="brand_term" value="' . esc_attr((string) $brand->termId) . '"'
            . ($checked ? ' checked' : '') . '> '
            . ($checked ? '<strong>' . esc_html($brand->name) . '</strong>' : esc_html($brand->name))
            . '</label></li>';
    }

    /**
     * Asking for a brand the list does not have.
     *
     * A text box and a button, and the button is its own action — not a side
     * effect of saving the product, because a request is not a change to the
     * product and must not look like one. While a request is open the box is
     * replaced by what is waiting, with its status in words: «ثبت برند و اعمال
     * آن روی محصول» are two halves and the vendor is told which one is left.
     */
    private static function requestBox(?BrandProposal $open): string
    {
        if ($open !== null) {
            $waiting = $open->outstanding() === 'apply_to_product'
                ? __('برند «%s» به فهرست مدیر اضافه شده و اعمال آن روی این محصول کامل نشده است. مدیر می‌تواند دوباره تلاش کند؛ لازم نیست درخواست تازه‌ای ثبت کنید.', 'tecteb-marketplace-core')
                : __('درخواست افزودن برند «%s» ثبت شده و در انتظار بررسی مدیر است. تا تأیید، برند فعلی این محصول تغییر نمی‌کند.', 'tecteb-marketplace-core');
            return '<div class="tv-brandpick__request">'
                . VendorUi::notice('info', sprintf($waiting, $open->name))
                . '</div>';
        }
        return '<div class="tv-brandpick__request">'
            . '<label class="tv-label" for="f-brand_request">'
            . esc_html__('برند من در فهرست نیست', 'tecteb-marketplace-core') . '</label>'
            . '<p class="tv-hint">'
            . esc_html__('نام برند را دقیق بنویسید. فقط مدیر می‌تواند برند تازه را به فهرست اضافه کند؛ تا آن موقع برند فعلی این محصول تغییر نمی‌کند.', 'tecteb-marketplace-core')
            . '</p>'
            . '<input class="tv-input" type="text" id="f-brand_request" name="brand_request" value="" maxlength="'
            . esc_attr((string) BrandName::MAX_LENGTH) . '">'
            . '<button type="submit" name="request_brand" value="1" class="tv-btn tv-btn--secondary">'
            . esc_html__('ثبت درخواست برند', 'tecteb-marketplace-core') . '</button>'
            . '</div>';
    }
}
