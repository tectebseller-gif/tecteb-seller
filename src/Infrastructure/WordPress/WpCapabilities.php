<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;

final class WpCapabilities implements CapabilityCheckerInterface
{
    public function can(string $capability): bool
    {
        return current_user_can($capability);
    }

    public function currentUserId(): ?int
    {
        $id = (int) get_current_user_id();
        return $id > 0 ? $id : null;
    }
}
