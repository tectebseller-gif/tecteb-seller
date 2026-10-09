<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Application;

use Tecteb\Marketplace\Contracts\TransactionInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStateMachine;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStatus;
use Tecteb\Marketplace\Modules\Vendor\Application\OperationResult;
use Tecteb\Marketplace\Modules\Vendor\Application\StaffAccess;
use Tecteb\Marketplace\Modules\Vendor\Application\StoreRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffArea;
use Tecteb\Marketplace\Modules\Vendor\Domain\StaffLevel;

/**
 * The vendor asking for their money.
 *
 * FIN-05 in four rules, each of which is a line in this class:
 *
 *  - **No amount field.** «فروشنده مبلغ دلخواه وارد نمی‌کند» (A.2). One
 *    request takes the whole eligible balance, which removes a whole class of
 *    argument about what "the balance" was at the moment of asking.
 *  - **Snapshot and lock in one step.** The amount is written with the lines
 *    that back it; if any line was already claimed the request does not exist
 *    at all. There is no window where a vendor sees an amount no set of lines
 *    supports.
 *  - **One open request per vendor** in v1; later sales wait for the next one.
 *  - **A double click is one request.** The second attempt finds the first and
 *    returns it, rather than making another — and beneath that, the database
 *    would refuse anyway.
 *
 * Whether ANY of this may run is not this class's decision: SettlementGate
 * answers that, and while DEC-02 and FIN-04 are open a request is refused with
 * those names rather than a vague «فعلاً نمی‌شود».
 */
final class RequestWithdrawal
{
    public function __construct(
        private readonly WithdrawalRepositoryInterface $withdrawals,
        private readonly VendorBalance $balance,
        private readonly SettlementGate $gate,
        private readonly StaffAccess $access,
        private readonly StoreRepositoryInterface $stores,
        private readonly WithdrawalStateMachine $states,
        private readonly AuditLogger $audit,
        /**
         * The unit of work a cancel needs.
         *
         * `alpha.39` closed the status and released the lines as two separate
         * writes and reported `release_failed` when the second one lost. That
         * is a true sentence about money that is still reserved against a
         * request nobody will finish — and a true sentence is not a fix. Both
         * writes are one transaction now, and a build without a unit of work
         * refuses rather than doing the pair unsafely, the same way
         * `ReviewWithdrawals` refuses to record a payment it cannot make
         * atomic. Optional so every existing construction site keeps working.
         */
        private readonly ?TransactionInterface $tx = null
    ) {
    }

    public function handle(int $actorId, int $vendorUserId): OperationResult
    {
        // Money is the accountant's area; the owner has it by being the owner.
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Finance, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $gate = $this->gate->check();
        if (!$gate['ready']) {
            return OperationResult::failure('settlement_blocked', [
                'reason' => $gate['reason'],
                'decisions' => implode('، ', SettlementGate::OPEN_DECISIONS),
            ]);
        }
        // A double click, or a second tab: the request that already exists is
        // the answer, not a second one.
        $open = $this->withdrawals->openFor($vendorUserId);
        if ($open !== null) {
            return OperationResult::failure('withdrawal_already_open', [
                'withdrawal_id' => $open->id,
                'amount_minor' => $open->amountMinor,
            ]);
        }
        $bank = $this->stores->bank($vendorUserId);
        if (trim((string) $bank['iban']) === '') {
            return OperationResult::failure('bank_account_missing');
        }
        if ((bool) $bank['on_hold']) {
            // A.4: changing the IBAN puts settlement on hold on purpose.
            return OperationResult::failure('bank_account_on_hold');
        }
        $balance = $this->balance->of($vendorUserId);
        if ($balance['eligible_line_ids'] === [] || $balance['eligible'] <= 0) {
            return OperationResult::failure('nothing_eligible', [
                'pending' => $balance['pending'],
                'awaiting_completion' => $balance['awaiting_completion'],
                'awaiting_delay' => $balance['awaiting_delay'],
                'delay_days' => $balance['delay_days'],
            ]);
        }
        $withdrawalId = $this->withdrawals->reserve(
            $vendorUserId,
            $balance['eligible_line_ids'],
            $balance['eligible'],
            (string) $bank['iban'],
            (string) $bank['holder']
        );
        if ($withdrawalId <= 0) {
            // Another request took at least one of these lines between the
            // read and the write. Nothing was created; asking again will see
            // the smaller balance.
            return OperationResult::failure('reservation_lost');
        }
        $this->audit->log(AuditEventCatalog::WITHDRAWAL_REQUESTED, $actorId, 'withdrawal', (string) $withdrawalId, [
            'vendor_id' => $vendorUserId,
            'withdrawal_id' => $withdrawalId,
            'amount_minor' => $balance['eligible'],
            'lines' => count($balance['eligible_line_ids']),
        ]);
        return OperationResult::success('withdrawal_requested', [
            'withdrawal_id' => $withdrawalId,
            'amount_minor' => $balance['eligible'],
            'lines' => count($balance['eligible_line_ids']),
        ]);
    }

