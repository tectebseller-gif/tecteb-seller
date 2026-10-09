<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation;

use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionOutcome;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDecision;
use Tecteb\Marketplace\Modules\Product\Domain\LongDescription;
use Tecteb\Marketplace\Modules\Product\Domain\ProductDetails;
use Tecteb\Marketplace\Modules\Product\Domain\ProductImagePolicy;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Product\Domain\SpecFieldType;
use Tecteb\Marketplace\Modules\Product\Domain\SpecTemplate;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorMessages;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorNotice;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUi;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorUrls;

/**
 * The four-step product form of Master Spec §6 and UX §5.2.
 *
 * The steps are links, each its own URL, and each step posts the WHOLE form:
 * that is what makes «حفظ مقادیر در خطای اعتبارسنجی» true without JavaScript,
 * because every value the vendor has typed travels with every submission and
 * comes back in the hidden carry-over fields of the other three steps.
 *
 * Step 4 shows the marketplace's own readiness verdict — the same call
 * submit() makes — so the preview and the button can never disagree.
 */
final class ProductFormView
{
    /** @var array<string,string> step => label */
    public static function steps(): array
    {
        return [
            '1' => __('۱. معرفی', 'tecteb-marketplace-core'),
            '2' => __('۲. قیمت و موجودی', 'tecteb-marketplace-core'),
            '3' => __('۳. فنی و پزشکی', 'tecteb-marketplace-core'),
            '4' => __('۴. بازبینی و ارسال', 'tecteb-marketplace-core'),
        ];
    }

