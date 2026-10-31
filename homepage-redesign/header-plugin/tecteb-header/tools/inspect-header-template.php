<?php
/**
 * بررسی فقط‌خواندنیِ قالب هدر Elementor پیش از جایگزینی — برای WP-CLI.
 *
 *   wp eval-file wp-content/plugins/tecteb-header/tools/inspect-header-template.php          # ۲۶۵۲
 *   wp eval-file wp-content/plugins/tecteb-header/tools/inspect-header-template.php 2652 json
 *
 * هیچ چیز نمی‌نویسد (نه option، نه متا، نه فایل، نه کش) و افزونه لازم نیست فعال باشد.
 * همان گزارش در پیشخوان: تک‌طب ← مدیریت هدر ← جایگزینی هدر ← «بررسی فقط‌خواندنی».
 *
 * @package Tecteb\Header
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

if ( ! class_exists( '\Tecteb\Header\Inspect' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-elementor.php';
	require_once dirname( __DIR__ ) . '/includes/class-inspect.php';
}

$tecteb_header_args = isset( $args ) && is_array( $args ) ? $args : array();
$tecteb_header_id   = isset( $tecteb_header_args[0] ) ? absint( $tecteb_header_args[0] ) : 2652;
$tecteb_header_r    = \Tecteb\Header\Inspect::report( $tecteb_header_id ?: 2652 );

if ( in_array( 'json', $tecteb_header_args, true ) ) {
	echo wp_json_encode( $tecteb_header_r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- خروجی ترمینال.
} else {
	echo \Tecteb\Header\Inspect::text( $tecteb_header_r ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- خروجی ترمینال.
}
