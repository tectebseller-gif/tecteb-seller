<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Finance\Presentation\MoneyText;
use Tecteb\Marketplace\Modules\Product\Domain\ProductAttribute;
use Tecteb\Marketplace\Modules\Product\Domain\ProductVariation;
use Tecteb\Marketplace\Modules\Finance\Domain\DecimalAmount;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariation;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontVariationSet;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * The variable product's panel on step 2: its axes, and one row per sellable
 * combination with its own price, stock, SKU and picture.
 *
 * Each combination is its own small form rather than one giant grid, because
 * a grid of N×M inputs posted as one blob loses everything when one cell is
 * wrong — and this area is exactly where a vendor types the most.
 */
final class VariationsView
{
    /**
     * @param list<ProductAttribute> $attributes
     * @param list<ProductVariation> $variations
     * @param array<int,string> $thumbnails media id => url
     */
    public static function render(
        int $productId,
        array $attributes,
        array $variations,
        array $thumbnails,
        VendorUrls $urls,
        string $nonceField,
        ?StorefrontVariationSet $storefront = null,
        ?bool $rolesSupported = true,
        /** @var array{total?:?int,existing?:int,stale?:?int,missing?:?int,over?:bool,limit?:int} */
        array $preview = []
    ): string {
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $html = '<fieldset class="tv-fieldset"><legend>' . esc_html__('ویژگی‌ها و ترکیب‌ها', 'tecteb-marketplace-core') . '</legend>';

        if ($productId <= 0) {
            return $html . VendorUi::notice('info', __('اول محصول را ذخیره کنید، بعد ویژگی‌ها و ترکیب‌ها را اینجا تعریف کنید.', 'tecteb-marketplace-core'))
                . '</fieldset>';
        }

        // ⛔ THE SHOP'S COMBINATIONS, when the marketplace holds none.
        //
        // This is the screen the owner photographed: product #189 showed
        // «هنوز ویژگی‌ای تعریف نشده است» while WooCommerce held variations
        // #11966, #11965 and #11967 at ۵۰، ۱۰۰ and ۲۰۰ عددی. The sentence was
        // true about `tmc_product_attributes` and false about the product, and
        // a vendor reading it would conclude the combinations were lost and
        // build them again — producing a second set that the next projection
        // would push into the shop beside the first.
        //
        // So when the shop has them, the shop's are shown with their real
        // ids — and since §3 of the November instruction, with a form for
        // the two figures a vendor may change on each. The «define an
        // attribute» form is still NOT offered on this branch, because
        // here it is an invitation to duplicate.
        if ($storefront !== null && $storefront->hasVariations()) {
            return $html . self::storefrontPanel($storefront, $productId, $urls, $nonceField, $fa) . '</fieldset>';
        }
        if ($storefront !== null && !$storefront->isRead()) {
            $html .= self::unreadNotice($storefront);
        }

        $html .= '<p class="tv-hint">' . esc_html__('محصول متغیر تا وقتی دست‌کم یک ترکیب فعال با قیمت نداشته باشد، آمادهٔ فروش شمرده نمی‌شود و ارسال نمی‌شود.', 'tecteb-marketplace-core') . '</p>';

        // ⛔ TWO NUMBERED SECTIONS, in the order §12 asks for: «نوع محصول ←
        // ویژگی‌ها ← ساخت تنوع‌ها ← ویرایش قیمت و موجودی هر تنوع». The second
        // one does not appear at all until the first has produced an axis,
        // because a «ساخت تنوع» button with nothing to vary along is a button
        // that can only fail.
        $html .= '<h3 class="tv-vsec__title">'
            . esc_html__('۱. ویژگی‌های محصول', 'tecteb-marketplace-core') . '</h3>';
        $html .= self::attributeList($attributes, $productId, $urls, $nonceField, $rolesSupported);
        $html .= self::attributeForm($productId, $urls, $nonceField, $rolesSupported);

        $html .= '<h3 class="tv-vsec__title">'
            . esc_html__('۲. ساخت و مدیریت تنوع‌ها', 'tecteb-marketplace-core') . '</h3>';
        $axes = ProductAttribute::axesOf($attributes);
        if ($axes === []) {
            // §4's own sentence, and the button is not drawn at all — «تا
            // زمانی که ویژگی قابل‌استفاده وجود ندارد، دکمه ایجاد تنوع فعال
            // نشود».
            return $html . VendorUi::notice('info', $attributes === []
                ? __('برای ساخت تنوع، ابتدا یک ویژگی تعریف کنید و گزینهٔ «استفاده برای ساخت تنوع» را فعال کنید.', 'tecteb-marketplace-core')
                : __('هیچ‌کدام از ویژگی‌های این محصول برای ساخت تنوع علامت نخورده‌اند. برای ساخت تنوع، گزینهٔ «استفاده برای ساخت تنوع» را روی دست‌کم یکی از آن‌ها فعال کنید.', 'tecteb-marketplace-core'))
                . '</fieldset>';
        }
        $html .= self::buildPanel($preview, $productId, $urls, $nonceField, $fa);
        $html .= self::variationList($variations, $attributes, $thumbnails, $productId, $urls, $nonceField, $fa);
        $html .= self::variationForm($axes, $productId, $urls, $nonceField);
        return $html . '</fieldset>';
    }

