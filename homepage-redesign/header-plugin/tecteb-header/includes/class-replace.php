<?php
/**
 * جایگزینی سراسری هدر Elementor — خاموش تا مدیر روشنش کند.
 *
 * سازوکار، از کد خودِ Theme Builder ِ Elementor Pro (Locations_Manager::do_location، که در
 * Pro Elements 4.2.2 — هم‌نسخهٔ Elementor Pro ِ Staging — خوانده شد):
 *
 *   1. اسناد هدرِ این صفحه در صف مکان «header» گذاشته می‌شوند؛
 *   2. do_action( 'elementor/theme/before_do_header', $locations_manager );
 *   3. هر سندی که هنوز در صف است چاپ می‌شود (while ( ! empty( queue ) ));
 *   4. do_action( 'elementor/theme/after_do_header', $locations_manager ); return true.
 *
 * این افزونه در گام ۲ — **آخرین** شنوندهٔ before_do_header — شناسهٔ اسناد صف را می‌خواند و اگر
 * همه در فهرست قالب‌هایی‌اند که مدیر برای جایگزینی انتخاب کرده (امروز فقط ۲۶۵۲)، همان‌ها را با
 * متد عمومی `remove_doc_from_location()` از صف برمی‌دارد. پس گام ۳ هیچ چیزی چاپ نمی‌کند — قالب
 * ۲۶۵۲ اصلاً رندر نمی‌شود — و در گام ۴، **اولین** شنوندهٔ after_do_header هدر این افزونه را
 * همان‌جا چاپ می‌کند.
 *
 * بی output buffer: خروجی هیچ افزونهٔ دیگری — حتی آنچه روی همین دو اکشن چاپ می‌کند — حذف یا
 * جابه‌جا نمی‌شود، و بافری بسته یا دور ریخته نمی‌شود.
 *
 * چرا دو هدر ممکن نیست:
 *   • هدر افزونه فقط وقتی چاپ می‌شود که همین درخواست اسناد را از صف برداشته و صف خالی شده باشد؛
 *   • اگر چیزی غیرمنتظره باشد — شیء locations manager نیست، متدها نیستند، سندی بیرون از فهرست
 *     مجاز در صف است، یا صف پس از برداشتن خالی نشد — اسناد برداشته‌شده به صف برمی‌گردند و
 *     **هیچ** چیزی از این افزونه چاپ نمی‌شود: هدر Elementor همان‌طور که امروز هست؛
 *   • پس از حلقهٔ چاپ، `did_location('header')` پرسیده می‌شود: اگر Elementor به هر دلیلی (مثلاً
 *     افزونهٔ دیگری سند را پس از ما دوباره به صف افزوده) چیزی چاپ کرده باشد، هدر افزونه چاپ
 *     نمی‌شود؛ و اگر Elementor پیش‌تر در همین درخواست هدر چاپ کرده، اصلاً جایگزینی نمی‌شود؛
 *   • Renderer در هر درخواست فقط یک بار هدر چاپ می‌کند؛ اگر مکان header دو بار اجرا شود یا
 *     shortcode پیش‌تر هدر را چاپ کرده باشد، ۲۶۵۲ باز هم برداشته می‌شود و چیز دومی چاپ نمی‌شود.
 *
 * چه نمی‌کند: قالب ۲۶۵۲، شرط نمایش آن، فوتر و هیچ تنظیم Elementor را نمی‌نویسد یا حذف نمی‌کند.
 * بازگشت = خاموش‌کردن گزینه یا غیرفعال‌کردن افزونه ⇒ از درخواست بعد همان ۲۶۵۲.
 *
 * دو حالت فقط برای مدیر (بی اثر روی دیگران و روی کش):
 *   ?tth_preview=1   جایگزینی حتی وقتی کلید خاموش است (با همان فهرست قالب‌های مجاز).
 *   ?tth_original=1  هدر Elementor حتی وقتی کلید روشن است.
 *
 * ⛔ Elementor Pro ِ واقعی در محیط توسعه اجرا نشد؛ سازوکار با شبیه‌سازی وفادار به همان کد
 * (tests/fixtures/elementor-location-emulator.php) آزموده شده است.
 *
 * @package Tecteb\Header
 */

namespace Tecteb\Header;

defined( 'ABSPATH' ) || exit;

final class Replace {

	/** @var list<int> اسنادی که همین درخواست از صف برداشت. */
	private static array $removed = array();

