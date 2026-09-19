<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface;

/**
 * "May this storefront product be bought?" — asked of the marketplace, about
 * marketplace products only.
 *
 * The scoping is the most important line in this class. A product that is not
 * the marketplace's own answers `NOT_OURS`, and the caller must then change
 * nothing: the shop's existing products and Dokan's products keep whatever
 * WooCommerce already decided about them, whatever state the marketplace is
 * in. The owner's instruction is explicit — «خرید فعلی سایت و دکان تحت تأثیر
 * این قفل قرار نگیرند» — and this is where that holds or fails.
 *
 * For the marketplace's own products there are five reasons to refuse, and
 * each has a short sentence a shopper can act on (PurchaseMessages):
 *
 *   STOREFRONT_STOPPED the marketplace's shelf was deliberately emptied — by
 *                   a manager, or by the plugin being deactivated
 *   ORDERS_BLOCKED  the financial rules are not settled, so the marketplace
 *                   does not sell (it does not "sell and hide the numbers")
 *   VENDOR_STOPPED  the shop is suspended — immediately, not at next login
 *   VENDOR_CLOSED   the shop set itself temporarily closed (A.5). NOT the same
 *                   as suspended: nobody did anything wrong, the shop is away,
 *                   and the two say different things to a shopper.
 *   NOT_PUBLISHED   the product is not live in the marketplace's own workflow
 *   OUT_OF_STOCK    zero stock stops the purchase (A.1)
 *
 * Temporary closure stops NEW purchases and nothing else. A.5 is explicit that
 * «سفارش‌های قبلی باید ادامه یابند», so closure is asked here — at the point of
 * buying — and nowhere near the order module. An order placed before the shop
 * closed goes on being prepared, shipped, returned and settled exactly as it
 * would have.
 */
final class PurchasePolicy
{
    public const ALLOWED = 'allowed';
    public const NOT_OURS = 'not_ours';
    public const STOREFRONT_STOPPED = 'storefront_stopped';
    public const ORDERS_BLOCKED = 'orders_blocked';
    public const VENDOR_STOPPED = 'vendor_stopped';
    public const VENDOR_CLOSED = 'vendor_closed';
    public const NOT_PUBLISHED = 'not_published';
    public const OUT_OF_STOCK = 'out_of_stock';

    /**
     * Per-request memo.
     *
     * «Per-request» is the intended lifetime and not automatically the real
     * one: the container hands out one instance and keeps it, so inside a
     * long-lived process — WP-CLI, a queue worker — this survives changes
     * that a browser request would never have seen. `forget()` is how the one
     * operation that changes «is this ours» tells it so.
     *
     * @var array<int,array{decision:string, product:?Product}>
     */
    private array $memo = [];

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly StaffAccess $access,
        private readonly OrderOperationsGate $gate,
        private readonly ?StorefrontSwitch $storefront = null,
        // Optional so every existing construction site keeps working; a build
        // without it simply never reports a closure, which is the old
        // behaviour rather than a wrong new one.
        private readonly ?StoreRepositoryInterface $stores = null,
        private readonly ?ClockInterface $clock = null
    ) {
    }

    /**
     * @return array{decision:string, product:?Product}
     */
    public function decide(int $wcProductId, int $stockOverride = -1): array
    {
        if (isset($this->memo[$wcProductId]) && $stockOverride < 0) {
            return $this->memo[$wcProductId];
        }
        $product = $this->products->findByWcProduct($wcProductId);
        if ($product === null) {
            // Not a marketplace product. Say nothing about it, ever.
            return $this->memo[$wcProductId] = ['decision' => self::NOT_OURS, 'product' => null];
        }
        $decision = $this->decisionFor($product, $stockOverride);
        $answer = ['decision' => $decision, 'product' => $product];
        if ($stockOverride < 0) {
            $this->memo[$wcProductId] = $answer;
        }
        return $answer;
    }

    /**
     * Drop the remembered answer for one product, or for all of them.
     *
     * Called when ownership moves between `observed` and `marketplace`,
     * because that is the only operation that changes whether a storefront
     * product is this marketplace's at all — and a remembered `not_ours`
     * would then refuse to sell a product the marketplace had just taken on.
     * Measured on the disposable site: without this, a transfer followed by
     * a sale in the same process answered `not_ours` for a product whose
     * ownership column already read `marketplace`.
     */
    public function forget(?int $wcProductId = null): void
    {
        if ($wcProductId === null) {
            $this->memo = [];
            return;
        }
        unset($this->memo[$wcProductId]);
    }

    public function isOurs(int $wcProductId): bool
    {
        return $this->decide($wcProductId)['decision'] !== self::NOT_OURS;
    }

    /** True only for a marketplace product that may actually be sold. */
    public function allows(int $wcProductId, int $stockOverride = -1): bool
    {
        return $this->decide($wcProductId, $stockOverride)['decision'] === self::ALLOWED;
    }

    /** Whether the marketplace may sell anything at all right now. */
    public function marketplaceIsOperational(): bool
    {
        return $this->marketplaceRefusal() === null;
    }

    /**
     * The marketplace-wide refusal, or null when there is none.
     *
     * Asked before anything about a particular product, and in this order: an
     * emptied shelf outranks an open financial decision, because the shelf was
     * emptied deliberately and the flag is what a half-finished withdrawal
     * leaves behind.
     */
    private function marketplaceRefusal(): ?string
    {
        if ($this->storefront?->isStopped() === true) {
            return self::STOREFRONT_STOPPED;
        }
        return $this->gate->check()['ready'] ? null : self::ORDERS_BLOCKED;
    }

    /**
     * Whether this shop has set itself temporarily closed, today.
     *
     * Read on every question, like the suspension above and for the same
     * reason: a closure that begins at the next cache flush is not a closure.
     * The date is the SITE's today, because that is the day the shop meant.
     */
    private function vendorIsClosed(int $vendorUserId): bool
    {
        if ($this->stores === null || $this->clock === null) {
            return false;
        }
        return $this->stores->find($vendorUserId)?->isClosedOn(
            $this->clock->now()->format('Y-m-d')
        ) === true;
    }

    private function decisionFor(Product $product, int $stockOverride): string
    {
        $marketplace = $this->marketplaceRefusal();
        if ($marketplace !== null) {
            return $marketplace;
        }
        // Read on every question rather than cached across requests: a
        // suspension that takes effect at the next login is not a suspension.
        if (!$this->access->vendorCanTrade($product->vendorUserId)) {
            return self::VENDOR_STOPPED;
        }
        // After suspension, never before: a suspended shop is suspended
        // whatever its holiday dates say, and telling a shopper «فروشگاه در
        // تعطیلات است» about a shop the marketplace has stopped would be the
        // wrong sentence and a misleading one.
        if ($this->vendorIsClosed($product->vendorUserId)) {
            return self::VENDOR_CLOSED;
        }
        if ($product->status !== ProductStatus::Published) {
            return self::NOT_PUBLISHED;
        }
        // A variable product holds no stock of its own — each combination
        // does. Reading the parent's column here would call a shop with six
        // sizes in stock "ناموجود", so the question is left to WooCommerce,
        // which is the source of truth for stock anyway (ADR-008) and asks
        // the variation filter about each combination separately.
        if ($product->details->type === ProductType::VARIABLE) {
            return self::ALLOWED;
        }
        $stock = $stockOverride >= 0 ? $stockOverride : $product->details->stock;
        if ($stock <= 0) {
            return self::OUT_OF_STOCK;
        }
        return self::ALLOWED;
    }
}
