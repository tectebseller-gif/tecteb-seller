<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Order\Domain;

/**
 * Whether one recorded line and its financial document agree — pure.
 *
 * Every question is answered from facts the caller has already read, so this
 * class touches no database and no WordPress and can be exercised exhaustively
 * in the unit suite. It is deliberately the ONLY place the seven rules are
 * written: a second copy inside a query would be a second rule that agrees
 * until somebody edits one (`alpha.87`).
 *
 * ⛔ **The order of the checks is a decision, not a reading order.** A line
 * with no accrual at all cannot also be «cancelled without a reversal»:
 * FIN-02 says an unresolvable rate records NOTHING, so there is no document
 * to reverse and inventing one would be the finding. So the absent-document
 * cases are judged first, and a line that reports one class does not report
 * the next — which is what makes a count of findings a count of problems
 * rather than a count of ways to describe one.
 */
final class LineReconciliation
{
    /**
     * @param string $ledgerEvent the line's own accrual key, '' when none
     * @param bool $accrualPresent whether that key has rows in the ledger
     * @param bool $reversalPresent whether the matching `…:void` key has rows
     * @param bool $cancelled whether the line's status is `cancelled`
     * @param bool $shareRecorded whether the line carries a vendor share
     * @param bool $eventBalanced whether the accrual's rows sum to nought
     * @param bool $eventSingleUnit whether the accrual's rows share one unit
     * @param bool $linesSharingEvent whether another line claims the same key
     * @param bool $vendorAgrees whether the line's vendor owns the product
     * @param bool $statusKnown whether the stored status is one this release has
     * @return list<OrderInconsistency> empty when the line is sound
     */
    public static function of(
        string $ledgerEvent,
        bool $accrualPresent,
        bool $reversalPresent,
        bool $cancelled,
        bool $shareRecorded,
        bool $eventBalanced,
        bool $eventSingleUnit,
        bool $linesSharingEvent,
        bool $vendorAgrees,
        bool $statusKnown
    ): array {
        $found = [];

        if (!$vendorAgrees || !$statusKnown) {
            $found[] = OrderInconsistency::VendorOrStatusMismatch;
        }

        if ($ledgerEvent === '') {
            // ⛔ NO DOCUMENT IS NOT A DEFECT BY ITSELF. FIN-02: a line whose
            // rate could not be resolved is recorded with no figures and no
            // accrual on purpose, and the `unrecorded` state is what the
            // report already prints. It becomes a finding only when the line
            // DOES carry a share — then a figure exists with nothing behind
            // it, which is the half-written state §5 is looking for.
            if ($shareRecorded) {
                $found[] = OrderInconsistency::LineWithoutAccrual;
            }
            return $found;
        }

        if (!$accrualPresent) {
            // The line names a document the ledger does not have: the write
            // that mattered is the one that did not happen.
            $found[] = OrderInconsistency::LineWithoutAccrual;
            return $found;
        }

        if ($linesSharingEvent) {
            $found[] = OrderInconsistency::DuplicateAccrual;
        }
        if (!$eventBalanced || !$eventSingleUnit) {
            $found[] = OrderInconsistency::UnbalancedEvent;
        }
        if ($cancelled && !$reversalPresent) {
            $found[] = OrderInconsistency::CancelledWithoutReversal;
        }
        if (!$cancelled && $reversalPresent) {
            // The obligation was reversed and the line came back out of
            // `cancelled` — `alpha.99`'s own rule says nothing un-cancels, so
            // this state can only be reached by hand, and it means the
            // reports are counting a share the ledger has already given back.
            $found[] = OrderInconsistency::ReversalWithoutCancel;
        }
        return $found;
    }
}