	/** آخرین تصمیم در این درخواست، برای توضیح HTML ِ مدیر و آزمون‌ها. */
	private static string $decision = '';

	/** @var list<int> */
	private static array $seen = array();

	/** Locations_Manager ِ همین اجرای مکان header، برای وارسی در finish(). */
	private static ?object $manager = null;

	public static function register_hooks(): void {
		add_action( 'elementor/theme/before_do_header', array( self::class, 'start' ), PHP_INT_MAX );
		add_action( 'elementor/theme/after_do_header', array( self::class, 'finish' ), PHP_INT_MIN );
	}

	/** آیا در این درخواست هدر Elementor جایگزین می‌شود؟ (پیش از بررسی قالب‌ها) */
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

	/** @param mixed $manager Locations_Manager ِ Elementor Pro */
	public static function start( $manager = null ): void {
		self::$removed  = array();
		self::$seen     = array();
		self::$manager  = null;
		self::$decision = 'off';
		if ( ! self::active() ) {
			return;
		}
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_documents_for_location' ) || ! method_exists( $manager, 'remove_doc_from_location' ) || ! method_exists( $manager, 'add_doc_to_location' ) || ! method_exists( $manager, 'did_location' ) ) {
			self::$decision = 'no_manager';
			return;
		}
		if ( $manager->did_location( 'header' ) ) {
			// Elementor پیش‌تر در همین درخواست هدری چاپ کرده؛ چیزی برداشته یا افزوده نمی‌شود.
			self::$decision = 'elementor_printed_before';
			return;
		}
		$queue      = (array) $manager->get_documents_for_location( 'header' );
		$ids        = array_map( 'intval', array_keys( $queue ) );
		self::$seen = $ids;
		$allowed    = array_map( 'intval', Settings::get()['replace_templates'] );
		if ( array() === $ids ) {
			self::$decision = 'empty_queue';
			return;
		}
		if ( array() !== array_diff( $ids, $allowed ) ) {
			self::$decision = 'not_allowed';
			return;
		}
		foreach ( $ids as $id ) {
			$manager->remove_doc_from_location( 'header', $id );
		}
		if ( array() !== (array) $manager->get_documents_for_location( 'header' ) ) {
			// صف خالی نشد: چیزی خلاف کد خوانده‌شده است. همه‌چیز همان‌طور که بود برمی‌گردد.
			foreach ( $ids as $id ) {
				$manager->add_doc_to_location( 'header', $id );
			}
			self::$decision = 'queue_not_emptied';
			return;
		}
		self::$removed  = $ids;
		self::$manager  = $manager;
		self::$decision = 'replaced';
	}

	/** @param mixed $manager Locations_Manager ِ Elementor Pro */
	public static function finish( $manager = null ): void {
		if ( array() === self::$removed ) {
			if ( '' !== self::$decision && 'off' !== self::$decision ) {
				self::comment( 'not replaced: ' . self::$decision );
			}
			return;
		}
		$ids           = self::$removed;
		$manager       = is_object( $manager ) ? $manager : self::$manager;
		self::$removed = array();
		self::$manager = null;
		// وارسی پس از حلقهٔ چاپ: اگر شنونده‌ای پس از این افزونه سندی را دوباره به صف افزوده و
		// Elementor آن را چاپ کرده (did_location — Locations_Manager، خط ۴۵۳ و ۴۸۰)، هدر Elementor
		// همان‌جاست؛ این افزونه چیزی چاپ نمی‌کند ⇒ همیشه دقیقاً یک هدر، و هرگز صفر.
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'did_location' ) || $manager->did_location( 'header' ) ) {
			self::$decision = 'elementor_printed';
			self::comment( 'not replaced: elementor_printed' );
			return;
		}
		Assets::enqueue();
		self::comment( 'replaced Elementor header template ' . implode( ',', $ids ) );
		echo Renderer::render( 'replace' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- قالب header.php هر جزء را escape می‌کند.
	}

	/** توضیح HTML فقط برای مدیر — بازدیدکننده (و کش صفحه) چیزی از آن نمی‌بیند. */
	private static function comment( string $text ): void {
		if ( current_user_can( Settings::capability() ) ) {
			echo "\n<!-- tecteb-header: " . esc_html( $text ) . " -->\n";
		}
	}

	public static function decision(): string {
		return self::$decision;
	}

	/** @return list<int> */
	public static function seen(): array {
		return self::$seen;
	}
}
