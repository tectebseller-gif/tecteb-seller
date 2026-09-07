<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Infrastructure\WordPress;

use Tecteb\Marketplace\Contracts\DependencyProbeInterface;

/**
 * Limited, guarded dependency detection (CORE-06, owner correction 6).
 * Every WooCommerce symbol is checked for existence first; nothing here can
 * fatal when WooCommerce is absent, and "HPOS enabled" is never coerced from
 * unknown to false.
 */
final class WpDependencyProbe implements DependencyProbeInterface
{
    public function woocommerceAvailable(): bool
    {
        return class_exists('WooCommerce', false);
    }

    public function woocommerceVersion(): ?string
    {
        if (!$this->woocommerceAvailable()) {
            return null;
        }
        if (defined('WC_VERSION')) {
            return (string) constant('WC_VERSION');
        }
        if (function_exists('WC')) {
            $wc = WC();
            if (is_object($wc) && isset($wc->version) && is_string($wc->version)) {
                return $wc->version;
            }
        }
        return null;
    }

    public function hposEnabled(): ?bool
    {
        if (!$this->woocommerceAvailable()) {
            return null;
        }
        $util = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';
        if (!class_exists($util, false) || !method_exists($util, 'custom_orders_table_usage_is_enabled')) {
            return null;
        }
        try {
            return (bool) call_user_func([$util, 'custom_orders_table_usage_is_enabled']);
        } catch (\Throwable) {
            return null;
        }
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function platformVersion(): ?string
    {
        $v = get_bloginfo('version');
        return is_string($v) && $v !== '' ? $v : null;
    }
}
