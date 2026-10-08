<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\TransactionInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerAccount;
use Tecteb\Marketplace\Modules\Finance\Domain\LedgerTransaction;
use Tecteb\Marketplace\Modules\Finance\Domain\Money;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStateMachine;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStatus;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;

/**
 * The manager moving a withdrawal along — and the two places where doing it
 * carelessly would cost real money.
 *
 * **Paying.** A payment is recorded only when a person says a transfer really
 * happened, and only with the reference that proves it (§4.4: «پرداخت‌شده با
 * شماره پیگیری»). The ledger entry is written under a key derived from the
 * withdrawal id, so a second click writes nothing: the unique index on the
 * event key refuses it (FIN-03).
 *
 * **Not knowing.** `reconciliation_required` exists for the transfer whose
 * outcome nobody can state. FIN-05 forbids retrying it automatically, so there
 * is no code path here that does — the only ways out are a person recording
 * that it did happen, or that it did not.
 *
 * Rejecting or cancelling before payment frees the reserved lines in the same
 * call, so the money goes back into the vendor's eligible balance rather than
 * vanishing into a closed request.
 */
final class ReviewWithdrawals
{
    public const CAPABILITY = 'tmc_review_withdrawals';

    public function __construct(
        private readonly WithdrawalRepositoryInterface $withdrawals,
        private readonly LedgerRepositoryInterface $ledger,
        private readonly WithdrawalStateMachine $states,
        private readonly AuditLogger $audit,
        private readonly CapabilityCheckerInterface $capabilities,
        /**
         * The unit of work the payment document needs.
         *
         * `TransactionInterface` exists for exactly this — «a service which
         * needs read, compare, write, and let nobody in between» — and asking
         * for it rather than the whole gateway is what keeps this class unable
         * to write its own SQL. Optional so every existing construction site
         * keeps working; without it the two writes are ordered as they were,
         * and `move()` says so by refusing to record a payment it cannot make
         * atomic.
         */
        private readonly ?TransactionInterface $tx = null
    ) {
    }

    public function startReview(int $withdrawalId): OperationResult
    {
        return $this->move($withdrawalId, WithdrawalStatus::Reviewing, '');
    }

    public function approve(int $withdrawalId, string $note = ''): OperationResult
    {
        return $this->move($withdrawalId, WithdrawalStatus::Approved, $note);
    }

    public function startPayment(int $withdrawalId): OperationResult
    {
        return $this->move($withdrawalId, WithdrawalStatus::PaymentInProgress, '');
    }

    public function reject(int $withdrawalId, string $note): OperationResult
    {
        if (trim($note) === '') {
            return OperationResult::failure('note_required');
        }
        return $this->move($withdrawalId, WithdrawalStatus::Rejected, $note);
    }

    /** The transfer answered neither yes nor no. Nothing retries this. */
    public function needsReconciliation(int $withdrawalId, string $note): OperationResult
    {
        if (trim($note) === '') {
            return OperationResult::failure('note_required');
        }
        return $this->move($withdrawalId, WithdrawalStatus::ReconciliationRequired, $note);
    }

    /** A transfer that really happened, with the reference that shows it. */
    public function recordPayment(int $withdrawalId, string $reference, string $note = ''): OperationResult
    {
        if (trim($reference) === '') {
            return OperationResult::failure('reference_required');
        }
        return $this->move($withdrawalId, WithdrawalStatus::Paid, $note, $reference);
    }

