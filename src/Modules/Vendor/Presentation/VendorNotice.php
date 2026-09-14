<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Presentation;

/**
 * One message on its way to the next page: the stable code, plus the values
 * that make the sentence specific (a size limit, an allowed format list, the
 * documents still missing).
 *
 * The code travels in the URL because it is harmless there and survives a
 * lost flash entry; the values travel in the flash store because they are the
 * server's own numbers and must not be tamperable. If the two disagree — a
 * flash left over from an earlier write — the code wins and the values are
 * dropped, which is why they are matched here rather than merged blindly.
 */
final class VendorNotice
{
    /** @param array<string,scalar|null> $context */
    private function __construct(
        public readonly string $code,
        public readonly array $context
    ) {
    }

    /** @param array<string,mixed>|null $flash as taken from the flash store */
    public static function fromRequest(string $code, ?array $flash): ?self
    {
        if ($code === '') {
            return null;
        }
        $context = [];
        if (is_array($flash) && ($flash['code'] ?? null) === $code && is_array($flash['context'] ?? null)) {
            foreach ($flash['context'] as $key => $value) {
                if (is_string($key) && (is_scalar($value) || $value === null)) {
                    $context[$key] = $value;
                }
            }
        }
        return new self($code, $context);
    }

    /** @param array<string,scalar|null> $context */
    public static function of(string $code, array $context = []): self
    {
        return new self($code, $context);
    }
}