    /** The vendor changing their mind, while that is still free. */
    public function cancel(int $actorId, int $vendorUserId, int $withdrawalId): OperationResult
    {
        if (!$this->access->can($actorId, $vendorUserId, StaffArea::Finance, StaffLevel::Edit)) {
            return OperationResult::failure('forbidden');
        }
        $withdrawal = $this->withdrawals->find($withdrawalId);
        if ($withdrawal === null || $withdrawal->vendorUserId !== $vendorUserId) {
            return OperationResult::failure('not_found');
        }
        if (!$this->states->vendorMayCancel($withdrawal->status)) {
            // Once a transfer has started, cancelling is no longer a matter of
            // freeing a reservation, and the vendor is not the one who decides.
            return OperationResult::failure('invalid_transition', ['from' => $withdrawal->status->value]);
        }
        // ONE UNIT OF WORK: the status, the unclaim and the reserve lines.
        //
        // `alpha.39` did these as two independent writes and said
        // `release_failed` when the second lost. True, and not a fix: the
        // request was closed and the money still reserved against it, which is
        // the exact shape «درخواست بسته با اقلام رزروشده» names. A build with
        // no transaction available refuses instead of trying.
        if ($this->tx === null) {
            return OperationResult::failure('cancel_not_atomic', ['withdrawal_id' => $withdrawalId]);
        }
        if (!$this->tx->begin()) {
            return OperationResult::failure('storage_failed', ['withdrawal_id' => $withdrawalId]);
        }
        // The status this cancel was DECIDED about goes into the write.
        //
        // The owner's scenario, and it was open until `alpha.39`: the vendor's
        // page reads «Approved» and offers Cancel; a manager moves the request
        // to «PaymentInProgress»; the vendor's click arrives, its `canMove()`
        // answer is about the status it read, and the write had no condition
        // on it — so the newer status was overwritten and its lines freed
        // while a transfer was being prepared. Now the row must still be in
        // the status this decision was made about, and when it is not the
        // vendor is told to look again. The guard survives the transaction:
        // it is in the `WHERE`, not in a read beforehand.
        if (!$this->withdrawals->updateStatus(
            $withdrawalId,
            WithdrawalStatus::Cancelled,
            $actorId,
            '',
            '',
            $withdrawal->status
        )) {
            $this->tx->rollback();
            return OperationResult::failure('withdrawal_moved_on', [
                'from' => $withdrawal->status->value,
                // Read again, so the message names where it actually is now
                // rather than where this request thought it was. Read AFTER
                // the rollback, so it reports the committed row.
                'now' => $this->withdrawals->find($withdrawalId)?->status->value ?? '',
            ]);
        }
        // CHECKED, and inside the same unit of work: a release that fails now
        // takes the status change with it, so there is no closed request
        // holding money for anybody to find later.
        if (!$this->withdrawals->release($withdrawalId)) {
            $this->tx->rollback();
            return OperationResult::failure('release_failed', [
                'withdrawal_id' => $withdrawalId,
                // Nothing changed: the request is still open and the vendor
                // can try again once whatever failed is fixed.
                'status' => $withdrawal->status->value,
            ]);
        }
        if (!$this->tx->commit()) {
            $this->tx->rollback();
            return OperationResult::failure('storage_failed', ['withdrawal_id' => $withdrawalId]);
        }
        // AFTER the commit, both of them: an audit line about a rolled-back
        // cancel is a false record, and a cache invalidated for one would
        // advertise money that is still reserved.
        $this->withdrawals->figuresChanged($vendorUserId);
        $this->audit->log(AuditEventCatalog::WITHDRAWAL_REVIEWED, $actorId, 'withdrawal', (string) $withdrawalId, [
            'vendor_id' => $vendorUserId,
            'withdrawal_id' => $withdrawalId,
            'from' => $withdrawal->status->value,
            'to' => WithdrawalStatus::Cancelled->value,
            'has_note' => false,
        ]);
        return OperationResult::success('withdrawal_cancelled', ['withdrawal_id' => $withdrawalId]);
    }
}
