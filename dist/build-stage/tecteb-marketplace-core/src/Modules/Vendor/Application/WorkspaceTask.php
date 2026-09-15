<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

/**
 * One row of the vendor dashboard. `action` is a route key, not a URL and
 * not a label: the view decides both, so this layer stays free of HTML and
 * of WordPress.
 */
final class WorkspaceTask
{
    /** @param array<string,scalar|null> $context */
    public function __construct(
        public readonly string $key,
        public readonly TaskState $state,
        public readonly ?string $action = null,
        public readonly array $context = []
    ) {
    }
}
