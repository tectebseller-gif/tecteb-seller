<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Core\Config\SettingsService;
use Tecteb\Marketplace\Modules\Order\Application\OrderItemRepositoryInterface;
use Tecteb\Marketplace\Modules\Order\Application\ShipmentRepositoryInterface;

/**
 * What a vendor has earned, and how much of it they may ask for today.
 *
 * Three numbers, and the difference between them is the whole design:
 *
 *   earned      every recorded share, whatever state it is in
 *   pending     earned but not yet eligible — either nobody has called the
 *               sale complete, or the approved waiting period has not passed
 *   eligible    complete, waited out, and not already locked by a request
 *
 * `pending` is not a rounding of `eligible`. A vendor looking at their
 * dashboard has to be able to tell "you have not earned this yet" from "you
 * have earned it and cannot have it yet", and a single balance cannot say
 * both. The reason a line is pending is returned with it.
 *
 * The waiting period is the approved four days (Master §8.3, A.2, F-03), read
 * from settings — not invented here. The moment it counts FROM is what DEC-02
 * has not defined, so it is a date a manager recorded on the line (ORDER-01),
 * never one this class inferred from a payment or a shipment.
 *
 * A line with an OPEN return is pending too, with its own reason: the goods
 * may be on their way back, and paying out a share that is about to be
 * reversed is how a vendor ends up owing money they have already spent.
 *
 * A share that was never recorded (an unresolvable rate, FIN-02) counts as
 * nothing at all here — not as zero. It is reported separately as
 * `unrecorded`, because a vendor whose sale produced no share is owed an
 * answer, not a silent omission.
 *
 * **EVERY FIGURE RETURNED IS IN ONE UNIT, AND THE ANSWER SAYS WHICH.** Until
 * `alpha.67` the shares were bare integers added together, and that was safe
 * only because `MoneyUnitClaim` forced one exponent on a vendor's whole
 * books. The owner's no-rounding decision ends that: a commission is recorded
 * at four more decimal places than the order's own unit, so a vendor who sold
 * before the upgrade and after it has lines at two exponents of one currency.
 * Adding those integers as they stand would be wrong by a factor of ten
 * thousand, so each line is rescaled — exactly, by a power of ten — into the
 * FINEST exponent present, and `exponent` and `currency` come back with the
 * numbers so no caller has to guess the scale of what it is printing.
 *
 * A line that does not carry its own unit (every line captured before
 * `alpha.67`) is read at the vendor's first-recorded unit, which is what it
 * was written in. Nothing is reinterpreted and nothing on disk is rewritten.
 */
final class VendorBalance
{
    public function __construct(
        private readonly OrderItemRepositoryInterface $orderItems,
        private readonly SettingsService $settings,
        private readonly ClockInterface $clock,
        private readonly ?ShipmentRepositoryInterface $returns = null,
        /**
         * The manual supplier payments, so money already handed over is not
         * offered again.
         *
         * Optional for the same reason `$returns` is: a build that does not
         * wire it answers exactly as before. But when it IS wired, a line Tecteb
         * has already paid for by hand counts as `paid` rather than `eligible`
         * — one obligation, one settlement. Measured on `alpha.60`, where such
         * a line stayed `eligible` and the withdrawal cycle claimed it.
         *
         * This is a READ for the answer, not the guard: the guard is in
         * `reserve()`'s own statement, because a payment can land between this
         * read and that write.
         */
        private readonly ?SupplierPaymentRepositoryInterface $supplierPayments = null,
        /**
         * Which money these books are kept in — for the scale, not the guard.
         *
         * Optional for the reason the two above are: a build that does not
         * wire it answers exactly as every release before `alpha.67` did,
         * with `unit_unreadable` saying so rather than a zero pretending to
         * be a fact. The guard against two currencies stays where it was, in
         * `CaptureOrder`'s claim and in `Money::assertSameUnit()`.
         */
        private readonly ?VendorMoneyUnitRegistryInterface $units = null
    ) {
    }

