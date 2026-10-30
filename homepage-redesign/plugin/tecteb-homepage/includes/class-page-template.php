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
		if ( is_page() && self::FILE === get_page_template_slug( get_queried_object_id() ) ) {
			return TECTEB_HOME_DIR . 'templates/page-full-width.php';
		}
		return $template;
	}
}
