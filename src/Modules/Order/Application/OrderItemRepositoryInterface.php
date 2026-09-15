<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;

/**
 * What the marketplace recorded about a sale.
 *
 * `record()` is idempotent on the WooCommerce order-item id: a checkout hook
 * that fires twice, a payment callback that retries, or a cron catching up
 * must all produce one row (and, through the ledger's own unique key, one set
 * of ledger lines).
 */
interface OrderItemRepositoryInterface
{
    /** @return int row id, 0 on failure; the existing id when already recorded */
    public function record(VendorOrderItem $item): int;

    public function find(int $id): ?VendorOrderItem;

    public function findByOrderItem(int $wcOrderItemId): ?VendorOrderItem;

    /** @return list<VendorOrderItem> this vendor's lines, newest order first */
    public function forVendor(int $vendorUserId, ?OrderItemStatus $status = null, int $limit = 100, int $offset = 0): array;

    public function countForVendor(int $vendorUserId, ?OrderItemStatus $status = null): int;

    /** @return array<string,int> status value => count */
    public function countsByStatus(int $vendorUserId): array;

    /** @return list<VendorOrderItem> every vendor's lines on one order */
    public function forOrder(int $wcOrderId): array;

    public function updateStatus(int $id, OrderItemStatus $status, string $carrier, string $trackingCode, ?string $shippedAt): bool;

    /** @return array<string,int> what this vendor has earned and owes, by account */
    public function totalsForVendor(int $vendorUserId): array;
}