    /**
     * @return array{
     *   earned:int, pending:int, eligible:int, reserved:int, paid:int,
     *   paid_direct:int, unrecorded:int, eligible_line_ids:list<int>, delay_days:int,
     *   awaiting_completion:int, awaiting_delay:int, awaiting_return:int,
     *   eligible_until:string, currency:string, exponent:int, unit_unreadable:bool
     * }
     */
    public function of(int $vendorUserId): array
    {
        $delayDays = $this->settings->load()->settlementDelayDays;
        $cutoff = $this->clock->now()->modify('-' . $delayDays . ' days');

        $earned = 0;
        $pending = 0;
        $eligible = 0;
        $reserved = 0;
        $paid = 0;
        $unrecorded = 0;
        $awaitingCompletion = 0;
        $awaitingDelay = 0;
        $awaitingReturn = 0;
        $paidDirect = 0;
        $eligibleIds = [];

        $paidByHand = [];
        foreach ($this->supplierPayments?->paidOrderItemIds($vendorUserId) ?? [] as $id) {
            $paidByHand[(int) $id] = true;
        }

        // THE UNIT EVERY FIGURE BELOW IS IN — the finest exponent of this
        // vendor's one currency. Asked once, before the loop, because a scale
        // that changed half way through a sum is a sum of two things.
        $lines = $this->orderItems->settlementView($vendorUserId);
        $unit = $this->summationUnit($vendorUserId, $lines);

        foreach ($lines as $line) {
            $share = $line['vendor_share_minor'];
            if ($share === null) {
                $unrecorded++;
                continue;       // unset is not zero (FIN-02)
            }
            // Rescaled into the one unit this answer is in — a multiplication
            // by a power of ten, so nothing is lost and nothing is rounded. A
            // line whose own exponent is already the finest is multiplied by
            // one.
            $share = $this->inUnit(
                $share,
                ($line['exponent'] ?? null) === null ? null : (int) $line['exponent'],
                $unit
            );
            if ($share === null) {
                // «I cannot say what this row's amount means» — a row finer
                // than the unit this answer is in, which cannot happen while
                // the unit is the maximum, and is counted rather than guessed
                // if it ever does.
                $unrecorded++;
                continue;
            }
            $earned += $share;
            if ($line['paid']) {
                $paid += $share;
                continue;
            }
            // ALREADY HANDED OVER, by the other path. Counted in `paid`
            // because that is what `paid` means — money that has left — and
            // reported separately as well, so «کدام مسیر» is answerable
            // without reading two screens. A recorded-but-unposted payment
            // counts too: the obligation was settled whether or not the ledger
            // line could be written, and a payment waiting on the owner's
            // definition must not make the line payable again.
            if (isset($paidByHand[$line['id']])) {
                $paid += $share;
                $paidDirect += $share;
                continue;
            }
            if ($line['withdrawal_id'] !== null) {
                $reserved += $share;
                continue;
            }
            $completedAt = $line['settlement_completed_at'];
            if ($completedAt === null) {
                $pending += $share;
                $awaitingCompletion++;
                continue;
            }
            if ($completedAt > $cutoff->format('Y-m-d H:i:s')) {
                $pending += $share;
                $awaitingDelay++;
                continue;
            }
            // UX §10.2: «ثبت مرجوعی، سهم مربوط را از قابل برداشت خارج می‌کند».
            // A line with a return still being decided is not money anybody
            // may ask for yet — the goods may be coming back and the share
            // with them. It is `pending`, not lost: a rejected return puts the
            // line straight back into `eligible` with no further action.
            if (($this->returns?->returnedQuantity((int) $line['id']) ?? 0) > 0) {
                $pending += $share;
                $awaitingReturn++;
                continue;
            }
            $eligible += $share;
            $eligibleIds[] = $line['id'];
        }

        return [
            'earned' => $earned,
            'pending' => $pending,
            'eligible' => $eligible,
            'reserved' => $reserved,
            'paid' => $paid,
            'paid_direct' => $paidDirect,
            'unrecorded' => $unrecorded,
            'eligible_line_ids' => $eligibleIds,
            'delay_days' => $delayDays,
            'awaiting_completion' => $awaitingCompletion,
            'awaiting_delay' => $awaitingDelay,
            'awaiting_return' => $awaitingReturn,
            // THE CUTOFF THIS ANSWER USED, so the reservation can enforce the
            // same one in its own `WHERE`.
            //
            // Returned rather than recomputed by the caller on purpose: the
            // waiting period is a setting and `now` moves, so two
            // computations of «the latest completion that still qualifies» are
            // two rules that agree until the second one is a millisecond
            // later. `alpha.39`'s claim had no cutoff at all and leaned on the
            // amount comparison, which cannot see it — a line whose period has
            // not elapsed carries exactly the share it carried a moment ago.
            'eligible_until' => $cutoff->format('Y-m-d H:i:s'),
            // THE UNIT ALL OF THE ABOVE ARE IN. Printed, not inferred: a page
            // that formats `eligible` without it would show ۸۸۰٬۰۰۰ where the
            // vendor is owed ۸۸٫۳۷۵.
            'currency' => $unit['currency'],
            'exponent' => $unit['exponent'],
            // Said out loud rather than folded into a zero: when the registry
            // could not be asked, the figures are still summed — in the
            // coarsest unit any row names — and the caller is told that the
            // unit is a fallback rather than a fact (`alpha.49`).
            'unit_unreadable' => $unit['unreadable'],
        ];
    }

