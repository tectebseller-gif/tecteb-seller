<?php
/**
 * حذف افزونه: تنها دادهٔ این افزونه — یک option — پاک می‌شود. هیچ محصول، دسته،
 * تصویر یا تنظیم ووکامرس، دکان یا بازارگاه لمس نمی‌شود.
 *
 * @package Tecteb\Homepage
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'tecteb_home_settings' );
