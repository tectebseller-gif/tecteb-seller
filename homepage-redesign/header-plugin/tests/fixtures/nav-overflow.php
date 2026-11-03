<?php
/**
 * فیکسچر «منوی بلند»: همان پیوندهای منوی اصلی سایت اصلی که در تصویر پیش‌نمایش مالک دیده می‌شوند (به همان
 * ترتیب)، روی وردپرس یکبارمصرف محلی. فقط برای سنجیدن جا شدن ردیف منو — هیچ چیزی به سایت واقعی وصل نمی‌شود.
 *
 *   wp eval-file tests/fixtures/nav-overflow.php on [n]   ⇒ فهرست «tthh-prod-like» با n پیوند اول (پیش‌فرض ۸)
 *   wp eval-file tests/fixtures/nav-overflow.php off      ⇒ فهرست و تنظیم قبلی برمی‌گردند
 *
 * برچسب هشتم («در تک طب فروشنده شوید») از روی تصویر خوانده شده و زیر دکمهٔ «همکاری تأمین‌کنندگان» نیمه‌پنهان
 * است؛ دو برچسب آخر (۹ و ۱۰) فقط برای فشار بیشترند و در سایت اصلی دیده نشده‌اند.
 *
 * @package Tecteb\Header
 */

if ( 'tth_disposable' !== DB_NAME || ! str_starts_with( home_url(), 'http://127.0.0.1' ) ) {
	fwrite( STDERR, "refused: not the disposable local site\n" );
	exit( 1 );
}
$verb   = $args[0] ?? 'on';
$labels = array( 'دسته بندی کالا ها', 'صفحه اصلی', 'فروشگاه', 'وبلاگ', 'درخواست تعمیرات', 'درباره تک طب', 'تماس با ما', 'در تک طب فروشنده شوید', 'سوالات متداول', 'پیگیری سفارش' );
$saved  = get_option( 'tthh_nav_overflow_saved' );
$s      = (array) get_option( 'tecteb_header_settings', array() );
$old    = wp_get_nav_menu_object( 'tthh-prod-like' );
if ( $old ) {
	wp_delete_nav_menu( $old->term_id );
}
if ( 'on' === $verb ) {
	if ( ! is_array( $saved ) ) {
		update_option( 'tthh_nav_overflow_saved', array( 'nav_menu' => $s['nav_menu'] ?? 0, 'nav_hidden' => $s['nav_hidden'] ?? array() ), false );
	}
	$n    = max( 1, min( count( $labels ), (int) ( $args[1] ?? 8 ) ) );
	$menu = wp_create_nav_menu( 'tthh-prod-like' );
	foreach ( array_slice( $labels, 0, $n ) as $i => $label ) {
		wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => $label, 'menu-item-url' => home_url( '/tthh-nav-' . ( $i + 1 ) . '/' ), 'menu-item-status' => 'publish', 'menu-item-position' => $i + 1 ) );
	}
	$s['nav_menu']   = (int) $menu;
	$s['nav_hidden'] = array();
	update_option( 'tecteb_header_settings', $s );
	echo "on {$n}\n";
} else {
	if ( is_array( $saved ) ) {
		$s['nav_menu']   = $saved['nav_menu'];
		$s['nav_hidden'] = $saved['nav_hidden'];
		update_option( 'tecteb_header_settings', $s );
		delete_option( 'tthh_nav_overflow_saved' );
	}
	echo "off\n";
}