    /**
     * The one unit this answer is taken in.
     *
     * The finest exponent among: what the registry says these books hold,
     * what the rows themselves say, and the vendor's first-recorded unit for
     * rows that say nothing. `unreadable` when the registry could not be
     * asked at all — which is reported rather than treated as «exponent 0»,
     * because a fallback presented as a fact is how `alpha.39` fixed the unit
     * of a whole ledger off a failed read.
     *
     * @param list<array<string,mixed>> $lines
     * @return array{currency:string, exponent:int, fallback:int, unreadable:bool}
     */
    private function summationUnit(int $vendorUserId, array $lines): array
    {
        $currency = '';
        $fallback = 0;
        $unreadable = $this->units === null;

        // THE COARSE END: what a row that does not carry its own unit was
        // written in. The registry's row holds the FIRST unit it recorded and
        // is deliberately never moved to a finer one, which is exactly what
        // makes it the right answer to this question.
        $stored = $this->units?->unitOf($vendorUserId);
        if ($stored !== null) {
            $currency = (string) $stored['currency'];
            $fallback = (int) $stored['exponent'];
        }
        $exponent = $fallback;

        // THE FINE END: the finest exponent the ledger holds. `null` from a
        // wired registry is «could not ask», which is reported rather than
        // read as nought (`alpha.49`).
        $finest = $this->units?->finestExponentOf($vendorUserId);
        if ($this->units !== null && $finest === null) {
            $unreadable = true;
        } elseif ($finest !== null) {
            $exponent = max($exponent, $finest);
        }

        // And the rows get a vote: one finer than anything the ledger holds
        // is reachable while a capture is half way through its transaction.
        foreach ($lines as $line) {
            if (($line['exponent'] ?? null) !== null) {
                $exponent = max($exponent, (int) $line['exponent']);
            }
            if ($currency === '' && (string) ($line['currency'] ?? '') !== '') {
                $currency = (string) $line['currency'];
            }
        }
        return [
            'currency' => $currency,
            'exponent' => $exponent,
            'fallback' => $fallback,
            'unreadable' => $unreadable,
        ];
    }

    /**
     * One line's share in the answer's unit, or `null` when it cannot be.
     *
     * A row with no unit of its own is read at the answer's coarse end —
     * which is the vendor's first-recorded unit, the unit it was written in —
     * and then rescaled like any other. `null` for a row finer than the
     * answer's unit: that cannot happen while the unit is the maximum of
     * everything, and inventing a rounded value for it is the one thing this
     * round exists to stop.
     *
     * @param int|null $own the row's own exponent, or null when it has none
     * @param array{currency:string, exponent:int, fallback:int, unreadable:bool} $unit
     */
    private function inUnit(int $share, ?int $own, array $unit): ?int
    {
        // A row that does not say is at the COARSE end of these books. That
        // is not a guess: it is what `tmc_vendor_money_unit` recorded at the
        // vendor's first sale and what every writer before `alpha.67` used.
        // Resolved from the unit computed once above rather than per row, so
        // this loop costs no queries.
        $from = $own ?? $unit['fallback'];
        if ($from > $unit['exponent']) {
            return null;
        }
        if ($from === $unit['exponent']) {
            return $share;
        }
        $factor = 10 ** ($unit['exponent'] - $from);
        if ($share !== 0 && intdiv(PHP_INT_MAX, abs($share)) < $factor) {
            return null;    // asked before the multiplication, never after
        }
        return $share * $factor;
    }
}
