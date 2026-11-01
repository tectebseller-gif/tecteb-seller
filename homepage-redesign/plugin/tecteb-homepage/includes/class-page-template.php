<?php
/**
 * قالب صفحهٔ «تک‌طب — تمام‌عرض»: راه دوم کنار ویجت Shortcode ِ Elementor.
 *
 * فایلی از قالب سایت عوض نمی‌شود: این قالب از راه دو فیلتر خودِ وردپرس
 * (theme_page_templates و template_include) پیشنهاد و بارگذاری می‌شود، و خودش
 * get_header() و get_footer() ِ همان قالب را صدا می‌زند — پس هدر و پاورقی
 * فعلی سایت (از جمله هدر/پاورقی Theme Builder ِ Elementor Pro که روی همان
 * قلاب‌ها می‌نشیند) دست‌نخورده می‌مانند. عنوان صفحه چاپ نمی‌شود، چون Hero
 * خودش تنها h1 ِ صفحه است.
 *
 * @package Tecteb\Homepage
 */

namespace Tecteb\Homepage;

defined( 'ABSPATH' ) || exit;

final class PageTemplate {

	public const FILE = 'tecteb-homepage-full-width.php';

	public static function register_hooks(): void {
		add_filter( 'theme_page_templates', array( self::class, 'offer' ) );
		add_filter( 'template_include', array( self::class, 'load' ), 99 );
	}

	public static function offer( array $templates ): array {
		$templates[ self::FILE ] = __( 'تک‌طب — تمام‌عرض (هدر و پاورقی سایت)', 'tecteb-homepage' );
		return $templates;
	}

	public static function load( string $template ): string {
		if ( self::takes_over_front() ) {
			return TECTEB_HOME_DIR . 'templates/front-page.php';
		}
		if ( is_page() && self::FILE === get_page_template_slug( get_queried_object_id() ) ) {
			return TECTEB_HOME_DIR . 'templates/page-full-width.php';
		}
		return $template;
	}

	/**
	 * آیا همین درخواست، صفحهٔ نخست سایت را با Concept C نشان می‌دهد؟ (۰٫۳٫۱)
	 *
	 * صفحهٔ نخست همان است که «تنظیمات ← خواندن» تعیین کرده و **دست نمی‌خورد**: نه محتوا، نه دادهٔ
	 * Elementor، نه قالب، نه option ِ page_on_front. فقط فایل قالبی که وردپرس برای همین درخواست بارگذاری
	 * می‌کند عوض می‌شود (template_include، اولویت ۹۹)؛ آن فایل get_header() و get_footer() ِ قالب سایت را
	 * صدا می‌زند، پس هدر مستقل تک‌طب و فوتر Elementor همان‌اند. خاموش‌کردن گزینه یا غیرفعال‌سازی افزونه ⇒
	 * از درخواست بعد همان برگهٔ قبلی، بی هیچ کار دیگری.
	 *
	 * دو پارامتر فقط برای مدیر (مجوز همین افزونه)، بی اثر روی بازدیدکننده و کش:
	 *   ?tecteb_home_preview=1   طرح، حتی وقتی گزینه خاموش است — پیش‌نمایش پیش از روشن‌کردن.
	 *   ?tecteb_home_original=1  برگهٔ فعلی، حتی وقتی گزینه روشن است — مقایسه.
	 */
	public static function takes_over_front(): bool {
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || ! is_front_page() || is_paged() ) {
			return false;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- فقط نمایش؛ هیچ چیزی ذخیره نمی‌شود.
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['preview'] ) ) {
			return false; // ویرایشگر و پیش‌نمایش خودِ برگه همان برگه را نشان می‌دهند.
		}
		if ( '' !== self::front_page_problem() ) {
			return false;
		}
		$admin = current_user_can( Admin::capability() );
		if ( $admin && isset( $_GET['tecteb_home_original'] ) ) {
			return false;
		}
		if ( $admin && isset( $_GET['tecteb_home_preview'] ) ) {
			return true;
		}
		// phpcs:enable
		return (bool) Settings::get()['front_page'];
	}

	/**
	 * دلیلی که صفحهٔ نخست نباید جایگزین شود، یا ''. صفحهٔ نخستی که خودش صفحهٔ فروشگاه ووکامرس است جایگزین
	 * نمی‌شود: فهرست محصولات از صفحهٔ نخست می‌رفت و کارت «فروشگاه» هم به همین صفحه برمی‌گشت.
	 */
	public static function front_page_problem(): string {
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		if ( $front > 0 && function_exists( 'wc_get_page_id' ) && (int) wc_get_page_id( 'shop' ) === $front ) {
			return __( 'صفحهٔ نخست سایت همان صفحهٔ فروشگاه ووکامرس است؛ جایگزینی آن فهرست محصولات را از دسترس برمی‌داشت. اول یک صفحهٔ نخست جدا در «تنظیمات ← خواندن» انتخاب کنید.', 'tecteb-homepage' );
		}
		return '';
	}
}
