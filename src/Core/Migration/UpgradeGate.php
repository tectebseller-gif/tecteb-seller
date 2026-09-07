<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Migration;

use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;

/**
 * Decides whether a request should run migrations, independently of
 * activation (CORE-01).
 *
 * WHY THIS EXISTS: WordPress does NOT re-fire the activation hook when a
 * plugin's files are replaced by an update. A plugin that only migrates on
 * activation therefore ships a new SCHEMA_VERSION that never gets applied,
 * and the site runs new code against an old structure. This gate closes that
 * hole by checking the stored version on ordinary requests too.
 *
 * It also governs RETRY after a failed migration:
 *  - a run that has never failed is attempted immediately
 *  - a run that failed is retried, but not more often than RETRY_COOLDOWN,
 *    so a permanently failing step cannot hammer the database on every
 *    single admin request while still recovering on its own from a
 *    transient failure (a lock timeout, a full disk that was cleared)
 *  - the failure stays visible on the health page the whole time; the
 *    cooldown throttles retries, it never hides the problem
 *
 * A schema AHEAD of this build is never touched: this build does not know
 * the newer structure (see MigrationRunner::isAhead()).
 */
final class UpgradeGate
{
    /** Minimum gap between automatic retries of a failed migration. */
    public const RETRY_COOLDOWN_SECONDS = 300;

    public function __construct(
        private MigrationRunner $runner,
        private OptionStoreInterface $options,
        private ClockInterface $clock
    ) {
    }

    /**
     * Cheap enough for every request: one option read in the common case,
     * a second only when a previous failure is recorded.
     */
    public function shouldRun(): bool
    {
        if ($this->runner->isAhead()) {
            return false;
        }
        if (!$this->runner->needsMigration()) {
            return false;
        }
        $lastError = $this->runner->lastError();
        if ($lastError === null) {
            return true;
        }
        return $this->cooldownElapsed($lastError);
    }

    /** Runs only when shouldRun() allows it; returns null when it declined. */
    public function runIfNeeded(): ?MigrationResult
    {
        if (!$this->shouldRun()) {
            return null;
        }
        return $this->runner->run();
    }

    /** @param array{step:string,message:string,at:string} $lastError */
    private function cooldownElapsed(array $lastError): bool
    {
        $at = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $lastError['at']);
        if ($at === false) {
            // Unparseable timestamp: treat as "long ago" rather than blocking
            // recovery forever on a corrupt option.
            return true;
        }
        return $this->clock->now()->getTimestamp() - $at->getTimestamp() >= self::RETRY_COOLDOWN_SECONDS;
    }

    public function secondsUntilRetry(): ?int
    {
        $lastError = $this->runner->lastError();
        if ($lastError === null || !$this->runner->needsMigration()) {
            return null;
        }
        $at = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $lastError['at']);
        if ($at === false) {
            return 0;
        }
        $remaining = self::RETRY_COOLDOWN_SECONDS - ($this->clock->now()->getTimestamp() - $at->getTimestamp());
        return max(0, $remaining);
    }
}
