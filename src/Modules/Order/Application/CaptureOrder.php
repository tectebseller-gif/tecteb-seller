<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Finance\Application\RecordCommission;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Product\Application\ProductRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Application\SyncCatalog;

/**
 * Turns one WooCommerce order into the marketplace's own record of it.
 *
 * The order of operations is the contract, and it is deliberately this way
 * round:
 *
 *   1. resolve the commission for the line — if it cannot be resolved, the
 *      line is NOT recorded as a sale the vendor is owed for; it is recorded
 *      as unrecorded and reported. Nothing is invented (FIN-02).
 *   2. write the ledger lines under a key derived from the order item, so a
 *      retried callback writes nothing twice (FIN-03).
 *   3. store the line with its commission SNAPSHOT, so a later rate change
 *      cannot rewrite history (FIN-01).
 *   4. pull the new stock back from WooCommerce, because the sale has just
 *      moved it and the marketplace's mirror must catch up (ADR-008).
 *
 * Lines that are not marketplace products are skipped entirely: a shop
 * product or a Dokan product in the same basket is none of this module's
 * business, and touching it is exactly what the owner forbade.
 */
final class CaptureOrder
{
    public function __construct(
        private readonly OrderItemRepositoryInterface $items,
        private readonly ProductRepositoryInterface $products,
        private readonly RecordCommission $commissions,
        private readonly SyncCatalog $catalog,
        private readonly OrderOperationsGate $gate,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * @param list<array{
     *   order_item_id:int, wc_product_id:int, variation_id:?int, title:string,
     *   sku:string, quantity:int, line_total_minor:int, line_tax_minor:int
     * }> $lines the WooCommerce order's lines, already read into plain values
     * @return array{captured:int, skipped:int, unrecorded:int, vendors:list<int>}
     */
    public function capture(int $orderId, array $lines, string $currency = 'IRR', int $exponent = 0): array
    {
        $captured = 0;
        $skipped = 0;
        $unrecorded = 0;
        $vendors = [];

        foreach ($lines as $line) {
            $product = $this->products->findByWcProduct((int) ($line['wc_product_id'] ?? 0));
            if ($product === null) {
                $skipped++;         // not ours: a shop or Dokan product
                continue;
            }
            if ($this->items->findByOrderItem((int) $line['order_item_id']) !== null) {
                continue;           // already recorded; the hook fired twice
            }

            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $base = Money::of((int) ($line['line_total_minor'] ?? 0), $currency, $exponent);
            $tax = Money::of((int) ($line['line_tax_minor'] ?? 0), $currency, $exponent);
            $eventKey = self::eventKey($orderId, (int) $line['order_item_id']);

            $outcome = $this->commissions->accrue(
                $eventKey,
                $product->vendorUserId,
                (string) $orderId,
                (string) $line['order_item_id'],
                $base,
                [
                    'product' => (string) $product->id,
                    'vendor' => (string) $product->vendorUserId,
                    'category' => $product->details->categoryKey,
                ],
                $tax
            );

            $recorded = $outcome->isCalculated();
            if (!$recorded) {
                $unrecorded++;
            }
            $this->items->record(new VendorOrderItem(
                0,
                $orderId,
                (int) $line['order_item_id'],
                $product->id,
                $product->vendorUserId,
                (string) ($line['title'] ?? $product->details->title),
                (string) ($line['sku'] ?? $product->details->sku),
                $quantity,
                (int) round($base->minor / $quantity),
                $base->minor,
                $tax->minor,
                $recorded ? $outcome->commission?->minor : null,
                $recorded ? $outcome->vendorShare?->minor : null,
                $recorded ? $outcome->snapshot?->rateBasisPoints : null,
                $recorded ? (string) $outcome->snapshot?->rateSource : '',
                $recorded ? $eventKey : '',
                OrderItemStatus::Placed,
                '',
                '',
                null,
                ($line['variation_id'] ?? null) === null ? null : (int) $line['variation_id'],
                (int) $line['wc_product_id']
            ));

            // The sale has moved WooCommerce's stock; bring it home so the
            // vendor's own list stops showing yesterday's number.
            $this->catalog->pullStock($product->id);

            $captured++;
            if (!in_array($product->vendorUserId, $vendors, true)) {
                $vendors[] = $product->vendorUserId;
            }
        }

        if ($captured > 0 || $unrecorded > 0) {
            $this->audit->log(AuditEventCatalog::ORDER_CAPTURED, 0, 'order', (string) $orderId, [
                'order_id' => $orderId,
                'vendors' => count($vendors),
                'items' => $captured,
                'recorded' => $captured - $unrecorded,
                'skipped' => $skipped,
            ]);
        }
        return ['captured' => $captured, 'skipped' => $skipped, 'unrecorded' => $unrecorded, 'vendors' => $vendors];
    }

    /**
     * Brings WooCommerce's stock home for every marketplace line of an order.
     *
     * Capture alone is not enough and this is why: the checkout hook fires
     * BEFORE WooCommerce reduces stock — reduction happens when the order
     * reaches a paid status. Measured on a real site, the mirror still read
     * the pre-sale number after checkout. So the stock hooks call this
     * afterwards, and a sold-out product stops looking available on the
     * vendor's own dashboard (ADR-008).
     *
     * @param list<array{wc_product_id:int}> $lines
     * @return int how many marketplace products were refreshed
     */
    public function refreshStock(array $lines): int
    {
        $refreshed = 0;
        $seen = [];
        foreach ($lines as $line) {
            $wcProductId = (int) ($line['wc_product_id'] ?? 0);
            if ($wcProductId <= 0 || isset($seen[$wcProductId])) {
                continue;
            }
            $seen[$wcProductId] = true;
            $product = $this->products->findByWcProduct($wcProductId);
            if ($product === null) {
                continue;        // not ours: its stock is not our business
            }
            $this->catalog->pullStock($product->id);
            $refreshed++;
        }
        return $refreshed;
    }

    /** Whether the marketplace may sell at all right now. */
    public function isOperational(): bool
    {
        return $this->gate->check()['ready'];
    }

    /** Stable and derivable, so a retry produces the same key (FIN-03). */
    public static function eventKey(int $orderId, int $orderItemId): string
    {
        return 'order:' . $orderId . ':item:' . $orderItemId;
    }
}
