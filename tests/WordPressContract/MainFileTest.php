<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\WordPressContract;

use Tecteb\Marketplace\Core\Autoloader;
use TmcWpStubs\State;

/** CORE-01: the real main file, included once, under stubs. */
final class MainFileTest extends ContractTestCase
{
    public function testMainFileRegistersLifecycleHooksAndAutoloader(): void
    {
        $file = dirname(__DIR__, 2) . '/tecteb-marketplace-core.php';
        $header = file_get_contents($file);
        self::assertMatchesRegularExpression('/^\s*\*\s*Plugin Name:\s*Tecteb Marketplace Core$/m', $header);
        self::assertMatchesRegularExpression('/^\s*\*\s*Requires PHP:\s*8\.1$/m', $header);
        self::assertMatchesRegularExpression('/^\s*\*\s*Text Domain:\s*tecteb-marketplace-core$/m', $header);
        self::assertStringNotContainsString('Requires Plugins:', $header, 'WooCommerce absence must be handled by the plugin, not blocked by WordPress');
        self::assertStringNotContainsString('WC tested up to', $header, 'no untested compatibility claim');
        self::assertStringContainsString("if ( ! defined( 'ABSPATH' ) )", $header);
        self::assertLessThan(strpos($header, 'require_once'), strpos($header, "version_compare( PHP_VERSION, '8.1.0', '<' )"), 'PHP check precedes any require');

        require $file;

        // Pinning one literal version here meant editing this test on every
        // delivery, which tests nothing — and it cost something real: the
        // alpha.18 bump left this suite red and the package went out anyway,
        // because only the packaging suite was re-run after the bump. What
        // must hold is that the header and the constant name the SAME
        // version, and that it is still the provisional alpha line (F-01).
        preg_match('/^\s*\*\s*Version:\s*([0-9A-Za-z.\-+]+)/m', $header, $declared);
        self::assertMatchesRegularExpression('/^0\.1\.0-alpha\.\d+$/', $declared[1] ?? '');
        self::assertSame($declared[1], TMC_PLUGIN_VERSION, 'the header and the constant must agree');
        self::assertSame($file, TMC_PLUGIN_FILE);
        self::assertTrue(Autoloader::isRegistered());
        $basename = 'tecteb-seller/tecteb-marketplace-core.php';
        self::assertArrayHasKey('activate_' . $basename, State::$hooks);
        self::assertArrayHasKey('deactivate_' . $basename, State::$hooks);
        self::assertArrayHasKey('plugins_loaded', State::$hooks);
        self::assertArrayHasKey('before_woocommerce_init', State::$hooks);
    }
}
