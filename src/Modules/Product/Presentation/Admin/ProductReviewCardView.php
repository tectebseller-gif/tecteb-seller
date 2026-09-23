<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation\Admin;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\SpecTemplate;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontField;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductMessages;

/**
 * One product, as somebody deciding about it needs to see it.
 *
 * What was here until `alpha.25` was a fact list: «تصویر: ۳» and «دسته: 30».
 * Neither is a product. A counter says how many pictures exist and nothing
 * about whether they show the item; a term id says nothing at all to a person
 * who has 1,070 of them. The owner's words were exact — «شمارندهٔ تصویر و
 * شناسهٔ عددی دسته کافی نیست».
 *
 * So the card shows the pictures, both descriptions, the category's whole
 * path, every medical specification with its label and unit, the price and
 * stock as numbers a person reads, and which shop it came from.
 *
 * **It does not rebuild WooCommerce's editor, and it does not rebuild Rank
 * Math.** Both are on the product's own edit screen and both are better than
 * anything that would be written here; the card links straight into them and
 * says so. What it does own is the marketplace's side: the three fields a
 * manager corrects before approving, and the record of who owns each field
 * once WooCommerce and the vendor disagree.
 */
final class ProductReviewCardView
{
    /**
     * @param list<array{url:string,id:int,main:bool}> $images
     * @param list<StorefrontField> $storefront
     * @param array<string,string> $store  name, status, city
     * @param string $fullDescription what the shop page shows — the projector
     *        BUILDS it from the short description, the brand and the medical
     *        specifications, so it is passed in rather than rebuilt here. A
     *        second implementation of that string would differ the day
     *        somebody changed one of them.
     */
    public static function render(
        Product $product,
        array $images,
        string $categoryPath,
        ?SpecTemplate $template,
        array $store,
        array $storefront,
        string $fullDescription,
        string $editorUrl,
        string $seoPlugin,
        string $nonceField,
        bool $woocommerceAvailable
    ): string {
        $d = $product->details;
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);

        $html = '<article class="tmc-review tmc-review--full">'
            . '<h3 class="tmc-review__title">' . esc_html($d->title !== '' ? $d->title : __('بدون عنوان', 'tecteb-marketplace-core')) . '</h3>'
            . self::storeLine($product, $store)
            . self::gallery($images)
            . self::descriptions($d->shortDescription, $fullDescription)
            . self::facts($product, $categoryPath, $fa)
            . self::specs($product, $template)
            . self::storefrontBlock($product, $storefront, $editorUrl, $seoPlugin, $nonceField, $woocommerceAvailable)
            . self::correctionForm($product, $categoryPath, $nonceField);

