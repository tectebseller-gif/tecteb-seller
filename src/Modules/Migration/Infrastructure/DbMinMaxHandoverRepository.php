<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Infrastructure;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Migration\Application\MinMaxHandoverRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0027MinMaxHandover;

/**
 * The Min Max handover ledger, in `tmc_minmax_handover`.
 *
 * `claim()` is an `INSERT IGNORE` whose only arbiter is the unique key on
 * `live_key`, and it runs BEFORE the destination is written. That ordering is
 * the whole difference from `alpha.53`, where the row went in afterwards and
 * so could only report a race that had already corrupted the record of what
 * the destination held: two runs both wrote the meta rows, and the surviving
 * row's `dest_*_before` described a destination the other run had replaced.
 *
 * Three facts live in the return value rather than in a boolean:
 *
 *  - zero affected rows is not «already the same»: it is «the key refused»,
 *    and the live row is read back so the caller is told WHICH decision stands
 *    (`alpha.28`: ask the data, not the return value);
 *  - a failed insert is `null` from `execute()`, which is NOT zero — the
 *    `alpha.8` rule, and the one that cost a whole schema version once;
 *  - the claim's own id comes back, because `finish()` must name the row it is
 *    completing and «the live row for this post» is a different question that
 *    a concurrent release could answer differently.
 */
final class DbMinMaxHandoverRepository implements MinMaxHandoverRepositoryInterface
{
    /** @var list<string> every column `claim()` accepts, in one place */
    private const COLUMNS = [
        'post_id', 'post_type', 'level',
        'source_min', 'source_max', 'source_step', 'source_default',
        'dest_min_before', 'dest_max_before', 'dest_step_before', 'dest_record_before',
        'run_id',
    ];

    /** @var list<string> columns written as integers rather than strings */
    private const INTEGERS = ['post_id', 'run_id'];

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    private function table(): string
    {
        return M0027MinMaxHandover::table($this->db);
    }

