<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Core\Lifecycle\Capabilities;
use Tecteb\Marketplace\Modules\Finance\Application\LedgerRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Application\WithdrawalRepositoryInterface;

/**
 * What Dokan recorded, what this marketplace owes, and the one explicit step
 * between them.
 *
 * An imported balance is **history**, and history does not create an
 * obligation by being copied. So this class computes nothing and pays
 * nothing. It reports, and then it records a decision somebody made.
 *
 * ### The three things it must never do
 *
 * **No new rate.** Every figure comes back exactly as Dokan wrote it, summed
 * in SQL over `DECIMAL(19,4)`. A shop that earned under 5% and now sits on 8%
 * still sees 5%'s numbers. Nothing here multiplies.
 *
 * **No double counting.** Dokan books an approved withdrawal twice on
 * purpose: a row in its withdraw table, and a DEBIT in its balance ledger. So
 * the closing balance has ALREADY subtracted every paid withdrawal, and
 * `closing + withdrawn` is an overstatement by exactly the amount that was
 * paid. The report therefore never adds them — it shows the closing figure,
 * shows the withdrawals beside it, and names the overlap with the count and
 * total of the balance rows that ARE those withdrawals.
 *
 * **No second payment.** A withdrawal request Dokan left pending is a request
 * this marketplace has never seen. Accepting responsibility for a balance does
 * not create a `tmc_withdrawals` row, does not call `RequestWithdrawal`, and
 * does not move money. Whether those requests get paid, and by whom, is a
 * commercial decision this code will not guess (DEC-06).
 *
 * ### Why the decision freezes a figure
 *
 * `decide()` stores the closing balance as it stood at the moment of the
 * decision. Not for arithmetic — nothing reads it back to compute — but so
 * that «what did we agree to take on» has one answer six months later, when
 * the imported ledger may have been re-imported, rolled back or extended.
 * A hand-over whose amount is re-derived on every page load is a hand-over
 * nobody can audit.
 */
final class ReconcileDokanFinance
{
    public const SETTING = 'tmc_dokan_finance_handover';

    /** Nobody has looked at this shop's balance yet. The starting state. */
    public const OPEN = 'open';
    /** This marketplace has taken responsibility for settling the figure. */
    public const ACCEPTED = 'accepted';
    /** The figure is settled outside this marketplace; we owe nothing for it. */
    public const STAYS_OUTSIDE = 'stays_outside';

    /** The transaction type Dokan gives the debit it books for a paid withdrawal. */
    public const WITHDRAW_TYPE = 'dokan_withdraw';

    public function __construct(
        private readonly ShopRecordRepositoryInterface $records,
        private readonly OptionStoreInterface $options,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly CapabilityCheckerInterface $caps,
        private readonly ?LedgerRepositoryInterface $ledger = null,
        private readonly ?WithdrawalRepositoryInterface $withdrawals = null
    ) {
    }

    /** @return list<string> the decisions a person may record */
    public static function decisions(): array
    {
        return [self::OPEN, self::ACCEPTED, self::STAYS_OUTSIDE];
    }

    /**
     * One shop's reconciliation, as two columns that are never added
     * together.
     *
     * @return array<string,mixed>
     */
    public function forVendor(int $vendorUserId): array
    {
        $summary = $this->records->summaryForVendor($vendorUserId);
        $byType = $this->records->balanceByType($vendorUserId);
        $byStatus = $this->records->withdrawalsByStatus($vendorUserId);
        $overlap = $byType[self::WITHDRAW_TYPE] ?? ['count' => 0, 'debit' => '0', 'credit' => '0'];
        $decision = $this->decisionFor($vendorUserId);

        return [
            'vendor_user_id' => $vendorUserId,

            // Column one: what Dokan recorded. Copied, never restated.
            'dokan' => [
                'balance_rows' => (int) $summary['balance_rows'],
                'credit' => (string) $summary['credit'],
                'debit' => (string) $summary['debit'],
                'closing' => $this->records->closingBalance($vendorUserId),
                'by_type' => $byType,
                'withdrawal_rows' => (int) $summary['withdrawals'],
                'withdrawals_by_status' => $byStatus,
            ],

            // The overlap, named rather than implied: these balance debits ARE
            // the paid withdrawals. `closing` already has them subtracted, so
            // the two figures must never be summed.
            'already_counted_once' => [
                'rows' => (int) $overlap['count'],
                'debit' => (string) $overlap['debit'],
                'note' => 'withdraw_debits_are_inside_closing',
            ],

            // Column two: what THIS marketplace's own engine holds. A shop
            // that has traded here since the cutover has real figures; a shop
            // that has only been imported has none, and the report says «۰»
            // rather than blending the two.
            'marketplace' => [
                'ledger_available' => $this->ledger !== null,
                'ledger_balances' => $this->ledger?->balances($vendorUserId) ?? [],
                'open_withdrawal' => $this->withdrawals?->openFor($vendorUserId) !== null,
            ],

            'handover' => $decision,
            'complete' => $decision['decision'] !== self::OPEN,
        ];
    }

    /** @return list<array<string,mixed>> every shop with imported records */
    public function all(): array
    {
        $out = [];
        foreach ($this->records->vendorsWithRecords() as $vendorUserId) {
            $out[] = $this->forVendor($vendorUserId);
        }
        return $out;
    }

