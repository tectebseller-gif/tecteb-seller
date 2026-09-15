<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/**
 * One document the manager asks vendors for. The plugin ships ZERO of these:
 * what a pharmacy marketplace must legally collect is the owner's decision,
 * not a guess in our code (plan §2).
 */
final class DocumentType
{
    /**
     * @param list<string> $allowedMime
     */
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $label,
        public readonly bool $required,
        public readonly array $allowedMime,
        public readonly int $maxBytes,
        public readonly string $instructions = '',
        public readonly int $sort = 0
    ) {
    }

    public function accepts(string $mime): bool
    {
        return in_array($mime, $this->allowedMime, true);
    }
}
