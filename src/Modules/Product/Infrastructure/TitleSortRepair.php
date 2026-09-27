<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Infrastructure;

use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Modules\Product\Domain\PersianCollation;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0005CreateProductTables as T;
use Tecteb\Marketplace\Modules\Product\Infrastructure\Migrations\M0020ProductTitleSort;

/**
 * Keeps `title_sort` telling the truth about `title`, after anything that
 * could have made it lie.
 *
 * **The hole this closes, exactly.** `alpha.33` added the column, an index and
 * a batched backfill in migration 20. Three facts together make that not
 * enough:
 *
 *  1. an older package — `alpha.32`, the one a rollback puts back — does not
 *     know the column exists. It writes `title` and leaves `title_sort` at
 *     whatever it was, so an edited title keeps the KEY OF ITS OLD NAME, and a
 *     product created there gets the column default, the empty string;
 *  2. coming forward again, `tmc_schema_version` still reads 20, so the upgrade
 *     gate sees nothing to do and migration 20 never runs a second time;
 *  3. and even if it did, its backfill is `WHERE title_sort = ''` — by design,
 *     so that an interrupted run resumes. It fills the created product's empty
 *     key and walks straight past the edited one.
 *
 * So a rollback and a re-upgrade left a list ordered by names some products no
 * longer have. Nothing was broken enough to notice: the page opened, the rows
 * were all there, and a handful of them were in the wrong place.
 *
 * **Why this is not the migration doing more.** A migration runs once per
 * version number. The damage here is not caused by a version being behind —
 * the version is 20 throughout — it is caused by a WRITE from a build that did
 * not maintain the column. That can happen at any time and more than once, so
 * the answer has to be something that keeps asking.
 *
 * **What it costs, which is the part that had to be designed.** Recomputing
 * every key on every request is the shape `alpha.32`'s paging exists to avoid:
 * it reads the whole catalogue to render twenty rows. So the work is bounded
 * per request and resumable across requests, and it never touches the queries
 * the list itself runs — the sort stays a single indexed `ORDER BY`.
 *
 * Three reasons open a sweep, and each is a question that can be asked
 * cheaply:
 *
 *  - **the key format changed.** `PersianCollation::BUILD` is stored beside the
 *    cursor; a mismatch means every stored key is stale by definition, which is
 *    the case `alpha.34` itself creates by fixing the number block. One option
 *    read.
 *  - **some row has no key at all.** One `LIMIT 1` on the `title_sort` index —
 *    an empty key sorts first, so this is the cheapest lookup the table has.
 *    A row without a key is evidence that a build which did not know the column
 *    wrote here, and that build will not have maintained the rows it EDITED
 *    either, so the sweep covers the whole table rather than that one row.
 *  - **the rotating audit found a key that disagrees with its title.** Once no
 *    sweep is open, each request verifies AUDIT rows starting where the last
 *    one stopped, and wraps. This is the net under the case with no other
 *    symptom — an older build that edited titles and created nothing — and it
 *    needs nobody to notice anything. A catalogue of N products is fully
 *    verified every ceil(N / AUDIT) wp-admin requests.
 *
 * A sweep writes only where the recomputed key DIFFERS from the stored one, so
 * a clean table costs reads and no writes, and a second pass over rows already
 * repaired is free.
 */
final class TitleSortRepair
{
    /** Cursor, build and counts, in one option so one read answers everything. */
    public const OPTION = 'tmc_title_sort_repair';

    /** Rows rebuilt per request while a sweep is open. */
    public const SWEEP = 200;

    /** Rows verified per request once no sweep is open. */
    public const AUDIT = 50;

