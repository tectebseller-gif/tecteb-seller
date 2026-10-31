<?php
/**
 * HTML ِ هدر B. یک نسخه در هر درخواست: شناسه‌های کشو و Mega Menu (`tth-drawer`،
 * `tth-mega`، …) یکتا هستند، پس دو هدر روی یک صفحه هم دو هدر دیده می‌شود و هم
 * دسترس‌پذیری را می‌شکند. فراخوانی دوم فقط یک توضیح HTML برمی‌گرداند.
 *
 * هر خروجی در قالب escape می‌شود؛ این کلاس فقط «مدل نما» را می‌سازد.
 *
 * @package Tecteb\Header
 */

namespace Tecteb\Header;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	private static bool $rendered = false;

	public static function register_hooks(): void {
		add_shortcode( 'tecteb_header', array( self::class, 'shortcode' ) );
	}

	/** @param array<string,string>|string $atts */
	public static function shortcode( $atts = array() ): string {
		Assets::enqueue();
		return self::render( 'shortcode' );
	}

	public static function rendered(): bool {
		return self::$rendered;
	}

	/** فقط Replace و آزمون‌ها: وضعیت «چاپ شد» را برمی‌گرداند/می‌گذارد. */
	public static function set_rendered( bool $value ): void {
		self::$rendered = $value;
	}

	/** @param string $context shortcode | replace */
	public static function render( string $context ): string {
		if ( self::$rendered ) {
			return "\n<!-- tecteb-header: already printed on this page -->\n";
		}
		self::$rendered = true;
		$vm             = self::view( $context );
		ob_start();
		( static function ( array $vm ): void {
			include TECTEB_HEADER_DIR . 'templates/header.php';
		} )( $vm );
		return (string) ob_get_clean();
	}

	/** @return array<string,mixed> */
	public static function view( string $context ): array {
		$s       = Settings::get();
		$classes = array( 'tecteb-header-v1', 'tecteb-header-v1--b' );
		if ( is_front_page() && $s['home_search_toggle'] && 'woocommerce' === $s['search_mode'] ) {
			$classes[] = 'tecteb-header-v1--home';
		}
		$logo_url  = $s['logo_id'] && Settings::is_icon( (int) $s['logo_id'] ) ? (string) wp_get_attachment_image_url( (int) $s['logo_id'], 'medium' ) : '';
		$logo_text = (string) $s['logo_text'];

		return array(
			'context'   => $context,
			'classes'   => $classes,
			// تنها در جایگزینی سراسری، هدر خودش نقش «banner» ِ صفحه است. در shortcode ممکن
			// است داخل <header> ِ Elementor باشد و دو banner نباید باشد.
			'banner'    => 'replace' === $context,
			'skip'      => (string) apply_filters( 'tecteb_header_skip_target', '' ),
			'home'      => home_url( '/' ),
			'logo'      => array(
				'src'   => '' !== $logo_url ? $logo_url : TECTEB_HEADER_URL . 'assets/img/tecteb-logo-mark-96.png',
				'text'  => $logo_text,
				'label' => ( '' !== $logo_text ? $logo_text : (string) get_bloginfo( 'name' ) ) . ' — ' . __( 'صفحهٔ اصلی', 'tecteb-header' ),
			),
			'search'    => array(
				'mode'      => $s['search_mode'],
				'shortcode' => $s['search_shortcode'],
				'action'    => home_url( '/' ),
				'value'     => get_search_query(),
			),
			'account'   => Account::account(),
			'cart'      => Account::cart(),
			'wishlist'  => Account::wishlist(),
			'nav'       => Menu::nav_items(),
			'cats'      => Menu::categories(),
			'shop'      => Menu::shop_url(),
			'supplier'  => '' !== $s['supplier_url'] ? Account::href( $s['supplier_url'] ) : '',
			'sprite'    => TECTEB_HEADER_DIR . 'templates/sprite.svg',
		);
	}

	/** پیوند سبد، هم برای چاپ اولیه و هم برای cart fragments ِ ووکامرس. */
	public static function cart_link( array $cart ): string {
		$count = $cart['count'];
		$html  = '<a class="tth-tool tth-tool--count tth-tool--cart" href="' . esc_url( $cart['url'] ) . '">'
			. '<svg class="tth-i" aria-hidden="true"><use href="#tth-i-bag"/></svg>';
		if ( null !== $count && $count > 0 ) {
			$html .= '<span class="tth-sr">' . esc_html__( 'سبد خرید،', 'tecteb-header' ) . '</span>'
				. '<span class="tth-badge" data-tth-count>' . esc_html( Account::fa( $count ) ) . '</span>'
				. '<span class="tth-sr">' . esc_html__( 'کالا', 'tecteb-header' ) . '</span>';
		} else {
			$html .= '<span class="tth-sr">' . esc_html__( 'سبد خرید', 'tecteb-header' ) . '</span>';
		}
		return $html . '</a>';
	}

	/**
	 * @param array<string,string> $fragments
	 * @return array<string,string>
	 */
	public static function cart_fragment( $fragments ): array {
		$fragments = is_array( $fragments ) ? $fragments : array();
		$fragments['.tecteb-header-v1 .tth-tool--cart'] = self::cart_link( Account::cart() );
		return $fragments;
	}
}
