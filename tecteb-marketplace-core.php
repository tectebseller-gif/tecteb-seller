<?php
/**
 * Plugin Name:       Tecteb Marketplace Core
 * Description:       هسته بازارگاه تک‌طب — زیرساخت و چهار صفحه مدیریت. نسخه آزمایشی (Alpha)؛ گیت‌های پذیرش روی WordPress یکبارمصرف قبول شدند، اما همین نسخه روی هیچ سایت واقعی نصب نشده است.
 * Version:           0.1.0-alpha.3
 * Requires at least: 6.3
 * Requires PHP:      8.1
 * Author:            Tecteb
 * Text Domain:       tecteb-marketplace-core
 * Domain Path:       /languages
 * License:           Proprietary
 */

/*
 * This file must stay parseable by the oldest PHP WordPress itself still
 * loads (7.2): no typed properties, enums, match, readonly or named
 * arguments here. Everything modern lives under src/ and is only required
 * after the PHP version check below (CORE-01).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TMC_PLUGIN_VERSION' ) ) {
	// TODO(F-01): placeholder taken from the prompt's empty-repository rule.
	// It is NOT a lineage decision; see docs/decision-log.md §4 and F-01.
	define( 'TMC_PLUGIN_VERSION', '0.1.0-alpha.3' );
}
if ( ! defined( 'TMC_PLUGIN_FILE' ) ) {
	define( 'TMC_PLUGIN_FILE', __FILE__ );
}

if ( version_compare( PHP_VERSION, '8.1.0', '<' ) ) {
	if ( ! function_exists( 'tmc_php_version_notice' ) ) {
		/**
		 * Persian notice when PHP is too old. Nothing else is loaded.
		 */
		function tmc_php_version_notice() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>'
				. esc_html( sprintf( 'بازارگاه تک‌طب به PHP 8.1 یا بالاتر نیاز دارد؛ نسخه فعلی %s است. افزونه راه‌اندازی نشد.', PHP_VERSION ) )
				. '</p></div>';
		}
	}
	add_action( 'admin_notices', 'tmc_php_version_notice' );
	return;
}

require_once __DIR__ . '/src/Core/Autoloader.php';
\Tecteb\Marketplace\Core\Autoloader::register( __DIR__ . '/src' );
\Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::init( __FILE__, TMC_PLUGIN_VERSION );
