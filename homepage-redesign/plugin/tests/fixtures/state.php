<?php
/**
 * وضعیت تنظیمات برای آزمون مرورگری — فقط وردپرس یکبارمصرف محلی.
 *
 * wp eval-file tests/fixtures/state.php shipped|full|front|unfront|show
 *
 *   shipped  همان چیزی که پس از نصب و انتخاب دسته‌ها دیده می‌شود: هیچ نشانی‌ای،
 *            هیچ خدمتی، هیچ مرحله‌ای — تصمیم‌های D6/D7/بند ۳ مالک.
 *   full     همهٔ بخش‌ها با متن آزمایشی (فقط برای سنجیدن چیدمان؛ متن تأییدشده نیست).
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

switch ( $verb ) {
	case 'shipped':
		update_option( Settings::OPTION, array( 'categories' => $cats ) );
		break;
	case 'full':
		update_option(
			Settings::OPTION,
			array(
				'categories'       => $cats,
				'links'            => array( 'quote' => '/quote/', 'supplier' => '/vendor/application/', 'supplier_terms' => '/terms/', 'all_categories' => '/shop/' ),
				'chips'            => array( 'پالس‌اکسیمتر', 'فشارسنج', 'آمبوبگ', 'دستکش جراحی' ),
				'services_enabled' => true,
				'services_lead'    => 'متن آزمایشی چیدمان، تأییدنشده.',
				'services'         => array(
					array( 'title' => 'خدمت آزمایشی یک', 'text' => 'متن آزمایشی برای سنجیدن چیدمان کارت.', 'icon' => 'quote' ),
					array( 'title' => 'خدمت آزمایشی دو', 'text' => 'متن آزمایشی برای سنجیدن چیدمان کارت.', 'icon' => 'list' ),
					array( 'title' => 'خدمت آزمایشی سه', 'text' => 'متن آزمایشی برای سنجیدن چیدمان کارت.', 'icon' => 'network' ),
					array( 'title' => 'خدمت آزمایشی چهار', 'text' => 'متن آزمایشی برای سنجیدن چیدمان کارت.', 'icon' => 'support' ),
				),
				'supplier_steps'   => array(
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
