<?php
/**
 * دادهٔ نمونه برای وردپرس یکبارمصرفِ محلی — هرگز روی staging یا سایت اصلی.
 *
 * wp eval-file tests/fixtures/seed.php seed|reset|ids
 *
 * دسته‌ها با نام فارسی (پس slug درصدی، مثل سایت واقعی)، و محصولات در هر حالتی
 * که صفحهٔ اصلی باید درست جوابش را بدهد: ویژه و موجود، ویژه بی‌تصویر، ویژه
 * بی‌قیمت، ویژه ناموجود، ویژهٔ پیش‌نویس، ویژهٔ پنهان از فروشگاه، و غیرویژه.
 *
 * @package Tecteb\Homepage
 */

defined( 'ABSPATH' ) || exit;

if ( 'tth_disposable' !== DB_NAME || ! str_starts_with( home_url(), 'http://127.0.0.1' ) ) {
	fwrite( STDERR, "refused: not the disposable local site\n" );
	exit( 1 );
}

const TTH_FIXTURE = '_tth_fixture';

$verb = $args[0] ?? 'seed';
$root = dirname( __DIR__, 3 );

function tth_reset(): void {
	foreach ( get_posts( array( 'post_type' => array( 'product', 'attachment', 'page', 'post' ), 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => TTH_FIXTURE, 'fields' => 'ids' ) ) as $id ) {
		'attachment' === get_post_type( $id ) ? wp_delete_attachment( $id, true ) : wp_delete_post( $id, true );
	}
	foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'meta_key' => TTH_FIXTURE ) ) as $term ) {
		wp_delete_term( $term->term_id, 'product_cat' );
	}
	delete_option( 'tecteb_home_settings' );
	update_option( 'show_on_front', 'posts' );
	delete_option( 'page_on_front' );
}

function tth_image( string $file, string $title ): int {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	$tmp = wp_tempnam( basename( $file ) );
	copy( $file, $tmp );
	$id = media_handle_sideload( array( 'name' => basename( $file ), 'tmp_name' => $tmp ), 0, $title );
	if ( is_wp_error( $id ) ) {
		fwrite( STDERR, $id->get_error_message() . "\n" );
		exit( 1 );
	}
	update_post_meta( $id, TTH_FIXTURE, '1' );
	return (int) $id;
}

function tth_term( string $name, int $parent = 0, string $description = '' ): int {
	$r = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent, 'description' => $description ) );
	$id = is_wp_error( $r ) ? (int) $r->get_error_data( 'term_exists' ) : (int) $r['term_id'];
	update_term_meta( $id, TTH_FIXTURE, '1' );
	return $id;
}

function tth_product( array $p ): int {
	$product = new WC_Product_Simple();
	$product->set_name( $p['name'] );
	$product->set_status( $p['status'] ?? 'publish' );
	$product->set_featured( $p['featured'] ?? false );
	$product->set_catalog_visibility( $p['visibility'] ?? 'visible' );
	if ( isset( $p['price'] ) ) {
		$product->set_regular_price( (string) $p['price'] );
	}
	$product->set_stock_status( $p['stock'] ?? 'instock' );
	$product->set_category_ids( $p['cats'] ?? array() );
	$product->set_menu_order( $p['order'] ?? 0 );
	if ( ! empty( $p['image'] ) ) {
		$product->set_image_id( $p['image'] );
	}
	if ( ! empty( $p['specs'] ) ) {
		$attrs = array();
		foreach ( $p['specs'] as $label => $value ) {
			$a = new WC_Product_Attribute();
			$a->set_name( $label );
			$a->set_options( array( $value ) );
			$a->set_visible( true );
			$attrs[] = $a;
		}
		$hidden = new WC_Product_Attribute();
		$hidden->set_name( 'کد داخلی' );
		$hidden->set_options( array( 'نباید دیده شود' ) );
		$hidden->set_visible( false );
		array_unshift( $attrs, $hidden );
		$product->set_attributes( $attrs );
	}
	$id = $product->save();
	update_post_meta( $id, TTH_FIXTURE, '1' );
	return $id;
}

if ( 'reset' === $verb ) {
	tth_reset();
	echo "reset\n";
	return;
}

if ( 'ids' === $verb ) {
	echo wp_json_encode( get_option( 'tth_fixture_ids' ) ), "\n";
	return;
}

