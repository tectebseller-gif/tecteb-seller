<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Migration\Application\MigrateMinMax;
use Tecteb\Marketplace\Modules\Migration\Domain\MinMaxRule;

/**
 * «برابری مجموعه تعدادهای مجاز … به‌صورت کامل تعیین شود» — as a test.
 *
 * `alpha.70` decided whether a migrated purchase-limit rule still allows the
 * same quantities by asking both plugins about 1…60. A docblock saying «ask
 * `allowedSet()` instead» is exactly what `alpha.22` warned about: the
 * sentence has to BE the check, or the next round writes the loop back —
 * and the next round's loop will run to 200 and be wrong about 201.
 *
 * Three things are asserted of the TOKENISED files, so a docblock naming a
 * method cannot fail this and a commented-out call cannot pass it:
 *
 *  1. nothing in `src/` samples quantities to compare two rules —
 *     `sourceAllows()` is called in exactly one place, and that place is
 *     `MinMaxRule::allowedUpTo()`, which the exit report uses to ILLUSTRATE
 *     and nothing uses to decide;
 *  2. `MigrateMinMax` carries no comparison bound any more, under that name
 *     or any other: `ILLUSTRATE_TO` exists, `COMPARE_TO` does not, and the
 *     one is used only where example rows are built;
 *  3. the decision goes through `QuantitySet`, which is the class that holds
 *     the arithmetic and the reasons it is exact.
 */
final class EqualityIsDecidedNotSampledTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src';

    /**
     * The ONE place a quantity may be asked about one at a time, with its
     * reason written down.
     *
     * `allowedUpTo()` enumerates on purpose — a manager reads the two lists
     * side by side and can disagree with them. What it may not be is the
     * answer to «is this the same rule», and nothing else in `src/` reaches
     * for the same verb.
     */
    private const MAY_SAMPLE = 'Modules/Migration/Domain/MinMaxRule.php';

    public function testNothingInTheSourceComparesTwoRulesBySamplingQuantities(): void
    {
        $callers = [];
        foreach (self::phpFiles(self::SRC) as $file) {
            if (!str_contains(self::codeOf($file), 'sourceAllows(')) {
                continue;
            }
            $callers[] = str_replace(self::SRC . '/', '', $file);
        }
        self::assertSame(
            [self::MAY_SAMPLE],
            $callers,
            'asking one quantity at a time is for an illustration, never for a verdict'
        );
    }

    public function testTheMigrationCarriesNoComparisonBound(): void
    {
        $constants = (new \ReflectionClass(MigrateMinMax::class))->getConstants();
        self::assertArrayNotHasKey(
            'COMPARE_TO',
            $constants,
            'a bound a verdict reads from is that verdict\'s bound, whatever it is called'
        );
        self::assertArrayHasKey('ILLUSTRATE_TO', $constants);

        // …and the one that remains is used ONLY where an example row is
        // built. Measured on the tokenised file: one declaration, one use.
        $code = self::codeOf(self::SRC . '/Modules/Migration/Application/MigrateMinMax.php');
        self::assertSame(
            2,
            substr_count($code, 'ILLUSTRATE_TO'),
            'the illustration bound is declared once and read once, in example()'
        );
        self::assertStringContainsString('$rule->allowedUpTo(self::ILLUSTRATE_TO)', $code);
    }

    public function testTheVerdictDecidesThroughTheSetItself(): void
    {
        $code = self::codeOf(self::SRC . '/Modules/Migration/Application/MigrateMinMax.php');
        self::assertStringContainsString('$rule->allowedSet()', $code);
        self::assertStringContainsString('QuantitySet::fromPurchaseQuantity($stored)', $code);
        self::assertStringContainsString('$minmax->equals($here)', $code);
        // And «I cannot tell» is asked BEFORE equality, because an unknown set
        // equals nothing and reading that as «different» would name the wrong
        // remedy.
        self::assertStringContainsString('!$minmax->known() || !$here->known()', $code);
    }

    /** Both sides' arithmetic stays in its own class, and each exposes its set. */
    public function testEachPluginsArithmeticAnswersWithItsOwnSet(): void
    {
        $rule = MinMaxRule::from(MinMaxRule::AT_PRODUCT, '4', '10', '2');
        self::assertTrue($rule->allowedSet()->known());
        self::assertTrue($rule->convertedSet()->known());
        $code = self::codeOf(self::SRC . '/Modules/Migration/Domain/MinMaxRule.php');
        // Min Max counts its multiples FROM ITS OWN MINIMUM, and that base is
        // passed explicitly rather than assumed — the one difference the whole
        // class exists to name.
        self::assertStringContainsString(
            'QuantitySet::of($this->min, $this->max, $this->min, $this->step)',
            $code
        );
    }

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
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }
}
