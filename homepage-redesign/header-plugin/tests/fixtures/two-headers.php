<?php
/**
 * فیکسچر Staging ِ گزارش‌شده: دو قالب هدر منتشرشده با «کل سایت» (۲۶۵۲ و ۲۰)، ۲۰ اول در ترتیب شرط‌های
 * Elementor، جایگزینی خاموش، فقط ۲۶۵۲ انتخاب‌شده. فقط روی پایگاه دادهٔ یکبارمصرف.
 *
 *   wp eval-file tests/fixtures/two-headers.php on|off
 *
 * @package Tecteb\Header
 */

if ( 'tth_disposable' !== DB_NAME || ! str_starts_with( home_url(), 'http://127.0.0.1' ) ) {
	fwrite( STDERR, "refused: not the disposable local site\n" );
	exit( 1 );
}
$verb = $args[0] ?? 'on';
$c    = get_option( 'elementor_pro_theme_builder_conditions', array() );
if ( 'on' === $verb ) {
	if ( ! get_post( 20 ) ) {
		wp_insert_post( array( 'import_id' => 20, 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_title' => 'Header (blue bar)' ) );
		update_post_meta( 20, '_elementor_template_type', 'header' );
		update_post_meta( 20, '_elementor_conditions', array( 'include/general' ) );
		update_post_meta( 20, '_tthh_fixture', 1 );
	}
	$c['header'] = array( 20 => array( 'include/general' ) ) + (array) ( $c['header'] ?? array() );
	update_option( 'elementor_pro_theme_builder_conditions', $c );
	$s = get_option( 'tecteb_header_settings', array() );
	update_option( 'tecteb_header_settings', array_merge( (array) $s, array( '_tab' => 'replace', 'replace' => false, 'replace_templates' => array( 2652 ) ) ) );
	echo "on\n";
} else {
	unset( $c['header'][20] );
	update_option( 'elementor_pro_theme_builder_conditions', $c );
	if ( get_post( 20 ) && get_post_meta( 20, '_tthh_fixture', true ) ) {
		wp_delete_post( 20, true );
	}
	echo "off\n";
}
