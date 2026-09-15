<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Application;

/**
 * What a use case did, in a form a controller can turn into one message.
 *
 * `code` is stable and machine-readable; the Persian sentence lives in the
 * presentation layer, so no service ever composes user-facing text.
 */
final class OperationResult
{
    /** @param array<string,scalar|null> $context */
    private function __construct(
        public readonly bool $ok,
        public readonly string $code,
        public readonly array $context = []
    ) {
    }

    /** @param array<string,scalar|null> $context */
    public static function success(string $code, array $context = []): self
    {
        return new self(true, $code, $context);
    }

    /** @param array<string,scalar|null> $context */
    public static function failure(string $code, array $context = []): self
    {
        return new self(false, $code, $context);
    }
}
