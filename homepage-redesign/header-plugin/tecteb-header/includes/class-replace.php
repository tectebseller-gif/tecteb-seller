<?php
/**
 * جایگزینی سراسری هدر Elementor — خاموش تا مدیر روشنش کند.
 *
 * چه می‌کند: Elementor Pro پیش و پس از چاپ مکان «header» ِ Theme Builder دو اکشن
 * می‌زند (`elementor/theme/before_do_header` و `elementor/theme/after_do_header`). وقتی
 * جایگزینی روشن است، خروجی میان آن دو — یعنی هر قالب هدری که Elementor برای این صفحه
 * انتخاب کرده، از جمله ۲۶۵۲ — دور ریخته و هدر همین افزونه **همان‌جا** چاپ می‌شود.
 *
 * چرا دو هدر هم‌زمان ممکن نیست:
 *   • هدر افزونه فقط در `after_do_header` چاپ می‌شود، یعنی فقط وقتی Elementor واقعاً
 *     هدری چاپ کرده و همان را حذف کرده‌ایم. اگر این اکشن‌ها اجرا نشوند (Elementor نیست،
 *     یا نسخه‌ای دیگر)، افزونه هیچ چاپ نمی‌کند و هدر فعلی سر جایش می‌ماند.
 *   • Renderer در هر درخواست فقط یک بار هدر چاپ می‌کند؛ shortcode ِ دوم فقط یک توضیح است.
 *
 * چه نمی‌کند: قالب ۲۶۵۲، شرط نمایش آن، فوتر Elementor و هیچ تنظیم Elementor را
 * نمی‌خواند، نمی‌نویسد و حذف نمی‌کند. بازگشت = خاموش‌کردن همین گزینه (یا غیرفعال‌کردن
 * افزونه) ⇒ از درخواست بعد همان ۲۶۵۲.
 *
 * دو حالت فقط برای مدیر (پیش‌نمایش روی همان صفحهٔ واقعی، بی اثر روی دیگران):
 *   ?tth_preview=1   هدر افزونه به‌جای هدر Elementor، حتی وقتی جایگزینی خاموش است.
 *   ?tth_original=1  هدر Elementor، حتی وقتی جایگزینی روشن است (برای مقایسه و آزمون بازگشت).
 *
 * ⛔ نام دو اکشن از مستندات/کد Elementor Pro است و در این محیط فقط با شبیه‌ساز سنجیده
 * شده؛ روی Elementor Pro ِ واقعی «اجرا نشده» است (README، بخش محدودیت‌ها).
 *
 * @package Tecteb\Header
 */

namespace Tecteb\Header;

defined( 'ABSPATH' ) || exit;

final class Replace {

	private static int $level = -1;
	private static bool $was_rendered = false;
	private static bool $replaced = false;

	public static function register_hooks(): void {
		add_action( 'elementor/theme/before_do_header', array( self::class, 'start' ), 0 );
		add_action( 'elementor/theme/after_do_header', array( self::class, 'finish' ), PHP_INT_MAX );
	}

	/** آیا در این درخواست هدر Elementor جایگزین می‌شود؟ */
	public static function active(): bool {
		if ( is_admin() || wp_doing_ajax() || is_feed() || self::in_elementor_editor() ) {
			return false;
		}
		$admin = current_user_can( Settings::capability() );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- فقط نمایش برای مدیر؛ چیزی ذخیره نمی‌شود.
		if ( $admin && isset( $_GET['tth_original'] ) ) {
			return false;
		}
		if ( $admin && isset( $_GET['tth_preview'] ) ) {
			return true;
		}
		// phpcs:enable
		/**
		 * آخرین تصمیم دربارهٔ جایگزینی در این درخواست.
		 *
		 * @param bool $active
		 */
		return (bool) apply_filters( 'tecteb_header_replace', (bool) Settings::get()['replace'] );
	}

	/** ویرایشگر و پیش‌نمایش Elementor: آنجا هیچ چیزی جایگزین نمی‌شود. */
	private static function in_elementor_editor(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- فقط خواندن وجود پارامتر.
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) ) {
			return true;
		}
		return is_singular( 'elementor_library' );
	}

	public static function start(): void {
		if ( self::$level >= 0 || ! self::active() ) {
			return;
		}
		self::$was_rendered = Renderer::rendered();
		self::$level        = ob_get_level();
		ob_start();
	}

	public static function finish(): void {
		if ( self::$level < 0 ) {
			return;
		}
		// هر بافری که قالب هدر باز کرده و نبسته، بسته و دور ریخته می‌شود.
		while ( ob_get_level() > self::$level + 1 ) {
			ob_end_clean();
		}
		if ( ob_get_level() === self::$level + 1 ) {
			ob_end_clean();
		}
		self::$level = -1;
		// اگر قالب Elementor خودش [tecteb_header] داشت، آن نسخه همین الان دور ریخته شد.
		Renderer::set_rendered( self::$was_rendered );
		self::$replaced = true;
		Assets::enqueue();
		echo Renderer::render( 'replace' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- قالب header.php هر جزء را escape می‌کند.
	}

	public static function replaced(): bool {
		return self::$replaced;
	}
}
