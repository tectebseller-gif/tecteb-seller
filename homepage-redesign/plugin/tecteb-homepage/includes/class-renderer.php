<?php
/**
 * از تنظیمات و ووکامرس، HTML ِ پنج بخش Concept C را می‌سازد.
 *
 * قاعدهٔ نمایش یک جا نوشته شده — plan() — و هم صفحه و هم صفحهٔ تنظیمات از آن
 * می‌پرسند، تا «چرا این بخش دیده نمی‌شود» جوابی داشته باشد که با خودِ صفحه
 * یکی است. قاعده: هر دکمه‌ای که مقصد تأییدشده ندارد چاپ نمی‌شود، و هر بخشی که
 * محتوای تأییدشده ندارد کامل کنار می‌رود — هیچ مسیر موقت، فرم ساختگی یا
 * خدمت تأییدنشده‌ای روی صفحه نمی‌آید.
 *
 * @package Tecteb\Homepage
 */

namespace Tecteb\Homepage;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	public const SECTIONS = array( 'hero', 'categories', 'products', 'services', 'supplier' );

	private static bool $sprite_printed = false;

	/**
	 * وضعیت هر بخش و هر دکمه، با دلیل. بدون HTML.
	 *
	 * @return array{sections:array<string,array{show:bool,reason:string}>,links:array<string,string>,categories:list<array>,products:list<array>,settings:array}
	 */
	public static function plan(): array {
		$s          = Settings::get();
		$categories = Catalog::categories();
		$products   = Catalog::featured_products();
		$links      = array(
			'quote'          => Settings::link( 'quote' ),
			'supplier'       => Settings::link( 'supplier' ),
			'supplier_terms' => Settings::link( 'supplier_terms' ),
			'all_categories' => Settings::link( 'all_categories' ),
			'shop'           => self::shop_url(),
		);

		$services = $s['services_enabled'] && array() !== $s['services'];

		return array(
			'settings'   => $s,
			'links'      => $links,
			'categories' => $categories,
			'products'   => $products,
			'sections'   => array(
				'hero'       => array( 'show' => true, 'reason' => '' ),
				'categories' => array(
					'show'   => array() !== $categories,
					'reason' => array() === $categories ? 'هیچ دستهٔ منتخبِ دارای محصولی تعیین نشده است.' : '',
				),
				'products'   => array(
					'show'   => array() !== $products,
					'reason' => array() === $products ? 'هیچ محصول «ویژه»ای که منتشرشده و در فروشگاه قابل‌مشاهده باشد پیدا نشد.' : '',
				),
				'services'   => array(
					'show'   => $services,
					'reason' => $services ? '' : ( $s['services_enabled'] ? 'هیچ خدمتی وارد نشده است.' : 'تا تأیید محتوا خاموش است.' ),
				),
				'supplier'   => array(
					'show'   => '' !== $links['supplier'],
					'reason' => '' === $links['supplier'] ? 'نشانی «همکاری تأمین‌کنندگان» تعیین نشده است.' : '',
				),
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

	/** نشانی استعلام برای یک محصول؛ پارامتر فقط اگر مدیر نامش را تعیین کرده باشد. */
	public static function quote_url( string $base, int $product_id, string $param ): string {
		return '' !== $param ? add_query_arg( $param, (string) $product_id, $base ) : $base;
	}

	public static function shop_url(): string {
		$id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
		return $id > 0 && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '';
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
