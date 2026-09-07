<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Support;

use Tecteb\Marketplace\Contracts\ClockInterface;

/**
 * Controllable clock. Lives in src/ (not tests/) because MigrationLock tests
 * and the FakeOtpProvider both need it; it holds no behaviour of its own.
 */
final class FixedClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(?\DateTimeImmutable $now = null)
    {
        $this->now = ($now ?? new \DateTimeImmutable('2026-09-06T12:00:00+00:00'))
            ->setTimezone(new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(\DateTimeImmutable $now): void
    {
        $this->now = $now->setTimezone(new \DateTimeZone('UTC'));
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }
}
