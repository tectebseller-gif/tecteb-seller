<?php
/**
 * حساب، سبد و علاقه‌مندی — فقط از منبع واقعی، هرگز با دادهٔ ساختگی.
 *
 *   • حساب: is_user_logged_in، wc_get_page_permalink('myaccount')، wc_get_account_menu_items()
 *     (همان فهرستی که خودِ صفحهٔ «حساب من» نشان می‌دهد، با هر موردی که افزونه‌های دیگر
 *     افزوده‌اند) و wc_logout_url().
 *   • سبد: WC()->cart->get_cart_contents_count() و wc_get_cart_url().
 *   • علاقه‌مندی: افزونهٔ علاقه‌مندیِ سایت هنوز شناسایی نشده، پس هیچ شمارنده‌ای حدس زده
 *     نمی‌شود. حالت پیش‌فرض «خاموش» است (نماد نمایش داده نمی‌شود)؛ «پیوند» فقط نشانیِ
 *     واردشدهٔ مدیر را نشان می‌دهد. شمارنده فقط وقتی هست که یک تطبیق‌دهندهٔ واقعی آن را از
 *     فیلتر `tecteb_header_wishlist_count` برگرداند.
 *
 * @package Tecteb\Header
 */

namespace Tecteb\Header;

defined( 'ABSPATH' ) || exit;

final class Account {

	/** @return array{logged_in:bool,name:string,url:string,items:list<array{key:string,label:string,url:string}>} */
	public static function account(): array {
		$url = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';
		if ( '' === $url ) {
			$url = wp_login_url();
		}
		if ( ! is_user_logged_in() ) {
			return array(
				'logged_in' => false,
				'name'      => '',
				'url'       => $url,
				'items'     => array(),
			);
		}
		$items = array();
		if ( function_exists( 'wc_get_account_menu_items' ) && function_exists( 'wc_get_account_endpoint_url' ) ) {
			foreach ( wc_get_account_menu_items() as $key => $label ) {
				$items[] = array(
					'key'   => (string) $key,
					'label' => wp_strip_all_tags( (string) $label ),
					'url'   => 'customer-logout' === $key && function_exists( 'wc_logout_url' ) ? wc_logout_url() : (string) wc_get_account_endpoint_url( (string) $key ),
				);
			}
		} else {
			$items[] = array(
				'key'   => 'customer-logout',
				'label' => __( 'خروج', 'tecteb-header' ),
				'url'   => wp_logout_url( home_url( '/' ) ),
			);
		}
		return array(
			'logged_in' => true,
			'name'      => (string) wp_get_current_user()->display_name,
			'url'       => $url,
			'items'     => $items,
		);
	}

	/** @return array{url:string,count:int|null} */
	public static function cart(): array {
		$url   = function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : '';
		$count = null;
		if ( function_exists( 'WC' ) && WC() && isset( WC()->cart ) && WC()->cart ) {
			$count = (int) WC()->cart->get_cart_contents_count();
		}
		return array(
			'url'   => $url,
			'count' => $count,
		);
	}

	/** @return array{url:string,count:int|null}|null  null ⇒ نماد علاقه‌مندی نمایش داده نمی‌شود. */
	public static function wishlist(): ?array {
		$s = Settings::get();
		if ( 'link' !== $s['wishlist_mode'] || '' === $s['wishlist_url'] ) {
			return null;
		}
		/**
		 * شمار علاقه‌مندی‌های کاربر جاری، فقط از یک تطبیق‌دهندهٔ واقعی. null ⇒ بی شمارنده.
		 *
		 * @param int|null $count
		 */
		$count = apply_filters( 'tecteb_header_wishlist_count', null );
		return array(
			'url'   => self::href( $s['wishlist_url'] ),
			'count' => is_int( $count ) && $count >= 0 ? $count : null,
		);
	}

	/** مسیر نسبی (/…) ⇒ home_url؛ نشانی کامل همان. */
	public static function href( string $url ): string {
		return str_starts_with( $url, '/' ) ? home_url( $url ) : $url;
	}

	/** ارقام فارسی برای شمارنده‌ها (مثل نمونهٔ طراحی). */
	public static function fa( int $n ): string {
		return strtr( (string) $n, array_combine( range( 0, 9 ), array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ) ) );
	}
}