tth_reset();
update_option( 'woocommerce_currency', 'IRT' );
update_option( 'woocommerce_price_num_decimals', 0 );
update_option( 'woocommerce_currency_pos', 'right_space' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

$assets  = $root . '/concept-c/assets/';
$img_mon = tth_image( $assets . 'product-monitor-640.webp', 'مانیتور' );
$img_oxi = tth_image( $assets . 'product-oximeter-640.webp', 'پالس‌اکسیمتر' );
$img_pmp = tth_image( $assets . 'product-pump-640.webp', 'سرنگ‌پمپ' );

$equip = tth_term( 'تجهیزات پزشکی' );
$cats  = array(
	'monitor'  => tth_term( 'پایش بیمار', $equip, "مانیتور، پالس‌اکسیمتر و ECG\nسطر دوم نباید نمایش داده شود" ),
	'or'       => tth_term( 'اتاق عمل', 0, 'چراغ، تخت و ابزار جراحی' ),
	'lab'      => tth_term( 'آزمایشگاه', 0, '' ),
	'consum'   => tth_term( 'ملزومات مصرفی', 0, 'تزریق، پانسمان و حفاظت فردی' ),
	'empty'    => tth_term( 'دستهٔ بی‌محصول', 0, '' ),
	'evil'     => tth_term( 'دسته <script>alert(1)</script> "ویژه"', 0, '<img src=x onerror=alert(2)>' ),
);
update_term_meta( $cats['or'], 'thumbnail_id', $img_pmp );
// نامی که فقط کاربری با unfiltered_html می‌تواند بنویسد؛ wp_insert_term از CLI آن را پاک می‌کند.
global $wpdb;
$wpdb->update( $wpdb->terms, array( 'name' => 'دسته <script>alert(1)</script> "ویژه"' ), array( 'term_id' => $cats['evil'] ) );
$wpdb->update( $wpdb->term_taxonomy, array( 'description' => '<img src=x onerror=alert(2)>' ), array( 'term_id' => $cats['evil'] ) );
clean_term_cache( $cats['evil'], 'product_cat' );

$p = array();
$p['monitor']  = tth_product( array( 'name' => 'مانیتور علائم حیاتی پنج‌پارامتری', 'featured' => true, 'price' => 45000000, 'cats' => array( $cats['monitor'] ), 'image' => $img_mon, 'order' => 1, 'specs' => array( 'پارامترها' => 'ECG · SpO2 · NIBP', 'نصب' => 'رومیزی' ) ) );
$p['oxi']      = tth_product( array( 'name' => 'پالس‌اکسیمتر انگشتی با نمایشگر دوخطی و قاب محافظ سیلیکونی', 'featured' => true, 'cats' => array( $cats['monitor'] ), 'image' => $img_oxi, 'order' => 2 ) );
$p['pump']     = tth_product( array( 'name' => 'سرنگ‌پمپ تک‌کاناله', 'featured' => true, 'price' => 31500000, 'cats' => array( $cats['consum'] ), 'image' => $img_pmp, 'order' => 3, 'stock' => 'onbackorder' ) );
$p['centri']   = tth_product( array( 'name' => 'سانتریفیوژ رومیزی <b>آزمایش</b>', 'featured' => true, 'price' => 12000000, 'cats' => array( $cats['lab'] ), 'order' => 4, 'stock' => 'outofstock' ) );
$p['draft']    = tth_product( array( 'name' => 'محصول ویژهٔ پیش‌نویس', 'featured' => true, 'status' => 'draft', 'price' => 1000, 'cats' => array( $cats['lab'] ) ) );
$p['hidden']   = tth_product( array( 'name' => 'محصول ویژهٔ پنهان از فروشگاه', 'featured' => true, 'visibility' => 'hidden', 'price' => 1000, 'cats' => array( $cats['lab'] ) ) );
$p['search']   = tth_product( array( 'name' => 'محصول ویژهٔ فقط در جست‌وجو', 'featured' => true, 'visibility' => 'search', 'price' => 1000, 'cats' => array( $cats['lab'] ) ) );
$p['plain']    = tth_product( array( 'name' => 'دستکش جراحی غیرویژه', 'featured' => false, 'price' => 90000, 'cats' => array( $cats['consum'] ) ) );
$p['light']    = tth_product( array( 'name' => 'چراغ سیالتیک غیرویژه', 'featured' => false, 'price' => 90000, 'cats' => array( $cats['or'] ) ) );
$p['evil']     = tth_product( array( 'name' => 'کالای غیرویژه در دستهٔ ناامن', 'price' => 1, 'cats' => array( $cats['evil'] ) ) );

$page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'صفحهٔ اصلی تازه',
		'post_name'    => 'tecteb-home-new',
		'post_content' => '[tecteb_home]',
	)
);
update_post_meta( $page, TTH_FIXTURE, '1' );
update_post_meta( $page, '_wp_page_template', 'tecteb-homepage-full-width.php' );
$other = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'صفحهٔ عادی', 'post_name' => 'plain-page', 'post_content' => 'متن عادی.' ) );
update_post_meta( $other, TTH_FIXTURE, '1' );

// جانشین‌های محلیِ دو صفحه‌ای که روی سایت واقعی هست (تعمیرات) یا خواهد بود
// (درخواست همکاری): فقط تا کلیک روی کارت‌ها در آزمون مرورگری به صفحه‌ای واقعی برسد.
foreach ( array( 'medical-equipment-repair' => 'تعمیرات تجهیزات پزشکی (جانشین محلی)', 'supplier-application' => 'درخواست همکاری (جانشین محلی)' ) as $slug => $title ) {
	$standin = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_content' => 'صفحهٔ جانشین برای آزمون محلی.' ) );
	update_post_meta( $standin, TTH_FIXTURE, '1' );
}

update_option( 'tth_fixture_ids', array( 'cats' => $cats, 'products' => $p, 'page' => $page, 'other' => $other, 'images' => array( $img_mon, $img_oxi, $img_pmp ) ) );
echo wp_json_encode( array( 'cats' => $cats, 'products' => $p, 'page' => $page, 'other' => $other ) ), "\n";
