<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * ⛔ THE ROUTE WIRING, which the behavioural tests cannot reach.
 *
 * `StoreAndStaffFlowTest` calls `mayReadVendorOrders()` directly and measures
 * the decision — the right thing to measure, and silent about whether the
 * orders route is the caller. Pointing `permission_callback` back at
 * `mayReadVendor` reinstates the whole defect and leaves every one of those
 * tests green, because none of them goes through `register_rest_route()`.
 *
 * So this asserts the wiring from the source: `alpha.77`'s rule, «a guard no
 * behavioural test can reach is asserted in the source, and the mutation
 * moves to that suite».
 */
final class MoneyCarryingRoutesTakeTheMoneyPermissionTest extends TestCase
{
    private const CONTROLLER =
        'src/Modules/Operations/Infrastructure/Rest/MarketplaceController.php';

    /**
     * The two read routes do NOT share a callback.
     *
     * Measured on the `register()` body with braces matched, not on the file:
     * the method names also appear in their own declarations and in prose
     * below them (`alpha.22`, `alpha.77`).
     */
    public function testTheOrdersRouteDoesNotShareTheCatalogueCallback(): void
    {
        $register = self::methodBody('registerRoutes');

        self::assertStringContainsString(
            "'/vendors/(?P<vendor>\\d+)/orders'",
            $register,
            'the orders route is registered here'
        );
        self::assertSame(
            1,
            substr_count($register, "[\$this, 'mayReadVendorOrders']"),
            'the orders route takes the orders-and-money callback, exactly once'
        );
        self::assertSame(
            1,
            substr_count($register, "[\$this, 'mayReadVendor']"),
            'and the catalogue callback is used for the catalogue route only'
        );
    }

    /**
     * And the decision itself asks for BOTH areas.
     *
     * `mayRead()` refuses on the first pair it cannot satisfy, so a list with
     * one entry is a weaker gate — and the one that matters is `Finance`,
     * because the payload carries `commission_minor` and
     * `vendor_share_minor`. A behavioural test covers `Order` alone being
     * refused; nothing covers somebody dropping the `Order` line, since a
     * finance-only reader is refused by every preset this repository ships.
     */
    public function testTheOrdersPermissionAsksForBothAreas(): void
    {
        $body = self::methodBody('mayReadVendorOrders');

        self::assertStringContainsString('StaffArea::Order, StaffLevel::View', $body);
        self::assertStringContainsString('StaffArea::Finance, StaffLevel::View', $body);
        self::assertStringNotContainsString(
            'StaffArea::Product',
            $body,
            'the catalogue permission is not what guards order lines'
        );
    }

    /** The body between the opening brace of a method and its match. */
    private static function methodBody(string $method): string
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/' . self::CONTROLLER
        );
        $at = strpos($source, 'function ' . $method . '(');
        self::assertNotFalse($at, $method . '() exists');

        $open = strpos($source, '{', (int) $at);
        self::assertNotFalse($open, $method . '() has a body');

        $depth = 0;
        $length = strlen($source);
        for ($i = (int) $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, (int) $open, $i - (int) $open + 1);
                }
            }
        }
        self::fail($method . '() braces are balanced');
    }
}
