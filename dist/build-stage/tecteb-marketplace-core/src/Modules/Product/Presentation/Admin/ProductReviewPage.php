<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Presentation\Admin;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Core\Support\PersianDigits;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Admin\Presentation\Components;
use Tecteb\Marketplace\Modules\Product\Application\ProductPublishPolicy;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRevisionRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\ReviewProducts;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductRevision;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;
use Tecteb\Marketplace\Modules\Product\Domain\SensitiveChange;
use Tecteb\Marketplace\Modules\Product\Presentation\ProductMessages;
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

        $this->renderQueue($products->inStatus(ProductStatus::Submitted));
        $this->renderRevisions($revisions->pending(), $products);
        $this->renderSeo($products->inStatus(ProductStatus::Published, 20));
        $this->renderPublishPermissions();

        echo Components::notice('info', __('تأیید محصول تازه یعنی همان نسخه منتشر می‌شود. تأیید «نسخه پیشنهادی» یعنی مقادیر پیشنهادی روی محصول منتشرشده می‌نشیند؛ موجودی از نسخه زنده گرفته می‌شود تا فروش این چند روز برنگردد.', 'tecteb-marketplace-core'));
        echo Components::shellClose();
    }

    /** @param list<Product> $queue */
    private function renderQueue(array $queue): void
    {
        echo '<section class="tmc-card"><h2 class="tmc-card__title">' . esc_html__('محصول‌های در انتظار انتشار', 'tecteb-marketplace-core') . '</h2>';
        if ($queue === []) {
            echo '<p>' . esc_html__('صف خالی است.', 'tecteb-marketplace-core') . '</p></section>';
            return;
        }
        $fa = static fn (string|int $v): string => PersianDigits::toPersian((string) $v);
        foreach ($queue as $product) {
            $d = $product->details;
            echo '<article class="tmc-review"><h3 class="tmc-review__title">' . esc_html($d->title) . '</h3>'
                . '<dl class="tmc-review__facts">'
                . '<div><dt>' . esc_html__('فروشنده', 'tecteb-marketplace-core') . '</dt><dd>'
                . Components::code('#' . $product->vendorUserId) . '</dd></div>'
                . '<div><dt>' . esc_html__('دسته', 'tecteb-marketplace-core') . '</dt><dd>' . esc_html($d->categoryKey) . '</dd></div>'
                . '<div><dt>' . esc_html__('قیمت', 'tecteb-marketplace-core') . '</dt><dd>' . esc_html($fa(number_format($d->priceMinor))) . '</dd></div>'
                . '<div><dt>' . esc_html__('موجودی', 'tecteb-marketplace-core') . '</dt><dd>' . esc_html($fa($d->stock)) . '</dd></div>'
                . '<div><dt>' . esc_html__('تصویر', 'tecteb-marketplace-core') . '</dt><dd>' . esc_html($fa(count($product->imageIds))) . '</dd></div>'
                . '<div><dt>' . esc_html__('مشخصات پزشکی', 'tecteb-marketplace-core') . '</dt><dd>' . esc_html($fa(count($product->specs))) . '</dd></div>'
                . '</dl>'
                . $this->decisionForm('product', $product->id, [
                    'approve' => __('تأیید و انتشار', 'tecteb-marketplace-core'),
                    'changes' => __('نیازمند اصلاح', 'tecteb-marketplace-core'),
                    'reject' => __('رد و بایگانی', 'tecteb-marketplace-core'),
                ])
                . '</article>';
        }
        echo '</section>';
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
    private function decisionForm(string $subject, int $id, array $decisions): string
    {
        $html = '<form method="post" class="tmc-review__form">'
            . wp_nonce_field(self::NONCE, 'tmc_review_nonce', true, false)
            . '<input type="hidden" name="subject" value="' . esc_attr($subject) . '">'
            . '<input type="hidden" name="subject_id" value="' . esc_attr((string) $id) . '">'
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
            'product:approve' => $review->approve($id),
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
            default => null,
        };
        return $result === null ? null : ['code' => $result->code, 'context' => $result->context];
    }
}
