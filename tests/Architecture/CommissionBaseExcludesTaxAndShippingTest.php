<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The commission base is the line's own money, and nothing else is in it.
 *
 * ⛔ **WHY THIS IS A SOURCE TEST AND NOT A BEHAVIOURAL ONE.** «مالیات» has a
 * behavioural guard already — `LedgerTest::testOnePaidItemBecomesFourBalanced
 * Lines` pays 900,000 with 81,000 of tax and asserts the commission is ten
 * per cent of the base rather than of the sum. «حمل» has none, and CANNOT
 * have one: shipping is an ORDER-level cost that never reaches a line, so the
 * guarantee is that `CaptureOrder` has no way to hear about it. A guarantee
 * made by absence is invisible to every behavioural suite (`alpha.9`), and
 * the way it breaks is somebody adding a helpful key to the array years from
 * now — which is exactly what this would fail on.
 */
final class CommissionBaseExcludesTaxAndShippingTest extends TestCase
{
    private const FILE = 'src/Modules/Order/Application/CaptureOrder.php';

    /** The whole file with its comments blanked: prose is not code (`alpha.22`). */
    private static function code(): string
    {
        $path = dirname(__DIR__, 2) . '/' . self::FILE;
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            $out .= is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat(' ', strlen($token[1]))
                    : $token[1])
                : $token;
        }
        return $out;
    }

    /**
     * Every key the capture reads off one line, by name.
     *
     * Pinned as a SET rather than a count, so the failure says which key
     * appeared — a number would only say that something changed.
     */
    public function testTheCaptureReadsExactlyTheseKeysOffALine(): void
    {
        preg_match_all("/\\\$line\\['([a-z_]+)'\\]/", self::code(), $m);
        $keys = array_values(array_unique($m[1]));
        sort($keys);
        self::assertSame(
            [
                'currency', 'exponent', 'line_tax_minor', 'line_total_minor',
                'order_item_id', 'quantity', 'sku', 'title', 'unit_error',
                'variation_id', 'wc_product_id',
            ],
            $keys,
            'a new key here changes what the commission is taken on'
        );
    }

    /** And none of them is shipping, under any of the names it goes by. */
    public function testNothingShippingShapedReachesTheCommission(): void
    {
        $code = self::code();
        foreach ([
            'shipping_total', 'shipping_minor', 'shipping_tax', 'line_shipping',
            'get_shipping_total', 'shipping_cost', 'freight',
        ] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $code,
                "shipping must not reach the line: «{$needle}»"
            );
        }
    }

    /**
     * The base is the line total ALONE, and the tax travels beside it.
     *
     * Both halves matter: `$base` holding `line_total_minor` is what makes
     * the commission exclude the tax, and the tax being a SEPARATE `Money`
     * is what lets the accrual balance it into `tax_collected` instead of
     * losing it.
     */
    public function testTheBaseIsTheLineTotalAndTheTaxIsItsOwnMoney(): void
    {
        $code = self::code();
        // ⛔ THE WHOLE STATEMENT, not its first half. The first version of
        // this matched a PREFIX, so `… ?? 0) + (int) ($line['line_tax_minor']
        // ?? 0), …` still matched it and the tax could be folded into the
        // base with the test green. A regex that stops before the semicolon
        // is asserting where a statement starts, never what it says.
        self::assertStringContainsString(
            "\$base = Money::of((int) (\$line['line_total_minor'] ?? 0), \$lineCurrency, \$lineExponent);",
            $code,
            'the commission base must be the line total on its own, and nothing added to it'
        );
        self::assertMatchesRegularExpression(
            "/\\\$tax = Money::of\\(\\(int\\) \\(\\\$line\\['line_tax_minor'\\]/",
            $code,
            'and the tax must be its own figure, not added to the base'
        );
        // The shape that would break it, spelled out so the failure is plain.
        self::assertStringNotContainsString('$base->add($tax)', $code);
        self::assertStringNotContainsString('$base = $base->add', $code);
    }
}
