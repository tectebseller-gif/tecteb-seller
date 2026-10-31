<?php
/**
 * راه‌اندازی. هیچ چیزی هنگام فعال‌سازی نوشته نمی‌شود: تا مدیر ذخیره نکند، option وجود
 * ندارد و پیش‌فرض‌ها (بی دسته، بی جایگزینی) خوانده می‌شوند. فعال‌سازی/غیرفعال‌سازی فقط وقتی
 * جایگزینی روشن است کش صفحه را پاک می‌کند (lifecycle_purge).
 *
 * @package Tecteb\Header
 */

namespace Tecteb\Header;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		add_action( 'init', array( Renderer::class, 'register_hooks' ) );
		// روی init (نه admin_init) تا update_option از هر جایی — نه فقط options.php — از همان اعتبارسنجی بگذرد.
		add_action( 'init', array( Settings::class, 'register' ) );
		Cache::register_hooks();
		Assets::register_hooks();
		Replace::register_hooks();
		add_filter( 'woocommerce_add_to_cart_fragments', array( Renderer::class, 'cart_fragment' ) );
		if ( is_admin() ) {
			Admin::register_hooks();
		}
	}

	public static function lifecycle_purge(): void {
		Settings::forget();
		if ( Settings::get()['replace'] ) {
			Cache::purge();
		}
	}
}
