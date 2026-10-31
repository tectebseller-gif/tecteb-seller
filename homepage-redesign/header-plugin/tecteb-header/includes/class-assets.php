<?php
/**
 * CSS و JS ِ هدر — از فایل‌های نمونهٔ تأییدشده:
 *   assets/js/header.js  = header/shared/header.js (بایت‌به‌بایت)
 *   assets/css/header.css = tools/harden-css.py روی header/shared/header-base.css + header/b/header-b.css:
 *     همان قاعده‌ها با پیشوند `#tth-root` و یک لایهٔ ایزوله، تا CSS ِ سراسری سایت (Kit ِ Elementor،
 *     قالب، Custom CSS ِ قالب‌ها) روی هدر اثر نگذارد.
 * (tools/build.sh هر دو را پیش از بسته‌بندی می‌سنجد.)
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
		wp_enqueue_style( 'tecteb-header-base', $css . 'header.css', array(), $v );
		wp_enqueue_style( 'tecteb-header', $css . 'tecteb-header.css', array( 'tecteb-header-base' ), $v );
		if ( 'plugin' === $s['font'] ) {
			// درون‌خطی و با نشانی مطلق: اگر WP Rocket (یا هر بهینه‌ساز) فایل‌های CSS را کوچک و به
			// پوشهٔ کش منتقل کند، نشانی نسبی ِ فونت نمی‌شکند.
			wp_add_inline_style( 'tecteb-header', self::font_css() );
		}
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

	/** فونت Vazirmatn ِ طرح (OFL-1.1، assets/fonts/OFL.txt). */
	public static function font_css(): string {
		$url = esc_url_raw( TECTEB_HEADER_URL . 'assets/fonts/Vazirmatn-wght.woff2' );
		return '@font-face{font-family:"TTH Vazirmatn";src:url("' . $url . '") format("woff2");font-weight:100 900;font-display:swap}'
			. '.tecteb-header-v1{--tth-font:"TTH Vazirmatn",Tahoma,system-ui,sans-serif}';
	}

	/** فقط برای آزمون‌ها. */
	public static function reset(): void {
		self::$done = false;
	}
}
