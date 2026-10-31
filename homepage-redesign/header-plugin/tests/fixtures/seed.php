<?php
/**
 * دادهٔ آزمون افزونهٔ tecteb-header، فقط روی وردپرس یکبارمصرفِ محلی — هرگز staging یا
 * سایت اصلی. هر چیزی که می‌سازد با متای `_tthh_fixture` نشانه می‌خورد و reset فقط همان‌ها
 * را برمی‌دارد.
 *
 *   wp eval-file tests/fixtures/seed.php seed|reset|ids
 *
 * می‌سازد: سیزده دستهٔ اصلی با همان نام‌های هدر فعلی (یکی با ي/ك ِ عربی و نیم‌فاصله، برای
 * یکسان‌سازی)، زیردسته و نوه، یک دستهٔ هم‌نامِ تکراری در عمق (برای «سطح اول مقدم است»)،
 * چند محصول، سه پیوست (PNG، WebP و یک SVG که باید رد شود)، و فهرست وردپرسی به شکل منوی
 * فعلی سایت (ردیف آبی + «دسته بندی کالا ها» با سیزده مورد product_cat).
 *
 * @package Tecteb\Header
 */

defined( 'ABSPATH' ) || exit;

if ( 'tth_disposable' !== DB_NAME || ! str_starts_with( home_url(), 'http://127.0.0.1' ) ) {
	fwrite( STDERR, "refused: not the disposable local site\n" );
	exit( 1 );
}

const TTHH_FIXTURE = '_tthh_fixture';

$verb = $args[0] ?? 'seed';

function tthh_reset(): void {
	foreach ( get_posts( array( 'post_type' => array( 'product', 'attachment', 'nav_menu_item', 'page' ), 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => TTHH_FIXTURE, 'fields' => 'ids' ) ) as $id ) {
		'attachment' === get_post_type( $id ) ? wp_delete_attachment( $id, true ) : wp_delete_post( $id, true );
	}
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'meta_key' => TTHH_FIXTURE ) );
	// فرزندان پیش از مادر، تا هیچ دسته‌ای «بی‌مادر» به سطح اول نپرد و دوباره ساخته نشود.
	usort( $terms, static fn( $a, $b ) => count( get_ancestors( $b->term_id, 'product_cat' ) ) <=> count( get_ancestors( $a->term_id, 'product_cat' ) ) );
	foreach ( $terms as $t ) {
		wp_delete_term( $t->term_id, 'product_cat' );
	}
	$menu = wp_get_nav_menu_object( 'tthh-main' );
	if ( $menu ) {
		wp_delete_nav_menu( $menu->term_id );
	}
	delete_option( 'tecteb_header_settings' );
	delete_option( 'tthh_fixture_ids' );
}

function tthh_term( string $name, int $parent = 0 ): int {
	$r = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
	if ( is_wp_error( $r ) ) {
		throw new RuntimeException( $name . ': ' . $r->get_error_message() );
	}
	update_term_meta( $r['term_id'], TTHH_FIXTURE, 1 );
	return (int) $r['term_id'];
}

function tthh_product( string $name, array $cats ): int {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_status( 'publish' );
	$p->set_regular_price( '1000000' );
	$p->set_category_ids( $cats );
	$id = $p->save();
	update_post_meta( $id, TTHH_FIXTURE, 1 );
	return $id;
}

/** پیوست مستقیم (بی بارگذاری)، تا SVG هم بی فعال‌کردن بارگذاری SVG ساخته شود. */
function tthh_attachment( string $name, string $mime, string $bytes ): int {
	$up   = wp_upload_dir();
	$path = trailingslashit( $up['path'] ) . $name;
	file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$id = wp_insert_attachment(
		array(
			'post_title'     => $name,
			'post_mime_type' => $mime,
			'post_status'    => 'inherit',
		),
		$path
	);
	if ( str_starts_with( $mime, 'image/' ) && 'image/svg+xml' !== $mime ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	}
	update_post_meta( $id, TTHH_FIXTURE, 1 );
	return (int) $id;
}

function tthh_png( string $fmt ): string {
	$im = imagecreatetruecolor( 64, 64 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 15, 122, 128 ) );
	imagefilledellipse( $im, 32, 32, 36, 36, imagecolorallocate( $im, 255, 255, 255 ) );
	ob_start();
	'webp' === $fmt ? imagewebp( $im ) : imagepng( $im );
	return (string) ob_get_clean();
}