    public const REASON_BUILD = 'build_changed';
    public const REASON_MISSING = 'missing_key';
    public const REASON_AUDIT = 'audit_mismatch';

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly OptionStoreInterface $options
    ) {
    }

    /**
     * One request's worth of work.
     *
     * @return array{
     *     state: string, reason: string, cursor: int, audited: int,
     *     checked: int, repaired: int, swept: int, swept_repaired: int,
     *     blocked_at: ?int, blocked_by: string, remaining: int
     * } `state` is one of `no_column`, `sweeping`, `finished`, `clean`,
     *   `blocked`. **`blocked` is the one that is not a success**: a row could
     *   not be settled, the sweep stays open, and the build is not stamped.
     */
    public function run(): array
    {
        $state = $this->state();

        // The column is asked about ONCE, on the first request that ever gets
        // here, and never again: a recorded build is proof the column was there
        // when the sweep that recorded it finished, and an `information_schema`
        // lookup on every wp-admin request would be a real cost for an answer
        // that cannot change back on its own.
        if ($state['build'] === 0 && !$this->columnExists()) {
            // Migration 20 has not run yet on this site. The gate will run it,
            // and its own backfill is the right tool for a column that has just
            // appeared; there is nothing for a repair to repair.
            return $this->result('no_column', $state, 0, 0, 0);
        }

        if ($state['reason'] === '') {
            $reason = $this->reasonToSweep($state);
            if ($reason === null) {
                return $this->audit($state);
            }
            $state['reason'] = $reason;
            $state['cursor'] = 0;
            $state['checked'] = 0;
            $state['repaired'] = 0;
        }

        return $this->sweep($state);
    }

    /** What the health page reports, without doing any work. */
    public function status(): array
    {
        $state = $this->state();
        return [
            'build' => (int) $state['build'],
            'expected_build' => PersianCollation::BUILD,
            'open' => $state['reason'] !== '',
            'reason' => (string) $state['reason'],
            'cursor' => (int) $state['cursor'],
            'audit_cursor' => (int) $state['audit'],
            'repaired' => (int) $state['repaired'],
            'last_repaired' => (int) $state['last_repaired'],
        ];
    }

    /**
     * The batch result, for a caller that wants the reason rather than the
     * state word — `tools/` evidence and the tests read this.
     *
     * Kept separate from `run()`'s array because `run()` is the thing a hook
     * calls and throws away; this is the thing a person asks.
     */
    public static function isBlocked(array $result): bool
    {
        return ($result['state'] ?? '') === 'blocked';
    }

    /**
     * Opens a sweep from outside — the documented way to stop waiting for the
     * audit to come round, used by the rollback guide and by the evidence.
     */
    public function requestSweep(string $reason = self::REASON_MISSING): void
    {
        $state = $this->state();
        $state['reason'] = $reason;
        $state['cursor'] = 0;
        $state['checked'] = 0;
        $state['repaired'] = 0;
        $this->store($state);
    }

    /** Null when nothing says the whole table needs rebuilding. */
    private function reasonToSweep(array $state): ?string
    {
        if ((int) $state['build'] !== PersianCollation::BUILD) {
            return self::REASON_BUILD;
        }
        $missing = $this->db->getVar(
            'SELECT id FROM `' . $this->products() . '` WHERE `' . M0020ProductTitleSort::COLUMN . "` = '' LIMIT 1"
        );
        return $missing === null ? null : self::REASON_MISSING;
    }

    /** One batch of an open sweep. Closes it when the batch comes back short. */
    private function sweep(array $state): array
    {
        $rows = $this->db->getResults(
            'SELECT id, title, `' . M0020ProductTitleSort::COLUMN . '` AS stored
             FROM `' . $this->products() . '` WHERE id > %d ORDER BY id ASC LIMIT %d',
            [(int) $state['cursor'], self::SWEEP]
        );

        $batch = $this->rewrite($rows);
        $state['checked'] = (int) $state['checked'] + $batch->examined;
        $state['repaired'] = (int) $state['repaired'] + $batch->repaired;
        // Only ever to the last row that came out RIGHT. The cursor is a
        // promise that everything behind it is settled, and moving it to the
        // end of the window on a pass that stopped half way turns that promise
        // into a lie no later pass can detect.
        if ($batch->cursor > 0) {
            $state['cursor'] = $batch->cursor;
        }

        if (!$batch->complete()) {
            // A short window that did not finish is NOT the end of the table.
            // This is the whole defect: the build must not be stamped, the
            // reason must stay, and the cursor must still point at the row
            // before the one that stopped us.
            $this->store($state);
            return $this->result('blocked', $state, 0, $batch->examined, $batch->repaired, null, $batch);
        }

        if (count($rows) < self::SWEEP) {
            // The end of the table, reached with every row settled. The build
            // is recorded only HERE, because a build recorded at the start
            // would make an interrupted sweep look like a finished one and
            // leave the rest of the table on the old format for good.
            $total = ['checked' => (int) $state['checked'], 'repaired' => (int) $state['repaired']];
            $state['build'] = PersianCollation::BUILD;
            $state['reason'] = '';
            $state['cursor'] = 0;
            $state['last_repaired'] = $total['repaired'];
            $state['checked'] = 0;
            $state['repaired'] = 0;
            $this->store($state);
            return $this->result('finished', $state, 0, $batch->examined, $batch->repaired, $total);
        }

        $this->store($state);
        return $this->result('sweeping', $state, 0, $batch->examined, $batch->repaired);
    }

    /**
     * The rotating verification, for the staleness with no other symptom.
     *
     * A mismatch is repaired on the spot AND opens a sweep: one wrong key means
     * some build wrote titles here without maintaining them, and the rows it
     * touched are not confined to this window.
     */
    private function audit(array $state): array
    {
        $from = (int) $state['audit'];
        $rows = $this->db->getResults(
            'SELECT id, title, `' . M0020ProductTitleSort::COLUMN . '` AS stored
             FROM `' . $this->products() . '` WHERE id > %d ORDER BY id ASC LIMIT %d',
            [$from, self::AUDIT]
        );
        if ($rows === []) {
            // Past the last row — or an empty table. Wrapping here rather than
            // on the next request means a short table is still verified every
            // request instead of every other one.
            $state['audit'] = 0;
            $rows = $this->db->getResults(
                'SELECT id, title, `' . M0020ProductTitleSort::COLUMN . '` AS stored
                 FROM `' . $this->products() . '` ORDER BY id ASC LIMIT %d',
                [self::AUDIT]
            );
        }

        $batch = $this->rewrite($rows);
        // Same rule as the sweep: the audit cursor may only pass rows that came
        // out right, or the one row it could not settle is the one row it never
        // looks at again.
        if ($batch->cursor > 0) {
            $state['audit'] = $batch->cursor;
        }
        // A repair found here means some build wrote titles without maintaining
        // the column, and the rows it touched are not confined to this window —
        // so a sweep opens. A window that could not be FINISHED opens one too:
        // «we did not get to the end of this» is not «the table is clean», and
        // leaving it closed would drop the pending repair on the floor.
        if ($batch->repaired > 0 || !$batch->complete()) {
            $state['reason'] = self::REASON_AUDIT;
            $state['cursor'] = 0;
            $state['checked'] = 0;
            $state['repaired'] = 0;
        }
        $this->store($state);

        $outcome = match (true) {
            !$batch->complete() => 'blocked',
            $batch->repaired > 0 => 'sweeping',
            default => 'clean',
        };
        return $this->result(
            $outcome,
            $state,
            $batch->examined,
            $batch->examined,
            $batch->repaired,
            null,
            $batch
        );
    }

    /**
     * Writes the rows whose key disagrees with their title, and only those —
     * and stops at the first row it cannot settle.
     *
     * **The write is guarded on the snapshot it was planned from.** The title
     * and the key are read in one statement and written back in another, and in
     * between the ordinary save path — which maintains the key itself — may
     * have stored a new title and its correct key. Keyed on `id` alone, this
     * `UPDATE` would then put the key of a name the product no longer has on
     * top of the right one, and the repair would have CAUSED the staleness it
     * exists to remove. So the row must still be the row that was read.
     *
     * **The comparison is bytes, not text.** `title` is
     * `utf8mb4_unicode_520_ci`, which holds that «Café» and «Cafe» are the same
     * string — and those two produce different keys. A plain `=` would accept
     * the stale snapshot and write the wrong key with a clear conscience, so
     * both sides are cast to `BINARY`. `title_sort` is already `utf8mb4_bin`
     * and is cast anyway, because a guard that is only correct while somebody
     * remembers which column has which collation is a guard waiting to break.
     *
     * **Zero rows here means the guard did not match, not «nothing to do».**
     * `alpha.8`'s rule still holds — `execute()` answers `null` for a failure
     * and a row count for a success — and it is exactly because zero is a
     * success that it has to be read carefully: this statement only runs when
     * the new key DIFFERS from the stored one, so a matching row is always a
     * changed row. wpdb does not set `CLIENT_FOUND_ROWS`, so zero changed rows
     * can only mean the `WHERE` found nothing: the row moved.
     *
     * A moved row is re-read ONCE and re-judged — somebody else may have
     * already put it right, in which case it is settled and costs nobody a
     * retry. If it is still wrong, the pass stops there and the next request
     * starts from the title the row has by then. One extra read per conflict,
     * and no loop: a row that keeps moving is retried on later requests, never
     * spun on inside this one.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function rewrite(array $rows): TitleSortRepairBatch
    {
        $repaired = 0;
        $examined = 0;
        $cursor = 0;

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $title = (string) ($row['title'] ?? '');
            $stored = (string) ($row['stored'] ?? '');
            $key = PersianCollation::sortKey($title);

            if ($key === $stored) {
                $examined++;
                $cursor = $id;
                continue;
            }

            $written = $this->db->execute(
                'UPDATE `' . $this->products() . '` SET `' . M0020ProductTitleSort::COLUMN . '` = %s
                 WHERE id = %d
                   AND CAST(`title` AS BINARY) = CAST(%s AS BINARY)
                   AND CAST(`' . M0020ProductTitleSort::COLUMN . '` AS BINARY) = CAST(%s AS BINARY)',
                [$key, $id, $title, $stored]
            );

            if ($written === null) {
                return new TitleSortRepairBatch(
                    $repaired,
                    $examined,
                    $cursor,
                    $id,
                    TitleSortRepairBatch::WRITE_FAILED
                );
            }
            if ($written === 0) {
                if ($this->alreadyCorrect($id)) {
                    $examined++;
                    $cursor = $id;
                    continue;
                }
                return new TitleSortRepairBatch(
                    $repaired,
                    $examined,
                    $cursor,
                    $id,
                    TitleSortRepairBatch::ROW_MOVED
                );
            }

            $repaired++;
            $examined++;
            $cursor = $id;
        }

        return new TitleSortRepairBatch($repaired, $examined, $cursor);
    }

    /**
     * Re-read one row and ask whether its key matches its title NOW.
     *
     * Only ever called about a row whose guarded write found nothing, so this
     * costs one read per conflict rather than one per row. A row that somebody
     * else has already put right is settled — reporting it as unresolved would
     * stop a sweep for work that is done.
     */
    private function alreadyCorrect(int $id): bool
    {
        $row = $this->db->getRow(
            'SELECT title, `' . M0020ProductTitleSort::COLUMN . '` AS stored
             FROM `' . $this->products() . '` WHERE id = %d',
            [$id]
        );
        if ($row === null) {
            return true;    // deleted between the read and the write: nothing left to repair
        }
        return PersianCollation::sortKey((string) ($row['title'] ?? '')) === (string) ($row['stored'] ?? '');
    }

    /**
     * @return array{
     *     build:int, reason:string, cursor:int, audit:int,
     *     checked:int, repaired:int, last_repaired:int
     * }
     */
    private function state(): array
    {
        $raw = $this->options->get(self::OPTION, []);
        $raw = is_array($raw) ? $raw : [];
        return [
            // Build 0 is «nothing has ever recorded one», which is what a site
            // upgrading from `alpha.33` looks like — and it must not read as
            // the current build, or the keys that round wrote would stand.
            'build' => (int) ($raw['build'] ?? 0),
            'reason' => (string) ($raw['reason'] ?? ''),
            'cursor' => max(0, (int) ($raw['cursor'] ?? 0)),
            'audit' => max(0, (int) ($raw['audit'] ?? 0)),
            'checked' => max(0, (int) ($raw['checked'] ?? 0)),
            'repaired' => max(0, (int) ($raw['repaired'] ?? 0)),
            'last_repaired' => max(0, (int) ($raw['last_repaired'] ?? 0)),
        ];
    }

    private function store(array $state): void
    {
        $this->options->set(self::OPTION, $state);
    }

    /**
     * `checked` and `repaired` are always THIS run's numbers, and
     * `swept`/`swept_repaired` the whole sweep's.
     *
     * The first version reported the cumulative figures on the request that
     * finished a sweep and the per-request ones on every other, so a caller
     * comparing two runs was comparing two different quantities — a second run
     * that repaired 25 rows said 225. Two names, because both are worth
     * knowing: one is the cost of this request, the other the size of the
     * damage.
     *
     * `blocked_at` and `blocked_by` are the part `alpha.34` could not say: the
     * row a pass stopped at, and why. A caller that only reads `repaired` still
     * reads it correctly — the count has always been «rows written» and still
     * is — but it can no longer mistake a stopped pass for a finished one,
     * because `state` says `blocked` and the id is right there.
     *
     * @param ?array{checked:int,repaired:int} $total
     * @return array<string,mixed>
     */
    private function result(
        string $state,
        array $s,
        int $audited,
        int $checked,
        int $repaired,
        ?array $total = null,
        ?TitleSortRepairBatch $batch = null
    ): array {
        return [
            'state' => $state,
            'reason' => (string) $s['reason'],
            'cursor' => (int) $s['cursor'],
            'audited' => $audited,
            'checked' => $checked,
            'repaired' => $repaired,
            'swept' => $total['checked'] ?? $checked,
            'swept_repaired' => $total['repaired'] ?? $repaired,
            'blocked_at' => $batch?->stoppedAt,
            'blocked_by' => $batch === null ? '' : $batch->reason,
            'remaining' => $s['reason'] === '' ? 0 : $this->remaining((int) $s['cursor']),
        ];
    }

    private function remaining(int $cursor): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->products() . '` WHERE id > %d',
            [$cursor]
        );
    }

    private function columnExists(): bool
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            [$this->products(), M0020ProductTitleSort::COLUMN]
        ) > 0;
    }

    private function products(): string
    {
        return T::table($this->db, T::PRODUCTS);
    }
}
