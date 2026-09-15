<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Migration;

final class MigrationResult
{
    /**
     * @param list<string> $appliedSteps
     * @param ?CleanupOutcome $cleanup what happened to a previously recorded
     *        failure. null when the run never reached that stage at all
     *        (locked out, failed, or a schema ahead of this build).
     */
    private function __construct(
        public readonly MigrationStatus $status,
        public readonly int $fromVersion,
        public readonly int $toVersion,
        public readonly array $appliedSteps,
        public readonly ?string $failedStep,
        public readonly ?string $error,
        public readonly ?CleanupOutcome $cleanup = null
    ) {
    }

    public static function upToDate(int $version, ?CleanupOutcome $cleanup = null): self
    {
        return new self(MigrationStatus::UpToDate, $version, $version, [], null, self::cleanupError($cleanup), $cleanup);
    }

    public static function locked(int $version): self
    {
        return new self(MigrationStatus::Locked, $version, $version, [], null, null);
    }

    /** @param list<string> $applied steps this run completed before losing the lock */
    public static function lockLost(int $from, int $reached, array $applied, string $step): self
    {
        return new self(MigrationStatus::LockLost, $from, $reached, $applied, $step, 'lock_lost');
    }

    public static function ahead(int $stored, int $target): self
    {
        return new self(MigrationStatus::Ahead, $stored, $target, [], null, 'schema_ahead');
    }

    /** @param list<string> $applied */
    public static function applied(int $from, int $to, array $applied, ?CleanupOutcome $cleanup = null): self
    {
        return new self(MigrationStatus::Applied, $from, $to, $applied, null, self::cleanupError($cleanup), $cleanup);
    }

    /** @param list<string> $applied */
    public static function failed(int $from, int $reached, array $applied, string $step, string $error): self
    {
        return new self(MigrationStatus::Failed, $from, $reached, $applied, $step, $error);
    }

    /** The schema reached the target version. Says nothing about the cleanup. */
    public function isSuccess(): bool
    {
        return $this->status === MigrationStatus::UpToDate || $this->status === MigrationStatus::Applied;
    }

    /**
     * The schema reached the target version AND nothing was left behind.
     *
     * A clear that failed leaves a stale failure record in the database. The
     * migration did happen — calling it Failed would be a lie — but calling it
     * an unqualified success would be one too, so callers that report "all
     * clean" must ask this, not isSuccess().
     */
    public function isFullySuccessful(): bool
    {
        return $this->isSuccess() && $this->cleanup !== CleanupOutcome::Failed;
    }

    /** True when a failure record from an earlier run is still in the database. */
    public function leftStaleErrorRecord(): bool
    {
        return $this->cleanup === CleanupOutcome::Failed;
    }

    private static function cleanupError(?CleanupOutcome $cleanup): ?string
    {
        return $cleanup === CleanupOutcome::Failed
            ? 'cleanup_failed: the previous failure record could not be removed'
            : null;
    }
}
