<?php
/**
 * Plugin Name: TTHH — شبیه‌ساز مکان header/footer ِ Elementor Theme Builder (فقط آزمون محلی)
 *
 * Elementor Pro در این محیط نیست. این mu-plugin فقط همان بخشی از قرارداد Theme Builder را
 * شبیه‌سازی می‌کند که tecteb-header به آن تکیه دارد، و **نه بیشتر**:
 *   elementor_theme_do_location( $location ) ⇒ اگر قالبی برای مکان هست:
 *     do_action( "elementor/theme/before_do_{$location}" ); چاپ قالب؛
 *     do_action( "elementor/theme/after_do_{$location}" ); return true;
 *   وگرنه return false (قالب Hello هدر خودش را چاپ می‌کند).
 * هدر شبیه‌سازی‌شده همان پوستهٔ «Header ۲۶۵۲» است (<header class="elementor elementor-2652
 * elementor-location-header">) و فوتر، «Footer». هر کدام را با option ِ tthh_emulate
 * (header|footer|both|none) می‌شود خاموش کرد. اینکه Elementor Pro ِ واقعی همین ترتیب را
 * اجرا می‌کند در این محیط **سنجیده نشده** است.
 *
 * فقط روی وردپرس یکبارمصرف بارگذاری می‌شود.
 */

defined( 'ABSPATH' ) || exit;

if ( 'tth_disposable' !== DB_NAME ) {
	return;
}

if ( ! function_exists( 'elementor_theme_do_location' ) ) {
	function elementor_theme_do_location( $location ) {
		$mode = get_option( 'tthh_emulate', 'both' );
		$on   = 'both' === $mode || $mode === $location;
		if ( ! $on || ! in_array( $location, array( 'header', 'footer' ), true ) ) {
			return false;
		}
		do_action( "elementor/theme/before_do_{$location}" );
		if ( 'header' === $location ) {
			// شبیه قالب Elementor: بافری که خودش باز می‌کند و همیشه نمی‌بندد، و shortcode ِ اختیاری.
			echo '<header data-elementor-type="header" data-elementor-id="2652" class="elementor elementor-2652 elementor-location-header" data-tthh-original>';
			echo '<div class="elementor-section"><a href="/" class="tthh-orig-logo">تک‌طب (هدر Elementor ۲۶۵۲)</a>';
			echo '<form role="search" class="tthh-orig-search"><input type="search" aria-label="جستجو سریع" placeholder="جستجو سریع ..."></form></div>';
			if ( get_option( 'tthh_emulate_shortcode' ) ) {
				echo do_shortcode( '[tecteb_header]' );
			}
			if ( get_option( 'tthh_emulate_dangling_ob' ) ) {
				ob_start();
				echo 'dangling-buffer-content';
			}
			echo '</header>';
		} else {
			echo '<footer data-elementor-type="footer" data-elementor-id="2700" class="elementor elementor-2700 elementor-location-footer" data-tthh-footer><p>فوتر Elementor (دست‌نخورده)</p></footer>';
		}
		do_action( "elementor/theme/after_do_{$location}" );
		return true;
	}
}
