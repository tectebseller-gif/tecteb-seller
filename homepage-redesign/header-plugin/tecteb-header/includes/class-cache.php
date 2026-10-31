<?php
/**
 * کش دسته‌های هدر و پاک‌سازی کش صفحه.
 *
 * فهرست حل‌شدهٔ دسته‌ها (نام، نشانی، آیکون) در یک transient است که کلیدش یک شمارندهٔ نسل
 * دارد (`tecteb_header_gen`)؛ هر تغییری که خروجی را عوض کند نسل را بالا می‌برد، پس کش
 * کهنه هرگز خوانده نمی‌شود و نیازی به پاک‌کردن کلید نیست:
 *   • ذخیرهٔ تنظیمات همین افزونه؛
 *   • ویرایش/حذف هر product_cat ِ تنظیم‌شده (نام، نامک، جابه‌جایی). دستهٔ تازه هنوز در
 *     تنظیمات نیست و خروجی را عوض نمی‌کند؛
 *   • تغییر شمار محصولات یک دستهٔ تنظیم‌شده — فقط وقتی «پنهان‌کردن دستهٔ بی‌محصول» روشن است؛
 *   • ویرایش/حذف پیوستی که آیکون یا لوگوی هدر است؛
 *   • تغییر ساختار پیوندهای یکتا (وردپرس یا ووکامرس).
 *
 * کش صفحه: هدر روی همهٔ صفحه‌هاست، پس پس از تغییری که مدیر می‌بیند (ذخیرهٔ تنظیمات، یا
 * تغییر یک دستهٔ تنظیم‌شده) قلاب عمومی LiteSpeed Cache (`litespeed_purge_all`) صدا زده
 * می‌شود — یک بار در هر درخواست. اگر آن افزونه نباشد کسی گوش نمی‌دهد. تغییر شمار
 * محصول کش صفحه را پاک نمی‌کند (روی هر ذخیرهٔ محصول رخ می‌دهد). ⛔ روی LiteSpeed ِ واقعی
 * سنجیده نشده است.
 *
 * @package Tecteb\Header
 */

namespace Tecteb\Header;

defined( 'ABSPATH' ) || exit;

final class Cache {

	public const GEN = 'tecteb_header_gen';

	private static bool $purged = false;

	public static function register_hooks(): void {
		foreach ( array( 'add_option_' . Settings::OPTION, 'update_option_' . Settings::OPTION ) as $hook ) {
			add_action( $hook, array( self::class, 'settings_changed' ) );
		}
		add_action( 'edited_product_cat', array( self::class, 'term_changed' ) );
		add_action( 'delete_product_cat', array( self::class, 'term_changed' ) );
		add_action( 'edited_term_taxonomy', array( self::class, 'count_changed' ), 10, 2 );
		add_action( 'edit_attachment', array( self::class, 'attachment_changed' ) );
		add_action( 'delete_attachment', array( self::class, 'attachment_changed' ) );
		foreach ( array( 'update_option_permalink_structure', 'update_option_woocommerce_permalinks', 'update_option_category_base' ) as $hook ) {
			add_action( $hook, array( self::class, 'bump' ) );
		}
	}

	public static function key(): string {
		return 'tecteb_header_cats_' . (int) get_option( self::GEN, 0 );
	}

	public static function bump(): void {
		$gen = (int) get_option( self::GEN, 0 );
		delete_transient( 'tecteb_header_cats_' . $gen );
		update_option( self::GEN, $gen + 1, true );
		Menu::forget();
	}

	public static function settings_changed(): void {
		Settings::forget();
		self::bump();
		self::purge();
	}

	/** @param int $term_id */
	public static function term_changed( $term_id ): void {
		if ( self::configured( (int) $term_id ) ) {
			self::bump();
			self::purge();
		}
	}

	/**
	 * @param int    $tt_id
	 * @param string $taxonomy
	 */
	public static function count_changed( $tt_id, $taxonomy = '' ): void {
		if ( 'product_cat' !== $taxonomy || ! Settings::get()['hide_empty'] ) {
			return;
		}
		$term = get_term_by( 'term_taxonomy_id', (int) $tt_id, 'product_cat' );
		if ( $term instanceof \WP_Term && self::configured( (int) $term->term_id ) ) {
			self::bump();
		}
	}

	/** @param int $id */
	public static function attachment_changed( $id ): void {
		$id = (int) $id;
		$s  = Settings::get();
		if ( $id === (int) $s['logo_id'] || in_array( $id, array_map( static fn( $c ) => (int) $c['icon_id'], $s['categories'] ), true ) ) {
			self::bump();
			self::purge();
		}
	}

	private static function configured( int $term_id ): bool {
		foreach ( Settings::get()['categories'] as $c ) {
			if ( (int) $c['term_id'] === $term_id ) {
				return true;
			}
			foreach ( $c['children'] as $k ) {
				if ( (int) $k['term_id'] === $term_id ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function purge(): void {
		if ( self::$purged ) {
			return;
		}
		self::$purged = true;
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- قلاب عمومی LiteSpeed Cache، نه قلاب ما.
		/**
		 * پس از درخواست پاک‌سازی کل کش صفحه از LiteSpeed، برای هر کش دیگری.
		 */
		do_action( 'tecteb_header_purge' );
	}

	/** فقط برای آزمون‌ها. */
	public static function reset(): void {
		self::$purged = false;
	}
}
