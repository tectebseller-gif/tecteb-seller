<?php
/**
 * Plugin Name:       Tecteb Homepage
 * Plugin URI:        https://tecteb.com/
 * Description:       صفحهٔ اصلی تک‌طب (Concept C): فقط لایهٔ نمایش، با خواندن از ووکامرس. هیچ دادهٔ ووکامرس، دکان یا بازارگاه را نمی‌نویسد.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Tecteb
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tecteb-homepage
 * Domain Path:       /languages
 *
 * @package Tecteb\Homepage
 */

defined( 'ABSPATH' ) || exit;

define( 'TECTEB_HOME_VERSION', '0.2.0' );
define( 'TECTEB_HOME_FILE', __FILE__ );
define( 'TECTEB_HOME_DIR', plugin_dir_path( __FILE__ ) );
define( 'TECTEB_HOME_URL', plugin_dir_url( __FILE__ ) );

require_once TECTEB_HOME_DIR . 'includes/class-settings.php';
require_once TECTEB_HOME_DIR . 'includes/class-catalog.php';
require_once TECTEB_HOME_DIR . 'includes/class-renderer.php';
require_once TECTEB_HOME_DIR . 'includes/class-assets.php';
require_once TECTEB_HOME_DIR . 'includes/class-page-template.php';
require_once TECTEB_HOME_DIR . 'includes/class-cache.php';
require_once TECTEB_HOME_DIR . 'includes/class-admin.php';
require_once TECTEB_HOME_DIR . 'includes/class-plugin.php';

add_action( 'plugins_loaded', array( \Tecteb\Homepage\Plugin::class, 'boot' ) );
