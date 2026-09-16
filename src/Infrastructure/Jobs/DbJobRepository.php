<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\Jobs;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\JobRepositoryInterface;
use Tecteb\Marketplace\Core\Jobs\Job;
use Tecteb\Marketplace\Core\Jobs\JobStatus;
use Tecteb\Marketplace\Core\Migration\Migrations\M0013JobsAndOutbox as T;

/**
 * The queue on the plugin's own schema.
 *
 * Every rule that two concurrent workers could otherwise both pass is in a
 * WHERE clause here:
 *
 *  - **`enqueue()` is `INSERT IGNORE` on the live key**, then a read of the row
 *    that survived. Two admins pressing «شروع» at the same instant get one job
 *    and the same id, and the second one is told it was already queued rather
 *    than being handed a second import of the same data.
 *  - **`claim()` is one UPDATE** whose WHERE says «still unowned or the lease
 *    ran out», and the winner is decided by the row count. The row is then read
 *    back BY TOKEN, so the job returned is provably the one this call claimed
 *    and not one a faster worker took in between.
 *  - **`checkpoint()`, `finish()` and `release()` all match on the token.** A
 *    worker whose lease expired writes nothing, and finds out because the row
 *    count is zero.
 *
 * `live_key` is cleared in the same statement that moves a job to a terminal
 * state, never in a second one: a job that finished but kept its key would
 * block the next import for ever, and a crash between the two writes is exactly
 * how that happens.
 */
