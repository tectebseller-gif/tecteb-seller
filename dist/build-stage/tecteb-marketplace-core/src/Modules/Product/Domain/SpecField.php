<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Product\Domain;

/**
 * One field in a category's medical template (MED-01).
 *
 * `key` is the stable identifier and never changes: the label above a field
 * can be rewritten freely, and every product that already answered it keeps
 * its answer. That is the difference MED-01 insists on between renaming a
 * field and destroying data.
 *
 * `deprecated` is how a field goes away. Deleting the row would take the
 * answers with it, including answers already snapshotted into orders, so a
 * retired field stops being asked and keeps what it was told.
 */
final class SpecField
{
    /** @param list<string> $options */
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly string $label,
        public readonly SpecFieldType $type,
        public readonly bool $required = false,
        public readonly string $unit = '',
        public readonly array $options = [],
        public readonly int $sort = 0,
        public readonly bool $deprecated = false,
        public readonly int $schemaVersion = 1
    ) {
    }

    public function isAsked(): bool
    {
        return !$this->deprecated;
    }
}
