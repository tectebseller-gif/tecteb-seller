<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Environment;

final class ResolvedEnvironment
{
    public const SOURCE_CONSTANT = 'constant';
    public const SOURCE_OPTION = 'option';
    public const SOURCE_PLATFORM = 'platform';
    public const SOURCE_NONE = 'none';

    /** @param list<string> $warnings codes: constant_invalid, option_invalid, platform_unknown */
    public function __construct(
        public readonly EnvironmentType $type,
        public readonly string $source,
        public readonly array $warnings = [],
        public readonly ?string $hostnameHint = null
    ) {
    }

    /** Health contract shape: {resolved, source}. */
    public function toArray(): array
    {
        return ['resolved' => $this->type->value, 'source' => $this->source];
    }
}
