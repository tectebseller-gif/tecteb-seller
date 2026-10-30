<?php
/**
 * سیم‌کشی: shortcode، دارایی‌ها، قالب صفحه، کش و صفحهٔ تنظیمات.
 *
 * مرز این افزونه: فقط نمایش صفحهٔ اصلی. هیچ منطق استعلام، فروشنده، سفارش یا
 * بازارگاه اینجا نیست و هیچ کلاس، تابع یا جدولی از دکان یا Tecteb Marketplace
 * صدا زده نمی‌شود؛ دکمه‌ها فقط به مقصدی می‌روند که مدیر تعیین کرده.
 *
 * @package Tecteb\Homepage
 */

namespace Tecteb\Homepage;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public const SHORTCODE = 'tecteb_home';

	public static function boot(): void {
		add_action( 'init', array( self::class, 'textdomain' ) );

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_products' ) ) {
			add_action( 'admin_notices', array( self::class, 'woocommerce_missing' ) );
			add_shortcode( self::SHORTCODE, '__return_empty_string' );
			return;
		}

		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		Assets::register_hooks();
		PageTemplate::register_hooks();
		Cache::register_hooks();
		if ( is_admin() ) {
			Admin::register_hooks();
		}
	}

	public static function textdomain(): void {
		load_plugin_textdomain( 'tecteb-homepage', false, dirname( plugin_basename( TECTEB_HOME_FILE ) ) . '/languages' );
	}

	/**
	 * [tecteb_home] — هر پنج بخش. [tecteb_home sections="hero,products"] — فقط همان‌ها، به همان ترتیب.
	 *
	 * @param array|string $atts
	 */
	public static function shortcode( $atts ): string {
		$atts     = shortcode_atts( array( 'sections' => implode( ',', Renderer::SECTIONS ) ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$sections = array_values( array_intersect( array_map( 'trim', explode( ',', strtolower( (string) $atts['sections'] ) ) ), Renderer::SECTIONS ) );
		$html     = Renderer::render( $sections );
		if ( '' !== $html ) {
			Assets::enqueue();
		}
		return $html;
	}

	public static function woocommerce_missing(): void {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'افزونهٔ «صفحهٔ اصلی تک‌طب» به ووکامرس نیاز دارد و تا فعال‌شدن ووکامرس چیزی نمایش نمی‌دهد.', 'tecteb-homepage' ) . '</p></div>';
		}
	}
}