    /**
     * @param array<string,string> $specs
     * @param list<array{id:int,url:string}> $images
     * @param array{selected?:\Tecteb\Marketplace\Modules\Product\Domain\ProductCategory|null,results?:list<\Tecteb\Marketplace\Modules\Product\Domain\ProductCategory>,query?:string,matched?:int,total?:int,missing?:bool} $categories
     *        The category picker's state. Until `alpha.24` this was a flat
     *        «key => label» map built from the manager's spec templates — the
     *        defect that left a shop with 1,070 real categories offering the
     *        vendor none.
     * @param array{attributes?:list<\Tecteb\Marketplace\Modules\Product\Domain\ProductAttribute>,variations?:list<\Tecteb\Marketplace\Modules\Product\Domain\ProductVariation>,thumbnails?:array<int,string>} $variable
     */
    public static function render(
        int $productId,
        ProductDetails $details,
        array $specs,
        array $images,
        int $mainImageId,
        ?SpecTemplate $template,
        string $step,
        ProductStatus $status,
        array $categories,
        VendorUrls $urls,
        string $nonceField,
        ?VendorNotice $notice = null,
        ?OperationResult $readiness = null,
        ?CommissionOutcome $share = null,
        bool $mayPublishDirectly = false,
        bool $hasPendingRevision = false,
        array $variable = [],
        string $revision = '',
        string $draftSavedAt = '',
        string $autosaveUrl = '',
        string $autosaveAction = '',
        string $autosaveNonce = '',
        int $maxImageBytes = 0,
        /**
         * The row as it stands, for the two refusals that ask the vendor to
         * look before they press save again. Null every other time: a form
         * that always rendered a comparison would be a form that never had a
         * reason to.
         */
        ?ProductDetails $storedForComparison = null,
        /**
         * The manager's last message about this product, if they left one.
         *
         * The status chip said «نیازمند اصلاح» and the sentence saying WHAT
         * to correct was written into a column nothing on this page read. So
         * the vendor was told to fix something and not told what — and the
         * manager, having typed it, had every reason to think it had been
         * delivered.
         */
        ?ProductDecision $managerMessage = null,
        /**
         * The one-time value that makes ONE submitted create form produce one
         * product.
         *
         * A replayed POST — which is what a browser sends after a request
         * times out, and what a double click sends — carried nothing to tell
         * it apart from a vendor who genuinely wants a second product, so it
         * made one. The token identifies the SUBMISSION: a freshly rendered
         * form gets a fresh one, so two products of the same name are still
         * perfectly possible on purpose.
         *
         * Only rendered while creating. An edit already has a product id,
         * which is a better identity than any token, and `revision` is what
         * guards that path.
         */
        string $createToken = ''
    ): string {
        $step = array_key_exists($step, self::steps()) ? $step : '1';
        $html = '';
        if ($notice !== null) {
            $html .= VendorUi::notice(
                ProductMessages::isErrorNotice($notice->code) ? 'warning' : 'success',
                ProductMessages::notice($notice->code, $notice->context)
                    ?? VendorMessages::notice($notice->code, $notice->context)
            );
            // The two refusals that end in «look, then decide» get something
            // to look at. Without this the advice was «open another tab», and
            // the fields that actually differed were never named.
            if ($storedForComparison !== null
                && in_array($notice->code, ['revision_missing', 'stale_revision'], true)) {
                $html .= ProductConflictView::render($details, $storedForComparison);
            }
        }
        // Above the form, before anything else the vendor can act on: a
        // message about work they have to redo belongs where the work is, not
        // three clicks away in a list.
        if ($managerMessage !== null) {
            $html .= self::managerMessage($managerMessage);
        }
        if ($productId > 0 && !$status->isEditableByVendor()) {
            $html .= VendorUi::notice('info', $status === ProductStatus::Submitted
                ? __('این محصول در صف بررسی است. تا تعیین تکلیف، فقط موجودی و کد SKU تغییر می‌کنند.', 'tecteb-marketplace-core')
                : __('این محصول منتشر شده است. تغییر عنوان، قیمت، دسته و مشخصات به‌صورت «نسخه پیشنهادی» ثبت می‌شود و نسخه فعلی روی سایت می‌ماند؛ موجودی فوری اعمال می‌شود.', 'tecteb-marketplace-core'));
        }
        if ($hasPendingRevision) {
            $html .= VendorUi::notice('info', __('یک نسخه پیشنهادی برای این محصول در انتظار بررسی مدیر است. ذخیره دوباره، همان نسخه را با مقادیر تازه جایگزین می‌کند.', 'tecteb-marketplace-core'));
        }

        $base = $urls->product($productId);
        $html .= '<section class="tv-card">'
            . '<h2 class="tv-card__title">' . esc_html(
                $productId > 0 ? __('ویرایش محصول', 'tecteb-marketplace-core') : __('محصول تازه', 'tecteb-marketplace-core')
            ) . '</h2>';
        if ($productId > 0) {
            $html .= '<p class="tv-hint">' . VendorUi::chip(ProductMessages::statusTone($status), ProductMessages::status($status)) . '</p>';
        }
        if ($draftSavedAt !== '') {
            $html .= VendorUi::notice('info', sprintf(
                /* translators: %s: time the draft was kept */
                __('یک پیش‌نویس ذخیره‌نشده از ساعت %s برای این محصول هست. اگر همین صفحه را ذخیره کنید، جای آن را می‌گیرد.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian($draftSavedAt)
            ));
        }
        $html .= self::stepNav($step, $base, $productId);

        // The autosave configuration travels on the form's own attributes.
        // Not an inline <script>: the vendor area renders no executable markup
        // at all, and that is worth keeping for one JSON object.
        $autosave = $autosaveUrl === '' ? '' :
            ' data-autosave-url="' . esc_url($autosaveUrl) . '"'
            . ' data-autosave-action="' . esc_attr($autosaveAction) . '"'
            . ' data-autosave-nonce="' . esc_attr($autosaveNonce) . '"'
            . ' data-text-saving="' . esc_attr__('در حال نگه‌داشتن…', 'tecteb-marketplace-core') . '"'
            . ' data-text-saved="' . esc_attr__('پیش‌نویس نگه داشته شد', 'tecteb-marketplace-core') . '"'
            . ' data-text-failed="' . esc_attr__('پیش‌نویس نگه داشته نشد؛ پیش از بستن صفحه، ذخیره کنید.', 'tecteb-marketplace-core') . '"'
            . ' data-text-conflict="' . esc_attr__('یکی دیگر این محصول را از وقتی این صفحه باز شده ذخیره کرده است. ذخیرهٔ شما رد می‌شود تا کار او پاک نشود.', 'tecteb-marketplace-core') . '"';

        $html .= '<form method="post" action="' . esc_url($urls->products()) . '" enctype="multipart/form-data" class="tv-form"' . $autosave . '>'
            . $nonceField
            . '<input type="hidden" name="tmc_vendor_action" value="save_product">'
            . '<input type="hidden" name="product_id" value="' . esc_attr((string) $productId) . '">'
            . '<input type="hidden" name="step" value="' . esc_attr($step) . '">'
            . ($productId === 0 && $createToken !== ''
                ? '<input type="hidden" name="create_token" value="' . esc_attr($createToken) . '">'
                : '')
            // The stamp this form was rendered from. A save whose stamp no
            // longer matches the row is refused rather than overwriting
            // somebody else's newer work — and it is a hidden field rather than
            // something computed at submit time, because the whole point is
            // that it is the value as it WAS when this page loaded.
            . '<input type="hidden" name="revision" value="' . esc_attr($revision) . '">'
            . self::carryOver($step, $details, $specs, $template)
            . self::gallery($images, $mainImageId, $step, $maxImageBytes)
            . match ($step) {
                '2' => self::stepPrice($details),
                '3' => self::stepTechnical($details, $specs, $template),
                '4' => self::stepReview($details, $readiness, $share, $mayPublishDirectly, $status, $categories),
                default => self::stepIntro($details, $categories),
            }
            . '<p class="tv-form__actions">'
            . VendorUi::submit(__('ذخیره و ادامه', 'tecteb-marketplace-core'))
            . '</p>'
            // Polite, not assertive: a save confirmation must not interrupt
            // somebody mid-sentence in the field above it.
            . ($autosaveUrl === '' ? '' : '<p id="tmc-autosave-status" class="tv-autosave" role="status" aria-live="polite"></p>')
            . '</form>';

        // OUTSIDE the form above, deliberately: each combination posts on its
        // own, and a form inside a form is not valid HTML — the browser would
        // silently drop the inner one and the vendor would press a button
        // that does nothing.
        if ($step === '2' && $details->type === ProductType::VARIABLE) {
            $html .= VariationsView::render(
                $productId,
                $variable['attributes'] ?? [],
                $variable['variations'] ?? [],
                $variable['thumbnails'] ?? [],
                $urls,
                $nonceField
            );
        }

        if ($step === '4' && $productId > 0 && $status->isEditableByVendor()) {
            $html .= self::submitForm($urls, $nonceField, $productId, $readiness, $mayPublishDirectly);
        }
        return $html . '</section>';
    }

    /**
     * The manager's own words, quoted, with the decision they belong to.
     *
     * Quoted rather than paraphrased: «قیمت با بازار نمی‌خواند» and «عکس
     * دوم مال محصول دیگری است» are different instructions, and a generic
     * «نیازمند اصلاح» delivers neither. The date is shown because «چه وقت
     * گفته شد» is what tells the vendor whether it is about the version they
     * have just changed.
     */
    private static function managerMessage(ProductDecision $decision): string
    {
        $heading = match ($decision->decision) {
            ProductDecision::CHANGES_REQUESTED => __('مدیر اصلاح خواسته است', 'tecteb-marketplace-core'),
            ProductDecision::REJECTED => __('این محصول رد و بایگانی شد', 'tecteb-marketplace-core'),
            ProductDecision::SUSPENDED => __('این محصول تعلیق شد', 'tecteb-marketplace-core'),
            ProductDecision::CORRECTED => __('مدیر این محصول را اصلاح کرد', 'tecteb-marketplace-core'),
            default => __('پیام مدیر', 'tecteb-marketplace-core'),
        };
        $when = trim($decision->createdAt) === ''
            ? ''
            : PersianDigits::toPersian(substr($decision->createdAt, 0, 16));

        return '<section class="tv-card tv-card--message"><h2 class="tv-card__title">'
            . esc_html($heading) . '</h2>'
            . ($when !== ''
                ? '<p class="tv-hint">' . esc_html(sprintf(
                    /* translators: %s: the date and time of the decision */
                    __('ثبت‌شده در %s', 'tecteb-marketplace-core'),
                    $when
                )) . '</p>'
                : '')
            . '<blockquote class="tv-quote"><p>' . esc_html($decision->note) . '</p></blockquote>'
            . '<p class="tv-hint">' . esc_html__('پس از اصلاح، دوباره «ارسال برای بررسی» را بزنید.', 'tecteb-marketplace-core') . '</p>'
            . '</section>';
    }

    private static function stepNav(string $current, string $base, int $productId): string
    {
        $html = '<nav class="tv-tabs" aria-label="' . esc_attr__('مرحله‌های فرم محصول', 'tecteb-marketplace-core') . '"><ul>';
        foreach (self::steps() as $key => $label) {
            // PHP turns the numeric string keys of steps() into integers, so
            // the comparisons below MUST be against a string again — without
            // this cast every tab compares false and step 1 renders as an
            // unreachable span on a brand-new product. Caught in the browser.
            $step = (string) $key;
            $isCurrent = $step === $current;
            // A product that does not exist yet has nothing to come back to,
            // so the later steps are announced but not linked until step 1 is
            // saved. Otherwise the vendor would fill step 3 and lose it.
            $reachable = $productId > 0 || $step === '1';
            $html .= '<li>';
            $html .= $reachable
                ? '<a class="tv-tab' . ($isCurrent ? ' is-current' : '') . '"'
                    . ($isCurrent ? ' aria-current="step"' : '')
                    . ' href="' . esc_url(add_query_arg('step', $step, $base)) . '">' . esc_html($label) . '</a>'
                : '<span class="tv-tab is-disabled" aria-disabled="true">' . esc_html($label) . '</span>';
            $html .= '</li>';
        }
        return $html . '</ul></nav>';
    }

    /**
     * Every value the CURRENT step does not show, as hidden inputs.
     *
     * This is the whole data-preservation mechanism: the server receives the
     * complete product on each save, so switching steps or hitting a
     * validation error can never silently blank a field the vendor filled
     * three screens ago.
     *
     * @param array<string,string> $specs
     */
    private static function carryOver(string $step, ProductDetails $d, array $specs, ?SpecTemplate $template): string
    {
        $fields = [
            '1' => ['title' => $d->title, 'type' => $d->type, 'category' => $d->categoryKey, 'brand' => $d->brand, 'short_description' => $d->shortDescription],
            '2' => [
                'price' => (string) $d->priceMinor,
                'sale_price' => $d->salePriceMinor === null ? '' : (string) $d->salePriceMinor,
                'sale_from' => $d->saleFrom ?? '',
                'sale_to' => $d->saleTo ?? '',
                'sku' => $d->sku,
                'stock' => (string) $d->stock,
                'min_purchase' => (string) $d->minPurchase,
                'max_purchase' => $d->maxPurchase === null ? '' : (string) $d->maxPurchase,
            ],
            '3' => ['weight_grams' => (string) $d->weightGrams, 'dimensions' => $d->dimensions, 'tax_class' => $d->taxClass],
        ];
        $html = '';
        foreach ($fields as $owner => $values) {
            if ($owner === $step) {
                continue;
            }
            foreach ($values as $name => $value) {
                $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
            }
        }
        // `description` cannot ride in the map above, because every value
        // there is a plain string and this one has three states. `null` would
        // become `''`, and `''` is the instruction to CLEAR — so a vendor who
        // saved step 2 would have blanked the long description they wrote on
        // step 1. A product that has never had one carries NOTHING, which is
        // exactly how the write path reads «still never set»; one that has a
        // value carries it together with the same base marker the step-1
        // panel sends, so the value makes the round trip unchanged.
        if ($step !== '1' && $d->description !== null) {
            $html .= '<input type="hidden" name="description" value="' . esc_attr($d->description) . '">'
                . '<input type="hidden" name="' . esc_attr(LongDescription::BASE_FIELD) . '" value="'
                . esc_attr(LongDescription::BASE_VALUE) . '">';
        }
        if ($step !== '3') {
            foreach ($template?->askedFields() ?? [] as $field) {
                $html .= '<input type="hidden" name="spec[' . esc_attr($field->key) . ']" value="'
                    . esc_attr($specs[$field->key] ?? '') . '">';
            }
        }
        return $html;
    }

    /** @param array<string,mixed> $categories the picker's state */
    private static function stepIntro(ProductDetails $d, array $categories): string
    {
        /** @var list<\Tecteb\Marketplace\Modules\Product\Domain\ProductCategory> $results */
        $results = is_array($categories['results'] ?? null) ? $categories['results'] : [];
        return '<fieldset class="tv-fieldset"><legend>' . esc_html__('معرفی محصول', 'tecteb-marketplace-core') . '</legend>'
            . VendorUi::input('title', __('عنوان محصول', 'tecteb-marketplace-core'), $d->title)
            . VendorUi::select('type', __('نوع محصول', 'tecteb-marketplace-core'), ProductMessages::types(), $d->type, __('محصول خارجی و گروهی در این نسخه ساخته نمی‌شود.', 'tecteb-marketplace-core'))
            . ProductCategoryPickerView::render(
                $categories['selected'] ?? null,
                $results,
                (string) ($categories['query'] ?? ''),
                (int) ($categories['matched'] ?? count($results)),
                (int) ($categories['total'] ?? count($results)),
                (bool) ($categories['missing'] ?? false),
                (string) ($categories['suggest_url'] ?? ''),
                (string) ($categories['suggest_nonce'] ?? ''),
                (string) ($categories['suggest_action'] ?? '')
            )
            . VendorUi::input('brand', __('برند', 'tecteb-marketplace-core'), $d->brand)
            . '</fieldset>'
            . self::descriptionTabs($d);
    }

    /**
     * The two descriptions, as two tabs of one fieldset.
     *
     * **Two independent values, not one split in half.** «توضیح کوتاه» goes to
     * WooCommerce's `short_description` and «توضیحات کامل» to its
     * `description`. Until `alpha.41` there was only the short one and the
     * long one was GENERATED from it plus the spec answers and the brand, so a
     * vendor had no way to write the text buyers actually read.
     *
     * **Both are real textareas, both always in the document, and both always
     * posted.** The tabs are a `<details>` pair and the panels are shown and
     * hidden with CSS driven by a radio — no JavaScript decides what is
     * submitted, so «تعویض تب متن واردشده را حفظ کند» is not a promise a
     * script has to keep: the text never leaves the DOM, and «ذخیره و ادامه»
     * posts both whichever tab is on top. With no JavaScript and no CSS at all
     * the two textareas are simply stacked and labelled, which is a worse
     * layout and a working form.
     *
     * The long one takes paragraphs and lists; `ProductArea` sanitises it
     * through `wp_kses_post()` on the way in, which is the same filter
     * WooCommerce applies to its own product description.
     */
    private static function descriptionTabs(ProductDetails $d): string
    {
        // `null` — a product from before the field existed — renders empty,
        // because there is nothing OF THE VENDOR'S to show. What the shop
        // holds is deliberately not prefilled: copying WooCommerce's text
        // into this box would file the manager's words as the vendor's own
        // proposal the first time anybody pressed save.
        //
        // And an empty box therefore means two different things, so the form
        // states which one it is showing. `description_given=1` — one flag on
        // every render — could not: it made «nothing to show» and «cleared»
        // the same submission, and saving the title asked for the shop's
        // description to be deleted.
        $long = $d->description ?? '';
        $base = LongDescription::baseOf($d->description);
        return '<fieldset class="tv-fieldset tv-desc"><legend>'
            . esc_html__('توضیحات محصول', 'tecteb-marketplace-core') . '</legend>'
            . '<p class="tv-hint">'
            . esc_html__('هر دو ذخیره می‌شوند و جای هم را نمی‌گیرند: توضیح کوتاه زیر عنوان محصول دیده می‌شود و توضیحات کامل در تب توضیحات صفحهٔ محصول.', 'tecteb-marketplace-core')
            . '</p>'
            // A radio pair, so the panel on top is chosen without a script and
            // the choice is not submitted as data anybody reads.
            . '<div class="tv-desc__tabs" role="group" aria-label="'
            . esc_attr__('کدام توضیح را می‌نویسید', 'tecteb-marketplace-core') . '">'
            . '<input class="tv-desc__pick" type="radio" name="tmc_desc_tab" id="tmc-desc-short" value="short" checked>'
            . '<label class="tv-desc__tab" for="tmc-desc-short">'
            . esc_html__('توضیح کوتاه', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tv-desc__pick" type="radio" name="tmc_desc_tab" id="tmc-desc-long" value="long">'
            . '<label class="tv-desc__tab" for="tmc-desc-long">'
            . esc_html__('توضیحات کامل', 'tecteb-marketplace-core') . '</label>'
            . '<div class="tv-desc__panel tv-desc__panel--short">'
            . VendorUi::textarea(
                'short_description',
                __('توضیح کوتاه', 'tecteb-marketplace-core'),
                $d->shortDescription,
                true,
                __('یک یا دو جمله. در فهرست محصولات و بالای صفحهٔ محصول دیده می‌شود.', 'tecteb-marketplace-core')
            )
            . '</div>'
            . '<div class="tv-desc__panel tv-desc__panel--long">'
            . VendorUi::textarea(
                'description',
                __('توضیحات کامل', 'tecteb-marketplace-core'),
                $long,
                true,
                __('پاراگراف و فهرست مجاز است. متن در تب «توضیحات» صفحهٔ محصول دیده می‌شود.', 'tecteb-marketplace-core')
            )
            // WHAT THIS FORM WAS SHOWING, which is what separates «cleared»
            // from «there was nothing here». A form that never had the field
            // sends neither and leaves the stored value alone.
            . '<input type="hidden" name="' . esc_attr(LongDescription::BASE_FIELD) . '" value="'
            . esc_attr($base) . '">'
            . self::clearBox($d)
            . '</div></div></fieldset>';
    }

    /**
     * The one case that needs a control of its own.
     *
     * With a value in the box, emptying it is the instruction — nothing extra
     * is offered, because two ways to say one thing is how an accidental
     * deletion gets a second chance. With NOTHING in the box, the vendor has
     * no way to reach the description the shop already holds, so this is the
     * only way to ask for it to go — unticked by default, because the default
     * must be the answer that changes nothing.
     *
     * A plain checkbox, so it works with no JavaScript at all.
     */
    private static function clearBox(ProductDetails $d): string
    {
        if (!LongDescription::offersClear($d->description)) {
            return '';
        }
        return '<p class="tv-desc__clear"><label><input type="checkbox" name="'
            . esc_attr(LongDescription::CLEAR_FIELD) . '" value="1"> '
            . esc_html__('توضیحات کاملی که از قبل در ووکامرس هست پاک شود', 'tecteb-marketplace-core')
            . '</label><span class="tv-hint">'
            . esc_html__('این محصول توضیحات کاملِ خودش را ندارد و آنچه روی صفحه دیده می‌شود نوشتهٔ مدیر یا متنِ قبلی ووکامرس است. تا این گزینه را نزنید، خالی گذاشتن جعبهٔ بالا آن متن را پاک نمی‌کند.', 'tecteb-marketplace-core')
            . '</span></p>';
    }

    private static function stepPrice(ProductDetails $d): string
    {
        return '<fieldset class="tv-fieldset"><legend>' . esc_html__('قیمت و موجودی', 'tecteb-marketplace-core') . '</legend>'
            . VendorUi::input('price', __('قیمت (تومان)', 'tecteb-marketplace-core'), (string) $d->priceMinor, true, 'text', 'ltr')
            . VendorUi::input('sale_price', __('قیمت با تخفیف (اختیاری)', 'tecteb-marketplace-core'), $d->salePriceMinor === null ? '' : (string) $d->salePriceMinor, true, 'text', 'ltr')
            . VendorUi::input('sale_from', __('شروع تخفیف', 'tecteb-marketplace-core'), $d->saleFrom ?? '', true, 'date', 'ltr')
            . VendorUi::input('sale_to', __('پایان تخفیف', 'tecteb-marketplace-core'), $d->saleTo ?? '', true, 'date', 'ltr')
            . VendorUi::input('sku', __('کد SKU', 'tecteb-marketplace-core'), $d->sku, true, 'text', 'ltr', __('در همین فروشگاه یکتا است.', 'tecteb-marketplace-core'))
            . VendorUi::input('stock', __('موجودی', 'tecteb-marketplace-core'), (string) $d->stock, true, 'text', 'ltr', __('صفر یعنی ناموجود؛ فروش متوقف می‌شود. پیش‌فروش و فروش با کمبود موجودی در این نسخه وجود ندارد.', 'tecteb-marketplace-core'))
            . VendorUi::input('min_purchase', __('حداقل خرید', 'tecteb-marketplace-core'), (string) $d->minPurchase, true, 'text', 'ltr')
            . VendorUi::input('max_purchase', __('حداکثر خرید (اختیاری)', 'tecteb-marketplace-core'), $d->maxPurchase === null ? '' : (string) $d->maxPurchase, true, 'text', 'ltr')
            . '</fieldset>';
    }

    /** @param array<string,string> $specs */
    private static function stepTechnical(ProductDetails $d, array $specs, ?SpecTemplate $template): string
    {
        $html = '<fieldset class="tv-fieldset"><legend>' . esc_html__('فنی و حمل', 'tecteb-marketplace-core') . '</legend>'
            . VendorUi::input('weight_grams', __('وزن (گرم)', 'tecteb-marketplace-core'), (string) $d->weightGrams, true, 'text', 'ltr')
            . VendorUi::input('dimensions', __('ابعاد', 'tecteb-marketplace-core'), $d->dimensions, true, 'text', 'rtl', __('برای نمونه: ۲۰×۱۰×۵ سانتی‌متر', 'tecteb-marketplace-core'))
            . VendorUi::input('tax_class', __('گروه مالیاتی', 'tecteb-marketplace-core'), $d->taxClass, true, 'text', 'rtl', __('اگر مطمئن نیستید خالی بگذارید؛ مدیر بازارگاه تعیین می‌کند.', 'tecteb-marketplace-core'))
            . '</fieldset>';

        $html .= '<fieldset class="tv-fieldset"><legend>' . esc_html__('مشخصات پزشکی دسته', 'tecteb-marketplace-core') . '</legend>';
        if ($template === null) {
            return $html . VendorUi::notice('info', __('برای این دسته هنوز الگوی مشخصاتی تعریف نشده است. تا وقتی مدیر بازارگاه الگو را نسازد، چیزی از شما خواسته نمی‌شود.', 'tecteb-marketplace-core'))
                . '</fieldset>';
        }
        if ($template->isEmpty()) {
            return $html . VendorUi::notice('info', __('الگوی این دسته هنوز فیلدی ندارد.', 'tecteb-marketplace-core')) . '</fieldset>';
        }
        foreach ($template->askedFields() as $field) {
            $name = 'spec[' . $field->key . ']';
            $label = $field->label
                . ($field->unit !== '' ? ' (' . $field->unit . ')' : '')
                . ($field->required ? ' *' : '');
            $value = $specs[$field->key] ?? '';
            $html .= match ($field->type) {
                SpecFieldType::Boolean => VendorUi::select($name, $label, [
                    '' => __('— انتخاب کنید —', 'tecteb-marketplace-core'),
                    '1' => __('بله', 'tecteb-marketplace-core'),
                    '0' => __('خیر', 'tecteb-marketplace-core'),
                ], $value),
                SpecFieldType::Choice => VendorUi::select(
                    $name,
                    $label,
                    ['' => __('— انتخاب کنید —', 'tecteb-marketplace-core')] + array_combine($field->options, $field->options),
                    $value
                ),
                SpecFieldType::Date => VendorUi::input($name, $label, $value, true, 'date', 'ltr'),
                SpecFieldType::Number => VendorUi::input($name, $label, $value, true, 'text', 'ltr'),
                SpecFieldType::Text => VendorUi::input($name, $label, $value),
            };
        }
        $html .= '<p class="tv-hint">' . esc_html(sprintf(
            __('نسخه الگوی این دسته: %s', 'tecteb-marketplace-core'),
            PersianDigits::toPersian((string) $template->schemaVersion)
        )) . '</p>';
        return $html . '</fieldset>';
    }

    /** @param array<string,mixed> $categories the picker's state */
    private static function stepReview(
        ProductDetails $d,
        ?OperationResult $readiness,
        ?CommissionOutcome $share,
        bool $mayPublishDirectly,
        ProductStatus $status,
        array $categories = []
    ): string {
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        $html = '<fieldset class="tv-fieldset"><legend>' . esc_html__('بازبینی', 'tecteb-marketplace-core') . '</legend>'
            . '<dl class="tv-review">'
            . self::reviewRow(__('عنوان', 'tecteb-marketplace-core'), $d->title)
            . self::reviewRow(__('نوع', 'tecteb-marketplace-core'), ProductMessages::type($d->type))
            // The stored value is a term id. Printing it raw would show the
            // vendor «۲۱۴۰» where they chose «تجهیزات پزشکی › بیهوشی و تنفسی».
            . self::reviewRow(
                __('دسته', 'tecteb-marketplace-core'),
                ($categories['selected'] ?? null)?->path ?? $d->categoryKey
            )
            . self::reviewRow(__('قیمت', 'tecteb-marketplace-core'), sprintf(__('%s تومان', 'tecteb-marketplace-core'), $fa(number_format($d->priceMinor))))
            . self::reviewRow(__('موجودی', 'tecteb-marketplace-core'), $fa($d->stock))
            . '</dl>';

        if ($readiness !== null && !$readiness->ok) {
            $html .= VendorUi::notice('warning', ProductMessages::notice($readiness->code, $readiness->context)
                ?? VendorMessages::notice($readiness->code, $readiness->context));
        } elseif ($readiness !== null) {
            $html .= VendorUi::notice('success', $mayPublishDirectly
                ? __('همه‌چیز کامل است. با ارسال، محصول بی‌درنگ منتشر می‌شود.', 'tecteb-marketplace-core')
                : __('همه‌چیز کامل است. با ارسال، محصول در صف بررسی مدیر قرار می‌گیرد.', 'tecteb-marketplace-core'));
        }

        $html .= self::shareBox($share, $d, $fa);
        if (!$status->isEditableByVendor()) {
            $html .= '<p class="tv-hint">' . esc_html__('این محصول در وضعیتی است که ارسال دوباره ندارد.', 'tecteb-marketplace-core') . '</p>';
        }
        return $html . '</fieldset>';
    }

    /** @param callable(string|int):string $fa */
    private static function shareBox(?CommissionOutcome $share, ProductDetails $d, callable $fa): string
    {
        $title = '<h3 class="tv-review__title">' . esc_html__('سهم تخمینی شما', 'tecteb-marketplace-core') . '</h3>';
        if ($share === null || !$share->isCalculated()) {
            // FIN-02: no rate is NOT a zero rate. Printing «۱۰۰٪ سهم شما»
            // here would be a number nobody decided.
            return '<div class="tv-note">' . $title
                . '<p>' . esc_html__('نرخ کمیسیون این محصول هنوز تعیین نشده است، پس سهم شما محاسبه نمی‌شود. این «صفر» نیست؛ مقدارش تعیین‌نشده است و مدیر بازارگاه آن را مشخص می‌کند.', 'tecteb-marketplace-core') . '</p></div>';
        }
        $rate = number_format(($share->snapshot?->rateBasisPoints ?? 0) / 100, 2);
        return '<div class="tv-note">' . $title
            . '<dl class="tv-review">'
            . self::reviewRow(__('مبنای محاسبه (پس از تخفیف، پیش از مالیات)', 'tecteb-marketplace-core'), sprintf(__('%s تومان', 'tecteb-marketplace-core'), $fa(number_format($share->base?->minor ?? 0))))
            . self::reviewRow(__('نرخ کمیسیون', 'tecteb-marketplace-core'), sprintf(__('%s درصد', 'tecteb-marketplace-core'), $fa($rate)))
            . self::reviewRow(__('کمیسیون بازارگاه', 'tecteb-marketplace-core'), sprintf(__('%s تومان', 'tecteb-marketplace-core'), $fa(number_format($share->commission?->minor ?? 0))))
            . self::reviewRow(__('سهم شما', 'tecteb-marketplace-core'), sprintf(__('%s تومان', 'tecteb-marketplace-core'), $fa(number_format($share->vendorShare?->minor ?? 0))))
            . '</dl>'
            . '<p class="tv-hint">' . esc_html__('تخمینی است: نرخ نهایی هنگام ثبت سفارش snapshot می‌شود و تغییر بعدی نرخ، سفارش‌های قبلی را عوض نمی‌کند. حمل در کمیسیون نیست.', 'tecteb-marketplace-core') . '</p>'
            . '</div>';
    }

    private static function reviewRow(string $label, string $value): string
    {
        return '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value !== '' ? $value : '—') . '</dd></div>';
    }

    /** @param list<array{id:int,url:string}> $images */
    private static function gallery(array $images, int $mainImageId, string $step, int $maxImageBytes = 0): string
    {
        if ($step !== '1') {
            // Carried, not shown: the gallery belongs to step 1, and the other
            // steps must still post it back or saving would empty it.
            $html = '';
            foreach ($images as $image) {
                $html .= '<input type="hidden" name="image_ids[]" value="' . esc_attr((string) $image['id']) . '">';
            }
            return $html . '<input type="hidden" name="main_image_id" value="' . esc_attr((string) $mainImageId) . '">';
        }

        $html = '<fieldset class="tv-fieldset"><legend>' . esc_html__('تصویرها', 'tecteb-marketplace-core') . '</legend>';
        if ($images === []) {
            $html .= '<p class="tv-hint">' . esc_html__('هنوز تصویری ندارید. ارسال محصول برای بررسی دست‌کم یک تصویر لازم دارد.', 'tecteb-marketplace-core') . '</p>';
        } else {
            $html .= '<ul class="tv-gallery">';
            foreach ($images as $index => $image) {
                $id = (int) $image['id'];
                $position = $index + 1;
                $html .= '<li class="tv-gallery__item">'
                    . '<img src="' . esc_url($image['url']) . '" alt="" width="96" height="96" loading="lazy">'
                    . '<input type="hidden" name="image_ids[]" value="' . esc_attr((string) $id) . '">'
                    . '<p class="tv-gallery__position">' . esc_html(sprintf(
                        /* translators: 1: this image's position, 2: how many images there are */
                        __('تصویر %1$s از %2$s', 'tecteb-marketplace-core'),
                        PersianDigits::toPersian((string) $position),
                        PersianDigits::toPersian((string) count($images))
                    )) . '</p>'
                    // Ordinary submit buttons of the SAME form, so the order
                    // can be changed with a keyboard alone — no drag, no
                    // JavaScript, and nothing that a nested form would break.
                    . '<div class="tv-gallery__move">'
                    . self::moveButton('up', $id, $index > 0, __('یک پله بالاتر', 'tecteb-marketplace-core'))
                    . self::moveButton('down', $id, $index < count($images) - 1, __('یک پله پایین‌تر', 'tecteb-marketplace-core'))
                    . '</div>'
                    . '<label class="tv-gallery__main"><input type="radio" name="main_image_id" value="' . esc_attr((string) $id) . '"'
                    . checked($mainImageId, $id, false) . '> ' . esc_html__('تصویر اصلی', 'tecteb-marketplace-core') . '</label>'
                    . '<label class="tv-gallery__drop"><input type="checkbox" name="remove_image_ids[]" value="' . esc_attr((string) $id) . '"> '
                    . esc_html__('حذف از گالری', 'tecteb-marketplace-core') . '</label>'
                    . '</li>';
            }
            $html .= '</ul>';
        }
        $maxBytes = ProductImagePolicy::effectiveMaxBytes($maxImageBytes);
        $maxMb = max(1, (int) floor($maxBytes / 1048576));

        // The limit is WRITTEN on the control, not only enforced behind it.
        // The same number reaches the hint the vendor reads, the pre-flight
        // check in the browser and the refusal on the server, because three
        // places quoting three numbers is how «فایلم که ۲ مگابایت بود» starts.
        return $html
            . '<div class="tv-field"><label class="tv-label" for="f-product-image">'
            . esc_html__('افزودن تصویر', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tv-input" type="file" id="f-product-image" name="product_image"'
            . ' accept="' . esc_attr(implode(',', ProductImagePolicy::ALLOWED_MIME)) . '"'
            . ' data-max-bytes="' . esc_attr((string) $maxBytes) . '"'
            . ' data-allowed-mime="' . esc_attr(implode(',', ProductImagePolicy::ALLOWED_MIME)) . '"'
            . ' data-text-too-large="' . esc_attr(sprintf(
                /* translators: 1: {size}, replaced by the browser with the chosen file's size; 2: the limit. Both in megabytes. Keep {size} as it is. */
                __('این فایل %1$s مگابایت است و سقف %2$s مگابایت است. پیش از ذخیره، فایل کوچک‌تری انتخاب کنید.', 'tecteb-marketplace-core'),
                '{size}',
                PersianDigits::toPersian((string) $maxMb)
            )) . '"'
            . ' data-text-bad-type="' . esc_attr__('فقط JPEG، PNG و WebP پذیرفته می‌شود. این فایل از این سه نیست.', 'tecteb-marketplace-core') . '"'
            . ' aria-describedby="f-product-image-hint">'
            . '<p class="tv-hint" id="f-product-image-hint">' . esc_html(sprintf(
                /* translators: %s: the largest image this installation accepts, in megabytes */
                __('JPEG، PNG یا WebP تا %s مگابایت. با ذخیره همین فرم بارگذاری می‌شود.', 'tecteb-marketplace-core'),
                PersianDigits::toPersian((string) $maxMb)
            )) . '</p>'
            // Emptied on every render, filled by the browser only when a
            // chosen file is already going to be refused. A vendor without
            // JavaScript never sees it and loses nothing: the server says the
            // same thing after the save, which is where it said it before.
            . '<p class="tv-hint tv-hint--warn" id="f-product-image-problem" role="status" aria-live="polite"></p>'
            . '</div>'
            . '</fieldset>';
    }

    /**
     * One reorder button. Disabled at the ends rather than hidden, so the
     * control does not move under a keyboard user between renders.
     */
    private static function moveButton(string $direction, int $imageId, bool $enabled, string $label): string
    {
        return '<button type="submit" class="tv-btn tv-btn--secondary tv-gallery__btn" name="move_image" value="'
            . esc_attr($direction . ':' . $imageId) . '"' . ($enabled ? '' : ' disabled')
            . '>' . esc_html($label) . '</button>';
    }

    private static function submitForm(VendorUrls $urls, string $nonce, int $productId, ?OperationResult $readiness, bool $direct): string
    {
        if ($readiness !== null && !$readiness->ok) {
            return '<p class="tv-hint">' . esc_html__('پس از رفع موارد بالا، دکمه ارسال همین‌جا فعال می‌شود.', 'tecteb-marketplace-core') . '</p>';
        }
        return '<form method="post" action="' . esc_url($urls->products()) . '" class="tv-form">'
            . $nonce
            . '<input type="hidden" name="tmc_vendor_action" value="submit_product">'
            . '<input type="hidden" name="product_id" value="' . esc_attr((string) $productId) . '">'
            . '<p class="tv-form__actions">'
            . VendorUi::submit($direct
                ? __('انتشار محصول', 'tecteb-marketplace-core')
                : __('ارسال برای بررسی', 'tecteb-marketplace-core'))
            . '</p></form>';
    }
}
