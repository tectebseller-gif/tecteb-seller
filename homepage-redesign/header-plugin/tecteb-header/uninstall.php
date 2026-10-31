<?php
/**
 * حذف افزونه: فقط دادهٔ همین افزونه پاک می‌شود — دو option و transient ِ دسته‌ها.
 * هیچ دسته، محصول، تصویر، فهرست وردپرس، قالب Elementor (از جمله هدر ۲۶۵۲) یا تنظیم
 * ووکامرس، دکان و بازارگاه لمس نمی‌شود.
 *
 * @package Tecteb\Header
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$tecteb_header_gen = (int) get_option( 'tecteb_header_gen', 0 );
delete_transient( 'tecteb_header_cats_' . $tecteb_header_gen );
delete_option( 'tecteb_header_settings' );
delete_option( 'tecteb_header_gen' );
