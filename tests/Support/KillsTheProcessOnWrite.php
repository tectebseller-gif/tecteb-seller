<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\DatabaseInterface;

/**
 * A database that kills its own process, for real, before the Nth write.
 *
 * The point is what it is NOT. An exception is caught, a `return null` is a
 * value the caller can branch on, and deleting a manifest AFTER the run
 * finished only ever proves what a finished run wrote. None of those is a
 * crash. A crash is the process stopping between two statements with nothing
 * running afterwards — no `finally`, no shutdown handler, no destructor, no
 * chance to tidy up.
 *
 * `SIGKILL` to its own pid is exactly that, and it is why this class exists
 * instead of a `throw`: `SIGKILL` cannot be caught, blocked or ignored, so
 * whatever the parent then reads is what a killed importer really leaves
 * behind. Used from a forked child, which is why the wpdb stub has
 * `disconnect()`/`reconnect()`.
 *
 * Reads are not counted. Only `execute()` — the statements that change
 * something — because «between which two writes did it die» is the question.
 */
final class KillsTheProcessOnWrite implements DatabaseInterface
{
    private int $writes = 0;

    public function __construct(
        private readonly DatabaseInterface $inner,
        /** Die BEFORE this write. 1 kills before the first one. */
        private readonly int $killBeforeWrite
    ) {
    }

    public function prefix(): string
    {
        return $this->inner->prefix();
    }

    public function charsetCollate(): string
    {
        return $this->inner->charsetCollate();
    }

    public function execute(string $sql, array $params = []): ?int
    {
        $this->writes++;
        if ($this->writes === $this->killBeforeWrite) {
            // No output, no flush, no exit code: the process simply stops.
            posix_kill(posix_getpid(), SIGKILL);
            // Unreachable. If the signal were ever ignored, failing loudly
            // beats quietly writing the statement this call was meant to stop.
            throw new \RuntimeException('SIGKILL did not kill the process');
        }
        return $this->inner->execute($sql, $params);
    }

    public function getVar(string $sql, array $params = []): mixed
    {
        return $this->inner->getVar($sql, $params);
    }

    public function getRow(string $sql, array $params = []): ?array
    {
        return $this->inner->getRow($sql, $params);
    }

    public function getResults(string $sql, array $params = []): array
    {
        return $this->inner->getResults($sql, $params);
    }

    public function lastError(): string
    {
        return $this->inner->lastError();
    }
}
