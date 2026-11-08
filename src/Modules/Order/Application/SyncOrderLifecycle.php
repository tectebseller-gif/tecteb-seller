<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Application;

use Tecteb\Marketplace\Contracts\TransactionInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\VendorOrderItem;
use Tecteb\Marketplace\Modules\Order\Domain\WcOrderDisposition;
use Tecteb\Marketplace\Modules\Order\Domain\WcOrderLifecycle;

/**
 * Brings the marketplace's record of an order into line with WooCommerce's own
 * status — and nothing more than that.
 *
 * ⛔ **The defect this closes, measured by the owner on `alpha.98`.** Order
 * 12300 was captured, ۱۹۵٬۷۰۹٬۵۰۰ تومان of vendor share was recorded, and the
 * order was then cancelled in WooCommerce. WooCommerce said `Cancelled`. The
 * marketplace went on reporting: recorded share ۴۰۱٬۷۱۹٬۵۰۰، pending share
 * ۴۰۱٬۷۱۹٬۵۰۰، items awaiting shipment ۲. No report was wrong — every total
 * already excludes a line whose own status is `cancelled` — and nothing in the
 * plugin had ever set it. `OrderHooks` listened to
 * `woocommerce_order_status_changed`, and what it did there was refresh the
 * STOCK mirror. The money and the operational state were not looked at at all.
 *
 * **Preserving a historical sale amount is not the same as keeping a valid
 * financial obligation** (§3-3), and the two are separated here the way this
 * plugin already separates them: the accrual STAYS in the ledger for ever,
 * with a reversing document beside it, and the line's own status takes it out
 * of every total. Nothing is deleted, nothing is rewritten, and
 * `forEvent($accrualKey)` answers tomorrow exactly what it answers today.
 *
 * **One disposition writes; the rest are named.** `WcOrderDisposition` says
 * which, and the asymmetry is the point: voiding an obligation is the safe
 * direction and creating one is not. So:
 *
 *   - a cancelled or trashed order with nothing shipped → the accrual is
 *     reversed and the line is cancelled;
 *   - a cancelled order whose goods already left → NOTHING is written and
 *     `cancel_after_shipping` is reported. Half a line is in the world and a
 *     return is the instrument for that, not a status flip;
 *   - a refunded order → nothing written, `refund_needs_decision` reported
 *     (§3-6: «ثبت لغو را با استرداد واقعی یکی نگیر»);
 *   - an unknown status → nothing written, the slug named;
 *   - anything else → nothing to do.
 *
 * **It cannot pay anybody, and that is enforced by not having the ability.**
 * There is no gateway, no refund call, no payout: §3-7's «هیچ استرداد وجه
 * بانکی خودکار، پرداخت یا تسویه خارجی» is a property of this class's
 * dependencies rather than a rule it keeps. `LifecycleHasNoPaymentPathTest`
 * reads the file's bytes and says so.
 *
 * **It never un-cancels** (§3-9). A WooCommerce order that moves back out of
 * `cancelled` leaves this line cancelled, and the divergence is REPORTED
 * (`reopened_after_void`) rather than resolved: re-creating an obligation from
 * a status flip is the one thing a plugin must not do with somebody's money,
 * and a reinstatement is a manager's decision with a document of its own. So
 * there is no state in which the two halves silently disagree and no path on
 * which a share is counted twice.
 */
final class SyncOrderLifecycle
{
    /**
     * The reversing document's key, derived so a repeated hook produces the
     * same one and the ledger's unique index refuses the second write.
     *
     * Deliberately a different key from the accrual's and from a return's: one
     * event, one document, and three instruments that can never collide
     * (FIN-03).
     */
    public static function voidEventKey(int $orderId, int $orderItemId): string
    {
        return CaptureOrder::eventKey($orderId, $orderItemId) . ':void';
    }

