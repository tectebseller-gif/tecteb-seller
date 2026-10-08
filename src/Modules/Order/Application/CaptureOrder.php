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
     * @return array{captured:int, skipped:int, unrecorded:int, failed:int, refused_unit:int, repaired:int, incomplete:int, reasons:list<string>, vendors:list<int>}
     */
    public function capture(int $orderId, array $lines, string $currency = 'IRR', int $exponent = 0): array
    {
        $captured = 0;
        $skipped = 0;
        $unrecorded = 0;
        $failed = 0;
        $refusedUnit = 0;
        $repaired = 0;
        $incomplete = 0;
        /** @var list<string> every named reason this pass produced */
        $reasons = [];
        $vendors = [];

        foreach ($lines as $line) {
            $product = $this->products->findByWcProduct((int) ($line['wc_product_id'] ?? 0));
            if ($product === null) {
                $skipped++;         // not ours: a shop or Dokan product
                continue;
            }
            $already = $this->items->findByOrderItem((int) $line['order_item_id']);
            if ($already !== null) {
                // The hook fired twice — OR the row is one of `alpha.38`'s
                // half-finished ones, and this is the only moment anything
                // looks at it.
                //
                // A row with a null share and an empty `ledger_event` beside a
                // perfectly good ledger event was the end state of the defect
                // §5 names: the item write was unchecked, a refused ledger
                // write was reported as «already recorded», and every later
                // callback skipped the line BECAUSE the row existed. So this
                // branch repairs instead of skipping, from the event the
                // ledger already holds — never at today's rate.
                $repair = $this->repair($already, $orderId);
                if ($repair !== '') {
                    $reasons[] = $repair;
                    if ($repair === 'line_repaired') {
                        $repaired++;
                    } else {
                        $incomplete++;
                    }
                }
                continue;
            }

            // The unit the READER measured, when it supplied one. A line that
            // carries its own currency and exponent is authoritative: the
            // arguments to this method are the order's unit, and the line's is
            // the same unit read at the same moment. Until `alpha.39` neither
            // travelled at all and this method's `'IRR', 0` default decided
            // the unit of every order on every site.
            $lineCurrency = (string) ($line['currency'] ?? '') !== '' ? (string) $line['currency'] : $currency;
            $lineExponent = isset($line['exponent']) ? (int) $line['exponent'] : $exponent;
            // «اگر ارز یا مقیاسی پشتیبانی نمی‌شود، صریح و قابل تشخیص رد شود؛
            // با واحد حدسی ثبت نشود.» The reader names what it could not read
            // exactly; nothing is written for such a line, and the count says
            // so instead of a silently rounded amount reaching the ledger.
            $unitError = (string) ($line['unit_error'] ?? '');
            // And one more unit question the reader cannot answer: does this
            // unit match the books this vendor ALREADY has?
            //
            // `DbLedgerRepository::balances()` sums `amount_minor` grouped by
            // account and nothing else, while `Money::assertSameUnit()`
            // refuses to combine two units one layer down. Until `alpha.39`
            // the two could not disagree, because the capture forced
            // `'IRR', 0` on every row — the books were uniform because the
            // unit was ignored. Now that the order's real unit is recorded,
            // «uniform» has to be checked, and a second unit is a refusal:
            // a marketplace records in one kind of money, and adding two kinds
            // together is not something to do quietly on a vendor's balance.
            //
            // Scoped per vendor and asked only once a unit is readable, so a
            // single-currency site — every real one — behaves exactly as
            // before and pays one indexed query per captured line.
            if ($unitError === '' && !$this->unitMatchesTheBooks($product->vendorUserId, $lineCurrency, $lineExponent)) {
                $unitError = 'unit_changed';
            }
            if ($unitError !== '') {
                $refusedUnit++;
                $reasons[] = $unitError;
                continue;
            }

            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $base = Money::of((int) ($line['line_total_minor'] ?? 0), $lineCurrency, $lineExponent);
            $tax = Money::of((int) ($line['line_tax_minor'] ?? 0), $lineCurrency, $lineExponent);
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
                $reasons[] = $outcome->reason;
            }
            $stored = $this->items->record(new VendorOrderItem(
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

            // CHECKED, not fired and forgotten. `alpha.38` dropped this
            // answer and still said `captured++`, so a storage failure read as
            // a captured sale — and because the next attempt looks for the row
            // that was never written, the retry was the only thing that could
            // have fixed it and it reported success too.
            if (!$stored) {
                $failed++;
                $reasons[] = 'item_not_stored';
                continue;
            }

            // The sale has moved WooCommerce's stock; bring it home so the
            // vendor's own list stops showing yesterday's number.
            $this->catalog->pullStock($product->id);

            $captured++;
            if (!in_array($product->vendorUserId, $vendors, true)) {
                $vendors[] = $product->vendorUserId;
            }
        }

        // Logged whenever anything happened OR anything was refused: a pass
        // that stored nothing because every line's unit was unreadable is the
        // most important one to find in the trail later.
        if ($captured > 0 || $unrecorded > 0 || $failed > 0 || $refusedUnit > 0 || $repaired > 0 || $incomplete > 0) {
            $this->audit->log(AuditEventCatalog::ORDER_CAPTURED, 0, 'order', (string) $orderId, [
                'order_id' => $orderId,
                'vendors' => count($vendors),
                'items' => $captured,
                'recorded' => max(0, $captured - $unrecorded),
                'skipped' => $skipped,
                'failed' => $failed,
                'refused_unit' => $refusedUnit,
                'repaired' => $repaired,
                'incomplete' => $incomplete,
                'reasons' => implode(',', array_unique(array_filter($reasons))),
            ]);
        }
        return [
            'captured' => $captured,
            'skipped' => $skipped,
            'unrecorded' => $unrecorded,
            // Two new counts, and the caller is meant to look at them: a
            // capture that refused or failed is not a capture that worked.
            'failed' => $failed,
            'refused_unit' => $refusedUnit,
            // A line that existed but was half-finished: repaired from its own
            // ledger event, or still incomplete and NAMED rather than skipped.
            'repaired' => $repaired,
            'incomplete' => $incomplete,
            'reasons' => array_values(array_unique(array_filter($reasons))),
            'vendors' => $vendors,
        ];
    }

    /**
     * Looks at a line that already exists, and finishes it if it is one of the
     * half-written ones.
     *
     * Three answers, and the empty string is the ordinary one:
     *
     *  - `''` — the row is complete. A repeated callback has nothing to do,
     *    which is the overwhelmingly common case and must stay free of noise.
     *  - `line_repaired` — the row was missing its figures and the ledger holds
     *    its event, so the figures were taken FROM THE EVENT. Not recomputed:
     *    a rate resolved today would rewrite what was agreed at the time.
     *  - `line_incomplete` — the row is missing its figures and there is no
     *    event to recover them from, or the repair write did not take. Either
     *    way a person has to decide, and saying so is the whole point: this is
     *    the state `alpha.38` left silent.
     */
    private function repair(VendorOrderItem $item, int $orderId): string
    {
        if ($item->vendorShareMinor !== null && trim($item->ledgerEvent) !== '') {
            return '';
        }
        $eventKey = $item->ledgerEvent !== '' ? $item->ledgerEvent : self::eventKey($orderId, $item->orderItemId);
        $outcome = $this->commissions->recoverRecorded($eventKey);
        if ($outcome === null || $outcome->commission === null || $outcome->vendorShare === null) {
            return 'line_incomplete';
        }
        $repaired = $this->items->completeFinancials(
            $item->id,
            $outcome->commission->minor,
            $outcome->vendorShare->minor,
            $outcome->snapshot?->rateBasisPoints,
            (string) ($outcome->snapshot?->rateSource ?? ''),
            $eventKey
        );
        return $repaired ? 'line_repaired' : 'line_incomplete';
    }

    /**
     * True when this unit is the one this vendor's ledger already uses — or
     * when the ledger has nothing to disagree with yet.
     *
     * Deliberately not «the site's configured currency»: there is no such
     * setting in this plugin, and inventing one would be a business decision.
     * What exists is the record, and the record is what the next row has to
     * agree with.
     */
    private function unitMatchesTheBooks(int $vendorUserId, string $currency, int $exponent): bool
    {
        $units = $this->commissions->unitsInUse($vendorUserId);
        if ($units === []) {
            return true;        // the first sale sets the unit
        }
        foreach ($units as $unit) {
            if ($unit['currency'] === $currency && $unit['exponent'] === $exponent) {
                return true;
            }
        }
        return false;
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
