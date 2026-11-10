<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit\Modules\Finance;

use PHPUnit\Framework\TestCase;
use Tecteb\Marketplace\Modules\Finance\Application\ForeignDiscountScope;

/**
 * The apply-time rule, as a pure function — no cart, no WooCommerce.
 *
 * The behavioural half, which proves the rule is actually WIRED to the hook
 * WooCommerce consults before applying a code, is
 * `OrderFlowTest::testTheNativeCartCouponIsRefusedBeforeItIsApplied` and the
 * tests beside it. A green test here says nothing about that (`alpha.80`).
 */
final class ForeignDiscountScopeTest extends TestCase
{
    public function testAnEmptyBasketAllowsTheCode(): void
    {
        self::assertTrue(ForeignDiscountScope::check([])['ok']);
    }

    public function testTheHostShopsOwnGoodsAreNotThisRulesBusiness(): void
    {
        $verdict = ForeignDiscountScope::check([
            ['vendor' => null, 'owned' => false, 'touches' => true],
            ['vendor' => 0, 'owned' => false, 'touches' => true],
        ]);
        self::assertTrue($verdict['ok']);
        self::assertSame(ForeignDiscountScope::OK, $verdict['reason']);
    }

    public function testAForeignCodeReachingAVendorLineIsRefusedAndNamesTheLine(): void
    {
        $verdict = ForeignDiscountScope::check([
            ['vendor' => 102, 'owned' => false, 'touches' => true, 'name' => 'محصول آزمایشی آلفا ۴۴'],
        ]);
        self::assertFalse($verdict['ok']);
        self::assertSame(ForeignDiscountScope::FOREIGN_CODE, $verdict['reason']);
        self::assertSame('محصول آزمایشی آلفا ۴۴', $verdict['product']);
        self::assertSame(102, $verdict['vendor']);
    }

    public function testAVendorsOwnCodeOnTheirOwnLineIsAllowed(): void
    {
        $verdict = ForeignDiscountScope::check([
            ['vendor' => 102, 'owned' => true, 'touches' => true],
        ]);
        self::assertTrue($verdict['ok']);
    }

    /**
     * §4-الف: «بدون خواندن تصمیم جاری، همه کوپن‌های ووکامرس را ممنوع نکن.»
     * A code scoped to the host shop's own ids does not reach a vendor line.
     */
    public function testACodeScopedAwayFromTheVendorLineIsAllowed(): void
    {
        $verdict = ForeignDiscountScope::check([
            ['vendor' => 102, 'owned' => false, 'touches' => false],
        ]);
        self::assertTrue($verdict['ok']);
        self::assertSame(ForeignDiscountScope::OK, $verdict['reason']);
    }

    public function testNotKnowingWhetherItReachesTheLineRefuses(): void
    {
        $verdict = ForeignDiscountScope::check([
            ['vendor' => 102, 'owned' => false, 'touches' => null],
        ]);
        self::assertFalse($verdict['ok']);
        self::assertSame(ForeignDiscountScope::FOREIGN_CODE, $verdict['reason']);
    }

    /**
     * The mixed basket of the owner's own staging test: host goods plus one
     * marketplace line. A cart-level code reaches everything, so it refuses —
     * and the refusal names the marketplace line, not the host one.
     */
    public function testAMixedBasketRefusesOnTheVendorLineAndNamesIt(): void
    {
        $verdict = ForeignDiscountScope::check([
            ['vendor' => null, 'owned' => false, 'touches' => true, 'name' => 'کالای خود فروشگاه'],
            ['vendor' => 128, 'owned' => false, 'touches' => true, 'name' => 'آلفا ۴۴'],
        ]);
        self::assertFalse($verdict['ok']);
        self::assertSame('آلفا ۴۴', $verdict['product']);
        self::assertSame(128, $verdict['vendor']);
    }

    /**
     * Two vendors, the code belongs to the first. The second is still somebody
     * else's money, so a basket-wide code is refused on the second line —
     * `alpha.46`, the conservative direction when a foreign code sits beside a
     * vendor's own.
     */
    public function testAVendorsOwnCodeDoesNotLicenceItAgainstAnotherVendorsLine(): void
    {
        $verdict = ForeignDiscountScope::check([
            ['vendor' => 102, 'owned' => true, 'touches' => true],
            ['vendor' => 128, 'owned' => false, 'touches' => true],
        ]);
        self::assertFalse($verdict['ok']);
        self::assertSame(128, $verdict['vendor']);
    }

    public function testAnUnnamedLineStillRefusesWithAnEmptyName(): void
    {
        $verdict = ForeignDiscountScope::check([
            ['vendor' => 102, 'owned' => false, 'touches' => true],
        ]);
        self::assertFalse($verdict['ok']);
        self::assertSame('', $verdict['product']);
    }
}
