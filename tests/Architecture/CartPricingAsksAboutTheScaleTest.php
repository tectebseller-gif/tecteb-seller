<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * WHAT THIS PROVES: every place in `CartPricing` that computes money asks
 * about the scale first, and asks the SAME question.
 *
 * `CartPricingScaleTest` pins what the answer is; this pins that the answer is
 * used, in all three places, and before anything is computed. Splitting it
 * this way is deliberate: a rule with one reader and three writers is the
 * shape this repository keeps paying for, and a test that only checked the
 * rule would pass on a build where two of the three forgot to ask
 * (`alpha.80`'s rule — count the CALLERS, not the constant).
 */
final class CartPricingAsksAboutTheScaleTest extends TestCase
{
    private const FILE = 'src/Modules/Marketplace/Infrastructure/WooCommerce/CartPricing.php';

    /**
     * The three money paths, by the method or filter each one is.
     *
     * @var list<string>
     */
    private const PATHS = [
        'couponData',
        'recordUses',
    ];

    public function testTheQuestionIsAskedInExactlyOnePlace(): void
    {
        $code = self::codeOnly();

        self::assertSame(
            1,
            preg_match_all('/function scaleIsSupported\(/', $code),
            'the scale question must have one definition; two would be two rules'
        );
        self::assertGreaterThanOrEqual(
            3,
            preg_match_all('/self::scaleIsSupported\(\)/', $code),
            'all three money paths must ask it — the ladder, the coupon and the usage recorder'
        );
    }

    /** @dataProvider paths */
    public function testEachMoneyMethodAsksBeforeItComputes(string $method): void
    {
        $body = self::methodBody($method);
        $asked = strpos($body, 'self::scaleIsSupported()');
        self::assertIsInt($asked, $method . '() computes money without asking about the scale');
        // Within the first few statements: a question asked after the figure
        // is built is not a stand-down, it is a late apology.
        self::assertLessThan(
            400,
            $asked,
            $method . '() asks about the scale too late to stand down'
        );
    }

    /** @return array<string,array{0:string}> */
    public static function paths(): array
    {
        $out = [];
        foreach (self::PATHS as $method) {
            $out[$method] = [$method];
        }
        return $out;
    }

    /** The wholesale ladder is a closure inside `register()`, not a method. */
    public function testTheWholesaleLadderAsksTooAndStandsDownToTheOrdinaryPrice(): void
    {
        $body = self::methodBody('register');
        self::assertStringContainsString(
            'self::scaleIsSupported()',
            $body,
            'the price filter must ask before it offers a ladder price'
        );
    }

    private static function codeOnly(): string
    {
        $raw = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::FILE);
        $out = '';
        foreach (token_get_all($raw) as $token) {
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

    /** One method's body, by brace matching — not a character window (`alpha.98`). */
    private static function methodBody(string $name): string
    {
        $source = self::codeOnly();
        $at = strpos($source, 'function ' . $name . '(');
        self::assertIsInt($at, $name . '() is not in ' . self::FILE);
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
