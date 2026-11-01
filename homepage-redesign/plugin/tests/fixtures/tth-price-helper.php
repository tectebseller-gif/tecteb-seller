<?php
/**
 * Plugin Name: TTH — جانشینِ «استعلام قیمت» (فقط وردپرس یکبارمصرف محلی)
 *
 * تنظیم «استعلام قیمت» روی هر سایتی از همین فیلترهای استاندارد ووکامرس می‌گذرد؛ این فایل یکی از آن
 * تنظیم‌ها را شبیه‌سازی می‌کند تا آزمون ببیند صفحهٔ اصلی متنِ خودِ سایت را نشان می‌دهد، نه متنی ساختگی.
 * با tests/fixtures/prices.php inquiry نصب و با reset برداشته می‌شود.
 */

defined( 'ABSPATH' ) || exit;

if ( 'tth_disposable' !== DB_NAME ) {
	return;
}

foreach ( array( 'woocommerce_empty_price_html', 'woocommerce_variable_empty_price_html' ) as $tth_hook ) {
	add_filter(
		$tth_hook,
		static function ( $html ) {
			$text = (string) get_option( 'tth_price_inquiry', '' );
			return '' !== $text ? '<span class="price-on-request">' . esc_html( $text ) . '</span>' : $html;
		}
	);
}
