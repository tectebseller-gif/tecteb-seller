<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Application;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\TransactionInterface;
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
 *
 * ### One snapshot, one lock
 *
 * The report, the token that travels with its form and the figure the record
 * freezes all come from ONE `DokanFinanceSnapshot`. Three separate reads —
 * which is what `alpha.19` did — are three different moments, and each is
 * individually correct about an instant that has already passed.
 *
 * The decision then holds the shop's version row under a write lock while it
 * reads, compares and writes, because a comparison made between a read and a
 * write is a comparison two writers both pass. Re-reading is not a guard; the
 * lock is. See `decide()`.
 */
final class ReconcileDokanFinance
{
    /**
     * The option the decisions lived in until `alpha.20`.
     *
     * Kept as a name, not as storage: migration 18 copies its contents into
     * `tmc_dokan_handover` and leaves the option itself untouched, so a site
     * rolled back to `alpha.19` still reads what it wrote. Nothing in this
     * class reads or writes it any more.
     */
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
        private readonly FinanceHandoverRepositoryInterface $handover,
        private readonly TransactionInterface $tx,
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
        return $this->reportFrom($this->snapshotFor($vendorUserId));
    }

    /**
     * This shop's figures as they stand, read in one pass.
     *
     * The version is read plainly here, not locked: a report is a read, and
     * holding a write lock for the length of a page render would make every
     * import wait on somebody's browser. `decide()` takes the same snapshot
     * under a lock, which is where the guarantee belongs.
     */
    public function snapshotFor(int $vendorUserId): DokanFinanceSnapshot
    {
        return DokanFinanceSnapshot::read(
            $this->records,
            $vendorUserId,
            $this->records->recordsVersion($vendorUserId)
        );
    }

    /**
     * One shop's reconciliation, as two columns that are never added
     * together — built from a snapshot and nothing else.
     *
     * Taking the snapshot as an argument is what lets the page print a report
     * and hand its form the token for THAT report. Before `alpha.20` the page
     * rendered from one read and then asked for a token from another, so the
     * receipt could be for figures the manager had not been shown.
     *
     * @return array<string,mixed>
     */
    /**
     * Every reason the two columns can differ, named — and nothing
     * reconciled.
     *
     * The report has always shown Dokan's figures beside this marketplace's
     * and refused to add them. What it did not do was say WHY they differ,
     * and a difference with no name is a difference somebody explains to
     * themselves. Each entry below is a fact that was read, not a judgement:
     *
     *  - `nothing_traded_here_yet` — the only honest reason for a zero in
     *    column two, and the common one: a shop that was imported and has
     *    not sold through this marketplace has no ledger rows at all.
     *  - `handover_decision_open` — nobody has said what to do with the
     *    imported past, so column two is not supposed to contain it.
     *  - `dokan_records_paid_withdrawals_twice` — Dokan writes a withdraw
     *    row AND a debit in the balance table for the same payment, so
     *    `closing` has already subtracted it. This is the one difference
     *    that looks like money going missing and is not.
     *  - `dokan_money_columns_carry_no_unit` — Dokan's columns are numbers
     *    with no currency and no scale; every figure in column two carries
     *    both (`tmc_ledger`, migration 4). Comparing them is only safe once
     *    somebody says what Dokan's numbers were denominated in.
     *  - `order_totals_read_from_dokan_orders` — a standing limitation,
     *    named since `alpha.53`: the 5.x reference is `dokan_order_stats`,
     *    whose `order_type` separates a parent from a sub-order, so a total
     *    taken from `dokan_orders` counts both. Changing the reference is an
     *    owner decision (`docs/open-decisions-alpha56.md` §10), so until
     *    then every order-derived figure here says where it came from.
     *
     * @param array{count:int, debit:string} $overlap
     * @param array<string,mixed> $decision
     * @return list<array{code:string, detail:string}>
     */
    private function differencesFor(
        DokanFinanceSnapshot $snapshot,
        array $overlap,
        array $decision
    ): array {
        $out = [];
        $balances = $this->ledger?->balances($snapshot->vendorUserId) ?? [];
        if ($this->ledger === null) {
            $out[] = ['code' => 'marketplace_ledger_unavailable', 'detail' => 'the finance module is not loaded'];
        } elseif ($balances === []) {
            $out[] = [
                'code' => 'nothing_traded_here_yet',
                'detail' => 'this shop has no ledger rows in this marketplace',
            ];
        }
        // THE UNIT OF COLUMN TWO, said rather than assumed. `balances()`
        // answers per account with the exponent it is in, and an account
        // holding two currencies refuses outright. Both are facts a human
        // comparing two columns has to be told, because a figure whose scale
        // is unstated is exactly what makes somebody add it to Dokan's bare
        // number (`alpha.68` §2).
        foreach ($balances as $account => $figure) {
            if (!is_array($figure)) {
                continue;
            }
            $refused = (string) ($figure['refused'] ?? '');
            if ($refused === 'mixed_currencies') {
                $out[] = [
                    'code' => 'marketplace_account_holds_two_currencies',
                    'detail' => (string) $account . ' holds ' . (string) ($figure['currencies'] ?? '')
                        . ' and is not summed; nothing is converted',
                ];
                continue;
            }
            if ($refused !== '') {
                $out[] = [
                    'code' => 'marketplace_account_not_summable',
                    'detail' => (string) $account . ' could not be taken in one unit: ' . $refused,
                ];
                continue;
            }
            if (count((array) ($figure['groups'] ?? [])) > 1) {
                $out[] = [
                    'code' => 'marketplace_account_spans_two_scales',
                    'detail' => (string) $account . ' was recorded at more than one exponent and is '
                        . 'combined exactly into 1e' . (string) ($figure['exponent'] ?? '?'),
                ];
            }
        }
        if (($decision['decision'] ?? self::OPEN) === self::OPEN) {
            $out[] = [
                'code' => 'handover_decision_open',
                'detail' => 'nobody has decided what happens to the imported past',
            ];
        }
        if ((int) $overlap['count'] > 0) {
            $out[] = [
                'code' => 'dokan_records_paid_withdrawals_twice',
                'detail' => $overlap['count'] . ' withdraw row(s) are also debits inside closing: ' . $overlap['debit'],
            ];
        }
        if ((int) $snapshot->summary['balance_rows'] > 0) {
            $out[] = [
                'code' => 'dokan_money_columns_carry_no_unit',
                'detail' => 'dokan stores bare numbers; every figure on this side carries currency and scale',
            ];
        }
        // Always named, because it is true of every figure in column one that
        // came from an order — including when the two columns happen to agree.
        $out[] = [
            'code' => 'order_totals_read_from_dokan_orders',
            'detail' => 'the 5.x reference is dokan_order_stats; changing it is an owner decision',
        ];
        return $out;
    }

    public function reportFrom(DokanFinanceSnapshot $snapshot): array
    {
        $overlap = $snapshot->withdrawOverlap(self::WITHDRAW_TYPE);
        $decision = $this->decisionFor($snapshot->vendorUserId);

        return [
            'vendor_user_id' => $snapshot->vendorUserId,

            // Column one: what Dokan recorded. Copied, never restated.
            'dokan' => [
                'balance_rows' => (int) $snapshot->summary['balance_rows'],
                'credit' => (string) $snapshot->summary['credit'],
                'debit' => (string) $snapshot->summary['debit'],
                'closing' => $snapshot->closing,
                'by_type' => $snapshot->byType,
                'withdrawal_rows' => (int) $snapshot->summary['withdrawals'],
                'withdrawals_by_status' => $snapshot->byStatus,
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
                // Each account with the unit it is in — never a bare integer.
                // `minor` is `null` with `refused: mixed_currencies` where
                // two currencies sit in one account, because there is no one
                // total to print (`alpha.68` §2).
                'ledger_balances' => $this->ledger?->balances($snapshot->vendorUserId) ?? [],
                'open_withdrawal' => $this->withdrawals?->openFor($snapshot->vendorUserId) !== null,
            ],

            // WHY the two columns differ, each reason named. §7 of
            // `alpha.56`: «هر اختلاف تطبیق باید قابل توضیح باشد». Two
            // columns side by side with different numbers and no explanation
            // is the shape that makes somebody add them up.
            'differences' => $this->differencesFor($snapshot, $overlap, $decision),

            'handover' => $decision,
            'complete' => $decision['decision'] !== self::OPEN,

            // Which version of the imported past this report describes, and
            // the token that travels with its form.
            'records_version' => $snapshot->recordsVersion,
            'figures_token' => $snapshot->token(),
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
     * The version match for this shop's figures as they stand right now.
     *
     * Convenience over `snapshotFor()->token()`, kept because a caller that
     * only wants the token should not have to know a snapshot type exists.
     * A caller that is also RENDERING the figures must take the snapshot
     * itself and use its token, or it is printing one report and issuing a
     * receipt for another.
     */
    public function figuresToken(int $vendorUserId): string
    {
        return $this->snapshotFor($vendorUserId)->token();
    }

    /**
     * Record who is responsible for a shop's imported balance.
     *
     * Writes one row and one audit line. It writes **no ledger line**, creates
     * **no withdrawal**, and touches **no Dokan table** — which is the whole
     * point, and is measured as a delta in the evidence rather than asserted
     * here.
     *
     * ### How the figures are held still
     *
     * The token check used to be a comparison in PHP between two reads, which
     * is a check that concurrent writers both pass — the same defect the
     * product form had in `alpha.13`. Here the shop's version row is taken
     * under a write lock FIRST; every write to this shop's imported rows
     * bumps that same row inside its own transaction, so while this lock is
     * held no import, rollback or extension can land. The snapshot is then
     * read inside the lock, the token compared against it, and the figure it
     * carries written — all from the one snapshot, and all before the lock is
     * released at commit.
     *
     * The token stands for «these figures at this version», not for anybody
     * having read them. See `DokanFinanceSnapshot`.
     *
     * @return array{ok:bool, reason:string, handover:array<string,mixed>}
     */
    public function decide(int $vendorUserId, string $decision, string $note = '', string $seenToken = ''): array
    {
        $current = $this->decisionFor($vendorUserId);
        if (!$this->caps->can(Capabilities::REVIEW_VENDOR)) {
            return ['ok' => false, 'reason' => 'forbidden', 'handover' => $current];
        }
        if (!in_array($decision, self::decisions(), true)) {
            return ['ok' => false, 'reason' => 'unknown_decision', 'handover' => $current];
        }
        // Refused before the lock, not inside it: an empty token cannot become
        // valid by looking at the database, and a transaction opened to learn
        // nothing is a transaction that blocks an import for nothing.
        if ($decision !== self::OPEN && $seenToken === '') {
            return ['ok' => false, 'reason' => 'report_not_seen', 'handover' => $current];
        }

        if (!$this->tx->begin()) {
            return ['ok' => false, 'reason' => 'storage_failed', 'handover' => $current];
        }

        // From here to the commit, this shop's imported past cannot move.
        $version = $this->records->lockRecordsVersion($vendorUserId);
        $snapshot = DokanFinanceSnapshot::read($this->records, $vendorUserId, $version);

        // Both closing decisions are statements about money — one takes the
        // figure on, the other declares we owe nothing for it — and both shut
        // the `finance_decided` gate. Neither may be made against figures that
        // have moved. Returning TO `open` needs no match: it creates no
        // obligation, it removes one.
        $expectedToken = $snapshot->token();
        if ($decision !== self::OPEN && !hash_equals($expectedToken, $seenToken)) {
            $this->tx->rollback();
            return ['ok' => false, 'reason' => 'figures_changed', 'handover' => $current];
        }

        $record = [
            'decision' => $decision,
            // Frozen on purpose, and taken from the SAME snapshot the token
            // was computed over: see the class docblock. Read back for the
            // report and for the audit trail, never for arithmetic.
            'closing_at_decision' => $snapshot->closing,
            'decided_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            'decided_by' => $this->caps->currentUserId() ?? 0,
            'note' => mb_substr(trim($note), 0, 500),
            // Named so that «we took the balance» can never be misread as «we
            // took the unpaid requests too». Paying them is DEC-06 and is not
            // this decision.
            'pending_requests_untouched' => $this->pendingCount($snapshot->byStatus),
            // Which report was on screen, and which version of the imported
            // past it described. Kept so «what did they agree to» names the
            // document as well as the amount.
            'figures_token' => $decision === self::OPEN ? '' : $expectedToken,
            'records_version' => $version,
        ];

        if (!$this->handover->record($vendorUserId, $record)) {
            $this->tx->rollback();
            return ['ok' => false, 'reason' => 'storage_failed', 'handover' => $current];
        }
        if (!$this->tx->commit()) {
            return ['ok' => false, 'reason' => 'storage_failed', 'handover' => $current];
        }

        // Audited after the commit, because an audit line for a decision the
        // database refused would be a record of something that never happened.
        $this->audit->log(
            AuditEventCatalog::DOKAN_FINANCE_HANDOVER,
            (int) $record['decided_by'],
            'vendor',
            (string) $vendorUserId,
            [
                'vendor_id' => $vendorUserId,
                'closing' => $snapshot->closing,
                'paid_by_dokan' => (string) $snapshot->withdrawOverlap(self::WITHDRAW_TYPE)['debit'],
                'pending_requests' => (int) $record['pending_requests_untouched'],
                'pending_total' => $this->pendingTotal($snapshot->byStatus),
                'decision' => $decision,
                'figures_token' => (string) $record['figures_token'],
                'records_version' => $version,
            ]
        );

        return ['ok' => true, 'reason' => 'handover_recorded', 'handover' => $record];
    }

    /** @return array<string,mixed> */
    public function decisionFor(int $vendorUserId): array
    {
        return $this->handover->decisionFor($vendorUserId);
    }

    /** The shops whose financial responsibility nobody has settled. @return list<int> */
    public function stillOpen(): array
    {
        // One read of the decisions rather than one per shop: this used to
        // unserialise the whole option inside the loop, and it is now a query
        // inside the loop, which is worse on a site with many shops.
        $decided = $this->handover->allDecisions();
        $open = [];
        foreach ($this->records->vendorsWithRecords() as $vendorUserId) {
            if ((string) ($decided[$vendorUserId]['decision'] ?? self::OPEN) === self::OPEN) {
                $open[] = $vendorUserId;
            }
        }
        return $open;
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
