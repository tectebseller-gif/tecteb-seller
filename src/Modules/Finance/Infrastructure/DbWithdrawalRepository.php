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
 * the request, then inserts one row per line into a table whose unique key is
 * the line id. A line already held by another request makes that insert fail,
 * and the whole reservation is rolled back by hand — every row it wrote is
 * deleted and the caller is told it got nothing.
 *
 * Doing it this way, rather than "check then insert", is the point: two tabs,
 * a double click and two workers all reach the same index, and only one of
 * them wins (FIN-05). A check would let both pass and both write.
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

    public function reserve(
        int $vendorUserId,
        array $orderItemIds,
        int $amountMinor,
        string $iban,
        string $accountHolder
    ): int {
        if ($orderItemIds === []) {
            return 0;
        }
        $now = $this->now();
        $created = $this->db->execute(
            'INSERT INTO `' . $this->table() . '`
             (vendor_user_id, status, amount_minor, line_count, open_marker, iban, account_holder, created_at, updated_at)
             VALUES (%d, %s, %d, %d, 1, %s, %s, %s, %s)',
            [
                $vendorUserId,
                WithdrawalStatus::Requested->value,
                $amountMinor,
                count($orderItemIds),
                $iban,
                $accountHolder,
                $now,
                $now,
            ]
        );
        if ($created === null) {
            return 0;       // the open-request index refused a second one
        }
        $withdrawalId = (int) $this->db->getVar('SELECT LAST_INSERT_ID()');
        if ($withdrawalId <= 0) {
            return 0;
        }
        foreach ($orderItemIds as $orderItemId) {
            $claimed = $this->db->execute(
                'INSERT INTO `' . $this->lines() . '` (withdrawal_id, order_item_id, vendor_user_id, amount_minor, created_at)
                 VALUES (%d, %d, %d, 0, %s)',
                [$withdrawalId, (int) $orderItemId, $vendorUserId, $now]
            );
            if ($claimed !== null) {
                continue;
            }
            // Somebody else already holds this line. Undo everything this
            // call wrote — a half-reserved request would show a vendor an
            // amount that no set of lines backs.
            $this->deleteReservation($withdrawalId);
            return 0;
        }
        $this->db->execute(
            'UPDATE `' . $this->orderItems() . '` SET withdrawal_id = %d, updated_at = %s
             WHERE id IN (' . $this->placeholders($orderItemIds) . ')',
            array_merge([$withdrawalId, $now], array_map('intval', $orderItemIds))
        );
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
        string $reference = ''
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

        return $this->db->execute(
            'UPDATE `' . $this->table() . "` SET status = %s, open_marker = {$marker},
             reviewed_by = {$reviewer}, reviewed_at = %s, note = %s, reference = %s,
             paid_at = {$paidAt}, updated_at = %s WHERE id = %d",
            $params
        ) !== null;
    }

    public function release(int $withdrawalId): bool
    {
        $this->db->execute(
            'UPDATE `' . $this->orderItems() . '` SET withdrawal_id = NULL, updated_at = %s WHERE withdrawal_id = %d',
            [$this->now(), $withdrawalId]
        );
        return $this->db->execute(
            'DELETE FROM `' . $this->lines() . '` WHERE withdrawal_id = %d',
            [$withdrawalId]
        ) !== null;
    }

    private function deleteReservation(int $withdrawalId): void
    {
        $this->db->execute('DELETE FROM `' . $this->lines() . '` WHERE withdrawal_id = %d', [$withdrawalId]);
        $this->db->execute('DELETE FROM `' . $this->table() . '` WHERE id = %d', [$withdrawalId]);
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
}
