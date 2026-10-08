<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Finance\Application\WithdrawalRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\Withdrawal;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStatus;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0007SettlementTables as T;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0006CatalogAndOrders as OrderTables;

/**
 * Withdrawal requests and the lines they lock.
 *
 * The locking is done by the database, not by this class. `reserve()` inserts
 * the request — whose own unique index allows one open request per vendor —
 * then claims the order items in a single guarded `UPDATE`, then inserts one
 * row per line into a table whose unique key is the line id. All of it is one
 * transaction, so a line already held, a statement that fails or a commit that
 * fails leaves nothing behind and the caller is told it got nothing.
 *
 * Doing it this way, rather than "check then insert", is the point: two tabs,
 * a double click and two workers all reach the same index, and only one of
 * them wins (FIN-05). A check would let both pass and both write.
 *
 * `alpha.39` removed the hand-rolled compensating delete that used to undo a
 * half-written reservation. A rollback cannot half-succeed and the deletes
 * could, so keeping both would have been two answers to one question — and
 * the method had no caller left.
 */
final class DbWithdrawalRepository implements WithdrawalRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    // Note on every `execute()` below: it answers NULL when the statement
    // failed and an affected-row count otherwise, and zero rows affected is a
    // success. So each check is against null — `if (!$result)` would read a
    // successful no-op as a failure and undo a reservation that was fine.

    public function find(int $withdrawalId): ?Withdrawal
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->table() . '` WHERE id = %d', [$withdrawalId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function openFor(int $vendorUserId): ?Withdrawal
    {
        $row = $this->db->getRow(
            'SELECT * FROM `' . $this->table() . '` WHERE vendor_user_id = %d AND open_marker = 1',
            [$vendorUserId]
        );
        return $row === null ? null : $this->hydrate($row);
    }

    public function forVendor(int $vendorUserId, int $limit = 50): array
    {
        return array_map([$this, 'hydrate'], $this->db->getResults(
            'SELECT * FROM `' . $this->table() . '` WHERE vendor_user_id = %d ORDER BY id DESC LIMIT %d',
            [$vendorUserId, max(1, $limit)]
        ));
    }

    public function withStatus(?WithdrawalStatus $status = null, int $limit = 50): array
    {
        if ($status === null) {
            return array_map([$this, 'hydrate'], $this->db->getResults(
                'SELECT * FROM `' . $this->table() . '` ORDER BY id DESC LIMIT %d',
                [max(1, $limit)]
            ));
        }
        return array_map([$this, 'hydrate'], $this->db->getResults(
            'SELECT * FROM `' . $this->table() . '` WHERE status = %s ORDER BY id DESC LIMIT %d',
            [$status->value, max(1, $limit)]
        ));
    }

    public function countsByStatus(): array
    {
        $counts = [];
        foreach (WithdrawalStatus::all() as $status) {
            $counts[$status->value] = 0;
        }
        foreach ($this->db->getResults('SELECT status, COUNT(*) AS n FROM `' . $this->table() . '` GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        return $counts;
    }

    /**
     * One unit of work: the request, its reserved lines, and the claim on the
     * order items — all of it, or none of it.
     *
     * **What this was until `alpha.39`.** Four independent writes with no
     * transaction, and the last one's result thrown away. Its own comment said
     * «a half-reserved request would show a vendor an amount that no set of
     * lines backs», and it only defended against the one case where a line was
     * already claimed: a failed final `UPDATE`, or a process that died inside
     * the loop, left exactly the shape the comment forbade. And because
     * eligibility is read from `order_items.withdrawal_id`
     * (`DbOrderItemRepository::settlementView()`), a failed claim left those
     * lines counting as withdrawable while the open request blocked any new
     * one — a vendor shown a balance they could not ask for, and `release()`
     * with nothing to find.
     *
     * Three things changed, and each is checked:
     *
     *  - **A transaction.** `begin()`, every statement's result read, and
     *    `rollback()` on the first `null`. A failed `commit()` is a failure:
     *    the one answer that must not be assumed.
     *  - **The claim is the guard.** The `UPDATE` carries
     *    `WHERE withdrawal_id IS NULL AND vendor_user_id = %d` and the number
     *    of rows it changed is compared with the number of lines asked for.
     *    Zero changed rows is neither success nor failure by itself — it is
     *    measured against what this operation expected, which is the rule
     *    `alpha.8` states and this method ignored.
     *  - **The amount is the lines.** It is recomputed from the shares of the
     *    rows actually claimed, inside the same transaction, so a refund or a
     *    reversal landing between the balance calculation and the reservation
     *    cannot be reserved at yesterday's figure. A caller's amount that no
     *    longer matches is refused rather than stored.
     *
     * @param list<int> $orderItemIds
     * @return int the new withdrawal id, or 0 when nothing was written
     */
    public function reserve(
        int $vendorUserId,
        array $orderItemIds,
        int $amountMinor,
        string $iban,
        string $accountHolder
    ): int {
        $ids = array_values(array_unique(array_map('intval', $orderItemIds)));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === [] || $vendorUserId <= 0 || $amountMinor <= 0) {
            return 0;
        }
        $now = $this->now();
        if (!$this->db->begin()) {
            return 0;
        }

        // The REQUEST first, because its own unique index is the real guard
        // against a second open request per vendor — a read beforehand is a
        // courtesy, this is the rule. (It also means no sentinel value is
        // needed on the order items: `withdrawal_id` is `BIGINT UNSIGNED`, so
        // the obvious «claim with -1 first» does not fit the column.)
        $created = $this->db->execute(
            'INSERT INTO `' . $this->table() . '`
             (vendor_user_id, status, amount_minor, line_count, open_marker, iban, account_holder, created_at, updated_at)
             VALUES (%d, %s, %d, %d, 1, %s, %s, %s, %s)',
            [
                $vendorUserId,
                WithdrawalStatus::Requested->value,
                $amountMinor,
                count($ids),
                $iban,
                $accountHolder,
                $now,
                $now,
            ]
        );
        if ($created === null) {
            $this->db->rollback();
            return 0;
        }
        $withdrawalId = (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
        if ($withdrawalId <= 0) {
            $this->db->rollback();
            return 0;
        }

        // THE CLAIM IS THE GUARD. Every condition a line must still satisfy is
        // in the `WHERE`, so «was it free when we wrote» is answered by the
        // write rather than by a read taken earlier — and the number of rows
        // it changed is compared with the number asked for. Zero changed rows
        // is neither success nor failure by itself; it is measured against
        // what this operation expected, which is the `alpha.8` rule this
        // method used to ignore entirely.
        $claimed = $this->db->execute(
            'UPDATE `' . $this->orderItems() . '` SET withdrawal_id = %d, updated_at = %s
             WHERE id IN (' . $this->placeholders($ids) . ')
               AND vendor_user_id = %d
               AND withdrawal_id IS NULL
               AND vendor_share_minor IS NOT NULL
               AND settlement_completed_at IS NOT NULL',
            array_merge([$withdrawalId, $now], $ids, [$vendorUserId])
        );
        if ($claimed === null || $claimed !== count($ids)) {
            // Either the statement failed, or somebody else holds a line, or a
            // line stopped being eligible between the balance and here.
            $this->db->rollback();
            return 0;
        }

        // The figure, recomputed from what was actually claimed and read
        // inside the transaction. «اگر بین محاسبهٔ مانده و رزرو، مرجوعی یا
        // تغییر مؤثر دیگری رخ دهد، مبلغ کهنه رزرو نشود» — a caller's amount
        // that no longer matches the lines is refused, and asking again sees
        // the new balance.
        $sum = $this->db->getVar(
            'SELECT COALESCE(SUM(vendor_share_minor), 0) FROM `' . $this->orderItems() . '`
             WHERE withdrawal_id = %d',
            [$withdrawalId]
        );
        if ($sum === null || (int) $sum !== $amountMinor) {
            $this->db->rollback();
            return 0;
        }

        foreach ($ids as $orderItemId) {
            $line = $this->db->execute(
                'INSERT INTO `' . $this->lines() . '` (withdrawal_id, order_item_id, vendor_user_id, amount_minor, created_at)
                 VALUES (%d, %d, %d, 0, %s)',
                [$withdrawalId, $orderItemId, $vendorUserId, $now]
            );
            if ($line === null) {
                $this->db->rollback();
                return 0;
            }
        }

        if (!$this->db->commit()) {
            // A commit can fail, and a caller told «reserved» after it did
            // would be looking at nothing.
            $this->db->rollback();
            return 0;
        }
        // AFTER the commit: a cache invalidated for a transaction that then
        // rolled back would advertise a reservation nobody made.
        $this->figuresChanged($vendorUserId);
        return $withdrawalId;
    }

    public function lineIds(int $withdrawalId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['order_item_id'],
            $this->db->getResults(
                'SELECT order_item_id FROM `' . $this->lines() . '` WHERE withdrawal_id = %d ORDER BY order_item_id ASC',
                [$withdrawalId]
            )
        );
    }

    public function updateStatus(
        int $withdrawalId,
        WithdrawalStatus $status,
        ?int $reviewerId,
        string $note,
        string $reference = '',
        ?WithdrawalStatus $expected = null
    ): bool {
        $now = $this->now();
        // `open_marker` is the unique index's half of "one open request per
        // vendor": 1 while open, NULL once closed, and repeated NULLs are
        // allowed in a unique index.
        $marker = $status->isOpen() ? '1' : 'NULL';
        $reviewer = $reviewerId === null ? 'NULL' : '%d';
        $paidAt = $status->isPaid() ? '%s' : 'paid_at';

        $params = [$status->value];
        if ($reviewerId !== null) {
            $params[] = $reviewerId;
        }
        $params[] = $now;
        $params[] = $note;
        $params[] = $reference;
        if ($status->isPaid()) {
            $params[] = $now;
        }
        $params[] = $now;
        $params[] = $withdrawalId;

        // THE EXPECTED STATUS IS IN THE `WHERE`.
        //
        // Until `alpha.39` this wrote `WHERE id = %d` and the transition was
        // validated in PHP against a status read earlier — so the owner's own
        // scenario held: the vendor's page reads «Approved» and offers Cancel;
        // a manager moves the request to «PaymentInProgress»; the vendor's
        // click arrives, finds its stale `canMove()` answer still true, and
        // overwrites the newer status and releases its lines. And because the
        // check was `!== null`, ZERO CHANGED ROWS counted as success, so the
        // caller was told the transition happened either way («alpha.8» cuts
        // both ways: zero is a success for a `DELETE` and a lost race here).
        //
        // Now the row must still be in the status the caller validated, and
        // zero changed rows is `false` — measured against what this operation
        // expected rather than read as success.
        $expectedClause = '';
        if ($expected !== null) {
            $expectedClause = ' AND status = %s';
            $params[] = $expected->value;
        }
        $rows = $this->db->execute(
            'UPDATE `' . $this->table() . "` SET status = %s, open_marker = {$marker},
             reviewed_by = {$reviewer}, reviewed_at = %s, note = %s, reference = %s,
             paid_at = {$paidAt}, updated_at = %s WHERE id = %d{$expectedClause}",
            $params
        );
        if ($rows === null || $rows === 0) {
            return false;
        }
        $this->figuresChanged((int) ($this->find($withdrawalId)?->vendorUserId ?? 0));
        return true;
    }

    /**
     * Gives a request's lines back — all of them, or none, and it SAYS which.
     *
     * The `UPDATE`'s answer used to be dropped and only the `DELETE`'s read,
     * so a failed unclaim reported success and left the lines inside a request
     * nobody would finish: invisible to the vendor's eligible balance and
     * invisible to any later release, because the next one looks for
     * `withdrawal_id = <id>` and the rows no longer point at it.
     *
     * Both statements are now one unit of work and both answers are read.
     * Zero rows is NOT failure here: a request whose lines were already given
     * back has nothing to unclaim, and that is the ordinary end of a second
     * call.
     */
    public function release(int $withdrawalId): bool
    {
        if ($withdrawalId <= 0) {
            return false;
        }
        $vendorUserId = (int) ($this->find($withdrawalId)?->vendorUserId ?? 0);
        if (!$this->db->begin()) {
            return false;
        }
        $unclaimed = $this->db->execute(
            'UPDATE `' . $this->orderItems() . '` SET withdrawal_id = NULL, updated_at = %s WHERE withdrawal_id = %d',
            [$this->now(), $withdrawalId]
        );
        if ($unclaimed === null) {
            $this->db->rollback();
            return false;
        }
        $removed = $this->db->execute(
            'DELETE FROM `' . $this->lines() . '` WHERE withdrawal_id = %d',
            [$withdrawalId]
        );
        if ($removed === null) {
            $this->db->rollback();
            return false;
        }
        if (!$this->db->commit()) {
            $this->db->rollback();
            return false;
        }
        if ($vendorUserId > 0) {
            $this->figuresChanged($vendorUserId);
        }
        return true;
    }

    /** @param list<int> $ids */
    private function placeholders(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '%d'));
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Withdrawal
    {
        return new Withdrawal(
            (int) $row['id'],
            (int) $row['vendor_user_id'],
            WithdrawalStatus::tryFrom((string) $row['status']) ?? WithdrawalStatus::Requested,
            (int) $row['amount_minor'],
            (int) $row['line_count'],
            (string) $row['iban'],
            (string) $row['account_holder'],
            (string) $row['reference'],
            (string) ($row['note'] ?? ''),
            $row['reviewed_by'] === null ? null : (int) $row['reviewed_by'],
            $row['reviewed_at'] === null ? null : (string) $row['reviewed_at'],
            $row['paid_at'] === null ? null : (string) $row['paid_at'],
            (string) $row['created_at'],
            (string) $row['updated_at']
        );
    }

    private function table(): string
    {
        return T::table($this->db, T::WITHDRAWALS);
    }

    private function lines(): string
    {
        return T::table($this->db, T::WITHDRAWAL_LINES);
    }

    private function orderItems(): string
    {
        return OrderTables::table($this->db, OrderTables::ORDER_ITEMS);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /**
     * The one hook a report cache listens to.
     *
     * Fired from the repository rather than from the service above it,
     * because Application may not call WordPress — and fired from the WRITE
     * rather than from the caller, which is the mistake `alpha.16` made with
     * the store page: forgetting before the save let a read land in between
     * and re-cache the stale answer under the new version.
     */
    private function figuresChanged(int $vendorUserId): void
    {
        if ($vendorUserId > 0 && function_exists('do_action')) {
            do_action('tmc_vendor_figures_changed', $vendorUserId);
        }
    }
}
