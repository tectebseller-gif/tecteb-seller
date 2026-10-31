<?php
/**
 * CSS و JS، فقط روی صفحه‌ای که این افزونه را نشان می‌دهد.
 *
 * تشخیص پیش از wp_head: صفحهٔ جاری shortcode ِ ما را در متن دارد، یا در دادهٔ
 * Elementor (`_elementor_data`، جایی که ویجت Shortcode ذخیره می‌شود)، یا قالب
 * صفحهٔ تمام‌عرض همین افزونه را. اگر shortcode جای دیگری اجرا شد که این تشخیص
 * ندیده بود (مثلاً یک template ِ Elementor Pro)، همان‌جا بارگذاری می‌شود و
 * وردپرس آن را در پاورقی چاپ می‌کند — دیرتر، ولی هرگز بی‌استایل نمی‌ماند.
 *
 * هیچ درخواست بیرونی: فونت، تصویر و اسکریپت همه از پوشهٔ همین افزونه‌اند.
 *
 * @package Tecteb\Homepage
 */

namespace Tecteb\Homepage;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public const STYLE  = 'tecteb-home';
	public const FONT   = 'tecteb-home-font';
	public const SCRIPT = 'tecteb-home';

	private static bool $font_preload = false;

	public static function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'register' ), 5 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_enqueue' ), 20 );
		add_action( 'wp_head', array( self::class, 'preload_font' ), 2 );
	}

	public static function register(): void {
		$base = TECTEB_HOME_URL . 'assets/';
		wp_register_style( self::FONT, $base . 'css/tecteb-home-font.css', array(), TECTEB_HOME_VERSION );
		wp_register_style( self::STYLE, $base . 'css/tecteb-home.css', array(), TECTEB_HOME_VERSION );
		wp_register_script(
			self::SCRIPT,
			$base . 'js/tecteb-home.js',
			array(),
			TECTEB_HOME_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}

	public static function maybe_enqueue(): void {
		if ( self::page_uses_homepage() ) {
			self::enqueue();
			self::$font_preload = 'bundled' === Settings::get()['font'];
		}
	}

	/** از shortcode صدا زده می‌شود؛ اگر از قبل بارگذاری شده، کاری نمی‌کند. */
	public static function enqueue(): void {
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			self::register();
		}
		if ( 'bundled' === Settings::get()['font'] ) {
			$shared = self::shared_font_url();
			if ( '' === $shared ) {
				wp_enqueue_style( self::FONT );
			} elseif ( ! wp_style_is( self::STYLE, 'enqueued' ) ) {
				// همان @font-face ِ tecteb-home-font.css، فقط با نشانی فایلِ هدر.
				wp_add_inline_style( self::STYLE, '@font-face{font-family:"TH Vazirmatn";src:url("' . esc_url_raw( $shared ) . '") format("woff2");font-weight:100 900;font-display:swap}' );
			}
		}
		wp_enqueue_style( self::STYLE );
		wp_enqueue_script( self::SCRIPT );
	}

	public static function preload_font(): void {
		if ( self::$font_preload ) {
			$shared = self::shared_font_url();
			printf(
				'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
				esc_url( '' !== $shared ? $shared : TECTEB_HOME_URL . 'assets/fonts/Vazirmatn-wght.woff2' )
			);
		}
	}

	/**
	 * هدر مستقل تک‌طب (tecteb-header) همان فایل Vazirmatn را دارد (بایت‌به‌بایت یکی، اندازه‌گیری‌شده).
	 * اگر فعال است و فایلش هم‌اندازهٔ فایل ماست، صفحهٔ اصلی همان نشانی را به کار می‌برد تا مرورگر
	 * فونت را یک بار بگیرد، نه دو بار (حدود ۱۱۰ کیلوبایت). هدر فقط خوانده می‌شود — هیچ چیزی از آن
	 * تغییر نمی‌کند — و بی آن، یا با فایل دیگر، فایل خودِ این افزونه بارگذاری می‌شود.
	 */
	public static function shared_font_url(): string {
		if ( ! defined( 'TECTEB_HEADER_URL' ) || ! defined( 'TECTEB_HEADER_DIR' ) ) {
			return '';
		}
		$theirs = TECTEB_HEADER_DIR . 'assets/fonts/Vazirmatn-wght.woff2';
		$ours   = TECTEB_HOME_DIR . 'assets/fonts/Vazirmatn-wght.woff2';
		if ( ! is_readable( $theirs ) || filesize( $theirs ) !== filesize( $ours ) ) {
			return '';
		}
		return TECTEB_HEADER_URL . 'assets/fonts/Vazirmatn-wght.woff2';
	}

	public static function page_uses_homepage(): bool {
		$uses = false;
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				$uses = has_shortcode( (string) $post->post_content, Plugin::SHORTCODE )
					|| PageTemplate::FILE === get_page_template_slug( $post )
					|| str_contains( (string) get_post_meta( $post->ID, '_elementor_data', true ), '[' . Plugin::SHORTCODE );
			}
		}
		/**
		 * صفحه‌ای که باید CSS/JS صفحهٔ اصلی را بگیرد، اگر تشخیص خودکار ندید.
		 *
		 * @param bool $uses
		 */
		return (bool) apply_filters( 'tecteb_home_should_enqueue', $uses );
	}
}
