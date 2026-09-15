<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

/**
 * What a module's POST handler wants the router to do next.
 *
 * `target` is a full URL because only the handler knows where its own write
 * belongs — back to the same product's step 3, or out to the list. The router
 * adds the notice code and performs the redirect, so the POST/redirect/GET
 * rule stays in ONE place rather than being re-implemented per module.
 */
final class VendorAreaOutcome
{
    /** @param array<string,scalar|null> $context */
    public function __construct(
        public readonly string $code,
        public readonly string $target,
        public readonly array $context = []
    ) {
    }
}
