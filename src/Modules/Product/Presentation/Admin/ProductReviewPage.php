<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Product\Application\ProductCategoryDirectoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductImageLibraryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductDecisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Application\SpecTemplateRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\StorefrontFieldsInterface;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\SensitiveChange;
use Tecteb\Marketplace\Modules\Product\Infrastructure\WooCommerce\ProjectedFieldOwnership;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductMessages;
use Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Presentation\VendorMessages;

/**
 * The manager's product queue (UX §14.2).
 *
 * Two queues on one page, because they are two different questions: a product
 * waiting to go live for the first time, and a CHANGE proposed to one that is
 * already live. The second is shown as a field-by-field diff — current value
 * beside proposed value — and rejecting it leaves the live product alone,
 * which is the rule the whole revision mechanism exists to keep.
 */
final class ProductReviewPage
{
    public const SLUG = 'tmc-product-review';
    public const CAPABILITY = Capabilities::REVIEW_PRODUCTS;
    private const NONCE = 'tmc_product_review';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public static function menuLabel(): string
    {
        return __('بررسی محصولات', 'tecteb-marketplace-core');
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('دسترسی لازم را ندارید.', 'tecteb-marketplace-core'), '', ['response' => 403]);
        }
        $notice = $this->handleAction(Request::capture());
        $products = $this->container->get(ProductRepositoryInterface::class);
        $revisions = $this->container->get(ProductRevisionRepositoryInterface::class);

        echo Components::shellOpen(self::menuLabel(), self::SLUG, __('نسخه آزمایشی', 'tecteb-marketplace-core'));
        if ($notice !== null) {
            echo Components::notice(
                ProductMessages::isErrorNotice($notice['code']) ? 'error' : 'success',
                (string) (ProductMessages::notice($notice['code'], $notice['context'])
                    ?? VendorMessages::notice($notice['code'], $notice['context']))
            );
        }

        $published = $products->inStatus(ProductStatus::Published, 20);
        $this->renderQueue($products->inStatus(ProductStatus::Submitted));
        $this->renderRevisions($revisions->pending(), $products);
        $this->renderCatalogue(Request::capture());
        $this->renderPublished($published);
        $this->renderSeo($published);
        $this->renderPublishPermissions();

        echo Components::notice('info', __('تأیید محصول تازه یعنی همان نسخه منتشر می‌شود. تأیید «نسخه پیشنهادی» یعنی مقادیر پیشنهادی روی محصول منتشرشده می‌نشیند؛ موجودی از نسخه زنده گرفته می‌شود تا فروش این چند روز برنگردد.', 'tecteb-marketplace-core'));
        echo Components::shellClose();
    }

    /**
     * The one thing a decided product had nowhere to be.
     *
     * Everything shown here is read on the spot. The WooCommerce column is
     * `get_post_status()`, not a mirror column of our own: a status copied
     * into our table is a second truth, and the first person to edit the post
     * in wp-admin makes it wrong without anyone finding out.
     */
    private function renderCatalogue(Request $request): void
    {
        $products = $this->container->get(ProductRepositoryInterface::class);
        $decisions = $this->container->get(ProductDecisionRepositoryInterface::class);
        $vendors = $this->container->get(VendorRepositoryInterface::class);
        $stores = $this->container->get(StoreRepositoryInterface::class);

        $status = ProductStatus::tryFrom($request->queryKey('status'));
        $search = $request->queryText('q');
        $page = max(1, $request->queryInt('paged'));
        $rows = $products->forManager(
            $status,
            $search,
            ProductCatalogueView::PER_PAGE,
            ($page - 1) * ProductCatalogueView::PER_PAGE
        );

        $shopStatus = [];
        $editorUrls = [];
        $history = [];
        $names = [];
        foreach ($rows as $product) {
            $history[$product->id] = $decisions->forProduct($product->id, null, 12);
            if ($product->isProjected()) {
                $wcId = (int) $product->wcProductId;
                // '' when the post is gone — which the view says in words
                // rather than drawing as an empty cell.
                $shopStatus[$product->id] = (string) (get_post_status($wcId) ?: '');
                $editorUrls[$product->id] = (string) get_edit_post_link($wcId, 'url');
            }
            // The settings row first, then the application: a shop that has
            // been approved has a name in the first and a shop mid-review
            // only has one in the second. `find()` does not exist on the
            // vendor repository and never did — the page threw on its first
            // render, which is precisely what `AdminPagesRenderTest` is for.
            $names[$product->id] = $stores->find($product->vendorUserId)?->storeName
                ?: ($vendors->findApplicationByUser($product->vendorUserId)?->details->storeName ?? '');
        }

        echo ProductCatalogueView::render(
            $rows,
            $products->countsByStatusForManager($search),
            $status?->value ?? '',
            $search,
            $page,
            $products->countForManager($status, $search),
            admin_url('admin.php?page=' . self::SLUG),
            $shopStatus,
            $editorUrls,
            $history,
            $names
        );
    }

    /**
     * What the reviewer was shown, as it arrived back from their form.
     *
     * Read as a flat map of field => fingerprint and nothing else: these
     * values decide whether an approval is refused, so a nested structure
     * arriving from a POST has no business being followed.
     *
     * @return array<string,string>
     */
    private static function seenFingerprints(Request $request): array
    {
        $seen = [];
        foreach (ProjectedFieldOwnership::FIELDS as $field) {
            $value = $request->postKey('seen_' . $field);
            if ($value !== '') {
                $seen[$field] = $value;
            }
        }
        return $seen;
    }

    /** @param list<Product> $queue */
    private function renderQueue(array $queue): void
    {
        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html__('محصول‌های در انتظار انتشار', 'tecteb-marketplace-core') . '</h2>';
        if ($queue === []) {
            echo '<p>' . esc_html__('صف خالی است.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        /** @var StorefrontFieldsInterface|null $storefront */
        $storefront = $this->container->get(StorefrontFieldsInterface::class);
        foreach ($queue as $product) {
            echo $this->card($product)
                . $this->decisionForm('product', $product->id, [
                    'approve' => __('تأیید و انتشار', 'tecteb-marketplace-core'),
                    'changes' => __('نیازمند اصلاح', 'tecteb-marketplace-core'),
                    'reject' => __('رد و بایگانی', 'tecteb-marketplace-core'),
                ], $storefront?->fingerprints($product) ?? []);
        }
        echo '</section>';
    }

    /**
     * One product, rendered the way somebody deciding about it needs it.
     *
     * Everything the card shows is read HERE and handed over, so the view
     * holds no container and asks no repository — and the two derived values
     * (the shop's description and the category path) come from the same code
     * that writes them, not from a second implementation.
     */
    private function card(Product $product): string
    {
        $catalog = $this->container->get(SyncCatalog::class);
        $available = $catalog->isAvailable();
        /** @var StorefrontFieldsInterface|null $storefront */
        $storefront = $this->container->get(StorefrontFieldsInterface::class);
        $fields = $storefront?->compare($product) ?? [];

        $description = '';
        foreach ($fields as $field) {
            if ($field->key === 'description') {
                $description = $field->marketplace;
            }
        }

        return ProductReviewCardView::render(
            $product,
            $this->imagesOf($product),
            $this->container->get(ProductCategoryDirectoryInterface::class)
                ->find($product->details->categoryKey)?->path ?? '',
            $this->container->get(SpecTemplateRepositoryInterface::class)
                ->findByCategory($product->details->categoryKey),
            $this->storeOf($product->vendorUserId),
            $fields,
            $description,
            $product->isProjected() ? ($storefront?->editorUrl((int) $product->wcProductId) ?? '') : '',
            $storefront?->seoPluginName() ?? '',
            wp_nonce_field(self::NONCE, 'tmc_review_nonce', true, false),
            $available
        );
    }

    /** @return list<array{url:string,id:int,main:bool}> */
    private function imagesOf(Product $product): array
    {
        $library = $this->container->get(ProductImageLibraryInterface::class);
        $ids = array_values(array_unique(array_merge(
            $product->mainImageId > 0 ? [$product->mainImageId] : [],
            $product->imageIds
        )));
        $out = [];
        foreach ($ids as $id) {
            $out[] = [
                'id' => (int) $id,
                'url' => $library->thumbnailUrl((int) $id),
                'main' => (int) $id === $product->mainImageId,
            ];
        }
        return $out;
    }

    /** @return array<string,string> */
    private function storeOf(int $vendorUserId): array
    {
        $settings = $this->container->get(StoreRepositoryInterface::class)->find($vendorUserId);
        $profile = $this->container->get(VendorRepositoryInterface::class)->findProfileByUser($vendorUserId);
        return [
            'name' => $settings?->storeName ?? ($profile?->storeName ?? ''),
            'city' => $settings?->city ?? '',
            'status' => $profile === null
                ? __('بدون پروندهٔ فروشندگی', 'tecteb-marketplace-core')
                : ($profile->canSell
                    ? __('اجازهٔ فروش دارد', 'tecteb-marketplace-core')
                    : __('اجازهٔ فروش ندارد', 'tecteb-marketplace-core')),
        ];
    }

    /** @param list<ProductRevision> $pending */
    private function renderRevisions(array $pending, ProductRepositoryInterface $products): void
    {
        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html__('نسخه‌های پیشنهادی محصول‌های منتشرشده', 'tecteb-marketplace-core') . '</h2>';
        if ($pending === []) {
            echo '<p>' . esc_html__('نسخه پیشنهادی در انتظاری وجود ندارد.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        foreach ($pending as $revision) {
            $product = $products->find($revision->productId);
            if ($product === null) {
                continue;
            }
            echo '<article class="tmc-review"><h3 class="tmc-review__title">' . esc_html($product->details->title) . '</h3>'
                . '<p class="tmc-hint">' . esc_html(sprintf(
                    __('فروشنده #%s — نسخه فعلی روی سایت است و با رد این پیشنهاد حذف نمی‌شود.', 'tecteb-marketplace-core'),
                    PersianDigits::toPersian((string) $revision->vendorUserId)
                )) . '</p>'
                . $this->diffTable($product, $revision)
                . $this->decisionForm('revision', $revision->id, [
                    'approve' => __('تأیید تغییر', 'tecteb-marketplace-core'),
                    'reject' => __('رد تغییر', 'tecteb-marketplace-core'),
                ])
                . '</article>';
        }
        echo '</section>';
    }

    private function diffTable(Product $product, ProductRevision $revision): string
    {
        $proposed = is_array($revision->payload['details'] ?? null) ? $revision->payload['details'] : [];
        $rows = '';
        foreach (SensitiveChange::SENSITIVE as $field) {
            $current = $product->details->{$field};
            $next = $proposed[$field] ?? $current;
            if ((string) $current === (string) $next) {
                continue;
            }
            $rows .= '<tr><th scope="row">' . esc_html(ProductMessages::field($field) !== $field
                ? ProductMessages::field($field)
                : $field) . '</th>'
                . '<td>' . esc_html((string) $current !== '' ? (string) $current : '—') . '</td>'
                . '<td><strong>' . esc_html((string) $next !== '' ? (string) $next : '—') . '</strong></td></tr>';
        }
        $proposedSpecs = is_array($revision->payload['specs'] ?? null) ? array_map('strval', $revision->payload['specs']) : [];
        if (SensitiveChange::specsChanged($product->specs, $proposedSpecs)) {
            $rows .= '<tr><th scope="row">' . esc_html__('مشخصات پزشکی', 'tecteb-marketplace-core') . '</th>'
                . '<td>' . esc_html(self::flatten($product->specs)) . '</td>'
                . '<td><strong>' . esc_html(self::flatten($proposedSpecs)) . '</strong></td></tr>';
        }
        $proposedImages = is_array($revision->payload['images'] ?? null) ? array_map('intval', $revision->payload['images']) : $product->imageIds;
        if ($proposedImages !== $product->imageIds) {
            $rows .= '<tr><th scope="row">' . esc_html__('گالری', 'tecteb-marketplace-core') . '</th>'
                . '<td>' . esc_html(PersianDigits::toPersian((string) count($product->imageIds))) . '</td>'
                . '<td><strong>' . esc_html(PersianDigits::toPersian((string) count($proposedImages))) . '</strong></td></tr>';
        }
        if ($rows === '') {
            return '<p>' . esc_html__('این پیشنهاد تفاوتی با نسخه فعلی ندارد.', 'tecteb-marketplace-core') . '</p>';
        }
        return '<table class="tmc-table"><thead><tr>'
            . '<th scope="col">' . esc_html__('فیلد', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('نسخه فعلی', 'tecteb-marketplace-core') . '</th>'
            . '<th scope="col">' . esc_html__('پیشنهاد فروشنده', 'tecteb-marketplace-core') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /** @param array<string,string> $values */
    private static function flatten(array $values): string
    {
        $parts = [];
        foreach ($values as $key => $value) {
            if (trim((string) $value) !== '') {
                $parts[] = $key . ': ' . $value;
            }
        }
        return $parts === [] ? '—' : implode('، ', $parts);
    }

    /** @param array<string,string> $decisions */
    /** @param array<string,string> $seen the shop's fingerprints as this page read them */
    private function decisionForm(string $subject, int $id, array $decisions, array $seen = []): string
    {
        $html = '<form method="post" class="tmc-review__form">'
            . wp_nonce_field(self::NONCE, 'tmc_review_nonce', true, false)
            . '<input type="hidden" name="subject" value="' . esc_attr($subject) . '">'
            . '<input type="hidden" name="subject_id" value="' . esc_attr((string) $id) . '">';
        // Carried, not recomputed on submit: the whole point is to compare
        // what the reviewer SAW against what is there now.
        foreach ($seen as $field => $fingerprint) {
            $html .= '<input type="hidden" name="seen_' . esc_attr((string) $field)
                . '" value="' . esc_attr((string) $fingerprint) . '">';
        }
        $html .= ''
            . '<div class="tmc-field"><label class="tmc-field__label" for="note-' . esc_attr($subject . '-' . $id) . '">'
            . esc_html__('دلیل تصمیم (برای اصلاح و رد اجباری است)', 'tecteb-marketplace-core') . '</label>'
            . '<textarea class="tmc-input" id="note-' . esc_attr($subject . '-' . $id) . '" name="note" rows="2"></textarea></div><p>';
        foreach ($decisions as $value => $label) {
            $html .= '<button type="submit" class="tmc-button' . ($value === 'approve' ? ' tmc-button--primary' : '') . '"'
                . ' name="decision" value="' . esc_attr($value) . '">' . esc_html($label) . '</button> ';
        }
        return $html . '</p></form>';
    }

    /**
     * Published products: the same card, so a manager can see and correct
     * what is live without leaving this page.
     *
     * The queue above is about a decision. This is about the product after
     * it: the pictures a buyer sees, the text WooCommerce actually has, and
     * — when the two sides disagree — the two buttons that end it.
     *
     * @param list<Product> $published
     */
    private function renderPublished(array $published): void
    {
        echo '<section class="tmc-card"><h2 class="tmc-card__title">'
            . esc_html__('محصول‌های منتشرشده', 'tecteb-marketplace-core') . '</h2>';
        if ($published === []) {
            echo '<p>' . esc_html__('هنوز محصول منتشرشده‌ای وجود ندارد.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        echo '<p class="tmc-hint">' . esc_html__('این کارت‌ها برای اصلاح و مقایسه‌اند، نه برای تصمیم دوباره. بیست محصول آخر نمایش داده می‌شود.', 'tecteb-marketplace-core') . '</p>';
        foreach ($published as $product) {
            echo $this->card($product);
        }
        echo '</section>';
    }

    /**
     * SEO — the manager's alone (§6). The vendor's form has no such fields and
     * never had: this is the only screen in the plugin where they exist.
     *
     * @param list<Product> $published
     */
    private function renderSeo(array $published): void
    {
        $catalog = $this->container->get(SyncCatalog::class);
        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html__('سئوی محصول — فقط مدیر', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tmc-hint">' . esc_html__('نشانی عمومی، عنوان و توضیح متای محصول در اختیار فروشنده نیست. تغییر این‌ها بی‌درنگ روی صفحهٔ عمومی محصول اعمال می‌شود.', 'tecteb-marketplace-core') . '</p>';
        if (!$catalog->isAvailable()) {
            echo Components::notice('warning', __('WooCommerce فعال نیست، پس محصول‌های بازارگاه صفحهٔ عمومی ندارند و سئو جایی اعمال نمی‌شود. مقدارها ذخیره می‌شوند و با فعال‌شدن WooCommerce اعمال خواهند شد.', 'tecteb-marketplace-core'));
        }
        if ($published === []) {
            echo '<p>' . esc_html__('هنوز محصول منتشرشده‌ای وجود ندارد.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        /** @var StorefrontFieldsInterface|null $storefront */
        $storefront = $this->container->get(StorefrontFieldsInterface::class);
        $seoPlugin = $storefront?->seoPluginName() ?? '';
        if ($seoPlugin !== '') {
            // The owner had values saved in Rank Math and this form showed
            // them blank, because it has never read anything but our own
            // column. An empty box next to a filled one is not a second
            // opinion, it is a trap: somebody types into it and the real
            // fields stay as they were. So when a real SEO plugin is here,
            // the form goes and a link to ITS editor takes its place.
            $this->renderSeoHandover($published, $seoPlugin);
            return;
        }
        foreach ($published as $product) {
            $id = 'seo-' . $product->id;
            echo '<form method="post" class="tmc-review">'
                . wp_nonce_field(self::NONCE, 'tmc_review_nonce', true, false)
                . '<input type="hidden" name="subject" value="seo">'
                . '<input type="hidden" name="subject_id" value="' . esc_attr((string) $product->id) . '">'
                . '<h3 class="tmc-review__title">' . esc_html($product->details->title) . '</h3>'
                . '<p class="tmc-hint">' . esc_html(
                    $product->isProjected()
                        ? sprintf(__('شناسهٔ محصول در فروشگاه: %s', 'tecteb-marketplace-core'), PersianDigits::toPersian((string) $product->wcProductId))
                        : __('این محصول هنوز به فروشگاه نگاشت نشده است.', 'tecteb-marketplace-core')
                ) . '</p>'
                . '<div class="tmc-field"><label class="tmc-field__label" for="' . $id . '-slug">'
                . esc_html__('نشانی (slug)', 'tecteb-marketplace-core') . '</label>'
                . '<input class="tmc-input" type="text" id="' . $id . '-slug" name="seo_slug" dir="ltr" value="'
                . esc_attr($product->seo->slug) . '"></div>'
                . '<div class="tmc-field"><label class="tmc-field__label" for="' . $id . '-title">'
                . esc_html__('عنوان متا', 'tecteb-marketplace-core') . '</label>'
                . '<input class="tmc-input" type="text" id="' . $id . '-title" name="seo_title" value="'
                . esc_attr($product->seo->title) . '"></div>'
                . '<div class="tmc-field"><label class="tmc-field__label" for="' . $id . '-desc">'
                . esc_html__('توضیح متا', 'tecteb-marketplace-core') . '</label>'
                . '<textarea class="tmc-input" id="' . $id . '-desc" name="seo_description" rows="2">'
                . esc_textarea($product->seo->description) . '</textarea></div>'
                . '<p><button type="submit" class="tmc-button tmc-button--primary" name="decision" value="save">'
                . esc_html__('ذخیره سئو', 'tecteb-marketplace-core') . '</button></p>'
                . '</form>';
        }
        echo '</section>';
    }

    /**
     * When a real SEO plugin is installed, it is the one place SEO lives.
     *
     * Nothing is deleted. `_tmc_seo_*` values written by earlier versions are
     * still in the database and still shown here — READ-ONLY, labelled as
     * this plugin's own older data, with the name of the field they belong in
     * on the other side. They are not copied across: writing into another
     * plugin's meta keys from here would be this plugin guessing at that
     * plugin's storage contract, and the day the guess is wrong it is the
     * owner's search results that pay for it.
     *
     * @param list<Product> $published
     */
    private function renderSeoHandover(array $published, string $plugin): void
    {
        /** @var StorefrontFieldsInterface|null $storefront */
        $storefront = $this->container->get(StorefrontFieldsInterface::class);
        echo Components::notice('info', sprintf(
            /* translators: %s: the SEO plugin's name, e.g. Rank Math */
            __('سئوی این محصول‌ها در «%s» تنظیم می‌شود — همان‌جایی که مقدارهای فعلی‌تان ذخیره شده‌اند. فرم جداگانهٔ سئو از این صفحه برداشته شد چون مقدارهای آن افزونه را نمی‌خواند و خالی نشان می‌داد.', 'tecteb-marketplace-core'),
            $plugin
        ));
        echo '<ul class="tmc-list">';
        foreach ($published as $product) {
            $editor = $product->isProjected() ? $storefront?->editorUrl((int) $product->wcProductId) ?? '' : '';
            echo '<li><strong>' . esc_html($product->details->title) . '</strong> — '
                . ($editor !== ''
                    ? '<a href="' . esc_url($editor) . '">' . esc_html(sprintf(
                        /* translators: %s: the SEO plugin's name */
                        __('ویرایش سئو در %s', 'tecteb-marketplace-core'),
                        $plugin
                    )) . '</a>'
                    : esc_html__('هنوز در ووکامرس ساخته نشده است.', 'tecteb-marketplace-core'))
                . self::legacySeo($product)
                . '</li>';
        }
        echo '</ul></section>';
    }

    /** The old plugin-specific values, shown so nobody thinks they were deleted. */
    private static function legacySeo(Product $product): string
    {
        $rows = array_filter([
            __('نشانی (slug)', 'tecteb-marketplace-core') => trim($product->seo->slug),
            __('عنوان متا', 'tecteb-marketplace-core') => trim($product->seo->title),
            __('توضیح متا', 'tecteb-marketplace-core') => trim($product->seo->description),
        ], static fn (string $v): bool => $v !== '');
        if ($rows === []) {
            return '';
        }
        $out = '<details class="tmc-history"><summary>'
            . esc_html__('دادهٔ سئوی قدیمیِ این افزونه (فقط برای دیدن)', 'tecteb-marketplace-core')
            . '</summary><p class="tmc-card__note">'
            . esc_html__('این مقدارها پاک نشده‌اند و جایی هم کپی نمی‌شوند. اگر می‌خواهید، خودتان آن‌ها را در افزونهٔ سئو بگذارید.', 'tecteb-marketplace-core')
            . '</p><ul>';
        foreach ($rows as $label => $value) {
            $out .= '<li>' . esc_html($label . ': ') . '<code>' . esc_html($value) . '</code></li>';
        }
        return $out . '</ul></details>';
    }

    private function renderPublishPermissions(): void
    {
        $policy = $this->container->get(ProductPublishPolicy::class);
        $granted = $policy->granted();
        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html__('مجوز انتشار مستقیم', 'tecteb-marketplace-core') . '</h2>'
            . '<p class="tmc-hint">' . esc_html__('این مجوز از «اجازه فروش» جداست: فروشنده‌ای که آن را دارد، محصولش بدون صف بررسی منتشر می‌شود. پیش‌فرض برای همه خاموش است.', 'tecteb-marketplace-core') . '</p>';
        if ($granted === []) {
            echo '<p>' . esc_html__('هیچ فروشنده‌ای این مجوز را ندارد.', 'tecteb-marketplace-core') . '</p>';
        } else {
            echo '<ul class="tmc-list">';
            foreach ($granted as $vendorId) {
                echo '<li>' . Components::code('#' . $vendorId)
                    . ' <form method="post" class="tmc-inline">' . wp_nonce_field(self::NONCE, 'tmc_review_nonce', true, false)
                    . '<input type="hidden" name="subject" value="publishing">'
                    . '<input type="hidden" name="subject_id" value="' . esc_attr((string) $vendorId) . '">'
                    . '<button type="submit" class="tmc-button" name="decision" value="revoke">'
                    . esc_html__('برداشتن مجوز', 'tecteb-marketplace-core') . '</button></form></li>';
            }
            echo '</ul>';
        }
        echo '<form method="post">' . wp_nonce_field(self::NONCE, 'tmc_review_nonce', true, false)
            . '<input type="hidden" name="subject" value="publishing">'
            . '<div class="tmc-field"><label class="tmc-field__label" for="grant-vendor">'
            . esc_html__('شناسه کاربری فروشنده', 'tecteb-marketplace-core') . '</label>'
            . '<input class="tmc-input tmc-input--short" type="text" id="grant-vendor" name="subject_id" dir="ltr" inputmode="numeric"></div>'
            . '<p><button type="submit" class="tmc-button tmc-button--primary" name="decision" value="grant">'
            . esc_html__('صدور مجوز انتشار مستقیم', 'tecteb-marketplace-core') . '</button></p></form></section>';
    }

    /** @return array{code:string,context:array<string,scalar|null>}|null */
    private function handleAction(Request $request): ?array
    {
        if (!$request->isPost() || !$request->hasPost('decision')) {
            return null;
        }
        if (!$request->nonceOk('tmc_review_nonce', self::NONCE)) {
            return ['code' => 'forbidden', 'context' => []];
        }
        $review = $this->container->get(ReviewProducts::class);
        $id = $request->postInt('subject_id');
        $note = $request->postTextarea('note');
        $result = match ($request->postKey('subject') . ':' . $request->postKey('decision')) {
            // The fingerprints the review screen rendered travel back with the
            // decision. A manager can fix a typo in WooCommerce while this
            // page is open, and an approval that wrote over it would undo an
            // edit nobody was shown.
            'product:approve' => $review->approve($id, self::seenFingerprints($request)),
            'product:changes' => $review->requestChanges($id, $note),
            'product:reject' => $review->reject($id, $note),
            'revision:approve' => $review->approveRevision($id),
            'revision:reject' => $review->rejectRevision($id, $note),
            'seo:save' => $review->setSeo(
                $id,
                $request->postText('seo_slug'),
                $request->postText('seo_title'),
                $request->postTextarea('seo_description')
            ),
            'publishing:grant' => $review->setDirectPublishing($id, true),
            'publishing:revoke' => $review->setDirectPublishing($id, false),
            'storefront:prepare' => $review->prepareStorefront($id),
            'correct:save' => $review->correct($id, [
                'title' => $request->postText('fix_title'),
                'short_description' => $request->postTextarea('fix_short'),
                'category' => $request->postText('fix_category'),
            ]),
            'field:keep', 'field:accept' => $review->resolveField(
                $id,
                $request->postKey('field'),
                $request->postKey('decision')
            ),
            'product_fields:keep', 'product_fields:accept' => $review->resolveProduct(
                $id,
                $request->postKey('decision')
            ),
            default => null,
        };
        return $result === null ? null : ['code' => $result->code, 'context' => $result->context];
    }
}
