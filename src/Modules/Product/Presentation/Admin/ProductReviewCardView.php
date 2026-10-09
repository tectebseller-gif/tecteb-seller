<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation\Admin;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\SpecTemplate;
use Tecteb\Marketplace\Modules\Product\Domain\FieldMerge;
use Tecteb\Marketplace\Modules\Product\Domain\StorefrontField;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\WooCommerceStorefrontFields as Reader;
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
     * @param string|null $fullDescription the vendor's own long description —
     *        `''` when they cleared it, and `null` for a product from before
     *        migration 23, which has none at all. Passed in rather than read
     *        here because this view holds no repository.
     */
    public static function render(
        Product $product,
        array $images,
        string $categoryPath,
        ?SpecTemplate $template,
        array $store,
        array $storefront,
        ?string $fullDescription,
        string $editorUrl,
        string $seoPlugin,
        string $nonceField,
        bool $woocommerceAvailable,
        /**
         * Where to LOOK at the product, from `StorefrontFieldsInterface::viewLink()`.
         *
         * A default, so the branch that draws no button is what a caller that
         * has not been told about this gets. The alternative default — a link —
         * would be a link nobody decided to offer.
         *
         * @var array{url:string, public:bool, reason:string}
         */
        array $viewLink = ['url' => '', 'public' => false, 'reason' => 'missing'],
        /** An unanswered proposal: what the shop shows is NOT what is proposed. */
        bool $hasPendingRevision = false,
        /**
         * Why the comparison is empty, when it is — from
         * `StorefrontFieldsInterface::comparisonState()`.
         *
         * The default is `compared`, which is the only value that lets the
         * «the two sides are identical» sentence be printed at all; a caller
         * that has not been told about this therefore cannot accidentally get
         * a stronger claim than it has evidence for.
         */
        string $comparisonState = Reader::STATE_COMPARED
    ): string {
        $d = $product->details;
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);

        $html = '<article class="tmc-review tmc-review--full">'
            . '<h3 class="tmc-review__title">' . esc_html(ProductMessages::displayTitle($d->title, $product->id)) . '</h3>'
            . self::storeLine($product, $store)
            . self::gallery($images)
            . self::descriptions($d->shortDescription, $fullDescription)
            . self::facts($product, $categoryPath, $fa)
            . self::specs($product, $template)
            . self::storefrontBlock($product, $storefront, $editorUrl, $seoPlugin, $nonceField, $woocommerceAvailable, $viewLink, $hasPendingRevision, $comparisonState)
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

    /**
     * The two descriptions, separately — because they are now two fields.
     *
     * Until `alpha.41` the second heading said «متن صفحهٔ محصول» and showed
     * text the PROJECTOR assembled from the short description, the brand and
     * the specifications; the vendor had one box and no way to write the long
     * text at all. Now «توضیحات کامل» is the vendor's own field, and the
     * manager has to be able to read each one as what it is — «مدیر هر دو
     * مقدار را جداگانه ببیند».
     *
     * `$full` is `null` for a product from before migration 23: nobody has
     * written a long description and the marketplace is not going to write
     * one, so the heading says that rather than showing an empty box that
     * looks like a vendor left it blank.
     */
    private static function descriptions(string $short, ?string $full): string
    {
        $html = '<div class="tmc-review__text">';
        $html .= '<h4>' . esc_html__('توضیح کوتاه', 'tecteb-marketplace-core') . '</h4>'
            . '<p>' . ($short !== '' ? esc_html($short) : '<em>' . esc_html__('ننوشته است', 'tecteb-marketplace-core') . '</em>') . '</p>';
        $html .= '<h4>' . esc_html__('توضیحات کامل', 'tecteb-marketplace-core') . '</h4>';
        if ($full === null) {
            // Not «empty» — «this product has never had one», which is a
            // different thing and the reason WooCommerce's own text is left
            // untouched for it.
            return $html . '<p><em>'
                . esc_html__('این محصول پیش از افزودن این فیلد ساخته شده و توضیحات کاملِ خودش را ندارد؛ آنچه در ووکامرس است دست‌نخورده می‌ماند.', 'tecteb-marketplace-core')
                . '</em></p></div>';
        }
        $html .= $full !== ''
            // Plain text, deliberately, even though the field accepts markup:
            // the stored value went through `wp_kses_post()` on the way in, and
            // rendering it as markup HERE would run a vendor's tags inside
            // wp-admin. The manager reads the text; the storefront renders it.
            ? '<p class="tmc-review__long">' . nl2br(esc_html($full)) . '</p>'
            : '<p><em>' . esc_html__('خالی گذاشته شده است', 'tecteb-marketplace-core') . '</em></p>';
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
        bool $woocommerceAvailable,
        array $viewLink,
        bool $hasPendingRevision,
        string $comparisonState = Reader::STATE_COMPARED
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
                . esc_html__('آماده‌سازی در ووکامرس (پیش‌نویس)', 'tecteb-marketplace-core') . '</button></form>'
                // Said rather than drawn as a dead button: «مشاهدهٔ محصول» has
                // nothing to open, and clicking it must not be what CREATES the
                // product either.
                . '<p class="tmc-review__viewnote">'
                . esc_html__('«مشاهدهٔ محصول» فعلاً در دسترس نیست، چون این محصول هنوز صفحه‌ای در فروشگاه ندارد. باز کردن یک صفحه، محصولی نمی‌سازد.', 'tecteb-marketplace-core')
                . '</p></div>';
        }

        $html .= '<p class="tmc-review__links">';
        if ($editorUrl !== '') {
            $html .= '<a class="tmc-button" href="' . esc_url($editorUrl) . '">'
                . esc_html__('ویرایش در ووکامرس', 'tecteb-marketplace-core') . '</a> ';
        }
        // «مشاهدهٔ محصول», beside the editor. A new tab, because the manager is
        // mid-review and the decision form on this page is what they come back
        // to; `rel="noopener"` because `target="_blank"` without it hands the
        // opened page a handle on this one.
        $html .= self::viewButton($viewLink);
        if ($editorUrl !== '') {
            $html .= ' <a class="tmc-button" href="' . esc_url($editorUrl . '#rank_math_metabox') . '">'
                . esc_html(sprintf(
                    /* translators: %s: SEO plugin name */
                    __('تنظیم سئو (%s)', 'tecteb-marketplace-core'),
                    $seoPlugin !== '' ? $seoPlugin : __('افزونهٔ سئو نصب نیست', 'tecteb-marketplace-core')
                )) . '</a>';
        }
        $html .= '</p>'
            . self::viewNote($viewLink, $hasPendingRevision)
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
            return $html . self::sameOrNot($fields, $comparisonState) . '</div>';
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
     * The «مشاهدهٔ محصول» button, or nothing and a reason beside it.
     *
     * Three shapes for three situations, because «صفحهٔ فروشگاه» and «پیش‌نمایش
     * پیش‌نویس» are not the same promise, and «نمی‌شود» has to say why.
     *
     * @param array{url:string, public:bool, reason:string} $link
     */
    private static function viewButton(array $link): string
    {
        if ($link['url'] === '') {
            return '';
        }
        return '<a class="tmc-button" href="' . esc_url($link['url']) . '" target="_blank" rel="noopener">'
            . esc_html(
                $link['public']
                    ? __('مشاهدهٔ محصول', 'tecteb-marketplace-core')
                    : __('مشاهدهٔ محصول (پیش‌نمایش)', 'tecteb-marketplace-core')
            )
            . '<span class="tmc-sr-only"> ' . esc_html__('در زبانهٔ تازه', 'tecteb-marketplace-core') . '</span></a>';
    }

    /**
     * What that button opens — said in words, right beside it.
     *
     * **The proposal sentence is the important one.** What the shop serves is
     * the version that was approved; an unanswered proposal is not applied to it
     * and will not be until the manager says so. A button labelled «پیش‌نمایش
     * تغییرات» would be a promise this round does not keep, so the label says
     * «محصول» and this note says which version that is. A preview of the
     * PROPOSED version is deliberately not built here.
     *
     * @param array{url:string, public:bool, reason:string} $link
     */
    private static function viewNote(array $link, bool $hasPendingRevision): string
    {
        $lines = [];
        if ($link['url'] === '') {
            $lines[] = $link['reason'] === 'not_permitted'
                ? __('برای دیدن پیش‌نمایش این محصول به دسترسی ویرایش همان محصول در ووکامرس نیاز است؛ حساب شما این دسترسی را ندارد.', 'tecteb-marketplace-core')
                : __('صفحهٔ این محصول در فروشگاه پیدا نشد؛ ممکن است نوشتهٔ ووکامرس آن حذف شده باشد.', 'tecteb-marketplace-core');
        } elseif (!$link['public']) {
            $lines[] = __('این محصول در ووکامرس منتشر نشده است، پس «مشاهدهٔ محصول» پیش‌نمایش وردپرس را باز می‌کند — خریدار آن را نمی‌بیند و این نشانی برای کسی که دسترسی ویرایش ندارد باز نمی‌شود.', 'tecteb-marketplace-core');
        }
        if ($hasPendingRevision) {
            $lines[] = __('توجه: آنچه باز می‌شود نسخهٔ فعلی فروشگاه است. تغییرهای پیشنهادی فروشنده روی آن اعمال نشده‌اند و تا تأیید شما اعمال نمی‌شوند.', 'tecteb-marketplace-core');
        }
        if ($lines === []) {
            return '';
        }
        $html = '';
        foreach ($lines as $line) {
            $html .= '<p class="tmc-review__viewnote">' . esc_html($line) . '</p>';
        }
        return $html;
    }

    /**
     * Fields on a product older than the ownership stamps.
     *
     * The marketplace does not know whether the text in WooCommerce is its
     * own work or the manager's, because the version that projected this
     * product recorded nothing either way. It writes nothing until somebody
    /**
     * «The two sides are identical» — but only when they are.
     *
     * **The defect this replaces.** The sentence was printed whenever there
     * was nothing for a manager to DECIDE, and those are two different
     * questions. A vendor's edit that sits on the agreed baseline gets the
     * verdict `write`: it will be applied when the manager approves, there is
     * nothing to ask about, and until then the marketplace record holds the
     * new text while WooCommerce still holds the old one. The owner read
     * «یکی است» about exactly that — product 7, WooCommerce 12107, a new short
     * description on the review page and the previous text in the shop — and
     * after «تأیید و انتشار» the new text appeared, which is the proof it had
     * never been applied.
     *
     * So the claim is made from an actual value comparison, and the four
     * answers are kept apart:
     *
     *  - the comparison could not be made → say which reason, never «same»;
     *  - every field's two sides match → «same», and now it is earned;
     *  - they differ and every difference is queued → say it is WAITING for
     *    approval and has not been applied;
     *  - they differ for any other reason → name the fields, and do not
     *    pretend the queue explains it.
     *
     * @param list<StorefrontField> $fields
     */
    private static function sameOrNot(array $fields, string $comparisonState): string
    {
        if ($comparisonState !== Reader::STATE_COMPARED || $fields === []) {
            return Components::notice('warning', match ($comparisonState) {
                Reader::STATE_NOT_PROJECTED => __('این محصول هنوز در ووکامرس ساخته نشده، پس مقایسه‌ای وجود ندارد.', 'tecteb-marketplace-core'),
                Reader::STATE_WOOCOMMERCE_MISSING => __('ووکامرس در دسترس نیست، پس مقایسهٔ این محصول انجام نشد. این یعنی «نمی‌دانیم»، نه «یکی است».', 'tecteb-marketplace-core'),
                Reader::STATE_NOT_OURS => __('نوشتهٔ ووکامرسی که به این محصول وصل است مال بازارگاه نیست، پس خوانده نشد و مقایسه‌ای انجام نشد.', 'tecteb-marketplace-core'),
                default => __('نوشتهٔ این محصول در ووکامرس خوانده نشد، پس مقایسه انجام نشد. این یعنی «نمی‌دانیم»، نه «یکی است».', 'tecteb-marketplace-core'),
            });
        }

        $differing = array_values(array_filter($fields, static fn (StorefrontField $f): bool => $f->differs()));
        if ($differing === []) {
            return '<p class="tmc-card__note">'
                . esc_html__('اطلاعات بازارگاه و ووکامرس برای این محصول یکی است.', 'tecteb-marketplace-core')
                . '</p>';
        }

        // A difference the manager has already SETTLED is not a new question
        // and not identity either. `description` is the standing case: the
        // projector builds that text, so the two sides go on differing after
        // «نسخهٔ من بماند» for ever — and `alpha.27` added `decided` precisely
        // so the screen would stop asking. Saying «یکی است» about it was the
        // other error, in the opposite direction.
        $live = array_values(array_filter($differing, static fn (StorefrontField $f): bool => !$f->decided));
        if ($live === []) {
            return '<p class="tmc-card__note">' . esc_html(sprintf(
                /* translators: %s: field names */
                __('در این فیلدها دو طرف یکی نیست و شما تعیین تکلیف کرده‌اید، پس بازارگاه رویشان نمی‌نویسد: %s. بقیهٔ فیلدها یکی‌اند.', 'tecteb-marketplace-core'),
                self::fieldNames($differing)
            )) . '</p>';
        }

        // Named, because «something differs» sends a manager hunting through
        // five fields. `ProductMessages::storefrontField()` is the same label
        // the ownership table uses, so the two blocks cannot disagree about
        // what a field is called.
        $names = self::fieldNames($live);
        // Every differing field is queued to be written — which is the
        // ordinary state of a submitted edit, so the sentence says so plainly
        // rather than implying a problem.
        $allQueued = array_reduce(
            $live,
            static fn (bool $carry, StorefrontField $f): bool => $carry && $f->verdict === FieldMerge::WRITE,
            true
        );
        return Components::notice('info', $allQueued
            ? sprintf(
                /* translators: %s: field names */
                __('تغییرات فروشنده در این فیلدها منتظر تأیید است و هنوز در ووکامرس اعمال نشده‌اند: %s. با «تأیید و انتشار» اعمال می‌شوند.', 'tecteb-marketplace-core'),
                $names
            )
            : sprintf(
                /* translators: %s: field names */
                __('اطلاعات بازارگاه و ووکامرس در این فیلدها یکی نیست: %s. تا تأیید، آنچه خریدار می‌بیند نسخهٔ ووکامرس است.', 'tecteb-marketplace-core'),
                $names
            ));
    }

    /**
     * Field keys as the Persian names the rest of the card uses.
     *
     * `ProductMessages::storefrontField()` and nothing local, so this block
     * and the ownership table cannot come to disagree about what a field is
     * called.
     *
     * @param list<StorefrontField> $fields
     */
    private static function fieldNames(array $fields): string
    {
        return implode('، ', array_map(
            static fn (StorefrontField $f): string => ProductMessages::storefrontField($f->key),
            $fields
        ));
    }

    /**
     * The fields nobody has claimed yet, and the two buttons that claim them.
     *
     * A product older than the ownership stamps has no history saying whether
     * the marketplace wrote its current WooCommerce text or the manager did.
     * Only a person can say — and this block is where they say it, per field
     * or in one go.
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
            // `hasPending`, not `pending !== ''`: an empty proposal is a
            // proposal, and reading it as «there isn't one» put the
            // marketplace's OLD value in this cell — so the manager approved
            // a change they were never shown.
            $wanted = $field->hasPending ? $field->pending : $field->marketplace;
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
                // Named, not left as the «—» that an empty value renders as:
                // «هیچ پیشنهادی نیست» and «فروشنده می‌خواهد این پاک شود» are
                // opposite instructions and they looked identical here.
                . ($wanted === ''
                    ? '<em>' . esc_html__('خالی — فروشنده می‌خواهد این فیلد پاک شود', 'tecteb-marketplace-core') . '</em>'
                    : esc_html(self::excerpt($wanted))) . '</td>'
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
