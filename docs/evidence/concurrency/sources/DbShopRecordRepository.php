<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Infrastructure;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Core\Migration\Migrations\M0017DokanShopRecords as T;
use Tecteb\Marketplace\Core\Migration\Migrations\M0018HandoverRowsAndRecordVersion as V;
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
    /** Result key => table suffix. One place, so a new table cannot be half-added. */
    private const TABLES = [
        'staff' => T::STAFF,
        'balance' => T::BALANCE,
        'withdrawals' => T::WITHDRAW,
    ];

    private const NOTHING_REMOVED = ['staff' => 0, 'balance' => 0, 'withdrawals' => 0];

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function recordStaff(string $runId, array $staff): string
    {
        return $this->writing((int) $staff['vendor_user_id'], fn (): ?int => $this->db->execute(
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
        return $this->writing((int) $row['vendor_user_id'], fn (): ?int => $this->db->execute(
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
        return $this->writing((int) $row['vendor_user_id'], fn (): ?int => $this->db->execute(
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

    public function staffForVendor(int $vendorUserId): array
    {
        $rows = $this->db->getResults(
            'SELECT staff_user_id, vendor_user_id, display_name, user_email, dokan_role
               FROM `' . $this->t(T::STAFF) . '` WHERE vendor_user_id = %d ORDER BY staff_user_id ASC',
            [$vendorUserId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'staff_user_id' => (int) $row['staff_user_id'],
                'vendor_user_id' => (int) $row['vendor_user_id'],
                'display_name' => (string) $row['display_name'],
                'user_email' => (string) $row['user_email'],
                'dokan_role' => (string) $row['dokan_role'],
            ];
        }
        return $out;
    }

    public function withdrawalsByStatus(int $vendorUserId): array
    {
        // `CAST(... AS CHAR)` for the same reason every other amount here is a
        // string: SUM() over DECIMAL comes back exact, and letting PHP see it
        // as a float is the first rounding nobody asked for.
        $rows = $this->db->getResults(
            'SELECT status, COUNT(*) AS rows_seen, CAST(COALESCE(SUM(amount), 0) AS CHAR) AS total
               FROM `' . $this->t(T::WITHDRAW) . '` WHERE vendor_user_id = %d
              GROUP BY status ORDER BY status ASC',
            [$vendorUserId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['status']] = [
                'count' => (int) $row['rows_seen'],
                'total' => (string) $row['total'],
            ];
        }
        return $out;
    }

    public function balanceByType(int $vendorUserId): array
    {
        $rows = $this->db->getResults(
            'SELECT trn_type, COUNT(*) AS rows_seen,
                    CAST(COALESCE(SUM(debit), 0) AS CHAR) AS debit,
                    CAST(COALESCE(SUM(credit), 0) AS CHAR) AS credit
               FROM `' . $this->t(T::BALANCE) . '` WHERE vendor_user_id = %d
              GROUP BY trn_type ORDER BY trn_type ASC',
            [$vendorUserId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['trn_type']] = [
                'count' => (int) $row['rows_seen'],
                'debit' => (string) $row['debit'],
                'credit' => (string) $row['credit'],
            ];
        }
        return $out;
    }

    public function closingBalance(int $vendorUserId): string
    {
        return (string) ($this->db->getVar(
            'SELECT CAST(COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS CHAR)
               FROM `' . $this->t(T::BALANCE) . '` WHERE vendor_user_id = %d',
            [$vendorUserId]
        ) ?? '0');
    }

    public function vendorsWithRecords(): array
    {
        $rows = $this->db->getResults(
            'SELECT vendor_user_id FROM `' . $this->t(T::STAFF) . '`
              UNION SELECT vendor_user_id FROM `' . $this->t(T::BALANCE) . '`
              UNION SELECT vendor_user_id FROM `' . $this->t(T::WITHDRAW) . '`
              ORDER BY vendor_user_id ASC',
            []
        );
        return array_map(static fn (array $r): int => (int) $r['vendor_user_id'], $rows);
    }

    public function deleteRun(string $runId): array
    {
        if ($runId === '') {
            // `run_id` defaults to '', so an empty id would take every row a
            // pre-run-id build ever wrote. Same guard as the order history.
            return self::NOTHING_REMOVED;
        }
        if (!$this->db->begin()) {
            return self::NOTHING_REMOVED;
        }

        // The shops whose versions must move are read INSIDE the transaction
        // and with `FOR UPDATE`, and both halves of that matter.
        //
        // **Inside**, because a list read before the transaction is a list of
        // who was in the run a moment ago: an import committing between the
        // list and the delete adds a shop whose rows this delete removes and
        // whose version nobody moves. That shop's figures then vanish under a
        // version still saying they are current, and a token issued before
        // the rollback keeps passing.
        //
        // **`FOR UPDATE`**, because moving the SELECT inside is not enough on
        // its own — that is the half which looks sufficient and is not. Under
        // REPEATABLE READ a plain `SELECT` reads this transaction's snapshot
        // while `DELETE` is a CURRENT read that sees the latest committed
        // rows, so the two disagree by construction rather than by bad luck.
        // Measured on MariaDB 10.11: the plain read saw one row, `FOR UPDATE`
        // saw two, and the `DELETE` removed two.
        //
        // The locks are the other half: a locking read over `run_id = X`
        // takes next-key locks across that range, so a concurrent import
        // cannot add a row to this run while the rollback is deciding whose
        // version to move. Reading the right set fixes the rows already
        // there; the range lock stops new ones arriving.
        $locked = [];
        $touched = [];
        foreach (self::TABLES as $key => $suffix) {
            $rows = $this->db->getResults(
                'SELECT vendor_user_id FROM `' . $this->t($suffix) . '` WHERE run_id = %s FOR UPDATE',
                [$runId]
            );
            $locked[$key] = count($rows);
            foreach ($rows as $row) {
                $touched[(int) $row['vendor_user_id']] = true;
            }
        }

        foreach (array_keys($touched) as $vendorUserId) {
            if (!$this->bump($vendorUserId)) {
                $this->db->rollback();
                return self::NOTHING_REMOVED;
            }
        }

        $out = [];
        foreach (self::TABLES as $key => $suffix) {
            $affected = $this->db->execute(
                'DELETE FROM `' . $this->t($suffix) . '` WHERE run_id = %s',
                [$runId]
            );
            if ($affected === null) {
                $this->db->rollback();
                return self::NOTHING_REMOVED;
            }
            $out[$key] = $affected;
        }

        // The invariant, checked rather than assumed: every row removed was a
        // row this call had locked and counted, so each belongs to a shop
        // whose version was just bumped. If the two numbers ever disagree,
        // something reached the run despite the locks, and the safe answer is
        // to remove nothing — a rollback that half-happened is worse than one
        // that did not, because only half of it is visible afterwards.
        foreach (self::TABLES as $key => $suffix) {
            if ($out[$key] !== $locked[$key]) {
                $this->db->rollback();
                return self::NOTHING_REMOVED;
            }
        }

        if (!$this->db->commit()) {
            return self::NOTHING_REMOVED;
        }
        return $out;
    }

    public function recordsVersion(int $vendorUserId): int
    {
        return (int) $this->db->getVar(
            'SELECT records_version FROM `' . $this->v() . '` WHERE vendor_user_id = %d',
            [$vendorUserId]
        );
    }

    public function lockRecordsVersion(int $vendorUserId): int
    {
        // The lock is taken FIRST, and the row is only created when there is
        // none. The obvious order — `INSERT IGNORE` to make sure a row exists,
        // then `SELECT ... FOR UPDATE` — is wrong twice over. An `INSERT
        // IGNORE` that hits an existing key takes a SHARED lock on it, so the
        // pair becomes «take S, then upgrade to X»: two managers deciding the
        // same shop would both hold S and both wait for the other's X, which
        // is a deadlock, not a queue. It also means the real exclusion came
        // from the `INSERT IGNORE` rather than from the `FOR UPDATE` that
        // claims to provide it — a guard that works by accident stops working
        // when somebody tidies the accident away.
        $version = $this->db->getVar(
            'SELECT records_version FROM `' . $this->v() . '` WHERE vendor_user_id = %d FOR UPDATE',
            [$vendorUserId]
        );
        if ($version !== null) {
            return (int) $version;
        }
        // No row yet: `FOR UPDATE` over nothing locks nothing. Create it and
        // take the lock properly. Two callers can race here; the unique key
        // decides, and the loser's `FOR UPDATE` then blocks on the winner's
        // row, which is the behaviour wanted.
        $this->db->execute(
            'INSERT IGNORE INTO `' . $this->v() . '` (vendor_user_id, records_version, updated_at)
             VALUES (%d, 0, %s)',
            [$vendorUserId, $this->now()]
        );
        return (int) $this->db->getVar(
            'SELECT records_version FROM `' . $this->v() . '` WHERE vendor_user_id = %d FOR UPDATE',
            [$vendorUserId]
        );
    }

    /**
     * One write to one shop's imported past, with its version bump inside the
     * same transaction.
     *
     * The order matters and is the whole point. If the row were committed and
     * the counter bumped afterwards, there would be a window in which the new
     * row is visible under the OLD version — and a manager reading in that
     * window would be handed figures that had already moved together with a
     * version saying they had not. Committing both together closes it: a
     * reader holding the version row's lock blocks the bump, and because the
     * bump and the insert are one transaction, it blocks the insert too.
     *
     * A duplicate does not bump. `INSERT IGNORE` writing nothing means the
     * shop's past is unchanged, and a version that moved for a no-op would
     * refuse decisions for no reason — the resumed-import case, which re-runs
     * its last page every time it picks up.
     *
     * @param callable():(?int) $write
     * @return self::RECORDED|self::ALREADY|self::FAILED
     */
    private function writing(int $vendorUserId, callable $write): string
    {
        if (!$this->db->begin()) {
            return self::FAILED;
        }
        $written = $write();
        if ($written === null) {
            $this->db->rollback();
            return self::FAILED;
        }
        if ($written > 0 && !$this->bump($vendorUserId)) {
            $this->db->rollback();
            return self::FAILED;
        }
        if (!$this->db->commit()) {
            return self::FAILED;
        }
        return $written > 0 ? self::RECORDED : self::ALREADY;
    }

    /** One statement: create the shop's counter at 1, or move the one there. */
    private function bump(int $vendorUserId): bool
    {
        return $this->db->execute(
            'INSERT INTO `' . $this->v() . '` (vendor_user_id, records_version, updated_at)
             VALUES (%d, 1, %s)
             ON DUPLICATE KEY UPDATE records_version = records_version + 1, updated_at = VALUES(updated_at)',
            [$vendorUserId, $this->now()]
        ) !== null;
    }

    private function v(): string
    {
        return V::table($this->db, V::VERSION_TABLE);
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