function tthh_menu_item( int $menu, array $args ): int {
	$id = wp_update_nav_menu_item( $menu, 0, array_merge( array( 'menu-item-status' => 'publish' ), $args ) );
	update_post_meta( $id, TTHH_FIXTURE, 1 );
	return (int) $id;
}

if ( 'reset' === $verb ) {
	tthh_reset();
	echo "reset\n";
	return;
}

if ( 'configure' === $verb ) {
	// همان حالتی که مرورگر می‌سنجد: سیزده دسته به ترتیب منو، دو آیکون، زیردسته، منوی وردپرس، جایگزینی روشن.
	$ids  = get_option( 'tthh_fixture_ids' );
	$cats = array();
	foreach ( $ids['mains'] as $id ) {
		$cats[] = array( 'term_id' => $id, 'enabled' => true, 'icon_id' => 0, 'children' => array() );
	}
	$cats[1]['icon_id']  = $ids['webp'];
	$cats[1]['children'] = array( array( 'term_id' => $ids['monitor'], 'enabled' => true ), array( 'term_id' => $ids['ecg'], 'enabled' => true ) );
	$cats[7]['icon_id']  = $ids['png'];
	$cats[7]['children'] = array( array( 'term_id' => $ids['chair'], 'enabled' => true ), array( 'term_id' => $ids['xray'], 'enabled' => true ) );
	$cats[11]['children'] = array( array( 'term_id' => $ids['bed'], 'enabled' => true ), array( 'term_id' => $ids['bed_icu'], 'enabled' => true ) );
	delete_option( 'tecteb_header_settings' );
	update_option(
		'tecteb_header_settings',
		array(
			'categories' => $cats,
			'nav_menu'   => $ids['menu'],
			'nav_hidden' => array( $ids['nav']['cats'], $ids['nav']['seller'] ),
			'replace'    => ( $args[1] ?? 'on' ) === 'on',
		)
	);
	echo wp_json_encode( get_option( 'tecteb_header_settings' ) ), "\n";
	return;
}

if ( 'ids' === $verb ) {
	echo wp_json_encode( get_option( 'tthh_fixture_ids', array() ), JSON_UNESCAPED_UNICODE ), "\n";
	return;
}

tthh_reset();
$ids   = array( 'mains' => array() );
$names = array(
	'پزشکی جنرال',
	'قلب و عروق',
	"چشم\u{200C}پزشكی",
	'جراحی',
	'زنان و زایمان',
	'ارتوپدی',
	'توانبخشی',
	'دندانپزشکی',
	'آزمایشگاه تشخیص',
	'استریلیزاسیون',
	"گوش و حلق و بيني",
	'هتلینگ',
	'بیهوشی و تنفسی',
);
foreach ( $names as $n ) {
	$ids['mains'][ $n ] = tthh_term( $n );
}
// «آزمایشگاه» ِ مبنا عمداً به نام دیگری ساخته شد ⇒ «پیدا نشد». و یک «جراحی» ِ دوم در عمق ⇒ سطح اول مقدم.
$ids['chair']      = tthh_term( 'یونیت دندانپزشکی', $ids['mains']['دندانپزشکی'] );
$ids['xray']       = tthh_term( 'رادیولوژی دندان', $ids['mains']['دندانپزشکی'] );
$ids['monitor']    = tthh_term( 'مانیتورینگ قلب', $ids['mains']['قلب و عروق'] );
$ids['ecg']        = tthh_term( 'الکتروکاردیوگراف', $ids['monitor'] );
$ids['bed']        = tthh_term( 'تخت بیمار', $ids['mains']['هتلینگ'] );
$ids['bed_icu']    = tthh_term( 'تخت ICU', $ids['bed'] );
$ids['surgery2']   = tthh_term( 'جراحی', $ids['mains']['دندانپزشکی'] );
$ids['empty_kid']  = tthh_term( 'زیردستهٔ بی‌محصول', $ids['mains']['قلب و عروق'] );
$ids['xss']        = tthh_term( 'دسته <img src=x onerror=alert(1)> "ویژه"', 0 );
// مدیری با unfiltered_html می‌تواند چنین نامی بگذارد؛ wp_insert_term از CLI آن را پاک می‌کند، پس مستقیم.
$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->terms, array( 'name' => 'دسته <img src=x onerror=alert(1)> "ویژه" & <b>' ), array( 'term_id' => $ids['xss'] ) ); // phpcs:ignore WordPress.DB
clean_term_cache( $ids['xss'], 'product_cat' );
$ids['other_top']  = tthh_term( 'ملزومات بیمارستانی', 0 );
$ids['other_kid']  = tthh_term( 'دستکش', $ids['other_top'] );

