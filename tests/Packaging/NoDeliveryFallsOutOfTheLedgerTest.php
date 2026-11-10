<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Packaging;

use PHPUnit\Framework\TestCase;

/**
 * ⛔ THE OTHER DIRECTION of the delivery ledger, which nothing asked.
 *
 * `PackagingTest::testEveryDeliveredPackageStillHasTheBytesItWasDeliveredWith()`
 * walks every LINE of `dist/DELIVERED.txt` and proves the bytes still match.
 * That protects against a line telling a lie. It says nothing about a package
 * with no line at all — and measured on this checkout, `alpha.100` and
 * `alpha.101` had none: the ledger's last entry was `alpha.99` while both had
 * been built, hashed into `dist/SHA256SUMS`, and handed over.
 *
 * The cost of that gap is exactly what the ledger exists to prevent:
 * `build.sh` asks the ledger before refusing to rebuild, so an unlisted
 * delivered version could be rebuilt with different bytes, and `SHA256SUMS`
 * — regenerated from disk — would agree with the overwrite. `alpha.24` paid
 * for that once already.
 *
 * ⛔ AND THE FIRST VERSION OF THIS GUARD WAS WIDER THAN THE DEFECT, which is
 * worse than no guard (`alpha.60`). It asked «is every built ZIP older than
 * the current version listed?» and named SEVENTEEN: `alpha.2`…`alpha.21`. Not
 * a finding — a wrong question. Plenty of those were built and never handed
 * over; `alpha.15` says so in `CLAUDE.md` in as many words («بستهٔ میانی بود:
 * ساخته و commit شد، تحویل نشد»). Passing that guard would have meant writing
 * ledger lines for packages nobody received, i.e. making the record lie to
 * satisfy a test.
 *
 * Only a person knows what was delivered, which is why the ledger is hand
 * written. So the measurable question is not «was this delivered?» but «did
 * the ledger STOP?»: every version above the newest line is one that came and
 * went after the last time anybody wrote anything down. Everything at or
 * below it was already triaged by a human — listed, or deliberately not.
 */
final class NoDeliveryFallsOutOfTheLedgerTest extends TestCase
{
    public function testTheLedgerDidNotStopBeingWritten(): void
    {
        $root = dirname(__DIR__, 2);
        $sums = $root . '/dist/SHA256SUMS';
        $ledger = $root . '/dist/DELIVERED.txt';
        self::assertFileExists($sums, 'SHA256SUMS records what was built');
        self::assertFileExists($ledger, 'DELIVERED.txt records what was handed over');

        $current = self::currentAlpha($root);
        $recorded = (string) file_get_contents($ledger);
        $newestRecorded = self::newestAlphaIn($recorded);

        self::assertGreaterThan(
            0,
            $newestRecorded,
            'the ledger names at least one install package, or it is not a record'
        );
        $gaps = [];
        $window = 0;
        foreach (self::builtAlphaZips($sums) as $number => $name) {
            // The version being built right now has not been delivered yet,
            // and a line for it would be the ledger claiming something no
            // human has done.
            if ($number >= $current) {
                continue;
            }
            // At or below the newest recorded delivery, a human has already
            // decided. Silence there means «built, not delivered», which is a
            // real and legitimate state.
            if ($number <= $newestRecorded) {
                continue;
            }
            $window++;
            if (!str_contains($recorded, $name)) {
                $gaps[] = $name;
            }
        }

        self::assertSame(
            [],
            $gaps,
            'the ledger stopped: these versions came and went after its newest entry'
                . ' (alpha.' . $newestRecorded . ') and nothing records what happened to'
                . ' them, so build.sh would rebuild them over the bytes that went out: '
                . implode(', ', $gaps)
        );
        self::assertSame(
            0,
            $window,
            'nothing sits between the newest ledger entry and the version being built'
        );
    }

    /**
     * The guard measures something — asked of the ledger itself rather than
     * trusted, because a parser that silently matches nothing would make the
     * assertion above pass on an empty file (`alpha.80`).
     */
    public function testTheMeasurementCanSeeTheLedgerAtAll(): void
    {
        $root = dirname(__DIR__, 2);
        $recorded = (string) file_get_contents($root . '/dist/DELIVERED.txt');

        self::assertGreaterThanOrEqual(
            60,
            count(self::alphaNumbersIn($recorded)),
            'the ledger parser reads the install packages it records'
        );
        self::assertNotSame(
            [],
            self::builtAlphaZips($root . '/dist/SHA256SUMS'),
            'the SHA256SUMS parser reads install ZIPs'
        );
    }

    /** @return array<int,string> version number => file name */
    private static function builtAlphaZips(string $sums): array
    {
        $found = [];
        foreach (explode("\n", (string) file_get_contents($sums)) as $line) {
            // Install ZIPs only. The companion archives (source, reviewable,
            // evidence) are deliberately uncommitted since `alpha.22` and are
            // not what «delivered» means here.
            if (
                preg_match(
                    '/^[0-9a-f]{64}\s+(tecteb-marketplace-core-0\.1\.0-alpha\.(\d+)\.zip)$/',
                    trim($line),
                    $m
                ) === 1
            ) {
                $found[(int) $m[2]] = $m[1];
            }
        }
        return $found;
    }

    /** @return list<int> */
    private static function alphaNumbersIn(string $text): array
    {
        preg_match_all(
            '/tecteb-marketplace-core-0\.1\.0-alpha\.(\d+)\.zip/',
            $text,
            $m
        );
        return array_map('intval', $m[1]);
    }

    private static function newestAlphaIn(string $text): int
    {
        $numbers = self::alphaNumbersIn($text);
        return $numbers === [] ? 0 : max($numbers);
    }

    /** The alpha number this tree builds, read from the plugin header. */
    private static function currentAlpha(string $root): int
    {
        $header = (string) file_get_contents($root . '/tecteb-marketplace-core.php');
        self::assertSame(
            1,
            preg_match('/^\s*\*\s*Version:\s*0\.1\.0-alpha\.(\d+)/m', $header, $m),
            'the plugin header declares an alpha version'
        );
        return (int) $m[1];
    }
}
