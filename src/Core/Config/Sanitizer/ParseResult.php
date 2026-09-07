<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Config\Sanitizer;

/**
 * Outcome of parsing one field. Error codes are stable identifiers; the
 * presentation layer maps them to translated Persian messages.
 */
final class ParseResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly mixed $value,
        public readonly ?string $errorCode
    ) {
    }

    public static function ok(mixed $value): self
    {
        return new self(true, $value, null);
    }

    public static function error(string $code): self
    {
        return new self(false, null, $code);
    }
}
