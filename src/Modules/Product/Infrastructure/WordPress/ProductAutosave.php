<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Infrastructure\WordPress\Http\Request;
use Tecteb\Marketplace\Modules\Product\Application\ProductDraftStoreInterface;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * One endpoint, one job: keep what somebody typed.
 *
 * It is `admin-ajax.php` rather than a route on the vendor area because the
 * vendor area answers in HTML through a POST-redirect-GET, and a background
 * save that redirected would fight the form the user is still typing in.
 * `wp_ajax_` (the logged-in variant, without `nopriv`) is reachable from the
 * front end and refuses a logged-out caller before any of our code runs.
 *
 * **It never touches the product.** The draft is the person's own copy; the
 * catalogue, the projector and WooCommerce see nothing. So the permission it
 * needs is «may edit this shop» and no more, and a failed autosave costs
 * nothing but the draft.
 *
 * **It reports the CURRENT revision back.** The browser learns that somebody
 * else has saved the product while this form has been open, and can say so
 * before the user spends another ten minutes on a save that will be refused.
 * Finding that out at submit time is the whole experience this endpoint exists
 * to avoid.
 */
final class ProductAutosave
{
    public const ACTION = 'tmc_product_autosave';

    public const NONCE = 'tmc_product_autosave';

    /** Fields the draft keeps. Anything else in the POST is not a product. */
    private const FIELDS = [
        'title', 'category_key', 'price', 'sale_price', 'sku', 'stock',
        'short_description', 'description', 'purchase_limit', 'step',
    ];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(['code' => 'bad_nonce'], 403);
        }
        $userId = get_current_user_id();
        if ($userId === 0) {
            wp_send_json_error(['code' => 'not_logged_in'], 401);
        }

        /** @var StaffAccess $access */
        $access = $this->container->get(StaffAccess::class);
        $vendorUserId = $access->storeFor($userId);
        if ($vendorUserId === null
            || !$access->can($userId, $vendorUserId, StaffArea::Product, StaffLevel::Edit)) {
            wp_send_json_error(['code' => 'forbidden'], 403);
        }

        // Through Request like every other read in this plugin. It is the one
        // file allowed to touch a superglobal, and an endpoint that reached
        // around it would be the one place the sanitising rule leaked.
        $request = Request::capture();
        $productId = $request->postInt('product_id');
        $revision = $request->postText('revision');

        $payload = [];
        foreach (self::FIELDS as $field) {
            if (!$request->hasPost($field)) {
                continue;
            }
            $payload[$field] = in_array($field, ['description', 'short_description'], true)
                ? $request->postTextarea($field)
                : $request->postText($field);
        }
        if ($payload === []) {
            wp_send_json_error(['code' => 'empty'], 400);
        }

        /** @var ProductDraftStoreInterface $drafts */
        $drafts = $this->container->get(ProductDraftStoreInterface::class);
        if (!$drafts->put($userId, $productId, $payload, $revision)) {
            wp_send_json_error(['code' => 'draft_too_large'], 413);
        }

        wp_send_json_success([
            'saved_at' => current_time('H:i'),
            // Read back from the row, not echoed from the request: the point is
            // to notice a change the browser does not know about yet.
            'current_revision' => $this->currentRevision($productId, $vendorUserId),
            'submitted_revision' => $revision,
        ]);
    }

    private function currentRevision(int $productId, int $vendorUserId): string
    {
        if ($productId <= 0) {
            return '';
        }
        /** @var ProductRepositoryInterface $products */
        $products = $this->container->get(ProductRepositoryInterface::class);
        $product = $products->findOwned($productId, $vendorUserId);
        return $product === null ? '' : $product->updatedAt;
    }
}
