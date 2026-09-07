<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Health\Application;

final class HealthCheck
{
    /** @param array<string, int|string|bool|null> $facts scalar facts for the presentation layer */
    public function __construct(
        public readonly string $key,
        public readonly HealthStatus $status,
        public readonly array $facts = []
    ) {
    }
}