    private function move(int $withdrawalId, WithdrawalStatus $to, string $note, string $reference = ''): OperationResult
    {
        if (!$this->capabilities->can(self::CAPABILITY)) {
            return OperationResult::failure('forbidden');
        }
        $withdrawal = $this->withdrawals->find($withdrawalId);
        if ($withdrawal === null) {
            return OperationResult::failure('not_found');
        }
        if (!$this->states->canMove($withdrawal->status, $to)) {
            return OperationResult::failure('invalid_transition', [
                'from' => $withdrawal->status->value,
                'to' => $to->value,
            ]);
        }
        $reviewer = $this->capabilities->currentUserId();

        // ONE UNIT OF WORK for a payment: the document and the status together.
        //
        // `alpha.38` wrote the ledger first and the status second, each on its
        // own. A failure of the second left a payout recorded against a
        // request that still read «PaymentInProgress» — payable again, with
        // the money already discharged on the books — and the caller was told
        // `storage_failed`, which is true and useless. Both are now inside one
        // transaction, so the pair exists or neither does.
        //
        // The status write carries the status this decision was VALIDATED
        // against, so two managers, or a manager and a vendor, cannot both
        // move the same request: the second write matches no row and is told
        // the request moved on. That also makes «Paid» final — nothing
        // validates a transition out of it, and nothing can overwrite it with
        // a decision about an earlier state.
        if ($to === WithdrawalStatus::Paid) {
            if ($this->tx === null) {
                // Refused rather than done unsafely. A build without a unit of
                // work cannot make this pair atomic, and a payment document is
                // not a place to find that out afterwards.
                return OperationResult::failure('payment_not_atomic');
            }
            $unit = $this->payoutUnit($withdrawal->vendorUserId);
            if ($unit === null) {
                // «با واحد حدسی ثبت نشود». The payout line used to be
                // `Money::of($amountMinor)` — the `'IRR', 0` default — which is
                // right only while that is the vendor's unit. It is read off
                // the vendor's own books now, and a vendor whose books hold no
                // unit, or more than one, is refused by name.
                return OperationResult::failure('payout_unit_unknown', [
                    'vendor_id' => $withdrawal->vendorUserId,
                ]);
            }
            if (!$this->tx->begin()) {
                return OperationResult::failure('storage_failed');
            }
            if (!$this->withdrawals->updateStatus($withdrawalId, $to, $reviewer, $note, $reference, $withdrawal->status)) {
                $this->tx->rollback();
                return OperationResult::failure('withdrawal_moved_on', [
                    'from' => $withdrawal->status->value,
                    'now' => $this->withdrawals->find($withdrawalId)?->status->value ?? '',
                ]);
            }
            if (!$this->recordPayout($withdrawal->vendorUserId, $withdrawalId, $withdrawal->amountMinor, $unit)) {
                // A status that says «paid» with no entry behind it is exactly
                // the failure FIN-03 exists to prevent — and so is an entry
                // with no status. Neither happens: the whole unit goes.
                $this->tx->rollback();
                return OperationResult::failure('ledger_unwritable');
            }
            if (!$this->tx->commit()) {
                $this->tx->rollback();
                return OperationResult::failure('storage_failed');
            }
        } elseif (!$this->withdrawals->updateStatus($withdrawalId, $to, $reviewer, $note, $reference, $withdrawal->status)) {
            return OperationResult::failure('withdrawal_moved_on', [
                'from' => $withdrawal->status->value,
                'now' => $this->withdrawals->find($withdrawalId)?->status->value ?? '',
            ]);
        }
        if (in_array($to, [WithdrawalStatus::Rejected, WithdrawalStatus::Cancelled], true)) {
            // A request that ended without a payment gives its lines back, so
            // the money returns to the vendor's eligible balance rather than
            // being stranded inside a request nobody will finish. A PAID
            // request keeps its lines for ever: they are the record of what
            // that payment covered.
            //
            // CHECKED since `alpha.39`: a release that failed used to be
            // invisible, and the lines it did not free are invisible too —
            // held by a closed request, so no later release looks for them.
            if (!$this->withdrawals->release($withdrawalId)) {
                return OperationResult::failure('release_failed', [
                    'withdrawal_id' => $withdrawalId,
                    'status' => $to->value,
                ]);
            }
        }
        $this->audit->log(AuditEventCatalog::WITHDRAWAL_REVIEWED, $reviewer ?? 0, 'withdrawal', (string) $withdrawalId, [
            'vendor_id' => $withdrawal->vendorUserId,
            'withdrawal_id' => $withdrawalId,
            'from' => $withdrawal->status->value,
            'to' => $to->value,
            'has_note' => trim($note) !== '',
        ]);
        return OperationResult::success('withdrawal_reviewed', [
            'withdrawal_id' => $withdrawalId,
            'from' => $withdrawal->status->value,
            'to' => $to->value,
        ]);
    }

    /**
     * One balanced pair: the vendor's earning is discharged, and the payout
     * is recorded as money that left.
     *
     * Keyed on the withdrawal, so a second attempt to record the same payment
     * writes nothing — the ledger's unique event key refuses it rather than a
     * check two callers could both pass.
     */
    /**
     * Every unit this vendor's books hold — exactly one, or nothing doing.
     *
     * @return array{currency:string, exponent:int}|null
     */
    private function payoutUnit(int $vendorUserId): ?array
    {
        $units = $this->ledger->unitsFor($vendorUserId);
        return count($units) === 1 ? $units[0] : null;
    }

    /**
     * @param array{currency:string, exponent:int} $unit
     */
    private function recordPayout(int $vendorUserId, int $withdrawalId, int $amountMinor, array $unit): bool
    {
        $key = 'withdrawal:' . $withdrawalId . ':paid';
        if ($this->ledger->hasEvent($key)) {
            // Already documented. A second confirmation of the same payment
            // makes no second entry, which is what the event key is for.
            return true;
        }
        $amount = Money::of($amountMinor, $unit['currency'], $unit['exponent']);
        $transaction = (new LedgerTransaction($key, $vendorUserId, 'withdrawal', (string) $withdrawalId))
            ->add(LedgerAccount::VendorEarning, $amount, 'withdrawal_paid')
            ->add(LedgerAccount::VendorPayout, $amount->negate(), 'withdrawal_paid');
        return $this->ledger->record($transaction);
    }
}
