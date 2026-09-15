<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

/**
 * Declares HPOS compatibility in before_woocommerce_init (CORE-10).
 * The declaration is a statement of intent, not a test result: the
 * compatibility matrix still reports HPOS modes as Not Run until executed.
 */
final class HposDeclaration
{
    public static function register(string $mainFile): void
    {
        add_action('before_woocommerce_init', static function () use ($mainFile): void {
            $util = 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
            if (class_exists($util) && method_exists($util, 'declare_compatibility')) {
                call_user_func([$util, 'declare_compatibility'], 'custom_order_tables', $mainFile, true);
            }
        });
    }
}
