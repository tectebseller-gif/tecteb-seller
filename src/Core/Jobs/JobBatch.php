<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Jobs;

/**
 * What one batch did, and — the part that makes a job resumable — where to
 * start next time.
 *
 * A handler that has finished returns `$more = false`; anything else and the
 * runner writes the cursor and leaves the job pending for the next worker.
 * There is deliberately no «I am done but here is a cursor» combination: the
 * cursor a finished job leaves behind is the one it finished on, which is what
 * the report needs and what a resumed job would re-read harmlessly.
 */
final class JobBatch
{
    /**
     * @param array<string,mixed>   $cursor where the next batch should start
     * @param list<string>          $notes  short machine keys, never sentences
     */
    private function __construct(
        public readonly array $cursor,
        public readonly int $done,
        public readonly int $failed,
        public readonly bool $more,
        public readonly int $total,
        public readonly array $notes,
        public readonly string $error
    ) {
    }

    /** @param array<string,mixed> $cursor @param list<string> $notes */
    public static function progress(array $cursor, int $done, int $failed = 0, int $total = 0, array $notes = []): self
    {
        return new self($cursor, $done, $failed, true, $total, $notes, '');
    }

    /** @param array<string,mixed> $cursor @param list<string> $notes */
    public static function finished(array $cursor = [], int $done = 0, int $failed = 0, int $total = 0, array $notes = []): self
    {
        return new self($cursor, $done, $failed, false, $total, $notes, '');
    }

    /**
     * The work itself was wrong. The job stops and waits for a person — it does
     * not retry, because a retry of a wrong instruction is the same wrong
     * instruction. A process that merely died leaves no batch at all and is
     * picked up again by the expired lease.
     *
     * @param array<string,mixed> $cursor
     */
    public static function failed(string $error, array $cursor = []): self
    {
        return new self($cursor, 0, 0, false, 0, [], $error === '' ? 'unknown_error' : $error);
    }

    public function isFailure(): bool
    {
        return $this->error !== '';
    }
}
