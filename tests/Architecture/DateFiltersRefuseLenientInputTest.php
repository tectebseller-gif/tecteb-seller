<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A date filter refuses what it cannot place, and refuses it twice.
 *
 * ⛔ **This file exists because a mutation survived.** `SiteTime::boundary()`
 * has two guards: an ISO-shape check and a round-trip check that refuses a
 * date PHP rolled over (`2026-02-30` → 2 March). The falsification loop of
 * `alpha.99` removed the FIRST one and every suite stayed green — so it was
 * measured, not argued: the second guard already rejects everything the first
 * does, because `format('Y-m-d') === $date` can only hold for a `Y-m-d`
 * string.
 *
 * That makes the first guard **defence in depth with no behaviour of its
 * own**, which is the one kind of code a later round deletes as dead. The
 * project's answer to exactly this shape is `alpha.77`'s: assert the property
 * in the SOURCE and move the mutation to the suite that reads it. So this is
 * what that mutation now measures.
 *
 * ⛔ And it is NOT a licence to drop the second guard: that one IS reachable
 * and `SiteTimeTest::testADateThatDoesNotExistIsRefusedRatherThanRolledOver`
 * is red the moment it goes.
 */
final class DateFiltersRefuseLenientInputTest extends TestCase
{
    private const SITE_TIME = 'src/Core/Support/SiteTime.php';

    /** WHAT THIS PROVES: the shape check is there, and it is a strict match. */
    public function testTheShapeCheckIsThere(): void
    {
        $body = self::methodBody(self::SITE_TIME, 'boundary');
        self::assertStringContainsString("preg_match('/^\\d{4}-\\d{2}-\\d{2}$/'", $body);
        // `!== 1` rather than a falsy test: `preg_match()` answers `false` on
        // error, and a truthiness test would read that as «no match» — which
        // is the right direction here, but only by accident.
        self::assertStringContainsString('!== 1', $body);
    }

    /** WHAT THIS PROVES: the calendar check is there, and compares the round trip. */
    public function testTheRolledOverDateCheckIsThere(): void
    {
        $body = self::methodBody(self::SITE_TIME, 'boundary');
        self::assertStringContainsString("format('Y-m-d') !== \$date", $body);
    }

    /**
     * WHAT THIS PROVES: a parse failure is `null`, not an exception escaping
     * into a page render.
     */
    public function testAnUnparseableDateIsCaughtRatherThanThrown(): void
    {
        $body = self::methodBody(self::SITE_TIME, 'boundary');
        self::assertStringContainsString('catch (\\Exception)', $body);
        self::assertSame(3, substr_count($body, 'return null;'), 'three refusals, one per guard');
    }

    /**
     * WHAT THIS PROVES: Iran's offset is nowhere in this class.
     *
     * §۸ of the owner's instruction: «timezone ایران به‌صورت ثابت در کد نوشته
     * نشود». The zone comes from `wp_timezone()` and the class is handed one.
     */
    public function testNoZoneIsHardCoded(): void
    {
        $source = self::code(self::SITE_TIME);
        foreach (['Asia/Tehran', '+03:30', '03:30', 'Iran'] as $needle) {
            self::assertStringNotContainsString($needle, $source, $needle . ' must come from the site');
        }
        // UTC is the one zone this class may name: the database stores UTC.
        self::assertStringContainsString("DateTimeZone('UTC')", $source);
    }

    /** The source with comments blanked: a docblock is not code (`alpha.22`). */
    private static function code(string $relative): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat(' ', strlen($token[1]));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /** One method's body, taken by matching braces rather than a window (`alpha.98`). */
    private static function methodBody(string $relative, string $name): string
    {
        $source = self::code($relative);
        $at = strpos($source, 'function ' . $name . '(');
        self::assertNotFalse($at, $name . ' must exist in ' . $relative);
        $from = substr($source, $at);
        $open = strpos($from, '{');
        self::assertNotFalse($open);
        $depth = 0;
        $length = strlen($from);
        for ($i = (int) $open; $i < $length; $i++) {
            if ($from[$i] === '{') {
                $depth++;
            } elseif ($from[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($from, (int) $open, $i - (int) $open + 1);
                }
            }
        }
        self::fail('unbalanced braces in ' . $name);
    }
}
