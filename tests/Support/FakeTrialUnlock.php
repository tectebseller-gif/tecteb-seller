<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Modules\Order\Application\OrderTrialInterface;

/**
 * The trial waiver, without an options table or an environment.
 *
 * Trial mode is what lets a test drive the whole order path while DEC-02 and
 * DEC-04 are open — the same waiver a disposable site gets, and refused the
 * same way everywhere else.
 */
final class FakeTrialUnlock implements OrderTrialInterface
{
    public function __construct(
        private readonly bool $active,
        private readonly string $environment = 'local'
    ) {
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function refusal(): string
    {
        return '';
    }

    public function environmentName(): string
    {
        return $this->environment;
    }
}
