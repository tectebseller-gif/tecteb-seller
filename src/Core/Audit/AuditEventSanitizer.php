<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Audit;

/**
 * Structural sanitiser: allowlisted keys only, scalar values only, bounded
 * length, plus a key denylist and value redaction as defence in depth.
 * Raw requests, emails, phones, OTPs, tokens, cookies and keys never reach
 * the table (CORE-08 / SEC-02).
 */
final class AuditEventSanitizer
{
    public const MAX_STRING = 500;
    public const MAX_LIST = 50;

    private const KEY_DENYLIST = '/(pass|secret|token|cookie|otp|email|mail|phone|mobile|tel|iban|sheba|card|apikey|api_key|private|auth|session|nonce)/i';
    private const EMAIL_PATTERN = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';
    private const LONG_DIGITS_PATTERN = '/\d{8,}/';

    /** @return array<string,mixed>|null null when the event type is unknown */
    public function sanitize(string $eventType, array $payload): ?array
    {
        $allowlist = AuditEventCatalog::allowlist();
        if (!isset($allowlist[$eventType])) {
            return null;
        }
        $allowedKeys = $allowlist[$eventType];
        $nestedKeys = AuditEventCatalog::nestedKeyAllowlist($eventType);
        $out = [];
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if (is_array($value)) {
                $out[$key] = $this->sanitizeArray($value, $nestedKeys);
            } else {
                $out[$key] = $this->sanitizeScalar($value);
            }
        }
        return $out;
    }

    /** @param list<string> $nestedKeys */
    private function sanitizeArray(array $value, array $nestedKeys): array
    {
        $out = [];
        $count = 0;
        if (array_is_list($value)) {
            foreach ($value as $item) {
                if ($count++ >= self::MAX_LIST) {
                    break;
                }
                if (is_array($item)) {
                    continue; // no deeper nesting
                }
                $scalar = $this->sanitizeScalar($item);
                if (is_string($scalar) && $nestedKeys !== [] && !in_array($scalar, $nestedKeys, true)) {
                    continue; // e.g. "changed" lists may only name allowlisted keys
                }
                $out[] = $scalar;
            }
            return $out;
        }
        foreach ($value as $key => $item) {
            if (!is_string($key) || ($nestedKeys !== [] && !in_array($key, $nestedKeys, true))) {
                continue;
            }
            if (preg_match(self::KEY_DENYLIST, $key) === 1) {
                continue;
            }
            if (is_array($item)) {
                continue;
            }
            $out[$key] = $this->sanitizeScalar($item);
        }
        return $out;
    }

    private function sanitizeScalar(mixed $value): int|string|bool|null
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (string) $value; // floats are stored as text to avoid precision claims
        }
        if (!is_string($value)) {
            return '[unsupported]';
        }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $text = preg_replace(self::EMAIL_PATTERN, '[redacted]', $text) ?? '';
        $text = preg_replace(self::LONG_DIGITS_PATTERN, '[redacted]', $text) ?? '';
        if (mb_strlen($text) > self::MAX_STRING) {
            $text = mb_substr($text, 0, self::MAX_STRING - 1) . '…';
        }
        return $text;
    }
}