    public function __construct(
        private readonly OrderItemRepositoryInterface $items,
        private readonly LedgerRepositoryInterface $ledger,
        private readonly AuditLogger $audit,
        /**
         * The unit of work the reversal and the status change share.
         *
         * Not optional in spirit and optional in signature for the reason
         * every other service in this module has it that way — an existing
         * construction site must keep working — but a sync WITHOUT it refuses
         * by name rather than writing one half. A reversed accrual beside a
         * line still counted as earned is the divergence §9 forbids, and a
         * cancelled line whose accrual still stands is the defect this class
         * exists to close. Either half alone is worse than neither.
         */
        private readonly ?TransactionInterface $tx = null
    ) {
    }

    /**
     * @return array{
     *   disposition:string, status:string, items:int, voided:int, cancelled:int,
     *   untouched:int, failed:int, reasons:list<string>, vendors:list<int>
     * }
     */
    public function sync(int $orderId, string $status): array
    {
        $disposition = WcOrderLifecycle::of($status);
        $rows = $this->items->forOrder($orderId);
        $result = [
            'disposition' => $disposition->value,
            'status' => $status,
            'items' => count($rows),
            'voided' => 0,
            'cancelled' => 0,
            'untouched' => 0,
            'failed' => 0,
            'reasons' => [],
            'vendors' => [],
        ];
        if ($rows === []) {
            // Not one of ours — a basket of shop or Dokan goods — or an order
            // this marketplace never captured. Nothing to say and nothing to
            // log: silence here is the common case on a site that also sells
            // its own stock.
            return $result;
        }

        // THE STATUS WE WERE TOLD, MIRRORED ONTO THE ROWS — first, and
        // whatever the disposition is.
        //
        // It is a FILTER and never a figure (migration 39): the detailed
        // report needs the order's status to be something it can put in a
        // `WHERE`, and loading an order per row is the cost that makes a
        // per-line report unusable at volume. So every disposition writes it,
        // including the ones that write nothing else — a report that knew
        // about cancellations and not about `processing` would answer half the
        // question.
        //
        // Its answer is read rather than discarded, because `null` is «the
        // column is not there yet» and that is worth a reader's attention on a
        // site mid-upgrade. It is not a failure of the sync: nothing in this
        // plugin decides anything from this column, so the obligation below is
        // settled by the ledger and the line's own status either way.
        if ($this->items->recordWcOrderStatus($orderId, $status) === null) {
            $result['reasons'][] = 'wc_status_not_mirrored';
        }

        $reasons = $result['reasons'];
        $vendors = [];
        foreach ($rows as $item) {
            if (!in_array($item->vendorUserId, $vendors, true)) {
                $vendors[] = $item->vendorUserId;
            }
            $outcome = $this->applyTo($item, $disposition, $status);
            if ($outcome['reason'] !== '') {
                $reasons[] = $outcome['reason'];
            }
            $result['voided'] += $outcome['voided'];
            $result['cancelled'] += $outcome['cancelled'];
            $result['untouched'] += $outcome['untouched'];
            $result['failed'] += $outcome['failed'];
        }
        $result['reasons'] = array_values(array_unique(array_filter($reasons)));
        $result['vendors'] = $vendors;

        // Logged when anything happened OR anything was named. A pass that
        // wrote nothing because every line had already shipped is the most
        // important one to find in the trail afterwards, which is the same
        // reason `CaptureOrder` logs its refusals.
        if ($result['cancelled'] > 0 || $result['voided'] > 0
            || $result['failed'] > 0 || $result['reasons'] !== []
        ) {
            $this->audit->log(AuditEventCatalog::ORDER_LIFECYCLE_SYNCED, 0, 'order', (string) $orderId, [
                'order_id' => $orderId,
                'wc_status' => $status,
                'disposition' => $disposition->value,
                'items' => $result['items'],
                'cancelled' => $result['cancelled'],
                'voided' => $result['voided'],
                'untouched' => $result['untouched'],
                'failed' => $result['failed'],
                'vendors' => count($vendors),
                'reasons' => implode(',', $result['reasons']),
            ]);
        }
        return $result;
    }

