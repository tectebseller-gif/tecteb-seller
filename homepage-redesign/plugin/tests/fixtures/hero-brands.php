<?php
/**
 * ۰٫۳٫۳ — دادهٔ آزمایشی برچسب‌های Hero و برندهای همکار. فقط وردپرس یکبارمصرف محلی.
 *
 * wp eval-file tests/fixtures/hero-brands.php labels|brands [n]|no-brands|normalized|pdf|editor|broken-logo|reset|show
 *
 *   labels       یک محصول آزمایشی در هر کدام از دسته‌های «پزشکی جنرال» و «بیهوشی و تنفسی» که فیکسچر هدر
 *                ساخته (پیدا شده با نام، بی شناسهٔ ثابت) تا هر چهار برچسب دستهٔ دارای محصول داشته باشند.
 *                «آزمایشگاه» و «جراحی» (یکی در ریشه، یکی زیر دندانپزشکی) از قبل هستند.
 *   brands [n]   n لوگوی آزمایشی (پیش‌فرض ۸) با نسبت‌های مختلف — پهن، بلند، مربع، شفاف — ساخته‌شده با GD و
 *                «Test logo» روی خودشان؛ و همان‌ها در تنظیمات (یکی غیرفعال، یکی بی نشانی).
 *   broken-logo  پیوستِ لوگوی برند دوم حذف می‌شود (لوگوی نامعتبر).
 *   reset        هر چه این فایل ساخته پاک می‌شود و brands خالی.
 *
 * @package Tecteb\Homepage
 */

use Tecteb\Homepage\Settings;

defined( 'ABSPATH' ) || exit;

if ( ! in_array( DB_NAME, array( 'tth_disposable', 'tth_perf' ), true ) || ! str_starts_with( home_url(), 'http://127.0.0.1' ) ) {
	fwrite( STDERR, "refused: not the disposable local site\n" );
	exit( 1 );
}

const TTH_HB = '_tth_hb_fixture';
$verb = $args[0] ?? 'show';

function tth_hb_term( string $name ): int {
	foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'name' => $name, 'parent' => 0 ) ) as $t ) {
		return (int) $t->term_id;
	}
	fwrite( STDERR, "category «{$name}» not found at the root — run the header fixture first\n" );
	exit( 1 );
}

function tth_hb_logo( int $w, int $h, string $label, bool $transparent ): int {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	$im = imagecreatetruecolor( $w, $h );
	imagesavealpha( $im, true );
	$bg = $transparent ? imagecolorallocatealpha( $im, 0, 0, 0, 127 ) : imagecolorallocate( $im, 240, 246, 248 );
	imagefill( $im, 0, 0, $bg );
	$ink = imagecolorallocate( $im, 15, 58, 85 );
	imagerectangle( $im, 2, 2, $w - 3, $h - 3, $ink );
	imagestring( $im, 5, 12, (int) ( $h / 2 ) - 8, $label, $ink );
	$tmp = wp_tempnam( 'tth-logo.png' );
	imagepng( $im, $tmp );
	$id = media_handle_sideload( array( 'name' => sanitize_title( $label ) . '.png', 'tmp_name' => $tmp ), 0, $label );
	if ( is_wp_error( $id ) ) {
		fwrite( STDERR, $id->get_error_message() . "\n" );
		exit( 1 );
	}
	update_post_meta( $id, TTH_HB, '1' );
	update_post_meta( $id, '_tth_fixture', '1' );
	return (int) $id;
}

function tth_hb_reset(): void {
	$editor = get_user_by( 'login', 'tth_editor' );
	if ( $editor ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $editor->ID );
	}
	foreach ( get_posts( array( 'post_type' => array( 'product', 'attachment' ), 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => TTH_HB, 'fields' => 'ids' ) ) as $id ) {
		'attachment' === get_post_type( $id ) ? wp_delete_attachment( $id, true ) : wp_delete_post( $id, true );
	}
	$s = (array) get_option( Settings::OPTION, array() );
	if ( $s ) {
		$s['brands']      = array();
		$s['hero_labels'] = array( 0, 0, 0, 0 );
		$s['hero_items']  = Settings::default_hero_items(); // ۰٫۳٫۴
		update_option( Settings::OPTION, $s );
	}
	delete_option( 'tth_hb_ids' );
}

