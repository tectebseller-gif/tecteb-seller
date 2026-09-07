<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Support;

use Tecteb\Marketplace\Contracts\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
