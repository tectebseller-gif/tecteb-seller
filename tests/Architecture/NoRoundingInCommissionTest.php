<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionPrecision;
use Tecteb\Marketplace\Modules\Finance\Domain\CommissionRate;
use Tecteb\Marketplace\Modules\Finance\Domain\RoundingPolicy;

/**
 * «هیچ گردکردنی مجاز نیست» — as a test, not as a paragraph.
 *
 * The owner's decision is that no commission may be rounded. A docblock
 * saying so is the thing `alpha.22` warned about: the sentence «we do not do
 * this» has to BE the check, or the next round quietly puts the call back.
 *
 * So this file asserts three things that cannot be argued with:
 *
 *  1. neither calculator calls `RoundingPolicy::apply()`, asked of the
 *     TOKENISED file so a docblock mentioning the method cannot fail it and a
 *     commented-out call cannot pass it (`alpha.22`'s other half);
 *  2. nothing on the surface where an integer IS money reaches for a float
 *     rounder — the finance domain and services, plus the order files that
 *     write the five money columns;
 *  3. the derived precision still matches the denominator it is derived from,
 *     so a future change to the rate's scale fails here rather than rounding
 *     a thousandth of a تومان somewhere else.
 */
final class NoRoundingInCommissionTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src';

    /**
     * The files that compute a commission, and the call neither may make.
     *
     * `RoundingPolicy` itself is NOT in the list: it keeps `apply()` so that
     * the `half_up@v1` on every row recorded before `alpha.67` still has an
     * explanation. A version string nothing in the codebase can explain is
     * worse than a method with no live caller.
     */
    public function testNeitherCalculatorRoundsACommission(): void
    {
        foreach ([
            'Modules/Finance/Domain/TectebCommissionCalculator.php',
            'Modules/Finance/Domain/CommissionCalculator.php',
        ] as $relative) {
            $code = self::codeOf(self::SRC . '/' . $relative);
            self::assertStringNotContainsString(
                'RoundingPolicy::apply(',
                $code,
                $relative . ' must not round: the owner has ruled rounding out entirely'
            );
        }
    }

    /**
     * And nothing that COMPUTES MONEY rounds it with a float.
     *
     * ⛔ The first version of this test banned `round()`, `ceil()`, `floor()`
     * and `number_format()` across the whole of `src/`, and it named
     * thirty-six files — pagination arithmetic, a weight in kilogrammes,
     * counts of products on a page. A guard wider than the defect is worse
     * than the defect (`alpha.60`), and that one would have been widened back
     * open by the first person who needed a page count.
     *
     * So it is scoped to the surface where an integer IS money: the finance
     * domain and its services, plus the two order files that write the five
     * money columns. Those files are allowed no float rounder at all — not
     * even `number_format()`, which takes a float and is how a share of
     * ۸۸٫۳۷۵ would become ۸۸ on its way to a page.
     *
     * Formatting lives in `MoneyText`, which is in `Presentation` and does
     * its grouping on a digit STRING for exactly this reason.
     */
    public function testNothingThatComputesMoneyUsesAFloatRounder(): void
    {
        $scopes = [
            self::SRC . '/Modules/Finance/Domain',
            self::SRC . '/Modules/Finance/Application',
        ];
        $alsoFiles = [
            self::SRC . '/Modules/Order/Application/CaptureOrder.php',
            self::SRC . '/Modules/Order/Application/ManageReturns.php',
            self::SRC . '/Modules/Order/Infrastructure/DbOrderItemRepository.php',
            self::SRC . '/Modules/Finance/Infrastructure/DbLedgerRepository.php',
            self::SRC . '/Modules/Finance/Infrastructure/DbWithdrawalRepository.php',
            self::SRC . '/Modules/Finance/Infrastructure/DbSupplierPaymentRepository.php',
        ];
        $paths = $alsoFiles;
        foreach ($scopes as $scope) {
            $paths = array_merge($paths, self::phpFiles($scope));
        }
        $offenders = [];
        foreach ($paths as $path) {
            self::assertFileExists($path, 'the scope of this guard must not drift out of existence');
            $code = self::codeOf($path);
            foreach (['round(', 'ceil(', 'floor(', 'number_format(', '(float)', '(double)'] as $needle) {
                if (str_contains($code, $needle)) {
                    $offenders[] = basename($path) . ' → ' . $needle;
                }
            }
        }
        sort($offenders);
        self::assertSame([], $offenders, 'money is integer arithmetic; these reach for a float');
    }

    /** The derivation, asserted against the denominator it comes from. */
    public function testTheDerivedPrecisionMatchesTheRateDenominator(): void
    {
        self::assertTrue(
            CommissionPrecision::matchesRateDenominator(),
            'EXTRA_SCALE must be the number of decimal digits in CommissionRate::MAX_BP'
        );
        self::assertSame(10000, CommissionRate::MAX_BP);
        self::assertSame(4, CommissionPrecision::EXTRA_SCALE);
        // ریال and تومان: four places. A two-decimal currency: six, exactly
        // at `Money`'s ceiling. Three: refused by name rather than rounded.
        self::assertSame(4, CommissionPrecision::exponentFor(0));
        self::assertSame(6, CommissionPrecision::exponentFor(2));
        self::assertNull(CommissionPrecision::exponentFor(3));
    }

    /** The current policy is exactness, and the historical name still resolves. */
    public function testTheRecordedPolicySaysExactAndTheOldNameSurvives(): void
    {
        self::assertSame('exact@v2', RoundingPolicy::describe());
        self::assertSame('half_up@v1', RoundingPolicy::describeHistoricalHalfUp());
    }

    /** Comments and strings emptied, so prose cannot pass or fail a check. */
    private static function codeOf(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }

    /** @return list<string> */
    private static function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }
}
