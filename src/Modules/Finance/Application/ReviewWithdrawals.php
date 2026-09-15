<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
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
        private readonly CapabilityCheckerInterface $capabilities
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
        // The ledger first, while the request is still the one we read. A
        // status that said "paid" with no entry behind it is exactly the
        // failure FIN-03 exists to prevent.
        if ($to === WithdrawalStatus::Paid && !$this->recordPayout($withdrawal->vendorUserId, $withdrawalId, $withdrawal->amountMinor)) {
            return OperationResult::failure('ledger_unwritable');
        }
        $reviewer = $this->capabilities->currentUserId();
        if (!$this->withdrawals->updateStatus($withdrawalId, $to, $reviewer, $note, $reference)) {
            return OperationResult::failure('storage_failed');
        }
        if (in_array($to, [WithdrawalStatus::Rejected, WithdrawalStatus::Cancelled], true)) {
            // A request that ended without a payment gives its lines back, so
            // the money returns to the vendor's eligible balance rather than
            // being stranded inside a request nobody will finish. A PAID
            // request keeps its lines for ever: they are the record of what
            // that payment covered.
            $this->withdrawals->release($withdrawalId);
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
    private function recordPayout(int $vendorUserId, int $withdrawalId, int $amountMinor): bool
    {
        $key = 'withdrawal:' . $withdrawalId . ':paid';
        if ($this->ledger->hasEvent($key)) {
            return true;
        }
        $amount = Money::of($amountMinor);
        $transaction = (new LedgerTransaction($key, $vendorUserId, 'withdrawal', (string) $withdrawalId))
            ->add(LedgerAccount::VendorEarning, $amount, 'withdrawal_paid')
            ->add(LedgerAccount::VendorPayout, $amount->negate(), 'withdrawal_paid');
        return $this->ledger->record($transaction);
    }
}