    /**
     * Record who is responsible for a shop's imported balance.
     *
     * Writes one option entry and one audit row. It writes **no ledger line**,
     * creates **no withdrawal**, and touches **no Dokan table** — which is the
     * whole point, and is measured as a delta in the evidence rather than
     * asserted here.
     *
     * @return array{ok:bool, reason:string, handover:array<string,mixed>}
     */
    public function decide(int $vendorUserId, string $decision, string $note = ''): array
    {
        $current = $this->decisionFor($vendorUserId);
        if (!$this->caps->can(Capabilities::REVIEW_VENDOR)) {
            return ['ok' => false, 'reason' => 'forbidden', 'handover' => $current];
        }
        if (!in_array($decision, self::decisions(), true)) {
            return ['ok' => false, 'reason' => 'unknown_decision', 'handover' => $current];
        }

        $closing = $this->records->closingBalance($vendorUserId);
        $pending = $this->records->withdrawalsByStatus($vendorUserId);
        $record = [
            'decision' => $decision,
            // Frozen on purpose: see the class docblock. Read back for the
            // report and for the audit trail, never for arithmetic.
            'closing_at_decision' => $closing,
            'decided_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            'decided_by' => $this->caps->currentUserId() ?? 0,
            'note' => mb_substr(trim($note), 0, 500),
            // Named so that «we took the balance» can never be misread as «we
            // took the unpaid requests too». Paying them is DEC-06 and is not
            // this decision.
            'pending_requests_untouched' => $this->pendingCount($pending),
        ];

        $all = $this->allDecisions();
        $all[(string) $vendorUserId] = $record;
        if (!$this->options->set(self::SETTING, $all)) {
            return ['ok' => false, 'reason' => 'storage_failed', 'handover' => $current];
        }

        $this->audit->log(
            AuditEventCatalog::DOKAN_FINANCE_HANDOVER,
            (int) $record['decided_by'],
            'vendor',
            (string) $vendorUserId,
            [
                'vendor_id' => $vendorUserId,
                'closing' => $closing,
                'paid_by_dokan' => (string) (($this->records->balanceByType($vendorUserId)[self::WITHDRAW_TYPE]['debit']) ?? '0'),
                'pending_requests' => (int) $record['pending_requests_untouched'],
                'pending_total' => $this->pendingTotal($pending),
                'decision' => $decision,
            ]
        );

        return ['ok' => true, 'reason' => 'handover_recorded', 'handover' => $record];
    }

    /** @return array<string,mixed> */
    public function decisionFor(int $vendorUserId): array
    {
        $all = $this->allDecisions();
        return $all[(string) $vendorUserId] ?? [
            'decision' => self::OPEN,
            'closing_at_decision' => '',
            'decided_at' => '',
            'decided_by' => 0,
            'note' => '',
            'pending_requests_untouched' => 0,
        ];
    }

    /** The shops whose financial responsibility nobody has settled. @return list<int> */
    public function stillOpen(): array
    {
        $open = [];
        foreach ($this->records->vendorsWithRecords() as $vendorUserId) {
            if ($this->decisionFor($vendorUserId)['decision'] === self::OPEN) {
                $open[] = $vendorUserId;
            }
        }
        return $open;
    }

    /** @return array<string,array<string,mixed>> */
    private function allDecisions(): array
    {
        $raw = $this->options->get(self::SETTING, []);
        return is_array($raw) ? $raw : [];
    }

    /**
     * How many withdrawal requests Dokan had not settled.
     *
     * «Not settled» is read from the status Dokan itself stored, and the set
     * of statuses that mean «paid» is Dokan's vocabulary, not ours — so
     * anything that is not plainly a completed/approved/cancelled row counts
     * as outstanding. Over-reporting an outstanding request asks a person a
     * question; under-reporting one loses it.
     *
     * @param array<string,array{count:int,total:string}> $byStatus
     */
    private function pendingCount(array $byStatus): int
    {
        $count = 0;
        foreach ($byStatus as $status => $tally) {
            if (!self::statusIsFinished((string) $status)) {
                $count += (int) $tally['count'];
            }
        }
        return $count;
    }

    /** @param array<string,array{count:int,total:string}> $byStatus */
    private function pendingTotal(array $byStatus): string
    {
        // Concatenated per status rather than summed: adding two decimal
        // strings in PHP is the rounding this file exists to avoid, and the
        // caller that wants one number can ask SQL for it.
        $parts = [];
        foreach ($byStatus as $status => $tally) {
            if (!self::statusIsFinished((string) $status)) {
                $parts[] = $status . '=' . $tally['total'];
            }
        }
        return implode(' ', $parts);
    }

    /**
     * Statuses that mean Dokan finished with the request — either it paid it
     * or it cancelled it.
     *
     * **The `dokan:` prefix is not decoration.** `dokan_withdraw.status` is an
     * `int(1)` holding 0/1/2, and the reader deliberately keeps it as
     * `dokan:1` rather than translating it into one of our withdrawal states,
     * because they are not the same set. Compare against the bare integer and
     * every imported row falls through as outstanding — including the ones
     * Dokan already paid, which is the single most expensive way to be wrong
     * here. So the prefix comes off first, and then the comparison is over
     * Dokan's own vocabulary: 0 pending, 1 approved, 2 cancelled, plus the
     * words its newer code writes.
     */
    private static function statusIsFinished(string $status): bool
    {
        $status = strtolower(trim($status));
        if (str_starts_with($status, 'dokan:')) {
            $status = substr($status, 6);
        }
        return in_array($status, ['1', '2', 'approved', 'completed', 'cancelled', 'canceled'], true);
    }
}
