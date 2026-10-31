<?php
/**
 * Plugin Name: TTHH — کمک‌های آزمون محلی (فقط وردپرس یکبارمصرف)
 *
 * tthh_minified: CSS ِ هدر با نسخهٔ کوچک‌شده با matthiasmullie/minify (همان کتابخانه‌ای که
 * «Minify CSS» ِ WP Rocket به کار می‌برد) جایگزین می‌شود، از wp-content/uploads/tthh-min/.
 */

defined( 'ABSPATH' ) || exit;

if ( 'tth_disposable' !== DB_NAME ) {
	return;
}

add_filter(
	'style_loader_src',
	static function ( $src, $handle ) {
		$map = array(
			'tecteb-header-base' => 'header.css',
			'tecteb-header'      => 'tecteb-header.css',
		);
		if ( get_option( 'tthh_minified' ) && isset( $map[ $handle ] ) ) {
			return content_url( 'uploads/tthh-min/' . $map[ $handle ] );
		}
		return $src;
	},
	10,
	2
);

// بدلِ پاک‌سازی WP Rocket: فقط شمارش (هیچ کش واقعی‌ای اینجا نیست).
if ( ! function_exists( 'rocket_clean_domain' ) ) {
	function rocket_clean_domain() {
		$GLOBALS['tthh_rocket'] = ( $GLOBALS['tthh_rocket'] ?? 0 ) + 1;
		update_option( 'tthh_rocket_calls', (int) get_option( 'tthh_rocket_calls', 0 ) + 1, false );
	}
}

// CSS ِ سراسری «بدخواه» (Kit ِ Elementor + frontend.min.css ِ واقعی Elementor 4.2.3 + Custom CSS ِ رایج)،
// پس از CSS ِ هدر (اولویت ۹۹۹) — بدترین ترتیب. فقط وقتی tthh_hostile روشن است.
add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( get_option( 'tthh_hostile' ) ) {
			wp_enqueue_style( 'tthh-elementor-frontend', content_url( 'uploads/tthh-hostile/elementor-frontend.min.css' ), array(), '4.2.3' );
			wp_enqueue_style( 'tthh-hostile', content_url( 'uploads/tthh-hostile/hostile-global.css' ), array( 'tthh-elementor-frontend' ), (string) filemtime( WP_CONTENT_DIR . '/uploads/tthh-hostile/hostile-global.css' ) );
		}
	},
	999
);
add_filter(
	'body_class',
	static function ( $c ) {
		if ( get_option( 'tthh_hostile' ) ) {
			$c[] = 'elementor-default';
			$c[] = 'elementor-kit-9';
		}
		return $c;
	}
);
