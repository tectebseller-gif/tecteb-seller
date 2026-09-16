<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Core\Events;

/**
 * The signature a receiver checks, computed the same way every time.
 *
 * **Canonical bytes, not «the JSON we happened to produce».** A receiver
 * verifies by re-encoding what it received and hashing it, and two JSON
 * encoders disagree about key order, unicode escaping and slashes. If the
 * signature were taken over an arbitrary encoding, a payload that survived the
 * wire byte-for-byte would still fail verification against a receiver written
 * in another language. So the keys are sorted, recursively, and the flags are
 * fixed — and the canonical string is stored next to the row, because the thing
 * that was signed is the thing that must be sent.
 *
 * **The timestamp is inside the signed material.** Without it a captured
 * delivery can be replayed for ever; with it a receiver can refuse anything
 * older than its own window. The header carries it separately so the receiver
 * can read it before deciding to verify at all.
 *
 * **Verification is `hash_equals`.** A `===` on two hashes leaks, through
 * timing, how many leading characters a guess got right. That is the whole
 * reason this function exists rather than a comparison at the call site.
 *
 * No secret is generated here and none is stored here. The Alpha has no
 * outbound channel at all — `OutboundPolicy` blocks every one and cannot be
 * opened by configuration — so what this class produces today is a signature a
 * reader can verify on the page, not something that leaves the site.
 */
final class EventSigner
{
    public const ALGORITHM = 'sha256';

    /** The header a receiver reads, when there is ever a receiver. */
    public const HEADER = 'X-TMC-Signature';

    public const TIMESTAMP_HEADER = 'X-TMC-Timestamp';

    public const VERSION = 'v1';

    /**
     * Bytes to sign: version, timestamp and the canonical payload, separated by
     * a character that cannot appear in any of them.
     *
     * @param array<string,mixed> $payload
     */
    public static function canonical(array $payload, int $timestamp): string
    {
        return self::VERSION . "\n" . $timestamp . "\n" . self::encode($payload);
    }

    /** @param array<string,mixed> $payload */
    public static function sign(array $payload, int $timestamp, string $secret): string
    {
        return self::VERSION . '=' . hash_hmac(self::ALGORITHM, self::canonical($payload, $timestamp), $secret);
    }

    /** @param array<string,mixed> $payload */
    public static function verify(array $payload, int $timestamp, string $secret, string $candidate): bool
    {
        return hash_equals(self::sign($payload, $timestamp, $secret), $candidate);
    }

    /**
     * Sorted keys, all the way down, with fixed encoder flags.
     *
     * @param array<string,mixed> $payload
     */
    public static function encode(array $payload): string
    {
        return (string) json_encode(
            self::sortDeep($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * @param array<array-key,mixed> $value
     * @return array<array-key,mixed>
     */
    private static function sortDeep(array $value): array
    {
        // A LIST keeps its order — position is meaning in a list, and sorting
        // one would change what the payload says. Only maps are sorted.
        $isList = array_is_list($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortDeep($item);
            }
        }
        if (!$isList) {
            ksort($value);
        }
        return $value;
    }
}