    public function claim(array $row): array
    {
        $postId = (int) ($row['post_id'] ?? 0);
        if ($postId <= 0) {
            return ['claimed' => false, 'id' => 0, 'taken_by' => ''];
        }
        $columns = [];
        $placeholders = [];
        $params = [];
        foreach (self::COLUMNS as $column) {
            if (!array_key_exists($column, $row)) {
                continue;
            }
            $value = $row[$column];
            $columns[] = '`' . $column . '`';
            if ($value === null) {
                // Written as a literal NULL rather than through a placeholder:
                // «the row was not there» has to reach the column as SQL NULL,
                // and a `%s` placeholder would make it the string ''. That is
                // the one distinction this whole table exists to keep.
                $placeholders[] = 'NULL';
                continue;
            }
            $isInt = in_array($column, self::INTEGERS, true);
            $placeholders[] = $isInt ? '%d' : '%s';
            $params[] = $isInt ? (int) $value : (string) $value;
        }
        // The claim itself: the live key is the post id, and the unique key on
        // it is what a second run collides with.
        $columns[] = '`live_key`';
        $placeholders[] = '%d';
        $params[] = $postId;
        $columns[] = '`status`';
        $placeholders[] = '%s';
        $params[] = self::CLAIMING;
        $columns[] = '`decided_at`';
        $placeholders[] = 'UTC_TIMESTAMP()';

        $affected = $this->db->execute(
            'INSERT IGNORE INTO `' . $this->table() . '` (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $placeholders) . ')',
            $params
        );
        if ($affected === null || $affected < 1) {
            $live = $this->find($postId);
            return [
                'claimed' => false,
                'id' => 0,
                'taken_by' => $live === null ? '' : (string) ($live['status'] ?? ''),
            ];
        }
        $id = (int) ($this->db->getVar(
            'SELECT id FROM `' . $this->table() . '` WHERE live_key = %d AND status = %s',
            [$postId, self::CLAIMING]
        ) ?? 0);
        if ($id <= 0) {
            // Inserted a row and cannot find it. The write did not land the
            // way it said it did, so the caller must treat this as no claim
            // at all rather than finish a row it cannot name.
            return ['claimed' => false, 'id' => 0, 'taken_by' => ''];
        }
        return ['claimed' => true, 'id' => $id, 'taken_by' => ''];
    }

    public function finish(int $claimId, string $status, string $problems, array $written): bool
    {
        if ($claimId <= 0) {
            return false;
        }
        $sets = ['`status` = %s', '`problems` = %s'];
        $params = [$status, $problems];
        foreach (['written_min', 'written_max', 'written_step'] as $column) {
            $value = $written[$column] ?? null;
            if ($value === null) {
                $sets[] = '`' . $column . '` = NULL';
                continue;
            }
            $sets[] = '`' . $column . '` = %s';
            $params[] = (string) $value;
        }
        $params[] = $claimId;
        $params[] = self::CLAIMING;
        // The claim's state is in the `WHERE`, so zero rows is a lost race and
        // the caller is told so — never «already finished».
        $affected = $this->db->execute(
            'UPDATE `' . $this->table() . '` SET ' . implode(', ', $sets) . '
              WHERE id = %d AND status = %s',
            $params
        );
        return $affected !== null && $affected > 0;
    }

    public function find(int $postId): ?array
    {
        if ($postId <= 0) {
            return null;
        }
        // The LIVE row, and a `claiming` row is not a decision: an uncommitted
        // claim is invisible outside its transaction anyway, and inside the
        // caller's own transaction reading it back as a decision would make a
        // retry impossible.
        return $this->db->getRow(
            'SELECT * FROM `' . $this->table() . '` WHERE live_key = %d AND status <> %s',
            [$postId, self::CLAIMING]
        );
    }

    public function history(int $postId, int $limit = 20): array
    {
        if ($postId <= 0) {
            return [];
        }
        return $this->db->getResults(
            'SELECT * FROM `' . $this->table() . '`
              WHERE post_id = %d AND status <> %s
              ORDER BY id DESC LIMIT %d',
            [$postId, self::CLAIMING, max(1, min(100, $limit))]
        );
    }

    public function tally(): array
    {
        // `(live_key IS NULL) AS released` is SELECTED and GROUPED BY the same
        // expression, rather than selecting the raw column and grouping by the
        // expression: under `ONLY_FULL_GROUP_BY` — MySQL 8's default, though
        // not MariaDB's — the second shape is an error, and `getResults()`
        // would answer `[]`, so the exit page would show an empty tally on a
        // site whose `sql_mode` happens to be the strict one.
        $rows = $this->db->getResults(
            'SELECT status, (live_key IS NULL) AS released, COUNT(*) AS total
               FROM `' . $this->table() . '`
              WHERE status <> %s
              GROUP BY status, released',
            [self::CLAIMING]
        );
        $out = [];
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            // A released `refused`/`held` row is counted separately: it is
            // history, and showing it among the live refusals would make the
            // exit report say a rule is still blocking when the manager has
            // already sent it back for a retry.
            $key = (int) $row['released'] === 1 && $status !== self::ROLLED_BACK
                ? $status . '_released'
                : $status;
            $out[$key] = ($out[$key] ?? 0) + (int) $row['total'];
        }
        return $out;
    }

    public function withStatus(array $statuses, int $limit = 200, int $afterId = 0): array
    {
        if ($statuses === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
        return $this->db->getResults(
            'SELECT * FROM `' . $this->table() . '`
              WHERE status IN (' . $placeholders . ') AND id > %d
              ORDER BY id ASC LIMIT %d',
            array_merge(
                array_map(static fn (string $s): string => $s, $statuses),
                [max(0, $afterId), max(1, min(1000, $limit))]
            )
        );
    }

    /**
     * Only from a state that actually wrote something, and the state is in the
     * `WHERE` rather than read first.
     *
     * The live key is cleared in the SAME statement, which is what lets the
     * product be migrated again while its row stays as history. Zero rows is a
     * LOST RACE, not «already rolled back» — the `alpha.49` rule — so the
     * caller is told false and says so, instead of reporting a rollback that
     * some other request performed.
     */
    public function markRolledBack(int $postId): bool
    {
        $affected = $this->db->execute(
            'UPDATE `' . $this->table() . '`
                SET status = %s, live_key = NULL, rolled_back_at = UTC_TIMESTAMP()
              WHERE live_key = %d AND status IN (%s, %s, %s)',
            [
                self::ROLLED_BACK,
                $postId,
                self::MIGRATED,
                self::UNENFORCED,
                // A granted product is rollable back too, and it has to be:
                // the grant was given about a rule this migration wrote, so
                // putting the rule back means the grant goes with it.
                self::ENFORCED,
            ]
        );
        return $affected !== null && $affected > 0;
    }

    public function markEnforced(int $postId): bool
    {
        $affected = $this->db->execute(
            'UPDATE `' . $this->table() . '` SET status = %s
              WHERE live_key = %d AND status = %s',
            [self::ENFORCED, $postId, self::UNENFORCED]
        );
        return $affected !== null && $affected > 0;
    }

    public function markUnenforced(int $postId): bool
    {
        $affected = $this->db->execute(
            'UPDATE `' . $this->table() . '` SET status = %s
              WHERE live_key = %d AND status = %s',
            [self::UNENFORCED, $postId, self::ENFORCED]
        );
        return $affected !== null && $affected > 0;
    }

    public function release(int $postId): bool
    {
        // Only from a state that wrote NOTHING. Releasing a `migrated` row
        // would leave three meta rows applied with no live record of where
        // they came from, which is the one thing the rollback target exists to
        // prevent — so that transition is a rollback, not a release.
        $affected = $this->db->execute(
            'UPDATE `' . $this->table() . '`
                SET live_key = NULL
              WHERE live_key = %d AND status IN (%s, %s)',
            [$postId, self::REFUSED, self::HELD]
        );
        return $affected !== null && $affected > 0;
    }
}
