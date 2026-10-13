<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Migration\Infrastructure;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Modules\Migration\Application\MinMaxHandoverRepositoryInterface;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0027MinMaxHandover;

/**
 * The Min Max handover ledger, in `tmc_minmax_handover`.
 *
 * `record()` is an `INSERT` with `IGNORE` and then a re-read, which is three
 * facts in one call:
 *
 *  - a product already decided about is LEFT ALONE, so a repeated batch writes
 *    nothing — and the unique key says so rather than a read-then-write, which
 *    two batches running at once would both pass (`alpha.39`);
 *  - zero affected rows is not «already the same»: it is «the key refused»,
 *    and the row is read back so the caller is told WHICH decision stands
 *    (`alpha.28`: ask the data, not the return value);
 *  - a failed insert is `null` from `execute()`, which is NOT zero — the
 *    `alpha.8` rule, and the one that cost a whole schema version once.
 */
final class DbMinMaxHandoverRepository implements MinMaxHandoverRepositoryInterface
{
    /** @var list<string> every column `record()` accepts, in one place */
    private const COLUMNS = [
        'post_id', 'post_type', 'level',
        'source_min', 'source_max', 'source_step', 'source_default',
        'dest_min_before', 'dest_max_before', 'dest_step_before', 'dest_record_before',
        'written_min', 'written_max', 'written_step',
        'status', 'problems', 'run_id',
    ];

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    private function table(): string
    {
        return M0027MinMaxHandover::table($this->db);
    }

    public function record(array $row): string
    {
        $postId = (int) ($row['post_id'] ?? 0);
        if ($postId <= 0) {
            return '';
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
            $placeholders[] = in_array($column, ['post_id', 'run_id'], true) ? '%d' : '%s';
            $params[] = in_array($column, ['post_id', 'run_id'], true) ? (int) $value : (string) $value;
        }
        $columns[] = '`decided_at`';
        $placeholders[] = 'UTC_TIMESTAMP()';

        $affected = $this->db->execute(
            'INSERT IGNORE INTO `' . $this->table() . '` (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $placeholders) . ')',
            $params
        );
        if ($affected === null) {
            return '';
        }
        $stored = $this->find($postId);
        if ($stored === null) {
            // Inserted nothing and there is nothing there: the write did not
            // land. Reported as «no decision» so a retry asks again.
            return '';
        }
        return $affected === 1 ? (string) ($stored['status'] ?? '') : '';
    }

    public function find(int $postId): ?array
    {
        if ($postId <= 0) {
            return null;
        }
        return $this->db->getRow(
            'SELECT * FROM `' . $this->table() . '` WHERE post_id = %d',
            [$postId]
        );
    }

    public function tally(): array
    {
        $rows = $this->db->getResults(
            'SELECT status, COUNT(*) AS total FROM `' . $this->table() . '` GROUP BY status'
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['total'];
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
     * Zero rows here is a LOST RACE, not «already rolled back» — the
     * `alpha.49` rule — so the caller is told false and says so, instead of
     * reporting a rollback that some other request performed.
     */
    public function markRolledBack(int $postId): bool
    {
        $affected = $this->db->execute(
            'UPDATE `' . $this->table() . '`
                SET status = %s, rolled_back_at = UTC_TIMESTAMP()
              WHERE post_id = %d AND status IN (%s, %s)',
            [
                self::ROLLED_BACK,
                $postId,
                self::MIGRATED,
                self::UNENFORCED,
            ]
        );
        return $affected !== null && $affected > 0;
    }
}
