<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor\Domain;

/**
 * A one-time invitation, handed over by the vendor rather than sent.
 *
 * WHY NOT EMAIL OR SMS: Alpha sends nothing (plan §4.4), so an invitation
 * that depended on delivery would depend on a thing this build refuses to
 * do. The vendor copies a link and gives it to the person however they
 * already talk to them. That is weaker than a delivered secret, and the
 * design says so out loud instead of pretending: the link expires, is single
 * use, and only the HASH is stored, so a database read cannot replay it.
 */
final class InvitationToken
{
    public const LIFETIME_SECONDS = 7 * 24 * 60 * 60;

    private function __construct(
        public readonly string $plain,
        public readonly string $hash
    ) {
    }

    public static function issue(): self
    {
        $plain = bin2hex(random_bytes(24));
        return new self($plain, self::hash($plain));
    }

    /** Same one-way function on both sides; never reversible, never logged. */
    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** Constant-time, so a wrong token cannot be found one character at a time. */
    public static function matches(string $candidate, string $storedHash): bool
    {
        return $storedHash !== '' && hash_equals($storedHash, self::hash($candidate));
    }

    public static function looksWellFormed(string $candidate): bool
    {
        return preg_match('/^[a-f0-9]{48}$/', $candidate) === 1;
    }
}
