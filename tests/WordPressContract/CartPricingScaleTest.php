<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Marketplace\Infrastructure\WooCommerce\CartPricing;
use TmcWpStubs\State;

/**
 * WHAT THIS PROVES: the cart's own money rules stand down on a currency whose
 * scale this plugin cannot express, instead of computing a wrong number.
 *
 * ⛔ **This test exists because a mutation survived.** Forcing
 * `scaleIsSupported()` to `true` left all four suites green — because
 * `CartPricing` had NO TEST AT ALL, and the stub shop reports zero decimals,
 * so the refusal branch was never taken by anything. «No suite noticed» is
 * byte-for-byte what an absent guard looks like.
 *
 * **Why the method became public rather than being tested through
 * `register()`.** Reaching it from the filters needs a container, a cart, a
 * coupon and a wholesale ladder — a graph, not a rule — and a test that boots
 * all of that measures the graph. `alpha.100` met this exact wall and the
 * answer was the same: move the decision somewhere its branch is reachable,
 * then pin the call sites separately
 * (`CartPricingAsksAboutTheScaleTest`). The method is still the only place
 * the question is asked.
 *
 * **What «cannot express» means here, measured rather than asserted.** On a
 * two-decimal shop `WooCommerceProjector` writes `set_regular_price()` with
 * the major unit while this plugin's own figures are minor units, and the two
 * disagree by a factor of a hundred. Nothing in the cart path carries a unit,
 * so the honest answer is to do nothing: WooCommerce's ordinary price applies,
 * the marketplace coupon is not offered, and the host shop's own prices and
 * coupons are untouched either way.
 */
final class CartPricingScaleTest extends TestCase
{
    private int $decimals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->decimals = State::$priceDecimals;
    }

    protected function tearDown(): void
    {
        // A fixture that spends a global must hand it back, or the next test
        // in this process answers a question about this one (`alpha.33`).
        State::$priceDecimals = $this->decimals;
        parent::tearDown();
    }

    public function testAZeroDecimalShopIsSupported(): void
    {
        State::$priceDecimals = 0;
        self::assertTrue(
            CartPricing::scaleIsSupported(),
            'ریال and تومان have no minor unit; this is the shop this plugin was built for'
        );
    }

    /** @dataProvider unsupportedScales */
    public function testAShopWithADecimalCurrencyIsNotSupported(int $decimals): void
    {
        State::$priceDecimals = $decimals;
        self::assertFalse(
            CartPricing::scaleIsSupported(),
            $decimals . ' decimals: the ladder and the coupon must stand down rather than be a hundredfold out'
        );
    }

    /** @return array<string,array{0:int}> */
    public static function unsupportedScales(): array
    {
        return [
            'two decimals, the common case' => [2],
            'three decimals' => [3],
            // One decimal is not a currency anybody runs, and that is exactly
            // why it is here: the rule is «anything but zero», not «not two».
            'one decimal' => [1],
        ];
    }
}