        return $html . '</article>';
    }

    /** @param array<string,string> $store */
    private static function storeLine(Product $product, array $store): string
    {
        $name = trim($store['name'] ?? '');
        $parts = [$name !== '' ? $name : sprintf(
            /* translators: %s: vendor user id */
            __('فروشگاه بدون نام (کاربر #%s)', 'tecteb-marketplace-core'),
            PersianDigits::toPersian((string) $product->vendorUserId)
        )];
        if (($store['city'] ?? '') !== '') {
            $parts[] = $store['city'];
        }
        if (($store['status'] ?? '') !== '') {
            $parts[] = $store['status'];
        }
        return '<p class="tmc-review__store">' . esc_html(implode(' · ', $parts))
            . ' ' . Components::code('#' . $product->vendorUserId) . '</p>';
    }

    /** @param list<array{url:string,id:int,main:bool}> $images */
    private static function gallery(array $images): string
    {
        if ($images === []) {
            return Components::notice('warning', __('این محصول هیچ تصویری ندارد. صفحهٔ محصول بدون تصویر منتشر نشود.', 'tecteb-marketplace-core'));
        }
        $html = '<ul class="tmc-review__gallery">';
        foreach ($images as $image) {
            $label = $image['main']
                ? __('تصویر اصلی', 'tecteb-marketplace-core')
                : __('تصویر گالری', 'tecteb-marketplace-core');
            $html .= '<li class="tmc-review__shot' . ($image['main'] ? ' is-main' : '') . '">'
                // The alt text is the ROLE of the picture, not a description
                // of what is in it — nobody here knows that, and inventing one
                // would put a wrong sentence in front of a screen reader.
                . ($image['url'] !== ''
                    ? '<img src="' . esc_url($image['url']) . '" alt="' . esc_attr($label) . '" loading="lazy" decoding="async">'
                    : '<span class="tmc-review__missing">' . esc_html__('فایل تصویر پیدا نشد', 'tecteb-marketplace-core') . '</span>')
                . '<span class="tmc-review__shot-meta">' . esc_html($label) . '</span>'
                . '</li>';
        }
        return $html . '</ul>';
    }

    private static function descriptions(string $short, string $full): string
    {
        $html = '<div class="tmc-review__text">';
        $html .= '<h4>' . esc_html__('توضیح کوتاه', 'tecteb-marketplace-core') . '</h4>'
            . '<p>' . ($short !== '' ? esc_html($short) : '<em>' . esc_html__('ننوشته است', 'tecteb-marketplace-core') . '</em>') . '</p>';
        // «توضیح کامل» is not a second field the vendor filled: the product
        // form has one description box. This is the text the shop page will
        // actually carry, assembled from the short description, the brand and
        // the specifications — so the manager reviews what a buyer will read,
        // not the raw parts.
        $html .= '<h4>' . esc_html__('متن صفحهٔ محصول', 'tecteb-marketplace-core') . '</h4>'
            . ($full !== ''
                // Plain text, deliberately. The vendor's description is stored
                // as text and rendering it as markup here would run whatever
                // a vendor typed inside wp-admin.
                ? '<p class="tmc-review__long">' . nl2br(esc_html($full)) . '</p>'
                : '<p><em>' . esc_html__('ننوشته است', 'tecteb-marketplace-core') . '</em></p>');
        return $html . '</div>';
    }

    private static function facts(Product $product, string $categoryPath, callable $fa): string
    {
        $d = $product->details;
        $price = $d->priceMinor > 0
            ? sprintf(
                /* translators: %s: price in toman */
                __('%s تومان', 'tecteb-marketplace-core'),
                $fa(number_format($d->priceMinor))
            )
            : __('قیمت ندارد', 'tecteb-marketplace-core');
        $rows = [
            __('دسته', 'tecteb-marketplace-core') => $categoryPath !== ''
                ? $categoryPath
                : __('دسته‌ای انتخاب نشده یا ترمش پیدا نشد', 'tecteb-marketplace-core'),
            __('قیمت', 'tecteb-marketplace-core') => $price,
            __('موجودی', 'tecteb-marketplace-core') => $fa($d->stock),
            __('کد SKU', 'tecteb-marketplace-core') => $d->sku !== '' ? $d->sku : '—',
            __('برند', 'tecteb-marketplace-core') => $d->brand !== '' ? $d->brand : '—',
        ];
        if ($d->salePriceMinor !== null && $d->salePriceMinor > 0) {
            $rows[__('قیمت فروش ویژه', 'tecteb-marketplace-core')] = $fa(number_format($d->salePriceMinor));
        }
        $html = '<dl class="tmc-review__facts">';
        foreach ($rows as $label => $value) {
            $html .= '<div><dt>' . esc_html((string) $label) . '</dt><dd>' . esc_html((string) $value) . '</dd></div>';
        }
        return $html . '</dl>';
    }

    private static function specs(Product $product, ?SpecTemplate $template): string
    {
        if ($product->specs === []) {
            return '<p class="tmc-card__note">' . esc_html(
                $template === null
                    ? __('این دسته الگوی مشخصات ندارد، پس مشخصهٔ پزشکی پرسیده نشده است. نبودِ الگو مانع تأیید نیست.', 'tecteb-marketplace-core')
                    : __('فروشنده هیچ مشخصهٔ پزشکی پر نکرده است.', 'tecteb-marketplace-core')
            ) . '</p>';
        }
        $html = '<table class="tmc-table tmc-review__specs"><caption>'
            . esc_html__('مشخصات پزشکی', 'tecteb-marketplace-core') . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('مشخصه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('مقدار', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($product->specs as $key => $value) {
            if (trim((string) $value) === '') {
                continue;
            }
            $field = $template?->field((string) $key);
            $label = $field?->label ?? (string) $key;
            $unit = ($field?->unit ?? '') !== '' ? ' ' . $field->unit : '';
            $html .= '<tr><th scope="row" data-label="' . esc_attr__('مشخصه', 'tecteb-marketplace-core') . '">'
                . esc_html($label) . '</th>'
                . '<td data-label="' . esc_attr__('مقدار', 'tecteb-marketplace-core') . '">'
                . esc_html(PersianDigits::toPersian((string) $value) . $unit) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    /**
     * The storefront half: where to edit it, and who owns each field.
     *
     * @param list<StorefrontField> $fields
     */
    private static function storefrontBlock(
        Product $product,
        array $fields,
        string $editorUrl,
        string $seoPlugin,
        string $nonceField,
        bool $woocommerceAvailable
    ): string {
        $html = '<div class="tmc-review__storefront"><h4>'
            . esc_html__('صفحهٔ محصول در ووکامرس', 'tecteb-marketplace-core') . '</h4>';

        if (!$woocommerceAvailable) {
            return $html . Components::notice('warning', __('ووکامرس فعال نیست، پس این محصول صفحه‌ای در فروشگاه ندارد و ویرایشگر و سئو در دسترس نیستند.', 'tecteb-marketplace-core')) . '</div>';
        }
        if (!$product->isProjected()) {
            // The preparation step. Explained rather than offered silently:
            // the manager is about to create a row in their own shop.
            return $html
                . '<p class="tmc-card__note">' . esc_html(sprintf(
                    /* translators: %s: name of the SEO plugin, or an empty string */
                    $seoPlugin !== ''
                        ? __('این محصول هنوز در ووکامرس ساخته نشده است. با «آماده‌سازی» یک محصول پیش‌نویس ساخته می‌شود تا بتوانید همان‌جا ویرایش و سئوی %s را پیش از انتشار تنظیم کنید. پیش‌نویس در فروشگاه دیده نمی‌شود و قابل خرید نیست.', 'tecteb-marketplace-core')
                        : __('این محصول هنوز در ووکامرس ساخته نشده است. با «آماده‌سازی» یک محصول پیش‌نویس ساخته می‌شود تا بتوانید همان‌جا ویرایشش کنید. پیش‌نویس در فروشگاه دیده نمی‌شود و قابل خرید نیست.%s', 'tecteb-marketplace-core'),
                    $seoPlugin
                )) . '</p>'
                . '<form method="post" class="tmc-inline">' . $nonceField
                . '<input type="hidden" name="subject" value="storefront">'
                . '<input type="hidden" name="subject_id" value="' . esc_attr((string) $product->id) . '">'
                . '<button type="submit" class="tmc-button" name="decision" value="prepare">'
                . esc_html__('آماده‌سازی در ووکامرس (پیش‌نویس)', 'tecteb-marketplace-core') . '</button></form></div>';
        }

        $html .= '<p class="tmc-review__links">';
        if ($editorUrl !== '') {
            $html .= '<a class="tmc-button" href="' . esc_url($editorUrl) . '">'
                . esc_html__('ویرایش در ووکامرس', 'tecteb-marketplace-core') . '</a> ';
            $html .= '<a class="tmc-button" href="' . esc_url($editorUrl . '#rank_math_metabox') . '">'
                . esc_html(sprintf(
                    /* translators: %s: SEO plugin name */
                    __('تنظیم سئو (%s)', 'tecteb-marketplace-core'),
                    $seoPlugin !== '' ? $seoPlugin : __('افزونهٔ سئو نصب نیست', 'tecteb-marketplace-core')
                )) . '</a>';
        }
        $html .= '</p>'
            . '<p class="tmc-card__note">' . esc_html(sprintf(
                /* translators: %s: storefront product id */
                __('شناسهٔ محصول در فروشگاه: %s — ویرایش در ووکامرس محصول را منتشر نمی‌کند؛ انتشار فقط با «تأیید و انتشار» همین صفحه انجام می‌شود.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) $product->wcProductId)
            )) . '</p>';

        // Two different questions, so two different blocks. «این دو با هم
        // نمی‌خوانند، کدام درست است؟» is a comparison. «این محصول از نسخهٔ
        // قدیمی مانده و معلوم نیست این متن را چه کسی نوشته» is a piece of
        // history, and mixing them would put a product's whole field list
        // under a heading that says the sides disagree when they may not.
        $unsettled = array_values(array_filter($fields, static fn (StorefrontField $f): bool => $f->isUnsettled()));
        $conflicts = array_values(array_filter(
            $fields,
            static fn (StorefrontField $f): bool => !$f->isUnsettled() && $f->needsDecision()
        ));
        if ($unsettled === [] && $conflicts === []) {
            return $html . '<p class="tmc-card__note">' . esc_html__('اطلاعات بازارگاه و ووکامرس برای این محصول یکی است.', 'tecteb-marketplace-core') . '</p></div>';
        }
        if ($unsettled !== []) {
            $html .= self::unsettledBlock($product, $unsettled, $nonceField);
        }
        if ($conflicts !== []) {
            $html .= self::ownershipTable($product, $conflicts, $nonceField);
        }
        return $html . '</div>';
    }

    /**
     * Fields on a product older than the ownership stamps.
     *
     * The marketplace does not know whether the text in WooCommerce is its
     * own work or the manager's, because the version that projected this
     * product recorded nothing either way. It writes nothing until somebody
     * says — and this block is where they say it, per field or in one go.
     *
     * @param list<StorefrontField> $fields
     */
    private static function unsettledBlock(Product $product, array $fields, string $nonceField): string
    {
        $html = Components::notice('warning', __('این محصول پیش از نسخهٔ ۰٫۱٫۰-alpha.27 ساخته شده است، پس سابقه‌ای از اینکه متن فعلی ووکامرس را بازارگاه نوشته یا شما، وجود ندارد. تا وقتی تعیین تکلیف نکنید، بازارگاه روی این فیلدها چیزی نمی‌نویسد و ذخیرهٔ فروشنده هم آن‌ها را عوض نمی‌کند.', 'tecteb-marketplace-core'))
            . '<table class="tmc-table tmc-review__owner"><caption>'
            . esc_html__('فیلدهای بدون سابقه', 'tecteb-marketplace-core') . '</caption><thead><tr>'
            . '<th scope="col">' . esc_html__('فیلد', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('روی ووکامرس', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('در پروندهٔ بازارگاه', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('تصمیم', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($fields as $field) {
            $html .= '<tr>'
                . '<th scope="row" data-label="' . esc_attr__('فیلد', 'tecteb-marketplace-core') . '">'
                . esc_html(ProductMessages::storefrontField($field->key))
                . (!$field->differs()
                    ? '<br><span class="tmc-card__note">'
                        . esc_html__('هر دو طرف یکی است؛ فقط باید ثبت شود.', 'tecteb-marketplace-core')
                        . '</span>'
                    : '')
                . '</th>'
                . '<td data-label="' . esc_attr__('روی ووکامرس', 'tecteb-marketplace-core') . '">'
                . esc_html(self::excerpt($field->storefront)) . '</td>'
                . '<td data-label="' . esc_attr__('در پروندهٔ بازارگاه', 'tecteb-marketplace-core') . '">'
                . esc_html(self::excerpt($field->marketplace)) . '</td>'
                . '<td data-label="' . esc_attr__('تصمیم', 'tecteb-marketplace-core') . '">'
                . self::decisionButtons($product, $field->key, $nonceField)
                . '</td></tr>';
        }
        $html .= '</tbody></table>';

        // One button for the whole product. Five fields times however many
        // products a shop carried over is not a decision, it is a chore —
        // and a chore is what somebody clicks through without reading.
        return $html . '<form method="post" class="tmc-inline">' . $nonceField
            . '<input type="hidden" name="subject" value="product_fields">'
            . '<input type="hidden" name="subject_id" value="' . esc_attr((string) $product->id) . '">'
            . '<button type="submit" class="tmc-button" name="decision" value="keep">'
            . esc_html__('برای همهٔ این فیلدها: نسخهٔ ووکامرس بماند', 'tecteb-marketplace-core') . '</button> '
            . '<button type="submit" class="tmc-button" name="decision" value="accept">'
            . esc_html__('برای همهٔ این فیلدها: مقدار بازارگاه اعمال شود', 'tecteb-marketplace-core') . '</button>'
            . '</form>';
    }

    private static function decisionButtons(Product $product, string $field, string $nonceField): string
    {
        return '<form method="post" class="tmc-inline">' . $nonceField
            . '<input type="hidden" name="subject" value="field">'
            . '<input type="hidden" name="subject_id" value="' . esc_attr((string) $product->id) . '">'
            . '<input type="hidden" name="field" value="' . esc_attr($field) . '">'
            . '<button type="submit" class="tmc-button" name="decision" value="keep">'
            . esc_html__('نسخهٔ ووکامرس بماند', 'tecteb-marketplace-core') . '</button> '
            . '<button type="submit" class="tmc-button" name="decision" value="accept">'
            . esc_html__('خواستهٔ فروشنده اعمال شود', 'tecteb-marketplace-core') . '</button>'
            . '</form>';
    }

    /** @param list<StorefrontField> $fields */
    private static function ownershipTable(Product $product, array $fields, string $nonceField): string
    {
        $html = Components::notice('warning', __('برای این فیلدها، متن ووکامرس با آنچه فروشنده فرستاده یکی نیست. تا وقتی تصمیم نگیرید، بازارگاه روی آن‌ها چیزی نمی‌نویسد.', 'tecteb-marketplace-core'))
            . '<table class="tmc-table tmc-review__owner"><thead><tr>'
            . '<th scope="col">' . esc_html__('فیلد', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('روی ووکامرس', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('خواستهٔ فروشنده', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('تصمیم', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($fields as $field) {
            $wanted = $field->pending !== '' ? $field->pending : $field->marketplace;
            $html .= '<tr>'
                . '<th scope="row" data-label="' . esc_attr__('فیلد', 'tecteb-marketplace-core') . '">'
                . esc_html(ProductMessages::storefrontField($field->key))
                . ($field->roundTrips ? '' : '<br><span class="tmc-card__note">'
                    . esc_html__('این متن از روی مشخصات ساخته می‌شود؛ نگه‌داشتن نسخهٔ ووکامرس یعنی بازارگاه دیگر آن را نمی‌نویسد.', 'tecteb-marketplace-core')
                    . '</span>')
                . '</th>'
                . '<td data-label="' . esc_attr__('روی ووکامرس', 'tecteb-marketplace-core') . '">'
                . esc_html(self::excerpt($field->storefront)) . '</td>'
                . '<td data-label="' . esc_attr__('خواستهٔ فروشنده', 'tecteb-marketplace-core') . '">'
                . esc_html(self::excerpt($wanted)) . '</td>'
                . '<td data-label="' . esc_attr__('تصمیم', 'tecteb-marketplace-core') . '">'
                . self::decisionButtons($product, $field->key, $nonceField)
                . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    /** The manager's own correction of the marketplace record. */
    private static function correctionForm(Product $product, string $categoryPath, string $nonceField): string
    {
        $id = 'fix-' . $product->id;
        return '<details class="tmc-review__fix"><summary>'
            . esc_html__('اصلاح اطلاعات توسط مدیر', 'tecteb-marketplace-core') . '</summary>'
            . '<p class="tmc-card__note">' . esc_html__('این اصلاح هم روی پروندهٔ بازارگاه می‌نشیند و هم روی ووکامرس، پس دو نسخهٔ متفاوت ساخته نمی‌شود. قیمت و موجودی اینجا نیست: قیمت شرط تجاری فروشنده است و موجودی را ووکامرس نگه می‌دارد.', 'tecteb-marketplace-core') . '</p>'
            . '<form method="post">' . $nonceField
            . '<input type="hidden" name="subject" value="correct">'
            . '<input type="hidden" name="subject_id" value="' . esc_attr((string) $product->id) . '">'
            . '<div class="tmc-field"><label class="tmc-field__label" for="' . $id . '-title">'
            . esc_html__('عنوان محصول', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input" type="text" id="' . $id . '-title" name="fix_title" value="'
            . esc_attr($product->details->title) . '"></div>'
            . '<div class="tmc-field"><label class="tmc-field__label" for="' . $id . '-short">'
            . esc_html__('توضیح کوتاه', 'tecteb-marketplace-core') . '</label>'
            . '<textarea class="tmc-input" id="' . $id . '-short" name="fix_short" rows="2">'
            . esc_textarea($product->details->shortDescription) . '</textarea></div>'
            . '<div class="tmc-field"><label class="tmc-field__label" for="' . $id . '-cat">'
            . esc_html__('شناسهٔ دستهٔ ووکامرس', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input tmc-input--short" type="text" id="' . $id . '-cat" name="fix_category" dir="ltr"'
            . ' inputmode="numeric" value="' . esc_attr($product->details->categoryKey) . '">'
            . '<span class="tmc-card__note">' . esc_html(
                $categoryPath !== ''
                    ? sprintf(
                        /* translators: %s: the current category path */
                        __('دستهٔ فعلی: %s — برای دیدن فهرست دسته‌ها به «محصولات ← دسته‌بندی‌ها» بروید.', 'tecteb-marketplace-core'),
                        $categoryPath
                    )
                    : __('دستهٔ فعلی پیدا نشد. شناسهٔ ترم ووکامرس را بگذارید.', 'tecteb-marketplace-core')
            ) . '</span></div>'
            . '<p><button type="submit" class="tmc-button" name="decision" value="save">'
            . esc_html__('ثبت اصلاح', 'tecteb-marketplace-core') . '</button></p>'
            . '</form></details>';
    }

    /** Enough of a value to recognise it, without a table cell holding an essay. */
    private static function excerpt(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($value === '') {
            return '—';
        }
        return mb_strlen($value) > 160 ? mb_substr($value, 0, 160) . '…' : $value;
    }
}