    /**
     * «این کار N ترکیب می‌سازد» — before anything is built.
     *
     * ⛔ **THE NUMBER COMES FROM THE SERVICE, NOT FROM THIS VIEW.** A count
     * computed here would be a second copy of the rule the generator enforces,
     * and the two would disagree the first time the cap moved (`alpha.78`).
     * `ManageVariations::previewCombinations()` writes nothing and is asked on
     * the render that draws this panel.
     *
     * Three shapes, because the vendor needs a different sentence in each:
     *
     *  - nothing missing → the set is complete and the button is NOT drawn.
     *    A button that can only answer «variations_already_built» is a button
     *    that teaches people to ignore buttons.
     *  - over the cap → the cap is STATED with its number and the button is
     *    not drawn, which is §4's «با پیام واضح امتناع کن». `total` is `null`
     *    for a product of four thirty-option axes, so the sentence names the
     *    limit rather than a figure it cannot print.
     *  - something to build → the count, the existing rows, and what pressing
     *    the button would add.
     *
     * ⛔ **AND THE PANEL NEVER CLAIMS THE RESULT.** It says what the generate
     * WOULD do; the sentence after the press comes from the service's own
     * answer (§7: «نمایش نتیجه واقعی عملیات، نه پیام موفقیت پیش از ذخیره»).
     *
     * @param array{total?:?int,existing?:int,stale?:?int,missing?:?int,over?:bool,limit?:int} $preview
     */
    private static function buildPanel(
        array $preview,
        int $productId,
        VendorUrls $urls,
        string $nonce,
        callable $fa
    ): string {
        if ($preview === []) {
            return '';
        }
        $limit = (int) ($preview['limit'] ?? 0);
        $existing = (int) ($preview['existing'] ?? 0);
        // `array_key_exists`, because `null` here means «more than this
        // marketplace will talk about» and is not the same answer as nought.
        $total = array_key_exists('total', $preview) ? $preview['total'] : null;
        $missing = array_key_exists('missing', $preview) ? $preview['missing'] : null;
        $stale = array_key_exists('stale', $preview) ? $preview['stale'] : null;
        $over = (bool) ($preview['over'] ?? false);

        $html = '<div class="tv-build"><h4 class="tv-build__title">'
            . esc_html__('ساخت خودکار ترکیب‌ها', 'tecteb-marketplace-core') . '</h4>';

        if ($over) {
            return $html . VendorUi::notice('warning', sprintf(
                /* translators: %s is the cap, in Persian digits. */
                __('ویژگی‌های انتخاب‌شده بیش از %s ترکیب می‌سازند و این بیشتر از سقف مجاز است. برای ساخت خودکار، تعداد گزینه‌های یکی از ویژگی‌ها را کم کنید یا ترکیب‌ها را یکی‌یکی از فرم پایین اضافه کنید.', 'tecteb-marketplace-core'),
                $fa($limit)
            )) . '</div>';
        }

        $html .= '<p class="tv-build__count">' . esc_html(sprintf(
            /* translators: 1: total combinations, 2: rows that match them. */
            __('ویژگی‌های انتخاب‌شده %1$s ترکیب می‌سازند و %2$s ترکیب از آن‌ها تا حالا ثبت شده است.', 'tecteb-marketplace-core'),
            $fa((int) $total),
            $fa($existing)
        )) . '</p>';

        // ⛔ THE ROWS THAT NO LONGER MATCH, NAMED AND NOT TOUCHED. Adding an
        // axis makes every earlier combination incomplete, and the first draft
        // of this panel counted them as «ثبت‌شده» — so it printed «۶ ترکیب
        // می‌سازند و ۳ ثبت شده» beside «۶ ترکیب تازه ساخته می‌شود», which does
        // not add up and was measured on a live render. They are a vendor's
        // own data: this says they are there and the vendor deletes them, if
        // they want to, from the list below. Nothing here removes anything.
        if (is_int($stale) && $stale > 0) {
            $html .= VendorUi::notice('warning', sprintf(
                /* translators: %s is how many stored combinations no longer match. */
                __('%s ترکیبِ ثبت‌شده با ویژگی‌های فعلی نمی‌خوانَد — این‌ها پیش از تغییر ویژگی‌ها ساخته شده‌اند. هیچ‌کدام حذف نشده است؛ اگر لازم نیستند، از فهرست پایین خودتان حذفشان کنید.', 'tecteb-marketplace-core'),
                $fa($stale)
            ));
        }

        if ($missing === null || $missing <= 0) {
            return $html . VendorUi::notice('success', __('همهٔ ترکیب‌های این ویژگی‌ها ثبت شده‌اند. چیزی برای ساختن نمانده است.', 'tecteb-marketplace-core'))
                . '</div>';
        }

        return $html
            . '<p class="tv-build__hint">' . esc_html(sprintf(
                /* translators: %s is how many rows the button would add. */
                __('با زدن این دکمه %s ترکیب تازه ساخته می‌شود. ترکیب‌های موجود دست نمی‌خورند و قیمت هر ترکیب تازه خالی می‌مانَد تا خودتان آن را بنویسید.', 'tecteb-marketplace-core'),
                $fa($missing)
            )) . '</p>'
            . self::inlineForm(
                $urls,
                $nonce,
                'build_variations',
                ['product_id' => (string) $productId],
                __('ساخت ترکیب‌های باقی‌مانده', 'tecteb-marketplace-core')
            )
            . '</div>';
    }

