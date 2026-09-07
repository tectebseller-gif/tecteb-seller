<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Config;

final class SanitizeOutcome
{
    /**
     * @param array<string,string> $errors  input field => error code
     * @param array<string,array{old:mixed,new:mixed}> $changes stored key => old/new (non-sensitive)
     */
    public function __construct(
        public readonly Settings $settings,
        public readonly array $errors,
        public readonly array $changes
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function hasChanges(): bool
    {
        return $this->changes !== [];
    }
}
