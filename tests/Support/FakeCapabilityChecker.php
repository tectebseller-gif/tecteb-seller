<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Support;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;

/** Who the test is pretending to be, and what they may do. */
final class FakeCapabilityChecker implements CapabilityCheckerInterface
{
    /** @param list<string> $capabilities */
    public function __construct(
        private ?int $userId = null,
        private array $capabilities = []
    ) {
    }

    /** @param list<string> $capabilities */
    public function become(?int $userId, array $capabilities): void
    {
        $this->userId = $userId;
        $this->capabilities = $capabilities;
    }

    public function can(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function currentUserId(): ?int
    {
        return $this->userId;
    }
}
