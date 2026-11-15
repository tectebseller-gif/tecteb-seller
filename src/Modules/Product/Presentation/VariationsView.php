<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Finance\Presentation\MoneyText;
use Tecteb\Marketplace\Modules\Product\Domain\ProductAttribute;
use Tecteb\Marketplace\Modules\Product\Domain\ProductVariation;
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
        ?StorefrontVariationSet $storefront = null
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
        // So when the shop has them, the shop's are shown, read-only, with
        // their real ids; and the «define an attribute» form is NOT offered,
        // because here it is an invitation to duplicate.
        if ($storefront !== null && $storefront->hasVariations()) {
            return $html . self::storefrontPanel($storefront, $fa) . '</fieldset>';
        }
        if ($storefront !== null && !$storefront->isRead()) {
            $html .= self::unreadNotice($storefront);
        }

        $html .= '<p class="tv-hint">' . esc_html__('محصول متغیر تا وقتی دست‌کم یک ترکیب فعال با قیمت نداشته باشد، آمادهٔ فروش شمرده نمی‌شود و ارسال نمی‌شود.', 'tecteb-marketplace-core') . '</p>';
        $html .= self::attributeList($attributes, $productId, $urls, $nonceField);
        $html .= self::attributeForm($productId, $urls, $nonceField);

        if ($attributes === []) {
            return $html . '</fieldset>';
        }
        $html .= self::variationList($variations, $attributes, $thumbnails, $productId, $urls, $nonceField, $fa);
        $html .= self::variationForm($attributes, $productId, $urls, $nonceField);
        return $html . '</fieldset>';
    }

    /** @param list<ProductAttribute> $attributes */
    private static function attributeList(array $attributes, int $productId, VendorUrls $urls, string $nonce): string
    {
        if ($attributes === []) {
            return VendorUi::notice('info', __('هنوز ویژگی‌ای تعریف نشده است. برای نمونه «اندازه» با گزینه‌های کوچک، متوسط و بزرگ.', 'tecteb-marketplace-core'));
        }
        $html = '<ul class="tv-attrs">';
        foreach ($attributes as $attribute) {
            $html .= '<li class="tv-attrs__item"><div class="tv-attrs__head">'
                . '<strong>' . esc_html($attribute->label) . '</strong> '
                . '<bdi class="tv-code">' . esc_html($attribute->key) . '</bdi></div>'
                . '<p>' . esc_html(implode('، ', $attribute->options)) . '</p>'
                . self::inlineForm($urls, $nonce, 'delete_attribute', [
                    'product_id' => (string) $productId,
                    'attribute_id' => (string) $attribute->id,
                ], __('حذف ویژگی', 'tecteb-marketplace-core'))
                . '</li>';
        }
        return $html . '</ul>';
    }

    private static function attributeForm(int $productId, VendorUrls $urls, string $nonce): string
    {
        return '<form method="post" action="' . esc_url($urls->products()) . '" class="tv-subform">'
            . $nonce
            . '<input type="hidden" name="tmc_vendor_action" value="save_attribute">'
            . '<input type="hidden" name="product_id" value="' . esc_attr((string) $productId) . '">'
            . '<h3 class="tv-subform__title">' . esc_html__('افزودن یا ویرایش ویژگی', 'tecteb-marketplace-core') . '</h3>'
            . VendorUi::input('attr_key', __('کلید (انگلیسی و ثابت)', 'tecteb-marketplace-core'), '', true, 'text', 'ltr')
            . VendorUi::input('attr_label', __('عنوان فارسی', 'tecteb-marketplace-core'), '')
            . VendorUi::textarea('attr_options', __('گزینه‌ها — هر گزینه در یک سطر', 'tecteb-marketplace-core'), '')
            . '<p class="tv-form__actions">' . VendorUi::submit(__('ذخیره ویژگی', 'tecteb-marketplace-core'), 'secondary') . '</p>'
            . '</form>';
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
                . self::stockForm($variation, $productId, $urls, $nonce)
                . self::inlineForm($urls, $nonce, 'delete_variation', [
                    'product_id' => (string) $productId,
                    'variation_id' => (string) $variation->id,
                ], __('حذف ترکیب', 'tecteb-marketplace-core'))
                . '</div></li>';
        }
        return $html . '</ul>';
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
            . '<h3 class="tv-subform__title">' . esc_html__('افزودن یا ویرایش ترکیب', 'tecteb-marketplace-core') . '</h3>';
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
     * The shop's own combinations, read and shown — and nothing more.
     *
     * No form, no button, no id of ours: there is nothing on this panel a
     * press could write, which is how «از ویرایش تنوعِ پرخطر پیشگیری کن» is
     * enforced rather than promised. What the vendor gets is the thing they
     * could not see at all before: which combinations exist, what each costs,
     * what WooCommerce says about its stock, and the real id to quote.
     *
     * Editing these from the marketplace panel is a separate, later step and
     * the sentence at the bottom says so — including WHERE the change can be
     * made today, because a screen that only says «you cannot» teaches people
     * to look for a way around it.
     */
    private static function storefrontPanel(StorefrontVariationSet $set, callable $fa): string
    {
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
            __('ترکیب‌های این محصول در ووکامرس ثبت شده‌اند: %s ترکیب. این فهرست فقط خوانده می‌شود.', 'tecteb-marketplace-core'),
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
                . '</dl></div></li>';
        }
        $html .= '</ul>';

        return $html . VendorUi::notice('info', __('تغییر قیمت یا موجودی این ترکیب‌ها فعلاً از ووکامرس انجام می‌شود؛ ویرایش آن‌ها از این پنل مرحلهٔ بعدی مهاجرت است. ساختن ترکیب تازه در این پنل، نسخهٔ دومی از همین ترکیب‌ها می‌سازد، پس در دسترس نیست.', 'tecteb-marketplace-core'));
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
