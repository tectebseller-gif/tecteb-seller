<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Application;

use Tecteb\Marketplace\Modules\Order\Application\OrderOperationsGate;
use Tecteb\Marketplace\Modules\Product\Domain\Product;
use Tecteb\Marketplace\Modules\Product\Domain\ProductStatus;
use Tecteb\Marketplace\Modules\Product\Domain\ProductType;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;

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
 * For the marketplace's own products there are four reasons to refuse, and
 * each has a sentence a shopper can act on:
 *
 *   ORDERS_BLOCKED  the financial rules are not settled, so the marketplace
 *                   does not sell (it does not "sell and hide the numbers")
 *   VENDOR_STOPPED  the shop is suspended — immediately, not at next login
 *   NOT_PUBLISHED   the product is not live in the marketplace's own workflow
 *   OUT_OF_STOCK    zero stock stops the purchase (A.1)
 */
final class PurchasePolicy
{
    public const ALLOWED = 'allowed';
    public const NOT_OURS = 'not_ours';
    public const ORDERS_BLOCKED = 'orders_blocked';
    public const VENDOR_STOPPED = 'vendor_stopped';
    public const NOT_PUBLISHED = 'not_published';
    public const OUT_OF_STOCK = 'out_of_stock';

    /** @var array<int,array{decision:string, product:?Product}> per-request memo */
    private array $memo = [];

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly StaffAccess $access,
        private readonly OrderOperationsGate $gate
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
        return $this->gate->check()['ready'];
    }

    private function decisionFor(Product $product, int $stockOverride): string
    {
        if (!$this->marketplaceIsOperational()) {
            return self::ORDERS_BLOCKED;
        }
        // Read on every question rather than cached across requests: a
        // suspension that takes effect at the next login is not a suspension.
        if (!$this->access->vendorCanTrade($product->vendorUserId)) {
            return self::VENDOR_STOPPED;
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