    /**
     * Every attribute as its own editable card, with what it is FOR on it.
     *
     * ⛔ **EACH CARD CARRIES ITS OWN PREFILLED FORM, AND THE IDS ARE UNIQUE.**
     * `VendorUi::input()` builds `id="f-{name}"`, so one shared helper across
     * N cards would print N elements under one id — the defect `alpha.57`
     * measured on a nonce whose name collided with a panel's own id, found by
     * a browser and by nothing else. The fields here are built by hand with
     * the attribute id in every id.
     *
     * Editing was possible before only by retyping the key in the bottom form
     * and hoping it matched; §3 asks for «ویژگی موجود را ویرایش کند» and this
     * is it.
     *
     * @param list<ProductAttribute> $attributes
     */
    private static function attributeList(
        array $attributes,
        int $productId,
        VendorUrls $urls,
        string $nonce,
        ?bool $rolesSupported
    ): string {
        if ($attributes === []) {
            return VendorUi::notice('info', __('هنوز ویژگی‌ای تعریف نشده است. برای نمونه «تعداد در بسته» با گزینه‌های ۵۰ عددی، ۱۰۰ عددی و ۲۰۰ عددی.', 'tecteb-marketplace-core'));
        }
        $html = '<ul class="tv-attrs">';
        foreach ($attributes as $attribute) {
            $html .= '<li class="tv-attrs__item"><div class="tv-attrs__head">'
                . '<strong>' . esc_html($attribute->label) . '</strong> '
                . '<bdi class="tv-code">' . esc_html($attribute->key) . '</bdi> '
                . VendorUi::chip(
                    $attribute->variation ? 'success' : 'neutral',
                    $attribute->variation
                        ? __('محور تنوع', 'tecteb-marketplace-core')
                        : __('فقط مشخصه', 'tecteb-marketplace-core')
                )
                . VendorUi::chip(
                    $attribute->visible ? 'success' : 'neutral',
                    $attribute->visible
                        ? __('در صفحهٔ محصول', 'tecteb-marketplace-core')
                        : __('پنهان در صفحهٔ محصول', 'tecteb-marketplace-core')
                )
                . '</div>'
                . '<p>' . esc_html(implode('، ', $attribute->options)) . '</p>'
                . self::attributeEditForm($attribute, $productId, $urls, $nonce, $rolesSupported)
                . self::inlineForm($urls, $nonce, 'delete_attribute', [
                    'product_id' => (string) $productId,
                    'attribute_id' => (string) $attribute->id,
                ], __('حذف ویژگی', 'tecteb-marketplace-core'))
                . '</li>';
        }
        return $html . '</ul>';
    }

