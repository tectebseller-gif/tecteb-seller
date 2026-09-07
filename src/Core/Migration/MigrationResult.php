<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Migration;

final class MigrationResult
{
    /** @param list<string> $appliedSteps */
    private function __construct(
        public readonly MigrationStatus $status,
        public readonly int $fromVersion,
        public readonly int $toVersion,
        public readonly array $appliedSteps,
        public readonly ?string $failedStep,
        public readonly ?string $error
    ) {
    }

    public static function upToDate(int $version): self
    {
        return new self(MigrationStatus::UpToDate, $version, $version, [], null, null);
    }

    public static function locked(int $version): self
    {
        return new self(MigrationStatus::Locked, $version, $version, [], null, null);
    }

    /** @param list<string> $applied */
    public static function applied(int $from, int $to, array $applied): self
    {
        return new self(MigrationStatus::Applied, $from, $to, $applied, null, null);
    }

    /** @param list<string> $applied */
    public static function failed(int $from, int $reached, array $applied, string $step, string $error): self
    {
        return new self(MigrationStatus::Failed, $from, $reached, $applied, $step, $error);
    }

    public function isSuccess(): bool
    {
        return $this->status === MigrationStatus::UpToDate || $this->status === MigrationStatus::Applied;
    }
}