    /**
     * @return array{voided:int, cancelled:int, untouched:int, failed:int, reason:string}
     */
    private function applyTo(VendorOrderItem $item, WcOrderDisposition $disposition, string $status): array
    {
        // Every branch returns exactly one of these four counted, so
        // `items === cancelled + untouched + failed` on every pass and a
        // report built from them cannot quietly lose a line. `voided` is a
        // subset of `cancelled`: it says the one that also had a document.
        $untouched = static fn (string $reason = ''): array
            => ['voided' => 0, 'cancelled' => 0, 'untouched' => 1, 'failed' => 0, 'reason' => $reason];

        if ($item->status === OrderItemStatus::Cancelled) {
            // Idempotent, and the two ways of arriving here are different
            // facts. A repeated `cancelled` hook is ordinary and silent. A
            // status that is NO LONGER void on a line that was already
            // cancelled is the §3-9 case: WooCommerce has moved the order back
            // and this plugin will not re-create the obligation from a status
            // flip. Reported, not resolved.
            return $disposition->voidsTheAccrual()
                ? $untouched()
                : $untouched('reopened_after_void');
        }
        if (!$disposition->voidsTheAccrual()) {
            if (!$disposition->needsADecision()) {
                return $untouched();
            }
            return $untouched($disposition === WcOrderDisposition::Refunded
                ? 'refund_needs_decision'
                // The slug, reduced to something safe to print and store: the
                // name is the only useful part of «I do not know this status»
                // and a payload key is not the place for whatever a plugin
                // chose to call it.
                : 'wc_status_unknown:' . substr(
                    (string) preg_replace('/[^a-z0-9_-]/', '', strtolower($status)),
                    0,
                    32
                ));
        }
        if ($item->status->hasShipped()) {
            // Goods are in the world. A line marked «cancelled» beside a
            // tracking code is a record that contradicts a delivery, and the
            // state machine has refused that move since the day it was written
            // — this agrees with it instead of going round it.
            return $untouched('cancel_after_shipping');
        }
        if (!$item->isRecorded()) {
            // Nothing was ever accrued (FIN-02: an unresolvable rate records
            // nothing rather than zero), so there is no document to reverse
            // and inventing one would invent a number. The line still has to
            // leave the awaiting-shipment count.
            return $this->items->updateStatus($item->id, OrderItemStatus::Cancelled, '', '', null)
                ? ['voided' => 0, 'cancelled' => 1, 'untouched' => 0, 'failed' => 0, 'reason' => 'cancelled_unrecorded']
                : ['voided' => 0, 'cancelled' => 0, 'untouched' => 0, 'failed' => 1, 'reason' => 'storage_failed'];
        }

        return $this->voidAccrual($item);
    }

