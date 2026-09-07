<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tecteb\Marketplace\Infrastructure\WordPress\WpDependencyProbe;

/** Correction 6: guarded, limited detection; never fatal without WooCommerce; unknown stays null. */
final class WpDependencyProbeTest extends ContractTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDetectionIsGuardedStepByStep(): void
    {
        $probe = new WpDependencyProbe();
        self::assertFalse($probe->woocommerceAvailable());
        self::assertNull($probe->woocommerceVersion());
        self::assertNull($probe->hposEnabled());
        self::assertSame(PHP_VERSION, $probe->phpVersion());

        eval('class WooCommerce {}');
        self::assertTrue($probe->woocommerceAvailable());
        self::assertNull($probe->woocommerceVersion(), 'no WC_VERSION constant → unknown, not a guess');
        self::assertNull($probe->hposEnabled(), 'no OrderUtil → unknown, not false');

        define('WC_VERSION', '11.0.1');
        self::assertSame('11.0.1', $probe->woocommerceVersion());

        eval('namespace Automattic\\WooCommerce\\Utilities; class OrderUtil { public static function custom_orders_table_usage_is_enabled(): bool { return true; } }');
        self::assertTrue($probe->hposEnabled());
    }
}
