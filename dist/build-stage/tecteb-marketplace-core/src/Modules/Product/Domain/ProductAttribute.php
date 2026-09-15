<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * One axis a variable product varies along — «اندازه» with «کوچک، متوسط،
 * بزرگ», say.
 *
 * `key` is stable and lowercase ASCII for the same reason a spec field's key
 * is: it names the axis in every variation's combination string, and renaming
 * it would orphan every variation stored under the old name.
 */
final class ProductAttribute
{
    public const MAX_OPTIONS = 30;

    /** @param list<string> $options */
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly string $label,
        public readonly array $options = [],
        public readonly int $sort = 0
    ) {
    }

    public function has(string $option): bool
    {
        return in_array($option, $this->options, true);
    }

    public function isUsable(): bool
    {
        return $this->key !== '' && $this->label !== '' && $this->options !== [];
    }
}