switch ( $verb ) {
	case 'labels':
		$ids = array();
		foreach ( array( 'پزشکی جنرال' => 'ترالی اورژانس (آزمایشی)', 'بیهوشی و تنفسی' => 'دستگاه بیهوشی (آزمایشی)' ) as $cat => $name ) {
			$p = new WC_Product_Simple();
			$p->set_name( $name );
			$p->set_status( 'publish' );
			$p->set_regular_price( '1000000' );
			$p->set_category_ids( array( tth_hb_term( $cat ) ) );
			$id = (int) $p->save();
			update_post_meta( $id, TTH_HB, '1' );
			update_post_meta( $id, '_tth_fixture', '1' );
			$ids[] = $id;
		}
		echo wp_json_encode( $ids ), "\n";
		break;
	case 'brands':
		$n      = max( 1, (int) ( $args[1] ?? 8 ) );
		$shapes = array( array( 600, 150, false ), array( 200, 400, false ), array( 300, 300, true ), array( 900, 180, true ), array( 260, 260, false ), array( 480, 240, false ) );
		$brands = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$shape    = $shapes[ $i % count( $shapes ) ];
			$label    = 'Test logo ' . chr( 65 + $i );
			$brands[] = array(
				'name'   => 'برند آزمایشی ' . chr( 65 + $i ),
				'logo'   => tth_hb_logo( $shape[0], $shape[1], $label, $shape[2] ),
				'url'    => 1 === $i ? '' : '/landing-seller/?brand=' . chr( 97 + $i ),
				'active' => 3 !== $i,
			);
		}
		$s           = (array) get_option( Settings::OPTION, array() );
		$s['brands'] = $brands;
		update_option( Settings::OPTION, $s );
		update_option( 'tth_hb_ids', array_column( $brands, 'logo' ) );
		echo wp_json_encode( array_column( $brands, 'logo' ) ), "\n";
		break;
	case 'pdf':
		// پیوستی که تصویر نیست (برای «لوگوی نامعتبر»).
		$id = wp_insert_attachment( array( 'post_title' => 'not an image', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ) );
		update_post_meta( $id, TTH_HB, '1' );
		update_post_meta( $id, '_tth_fixture', '1' );
		echo (int) $id, "\n";
		break;
	case 'editor':
		// کاربری بی manage_woocommerce: نه صفحه را می‌بیند نه می‌تواند ذخیره کند.
		if ( ! get_user_by( 'login', 'tth_editor' ) ) {
			$uid = wp_insert_user( array( 'user_login' => 'tth_editor', 'user_pass' => 'local-only-editor', 'role' => 'editor', 'user_email' => 'tth_editor@example.invalid' ) );
			update_user_meta( $uid, TTH_HB, '1' );
		}
		echo "editor\n";
		break;
	case 'normalized':
		// تنظیمات ذخیره‌شده به همان شکلی که افزونه می‌خواندشان (برای مقایسهٔ «پیش/پس» بی تفاوت‌های شکلی).
		echo wp_json_encode( Settings::normalize( (array) get_option( Settings::OPTION, array() ), false ) ), "\n";
		break;
	case 'no-brands':
		$s           = (array) get_option( Settings::OPTION, array() );
		$s['brands'] = array();
		update_option( Settings::OPTION, $s );
		echo "no brands\n";
		break;
	case 'broken-logo':
		$logos = (array) get_option( 'tth_hb_ids', array() );
		wp_delete_attachment( (int) $logos[0], true );
		echo "deleted {$logos[0]}\n";
		break;
	case 'reset':
		tth_hb_reset();
		echo "reset\n";
		break;
	default:
		Settings::forget();
		echo wp_json_encode( array( 'labels' => \Tecteb\Homepage\Catalog::hero_labels(), 'brands' => Settings::get()['brands'] ) ), "\n";
}
