<?php
/**
 * Plugin Name:       Tecteb Header
 * Plugin URI:        https://tecteb.com/
 * Description:       هدر سراسری تک‌طب (طرح B): لوگو، جست‌وجو، حساب، سبد، علاقه‌مندی، منو، دسته‌بندی کالاها و نسخهٔ موبایل — با shortcode و جایگزینی اختیاری هدر Elementor. هیچ دادهٔ ووکامرس، دکان یا بازارگاه را نمی‌نویسد.
 * Version:           0.2.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Tecteb
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tecteb-header
 *
 * @package Tecteb\Header
 */

defined( 'ABSPATH' ) || exit;

define( 'TECTEB_HEADER_VERSION', '0.2.1' );
define( 'TECTEB_HEADER_FILE', __FILE__ );
define( 'TECTEB_HEADER_DIR', plugin_dir_path( __FILE__ ) );
define( 'TECTEB_HEADER_URL', plugin_dir_url( __FILE__ ) );

require_once TECTEB_HEADER_DIR . 'includes/class-settings.php';
require_once TECTEB_HEADER_DIR . 'includes/class-menu.php';
require_once TECTEB_HEADER_DIR . 'includes/class-account.php';
require_once TECTEB_HEADER_DIR . 'includes/class-renderer.php';
require_once TECTEB_HEADER_DIR . 'includes/class-assets.php';
require_once TECTEB_HEADER_DIR . 'includes/class-elementor.php';
require_once TECTEB_HEADER_DIR . 'includes/class-inspect.php';
require_once TECTEB_HEADER_DIR . 'includes/class-replace.php';
require_once TECTEB_HEADER_DIR . 'includes/class-cache.php';
require_once TECTEB_HEADER_DIR . 'includes/class-admin.php';
require_once TECTEB_HEADER_DIR . 'includes/class-plugin.php';

add_action( 'plugins_loaded', array( \Tecteb\Header\Plugin::class, 'boot' ) );

// بازگشت فوری: اگر جایگزینی روشن است، غیرفعال‌سازی (و فعال‌سازی دوباره) کش صفحه را پاک می‌کند تا
// صفحه‌های کش‌شده با هدر قبلی/تازه نمانند. وقتی جایگزینی خاموش است، هیچ پاک‌سازی‌ای نیست.
register_activation_hook( __FILE__, array( \Tecteb\Header\Plugin::class, 'lifecycle_purge' ) );
register_deactivation_hook( __FILE__, array( \Tecteb\Header\Plugin::class, 'lifecycle_purge' ) );
