<?php
/**
 * محصولات آزمایشی با همهٔ شکل‌های قیمت ووکامرس — فقط وردپرس یکبارمصرف محلی (۰٫۳٫۲).
 *
 * wp eval-file tests/fixtures/prices.php seed|reset|ids|inquiry <text>|no-inquiry|currency <usd|irt>|suffix <text>|format <staging|local>
 *
 *   seed      ده محصول با قیمت ساده، تخفیف، بازهٔ متغیر، متغیرِ هم‌قیمت با تخفیف، متغیر با تخفیفِ
 *             بخشی، متغیرِ بی‌قیمت، بی‌قیمت، قیمت صفر، گروهی و متغیرِ ناموجود؛ و همان‌ها را به همان
 *             ترتیب در «محصولات منتخب» می‌گذارد (دسته‌ها و نشانی‌ها از state.php full).
 *   inquiry   فیلتر استاندارد `woocommerce_empty_price_html` را با یک متن فعال می‌کند (جانشینِ هر
 *             تنظیمِ «استعلام قیمت» روی سایت) — با mu-plugin ِ tth-price-helper.php.
 *   currency  usd ⇒ «$» چپ با دو رقم اعشار؛ irt ⇒ همان تنظیم fixture اصلی.
 *   suffix    پسوند نمایش قیمت ووکامرس (`woocommerce_price_display_suffix`)؛ خالی ⇒ حذف.
 *
 * محصولات `_tth_fixture` دارند، پس `seed.php reset` هم برشان می‌دارد.
 *
 * @package Tecteb\Homepage
 */

use Tecteb\Homepage\Settings;

defined( 'ABSPATH' ) || exit;

if ( 'tth_disposable' !== DB_NAME || ! str_starts_with( home_url(), 'http://127.0.0.1' ) ) {
	fwrite( STDERR, "refused: not the disposable local site\n" );
	exit( 1 );
}

const TTH_PRICE_FIXTURE = '_tth_price_fixture';
$verb   = $args[0] ?? 'seed';
$helper = WPMU_PLUGIN_DIR . '/tth-price-helper.php';

function tth_price_reset(): void {
	foreach ( get_posts( array( 'post_type' => array( 'product', 'product_variation' ), 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => TTH_PRICE_FIXTURE, 'fields' => 'ids' ) ) as $id ) {
		$p = wc_get_product( $id );
		if ( $p instanceof WC_Product ) {
			foreach ( $p->get_children() as $child ) {
				if ( 'product_variation' === get_post_type( $child ) ) {
					wp_delete_post( $child, true );
				}
			}
		}
		wp_delete_post( $id, true );
	}
	delete_option( 'tth_price_fixture_ids' );
}

function tth_price_mark( int $id ): int {
	update_post_meta( $id, TTH_PRICE_FIXTURE, '1' );
	update_post_meta( $id, '_tth_fixture', '1' );
	return $id;
}

function tth_price_simple( string $name, string $regular, string $sale = '', array $extra = array() ): int {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_status( 'publish' );
	$p->set_regular_price( $regular );
	if ( '' !== $sale ) {
		$p->set_sale_price( $sale );
	}
	$p->set_stock_status( $extra['stock'] ?? 'instock' );
	$p->set_category_ids( $extra['cats'] ?? array() );
	if ( ! empty( $extra['image'] ) ) {
		$p->set_image_id( $extra['image'] );
	}
	return tth_price_mark( (int) $p->save() );
}

/** @param list<array{0:string,1:string,2?:string}> $rows  [label, regular, sale] */
function tth_price_variable( string $name, array $rows, array $extra = array() ): int {
	$p    = new WC_Product_Variable();
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'اندازه' );
	$attr->set_options( array_column( $rows, 0 ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$p->set_name( $name );
	$p->set_status( 'publish' );
	$p->set_attributes( array( $attr ) );
	$p->set_category_ids( $extra['cats'] ?? array() );
	if ( ! empty( $extra['image'] ) ) {
		$p->set_image_id( $extra['image'] );
	}
	$id = tth_price_mark( (int) $p->save() );
	foreach ( $rows as $row ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $id );
		$v->set_attributes( array( sanitize_title( 'اندازه' ) => $row[0] ) );
		$v->set_regular_price( $row[1] );
		if ( '' !== ( $row[2] ?? '' ) ) {
			$v->set_sale_price( $row[2] );
		}
		$v->set_stock_status( $extra['stock'] ?? 'instock' );
		$v->set_status( 'publish' );
		tth_price_mark( (int) $v->save() );
	}
	WC_Product_Variable::sync( $id );
	wc_delete_product_transients( $id );
	return $id;
}