final class DbJobRepository implements JobRepositoryInterface
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock
    ) {
    }

    public function enqueue(string $type, array $payload, string $liveKey, ?int $actorId): array
    {
        $now = $this->now();
        $key = $liveKey === '' ? $type : $liveKey;
        $written = $this->db->execute(
            'INSERT IGNORE INTO `' . $this->jobs() . '`
             (job_type, live_key, status, payload, `cursor`, total, done_count, failed_count,
              attempts, lock_token, locked_until, last_error, actor_id, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s, 0, 0, 0, 0, NULL, NULL, %s, %s, %s, %s)',
            [
                $type,
                $key,
                JobStatus::Pending->value,
                $this->encode($payload),
                $this->encode([]),
                '',
                $actorId === null ? null : (string) $actorId,
                $now,
                $now,
            ]
        );
        if ($written !== null && $written > 0) {
            return ['id' => (int) $this->db->getVar('SELECT LAST_INSERT_ID()'), 'created' => true];
        }
        // Either the insert was ignored because a live job already holds this
        // key, or it failed. Those are different answers and the row says which.
        $existing = (int) $this->db->getVar(
            'SELECT id FROM `' . $this->jobs() . '` WHERE job_type = %s AND live_key = %s',
            [$type, $key]
        );
        return ['id' => $existing, 'created' => false];
    }

    public function claim(array $types, string $lockToken, int $leaseSeconds): ?Job
    {
        $now = $this->now();
        $until = $this->plus($leaseSeconds);

        $where = 'status = %s OR (status = %s AND (locked_until IS NULL OR locked_until < %s))';
        $params = [JobStatus::Pending->value, JobStatus::Running->value, $now];
        $typeSql = '';
        if ($types !== []) {
            $typeSql = ' AND job_type IN (' . implode(', ', array_fill(0, count($types), '%s')) . ')';
        }

        // Pick a candidate, then try to take it. The SELECT can be beaten; the
        // UPDATE cannot, and that is the one whose answer is believed.
        $candidates = $this->db->getResults(
            'SELECT id FROM `' . $this->jobs() . '` WHERE (' . $where . ')' . $typeSql . ' ORDER BY id ASC LIMIT 5',
            array_merge($params, $types)
        );
        foreach ($candidates as $row) {
            $id = (int) $row['id'];
            $taken = $this->db->execute(
                'UPDATE `' . $this->jobs() . '`
                 SET lock_token = %s, locked_until = %s, status = %s, attempts = attempts + 1, updated_at = %s
                 WHERE id = %d AND (' . $where . ')',
                array_merge([$lockToken, $until, JobStatus::Running->value, $now, $id], $params)
            );
            if ($taken !== null && $taken > 0) {
                return $this->findByToken($id, $lockToken);
            }
        }
        return null;
    }

    public function checkpoint(int $jobId, string $lockToken, array $cursor, int $doneDelta, int $failedDelta, int $total, int $leaseSeconds): bool
    {
        $written = $this->db->execute(
            'UPDATE `' . $this->jobs() . '`
             SET `cursor` = %s,
                 done_count = done_count + %d,
                 failed_count = failed_count + %d,
                 total = IF(%d > 0, %d, total),
                 locked_until = %s,
                 updated_at = %s
             WHERE id = %d AND lock_token = %s',
            [
                $this->encode($cursor),
                max(0, $doneDelta),
                max(0, $failedDelta),
                $total,
                $total,
                $this->plus($leaseSeconds),
                $this->now(),
                $jobId,
                $lockToken,
            ]
        );
        return $this->wrote($written, $jobId, $lockToken);
    }

    public function finish(int $jobId, string $lockToken, JobStatus $status, string $error): bool
    {
        if ($status->isLive()) {
            return false;                       // finish() is for terminal states only
        }
        $now = $this->now();
        $written = $this->db->execute(
            'UPDATE `' . $this->jobs() . '`
             SET status = %s, live_key = NULL, lock_token = NULL, locked_until = NULL,
                 last_error = %s, finished_at = %s, updated_at = %s
             WHERE id = %d AND lock_token = %s',
            [$status->value, $this->trim($error), $now, $now, $jobId, $lockToken]
        );
        // A terminal write always changes the status, so a zero here really is
        // «not ours» — but it goes through the same check as the others rather
        // than relying on that staying true.
        return $written !== null && ($written > 0 || $this->stateIs($jobId, $status));
    }

    public function release(int $jobId, string $lockToken): bool
    {
        $written = $this->db->execute(
            'UPDATE `' . $this->jobs() . '`
             SET status = %s, lock_token = NULL, locked_until = NULL, updated_at = %s
             WHERE id = %d AND lock_token = %s',
            [JobStatus::Pending->value, $this->now(), $jobId, $lockToken]
        );
        return $written !== null && ($written > 0 || $this->stateIs($jobId, JobStatus::Pending));
    }

    public function find(int $jobId): ?Job
    {
        $row = $this->db->getRow('SELECT * FROM `' . $this->jobs() . '` WHERE id = %d', [$jobId]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function recent(array $statuses, int $limit, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT * FROM `' . $this->jobs() . '`';
        $params = [];
        if ($statuses !== []) {
            $sql .= ' WHERE status IN (' . implode(', ', array_fill(0, count($statuses), '%s')) . ')';
            $params = $statuses;
        }
        $sql .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;
        return array_map(fn (array $row): Job => $this->hydrate($row), $this->db->getResults($sql, $params));
    }

    public function census(): array
    {
        $out = [];
        foreach (JobStatus::cases() as $case) {
            $out[$case->value] = 0;
        }
        foreach ($this->db->getResults('SELECT status, COUNT(*) AS n FROM `' . $this->jobs() . '` GROUP BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }
        // A job whose worker died is still «running» in the row; the report has
        // to separate that from one a worker is actually holding.
        $out['stalled'] = (int) $this->db->getVar(
            'SELECT COUNT(*) FROM `' . $this->jobs() . '`
             WHERE status = %s AND (locked_until IS NULL OR locked_until < %s)',
            [JobStatus::Running->value, $this->now()]
        );
        return $out;
    }

    public function cancel(int $jobId): bool
    {
        $now = $this->now();
        $written = $this->db->execute(
            'UPDATE `' . $this->jobs() . '`
             SET status = %s, live_key = NULL, lock_token = NULL, locked_until = NULL,
                 finished_at = %s, updated_at = %s
             WHERE id = %d AND status IN (%s, %s)',
            [JobStatus::Cancelled->value, $now, $now, $jobId, JobStatus::Pending->value, JobStatus::Running->value]
        );
        return $written !== null && $written > 0;
    }

    public function lastError(): string
    {
        return $this->db->lastError();
    }

    // ------------------------------------------------------------- internals

    /**
     * Did this write land, given that zero rows is ambiguous?
     *
     * MySQL's `UPDATE` reports rows CHANGED, not rows matched, and wpdb does
     * not set `CLIENT_FOUND_ROWS`. So a checkpoint that writes the same cursor
     * with no new counts, in the same second as the last one, changes nothing —
     * and a bare `> 0` reads that as «somebody else owns this job now». It is
     * not a hypothetical: the first run of the delivery job on the disposable
     * site reported `lease_lost` on its final, entirely correct batch.
     *
     * So zero is not an answer by itself. The row is asked whether it still
     * carries our token, which is the question the WHERE clause was really
     * asking all along.
     */
    private function wrote(?int $written, int $jobId, string $lockToken): bool
    {
        if ($written === null) {
            return false;                       // a real failure; lastError() has it
        }
        if ($written > 0) {
            return true;
        }
        return (string) $this->db->getVar(
            'SELECT lock_token FROM `' . $this->jobs() . '` WHERE id = %d',
            [$jobId]
        ) === $lockToken;
    }

    /** Same idea for a terminal write: did the row reach the state we asked for? */
    private function stateIs(int $jobId, JobStatus $status): bool
    {
        return (string) $this->db->getVar(
            'SELECT status FROM `' . $this->jobs() . '` WHERE id = %d',
            [$jobId]
        ) === $status->value;
    }

    private function findByToken(int $jobId, string $lockToken): ?Job
    {
        $row = $this->db->getRow(
            'SELECT * FROM `' . $this->jobs() . '` WHERE id = %d AND lock_token = %s',
            [$jobId, $lockToken]
        );
        return $row === null ? null : $this->hydrate($row);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Job
    {
        return new Job(
            (int) $row['id'],
            (string) $row['job_type'],
            JobStatus::fromStorage((string) $row['status']),
            $this->decode($row['payload'] ?? null),
            $this->decode($row['cursor'] ?? null),
            (int) $row['total'],
            (int) $row['done_count'],
            (int) $row['failed_count'],
            (int) $row['attempts'],
            (string) ($row['lock_token'] ?? ''),
            (string) ($row['locked_until'] ?? ''),
            (string) ($row['last_error'] ?? ''),
            isset($row['actor_id']) && $row['actor_id'] !== null ? (int) $row['actor_id'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
            (string) ($row['finished_at'] ?? '')
        );
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,mixed> */
    private function decode(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function trim(string $error): string
    {
        return mb_substr($error, 0, 500);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function plus(int $seconds): string
    {
        return $this->clock->now()->modify('+' . max(1, $seconds) . ' seconds')->format('Y-m-d H:i:s');
    }

    private function jobs(): string
    {
        return T::table($this->db, T::JOBS);
    }
}
