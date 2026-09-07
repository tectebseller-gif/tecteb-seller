<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Environment;

use Tecteb\Marketplace\Core\Exceptions\OutboundBlockedException;

/**
 * Alpha outbound lock (CORE-04). Not configurable: no option, constant,
 * environment or filter can open it. Other plugins' safety is unknown and is
 * reported as such — never as "the whole site is safe".
 */
final class OutboundPolicy
{
    public const TMC = 'blocked';
    public const OTHER_PLUGINS = 'unknown';

    public static function tmcStatus(): string
    {
        return self::TMC;
    }

    public static function otherPluginsStatus(): string
    {
        return self::OTHER_PLUGINS;
    }

    /** Always false in the Alpha regardless of the resolved environment. */
    public static function isTmcOutboundAllowed(ResolvedEnvironment $environment): bool
    {
        return false;
    }

    /** Any future sender MUST call this first; it throws unconditionally in the Alpha. */
    public static function assertAllowed(ResolvedEnvironment $environment, string $channel): void
    {
        throw new OutboundBlockedException(
            sprintf('TMC outbound channel "%s" is blocked in this release (environment: %s).', $channel, $environment->type->value)
        );
    }

    /** Health contract shape. */
    public static function toArray(): array
    {
        return ['tmc' => self::TMC, 'other_plugins' => self::OTHER_PLUGINS];
    }
}