switch ( $verb ) {
	case 'reset':
		tth_price_reset();
		if ( file_exists( $helper ) ) {
			unlink( $helper );
		}
		delete_option( 'tth_price_inquiry' );
		delete_option( 'woocommerce_price_display_suffix' );
		echo "reset\n";
		return;
	case 'ids':
		echo wp_json_encode( get_option( 'tth_price_fixture_ids' ) ), "\n";
		return;
	case 'inquiry':
		copy( __DIR__ . '/tth-price-helper.php', $helper );
		update_option( 'tth_price_inquiry', (string) ( $args[1] ?? 'استعلام قیمت' ) );
		echo "inquiry on\n";
		return;
	case 'no-inquiry':
		delete_option( 'tth_price_inquiry' );
		echo "inquiry off\n";
		return;
	case 'currency':
		if ( 'usd' === ( $args[1] ?? '' ) ) {
			update_option( 'woocommerce_currency', 'USD' );
			update_option( 'woocommerce_price_num_decimals', 2 );
			update_option( 'woocommerce_currency_pos', 'left' );
		} else {
			update_option( 'woocommerce_currency', 'IRT' );
			update_option( 'woocommerce_price_num_decimals', 0 );
			update_option( 'woocommerce_currency_pos', 'right_space' );
		}
		echo get_woocommerce_currency(), "\n";
		return;
	case 'format':
		// ۰٫۳٫۳: «staging» = همان چیزی که روی Staging دیده شد (`5634400تومان`): بی جداکنندهٔ هزارگان، نماد بی فاصله.
		if ( 'staging' === ( $args[1] ?? '' ) ) {
			update_option( 'woocommerce_price_thousand_sep', '' );
			update_option( 'woocommerce_currency_pos', 'right' );
		} else {
			update_option( 'woocommerce_price_thousand_sep', ',' );
			update_option( 'woocommerce_currency_pos', 'right_space' );
		}
		echo get_option( 'woocommerce_currency_pos' ), "\n";
		return;
	case 'suffix':
		'' === (string) ( $args[1] ?? '' ) ? delete_option( 'woocommerce_price_display_suffix' ) : update_option( 'woocommerce_price_display_suffix', (string) $args[1] );
		echo "suffix\n";
		return;
}

// seed
tth_price_reset();
$base = get_option( 'tth_fixture_ids' );
if ( ! is_array( $base ) ) {
	fwrite( STDERR, "seed first: wp eval-file tests/fixtures/seed.php seed\n" );
	exit( 1 );
}
$cat = array( (int) $base['cats']['monitor'] );
$img = $base['images'];

$ids                   = array();
$ids['simple']         = tth_price_simple( 'مانیتور علائم حیاتی ساده', '45000000', '', array( 'cats' => $cat, 'image' => $img[0] ) );
$ids['sale']           = tth_price_simple( 'پالس‌اکسیمتر با تخفیف', '12500000', '9800000', array( 'cats' => $cat, 'image' => $img[1] ) );
$ids['range']          = tth_price_variable( 'سرنگ‌پمپ چندمدلی (بازهٔ قیمت)', array( array( 'پایه', '3200000' ), array( 'پیشرفته', '128500000' ) ), array( 'cats' => $cat, 'image' => $img[2] ) );
$ids['var_sale']       = tth_price_variable( 'دستگاه ساکشن هم‌قیمت با تخفیف', array( array( 'کوچک', '7000000', '6300000' ), array( 'بزرگ', '7000000', '6300000' ) ), array( 'cats' => $cat ) );
$ids['var_partial']    = tth_price_variable( 'ونتیلاتور با تخفیف بخشی', array( array( 'A', '980000000', '915000000' ), array( 'B', '1250000000' ) ), array( 'cats' => $cat, 'image' => $img[0] ) );
$ids['var_noprice']    = tth_price_variable( 'دستگاه متغیرِ بی‌قیمت', array( array( 'یک', '' ), array( 'دو', '' ) ), array( 'cats' => $cat ) );
$ids['noprice']        = tth_price_simple( 'تخت بیمارستانی بی‌قیمت', '', '', array( 'cats' => $cat ) );
$ids['zero']           = tth_price_simple( 'دفترچهٔ راهنما با قیمت صفر', '0', '', array( 'cats' => $cat ) );
$g                     = new WC_Product_Grouped();
$g->set_name( 'بستهٔ گروهیِ پایش' );
$g->set_status( 'publish' );
$g->set_category_ids( $cat );
$g->set_children( array( $ids['simple'], $ids['sale'] ) );
$ids['grouped']        = tth_price_mark( (int) $g->save() );
$ids['var_out']        = tth_price_variable( 'الکتروکوتر ناموجود', array( array( 'X', '54000000' ), array( 'Y', '61000000' ) ), array( 'cats' => $cat, 'stock' => 'outofstock' ) );

update_option( 'tth_price_fixture_ids', $ids );
$settings                  = (array) get_option( Settings::OPTION, array() );
$settings['products']      = array_values( $ids );
$settings['product_count'] = count( $ids );
update_option( Settings::OPTION, $settings );
Settings::forget();
echo wp_json_encode( $ids ), "\n";