$ids['products'] = array(
	tthh_product( 'یونیت دندانپزشکی نمونه', array( $ids['chair'] ) ),
	tthh_product( 'دستگاه نوار قلب نمونه', array( $ids['ecg'] ) ),
	tthh_product( 'تخت آی‌سی‌یو نمونه', array( $ids['bed_icu'] ) ),
	tthh_product( 'دستکش جراحی نمونه', array( $ids['other_kid'], $ids['mains']['جراحی'] ) ),
);
// ووکامرس شمار «با زیردسته‌ها» را در product_count_product_cat نگه می‌دارد.
if ( function_exists( '_wc_term_recount' ) ) {
	_wc_term_recount( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) ), get_taxonomy( 'product_cat' ), true, false );
}

$ids['png']  = tthh_attachment( 'tthh-icon.png', 'image/png', tthh_png( 'png' ) );
$ids['webp'] = tthh_attachment( 'tthh-icon.webp', 'image/webp', tthh_png( 'webp' ) );
$ids['svg']  = tthh_attachment( 'tthh-icon.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>alert(1)</script><circle r="5"/></svg>' );
$ids['jpg']  = tthh_attachment( 'tthh-photo.jpg', 'image/jpeg', (string) ( function () {
	$im = imagecreatetruecolor( 8, 8 );
	ob_start();
	imagejpeg( $im );
	return ob_get_clean();
} )() );
$ids['page'] = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'درباره تک طب', 'post_name' => 'tthh-about' ) );
update_post_meta( $ids['page'], TTHH_FIXTURE, 1 );

// فهرست به شکل منوی فعلی سایت.
$menu        = wp_create_nav_menu( 'tthh-main' );
$ids['menu'] = (int) $menu;
$ids['nav']  = array();
$ids['nav']['home'] = tthh_menu_item( $menu, array( 'menu-item-title' => 'صفحه اصلی', 'menu-item-url' => home_url( '/' ), 'menu-item-type' => 'custom' ) );
$ids['nav']['cats'] = tthh_menu_item( $menu, array( 'menu-item-title' => 'دسته بندی کالا ها', 'menu-item-url' => home_url( '/shop/' ), 'menu-item-type' => 'custom' ) );
$navcat = array();
foreach ( $names as $n ) {
	$navcat[ $n ] = tthh_menu_item( $menu, array( 'menu-item-title' => $n, 'menu-item-object' => 'product_cat', 'menu-item-object-id' => $ids['mains'][ $n ], 'menu-item-type' => 'taxonomy', 'menu-item-parent-id' => $ids['nav']['cats'] ) );
}
// یک زیرمورد product_cat زیر یک مورد product_cat ⇒ نباید سطح اول خوانده شود.
tthh_menu_item( $menu, array( 'menu-item-title' => 'یونیت', 'menu-item-object' => 'product_cat', 'menu-item-object-id' => $ids['chair'], 'menu-item-type' => 'taxonomy', 'menu-item-parent-id' => $navcat['دندانپزشکی'] ) );
$ids['nav']['shop']    = tthh_menu_item( $menu, array( 'menu-item-title' => 'فروشگاه', 'menu-item-url' => home_url( '/shop/' ), 'menu-item-type' => 'custom' ) );
$ids['nav']['blog']    = tthh_menu_item( $menu, array( 'menu-item-title' => 'وبلاگ', 'menu-item-url' => home_url( '/blog/' ), 'menu-item-type' => 'custom' ) );
$ids['nav']['repair']  = tthh_menu_item( $menu, array( 'menu-item-title' => 'درخواست تعمیرات', 'menu-item-url' => home_url( '/https-tecteb-com-repair-request/' ), 'menu-item-type' => 'custom' ) );
$ids['nav']['about']   = tthh_menu_item( $menu, array( 'menu-item-title' => 'درباره تک طب', 'menu-item-object' => 'page', 'menu-item-object-id' => $ids['page'], 'menu-item-type' => 'post_type' ) );
$ids['nav']['contact'] = tthh_menu_item( $menu, array( 'menu-item-title' => 'تماس با ما <b>!</b>', 'menu-item-url' => home_url( '/contact/' ), 'menu-item-type' => 'custom' ) );
$ids['nav']['seller']  = tthh_menu_item( $menu, array( 'menu-item-title' => 'در تک طب فروشنده شوید!', 'menu-item-url' => home_url( '/landing-seller/' ), 'menu-item-type' => 'custom' ) );

update_option( 'tthh_fixture_ids', $ids, false );
echo wp_json_encode( $ids, JSON_UNESCAPED_UNICODE ), "\n";
