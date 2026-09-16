<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Operations\Infrastructure\Rest;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\OutboxRepositoryInterface;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Operations\Application\RecordEvent;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * `tecteb/v1` — the read contract, versioned and scoped to one store.
 *
 * **Authorization is the panel's, not a second one.** There is no API key table
 * and no bearer secret of our own. A caller authenticates as a WordPress user —
 * an application password over Basic auth is what a script would use — and
 * `StaffAccess` then answers the same question it answers for every page: may
 * THIS user read THIS store? Inventing a parallel identity would have meant two
 * places deciding who may see a vendor's orders, and the day they disagreed the
 * looser one would win.
 *
 * **Every route is scoped by a vendor id in the path, and the scope is checked
 * against the caller, not read from the caller.** A vendor asking for another
 * vendor's products gets 403 rather than an empty list, because an empty list
 * is indistinguishable from «that shop has no products» and teaches an attacker
 * which ids exist.
 *
 * **Reads only.** No route here writes anything. The write contract is not
 * «coming later as a POST on the same path»: it is a decision about who may
 * change a marketplace from outside it, and nobody has made it.
 *
 * **The namespace is `tecteb/v1`, separate from `tmc/v1`.** The health route
 * is an operational probe with its own compatibility promise; a business read
 * contract that shared its namespace would be tied to that promise for ever.
 */
