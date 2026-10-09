<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

use Tecteb\Marketplace\Contracts\TransactionInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Finance\Application\RecordCommission;
use Tecteb\Marketplace\Modules\Finance\Application\VendorMoneyUnitRegistryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\MoneyUnitClaim;
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
        private readonly AuditLogger $audit,
        /**
         * The vendor's money unit, recorded against a primary key.
         *
         * Optional so every existing construction site keeps working — but a
         * capture WITHOUT it refuses every line as `unit_unreadable` rather
         * than assuming, because a build that cannot establish the unit does
         * not get to record money in it.
         */
        private readonly ?VendorMoneyUnitRegistryInterface $units = null,
        /**
         * The unit of work the unit claim and the accrual share.
         *
         * Fixing a vendor's money unit is a write, and this hook runs at
         * checkout — before anything is paid. A claim that outlived a line
         * whose rate could not be resolved would fix a currency from an order
         * that recorded nothing, with no way back. Optional so every existing
         * construction site keeps working; without it a line is refused as
         * `capture_not_atomic` rather than claimed unsafely.
         */
        private readonly ?TransactionInterface $tx = null
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
                        // `line_incomplete` and `line_reconcile_required` are
                        // both «not finished, a person decides» — the count is
                        // the same and the NAME is the difference, because one
                        // is a line missing its figures and the other is a line
                        // whose figures contradict its event.
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
            if ($unitError !== '') {
                $refusedUnit++;
                $reasons[] = $unitError;
                continue;
            }

            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $base = Money::of((int) ($line['line_total_minor'] ?? 0), $lineCurrency, $lineExponent);
            $tax = Money::of((int) ($line['line_tax_minor'] ?? 0), $lineCurrency, $lineExponent);
            $eventKey = self::eventKey($orderId, (int) $line['order_item_id']);

            // THE UNIT CLAIM AND THE ACCRUAL ARE ONE UNIT OF WORK.
            //
            // Fixing the unit is a WRITE, and a write that outlives the thing
            // it was made for is a trap: this hook runs at checkout, before
            // anything is paid, and a line whose rate cannot be resolved —
            // or whose ledger write fails — records no money at all. The first
            // version of this claimed the unit before the accrual and left the
            // row behind, so an order that recorded nothing could still fix a
            // vendor's currency for ever, and the vendor's first real sale in
            // another unit would be refused with no way back. Found by the
            // automated review of §4's commit, not by a test.
            //
            // So both go in one transaction. The claim still comes FIRST,
            // because that is the whole point of the keyed row: two concurrent
            // first captures must not both reach the ledger. The loser rolls
            // back before writing a single ledger line.
            if ($this->tx === null) {
                // A build that cannot make the pair atomic does not get to fix
                // a vendor's unit — the same posture as `payment_not_atomic`.
                $refusedUnit++;
                $reasons[] = 'capture_not_atomic';
                continue;
            }
            if (!$this->tx->begin()) {
                $failed++;
                $reasons[] = 'storage_failed';
                continue;
            }
            $claim = $this->claimUnit($product->vendorUserId, $lineCurrency, $lineExponent);
            if (!$claim->isAgreed()) {
                // The claim's own word, not a single catch-all: «the books
                // hold another unit», «the books hold two», «the database
                // could not be asked» and «that is not a unit» are four
                // different pieces of work for a person.
                $this->tx->rollback();
                $refusedUnit++;
                $reasons[] = $claim->state;
                continue;
            }
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
                // Nothing reached the ledger, so nothing claims this vendor's
                // unit: the claim goes back with the accrual. The LINE is
                // still stored below, with null figures and counted
                // `unrecorded` — exactly as before. FIN-02's rule is that a
                // vendor whose sale produced no share is owed an answer rather
                // than a silent omission, and `VendorBalance` reports that
                // count from stored rows. Dropping the line would hide it.
                $this->tx->rollback();
                $unrecorded++;
                $reasons[] = $outcome->reason;
            } elseif (!$this->tx->commit()) {
                $this->tx->rollback();
                $failed++;
                $reasons[] = 'storage_failed';
                continue;
            }

            // A RECOVERED outcome writes the EVENT's figures, not this
            // callback's input — and refuses when the two disagree.
            //
            // `alpha.39` took the share and the commission off the recovered
            // event and `base_minor`, `tax_minor` and `quantity` from the
            // fresh line. An order edited between the failed line write and
            // the retry — a quantity corrected, a discount applied — then
            // produced a line whose base disagreed with the ledger entry it
            // pointed at, and every total built from the two disagreed with
            // itself. Nothing reported it: `captured` counted 1.
            //
            // So: base and tax come from the event, and a line whose input no
            // longer matches what was recorded is NOT stored. The quantity is
            // not in the ledger and is not guessed — it is trusted only while
            // the money agrees, which is the evidence that this is the same
            // line. A mismatch needs a person, and `line_mismatch` says so.
            $storedBase = $base;
            $storedTax = $tax;
            if ($outcome->isRecovered() && $outcome->base !== null) {
                $recoveredTax = $outcome->recoveredTax ?? $outcome->base->zero();
                if ($outcome->base->minor !== $base->minor || $recoveredTax->minor !== $tax->minor) {
                    $incomplete++;
                    $reasons[] = 'line_mismatch';
                    continue;
                }
                $storedBase = $outcome->base;
                $storedTax = $recoveredTax;
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
                (int) round($storedBase->minor / $quantity),
                $storedBase->minor,
                $storedTax->minor,
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
     *    event to recover them from. A person has to decide, and saying so is
     *    the whole point: this is the state `alpha.38` left silent.
     *  - `line_reconcile_required` — the row HOLDS figures and the event holds
     *    different ones. Nothing here can fix that: one of the two is wrong and
     *    only a person can say which, so this names it instead of overwriting
     *    recorded money with the other answer. «ناسازگاری غیرقابل‌بازیابی
     *    به‌عنوان نیازمند تطبیق نام‌گذاری شود.»
     *
     * The repair never recomputes: the figures come from the event, and a rate
     * resolved today would rewrite what was agreed at the time.
     */
    private function repair(VendorOrderItem $item, int $orderId): string
    {
        if ($item->vendorShareMinor !== null && trim($item->ledgerEvent) !== '') {
            // A repeated callback on a complete line. No second document, no
            // second ledger entry, and no noise in `reasons`.
            return '';
        }
        $eventKey = $item->ledgerEvent !== '' ? $item->ledgerEvent : self::eventKey($orderId, $item->orderItemId);
        $outcome = $this->commissions->recoverRecorded($eventKey);
        if ($outcome === null || $outcome->commission === null || $outcome->vendorShare === null) {
            return 'line_incomplete';
        }
        // CHECKED BEFORE THE WRITE, because the write's own guard refuses
        // silently and the two refusals mean different things. A row that holds
        // a share is a row whose money is already recorded; this pass is only
        // allowed to add the link to the event it was recorded under, and only
        // when the two agree.
        if ($item->vendorShareMinor !== null && $item->vendorShareMinor !== $outcome->vendorShare->minor) {
            return 'line_reconcile_required';
        }
        if ($item->commissionMinor !== null && $item->commissionMinor !== $outcome->commission->minor) {
            return 'line_reconcile_required';
        }
        if ($this->items->completeFinancials(
            $item->id,
            $outcome->commission->minor,
            $outcome->vendorShare->minor,
            $outcome->snapshot?->rateBasisPoints,
            (string) ($outcome->snapshot?->rateSource ?? ''),
            $eventKey
        )) {
            return 'line_repaired';
        }
        // The write did not take. Read the row back rather than guessing why:
        // another pass finishing it first is not a finding, and a row that now
        // disagrees with the event is the reconciliation case above.
        $now = $this->items->find($item->id);
        if ($now === null) {
            return 'line_incomplete';
        }
        if ($now->vendorShareMinor === $outcome->vendorShare->minor
            && $now->commissionMinor === $outcome->commission->minor
            && trim($now->ledgerEvent) !== ''
        ) {
            return '';
        }
        if ($now->vendorShareMinor !== null || $now->commissionMinor !== null) {
            return 'line_reconcile_required';
        }
        return 'line_incomplete';
    }

    /**
     * Claims this unit for the vendor's books, and says what the books said.
     *
     * **What this replaced, and why «any match» was not enough.** `alpha.39`
     * asked `unitsInUse()` — `SELECT DISTINCT currency, exponent` over the
     * ledger — and returned true if ANY of the answers matched. So a vendor
     * whose books already held two units accepted a line in either of them and
     * went on being mixed, which is the state a balance cannot be computed
     * over. And `getResults()` answers an empty array both for «no rows» and
     * for «the read failed», so a broken database read was indistinguishable
     * from a first sale and silently fixed the unit of a whole ledger.
     *
     * The registry answers five ways instead of two, and its write collides on
     * a primary key — so two first captures at the same moment cannot both fix
     * a unit. `MIXED` and `UNREADABLE` are refusals in their own right, with
     * their own names in `reasons`.
     */
    private function claimUnit(int $vendorUserId, string $currency, int $exponent): MoneyUnitClaim
    {
        return $this->units === null
            // No registry wired: `unit_unreadable` rather than «fine». A build
            // that cannot establish the unit does not get to record money in
            // it — the same posture as `payment_not_atomic`.
            ? MoneyUnitClaim::refused(MoneyUnitClaim::UNREADABLE)
            : $this->units->claim($vendorUserId, $currency, $exponent);
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
