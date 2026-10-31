<?php
/**
 * CSS و JS ِ هدر — همان فایل‌های نمونهٔ تأییدشده، بی هیچ تغییر:
 *   assets/css/header-base.css = header/shared/header-base.css
 *   assets/css/header-b.css    = header/b/header-b.css
 *   assets/js/header.js        = header/shared/header.js
 * (tools/build.sh برابری بایت‌به‌بایت را پیش از بسته‌بندی می‌سنجد.)
 *
 * در head بارگذاری می‌شوند وقتی جایگزینی سراسری فعال است یا مدیر «بارگذاری در همهٔ
 * صفحه‌ها» را روشن کرده؛ وگرنه shortcode آن‌ها را دیر صف می‌کند و وردپرس در پانویس چاپشان
 * می‌کند (یک لحظه هدر بی‌سبک دیده می‌شود — README).
 *
 * @package Tecteb\Header
 */

namespace Tecteb\Header;

defined( 'ABSPATH' ) || exit;

final class Assets {

	private static bool $done = false;

	public static function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_head' ), 20 );
	}

	public static function in_head(): bool {
		/**
		 * آیا CSS/JS ِ هدر در head همهٔ صفحه‌های عمومی بارگذاری شود؟
		 *
		 * @param bool $load
		 */
		return (bool) apply_filters( 'tecteb_header_should_enqueue', Settings::get()['assets_everywhere'] || Replace::active() );
	}

	public static function maybe_head(): void {
		if ( self::in_head() ) {
			self::enqueue();
		}
	}

	public static function enqueue(): void {
		if ( self::$done ) {
			return;
		}
		self::$done = true;
		$s          = Settings::get();
		$v          = TECTEB_HEADER_VERSION;
		$css        = TECTEB_HEADER_URL . 'assets/css/';
		$deps       = array();
		if ( 'plugin' === $s['font'] ) {
			wp_enqueue_style( 'tecteb-header-font', $css . 'tecteb-header-font.css', array(), $v );
			$deps[] = 'tecteb-header-font';
		}
		wp_enqueue_style( 'tecteb-header-base', $css . 'header-base.css', $deps, $v );
		wp_enqueue_style( 'tecteb-header-b', $css . 'header-b.css', array( 'tecteb-header-base' ), $v );
		wp_enqueue_style( 'tecteb-header', $css . 'tecteb-header.css', array( 'tecteb-header-b' ), $v );
		wp_enqueue_script(
			'tecteb-header',
			TECTEB_HEADER_URL . 'assets/js/header.js',
			array(),
			$v,
			array(
				'in_footer' => false,
				'strategy'  => 'defer',
			)
		);
		if ( $s['cart_fragments'] && wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
			wp_enqueue_script( 'wc-cart-fragments' );
		}
	}

	/** فقط برای آزمون‌ها. */
	public static function reset(): void {
		self::$done = false;
	}
}