    /**
     * The reversal and the status change, as ONE unit of work.
     *
     * The order inside it is the contract. The ledger goes first, because a
     * line cancelled beside a standing accrual is the defect this class
     * closes, and the reverse order would re-create it on any failure. Then
     * the status, and a status that will not write rolls the document back.
     *
     * @return array{voided:int, cancelled:int, untouched:int, failed:int, reason:string}
     */
    private function voidAccrual(VendorOrderItem $item): array
    {
        $failed = static fn (string $reason): array
            => ['voided' => 0, 'cancelled' => 0, 'untouched' => 0, 'failed' => 1, 'reason' => $reason];

        // THE UNIT AND THE LINE IDS, BEFORE ANY WRITE — read off the accrual
        // being reversed, never taken from a caller and never defaulted. A
        // reversal in a different unit balances arithmetically and means
        // nothing, which is the mistake `alpha.39` found in the refund path
        // and this path is not allowed to repeat.
        $accrual = $this->accrual($item);
        if ($accrual['unit'] === null) {
            return $failed('void_unit_unknown');
        }
        if ($accrual['mixed']) {
            return $failed('void_unit_inconsistent');
        }
        /** @var array{currency:string, exponent:int} $unit */
        $unit = $accrual['unit'];

        if ($this->tx === null) {
            return $failed('void_not_atomic');
        }
        if (!$this->tx->begin()) {
            return $failed('storage_failed');
        }

        $money = static fn (int $minor): Money => Money::of($minor, $unit['currency'], $unit['exponent']);
        $commission = (int) ($item->commissionMinor ?? 0);
        $share = (int) ($item->vendorShareMinor ?? 0);
        $tax = $item->taxMinor;
        $original = $accrual['lines'];
        // The exact mirror of `RecordCommission::accrue()`'s four lines, each
        // pointing at the one it undoes, so the ledger itself says «this
        // reverses that» rather than leaving a reader to match keys by eye.
        //
        // ⛔ It voids the ACCRUAL — the marketplace's claim on this sale — and
        // records no customer refund, because none has happened. On a cancel
        // before payment there was no money; on a cancel after payment the
        // customer's money is WooCommerce's business and the manager's, and
        // this class has no way to move it.
        $transaction = (new LedgerTransaction(
            self::voidEventKey($item->orderId, $item->orderItemId),
            $item->vendorUserId,
            (string) $item->orderId,
            (string) $item->id
        ))
            ->add(
                LedgerAccount::CentralPayment,
                $money($item->baseMinor + $tax)->negate(),
                'order_voided',
                $original[LedgerAccount::CentralPayment->value] ?? null
            )
            ->add(
                LedgerAccount::Commission,
                $money($commission),
                'commission_voided',
                $original[LedgerAccount::Commission->value] ?? null
            )
            ->add(
                LedgerAccount::VendorEarning,
                $money($share),
                'vendor_earning_voided',
                $original[LedgerAccount::VendorEarning->value] ?? null
            )
            ->add(
                LedgerAccount::TaxCollected,
                $money($tax),
                'tax_voided',
                $original[LedgerAccount::TaxCollected->value] ?? null
            );

        if (!$transaction->balances()) {
            // The four figures on the row do not add up to the four the
            // accrual wrote. Named rather than forced: one of the two is
            // wrong and only a person can say which, which is exactly what
            // `line_reconcile_required` says on the capture path.
            $this->tx->rollback();
            return $failed('void_unbalanced');
        }
        if (!$this->ledger->record($transaction)) {
            // Either the document is already there — a repeated hook, which is
            // what the unique index is for — or the write failed. Asked rather
            // than assumed, the same way `RecordCommission` asks: the two need
            // different words and `alpha.38` called both of them «already».
            if (!$this->ledger->hasEvent($transaction->eventKey)) {
                $this->tx->rollback();
                return $failed('void_not_written');
            }
        }
        if (!$this->items->updateStatus($item->id, OrderItemStatus::Cancelled, '', '', null)) {
            // The document would otherwise stand beside a line still counted
            // as earned — a reversal nobody's totals can see. Both halves go
            // back together.
            $this->tx->rollback();
            return $failed('void_status_unwritten');
        }
        if (!$this->tx->commit()) {
            $this->tx->rollback();
            return $failed('storage_failed');
        }
        return ['voided' => 1, 'cancelled' => 1, 'untouched' => 0, 'failed' => 0, 'reason' => 'order_voided'];
    }

    /**
     * The accrual's line ids and the unit it was recorded in.
     *
     * The same shape and the same reading as `ManageReturns`'s own helper, and
     * for the same reason: a reversal has to be written in the unit of the
     * thing it reverses, and that is a fact on the ledger rather than an
     * argument a caller supplies.
     *
     * @return array{lines:array<string,int>, unit:array{currency:string, exponent:int}|null, mixed:bool}
     */
    private function accrual(VendorOrderItem $item): array
    {
        $lines = [];
        $unit = null;
        $mixed = false;
        if (trim($item->ledgerEvent) === '') {
            return ['lines' => $lines, 'unit' => null, 'mixed' => false];
        }
        foreach ($this->ledger->forEvent($item->ledgerEvent) as $entry) {
            $lines[$entry->account->value] = $entry->id;
            $here = ['currency' => $entry->amount->currency, 'exponent' => $entry->amount->exponent];
            if ($unit === null) {
                $unit = $here;
                continue;
            }
            if ($unit !== $here) {
                $mixed = true;
            }
        }
        return ['lines' => $lines, 'unit' => $unit, 'mixed' => $mixed];
    }
}
