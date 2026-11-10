<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Core\Audit\AuditEventCatalog;
use Tecteb\Marketplace\Core\Audit\AuditEventSanitizer;

/**
 * WHAT THIS PROVES: the key denylist is applied to TOP-LEVEL payload keys, and
 * — measured — no allowlisted key matches it today.
 *
 * ⛔ **This test exists because a mutation survived, and the survival was the
 * right answer.** Taking the top-level denylist out of `sanitize()` left all
 * four suites green, and that is not a hole: `sanitize()` iterates the
 * CATALOG'S allowed keys, not the payload's, so the check can only ever fire
 * for an allowlisted key — and **zero of the 167 allowlisted keys match the
 * pattern**. The branch is unreachable with today's catalog, by construction.
 *
 * `alpha.77`'s rule covers exactly this: a mutation that cannot be reached is
 * measured rather than guessed at, the property is asserted in SOURCE, and the
 * mutation moves to the suite that holds the assertion. Deleting the guard
 * instead would be the wrong half of the lesson — the guard is for the
 * allowlist entry somebody adds next year, and a `*_token` key would otherwise
 * pass both defences, because an alphanumeric token has no `@` and no run of
 * eight digits for value redaction to catch.
 *
 * So two assertions, and they do different work:
 *
 *  1. The check is THERE and it is BEFORE the value is read. A denylist
 *     applied after `$out[$key]` is assigned is not a denylist.
 *  2. The measurement that makes it unreachable is itself pinned. The day an
 *     allowlist entry matches the pattern, assertion 2 fails — and whoever
 *     reads the failure is told that the guard has started doing something,
 *     which is the moment a behavioural test becomes writable.
 */
final class AuditDenylistCoversTopLevelKeysTest extends TestCase
{
    private const SANITIZER = 'src/Core/Audit/AuditEventSanitizer.php';

    public function testTheDenylistIsCheckedBeforeTheValueIsEverRead(): void
    {
        $body = self::methodBody('sanitize');

        $check = strpos($body, 'preg_match(self::KEY_DENYLIST, $key)');
        self::assertIsInt(
            $check,
            'the top-level loop no longer asks the denylist about the key at all'
        );

        $read = strpos($body, '$value = $payload[$key];');
        self::assertIsInt($read, 'the loop no longer reads the value where this test expects');
        self::assertLessThan(
            $read,
            $check,
            'the denylist must be asked BEFORE the value is read; after it, the key has already been kept'
        );

        // And it `continue`s rather than merely noting the match.
        $after = substr($body, $check, 120);
        self::assertStringContainsString(
            'continue;',
            $after,
            'a denylist that matches and then falls through has not dropped anything'
        );
    }

    /**
     * The measurement, pinned: zero allowlisted keys match the pattern.
     *
     * This is why the branch is unreachable today, and it is asserted rather
     * than written in a comment so that the comment cannot drift away from the
     * catalog.
     */
    public function testNoAllowlistedKeyMatchesTheDenylistToday(): void
    {
        $pattern = self::denylistPattern();

        $keys = [];
        foreach (AuditEventCatalog::allowlist() as $event => $allowed) {
            foreach ($allowed as $key) {
                $keys[(string) $key] = true;
            }
        }
        self::assertGreaterThan(100, count($keys), 'the allowlist got small — read the catalog, not this test');

        $matching = [];
        foreach (array_keys($keys) as $key) {
            if (preg_match($pattern, $key) === 1) {
                $matching[] = $key;
            }
        }

        self::assertSame(
            [],
            $matching,
            "an allowlisted key now matches the denylist, so the top-level guard has started DOING something:\n"
                . implode("\n", $matching)
                . "\nThat is not a failure of the guard — it is the moment a behavioural test for it becomes "
                . "writable. Write it, then update this one."
        );
    }

    /** The pattern itself, read from the class rather than copied. */
    private static function denylistPattern(): string
    {
        $reflection = new \ReflectionClass(AuditEventSanitizer::class);
        $constants = $reflection->getConstants(\ReflectionClassConstant::IS_PRIVATE);
        self::assertArrayHasKey('KEY_DENYLIST', $constants, 'the denylist constant was renamed or removed');
        return (string) $constants['KEY_DENYLIST'];
    }

    /** One method's body, by brace matching — not by a character window. */
    private static function methodBody(string $name): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::SANITIZER);
        $at = strpos($source, 'function ' . $name . '(');
        self::assertIsInt($at, $name . '() is not in ' . self::SANITIZER);
        $open = strpos($source, '{', $at);
        self::assertIsInt($open);

        $depth = 0;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }
        self::fail('braces never balanced in ' . $name . '()');
    }
}
