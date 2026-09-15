<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Contracts;

final class ModuleManifest
{
    /** Lowercase, starts with a letter, no trailing/double hyphen, 1-41 chars. */
    public const ID_PATTERN = '/^[a-z](?:[a-z0-9]|-(?=[a-z0-9])){0,40}$/';

    /**
     * @param list<string> $dependencies module ids that must be Active before this one runs
     */
    public function __construct(
        public readonly string $id,
        public readonly string $version,
        public readonly string $label,
        public readonly ModuleKind $kind,
        public readonly array $dependencies = [],
        public readonly bool $requiresWooCommerce = false,
        public readonly string $description = ''
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException('Invalid module id: ' . $id);
        }
        foreach ($dependencies as $dep) {
            if (!is_string($dep) || preg_match(self::ID_PATTERN, $dep) !== 1) {
                throw new \InvalidArgumentException('Invalid dependency id in module ' . $id);
            }
        }
    }
}
