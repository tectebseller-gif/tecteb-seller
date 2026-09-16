<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Migration\Migrations\M0017DokanShopRecords as T;
use Tecteb\Marketplace\Modules\Migration\Application\ShopRecordRepositoryInterface;

/**
 * A migrated shop's staff, balance ledger and withdrawals, on our own schema
 * and in Dokan's own figures.
 *
 * `INSERT IGNORE` over a natural unique key, for the reason this codebase has
 * now met four times: a resumed job re-runs its last page, and «have we
 * imported this already?» asked in PHP is a question two batches can both
 * answer no. The index answers it once.
 *
 * Three outcomes rather than a boolean, for the reason the order history
 * learned in `alpha.15`: `execute()` returns null on failure and `INSERT
 * IGNORE` counts zero for a duplicate, and one boolean makes a broken write
 * indistinguishable from a row that was already there.
 *
 * Every amount is bound as a STRING into a `DECIMAL(19,4)` column. Nothing in
 * this file multiplies, divides, rounds or applies a rate.
 */
final class DbShopRecordRepository implements ShopRecordRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function recordStaff(string $runId, array $staff): string
    {
        return $this->outcome($this->db->execute(
            'INSERT IGNORE INTO `' . $this->t(T::STAFF) . '`
             (run_id, vendor_user_id, staff_user_id, display_name, user_email, dokan_role, source, imported_at)
             VALUES (%s, %d, %d, %s, %s, %s, %s, %s)',
            [
                $this->runId($runId),
                (int) $staff['vendor_user_id'],
                (int) $staff['staff_user_id'],
                mb_substr((string) $staff['display_name'], 0, 191),
                mb_substr((string) $staff['user_email'], 0, 191),
                mb_substr((string) $staff['dokan_role'], 0, 64),
                'dokan',
                $this->now(),
            ]
        ));
    }

    public function recordBalance(string $runId, array $row): string
    {
        return $this->outcome($this->db->execute(
            'INSERT IGNORE INTO `' . $this->t(T::BALANCE) . '`
             (run_id, vendor_user_id, trn_id, trn_type, particulars, debit, credit, status, trn_date, source, imported_at)
             VALUES (%s, %d, %d, %s, %s, %s, %s, %s, %s, %s, %s)',
            [
                $this->runId($runId),
                (int) $row['vendor_user_id'],
                (int) $row['trn_id'],
                mb_substr((string) $row['trn_type'], 0, 30),
                (string) $row['particulars'],
                // Bound as given. A cast to float here would be the exact
                // moment a shop's balance stops being Dokan's number.
                (string) $row['debit'],
                (string) $row['credit'],
                mb_substr((string) $row['status'], 0, 30),
                $this->dateOrNull((string) $row['trn_date']),
                'dokan',
                $this->now(),
            ]
        ));
    }

    public function recordWithdrawal(string $runId, array $row): string
    {
        return $this->outcome($this->db->execute(
            'INSERT IGNORE INTO `' . $this->t(T::WITHDRAW) . '`
             (run_id, vendor_user_id, dokan_withdraw_id, amount, status, method, note, requested_at, source, imported_at)
             VALUES (%s, %d, %d, %s, %s, %s, %s, %s, %s, %s)',
            [
                $this->runId($runId),
                (int) $row['vendor_user_id'],
                (int) $row['withdraw_id'],
                (string) $row['amount'],
                mb_substr((string) $row['status'], 0, 30),
                mb_substr((string) $row['method'], 0, 64),
                (string) $row['note'],
                $this->dateOrNull((string) $row['requested_at']),
                'dokan',
                $this->now(),
            ]
        ));
    }

    public function summaryForVendor(int $vendorUserId): array
    {
        $balance = $this->db->getRow(
            'SELECT COUNT(*) AS rows_seen,
                    CAST(COALESCE(SUM(debit), 0) AS CHAR) AS debit,
                    CAST(COALESCE(SUM(credit), 0) AS CHAR) AS credit
               FROM `' . $this->t(T::BALANCE) . '` WHERE vendor_user_id = %d',
            [$vendorUserId]
        );
        $withdraw = $this->db->getRow(
            'SELECT COUNT(*) AS rows_seen, CAST(COALESCE(SUM(amount), 0) AS CHAR) AS total
               FROM `' . $this->t(T::WITHDRAW) . '` WHERE vendor_user_id = %d',
            [$vendorUserId]
        );
        return [
            'staff' => (int) $this->db->getVar(
                'SELECT COUNT(*) FROM `' . $this->t(T::STAFF) . '` WHERE vendor_user_id = %d',
                [$vendorUserId]
            ),
            'balance_rows' => (int) ($balance['rows_seen'] ?? 0),
            'debit' => (string) ($balance['debit'] ?? '0'),
            'credit' => (string) ($balance['credit'] ?? '0'),
            'withdrawals' => (int) ($withdraw['rows_seen'] ?? 0),
            'withdrawn' => (string) ($withdraw['total'] ?? '0'),
        ];
    }

    public function deleteRun(string $runId): array
    {
        if ($runId === '') {
            // `run_id` defaults to '', so an empty id would take every row a
            // pre-run-id build ever wrote. Same guard as the order history.
            return ['staff' => 0, 'balance' => 0, 'withdrawals' => 0];
        }
        return [
            'staff' => $this->db->execute('DELETE FROM `' . $this->t(T::STAFF) . '` WHERE run_id = %s', [$runId]) ?? 0,
            'balance' => $this->db->execute('DELETE FROM `' . $this->t(T::BALANCE) . '` WHERE run_id = %s', [$runId]) ?? 0,
            'withdrawals' => $this->db->execute('DELETE FROM `' . $this->t(T::WITHDRAW) . '` WHERE run_id = %s', [$runId]) ?? 0,
        ];
    }

    public function countsForRun(string $runId): array
    {
        return [
            'staff' => (int) $this->db->getVar('SELECT COUNT(*) FROM `' . $this->t(T::STAFF) . '` WHERE run_id = %s', [$runId]),
            'balance' => (int) $this->db->getVar('SELECT COUNT(*) FROM `' . $this->t(T::BALANCE) . '` WHERE run_id = %s', [$runId]),
            'withdrawals' => (int) $this->db->getVar('SELECT COUNT(*) FROM `' . $this->t(T::WITHDRAW) . '` WHERE run_id = %s', [$runId]),
        ];
    }

    private function outcome(?int $written): string
    {
        return match (true) {
            $written === null => self::FAILED,
            $written > 0 => self::RECORDED,
            default => self::ALREADY,
        };
    }

    private function runId(string $runId): string
    {
        return mb_substr($runId, 0, 64);
    }

    /** Dokan's timestamps can be '0000-00-00 00:00:00', which MySQL refuses. */
    private function dateOrNull(string $value): ?string
    {
        return $value === '' || str_starts_with($value, '0000-') ? null : $value;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function t(string $suffix): string
    {
        return T::table($this->db, $suffix);
    }
}