    /** One attribute's own form, prefilled, inside a native `<details>`. */
    private static function attributeEditForm(
        ProductAttribute $attribute,
        int $productId,
        VendorUrls $urls,
        string $nonce,
        ?bool $rolesSupported
    ): string {
        $id = (string) $attribute->id;
        $labelId = 'f-attr-label-' . $id;
        $optionsId = 'f-attr-options-' . $id;

        return '<details class="tv-attrs__edit"><summary>'
            . esc_html__('ویرایش این ویژگی', 'tecteb-marketplace-core') . '</summary>'
            . '<form method="post" action="' . esc_url($urls->products()) . '" class="tv-subform">'
            . $nonce
            . '<input type="hidden" name="tmc_vendor_action" value="save_attribute">'
            . '<input type="hidden" name="product_id" value="' . esc_attr((string) $productId) . '">'
            // The key travels hidden and is NOT editable: it names this axis
            // in every combination already stored, and renaming it would
            // orphan each one of them.
            . '<input type="hidden" name="attr_key" value="' . esc_attr($attribute->key) . '">'
            . '<div class="tv-field"><label class="tv-label" for="' . esc_attr($labelId) . '">'
            . esc_html__('عنوان فارسی', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tv-input" type="text" id="' . esc_attr($labelId) . '" name="attr_label"'
            . ' value="' . esc_attr($attribute->label) . '"></div>'
            . '<div class="tv-field"><label class="tv-label" for="' . esc_attr($optionsId) . '">'
            . esc_html__('گزینه‌ها — هر گزینه در یک سطر', 'tecteb-marketplace-core') . '</label>'
            . '<textarea class="tv-input" id="' . esc_attr($optionsId) . '" name="attr_options" rows="3">'
            . esc_textarea(implode("\n", $attribute->options)) . '</textarea></div>'
            . self::roleControls($attribute->visible, $attribute->variation, $id, $rolesSupported)
            . '<p class="tv-form__actions">'
            . VendorUi::submit(__('ذخیرهٔ این ویژگی', 'tecteb-marketplace-core'), 'secondary')
            . '</p></form></details>';
    }

    /**
     * The two decisions §3 asks for — or the named reason they are not offered.
     *
     * ⛔ **NOT OFFERED IS NOT THE SAME AS OFFERED AND IGNORED.** When the
     * store cannot record a role — this release's files reached the site
     * before migration 40 did (`alpha.67`), or the catalogue could not be read
     * (`alpha.68`) — the ticks are replaced by a sentence and two hidden
     * inputs carrying the values that ARE in force. A vendor is never shown a
     * control whose answer the service would refuse, and the form still posts
     * the truth rather than two silent noes.
     */
    private static function roleControls(
        bool $visible,
        bool $variation,
        string $suffix,
        ?bool $rolesSupported
    ): string {
        if ($rolesSupported !== true) {
            return '<input type="hidden" name="attr_visible" value="' . ($visible ? '1' : '') . '">'
                . '<input type="hidden" name="attr_variation" value="' . ($variation ? '1' : '') . '">'
                . '<p class="tv-hint">' . esc_html(
                    $rolesSupported === null
                        ? __('نقش ویژگی‌ها الان خواندنی نیست، پس این دو گزینه نمایش داده نمی‌شوند. مقدار فعلی دست‌نخورده می‌مانَد.', 'tecteb-marketplace-core')
                        : __('ساختار دادهٔ این سایت هنوز به‌روز نشده است، پس نقش ویژگی‌ها قابل ثبت نیست. پس از به‌روزرسانی، این دو گزینه ظاهر می‌شوند.', 'tecteb-marketplace-core')
                ) . '</p>';
        }
        // ⛔ EACH BOX IS ITS OWN `tv-field tv-field--check`, which is the
        // primitive `VendorUi::checkbox()` already writes and this stylesheet
        // already gives a 24px touch target. The first draft invented a
        // `tv-check` class instead and gave it no rule — `alpha.32` again, in
        // the round whose §12 asks for exactly that not to happen. Reusing the
        // primitive also means the two boxes line up with every other
        // checkbox on the form rather than almost lining up.
        //
        // `for`/`id` carry the attribute's own suffix because N cards render N
        // copies of this helper, and `VendorUi::input()`-style shared ids
        // would be N elements under one id (`alpha.57`).
        return self::roleBox(
            'attr_variation',
            'f-attr-variation-' . $suffix,
            __('استفاده برای ساخت تنوع', 'tecteb-marketplace-core'),
            __('اگر روشن باشد، خریدار از میان گزینه‌های این ویژگی یکی را انتخاب می‌کند.', 'tecteb-marketplace-core'),
            $variation
        ) . self::roleBox(
            'attr_visible',
            'f-attr-visible-' . $suffix,
            __('نمایش در صفحهٔ محصول', 'tecteb-marketplace-core'),
            __('اگر روشن باشد، این ویژگی در جدول مشخصات صفحهٔ محصول دیده می‌شود.', 'tecteb-marketplace-core'),
            $visible
        );
    }

    private static function roleBox(
        string $name,
        string $id,
        string $label,
        string $hint,
        bool $checked
    ): string {
        return '<div class="tv-field tv-field--check">'
            . '<label for="' . esc_attr($id) . '">'
            . '<input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1"'
            . checked($checked, true, false) . '> '
            . esc_html($label) . '</label>'
            . '<p class="tv-hint">' . esc_html($hint) . '</p>'
            . '</div>';
    }

    private static function attributeForm(
        int $productId,
        VendorUrls $urls,
        string $nonce,
        ?bool $rolesSupported
    ): string {
        return '<details class="tv-attrs__add"><summary>'
            . esc_html__('افزودن ویژگی تازه', 'tecteb-marketplace-core') . '</summary>'
            . '<form method="post" action="' . esc_url($urls->products()) . '" class="tv-subform">'
            . $nonce
            . '<input type="hidden" name="tmc_vendor_action" value="save_attribute">'
            . '<input type="hidden" name="product_id" value="' . esc_attr((string) $productId) . '">'
            . VendorUi::input('attr_label', __('عنوان فارسی ویژگی', 'tecteb-marketplace-core'), '', true, 'text', 'rtl',
                __('برای نمونه: تعداد در بسته', 'tecteb-marketplace-core'))
            . VendorUi::input('attr_key', __('کلید انگلیسی (اختیاری)', 'tecteb-marketplace-core'), '', true, 'text', 'ltr',
                __('خالی بگذارید تا خودش ساخته شود. این کلید بعداً عوض نمی‌شود، چون نام این محور در همهٔ ترکیب‌هاست.', 'tecteb-marketplace-core'))
            . VendorUi::textarea('attr_options', __('گزینه‌ها — هر گزینه در یک سطر', 'tecteb-marketplace-core'), '')
            // A NEW attribute arrives with both roles on, which is what every
            // attribute written before this release means and what the
            // commonest case wants.
            . self::roleControls(true, true, 'new', $rolesSupported)
            . '<p class="tv-form__actions">' . VendorUi::submit(__('ذخیرهٔ ویژگی', 'tecteb-marketplace-core'), 'secondary') . '</p>'
            . '</form></details>';
    }

    /**
     * @param list<ProductVariation> $variations
     * @param list<ProductAttribute> $attributes
     * @param array<int,string> $thumbnails
     * @param callable(string|int):string $fa
     */
    private static function variationList(
        array $variations,
        array $attributes,
        array $thumbnails,
        int $productId,
        VendorUrls $urls,
        string $nonce,
        callable $fa
    ): string {
        if ($variations === []) {
            return VendorUi::notice('warning', __('هیچ ترکیبی ساخته نشده است. تا یک ترکیب با قیمت نداشته باشید، این محصول ارسال نمی‌شود.', 'tecteb-marketplace-core'));
        }
        $labels = [];
        foreach ($attributes as $attribute) {
            $labels[$attribute->key] = $attribute->label;
        }
        $html = '<ul class="tv-variations">';
        foreach ($variations as $variation) {
            $parts = [];
            foreach ($variation->attributes as $key => $value) {
                $parts[] = ($labels[$key] ?? $key) . ': ' . $value;
            }
            $thumb = $thumbnails[$variation->mediaId] ?? '';
            $html .= '<li class="tv-variations__item">'
                . ($thumb !== '' ? '<img src="' . esc_url($thumb) . '" alt="" width="64" height="64" loading="lazy">' : '')
                . '<div class="tv-variations__body">'
                . '<strong>' . esc_html(implode(' · ', $parts)) . '</strong>'
                . '<dl class="tv-product__facts">'
                . '<div><dt>' . esc_html__('قیمت', 'tecteb-marketplace-core') . '</dt><dd>'
                . esc_html($variation->priceMinor > 0
                    ? sprintf(__('%s تومان', 'tecteb-marketplace-core'), $fa(number_format($variation->priceMinor)))
                    : __('تعیین‌نشده', 'tecteb-marketplace-core'))
                . '</dd></div>'
                . '<div><dt>' . esc_html__('موجودی', 'tecteb-marketplace-core') . '</dt><dd>'
                . esc_html($variation->stock > 0 ? $fa($variation->stock) : __('ناموجود', 'tecteb-marketplace-core')) . '</dd></div>'
                . '<div><dt>' . esc_html__('کد SKU', 'tecteb-marketplace-core') . '</dt><dd><bdi>'
                . esc_html($variation->sku !== '' ? $variation->sku : '—') . '</bdi></dd></div>'
                . '<div><dt>' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</dt><dd>'
                . esc_html($variation->enabled ? __('فعال', 'tecteb-marketplace-core') : __('غیرفعال', 'tecteb-marketplace-core'))
                . '</dd></div>'
                . '</dl>'
                // Both, on purpose: stock is the edit a vendor makes most and
                // it keeps its one-click box, while everything else lives in
                // the card. Removing a control that works would be the
                // «بازنویسی سلیقه‌ای» the first rule forbids.
                . self::stockForm($variation, $productId, $urls, $nonce)
                . self::variationEditForm($variation, $labels, $productId, $urls, $nonce)
                . self::inlineForm($urls, $nonce, 'delete_variation', [
                    'product_id' => (string) $productId,
                    'variation_id' => (string) $variation->id,
                ], __('حذف ترکیب', 'tecteb-marketplace-core'))
                . '</div></li>';
        }
        return $html . '</ul>';
    }

    /**
     * §5: ONE CARD PER COMBINATION, prefilled, addressed by the combination
     * this row already holds.
     *
     * ⛔ WHAT THIS REPLACES, AND WHY IT WAS BLOCKING. Until this card the
     * only per-row control was `stockForm()` — one field, `stock` — and the
     * only way to reach a price was the single «افزودن یا ویرایش ترکیب» form
     * at the bottom of the section, whose axis dropdowns open on «— انتخاب
     * کنید —» and whose price, sale, SKU and stock boxes open EMPTY. So
     * editing one combination meant re-picking every axis and retyping every
     * number from nothing, with no way to see what the row currently held.
     *
     * Measured on the real page after pressing the generate button: the only
     * field names under a combination were `stock`, and the only actions
     * `save_variation_stock` and `delete_variation`. A generated row's price
     * could not be written from this panel at all — and since `alpha.109`'s
     * projector guard keeps an unpriced row out of the shop, that made the
     * vendor's path stop dead exactly where §17 says it must not.
     *
     * ⛔ NO NEW VERB AND NO NEW SERVICE METHOD. `save_variation` has taken
     * price, sale price, SKU, stock, image and enabled since `alpha.6`, and
     * `saveVariation()` finds an existing row BY ITS COMBINATION — so the
     * combination travels as hidden inputs and the card is an edit of the row
     * it is printed under, not a second way to create one.
     *
     * ⛔ AND THE IDS ARE PER ROW, not from `VendorUi::input()`. That helper
     * builds `id="f-{name}"` from the field name, so N cards sharing it would
     * put N elements with one id on the page — the `alpha.57` defect, which
     * `wp_nonce_field()` caused once already. `stockForm()` right below
     * already writes `f-varstock-{id}`; this follows it rather than adding an
     * id parameter to a helper every vendor form shares.
     *
     * @param array<string,string> $labels attribute key => label
     */
    private static function variationEditForm(
        ProductVariation $variation,
        array $labels,
        int $productId,
        VendorUrls $urls,
        string $nonce
    ): string {
        $p = 'f-var' . $variation->id . '-';
        $html = '<details class="tv-vedit"><summary>'
            . esc_html__('ویرایش این ترکیب', 'tecteb-marketplace-core') . '</summary>'
            . '<form method="post" action="' . esc_url($urls->products()) . '" enctype="multipart/form-data" class="tv-subform">'
            . $nonce
            . '<input type="hidden" name="tmc_vendor_action" value="save_variation">'
            . '<input type="hidden" name="product_id" value="' . esc_attr((string) $productId) . '">';
        // The combination is fixed: this card edits the row it sits under.
        // Changing which options a row stands for is a delete and an add,
        // because a combination IS the row's identity here.
        foreach ($variation->attributes as $key => $value) {
            $html .= '<input type="hidden" name="variant[' . esc_attr((string) $key) . ']" value="' . esc_attr((string) $value) . '">';
        }
        $parts = [];
        foreach ($variation->attributes as $key => $value) {
            $parts[] = ($labels[$key] ?? $key) . ': ' . $value;
        }
        $html .= '<p class="tv-hint">' . esc_html(implode(' · ', $parts)) . '</p>'
            . self::field($p . 'price', 'var_price', __('قیمت این ترکیب (تومان)', 'tecteb-marketplace-core'),
                $variation->priceMinor > 0 ? (string) $variation->priceMinor : '')
            . self::field($p . 'sale', 'var_sale_price', __('قیمت با تخفیف (اختیاری)', 'tecteb-marketplace-core'),
                $variation->salePriceMinor === null ? '' : (string) $variation->salePriceMinor)
            . self::field($p . 'sku', 'var_sku', __('کد SKU این ترکیب', 'tecteb-marketplace-core'), $variation->sku)
            . self::field($p . 'stock', 'var_stock', __('موجودی', 'tecteb-marketplace-core'), (string) $variation->stock)
            . '<div class="tv-field"><label class="tv-label" for="' . esc_attr($p . 'image') . '">'
            . esc_html__('تصویر این ترکیب (اختیاری)', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tv-input" type="file" id="' . esc_attr($p . 'image') . '" name="variation_image"'
            . ' accept="image/jpeg,image/png,image/webp"></div>'
            // `VendorUi::checkbox()` wraps its input inside the label and so
            // carries no id at all — it is safe to repeat, and it is reused.
            . VendorUi::checkbox('var_enabled', __('این ترکیب فعال باشد', 'tecteb-marketplace-core'), $variation->enabled)
            . '<p class="tv-hint">'
            . esc_html__('وزن، ابعاد، کلاس ارسال، بازه تاریخ حراج، توضیح جداگانه و مدیریت موجودی برای هر ترکیب در این نسخه ذخیره نمی‌شوند؛ کادری برایشان نمی‌بینید تا مقداری ساختگی ثبت نشود. وزن و ابعاد محصول را از مرحله ۲ همین فرم بنویسید.', 'tecteb-marketplace-core')
            . '</p>'
            . '<p class="tv-form__actions">' . VendorUi::submit(__('ذخیره این ترکیب', 'tecteb-marketplace-core'), 'secondary') . '</p>'
            . '</form></details>';
        return $html;
    }

    /**
     * One labelled box whose id is ours rather than derived from the field
     * name, for the reason written on `variationEditForm()`.
     */
    private static function field(string $id, string $name, string $label, string $value): string
    {
        return '<div class="tv-field">'
            . '<label class="tv-label" for="' . esc_attr($id) . '">' . esc_html($label) . '</label>'
            . '<input class="tv-input" type="text" dir="ltr" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"'
            . ' value="' . esc_attr($value) . '">'
            . '</div>';
    }

    private static function stockForm(ProductVariation $variation, int $productId, VendorUrls $urls, string $nonce): string
    {
        $id = 'f-varstock-' . $variation->id;
        return '<form method="post" action="' . esc_url($urls->products()) . '" class="tv-inline tv-inline--stock">'
            . $nonce
            . '<input type="hidden" name="tmc_vendor_action" value="save_variation_stock">'
            . '<input type="hidden" name="product_id" value="' . esc_attr((string) $productId) . '">'
            . '<input type="hidden" name="variation_id" value="' . esc_attr((string) $variation->id) . '">'
            . '<label class="tv-label" for="' . esc_attr($id) . '">' . esc_html__('موجودی این ترکیب', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tv-input tv-input--short" type="text" id="' . esc_attr($id) . '" name="stock" dir="ltr" inputmode="numeric" value="'
            . esc_attr((string) $variation->stock) . '">'
            . VendorUi::submit(__('ذخیره موجودی', 'tecteb-marketplace-core'), 'secondary')
            . '</form>';
    }

    /** @param list<ProductAttribute> $attributes */
    private static function variationForm(array $attributes, int $productId, VendorUrls $urls, string $nonce): string
    {
        $html = '<form method="post" action="' . esc_url($urls->products()) . '" enctype="multipart/form-data" class="tv-subform">'
            . $nonce
            . '<input type="hidden" name="tmc_vendor_action" value="save_variation">'
            . '<input type="hidden" name="product_id" value="' . esc_attr((string) $productId) . '">'
            . '<h3 class="tv-subform__title">' . esc_html__('افزودن یک ترکیب دستی', 'tecteb-marketplace-core') . '</h3>';
        foreach ($attributes as $attribute) {
            $options = ['' => __('— انتخاب کنید —', 'tecteb-marketplace-core')];
            foreach ($attribute->options as $option) {
                $options[$option] = $option;
            }
            $html .= VendorUi::select('variant[' . $attribute->key . ']', $attribute->label, $options, '');
        }
        return $html
            . VendorUi::input('var_price', __('قیمت این ترکیب (تومان)', 'tecteb-marketplace-core'), '', true, 'text', 'ltr')
            . VendorUi::input('var_sale_price', __('قیمت با تخفیف (اختیاری)', 'tecteb-marketplace-core'), '', true, 'text', 'ltr')
            . VendorUi::input('var_sku', __('کد SKU این ترکیب', 'tecteb-marketplace-core'), '', true, 'text', 'ltr')
            . VendorUi::input('var_stock', __('موجودی', 'tecteb-marketplace-core'), '0', true, 'text', 'ltr')
            . '<div class="tv-field"><label class="tv-label" for="f-var-image">'
            . esc_html__('تصویر این ترکیب (اختیاری)', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tv-input" type="file" id="f-var-image" name="variation_image" accept="image/jpeg,image/png,image/webp"></div>'
            . VendorUi::checkbox('var_enabled', __('این ترکیب فعال باشد', 'tecteb-marketplace-core'), true)
            . '<p class="tv-form__actions">' . VendorUi::submit(__('ذخیره ترکیب', 'tecteb-marketplace-core'), 'secondary') . '</p>'
            . '</form>';
    }

    /** @param array<string,string> $fields */
    private static function inlineForm(VendorUrls $urls, string $nonce, string $action, array $fields, string $label): string
    {
        $html = '<form method="post" action="' . esc_url($urls->products()) . '" class="tv-inline">' . $nonce
            . '<input type="hidden" name="tmc_vendor_action" value="' . esc_attr($action) . '">';
        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
        return $html . VendorUi::submit($label, 'secondary') . '</form>';
    }

    /**
     * The shop's own combinations — read, shown, and from §3 of the owner's
     * instruction EDITABLE in exactly two figures.
     *
     * ⛔ **WHAT IS OFFERED HERE AND WHAT IS NOT.** Two forms per row: the
     * prices (regular and sale, as one unit — `alpha.83`) and the stock. There
     * is no «add a combination» form, no «delete» button and no axis form on
     * this branch, and that absence is the guard rather than a sentence about
     * one: the shop holds these children, so a combination made here would be
     * a SECOND set beside them (`alpha.105`). Creating combinations is §4's
     * door and goes through `tmc_product_variations` for a product the shop
     * holds none of.
     *
     * **Every form carries what the screen SHOWED.** `seen_regular`,
     * `seen_sale`, `seen_exponent` and `seen_stock` are the figures printed in
     * this very render, in minor units, so the service can refuse a write over
     * a value that moved since (`alpha.49`) — and the empty string is «no
     * figure of that kind», which is not nought.
     */
    private static function storefrontPanel(
        StorefrontVariationSet $set,
        int $productId,
        VendorUrls $urls,
        string $nonce,
        callable $fa
    ): string {
        $money = static function (?int $minor, int $exponent) use ($fa): string {
            if ($minor === null) {
                return __('نامشخص', 'tecteb-marketplace-core');
            }
            return sprintf(
                __('%s تومان', 'tecteb-marketplace-core'),
                MoneyText::format($minor, $exponent) ?? $fa(number_format($minor))
            );
        };

        $html = VendorUi::notice('info', sprintf(
            /* translators: %s: how many variations WooCommerce holds */
            __('ترکیب‌های این محصول در ووکامرس ثبت شده‌اند: %s ترکیب. قیمت و موجودی هر کدام را از همین‌جا می‌توانید تغییر دهید.', 'tecteb-marketplace-core'),
            $fa($set->count())
        ));
        if ($set->unreadableChildren > 0) {
            $html .= VendorUi::notice('warning', sprintf(
                /* translators: %s: how many children could not be read */
                __('%s ترکیب در ووکامرس هست که خوانده نشد. عددهای زیر دربارهٔ بقیه است، نه دربارهٔ همه.', 'tecteb-marketplace-core'),
                $fa($set->unreadableChildren)
            ));
        }

        $min = $set->minPriceMinor();
        $max = $set->maxPriceMinor();
        $html .= '<dl class="tv-product__facts">'
            . '<div><dt>' . esc_html__('بازهٔ قیمت', 'tecteb-marketplace-core') . '</dt><dd>' . esc_html(
                $min === null
                    ? __('هیچ ترکیبی قیمت خواندنی ندارد', 'tecteb-marketplace-core')
                    : ($min === $max
                        ? $money($min, $set->priceExponent)
                        : sprintf(
                            /* translators: 1: lowest price, 2: highest price */
                            __('از %1$s تا %2$s', 'tecteb-marketplace-core'),
                            $money($min, $set->priceExponent),
                            $money($max, $set->priceExponent)
                        ))
            ) . '</dd></div>'
            . '<div><dt>' . esc_html__('قابل خرید', 'tecteb-marketplace-core') . '</dt><dd>' . esc_html(sprintf(
                /* translators: 1: buyable variations, 2: variations read */
                __('%1$s از %2$s', 'tecteb-marketplace-core'),
                $fa($set->buyableCount()),
                $fa($set->count())
            )) . '</dd></div>'
            . '<div><dt>' . esc_html__('جمع موجودی', 'tecteb-marketplace-core') . '</dt><dd>' . esc_html(
                $set->totalStock() === null
                    ? __('به‌ازای تنوع — جمع‌شدنی نیست', 'tecteb-marketplace-core')
                    : $fa((int) $set->totalStock())
            ) . '</dd></div>'
            . '</dl>';

        $html .= '<ul class="tv-variations">';
        foreach ($set->variations as $variation) {
            $parts = [];
            foreach ($variation->attributes as $label => $value) {
                // ⛔ DECODED HERE, where it is printed, and nowhere else.
                // `sanitize_title()` percent-encodes Persian, so a custom
                // attribute the vendor typed as «اندازه» is stored as
                // `%d8%a7%d9%86%d8%af%d8%a7%d8%b2%d9%87` and was printed that
                // way. The stored key is untouched: it is what joins this
                // child to its parent's axis.
                $parts[] = AttributeLabel::forDisplay((string) $label)
                    . ': ' . AttributeLabel::forDisplay($value);
            }
            $html .= '<li class="tv-variations__item"><div class="tv-variations__body">'
                . '<strong>' . esc_html($parts === []
                    ? __('بدون ویژگی اعلام‌شده', 'tecteb-marketplace-core')
                    : implode(' · ', $parts)) . '</strong> '
                . VendorUi::chip(
                    $variation->isBuyable() ? 'success' : 'neutral',
                    $variation->isBuyable()
                        ? __('قابل خرید', 'tecteb-marketplace-core')
                        : __('قابل خرید نیست', 'tecteb-marketplace-core')
                )
                . '<dl class="tv-product__facts">'
                . '<div><dt>' . esc_html__('شناسهٔ ووکامرس', 'tecteb-marketplace-core') . '</dt><dd><bdi>'
                . esc_html($fa($variation->wcVariationId)) . '</bdi></dd></div>'
                . '<div><dt>' . esc_html__('قیمت', 'tecteb-marketplace-core') . '</dt><dd>'
                . esc_html($money($variation->currentPriceMinor, $set->priceExponent)) . '</dd></div>'
                . '<div><dt>' . esc_html__('موجودی', 'tecteb-marketplace-core') . '</dt><dd>'
                . esc_html(self::stockText($variation, $fa)) . '</dd></div>'
                . '<div><dt>' . esc_html__('کد SKU', 'tecteb-marketplace-core') . '</dt><dd><bdi>'
                . esc_html($variation->sku !== '' ? $variation->sku : '—') . '</bdi></dd></div>'
                . '<div><dt>' . esc_html__('وضعیت', 'tecteb-marketplace-core') . '</dt><dd>'
                . esc_html($variation->enabled
                    ? __('فعال', 'tecteb-marketplace-core')
                    : __('غیرفعال در ووکامرس', 'tecteb-marketplace-core')) . '</dd></div>'
                . '</dl>'
                . self::shopPriceForm($variation, $set->priceExponent, $productId, $urls, $nonce)
                . self::shopStockForm($variation, $productId, $urls, $nonce)
                . '</div></li>';
        }
        $html .= '</ul>';

        return $html . VendorUi::notice('info', __('قیمت و موجودی هر ترکیب را از همین‌جا می‌توانید تغییر دهید؛ تغییر روی همان ترکیب ووکامرس با همان شناسه ثبت می‌شود. ساختن یا حذف ترکیب در این پنل در دسترس نیست، چون ترکیب‌های این محصول در ووکامرس ثبت شده‌اند و ساختن دوباره‌شان نسخهٔ دومی می‌سازد.', 'tecteb-marketplace-core'));
    }

    /**
     * One real variation's two prices, as one form.
     *
     * ⛔ **THE TWO MOVE TOGETHER**, because `applySale()` has been called from
     * inside the price branch since `alpha.6` and `alpha.83` took all four
     * sale fields out of the approval cycle with the regular price for this
     * reason: a vendor who drops a sale and raises the price must not get the
     * raise live over a sale still running. Two separate buttons would be two
     * separate halves of one decision.
     *
     * The values are printed as the DECIMAL WooCommerce stores, at the shop's
     * own scale, so the box shows the vendor the figure that is actually on
     * the product. Typing is read back through `DecimalAmount` at the same
     * scale — exact or refused — and Persian digits are accepted.
     */
    private static function shopPriceForm(
        StorefrontVariation $variation,
        int $exponent,
        int $productId,
        VendorUrls $urls,
        string $nonce
    ): string {
        $text = static function (?int $minor) use ($exponent): string {
            // '' for «no price of that kind», which is the state WooCommerce
            // stores as an empty string and is not the same as nought.
            return $minor === null ? '' : (string) (DecimalAmount::toDecimal($minor, $exponent) ?? '');
        };
        $seen = static fn (?int $minor): string => $minor === null ? '' : (string) $minor;
        $id = $variation->wcVariationId;

        return '<form method="post" action="' . esc_url($urls->products()) . '" class="tv-inline">' . $nonce
            . '<input type="hidden" name="tmc_vendor_action" value="save_shop_variation_prices">'
            . '<input type="hidden" name="product_id" value="' . esc_attr((string) $productId) . '">'
            . '<input type="hidden" name="variation_id" value="' . esc_attr((string) $id) . '">'
            // WHAT THIS RENDER SHOWED, so the save can refuse over a figure
            // that moved in the meantime rather than overwrite it.
            . '<input type="hidden" name="seen_regular" value="' . esc_attr($seen($variation->regularPriceMinor)) . '">'
            . '<input type="hidden" name="seen_sale" value="' . esc_attr($seen($variation->salePriceMinor)) . '">'
            . '<input type="hidden" name="seen_exponent" value="' . esc_attr((string) $exponent) . '">'
            . '<label class="tv-label" for="f-var-regular-' . esc_attr((string) $id) . '">'
            . esc_html__('قیمت (تومان)', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tv-input tv-input--short" type="text" inputmode="decimal"'
            . ' id="f-var-regular-' . esc_attr((string) $id) . '" name="var_regular"'
            . ' value="' . esc_attr($text($variation->regularPriceMinor)) . '">'
            . '<label class="tv-label" for="f-var-sale-' . esc_attr((string) $id) . '">'
            . esc_html__('قیمت حراج (اختیاری)', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tv-input tv-input--short" type="text" inputmode="decimal"'
            . ' id="f-var-sale-' . esc_attr((string) $id) . '" name="var_sale"'
            . ' value="' . esc_attr($text($variation->salePriceMinor)) . '">'
            . VendorUi::submit(__('ذخیرهٔ قیمت', 'tecteb-marketplace-core'), 'secondary')
            . '</form>';
    }

    /**
     * One real variation's stock.
     *
     * `seen_stock` is EMPTY when this child keeps no number of its own — a
     * variation that inherits the parent's answer, which WooCommerce models as
     * «Manage stock?» unticked. Saving a number for such a child turns
     * management on FOR THE CHILD; the parent's flag is never touched, which
     * is the `alpha.106` protection and the §3 line «`manage_stock` والد
     * ناخواسته فعال نشود».
     */
    private static function shopStockForm(
        StorefrontVariation $variation,
        int $productId,
        VendorUrls $urls,
        string $nonce
    ): string {
        $id = $variation->wcVariationId;
        $managed = $variation->manageStock && $variation->stockQuantity !== null;

        return '<form method="post" action="' . esc_url($urls->products()) . '" class="tv-inline">' . $nonce
            . '<input type="hidden" name="tmc_vendor_action" value="save_shop_variation_stock">'
            . '<input type="hidden" name="product_id" value="' . esc_attr((string) $productId) . '">'
            . '<input type="hidden" name="variation_id" value="' . esc_attr((string) $id) . '">'
            . '<input type="hidden" name="seen_stock" value="'
            . esc_attr($managed ? (string) $variation->stockQuantity : '') . '">'
            . '<label class="tv-label" for="f-var-stock-' . esc_attr((string) $id) . '">'
            . esc_html__('موجودی', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tv-input tv-input--short" type="text" inputmode="numeric"'
            . ' id="f-var-stock-' . esc_attr((string) $id) . '" name="stock"'
            . ' value="' . esc_attr($managed ? (string) $variation->stockQuantity : '') . '">'
            . ($managed ? '' : '<p class="tv-hint">'
                . esc_html__('این ترکیب الان موجودی مستقل ندارد و از والد پیروی می‌کند. با ثبت یک عدد، موجودی خودش شمرده می‌شود.', 'tecteb-marketplace-core')
                . '</p>')
            . VendorUi::submit(__('ذخیرهٔ موجودی', 'tecteb-marketplace-core'), 'secondary')
            . '</form>';
    }

    /**
     * «نمی‌دانم», with the reason — four situations that used to arrive as one.
     *
     * A build with no WooCommerce is not a fault, a marketplace row with no
     * storefront product is a different fault from a read that failed, and a
     * simple product reaching this panel at all is a third. One sentence for
     * all four is what let «no variations» be printed about a product that
     * had three.
     */
    private static function unreadNotice(StorefrontVariationSet $set): string
    {
        $message = match ($set->state) {
            StorefrontVariationSet::NO_WOOCOMMERCE =>
                __('ووکامرس روی این سایت در دسترس نیست، پس ترکیب‌های سمت فروشگاه خوانده نشد.', 'tecteb-marketplace-core'),
            StorefrontVariationSet::NO_STOREFRONT_PRODUCT =>
                __('این محصول هنوز محصولی در ووکامرس ندارد، پس ترکیبی برای خواندن نیست.', 'tecteb-marketplace-core'),
            StorefrontVariationSet::NOT_VARIABLE =>
                __('محصول متناظر در ووکامرس متغیر نیست. تا این ناهماهنگی بررسی نشود، ترکیب‌ها از این پنل خوانده نمی‌شوند.', 'tecteb-marketplace-core'),
            default =>
                __('ترکیب‌های سمت فروشگاه خوانده نشد. این «ترکیبی ندارد» نیست؛ یعنی پاسخی نگرفتیم.', 'tecteb-marketplace-core'),
        };
        return VendorUi::notice(
            $set->state === StorefrontVariationSet::NO_WOOCOMMERCE ? 'info' : 'warning',
            $message
        );
    }

    /** @param callable(string|int):string $fa */
    private static function stockText(StorefrontVariation $variation, callable $fa): string
    {
        if ($variation->manageStock && $variation->stockQuantity !== null) {
            return $fa($variation->stockQuantity);
        }
        return match ($variation->stockStatus) {
            'instock' => __('موجود (ووکامرس عدد نگه نمی‌دارد)', 'tecteb-marketplace-core'),
            'outofstock' => __('ناموجود', 'tecteb-marketplace-core'),
            'onbackorder' => __('پیش‌خرید', 'tecteb-marketplace-core'),
            default => __('نامشخص', 'tecteb-marketplace-core'),
        };
    }
}
