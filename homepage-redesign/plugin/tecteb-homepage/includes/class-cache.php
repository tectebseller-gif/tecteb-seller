<?php
/**
 * وقتی چیزی که صفحهٔ اصلی نشان می‌دهد عوض شد، فقط نشانی صفحهٔ اصلی از کش صفحه
 * پاک می‌شود — یک بار در هر درخواست، همان لحظه (نه در shutdown: LiteSpeed
 * پاک‌سازی را با سربرگ پاسخ اعلام می‌کند و آن وقت سربرگ‌ها رفته‌اند).
 *
 * هر ذخیرهٔ محصول (قیمت، موجودی، تصویر، انتشار) woocommerce_update_product را
 * می‌زند؛ انتخاب و ترتیب محصولات و دسته‌ها تنظیم همین افزونه است. پاک‌سازی از راه قلاب عمومی LiteSpeed Cache
 * (`litespeed_purge_url`) است: اگر آن افزونه نصب نباشد، کسی به این قلاب گوش
 * نمی‌دهد و هیچ اتفاقی نمی‌افتد. قلاب `tecteb_home_purge` برای هر کش دیگری.
 *
 * @package Tecteb\Homepage
 */

namespace Tecteb\Homepage;

defined( 'ABSPATH' ) || exit;

final class Cache {

	private static bool $done = false;

	public static function register_hooks(): void {
		foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_product_set_stock_status', 'edited_product_cat', 'delete_product_cat', 'update_option_' . Settings::OPTION, 'add_option_' . Settings::OPTION ) as $hook ) {
			add_action( $hook, array( self::class, 'request' ) );
		}
		add_action( 'wp_trash_post', array( self::class, 'maybe_product' ) );
	}

	public static function maybe_product( $post_id ): void {
		if ( 'product' === get_post_type( (int) $post_id ) ) {
			self::request();
		}
	}

	public static function request(): void {
		Settings::forget();
		if ( ! self::$done ) {
			self::$done = true;
			self::purge();
		}
	}

	/** فقط برای آزمون‌ها. */
	public static function reset(): void {
		self::$done = false;
	}

	/** @return list<string> */
	public static function urls(): array {
		$urls  = array( home_url( '/' ) );
		$front = (int) get_option( 'page_on_front' );
		if ( 'page' === get_option( 'show_on_front' ) && $front > 0 ) {
			$urls[] = (string) get_permalink( $front );
		}
		return array_values( array_unique( array_filter( $urls ) ) );
	}

	public static function purge(): void {
		$urls = self::urls();
		foreach ( $urls as $url ) {
			do_action( 'litespeed_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- قلاب عمومی LiteSpeed Cache، نه قلاب ما.
		}
		/**
		 * پس از پاک‌سازی صفحهٔ اصلی از LiteSpeed، برای هر کش دیگری.
		 *
		 * @param list<string> $urls
		 */
		do_action( 'tecteb_home_purge', $urls );
	}
}
