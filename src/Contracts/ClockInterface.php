<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

/**
 * Injectable clock. Implementations MUST return UTC.
 * Pure contract: no WordPress dependency (FIN-04 asks for an injectable clock).
 */
interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
