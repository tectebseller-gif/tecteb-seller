<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The unit suite must run with no WordPress symbol in existence. */
final class UnitSuiteGuardTest extends TestCase
{
    public function testNoWordPressFunctionsAreDefined(): void
    {
        foreach (['add_action', 'get_option', 'esc_html', '__', 'current_user_can', 'wp_die', 'register_rest_route'] as $fn) {
            self::assertFalse(function_exists($fn), "WordPress function {$fn} must not exist in the unit suite");
        }
        self::assertFalse(class_exists('WP_Error', false));
        self::assertFalse(class_exists('WooCommerce', false));
    }
}
