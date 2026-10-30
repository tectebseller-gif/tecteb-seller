<?php
/**
 * وضعیت تنظیمات برای آزمون مرورگری — فقط وردپرس یکبارمصرف محلی.
 *
 * wp eval-file tests/fixtures/state.php shipped|full|front|unfront|show
 *
 *   shipped  پس از نصب، با دسته‌ها و محصولات منتخب و بی هیچ نشانیِ تأییدشده:
 *            فقط مسیر فروشگاه، بی تعمیرات و بی همکاری.
 *   full     هر سه مسیر و همهٔ بخش‌ها با نشانی و متن آزمایشی (فقط برای سنجیدن چیدمان).
 *
 * @package Tecteb\Homepage
 */

use Tecteb\Homepage\Settings;

defined( 'ABSPATH' ) || exit;

if ( 'tth_disposable' !== DB_NAME || ! str_starts_with( home_url(), 'http://127.0.0.1' ) ) {
	fwrite( STDERR, "refused: not the disposable local site\n" );
	exit( 1 );
}

$ids  = get_option( 'tth_fixture_ids' );
$c    = $ids['cats'];
$verb = $args[0] ?? 'show';
$cats = array( array( 'id' => $c['monitor'], 'icon' => 'monitor' ), array( 'id' => $c['or'], 'icon' => 'or' ), array( 'id' => $c['lab'], 'icon' => 'lab' ), array( 'id' => $c['consum'], 'icon' => 'syringe' ) );

$pr   = $ids['products'];
$pick = array( $pr['monitor'], $pr['oxi'], $pr['pump'], $pr['centri'] );

switch ( $verb ) {
	case 'shipped':
		// همان چیزی که پس از نصب، با دسته‌ها و محصولات منتخب و بی هیچ نشانیِ تأییدشده دیده می‌شود.
		update_option( Settings::OPTION, array( 'categories' => $cats, 'products' => $pick ) );
		break;
	case 'full':
		update_option(
			Settings::OPTION,
			array(
				'categories'     => $cats,
				'products'       => $pick,
				'links'          => array( 'repair' => '/medical-equipment-repair/', 'supplier' => '/supplier-application/', 'supplier_terms' => '/terms/', 'all_categories' => '/shop/' ),
				'chips'          => array( 'پالس‌اکسیمتر', 'فشارسنج', 'آمبوبگ', 'دستکش جراحی' ),
				'supplier_steps' => array(
					array( 'title' => 'گام آزمایشی', 'text' => 'متن آزمایشی' ),
					array( 'title' => 'گام آزمایشی', 'text' => 'متن آزمایشی' ),
					array( 'title' => 'گام آزمایشی', 'text' => 'متن آزمایشی' ),
				),
			)
		);
		break;
	case 'front':
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', (int) $ids['page'] );
		break;
	case 'unfront':
		update_option( 'show_on_front', 'posts' );
		delete_option( 'page_on_front' );
		break;
}
echo wp_json_encode( get_option( Settings::OPTION ) ), "\n";