final class MarketplaceController
{
    public const NAMESPACE = 'tecteb/v1';

    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/vendors/(?P<vendor>\d+)/products', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'products'],
            'permission_callback' => [$this, 'mayReadVendor'],
            'args' => $this->pagingArgs(),
        ]);
        register_rest_route(self::NAMESPACE, '/vendors/(?P<vendor>\d+)/orders', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'orders'],
            'permission_callback' => [$this, 'mayReadVendor'],
            'args' => $this->pagingArgs(),
        ]);
        register_rest_route(self::NAMESPACE, '/events', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'events'],
            'permission_callback' => [$this, 'mayManageApi'],
            'args' => $this->pagingArgs(),
        ]);
        register_rest_route(self::NAMESPACE, '/contract', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'contract'],
            'permission_callback' => [$this, 'mayManageApi'],
        ]);
    }

    // ----------------------------------------------------------- permissions

    public function mayReadVendor(\WP_REST_Request $request): bool|\WP_Error
    {
        $vendorUserId = (int) $request['vendor'];
        $userId = get_current_user_id();
        if ($userId === 0) {
            return $this->denied('tmc_not_authenticated', __('برای این درخواست باید وارد شده باشید.', 'tecteb-marketplace-core'));
        }
        /** @var CapabilityCheckerInterface $caps */
        $caps = $this->container->get(CapabilityCheckerInterface::class);
        if ($caps->can(Capabilities::MANAGE_API)) {
            return true;                        // the manager reads every store
        }
        /** @var StaffAccess $access */
        $access = $this->container->get(StaffAccess::class);
        if ($access->can($userId, $vendorUserId, StaffArea::Product, StaffLevel::View)) {
            return true;
        }
        // 403, never an empty 200: «you may not» and «there is nothing» must
        // not be the same answer, or the endpoint becomes an id oracle.
        return $this->denied('tmc_forbidden', __('این فروشگاه در دسترس شما نیست.', 'tecteb-marketplace-core'));
    }

    public function mayManageApi(): bool|\WP_Error
    {
        /** @var CapabilityCheckerInterface $caps */
        $caps = $this->container->get(CapabilityCheckerInterface::class);
        if ($caps->can(Capabilities::MANAGE_API)) {
            return true;
        }
        return $this->denied('tmc_forbidden', __('اجازهٔ این بخش را ندارید.', 'tecteb-marketplace-core'));
    }

    // --------------------------------------------------------------- handlers

    public function products(\WP_REST_Request $request): \WP_REST_Response
    {
        $vendorUserId = (int) $request['vendor'];
        [$page, $perPage] = $this->paging($request);
        /** @var ProductRepositoryInterface $products */
        $products = $this->container->get(ProductRepositoryInterface::class);
        $all = $products->allForVendor($vendorUserId);
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

        $items = array_map(static fn (Product $product): array => [
            'id' => $product->id,
            'title' => $product->details->title,
            'sku' => $product->details->sku,
            'price_minor' => $product->details->priceMinor,
            'stock' => $product->details->stock,
            'status' => $product->status->value,
            'wc_product_id' => $product->wcProductId,
        ], $slice);

        return $this->page($items, $page, $perPage, count($all));
    }

    public function orders(\WP_REST_Request $request): \WP_REST_Response
    {
        $vendorUserId = (int) $request['vendor'];
        [$page, $perPage] = $this->paging($request);
        /** @var OrderItemRepositoryInterface $repository */
        $repository = $this->container->get(OrderItemRepositoryInterface::class);
        $rows = $repository->forVendor($vendorUserId, null, $perPage, ($page - 1) * $perPage);

        $items = array_map(static fn (VendorOrderItem $item): array => [
            'wc_order_id' => $item->orderId,
            'order_item_id' => $item->orderItemId,
            'product_id' => $item->productId,
            'title' => $item->title,
            'sku' => $item->sku,
            'quantity' => $item->quantity,
            'base_minor' => $item->baseMinor,
            'tax_minor' => $item->taxMinor,
            // The vendor's own share, and NOT the commission rate that produced
            // it. A rate is a commercial term between the marketplace and one
            // shop; putting it on a read endpoint would publish every shop's
            // terms to anybody who could read one shop's orders.
            'vendor_share_minor' => $item->vendorShareMinor,
            'status' => $item->status->value,
        ], $rows);

        return $this->page($items, $page, $perPage, $repository->countForVendor($vendorUserId));
    }

    public function events(\WP_REST_Request $request): \WP_REST_Response
    {
        [$page, $perPage] = $this->paging($request);
        /** @var OutboxRepositoryInterface $outbox */
        $outbox = $this->container->get(OutboxRepositoryInterface::class);
        $rows = $outbox->recent([], $perPage, ($page - 1) * $perPage);

        $items = array_map(static function (array $row): array {
            $payload = json_decode((string) $row['payload'], true);
            return [
                'id' => (int) $row['id'],
                'event' => (string) $row['event_type'],
                'event_id' => (string) $row['event_id'],
                'vendor_user_id' => $row['vendor_user_id'] === null ? null : (int) $row['vendor_user_id'],
                'delivery_state' => (string) $row['delivery_state'],
                'delivery_reason' => (string) $row['delivery_reason'],
                'signature' => (string) $row['signature'],
                'created_at' => (string) $row['created_at'],
                'payload' => is_array($payload) ? $payload : [],
            ];
        }, $rows);

        return $this->page($items, $page, $perPage, $outbox->count());
    }

    /** What this build publishes, so a client can check before it integrates. */
    public function contract(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'namespace' => self::NAMESPACE,
            'reads_only' => true,
            'authentication' => 'wordpress_user',
            'authorization' => 'staff_access_per_vendor',
            'event_types' => RecordEvent::types(),
            'event_signature' => [
                'header' => \Tecteb\Marketplace\Core\Events\EventSigner::HEADER,
                'timestamp_header' => \Tecteb\Marketplace\Core\Events\EventSigner::TIMESTAMP_HEADER,
                'algorithm' => \Tecteb\Marketplace\Core\Events\EventSigner::ALGORITHM,
                'version' => \Tecteb\Marketplace\Core\Events\EventSigner::VERSION,
            ],
            // Stated in the contract itself, not only in a document: a client
            // reading this knows before it writes any code that nothing is
            // delivered outward from this release.
            'outbound_delivery' => [
                'enabled' => false,
                'reason' => 'outbound_blocked',
                'note' => 'CORE-04: no outbound channel is open in this release and none can be opened by configuration.',
            ],
        ], 200);
    }

    // -------------------------------------------------------------- internals

    /** @return array<string,array<string,mixed>> */
    private function pagingArgs(): array
    {
        return [
            'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
            'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE],
        ];
    }

    /** @return array{0:int, 1:int} */
    private function paging(\WP_REST_Request $request): array
    {
        return [
            max(1, (int) $request->get_param('page')),
            max(1, min(self::MAX_PER_PAGE, (int) $request->get_param('per_page'))),
        ];
    }

    /** @param list<array<string,mixed>> $items */
    private function page(array $items, int $page, int $perPage, ?int $total, ?bool $hasMore = null): \WP_REST_Response
    {
        $body = [
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore ?? ($total !== null && $page * $perPage < $total),
        ];
        if ($total !== null) {
            $body['total'] = $total;
        }
        $response = new \WP_REST_Response($body, 200);
        $response->header('Cache-Control', 'no-store');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    private function denied(string $code, string $message): \WP_Error
    {
        return new \WP_Error($code, $message, ['status' => rest_authorization_required_code()]);
    }
}
