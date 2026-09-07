<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Admin\Application;

use Tecteb\Marketplace\Core\Config\Settings;

/**
 * What validation decided, carried from the sanitize callback to the moment
 * WordPress reports whether the write actually happened.
 *
 * Validation and persistence are different events: the sanitize callback runs
 * BEFORE the database write and cannot know its outcome. Nothing here claims
 * success; that is decided later against the real persisted value.
 */
final class PendingSettingsChange
{
    /**
     * @param array<string,string> $errors input field => error code
     * @param array<string,array{old:mixed,new:mixed}> $changes intended changes
     */
    public function __construct(
        public readonly bool $authorized,
        public readonly Settings $before,
        public readonly Settings $candidate,
        public readonly array $errors,
        public readonly array $changes
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function intendsChange(): bool
    {
        return $this->changes !== [];
    }
}
