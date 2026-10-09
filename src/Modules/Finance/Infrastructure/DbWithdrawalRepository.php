<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Finance\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Finance\Application\WithdrawalRepositoryInterface;
use Tecteb\Marketplace\Modules\Finance\Domain\Withdrawal;
use Tecteb\Marketplace\Modules\Finance\Domain\WithdrawalStatus;
use Tecteb\Marketplace\Modules\Finance\Infrastructure\Migrations\M0007SettlementTables as T;
use Tecteb\Marketplace\Modules\Order\Domain\OrderItemStatus;
use Tecteb\Marketplace\Modules\Order\Domain\ReturnStatus;
use Tecteb\Marketplace\Modules\Order\Infrastructure\Migrations\M0008ShipmentsAndReturns as ReturnTables;
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
        string $accountHolder,
        string $eligibleUntil = '9999-12-31 23:59:59'
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

        // THE CLAIM IS THE GUARD, AND IT CARRIES EVERY CONDITION — which
        // `alpha.39` did not.
        //
        // `alpha.39` put four of `VendorBalance`'s six conditions in this
        // `WHERE` and leaned on the amount comparison below for the rest. That
        // comparison proves the SUM of the claimed shares, which is not the
        // same claim at all: a line that was cancelled, or whose waiting
        // period had not elapsed, or that had picked up a blocking return
        // since the balance was read, has exactly the same `vendor_share_minor`
        // as it did a moment earlier. The sum matches and the reservation is
        // invalid.
        //
        // So the rule is restated here in full, derived from the same code
        // `VendorBalance` reads rather than written again:
        //
        //  - a share was recorded (FIN-02: unset is not zero);
        //  - nobody else holds the line — which also covers «already paid»,
        //    because a paid request keeps its lines for ever;
        //  - a manager called the sale complete (ORDER-01);
        //  - the approved waiting period has elapsed since that moment;
        //  - the line is not CANCELLED. `settlementView()` filters it out with
        //    `status <> 'cancelled'`, so the balance never saw such a line and
        //    this must not claim one;
        //  - and no return is still counted against it (UX §10.2). The status
        //    list comes from `ReturnStatus::reservesQuantity()`, the same
        //    predicate `DbShipmentRepository::returnedQuantity()` asks — one
        //    rule read twice, not two lists.
        //
        // All of it inside the transaction and inside the write: «یک SELECT
        // دیگر خارج از تراکنش کافی نیست», and a read is not a lock.
        $claimed = $this->db->execute(
            'UPDATE `' . $this->orderItems() . '` SET withdrawal_id = %d, updated_at = %s
             WHERE id IN (' . $this->placeholders($ids) . ')
               AND vendor_user_id = %d
               AND withdrawal_id IS NULL
               AND vendor_share_minor IS NOT NULL
               AND settlement_completed_at IS NOT NULL
               AND settlement_completed_at <= %s
               AND status <> %s
               AND NOT EXISTS (
                     SELECT 1 FROM `' . $this->returns() . '` tmc_r
                      WHERE tmc_r.order_item_id = `' . $this->orderItems() . '`.id
                        AND tmc_r.status IN (' . self::reservingReturnStatuses() . ')
                   )',
            array_merge(
                [$withdrawalId, $now],
                $ids,
                [$vendorUserId, $eligibleUntil, OrderItemStatus::Cancelled->value]
            )
        );
        if ($claimed === null || $claimed !== count($ids)) {
            // Either the statement failed, or somebody else holds a line, or a
            // line stopped being eligible between the balance and here.
            $this->db->rollback();
            return 0;
        }

        // The figure, recomputed from what was actually claimed and read
        // inside the transaction. Still here, and still necessary — it catches
        // a share that CHANGED rather than a line that stopped qualifying —
        // but it is no longer carrying the conditions above on its own.
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
        // Nested, the caller fires the hook after its own commit — the same
        // rule as `release()`, and for the same reason.
        if (!$this->db->inTransaction()) {
            $this->figuresChanged((int) ($this->find($withdrawalId)?->vendorUserId ?? 0));
        }
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
        // WHO FIRES THE CACHE HOOK depends on who owns the transaction.
        //
        // `alpha.39` let this method invalidate the report cache after its own
        // `commit()`. Nested inside a caller's transaction that commit is a
        // no-op — the gateway counts depth rather than saving savepoints — so
        // the hook fired while the outer unit of work could still roll back,
        // advertising a release that never happened. Nested, the caller fires
        // it after ITS commit; standalone, this still does.
        $nested = $this->db->inTransaction();
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
        if (!$nested && $vendorUserId > 0) {
            $this->figuresChanged($vendorUserId);
        }
        return true;
    }

    /**
     * Closed requests that still hold money — the detection half of §2's
     * «درخواست‌های بسته با رزرو باقی‌ماندهٔ قدیمی قابل‌شناسایی باشند».
     *
     * `alpha.39` closed the status and released the lines as two separate
     * writes, so a failed release left a request that is finished on paper
     * with order items still pointing at it: invisible to the vendor's
     * eligible balance, and invisible to any later release, because the next
     * one looks for `withdrawal_id = <id>` and nothing else ever does.
     * `alpha.40` cannot produce that state any more and cannot un-produce the
     * rows already on disk either.
     *
     * A PAID request is deliberately NOT stranded: it keeps its lines for
     * ever, because they are the record of what that payment covered. So the
     * query asks for requests that are closed, not paid, and still hold
     * something — and reports both halves separately, because the two tables
     * can disagree (`alpha.39`'s `DELETE` succeeded while its `UPDATE`
     * failed, which took the record of WHICH request holds the money and left
     * the money held).
     *
     * @return list<array{withdrawal_id:int, vendor_user_id:int, status:string, amount_minor:int, claimed_items:int, reserve_lines:int}>
     */
    public function strandedReservations(int $limit = 200): array
    {
        $rows = $this->db->getResults(
            'SELECT w.id, w.vendor_user_id, w.status, w.amount_minor,
                    (SELECT COUNT(*) FROM `' . $this->orderItems() . '` i WHERE i.withdrawal_id = w.id) AS claimed_items,
                    (SELECT COUNT(*) FROM `' . $this->lines() . '` l WHERE l.withdrawal_id = w.id) AS reserve_lines
             FROM `' . $this->table() . '` w
             WHERE w.open_marker IS NULL AND w.status <> %s
               AND ((SELECT COUNT(*) FROM `' . $this->orderItems() . '` i2 WHERE i2.withdrawal_id = w.id) > 0
                 OR (SELECT COUNT(*) FROM `' . $this->lines() . '` l2 WHERE l2.withdrawal_id = w.id) > 0)
             ORDER BY w.id ASC LIMIT %d',
            [WithdrawalStatus::Paid->value, max(1, min(1000, $limit))]
        );
        return array_map(
            static fn (array $row): array => [
                'withdrawal_id' => (int) $row['id'],
                'vendor_user_id' => (int) $row['vendor_user_id'],
                'status' => (string) $row['status'],
                'amount_minor' => (int) $row['amount_minor'],
                'claimed_items' => (int) $row['claimed_items'],
                'reserve_lines' => (int) $row['reserve_lines'],
            ],
            $rows
        );
    }

    /**
     * The return statuses that still hold a line's money, as an SQL list.
     *
     * Derived from `ReturnStatus::reservesQuantity()` — the same predicate
     * `DbShipmentRepository::returnedQuantity()` asks, which is what
     * `VendorBalance` reads. Two hand-written lists would be two rules, and
     * the one that drifted would be this one, because nothing here renders a
     * return.
     */
    private static function reservingReturnStatuses(): string
    {
        $quoted = [];
        foreach (ReturnStatus::all() as $status) {
            if ($status->reservesQuantity()) {
                $quoted[] = "'" . $status->value . "'";
            }
        }
        return implode(', ', $quoted);
    }

    private function returns(): string
    {
        return ReturnTables::table($this->db, ReturnTables::RETURNS);
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
     *
     * PUBLIC since `alpha.40`, for the one case the write cannot answer for
     * itself: a service that wraps several writes in one transaction. There
     * the inner commits are no-ops, so a write that invalidated the cache
     * from inside would advertise an outcome the outer unit of work can still
     * roll back. Those writes stay silent while nested and the service calls
     * this once, after its own commit. Still not WordPress in Application:
     * the hook is fired here, which is where it has always been.
     */
    public function figuresChanged(int $vendorUserId): void
    {
        if ($vendorUserId > 0 && function_exists('do_action')) {
            do_action('tmc_vendor_figures_changed', $vendorUserId);
        }
    }
}
