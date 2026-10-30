<?php
/**
 * از تنظیمات و ووکامرس، HTML ِ بخش‌های Concept C را می‌سازد.
 *
 * قاعدهٔ نمایش یک جا نوشته شده — plan() — و هم صفحه و هم صفحهٔ تنظیمات از آن
 * می‌پرسند، تا «چرا این بخش دیده نمی‌شود» جوابی داشته باشد که با خودِ صفحه
 * یکی است. قاعده: هر دکمه‌ای که مقصد تأییدشده ندارد چاپ نمی‌شود، و هر بخشی که
 * محتوای تأییدشده ندارد کامل کنار می‌رود — هیچ مسیر موقت، فرم ساختگی یا
 * خدمت تأییدنشده‌ای روی صفحه نمی‌آید. سه مسیر اصلی (فروشگاه، تعمیرات، همکاری)
 * کارت‌های Hero اند؛ بخش خدمات عمومی از ۰٫۲٫۰ نیست.
 *
 * @package Tecteb\Homepage
 */

namespace Tecteb\Homepage;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	public const SECTIONS = array( 'hero', 'categories', 'products', 'supplier' );

	/** سه مسیر اصلی صفحه، به همین ترتیب؛ هر کدام فقط با مقصد معتبر چاپ می‌شود. */
	public const ROUTES = array( 'shop', 'repair', 'supplier' );

	private static bool $sprite_printed = false;

	/**
	 * وضعیت هر بخش و هر دکمه، با دلیل. بدون HTML.
	 *
	 * @return array{sections:array<string,array{show:bool,reason:string}>,links:array<string,string>,categories:list<array>,products:list<array>,settings:array}
	 */
	public static function plan(): array {
		$s          = Settings::get();
		$categories = Catalog::categories();
		$products   = Catalog::selected_products();
		$links      = array(
			'shop'           => self::shop_url(),
			'repair'         => Settings::link( 'repair' ),
			'supplier'       => Settings::link( 'supplier' ),
			'supplier_terms' => Settings::link( 'supplier_terms' ),
			'all_categories' => Settings::link( 'all_categories' ),
		);
		$routes     = array(
			'shop'     => array( 'show' => '' !== $links['shop'], 'reason' => '' === $links['shop'] ? 'صفحهٔ فروشگاه در ووکامرس تعیین نشده یا منتشر نیست.' : '' ),
			'repair'   => array( 'show' => '' !== $links['repair'], 'reason' => '' === $links['repair'] ? 'نشانی صفحهٔ تعمیرات تعیین نشده است.' : '' ),
			'supplier' => array( 'show' => '' !== $links['supplier'], 'reason' => '' === $links['supplier'] ? 'نشانی همکاری تأمین‌کنندگان تعیین نشده است.' : '' ),
		);

		if ( array() === $s['products'] ) {
			$why = 'هیچ محصولی برای صفحهٔ اصلی انتخاب نشده است.';
		} elseif ( array() === $products ) {
			$why = 'هیچ‌کدام از محصولات منتخب امروز در فروشگاه قابل‌نمایش نیست (جزئیات در فهرست محصولات منتخب).';
		} else {
			$why = '';
		}

		return array(
			'settings'   => $s,
			'links'      => $links,
			'routes'     => $routes,
			'categories' => $categories,
			'products'   => $products,
			'sections'   => array(
				'hero'       => array( 'show' => true, 'reason' => '' ),
				'categories' => array(
					'show'   => array() !== $categories,
					'reason' => array() === $categories ? 'هیچ دستهٔ منتخبِ دارای محصولی تعیین نشده است.' : '',
				),
				'products'   => array( 'show' => array() !== $products, 'reason' => $why ),
				'supplier'   => array( 'show' => $routes['supplier']['show'], 'reason' => $routes['supplier']['reason'] ),
			),
		);
	}

	/**
	 * @param list<string> $sections بخش‌هایی که این فراخوان چاپ می‌کند، به همین ترتیب.
	 */
	public static function render( array $sections = self::SECTIONS ): string {
		$plan  = self::plan();
		$html  = '';
		foreach ( $sections as $section ) {
			if ( in_array( $section, self::SECTIONS, true ) && $plan['sections'][ $section ]['show'] ) {
				$html .= self::template( $section, $plan );
			}
		}
		if ( '' === $html ) {
			return '';
		}

		$classes = 'tecteb-home-v1 th-c' . ( 'site' === $plan['settings']['font'] ? ' th-font-site' : '' );
		$sprite  = '';
		if ( ! self::$sprite_printed ) {
			self::$sprite_printed = true;
			$sprite               = (string) file_get_contents( TECTEB_HOME_DIR . 'templates/sprite.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- فایل محلیِ خودِ افزونه، نه نشانی.
		}

		return '<div class="' . esc_attr( $classes ) . '" dir="rtl" lang="fa">' . $sprite . $html . '</div>';
	}

	/** نشانی جست‌وجو برای یک واژه — همان شکلی که فرم Hero می‌فرستد. */
	public static function search_url( string $term ): string {
		return add_query_arg(
			array(
				's'         => rawurlencode( $term ),
				'post_type' => 'product',
			),
			home_url( '/' )
		);
	}

	/**
	 * صفحهٔ فروشگاه ووکامرس — یا '' اگر تعیین نشده یا منتشر نیست.
	 * wc_get_page_permalink('shop') برای صفحهٔ نامعتبر به‌جای خطا نشانی صفحهٔ اصلی
	 * را برمی‌گرداند، پس اول خودِ صفحه سنجیده می‌شود تا دکمهٔ «فروشگاه» هرگز به
	 * همین صفحه برنگردد.
	 */
	public static function shop_url(): string {
		$id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
		if ( $id < 1 || 'page' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return '';
		}
		return (string) wc_get_page_permalink( 'shop' );
	}

	/**
	 * HTML ِ قیمت ووکامرس، امن، بی آنکه شکلش عوض شود. wp_kses_post برچسب <bdi> و
	 * ویژگی translate را برمی‌دارد، و <bdi> همان چیزی است که در صفحهٔ راست‌به‌چپ
	 * عدد و واحد پول را کنار هم و به ترتیب درست نگه می‌دارد.
	 */
	public static function price_html( string $html ): string {
		$allowed         = wp_kses_allowed_html( 'post' );
		$allowed['bdi']  = array();
		$allowed['span'] = array_merge( $allowed['span'] ?? array(), array( 'translate' => true ) );
		return wp_kses( $html, $allowed );
	}

	/** عدد با رقم فارسی (برای شمارهٔ مراحل و پیام‌های صفحهٔ تنظیمات). */
	public static function digits( int $n ): string {
		return strtr( (string) $n, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
	}

	/** یک آیکون از sprite؛ نام باید یکی از symbolهای sprite.svg باشد. */
	public static function icon( string $name, string $class = 'th-icon' ): string {
		$name = preg_replace( '/[^a-z-]/', '', $name );
		return '<svg class="' . esc_attr( $class ) . '" aria-hidden="true" focusable="false"><use href="#th-i-' . esc_attr( $name ) . '"/></svg>';
	}

	/** فقط برای آزمون‌ها: sprite در درخواست بعدی دوباره چاپ شود. */
	public static function reset(): void {
		self::$sprite_printed = false;
	}

	private static function template( string $name, array $plan ): string {
		ob_start();
		( static function ( string $file, array $plan ): void {
			include $file;
		} )( TECTEB_HOME_DIR . 'templates/' . $name . '.php', $plan );
		return (string) ob_get_clean();
	}
}
