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

	/** @var \WP_Post|null|false پستِ کنارگذاشته در hide_front_document()؛ false یعنی چیزی کنار گذاشته نشده. */
	private static $stashed_post = false;

	public static function register_hooks(): void {
		add_filter( 'theme_page_templates', array( self::class, 'offer' ) );
		add_filter( 'template_include', array( self::class, 'load' ), 99 );
		// 0.3.7 — دو طرف بلوک «CSS و دارایی‌های پست جاری» در Elementor\Frontend::enqueue_styles.
		add_action( 'elementor/frontend/after_enqueue_styles', array( self::class, 'hide_front_document' ), PHP_INT_MAX );
		add_action( 'elementor/frontend/after_enqueue_post_styles', array( self::class, 'restore_front_document' ), PHP_INT_MIN );
		add_action( 'wp_enqueue_scripts', array( self::class, 'restore_front_document' ), PHP_INT_MAX );
	}

	/**
	 * 0.3.7 — وقتی این افزونه صفحهٔ نخست را جایگزین می‌کند، CSS و دارایی‌های Elementor ِ همان برگه بارگذاری نشوند.
	 *
	 * Elementor برای هر صفحهٔ singular، در Frontend::enqueue_styles (خط ۶۸۶–۶۹۷ ِ Elementor 4.2.3)، سه چیز ِ پست
	 * جاری را در <head> می‌گذارد: post-{ID}.css (و فونت‌ها و کتابخانه‌های آیکون ثبت‌شده در متای آن)، و «دارایی‌های
	 * صفحه» ِ ابزارک‌هایش (`_elementor_page_assets`: widget-*، swiper، e-animation-*، …). وقتی برگهٔ نخست با
	 * Elementor ساخته شده و این افزونه به‌جایش templates/front-page.php را چاپ می‌کند، هیچ‌کدام از آن ابزارک‌ها روی
	 * صفحه نیست — پس همهٔ آن‌ها درخواست‌های مسدودکنندهٔ رندری‌اند که هیچ چیزی را نمی‌آرایند.
	 *
	 * آن بلوک با `get_the_ID()` تصمیم می‌گیرد و فیلتری ندارد؛ پس درست پیش از آن (آخرین شنوندهٔ
	 * elementor/frontend/after_enqueue_styles) پست جاری کنار گذاشته و درست پس از آن (اولین شنوندهٔ
	 * elementor/frontend/after_enqueue_post_styles، که Elementor همیشه پس از همان بلوک صدا می‌زند) برگردانده می‌شود.
	 * میان این دو فقط کد خودِ Elementor اجرا می‌شود. محض احتیاط، آخرین شنوندهٔ wp_enqueue_scripts هم برمی‌گرداند.
	 *
	 * فقط وقتی takes_over_front() (پس نه در ویرایشگر، پیش‌نمایش، ?tecteb_home_original=1 یا صفحه‌ای دیگر). شرط «برگه
	 * با Elementor ساخته شده» عمداً نیست: Elementor متای `_elementor_page_assets` ِ برگه‌ای را که بعداً به ویرایشگر
	 * معمولی برگشته هم بارگذاری می‌کند (آزموده)، و محتوای برگه در هر حال با این جایگزینی چاپ نمی‌شود. هدر، فوتر،
	 * Popup و Kit ِ Elementor دست نمی‌خورند (Theme Builder آن‌ها را جدا بارگذاری می‌کند)؛ هیچ داده‌ای نوشته یا حذف
	 * نمی‌شود.
	 */
	public static function hide_front_document(): void {
		if ( false !== self::$stashed_post || ! self::takes_over_front() ) {
			return;
		}
		self::$stashed_post = $GLOBALS['post'] ?? null;
		$GLOBALS['post']    = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- همان لحظه در restore_front_document() برمی‌گردد.
	}

	public static function restore_front_document(): void {
		if ( false === self::$stashed_post ) {
			return;
		}
		$GLOBALS['post']    = self::$stashed_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- بازگرداندن همان مقدار.
		self::$stashed_post = false;
	}

	/** برای آزمون‌ها: آیا پست جاری الان کنار گذاشته است؟ */
	public static function front_document_hidden(): bool {
		return false !== self::$stashed_post;
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
