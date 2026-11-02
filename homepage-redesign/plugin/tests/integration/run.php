<?php
/**
 * آزمون یکپارچه روی وردپرس و ووکامرسِ واقعی (محلی، یکبارمصرف).
 *
 *   wp eval-file tests/fixtures/seed.php seed
 *   wp eval-file tests/integration/run.php
 *
 * هر بررسی یک خط PASS/FAIL چاپ می‌کند و در پایان شمارش؛ کد خروج ۱ اگر یکی شکست
 * خورد. هیچ چیزی روی سایتی جز وردپرس یکبارمصرف محلی اجرا نمی‌شود.
 *
 * @package Tecteb\Homepage
 */

use Tecteb\Homepage\Admin;
use Tecteb\Homepage\Assets;
use Tecteb\Homepage\Cache;
use Tecteb\Homepage\Catalog;
use Tecteb\Homepage\Plugin;
use Tecteb\Homepage\Renderer;
use Tecteb\Homepage\Settings;

defined( 'ABSPATH' ) || exit;

if ( 'tth_disposable' !== DB_NAME || ! str_starts_with( home_url(), 'http://127.0.0.1' ) ) {
	fwrite( STDERR, "refused: not the disposable local site\n" );
	exit( 1 );
}

$GLOBALS['tth_pass'] = 0;
$GLOBALS['tth_fail'] = 0;

function check( string $name, bool $ok, string $detail = '' ): void {
	$GLOBALS[ $ok ? 'tth_pass' : 'tth_fail' ]++;
	echo ( $ok ? 'PASS ' : 'FAIL ' ), $name, ( $ok || '' === $detail ? '' : '  — ' . $detail ), "\n";
}

function settings( array $patch ): void {
	$current = get_option( Settings::OPTION, array() );
	update_option( Settings::OPTION, array_replace( is_array( $current ) ? $current : array(), $patch ) );
	Settings::forget();
}

/** ۰٫۳٫۴: ردیف‌های برچسب Hero = پیش‌فرض، با وصله‌های داده‌شده (اندیس ⇒ کلیدها)، از مسیر sanitize. */
function hero_items( array $patch ): void {
	$rows = Settings::default_hero_items();
	foreach ( $patch as $i => $row ) {
		$rows[ $i ] = array_replace( $rows[ $i ], $row );
	}
	settings( array( 'hero_items' => $rows ) );
}

function html( string $sections = '' ): string {
	Renderer::reset();
	Settings::forget();
	return Plugin::shortcode( '' === $sections ? array() : array( 'sections' => $sections ) );
}

function dom( string $html ): DOMXPath {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>' );
	libxml_clear_errors();
	return new DOMXPath( $doc );
}

function nodes( string $html, string $xpath ): array {
	$out = array();
	foreach ( dom( $html )->query( $xpath ) as $n ) {
		$out[] = $n;
	}
	return $out;
}

function texts( string $html, string $xpath ): array {
	return array_map( static fn( $n ) => trim( preg_replace( '/\s+/u', ' ', $n->textContent ) ), nodes( $html, $xpath ) );
}

function cls( string $c ): string {
	return "contains(concat(' ', normalize-space(@class), ' '), ' $c ')";
}

function http( string $path ): string {
	$r = wp_remote_get( home_url( $path ), array( 'timeout' => 30 ) );
	return is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_body( $r );
}

/** همان درخواست HTTP با کوکی ورودِ یک کاربر (فقط وردپرس یکبارمصرف محلی). */
function http_as( string $login, string $path ): string {
	$u = get_user_by( 'login', $login );
	$c = wp_generate_auth_cookie( $u->ID, time() + 600, 'logged_in' );
	$r = wp_remote_get( home_url( $path ), array( 'timeout' => 30, 'cookies' => array( LOGGED_IN_COOKIE => $c ) ) );
	return is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_body( $r );
}

function db_digest(): array {
	global $wpdb;
	$out = array();
	foreach ( array( 'posts' => 'ID', 'postmeta' => 'meta_id', 'terms' => 'term_id', 'termmeta' => 'meta_id', 'term_relationships' => 'object_id', 'users' => 'ID', 'usermeta' => 'umeta_id', 'wc_product_meta_lookup' => 'product_id' ) as $t => $col ) {
		$out[ $t ] = $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(MAX($col),0)) FROM {$wpdb->prefix}$t" ); // phpcs:ignore WordPress.DB
	}
	$out['options'] = $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(option_name, '=', MD5(option_value) ORDER BY option_name)) FROM {$wpdb->options} WHERE option_name NOT LIKE '%transient%' AND option_name NOT IN ('cron','" . Settings::OPTION . "') AND option_name NOT LIKE 'action_scheduler%' AND option_name NOT LIKE 'woocommerce_admin%' AND option_name NOT LIKE '%_last_%'" ); // phpcs:ignore WordPress.DB
	return $out;
}

$ids = get_option( 'tth_fixture_ids' );
if ( ! is_array( $ids ) ) {
	fwrite( STDERR, "seed first: wp eval-file tests/fixtures/seed.php seed\n" );
	exit( 1 );
}
$c = $ids['cats'];
$p = $ids['products'];
delete_option( Settings::OPTION );
Settings::forget();

/* ------------------------------------------------------------ ۱. پیش‌فرض‌ها */
$h = html();
check( 'defaults: root wrapper rtl/fa', 1 === count( nodes( $h, '//div[' . cls( 'tecteb-home-v1' ) . ' and @dir="rtl" and @lang="fa"]' ) ) );
check( 'defaults: one h1', 1 === count( nodes( $h, '//h1' ) ) );
check( 'defaults: no categories section without selection', 0 === count( nodes( $h, '//*[@id="th-cats-title"]' ) ) );
check( 'defaults: no products section without a manual selection (featured flag ignored)', 0 === count( nodes( $h, '//*[@id="th-products-title"]' ) ) );
check( 'defaults: no services section exists any more', ! str_contains( $h, 'th-services' ) && ! str_contains( $h, 'th-service' ) );
// ۰٫۳٫۰: مقصدهای تأییدشدهٔ مالک پیش‌فرض‌اند — تعمیرات ⇒ صفحهٔ موجود تعمیرات، همکاری ⇒ /landing-seller/.
check( 'defaults: supplier section links to /landing-seller/', 1 === count( nodes( $h, '//*[@id="th-supplier-title"]' ) ) && 1 === count( nodes( $h, '//section[' . cls( 'th-section--join' ) . ']//a[@href="' . home_url( '/landing-seller/' ) . '"]' ) ) );
check( 'defaults: three routes shop → repair → supplier', array( 'th-route th-route--shop', 'th-route th-route--repair', 'th-route th-route--supplier' ) === array_map( static fn( $n ) => $n->getAttribute( 'class' ), nodes( $h, '//a[' . cls( 'th-route' ) . ']' ) ) );
check( 'defaults: repair route → /https-tecteb-com-repair-request/ (only a link)', home_url( '/https-tecteb-com-repair-request/' ) === nodes( $h, '//a[' . cls( 'th-route--repair' ) . ']' )[0]->getAttribute( 'href' ) && 0 === count( nodes( $h, '//form[not(@role="search")]' ) ) );
check( 'defaults: supplier route → /landing-seller/', home_url( '/landing-seller/' ) === nodes( $h, '//a[' . cls( 'th-route--supplier' ) . ']' )[0]->getAttribute( 'href' ) );
check( 'defaults: routes nav labelled, data-count=3', 1 === count( nodes( $h, '//nav[' . cls( 'th-routes' ) . ' and @data-count="3" and @aria-label]' ) ) );
check( 'defaults: supplier section has one button only (no «supplier terms» without a terms URL)', 1 === count( nodes( $h, '//section[' . cls( 'th-section--join' ) . ']//a' ) ) );
check( 'shop route: href is wc_get_page_permalink(shop)', wc_get_page_permalink( 'shop' ) === nodes( $h, '//a[' . cls( 'th-route--shop' ) . ']' )[0]->getAttribute( 'href' ) );
foreach ( array( 'درخواست تأمین', 'استعلام', 'پیش‌فاکتور', 'th-product__quote', 'th-route--buyer' ) as $gone ) {
	check( "retired: «{$gone}» nowhere on the page", ! str_contains( $h, $gone ) );
}
check( 'defaults: no quick chips', 0 === count( nodes( $h, '//*[' . cls( 'th-quick' ) . ']' ) ) );
check( 'defaults: no category select without categories', 0 === count( nodes( $h, '//select[@name="product_cat"]' ) ) );
check( 'defaults: audience line shows', 1 === count( nodes( $h, '//*[' . cls( 'th-audience' ) . ']' ) ) );
// ۰٫۳٫۰: هیچ قابلیت تأییدنشده‌ای (مقایسهٔ کنار هم، استعلام، آمار) در متن پیش‌فرض.
foreach ( array( 'کنار هم', 'مقایسه', 'تضمین', 'نمایندگی رسمی', '٪', 'هزار مشتری', 'رضایت' ) as $claim ) {
	check( "defaults: no unconfirmed claim «{$claim}» on the page", ! str_contains( $h, $claim ) );
}
check( 'no temporary #th-route links anywhere', ! str_contains( $h, '#th-route' ) && ! str_contains( $h, 'data-th-route' ) );
foreach ( array( 'th-sample', 'th-imgnote', 'th-hero__note', 'proto-', 'تصویر نمایشی', 'نمونه', 'در انتظار تأیید' ) as $needle ) {
	check( "no preview marker «{$needle}»", ! str_contains( $h, $needle ) );
}
$hrefs = array_map( static fn( $n ) => $n->getAttribute( 'href' ), nodes( $h, '//a' ) );
check( 'every link is absolute http(s) on this site', array() === array_filter( $hrefs, static fn( $u ) => ! str_starts_with( $u, home_url() ) ), implode( ' ', $hrefs ) );
check( 'search form goes to home_url with post_type=product', 1 === count( nodes( $h, '//form[@role="search" and @action="' . esc_url( home_url( '/' ) ) . '" and @method="get"]//input[@name="post_type" and @value="product"]' ) ) );
check( 'hero image eager, high priority, excluded from lazyload', 1 === count( nodes( $h, '//img[@fetchpriority="high" and @data-no-lazy="1" and not(@loading)]' ) ) );

/* ------------------------------------------------------------ ۱-ب. مسیر فروشگاه، ایمن */
$shop_id = (int) get_option( 'woocommerce_shop_page_id' );
update_option( 'woocommerce_shop_page_id', 0 );
$plan = Renderer::plan();
check( 'shop route: hidden (not pointed at the homepage) when no shop page is set', ! $plan['routes']['shop']['show'] && '' === Renderer::shop_url() && '' !== $plan['routes']['shop']['reason'] );
settings( array( 'links' => array( 'repair' => '', 'supplier' => '' ) ) );
check( 'shop route: no route cards at all when none has a destination', 0 === count( nodes( html( 'hero' ), '//nav[' . cls( 'th-routes' ) . ']' ) ) );
check( 'saved empty repair/supplier URL hides them (no fallback to the default)', '' === Settings::link( 'repair' ) && '' === Settings::link( 'supplier' ) );
delete_option( Settings::OPTION );
Settings::forget();
update_option( 'woocommerce_shop_page_id', $ids['page'] );
wp_update_post( array( 'ID' => $ids['page'], 'post_status' => 'draft' ) );
check( 'shop route: hidden when the shop page is not published', '' === Renderer::shop_url() );
wp_update_post( array( 'ID' => $ids['page'], 'post_status' => 'publish' ) );
update_option( 'woocommerce_shop_page_id', $shop_id );
check( 'shop route: back once the shop page is valid', get_permalink( $shop_id ) === Renderer::shop_url() );

/* ------------------------------------------------------------ ۲. محصولات منتخب (دستی) */
// انتخاب: پمپ، مانیتور، دستکشِ غیرویژه، محصول پنهان، پیش‌نویس، سانتریفیوژ ناموجود، اکسیمتر.
$pick = array( $p['pump'], $p['monitor'], $p['plain'], $p['hidden'], $p['draft'], $p['centri'], $p['oxi'] );
$relevant_meta = static function () use ( $p ): string {
	global $wpdb;
	$ids = implode( ',', array_map( 'intval', $p ) );
	return md5(
		(string) $wpdb->get_var( "SELECT GROUP_CONCAT(post_id, meta_key, MD5(meta_value) ORDER BY meta_id) FROM {$wpdb->postmeta} WHERE post_id IN ($ids)" ) // phpcs:ignore WordPress.DB
		. (string) $wpdb->get_var( "SELECT GROUP_CONCAT(object_id, '-', term_taxonomy_id ORDER BY object_id, term_taxonomy_id) FROM {$wpdb->term_relationships} WHERE object_id IN ($ids)" ) // phpcs:ignore WordPress.DB
		. (string) $wpdb->get_var( "SELECT GROUP_CONCAT(ID, post_status, post_modified_gmt ORDER BY ID) FROM {$wpdb->posts} WHERE ID IN ($ids)" ) // phpcs:ignore WordPress.DB
	);
};
$meta_before = $relevant_meta();
settings( array( 'products' => $pick, 'product_count' => 8 ) );
check( 'selection: saving a selection changes nothing on the products (featured, price, stock, category, status)', $meta_before === $relevant_meta() );
$h     = html( 'products' );
$names = texts( $h, '//h3[' . cls( 'th-product__title' ) . ']' );
check( 'selection: shown in the admin order; hidden and draft dropped; nothing added', array( 'سرنگ‌پمپ تک‌کاناله', 'مانیتور علائم حیاتی پنج‌پارامتری', 'دستکش جراحی غیرویژه', 'سانتریفیوژ رومیزی <b>آزمایش</b>', 'پالس‌اکسیمتر انگشتی با نمایشگر دوخطی و قاب محافظ سیلیکونی' ) === $names, implode( ' | ', $names ) );
check( 'selection: a non-featured product is shown when selected', in_array( 'دستکش جراحی غیرویژه', $names, true ) && ! wc_get_product( $p['plain'] )->get_featured() );
check( 'selection: a featured product that is not selected is not shown', ! str_contains( $h, 'فقط در جست‌وجو' ) && wc_get_product( $p['search'] )->get_featured() );
foreach ( array( 'پیش‌نویس', 'پنهان از فروشگاه' ) as $absent ) {
	check( "selection: «{$absent}» product excluded", ! str_contains( $h, $absent ) );
}
$q = Catalog::selected_query( $pick );
check( 'query contract: include = selection, publish, catalog visibility', $pick === $q['include'] && 'publish' === $q['status'] && 'catalog' === $q['visibility'] && ! isset( $q['featured'] ) );
check( 'product title is escaped, not markup', ! str_contains( $h, '<b>آزمایش</b>' ) && str_contains( $h, '&lt;b&gt;آزمایش&lt;/b&gt;' ) );
check( 'specs: two visible attributes, hidden one not shown', array( 'ECG · SpO2 · NIBP', 'رومیزی' ) === texts( $h, '(//ul[' . cls( 'th-product__specs' ) . '])[1]/li' ) && ! str_contains( $h, 'نباید دیده شود' ) );
check( 'price: WooCommerce price html for priced product', str_contains( $h, 'woocommerce-Price-amount' ) );
$pump_price = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( wc_get_product( $p['pump'] )->get_price_html() ), ENT_QUOTES, 'UTF-8' ) ) );
check( 'price: WooCommerce price markup kept intact (bdi for RTL number + currency)', str_contains( $h, '<bdi>' ) && str_contains( $h, 'translate="no"' ) );
check( 'price: exactly the price WooCommerce shows for that product', $pump_price === trim( texts( $h, '//p[' . cls( 'th-product__price' ) . ']' )[0] ?? '' ), $pump_price );
check( 'price: product without price says «قیمت اعلام نشده»', 1 === count( nodes( $h, '//p[' . cls( 'th-product__price--ask' ) . ']' ) ) );
check( 'stock: out of stock shows «فعلاً ناموجود» and ناموجود badge', 1 === count( nodes( $h, '//p[' . cls( 'th-product__price--out' ) . ']' ) ) && in_array( 'ناموجود', texts( $h, '//span[' . cls( 'th-badge--out' ) . ']' ), true ) );
check( 'stock: backorder badge «پیش‌سفارش»', in_array( 'پیش‌سفارش', texts( $h, '//span[' . cls( 'th-badge' ) . ']' ), true ) );
$imgs = nodes( $h, '//span[' . cls( 'th-product__pic' ) . ']/img' );
check( 'image: WordPress attachment with srcset, sizes, lazy, width/height', 3 === count( nodes( $h, '//span[' . cls( 'th-product__pic' ) . ']/img[@srcset and @sizes and @loading="lazy" and @width and @height]' ) ) );
check( 'image: the product\'s own featured image', str_contains( $imgs[0]->getAttribute( 'src' ), basename( (string) get_attached_file( wc_get_product( $p['pump'] )->get_image_id() ), '.webp' ) ) );
check( 'image: products without image get the empty state', 2 === count( nodes( $h, '//div[' . cls( 'th-product__media--empty' ) . ']' ) ) );
check( 'image alt falls back to the product name', in_array( 'مانیتور علائم حیاتی پنج‌پارامتری', array_map( static fn( $n ) => $n->getAttribute( 'alt' ), $imgs ), true ) );
check( 'category label from product category', 'ملزومات مصرفی' === ( texts( $h, '//p[' . cls( 'th-product__cat' ) . ']' )[0] ?? '' ) );
check( 'card links to product permalink', get_permalink( $p['pump'] ) === nodes( $h, '//h3/a' )[0]->getAttribute( 'href' ) );
check( 'no WooCommerce loop markup (no ul.products / add-to-cart)', ! str_contains( $h, 'add_to_cart' ) && 0 === count( nodes( $h, '//ul[' . cls( 'products' ) . ']' ) ) );

settings( array( 'product_count' => 2 ) );
check( 'count: only the first N shown products', array( 'سرنگ‌پمپ تک‌کاناله', 'مانیتور علائم حیاتی پنج‌پارامتری' ) === texts( html( 'products' ), '//h3' ) );
// قیمت و موجودیِ زنده: ذخیرهٔ محصول در ووکامرس بی ذخیرهٔ دوبارهٔ تنظیمات دیده می‌شود.
( $tmp = wc_get_product( $p['pump'] ) )->set_regular_price( '32750000' );
$tmp->save();
check( 'live data: a price edited in WooCommerce shows without touching the plugin', str_contains( wp_strip_all_tags( html( 'products' ) ), '32,750,000' ) );
( $tmp = wc_get_product( $p['pump'] ) )->set_regular_price( '31500000' );
$tmp->save();
settings( array( 'product_count' => 8 ) );

// محصولی که پس از انتخاب پنهان، پیش‌نویس یا حذف شد کنار می‌رود و چیزی جایش نمی‌آید.
( $tmp = wc_get_product( $p['monitor'] ) )->set_catalog_visibility( 'hidden' );
$tmp->save();
check( 'selection: a product hidden after selection drops out', ! str_contains( html( 'products' ), 'مانیتور علائم' ) && 4 === count( nodes( html( 'products' ), '//article' ) ) );
( $tmp = wc_get_product( $p['monitor'] ) )->set_catalog_visibility( 'visible' );
$tmp->save();
wp_trash_post( $p['oxi'] );
check( 'selection: a trashed product drops out', ! str_contains( html( 'products' ), 'پالس‌اکسیمتر' ) );
$st = Catalog::selection_status( Settings::get()['products'] );
check( 'selection: a trashed product stays in the admin list (not silently removed)', in_array( $p['oxi'], Settings::get()['products'], true ) );
check( 'sanitize: a trashed product cannot be newly added', ! in_array( $p['oxi'], Settings::sanitize( array( 'add_product' => $p['oxi'] ) )['products'], true ) );
check( 'admin status: says why each selected product is not shown', ! $st[ $p['hidden'] ]['shown'] && ! $st[ $p['draft'] ]['shown'] && ! $st[ $p['oxi'] ]['shown'] && $st[ $p['pump'] ]['shown'] );
wp_untrash_post( $p['oxi'] );
wp_update_post( array( 'ID' => $p['oxi'], 'post_status' => 'publish' ) );
update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
$h = html( 'products' );
check( 'hide out of stock setting is honoured (backorder still shown)', ! str_contains( $h, 'سانتریفیوژ' ) && str_contains( $h, 'سرنگ‌پمپ' ) );
check( 'admin status: out-of-stock hidden by WooCommerce is explained', str_contains( Catalog::selection_status( array( $p['centri'] ) )[ $p['centri'] ]['reason'], 'ناموجود' ) );
update_option( 'woocommerce_hide_out_of_stock_items', 'no' );

$s = Settings::sanitize( array( 'products' => array( array( 'id' => $p['pump'], 'order' => '3' ), array( 'id' => $p['monitor'], 'order' => '1' ), array( 'id' => $p['oxi'], 'order' => '2', 'remove' => '1' ) ), 'add_product' => $p['centri'] ) );
check( 'sanitize: order numbers, remove, and add', array( $p['monitor'], $p['pump'], $p['centri'] ) === $s['products'], wp_json_encode( $s['products'] ) );
check( 'sanitize: idempotent', $s === Settings::sanitize( $s ) );
check( 'sanitize: unknown id, a page id and a category id are refused', array() === Settings::sanitize( array( 'products' => array( 999999, $ids['page'] ), 'add_product' => $c['lab'] ) )['products'] );
check( 'sanitize: duplicates collapsed', array( $p['pump'] ) === Settings::sanitize( array( 'products' => array( $p['pump'], $p['pump'] ), 'add_product' => $p['pump'] ) )['products'] );
check( 'sanitize: a variation is refused', array() === Settings::sanitize( array( 'add_product' => wp_insert_post( array( 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_title' => 'v' ) ) ) )['products'] );
check( 'sanitize: count bounded 1..12', 1 === Settings::sanitize( array( 'product_count' => 0 ) )['product_count'] && 12 === Settings::sanitize( array( 'product_count' => 99 ) )['product_count'] );
settings( array( 'products' => array() ) );
$plan = Renderer::plan();
check( 'nothing selected: products section hidden, with a reason', ! $plan['sections']['products']['show'] && str_contains( $plan['sections']['products']['reason'], 'انتخاب نشده' ) && '' === html( 'products' ) );
settings( array( 'products' => array( $p['draft'], $p['hidden'] ) ) );
$plan = Renderer::plan();
check( 'only unshowable selected: section hidden, reason differs', ! $plan['sections']['products']['show'] && str_contains( $plan['sections']['products']['reason'], 'قابل‌نمایش نیست' ) );
check( 'search: by name finds the product', in_array( $p['plain'], array_column( Catalog::search_products( 'دستکش' ), 'id' ), true ) );
check( 'search: by numeric ID finds the product', array( $p['monitor'] ) === array_column( Catalog::search_products( (string) $p['monitor'] ), 'id' ) );
check( 'search: includes draft/hidden (marked), excludes non-products', in_array( $p['draft'], array_column( Catalog::search_products( 'پیش‌نویس' ), 'id' ), true ) && array() === Catalog::search_products( (string) $ids['page'] ) );
settings( array( 'products' => array( $p['monitor'], $p['oxi'], $p['pump'], $p['centri'] ), 'product_count' => 4 ) );

/* ------------------------------------------------------------ ۳. دسته‌ها */
settings( array( 'categories' => array( array( 'id' => $c['consum'] ), array( 'id' => $c['monitor'] ), array( 'id' => $c['or'] ) ) ) );
$h = html();
check( 'categories: shown in the admin order', array( 'ملزومات مصرفی', 'پایش بیمار', 'اتاق عمل' ) === texts( $h, '//span[' . cls( 'th-cat__name' ) . ']' ), implode( ' | ', texts( $h, '//span[' . cls( 'th-cat__name' ) . ']' ) ) );
check( 'categories: link to the WooCommerce term link', get_term_link( $c['monitor'], 'product_cat' ) === nodes( $h, '//a[' . cls( 'th-cat' ) . ']' )[1]->getAttribute( 'href' ) );
check( 'categories: note = first line of description only', in_array( 'مانیتور، پالس‌اکسیمتر و ECG', texts( $h, '//span[' . cls( 'th-cat__meta' ) . ']' ), true ) && ! str_contains( $h, 'سطر دوم' ) );
check( 'categories: thumbnail image used when present', 1 === count( nodes( $h, '//span[' . cls( 'th-orb' ) . ']/img[' . cls( 'th-orb__img' ) . ']' ) ) );
// ۰٫۳٫۲: منوی «همهٔ دسته‌ها» از جست‌وجوی Hero برداشته شد (دستور مالک)؛ بخش دسته‌ها همان است.
check( 'categories: Hero search has no category select even with categories selected (0.3.2)', 0 === count( nodes( $h, '//form[' . cls( 'th-search' ) . ']//select' ) ) && ! str_contains( $h, 'همهٔ دسته‌ها' ) );
// ۰٫۳٫۳: برچسب‌های کنار تصویر دیگر «دو دستهٔ اول» نیستند؛ چهار برچسب ثابت با دستهٔ واقعیِ دارای محصول (بخش ۱۳-د).
check( 'categories: hero labels no longer follow the selected categories (0.3.3)', ! in_array( 'ملزومات مصرفی', texts( $h, '//a[' . cls( 'th-float' ) . ']' ), true ) );
check( 'categories: product filter chips = shop + categories', array( 'همه', 'ملزومات مصرفی', 'پایش بیمار', 'اتاق عمل' ) === texts( $h, '//nav[' . cls( 'th-filter' ) . ']//a' ) );
check( 'categories: no aria-current on the shop chip', 0 === count( nodes( $h, '//nav//a[@aria-current]' ) ) );
check( 'categories: no «همهٔ دسته‌ها» link without destination', 0 === count( nodes( $h, '//a[' . cls( 'th-more' ) . ' and contains(., "همهٔ دسته‌ها")]' ) ) );

$s = Settings::sanitize(
	array(
		'categories'   => array(
			array( 'id' => $c['consum'], 'order' => '3' ),
			array( 'id' => $c['monitor'], 'order' => '1', 'note' => 'متن مدیر' ),
			array( 'id' => $c['or'], 'order' => '2', 'remove' => '1' ),
		),
		'add_category' => $c['lab'],
	)
);
check( 'sanitize: order numbers, remove, and add', array( $c['monitor'], $c['consum'], $c['lab'] ) === array_column( $s['categories'], 'id' ), wp_json_encode( $s['categories'] ) );
check( 'sanitize: idempotent', $s === Settings::sanitize( $s ) );
check( 'sanitize: unknown category id is refused', array() === Settings::sanitize( array( 'add_category' => 999999 ) )['categories'] );
check( 'sanitize: at most 8 categories', 8 === count( Settings::sanitize( array( 'categories' => array_map( static fn( $i ) => array( 'id' => $i ), range( 1, 20 ) ) ) )['categories'] ) );
check( 'sanitize: bad icon falls back to default', '' === Settings::sanitize( array( 'categories' => array( array( 'id' => $c['lab'], 'icon' => 'x" onload="' ) ) ) )['categories'][0]['icon'] );

settings( array( 'categories' => array( array( 'id' => $c['empty'] ), array( 'id' => 999999 ), array( 'id' => $c['monitor'], 'note' => 'متن مدیر' ) ) ) );
$h = html( 'categories' );
check( 'categories: empty and deleted categories are skipped', array( 'پایش بیمار' ) === texts( $h, '//span[' . cls( 'th-cat__name' ) . ']' ) );
settings( array( 'categories' => array( array( 'id' => get_term( $c['monitor'] )->parent ) ) ) );
check( 'categories: a parent whose products are only in subcategories is not empty', array( 'تجهیزات پزشکی' ) === texts( html( 'categories' ), '//span[' . cls( 'th-cat__name' ) . ']' ) );
settings( array( 'categories' => array( array( 'id' => $c['lab'] ) ) ) );
check( 'categories: a category with a visible (even out-of-stock) product is shown', array( 'آزمایشگاه' ) === texts( html( 'categories' ), '//span[' . cls( 'th-cat__name' ) . ']' ) );
check( 'categories: admin note overrides the description', array( 'متن مدیر' ) === texts( $h, '//span[' . cls( 'th-cat__meta' ) . ']' ) );

settings( array( 'categories' => array( array( 'id' => $c['evil'] ) ) ) );
$h = html();
check( 'escaping: hostile category name/description never becomes markup', ! str_contains( $h, '<script>' ) && ! str_contains( $h, '<img src=x' ) && str_contains( $h, '&lt;script&gt;' ) );

/* ------------------------------------------------------------ ۴. جست‌وجو */
settings( array( 'categories' => array( array( 'id' => $c['monitor'] ), array( 'id' => $c['consum'] ) ), 'chips' => "دستکش جراحی\nمانیتور\n\nمانیتور" ) );
$h    = html( 'hero' );
check( 'chips: blank and duplicate lines dropped', array( 'دستکش جراحی', 'مانیتور' ) === texts( $h, '//ul[' . cls( 'th-quick__list' ) . ']/li' ) );
$chip = nodes( $h, '//ul[' . cls( 'th-quick__list' ) . ']//a' )[0]->getAttribute( 'href' );
check( 'chips: link is a product search for the word', str_contains( $chip, 'post_type=product' ) && str_contains( rawurldecode( $chip ), 's=دستکش جراحی' ), $chip );
$res = http( '/?' . wp_parse_url( $chip, PHP_URL_QUERY ) );
check( 'chips: the search URL finds the product on this WordPress', str_contains( $res, 'دستکش جراحی غیرویژه' ) );
$form = nodes( $h, '//form[' . cls( 'th-search' ) . ']' )[0];
$fields = array_map( static fn( $n ) => $n->getAttribute( 'name' ), nodes( $h, '//form[' . cls( 'th-search' ) . ']//*[@name]' ) );
check( 'search: one text field + post_type=product, no product_cat (0.3.2)', array( 's', 'post_type' ) === $fields && 'product' === nodes( $h, '//form[' . cls( 'th-search' ) . ']//input[@name="post_type"]' )[0]->getAttribute( 'value' ), implode( ',', $fields ) );
check( 'search: GET to the site home (WooCommerce product search), button «جست‌وجو»', 'get' === $form->getAttribute( 'method' ) && home_url( '/' ) === $form->getAttribute( 'action' ) && array( 'جست‌وجو' ) === texts( $h, '//form[' . cls( 'th-search' ) . ']//button[@type="submit"]' ) );
$res = http( '/?s=' . rawurlencode( 'پالس' ) . '&post_type=product' );
check( 'search: the submitted URL finds the product (WooCommerce search)', str_contains( $res, 'پالس‌اکسیمتر انگشتی' ) );

/* ------------------------------------------------------------ ۵. پیوندها */
$s = Settings::sanitize( array( 'links' => array( 'repair' => 'javascript:alert(1)', 'supplier' => 'data:text/html,x', 'supplier_terms' => '//evil.example/x', 'all_categories' => 'ftp://x' ) ) );
check( 'links: javascript:, data:, protocol-relative and ftp are refused', array( '', '', '', '' ) === array_values( $s['links'] ), wp_json_encode( $s['links'] ) );
$s = Settings::sanitize( array( 'links' => array( 'repair' => ' /repair/ ', 'supplier' => 'https://example.com/a?b=1&c=2', 'quote' => '/quote/' ) ) );
check( 'links: relative path kept relative, https kept', '/repair/' === $s['links']['repair'] && 'https://example.com/a?b=1&c=2' === $s['links']['supplier'] );
check( 'links: the retired quote key is not stored', ! isset( $s['links']['quote'] ) && ! isset( $s['quote_product_param'] ) && ! isset( $s['services'] ) );
settings( array( 'links' => array( 'repair' => '', 'supplier' => '', 'supplier_terms' => '', 'all_categories' => '/all-categories/' ) ) );
$h = html( 'hero' );
check( 'repair: no card while its address is empty', 0 === count( nodes( $h, '//a[' . cls( 'th-route--repair' ) . ']' ) ) && ! str_contains( $h, 'تعمیرات' ) );
check( 'repair: admin plan says why', ! Renderer::plan()['routes']['repair']['show'] && '' !== Renderer::plan()['routes']['repair']['reason'] );
settings( array( 'links' => array( 'repair' => '/medical-equipment-repair/', 'supplier' => '', 'supplier_terms' => '', 'all_categories' => '/all-categories/' ) ) );
$h = html( 'hero' );
check( 'repair: card appears with its address (relative resolved against this site)', 1 === count( nodes( $h, '//a[' . cls( 'th-route--repair' ) . ' and @href="' . esc_url( home_url( '/medical-equipment-repair/' ) ) . '"]' ) ) );
check( 'repair: card title is «درخواست تعمیرات تجهیزات پزشکی»', in_array( 'درخواست تعمیرات تجهیزات پزشکی', texts( $h, '//span[' . cls( 'th-route__title' ) . ']' ), true ) );
check( 'repair: exactly one repair link on the whole page', 1 === substr_count( html(), 'medical-equipment-repair' ) );
check( 'routes: shop first, then repair; data-count=2', array( 'th-route th-route--shop', 'th-route th-route--repair' ) === array_map( static fn( $n ) => $n->getAttribute( 'class' ), nodes( $h, '//a[' . cls( 'th-route' ) . ']' ) ) && 1 === count( nodes( $h, '//nav[@data-count="2"]' ) ) );
check( 'repair: no form, ticket or business logic — only a link', 0 === count( nodes( html(), '//form[not(@role="search")]' ) ) );
check( 'links: «همهٔ دسته‌ها» appears with its destination', 1 === count( nodes( html( 'categories' ), '//a[' . cls( 'th-more' ) . ' and @href="' . esc_url( home_url( '/all-categories/' ) ) . '"]' ) ) );

/* ------------------------------------------------------------ ۷. همکاری */
check( 'supplier: hidden without a link', '' === html( 'supplier' ) );
settings( array( 'links' => array_merge( Settings::get()['links'], array( 'supplier' => '/vendor/application/' ) ) ) );
$h = html( 'supplier' );
check( 'routes: all three, in order shop → repair → supplier, data-count=3', array( 'th-route th-route--shop', 'th-route th-route--repair', 'th-route th-route--supplier' ) === array_map( static fn( $n ) => $n->getAttribute( 'class' ), nodes( html( 'hero' ), '//a[' . cls( 'th-route' ) . ']' ) ) && 1 === count( nodes( html( 'hero' ), '//nav[@data-count="3"]' ) ) );
check( 'supplier: shown with its link', 1 === count( nodes( $h, '//a[' . cls( 'th-btn--light' ) . ' and @href="' . esc_url( home_url( '/vendor/application/' ) ) . '"]' ) ) );
check( 'supplier: no steps by default, solo layout', 0 === count( nodes( $h, '//ol' ) ) && 1 === count( nodes( $h, '//div[' . cls( 'th-join--solo' ) . ']' ) ) );
check( 'supplier: no terms button without its link', 0 === count( nodes( $h, '//a[' . cls( 'th-btn--ghost' ) . ']' ) ) );
check( 'supplier: hero route appears with the link', 1 === count( nodes( html( 'hero' ), '//a[' . cls( 'th-route--supplier' ) . ']' ) ) );
settings( array( 'supplier_steps' => array( array( 'title' => 'یک', 'text' => 'الف' ), array( 'title' => 'دو', 'text' => 'ب' ), array( 'title' => 'سه', 'text' => 'ج' ), array( 'title' => 'چهار', 'text' => 'د' ) ) ) );
$h = html( 'supplier' );
check( 'supplier: at most 3 steps, Persian numerals', array( '۱', '۲', '۳' ) === texts( $h, '//span[' . cls( 'th-step__n' ) . ']' ) );
define( 'TECTEB_HOME_SUPPLIER_URL', 'https://staging.example/vendor/application/' );
check( 'links: wp-config constant overrides the option', 'https://staging.example/vendor/application/' === Settings::link( 'supplier' ) && 'constant' === Settings::link_source( 'supplier' ) );

/* ------------------------------------------------------------ ۸. shortcode */
$h = html( 'products,hero,nonsense' );
$order = array_map( static fn( $n ) => $n->getAttribute( 'aria-labelledby' ), nodes( $h, '//section' ) );
check( 'shortcode: sections attribute order kept, unknown ignored', array( 'th-products-title', 'th-hero-title' ) === $order, implode( ',', $order ) );
Renderer::reset();
$both = Plugin::shortcode( array( 'sections' => 'hero' ) ) . Plugin::shortcode( array( 'sections' => 'products' ) );
check( 'shortcode: icon sprite printed once per page', 1 === substr_count( $both, 'class="th-sprite"' ) );

/* ------------------------------------------------------------ ۹. فقط‌خواندنی */
settings( array( 'categories' => array( array( 'id' => $c['monitor'] ), array( 'id' => $c['or'] ) ), 'products' => array( $p['monitor'], $p['oxi'], $p['pump'], $p['centri'] ), 'product_count' => 8 ) );
$before = db_digest();
for ( $i = 0; $i < 3; $i++ ) {
	html();
}
Renderer::plan();
Catalog::selection_status( Settings::get()['products'] );
Catalog::search_products( 'پمپ' );
Catalog::search_categories( 'پایش' );
http( '/tecteb-home-new/' );
http( '/plain-page/' );
$after = db_digest();
check( 'read-only: rendering and serving changes no WordPress/WooCommerce row', $before === $after, wp_json_encode( array_diff_assoc( $after, $before ) ) );

/* ------------------------------------------------------------ ۱۰. دارایی‌ها */
$page  = http( '/tecteb-home-new/' );
$plain = http( '/plain-page/' );
$shop  = http( '/shop/' );
$prod  = http( '/?p=' . $p['monitor'] );
$shared = Assets::shared_font_url();
check( 'assets: homepage loads css, font and js', str_contains( $page, 'tecteb-home.css' ) && str_contains( $page, 'tecteb-home.js' ) && ( '' === $shared ? str_contains( $page, 'tecteb-home-font.css' ) : str_contains( $page, '@font-face{font-family:"TH Vazirmatn";src:url("' . $shared . '")' ) && ! str_contains( $page, 'tecteb-home-font.css' ) ) );
check( 'assets: with tecteb-header active, the font is the header\'s file (one download), else our own', defined( 'TECTEB_HEADER_URL' ) ? TECTEB_HEADER_URL . 'assets/fonts/Vazirmatn-wght.woff2' === $shared && ! str_contains( $page, 'tecteb-homepage/assets/fonts/Vazirmatn-wght.woff2' ) : '' === $shared );
check( 'assets: css loads in <head>, not late in the footer', strpos( $page, 'tecteb-home.css' ) < strpos( $page, '</head>' ) );
check( 'assets: js is deferred', (bool) preg_match( '#<script[^>]+tecteb-home\.js[^>]*defer|<script[^>]+defer[^>]+tecteb-home\.js#', $page ) );
check( 'assets: font preloaded', str_contains( $page, 'rel="preload"' ) && str_contains( $page, 'Vazirmatn-wght.woff2' ) );
foreach ( array( 'plain page' => $plain, 'shop' => $shop, 'product' => $prod ) as $name => $body ) {
	check( "assets: nothing loads on {$name}", '' !== $body && ! str_contains( $body, 'tecteb-home' ) );
}
check( 'assets: no external host referenced by our assets', ! preg_match( '#https?://(?!127\.0\.0\.1)[^"\')\s]+#', file_get_contents( TECTEB_HOME_DIR . 'assets/css/tecteb-home.css' ) . file_get_contents( TECTEB_HOME_DIR . 'assets/js/tecteb-home.js' ) ) );
settings( array( 'font' => 'site' ) );
$page = http( '/tecteb-home-new/' );
// (فونت خودِ هدر مستقل، اگر فعال است، کار هدر است و اینجا سنجیده نمی‌شود.)
check( 'font=site: no font css, no preload, no TH Vazirmatn face, root gets th-font-site', ! str_contains( $page, 'tecteb-home-font.css' ) && ! str_contains( $page, 'tecteb-homepage/assets/fonts/Vazirmatn-wght.woff2' ) && ! str_contains( $page, 'font-family:"TH Vazirmatn";src' ) && ! preg_match( '#<link rel="preload"[^>]+Vazirmatn[^>]+>#', $page ) && str_contains( $page, 'th-font-site' ) );
settings( array( 'font' => 'bundled' ) );

/* ------------------------------------------------------------ ۱۱. قالب صفحه */
$page = http( '/tecteb-home-new/' );
// هدر و پاورقی سایت — هر کدام که این محیط دارد: قالب Hello، مکان Elementor Theme Builder، یا هدر
// مستقل tecteb-header (که جای مکان header ِ Elementor را می‌گیرد). دقیقاً یک هدر.
$site_headers = substr_count( $page, 'id="site-header"' ) + substr_count( $page, 'data-elementor-type="header"' ) + substr_count( $page, 'data-tth-header' );
check( 'page template: site header kept (exactly one)', 1 === $site_headers, (string) $site_headers );
check( 'page template: site footer kept', str_contains( $page, 'id="site-footer"' ) || str_contains( $page, 'data-elementor-type="footer"' ) );
check( 'page template: no page title, one h1', 1 === preg_match_all( '#<h1[\s>]#', $page ) && ! str_contains( $page, 'صفحهٔ اصلی تازه</h1>' ) );
check( 'page template: offered in the page template list', isset( wp_get_theme()->get_page_templates( null, 'page' )[ \Tecteb\Homepage\PageTemplate::FILE ] ) );

/* ------------------------------------------------------------ ۱۲. کش */
$purged = array();
add_action( 'litespeed_purge_url', static function ( $url ) use ( &$purged ) {
	$purged[] = $url;
} );
Cache::reset();
( $tmp = wc_get_product( $p['oxi'] ) )->set_regular_price( '1000' );
$tmp->save();
( $tmp = wc_get_product( $p['oxi'] ) )->set_regular_price( '' );
$tmp->save();
check( 'cache: a product edit purges the homepage URL once per request', array( home_url( '/' ) ) === $purged, wp_json_encode( $purged ) );
$purged = array();
Cache::reset();
settings( array( 'product_count' => 4 ) );
check( 'cache: saving settings purges the homepage', array( home_url( '/' ) ) === $purged );
$purged = array();
Cache::reset();
wp_update_term( $c['or'], 'product_cat', array( 'description' => 'به‌روز' ) );
check( 'cache: editing a category purges the homepage', array( home_url( '/' ) ) === $purged );
// WP Rocket: فقط صفحهٔ اصلی (rocket_clean_home)، یک بار در هر درخواست؛ هرگز کل دامنه.
if ( function_exists( 'rocket_clean_home' ) ) {
	$GLOBALS['tthh_rocket_home'] = 0;
	$domain_before               = (int) get_option( 'tthh_rocket_calls', 0 );
	Cache::reset();
	settings( array( 'product_count' => 3 ) );
	settings( array( 'product_count' => 4 ) );
	check( 'cache: WP Rocket — home page purged once per request, whole-domain purge never called', 1 === $GLOBALS['tthh_rocket_home'] && $domain_before === (int) get_option( 'tthh_rocket_calls', 0 ), $GLOBALS['tthh_rocket_home'] . ' ' . $domain_before );
} else {
	check( 'cache: WP Rocket stub present in this environment', false, 'rocket_clean_home stub (header-plugin/tests/fixtures/test-helpers.php) not loaded' );
}

/* ------------------------------------------------------------ ۱۳. مدیریت */
Admin::register_hooks();
$admin = get_user_by( 'login', 'tthadmin' );
wp_set_current_user( $admin->ID );
check( 'admin: settings capability is manage_woocommerce', 'manage_woocommerce' === Admin::capability() && 'manage_woocommerce' === apply_filters( 'option_page_capability_' . Settings::GROUP, 'manage_options' ) );
$sub = wp_insert_user( array( 'user_login' => 'tth_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
check( 'admin: a subscriber cannot reach the page', ! user_can( $sub, Admin::capability() ) );
wp_delete_user( $sub );
settings( array( 'categories' => array( array( 'id' => $c['monitor'] ), array( 'id' => $c['evil'] ) ), 'links' => array( 'repair' => '' ) ) );
$_GET['tth_pq'] = 'پمپ';
$_GET['tth_q'] = 'دسته';
ob_start();
Admin::render();
$a = (string) ob_get_clean();
unset( $_GET['tth_q'], $_GET['tth_pq'] );
check( 'admin: page renders with settings form and nonce', str_contains( $a, 'action="' . esc_url( admin_url( 'options.php' ) ) . '"' ) && (bool) preg_match( '#name=[\'"]option_page[\'"] value=[\'"]tecteb_home[\'"]#', $a ) && str_contains( $a, '_wpnonce' ) );
check( 'admin: category search lists matches with full path and add buttons', str_contains( $a, 'تجهیزات پزشکی › پایش بیمار' ) || str_contains( $a, 'دستهٔ بی‌محصول' ) );
check( 'admin: add button is part of the save form', str_contains( $a, 'name="tecteb_home_settings[add_category]"' ) );
check( 'admin: hostile names escaped in the admin page', ! str_contains( $a, '<script>alert' ) && ! str_contains( $a, '<img src=x' ) );
check( 'admin: status table explains the hidden repair route', str_contains( $a, 'نشانی صفحهٔ تعمیرات تعیین نشده است' ) );
check( 'admin: product search lists matches with an add button', str_contains( $a, 'سرنگ‌پمپ' ) && str_contains( $a, 'name="tecteb_home_settings[add_product]"' ) || str_contains( $a, 'انتخاب‌شده' ) );
check( 'admin: selected products table with order, remove and live price', str_contains( $a, 'name="tecteb_home_settings[products][0][order]"' ) && str_contains( $a, 'name="tecteb_home_settings[products][0][remove]"' ) );
check( 'admin: no services or quote settings remain', ! str_contains( $a, 'services' ) && ! str_contains( $a, 'quote' ) );
check( 'admin: shop destination shown read-only from WooCommerce', str_contains( $a, esc_html( wc_get_page_permalink( 'shop' ) ) ) );
check( 'admin: wp-config constant shown read-only', str_contains( $a, 'TECTEB_HOME_SUPPLIER_URL' ) && str_contains( $a, 'staging.example' ) );
wp_set_current_user( 0 );

/* ------------------------------------------------------------ ۱۳-ب. نمایش روی صفحهٔ نخست (۰٫۳٫۱) */
// همان شکل Staging: «تنظیمات ← خواندن» یک برگهٔ عادی با محتوای خودش را صفحهٔ نخست کرده.
$plain_id     = (int) get_page_by_path( 'plain-page' )->ID;
$plain_before = get_post( $plain_id )->post_content;
$prev_front   = array( get_option( 'show_on_front' ), get_option( 'page_on_front' ) );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $plain_id );
settings( array( 'categories' => array( array( 'id' => $c['monitor'], 'icon' => 'monitor' ) ), 'products' => array( $p['monitor'], $p['oxi'] ) ) );
$kept         = array( Settings::get()['categories'], Settings::get()['products'] );
$pages_before = (int) wp_count_posts( 'page' )->publish;
$d_before     = db_digest();
check( 'front page: switch off by default (also for an option saved by 0.3.0, without the key)', false === Settings::get()['front_page'] && false === Settings::normalize( array( 'products' => array( $p['monitor'] ) ), false )['front_page'] );
$h = http( '/' );
check( 'front page, switch off: the selected page shows its own content, no Concept C, no homepage assets', str_contains( $h, 'متن عادی.' ) && ! str_contains( $h, 'th-hero-title' ) && ! str_contains( $h, 'tecteb-home.css' ) );
$h = http( '/?tecteb_home_preview=1' );
check( 'front page, switch off: ?tecteb_home_preview=1 does nothing for a visitor', str_contains( $h, 'متن عادی.' ) && ! str_contains( $h, 'th-hero-title' ) );
$h = http_as( 'tthadmin', '/?tecteb_home_preview=1' );
check( 'front page, switch off: admin ?tecteb_home_preview=1 shows Concept C (admin-only preview)', str_contains( $h, 'th-hero-title' ) && ! str_contains( $h, 'متن عادی.' ) );
settings( array( 'front_page' => '1' ) );
check( 'front page: switching on keeps the selected products and categories', true === Settings::get()['front_page'] && $kept === array( Settings::get()['categories'], Settings::get()['products'] ) );
$h = http( '/' );
$site_headers = substr_count( $h, 'id="site-header"' ) + substr_count( $h, 'data-elementor-type="header"' ) + substr_count( $h, 'data-tth-header' );
check( 'front page, switch on: Concept C instead of the page content, between the site header and footer', str_contains( $h, 'th-hero-title' ) && ! str_contains( $h, 'متن عادی.' ) && 1 === $site_headers && ( str_contains( $h, 'id="site-footer"' ) || str_contains( $h, 'data-elementor-type="footer"' ) ) );
$css_at = strpos( $h, 'tecteb-home.css' );
check( 'front page, switch on: one h1, homepage CSS in <head>, real products', 1 === substr_count( $h, '<h1' ) && false !== $css_at && $css_at < (int) strpos( $h, '</head>' ) && 2 === substr_count( $h, 'class="th-product__title"' ) );
check( 'front page, switch on: the page title (SEO) is still the selected page', str_contains( $h, '<title>' ) && (bool) preg_match( '#<title>[^<]*' . preg_quote( esc_html( get_bloginfo( 'name' ) ), '#' ) . '#u', $h ) );
check( 'front page, switch on: ?tecteb_home_original=1 is ignored for a visitor', str_contains( http( '/?tecteb_home_original=1' ), 'th-hero-title' ) );
check( 'front page, switch on: admin ?tecteb_home_original=1 shows the selected page itself', str_contains( $o = http_as( 'tthadmin', '/?tecteb_home_original=1' ), 'متن عادی.' ) && ! str_contains( $o, 'th-hero-title' ) );
check( 'front page, switch on: a paginated front page (?paged=2) is left to the page', ! str_contains( http( '/?paged=2' ), 'th-hero-title' ) );
check( 'front page, switch on: ?elementor-preview keeps the page itself (editor)', ! str_contains( http( '/?elementor-preview=' . $plain_id ), 'th-hero-title' ) );
check( 'front page, switch on: other pages untouched (shop, product)', ! str_contains( http( '/shop/' ), 'th-hero-title' ) && ! str_contains( http( '/?p=' . $p['monitor'] ), 'th-hero-title' ) );
check( 'front page: the selected page, Reading settings and page count are unchanged; no new page', $plain_before === get_post( $plain_id )->post_content && $plain_id === (int) get_option( 'page_on_front' ) && 'page' === get_option( 'show_on_front' ) && $pages_before === (int) wp_count_posts( 'page' )->publish );
check( 'front page: switching on writes nothing but the plugin option', $d_before === db_digest(), wp_json_encode( array_diff_assoc( db_digest(), $d_before ) ) );
wp_set_current_user( (int) get_user_by( 'login', 'tthadmin' )->ID );
ob_start();
Admin::render();
$a = (string) ob_get_clean();
check( 'admin: front-page section names the Reading page and shows the switch (checked)', str_contains( $a, 'id="tth-front"' ) && str_contains( $a, esc_html( get_the_title( $plain_id ) ) ) && (bool) preg_match( '#name="tecteb_home_settings\[front_page\]" value="1" checked#', $a ) && str_contains( $a, 'data-tth-front-state="on"' ) );
check( 'admin: preview and original links for the admin', str_contains( $a, 'tecteb_home_preview=1' ) && str_contains( $a, 'tecteb_home_original=1' ) );
wp_set_current_user( 0 );
settings( array( 'front_page' => '' ) );
$h = http( '/' );
check( 'front page: switching off restores the selected page immediately', str_contains( $h, 'متن عادی.' ) && ! str_contains( $h, 'th-hero-title' ) && $plain_before === get_post( $plain_id )->post_content );
// صفحهٔ نخستی که خودش صفحهٔ فروشگاه است: جایگزین نمی‌شود، و کارت فروشگاه به همین صفحه برنمی‌گردد.
$shop_page = (int) wc_get_page_id( 'shop' );
update_option( 'page_on_front', $shop_page );
settings( array( 'front_page' => '1' ) );
$h = http( '/' );
check( 'front page = WooCommerce shop page: no takeover (product list stays), reason given', ! str_contains( $h, 'th-hero-title' ) && '' !== \Tecteb\Homepage\PageTemplate::front_page_problem() && 'blocked' === Admin::front_state()['code'] );
check( 'front page = shop page: the shop route is hidden instead of linking back to the homepage', '' === Renderer::shop_url() );
settings( array( 'front_page' => '' ) );
update_option( 'show_on_front', $prev_front[0] );
$prev_front[1] ? update_option( 'page_on_front', $prev_front[1] ) : delete_option( 'page_on_front' );
check( 'shop route back once the shop page is not the front page', '' !== Renderer::shop_url() );

/* ------------------------------------------------------------ ۱۳-ج. قیمت کارت محصول (۰٫۳٫۲) */
// علت ایراد Staging: get_price_html() ِ ووکامرس در بازه و تخفیف متن مخصوص صفحه‌خوان («Price range: … through …»،
// «Original price was: …») دارد و لایهٔ ایزولهٔ ۰٫۳٫۰ پنهان‌ماندنش را برمی‌گرداند. حالا Price::of() از همان API می‌سازد.
$mk_simple = static function ( string $name, string $reg, string $sale = '' ): int {
	$x = new WC_Product_Simple();
	$x->set_name( $name );
	$x->set_status( 'publish' );
	$x->set_regular_price( $reg );
	if ( '' !== $sale ) {
		$x->set_sale_price( $sale );
	}
	$id = (int) $x->save();
	update_post_meta( $id, '_tth_fixture', '1' );
	return $id;
};
$mk_var = static function ( string $name, array $rows ): int {
	$x = new WC_Product_Variable();
	$a = new WC_Product_Attribute();
	$a->set_name( 'size' );
	$a->set_options( array_column( $rows, 0 ) );
	$a->set_variation( true );
	$a->set_visible( true );
	$x->set_name( $name );
	$x->set_status( 'publish' );
	$x->set_attributes( array( $a ) );
	$id = (int) $x->save();
	update_post_meta( $id, '_tth_fixture', '1' );
	foreach ( $rows as $r ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $id );
		$v->set_attributes( array( 'size' => $r[0] ) );
		$v->set_regular_price( $r[1] );
		if ( isset( $r[2] ) ) {
			$v->set_sale_price( $r[2] );
		}
		$v->set_status( 'publish' );
		$v->save();
	}
	WC_Product_Variable::sync( $id );
	wc_delete_product_transients( $id );
	return $id;
};
$pp  = array(
	'simple'  => $mk_simple( 'قیمت ساده ۰٫۳٫۲', '45000000' ),
	'sale'    => $mk_simple( 'قیمت با تخفیف ۰٫۳٫۲', '12500000', '9800000' ),
	'range'   => $mk_var( 'قیمت بازه‌ای ۰٫۳٫۲', array( array( 'a', '3200000' ), array( 'b', '128500000' ) ) ),
	'varsale' => $mk_var( 'متغیر هم‌قیمت با تخفیف ۰٫۳٫۲', array( array( 'a', '7000000', '6300000' ), array( 'b', '7000000', '6300000' ) ) ),
	'empty'   => $mk_simple( 'بی‌قیمت ۰٫۳٫۲', '' ),
);
$P   = static fn( string $k ): array => \Tecteb\Homepage\Price::of( wc_get_product( $pp[ $k ] ) );
$vis = static fn( string $html ): string => trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
check( 'price model: simple ⇒ one amount = wc_price(display price)', 'amount' === $P( 'simple' )['kind'] && wc_price( 45000000 ) === $P( 'simple' )['now'] );
check( 'price model: simple on sale ⇒ was 12,500,000 / now 9,800,000', 'sale' === $P( 'sale' )['kind'] && wc_price( 12500000 ) === $P( 'sale' )['was'] && wc_price( 9800000 ) === $P( 'sale' )['now'] );
check( 'price model: variable with two prices ⇒ range from/to', 'range' === $P( 'range' )['kind'] && wc_price( 3200000 ) === $P( 'range' )['from'] && wc_price( 128500000 ) === $P( 'range' )['to'] );
check( 'price model: variable, all variations same price on sale ⇒ sale (as WooCommerce)', 'sale' === $P( 'varsale' )['kind'] && wc_price( 7000000 ) === $P( 'varsale' )['was'] && wc_price( 6300000 ) === $P( 'varsale' )['now'] );
check( 'price model: no price ⇒ none (no number invented)', array( 'kind' => 'none' ) === $P( 'empty' ) );
foreach ( array( 'simple', 'sale', 'range', 'varsale' ) as $k ) {
	$wc_visible = $vis( (string) preg_replace( '#<span[^>]*screen-reader-text[^>]*>.*?</span>#su', '', wc_get_product( $pp[ $k ] )->get_price_html() ) );
	$ours       = array_map( $vis, array_intersect_key( $P( $k ), array_flip( array( 'now', 'was', 'from', 'to' ) ) ) );
	check( "price model: «{$k}» shows only amounts WooCommerce itself shows", array() !== $ours && array_reduce( $ours, static fn( $ok, $a ) => $ok && str_contains( $wc_visible, $a ), true ), wp_json_encode( array( $wc_visible, $ours ) ) );
}
settings( array( 'products' => array_values( $pp ), 'product_count' => 5 ) );
$h = html( 'products' );
foreach ( array( 'Price range', 'through', 'Original price', 'Current price', 'screen-reader-text' ) as $en ) {
	check( "price card: «{$en}» nowhere in the products section", ! str_contains( $h, $en ) );
}
$cards = nodes( $h, '//p[' . cls( 'th-product__price' ) . ']' );
check( 'price card: five cards, five price lines, in the admin order', 5 === count( $cards ) );
check( 'price card: simple = one wc_price amount with <bdi>', 'th-product__price th-price th-price--amount' === $cards[0]->getAttribute( 'class' ) && 1 === count( nodes( $h, '(//p[' . cls( 'th-product__price' ) . '])[1]//bdi' ) ) );
check( 'price card: sale = <del> old + <ins> current + Persian labels for screen readers', 1 === count( nodes( $h, '(//p[' . cls( 'th-product__price' ) . '])[2]//del[' . cls( 'th-price__was' ) . ']' ) ) && 1 === count( nodes( $h, '(//p[' . cls( 'th-product__price' ) . '])[2]//ins[' . cls( 'th-price__now' ) . ']' ) ) && array( 'قیمت قبلی:', 'قیمت فعلی:' ) === texts( $h, '(//p[' . cls( 'th-product__price' ) . '])[2]//span[' . cls( 'th-visually-hidden' ) . ']' ) );
check( 'price card: range = «از …» / «تا …», two amounts', array( 'از', 'تا' ) === texts( $h, '(//p[' . cls( 'th-product__price' ) . '])[3]//span[' . cls( 'th-price__word' ) . ']' ) && 2 === count( nodes( $h, '(//p[' . cls( 'th-product__price' ) . '])[3]//bdi' ) ) );
check( 'price card: no price = «قیمت اعلام نشده»', 'قیمت اعلام نشده' === trim( $cards[4]->textContent ) );
check( 'price admin text: plain Persian, no screen-reader English', 'از ' . $vis( wc_price( 3200000 ) ) . ' تا ' . $vis( wc_price( 128500000 ) ) === \Tecteb\Homepage\Price::text( wc_get_product( $pp['range'] ) ) && ! str_contains( \Tecteb\Homepage\Price::text( wc_get_product( $pp['sale'] ) ), 'Original' ) );
// فیلترهای سایت مرجع می‌مانند: «استعلام قیمت»، پنهان‌کردن قیمت، و جایگزینی متن.
$inq = static fn() => '<span class="x">استعلام قیمت</span>';
add_filter( 'woocommerce_empty_price_html', $inq );
check( 'site filter: «استعلام قیمت» from woocommerce_empty_price_html shown as written', array( 'kind' => 'text', 'text' => 'استعلام قیمت' ) === $P( 'empty' ) && str_contains( html( 'products' ), '>استعلام قیمت</p>' ) );
remove_filter( 'woocommerce_empty_price_html', $inq );
$hide = static fn() => '';
add_filter( 'woocommerce_get_price_html', $hide );
check( 'site filter: price hidden by the site (get_price_html empty) ⇒ no number on the card', array( 'kind' => 'none' ) === $P( 'simple' ) && array( 'kind' => 'none' ) === $P( 'range' ) && ! str_contains( html( 'products' ), 'woocommerce-Price-amount' ) );
remove_filter( 'woocommerce_get_price_html', $hide );
$call = static fn() => 'تماس بگیرید';
add_filter( 'woocommerce_get_price_html', $call );
check( 'site filter: price replaced by the site text ⇒ that text, never our number', array( 'kind' => 'text', 'text' => 'تماس بگیرید' ) === $P( 'sale' ) && ! str_contains( html( 'products' ), '9,800,000' ) );
remove_filter( 'woocommerce_get_price_html', $call );
// واحد پول از ووکامرس می‌آید؛ هیچ «تومان» ِ ثابتی نیست.
$cur = array( get_option( 'woocommerce_currency' ), get_option( 'woocommerce_price_num_decimals' ), get_option( 'woocommerce_currency_pos' ) );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
update_option( 'woocommerce_currency_pos', 'left' );
$h = html( 'products' );
check( 'currency: USD/2 decimals/left from WooCommerce settings ⇒ $45,000,000.00, no «تومان»', str_contains( $vis( $P( 'simple' )['now'] ), '$45,000,000.00' ) && ! str_contains( $h, 'تومان' ) && str_contains( $vis( $h ), '$128,500,000.00' ) );
update_option( 'woocommerce_currency', $cur[0] );
update_option( 'woocommerce_price_num_decimals', $cur[1] );
update_option( 'woocommerce_currency_pos', $cur[2] );
// ووکامرس پسوند را فقط با مالیاتِ فعال و محصولِ مشمول مالیات چاپ می‌کند (get_price_suffix).
$taxes = get_option( 'woocommerce_calc_taxes' );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_price_display_suffix', 'با احتساب مالیات' );
check( 'price suffix: WooCommerce display suffix kept (plain text)', 'با احتساب مالیات' === ( $P( 'simple' )['suffix'] ?? '' ) && array( 'با احتساب مالیات' ) === texts( html( 'products' ), '(//p[' . cls( 'th-product__price' ) . '])[1]//span[' . cls( 'th-price__suffix' ) . ']' ) );
delete_option( 'woocommerce_price_display_suffix' );
update_option( 'woocommerce_calc_taxes', $taxes );
foreach ( $pp as $id ) {
	foreach ( wc_get_product( $id )->get_children() as $child ) {
		wp_delete_post( $child, true );
	}
	wp_delete_post( $id, true );
}

/* ------------------------------------------------------------ ۱۳-د. ۰٫۳٫۳: برچسب Hero، چهار برچسب، قالب قیمت، برندها */
$label_texts = static fn( string $h ): array => texts( $h, '//ul[' . cls( 'th-floats' ) . ']//a' );
$h = html( 'hero' );
check( 'hero 0.3.3: the «بازار B2B تجهیزات پزشکی» label is gone from the output (no th-pill, no text)', ! str_contains( $h, 'بازار B2B' ) && ! str_contains( $h, 'th-pill' ) );
check( 'hero 0.3.3: title, lead, search, image and routes still there', 1 === count( nodes( $h, '//h1[@id="th-hero-title"]' ) ) && str_contains( $h, 'تجهیزات پزشکی را دقیق پیدا کنید،' ) && str_contains( $h, 'با اطمینان تأمین کنید.' ) && 1 === count( nodes( $h, '//form[' . cls( 'th-search' ) . ']' ) ) && 1 === count( nodes( $h, '//picture[' . cls( 'th-hero__render' ) . ']' ) ) && 1 === count( nodes( $h, '//nav[' . cls( 'th-routes' ) . ']' ) ) );
check( 'hero 0.3.3: the hero now starts with the h1 (first child of the copy column)', 'h1' === nodes( $h, '//div[' . cls( 'th-hero__copy' ) . ']/*[1]' )[0]->nodeName );
// چهار برچسب، از دسته‌های واقعیِ همین وردپرس (فیکسچر هدر: «پزشکی جنرال» و «بیهوشی و تنفسی» بی‌محصول، «جراحی» دو تا).
$L = Catalog::hero_labels();
check( 'labels: four slots in the required order', array( 'پزشکی جنرال', 'بیهوشی و تنفسی', 'آزمایشگاه', 'جراحی' ) === array_column( \Tecteb\Homepage\Settings::HERO_LABELS, 0 ) && 4 === count( $L ) );
check( 'labels: a category without products is not linked (empty)', 'empty' === $L[0]['status'] && 'empty' === $L[1]['status'] && '' === $L[0]['url'] );
$lab = get_term( $L[2]['term'], 'product_cat' );
check( 'labels: «آزمایشگاه» resolved by exact name to a real term, linked to its WooCommerce archive', 'ok' === $L[2]['status'] && $lab instanceof WP_Term && 'آزمایشگاه' === $lab->name && get_term_link( $lab ) === $L[2]['url'] );
$surg = get_term( $L[3]['term'], 'product_cat' );
check( 'labels: two «جراحی» categories, exactly one at the root ⇒ the root one', 'ok' === $L[3]['status'] && 0 === (int) $surg->parent && 2 === count( array_filter( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) ), static fn( $t ) => 'جراحی' === $t->name ) ) );
$h = html( 'hero' );
check( 'labels: only linked labels are printed, in order', array( 'آزمایشگاه', 'جراحی' ) === $label_texts( $h ) && array( get_term_link( $lab ), get_term_link( $surg ) ) === array_map( static fn( $n ) => $n->getAttribute( 'href' ), nodes( $h, '//ul[' . cls( 'th-floats' ) . ']//a' ) ) );
check( 'labels: positions fill in order (0.3.4) — two shown ⇒ one, two; a hidden label leaves no hole', array( 'th-float th-float--one', 'th-float th-float--two' ) === array_map( static fn( $n ) => $n->getAttribute( 'class' ), nodes( $h, '//ul[' . cls( 'th-floats' ) . ']//a' ) ) );
// محصول آزمایشی در دو دستهٔ بی‌محصول ⇒ هر چهار برچسب.
$tmp = array();
foreach ( array( 0, 1 ) as $k ) {
	$x = new WC_Product_Simple();
	$x->set_name( 'برچسب آزمایشی ' . $k );
	$x->set_status( 'publish' );
	$x->set_regular_price( '10' );
	$x->set_category_ids( array( $L[ $k ]['term'] ) );
	$tmp[] = (int) $x->save();
}
$h = html( 'hero' );
check( 'labels: with products in all four ⇒ all four, in order, each to its own archive', array( 'پزشکی جنرال', 'بیهوشی و تنفسی', 'آزمایشگاه', 'جراحی' ) === $label_texts( $h ) && 4 === count( array_unique( array_map( static fn( $n ) => $n->getAttribute( 'href' ), nodes( $h, '//ul[' . cls( 'th-floats' ) . ']//a' ) ) ) ) );
// هیچ دسته‌ای با این نام در ریشه نیست و دو تا هست ⇒ مبهم، چاپ نمی‌شود، و نامزدها برای مدیر.
$surg_parent = (int) $surg->parent; // هر چه بود به همان برمی‌گردد (نه فرضِ ۰) — حتی وقتی یک جهش دستهٔ دیگری را برگزیده باشد.
// و حتی اگر این اجرا وسط کار بمیرد (خطای مرگبار زیر یک جهش): دادهٔ محلی برای اجرای بعد سالم بماند.
register_shutdown_function( static fn() => wp_update_term( $surg->term_id, 'product_cat', array( 'parent' => $surg_parent ) ) );
wp_update_term( $surg->term_id, 'product_cat', array( 'parent' => $c['lab'] ) );
$L = Catalog::hero_labels();
check( 'labels: two «جراحی» and none at the root ⇒ ambiguous, not linked, both candidates listed', 'ambiguous' === $L[3]['status'] && '' === $L[3]['url'] && 2 === count( $L[3]['candidates'] ) && ! in_array( 'جراحی', $label_texts( html( 'hero' ) ), true ) );
hero_items( array( 3 => array( 'term' => $surg->term_id ) ) );
$L = Catalog::hero_labels();
check( 'labels: admin pins the term ID ⇒ that category', 'ok' === $L[3]['status'] && $L[3]['pinned'] && $surg->term_id === $L[3]['term'] && 'جراحی' === $L[3]['name'] );
wp_update_term( $surg->term_id, 'product_cat', array( 'parent' => $surg_parent ) );
$s = \Tecteb\Homepage\Settings::sanitize( array_replace( array_diff_key( get_option( \Tecteb\Homepage\Settings::OPTION ), array( 'hero_items' => 1 ) ), array( 'hero_labels' => array( 0, 99999999, 0, 'abc' ) ) ) );
check( 'labels: a pinned 0.3.3 ID that is not a product category is refused (0 = by name)', array( 0, 0, 0, 0 ) === $s['hero_labels'] && array( 0, 0, 0, 0 ) === array_column( $s['hero_items'], 'term' ) );
check( 'labels: name matching folds Arabic ي/ك and ZWNJ, nothing else', Catalog::name_key( 'جراحي' ) === Catalog::name_key( 'جراحی' ) && Catalog::name_key( 'پزشكی‌جنرال' ) !== Catalog::name_key( 'پزشکی جنرال' ) && Catalog::name_key( "  پزشکی   جنرال " ) === Catalog::name_key( 'پزشکی جنرال' ) );
/* ------------------------------------------------------------ ۱۳-هـ. ۰٫۳٫۴: مدیریت برچسب‌های Hero از پنل */
$S     = \Tecteb\Homepage\Settings::class;
$floats = static fn( string $h ): array => array_map( static fn( $n ) => array( trim( $n->textContent ), $n->getAttribute( 'href' ), $n->getAttribute( 'class' ) ), nodes( $h, '//ul[' . cls( 'th-floats' ) . ']//a' ) );
hero_items( array() );
$L0    = array_column( Catalog::hero_labels(), null, 'index' );
$terms = array_map( static fn( $r ) => (int) $r['term'], $L0 );
$links = array_map( static fn( $t ) => get_term_link( $t, 'product_cat' ), $terms );
$terms_before = wp_json_encode( array_map( static fn( $t ) => array( $t->term_id, $t->name, $t->slug, $t->parent, $t->count ), get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'term_id' ) ) ) );
$cats_before  = html( 'categories' );
check( 'hero 0.3.4: defaults = the four 0.3.3 titles, in order, all active, no category pinned', array( 'پزشکی جنرال', 'بیهوشی و تنفسی', 'آزمایشگاه', 'جراحی' ) === array_column( $S::default_hero_items(), 'title' ) && array( 1, 2, 3, 4 ) === array_column( $S::default_hero_items(), 'order' ) && array( true, true, true, true ) === array_column( $S::default_hero_items(), 'active' ) && array( 0, 0, 0, 0 ) === array_column( $S::default_hero_items(), 'term' ) );
// ارتقا از ۰٫۳٫۳: option ِ آن نسخه کلید hero_items ندارد.
$up = $S::normalize( array( 'hero_labels' => array( 0, 0, 0, $terms[3] ) ), false );
check( 'hero 0.3.4: a 0.3.3 option (no hero_items) ⇒ default titles, order and active kept; its pinned ID kept with the title 0.3.3 showed (the category name)', array( 'پزشکی جنرال', 'بیهوشی و تنفسی', 'آزمایشگاه', get_term( $terms[3] )->name ) === array_column( $up['hero_items'], 'title' ) && array( 0, 0, 0, $terms[3] ) === array_column( $up['hero_items'], 'term' ) && array( 1, 2, 3, 4 ) === array_column( $up['hero_items'], 'order' ) && array( 0, 0, 0, $terms[3] ) === $up['hero_labels'] );
check( 'hero 0.3.4: a 0.3.3 option renders exactly as 0.3.3 did (same four titles, same archives, same order)', array( array( 'پزشکی جنرال', $links[0] ), array( 'بیهوشی و تنفسی', $links[1] ), array( 'آزمایشگاه', $links[2] ), array( 'جراحی', $links[3] ) ) === array_map( static fn( $f ) => array( $f[0], $f[1] ), $floats( html( 'hero' ) ) ) );
// عنوان مستقل از نام دسته.
hero_items( array( 0 => array( 'title' => 'تجهیزات عمومی' ) ) );
$f = $floats( html( 'hero' ) );
check( 'hero 0.3.4: editing a title changes only the text — same category archive, the WooCommerce term name untouched', 'تجهیزات عمومی' === $f[0][0] && $links[0] === $f[0][1] && 'پزشکی جنرال' === get_term( $terms[0] )->name );
hero_items( array( 0 => array( 'title' => '' ) ) );
check( 'hero 0.3.4: an empty title ⇒ the category\'s own name', 'پزشکی جنرال' === $floats( html( 'hero' ) )[0][0] );
$x = $S::sanitize( array_replace( get_option( $S::OPTION ), array( 'hero_items' => array( array( 'title' => '<script>alert(1)</script>عنوان <b>تست</b>', 'term' => 0, 'order' => 1, 'active' => '1' ) ) ) ) );
check( 'hero 0.3.4: titles are sanitized (no tags, max 40 characters); missing rows fall back to their defaults', ! str_contains( $x['hero_items'][0]['title'], '<' ) && str_contains( $x['hero_items'][0]['title'], 'عنوان' ) && 'بیهوشی و تنفسی' === $x['hero_items'][1]['title'] && 40 >= mb_strlen( $S::sanitize( array( 'hero_items' => array( array( 'title' => str_repeat( 'الف', 60 ), 'active' => '1' ) ) ) )['hero_items'][0]['title'] ) );
// ترتیب.
hero_items( array( 0 => array( 'order' => 4 ), 1 => array( 'order' => 3 ), 2 => array( 'order' => 2 ), 3 => array( 'order' => 1 ) ) );
$f = $floats( html( 'hero' ) );
check( 'hero 0.3.4: order — reversed in the panel ⇒ reversed on the page, positions one…four in the new order', array( 'جراحی', 'آزمایشگاه', 'بیهوشی و تنفسی', 'پزشکی جنرال' ) === array_column( $f, 0 ) && array( $links[3], $links[2], $links[1], $links[0] ) === array_column( $f, 1 ) && array( 'th-float th-float--one', 'th-float th-float--two', 'th-float th-float--three', 'th-float th-float--four' ) === array_column( $f, 2 ) );
$o = $S::sanitize( array_replace( get_option( $S::OPTION ), array( 'hero_items' => array( array( 'order' => 7, 'active' => '1' ), array( 'order' => 7, 'active' => '1' ), array( 'order' => 'x', 'active' => '1' ), array( 'order' => -2, 'active' => '1' ) ) ) ) );
check( 'hero 0.3.4: order is stored as ranks 1…4 (ties keep row order; a non-number keeps the row\'s place)', array( 3, 4, 2, 1 ) === array_column( $o['hero_items'], 'order' ) );
// فعال / غیرفعال.
hero_items( array( 1 => array( 'active' => false ) ) );
$f = $floats( html( 'hero' ) );
$L = array_column( Catalog::hero_labels(), null, 'index' );
check( 'hero 0.3.4: an inactive label is not printed and leaves no hole (one, two, three)', array( 'پزشکی جنرال', 'آزمایشگاه', 'جراحی' ) === array_column( $f, 0 ) && array( 'th-float th-float--one', 'th-float th-float--two', 'th-float th-float--three' ) === array_column( $f, 2 ) && 'inactive' === $L[1]['status'] && '' === $L[1]['url'] && 'ok' === $L[1]['resolved'] );
hero_items( array( 0 => array( 'active' => false ), 1 => array( 'active' => false ), 2 => array( 'active' => false ), 3 => array( 'active' => false ) ) );
check( 'hero 0.3.4: all four inactive ⇒ no th-floats list at all (no empty ul)', array() === nodes( html( 'hero' ), '//ul[' . cls( 'th-floats' ) . ']' ) );
$off = $S::sanitize( array_replace( get_option( $S::OPTION ), array( 'hero_items' => array( array( 'title' => 'الف' ), array(), array(), array() ) ) ) );
check( 'hero 0.3.4: an unticked «فعال» (no field posted) saves as inactive', array( false, false, false, false ) === array_column( $off['hero_items'], 'active' ) );
// انتخاب دسته.
hero_items( array( 1 => array( 'term' => $terms[2], 'title' => 'آزمایشگاه تشخیص طبی' ) ) );
$f = $floats( html( 'hero' ) );
check( 'hero 0.3.4: choosing a category by ID ⇒ that archive, with the admin\'s title', 'آزمایشگاه تشخیص طبی' === $f[1][0] && $links[2] === $f[1][1] );
$pick = $S::sanitize( array_replace( get_option( $S::OPTION ), array( 'hero_pick' => '0:' . $terms[3] ) ) );
check( 'hero 0.3.4: «برای برچسب ۱» from the search results sets that row\'s category (and only that row)', $terms[3] === $pick['hero_items'][0]['term'] && $terms[2] === $pick['hero_items'][1]['term'] && 0 === $pick['hero_items'][2]['term'] );
check( 'hero 0.3.4: a malformed or out-of-range pick changes nothing', $S::sanitize( array_replace( get_option( $S::OPTION ), array( 'hero_pick' => '9:' . $terms[3] ) ) )['hero_items'] === $S::sanitize( get_option( $S::OPTION ) )['hero_items'] && $S::sanitize( array_replace( get_option( $S::OPTION ), array( 'hero_pick' => '0:abc' ) ) )['hero_items'] === $S::sanitize( get_option( $S::OPTION ) )['hero_items'] );
$bad = $S::sanitize( array_replace( get_option( $S::OPTION ), array( 'hero_items' => array( array( 'term' => 99999999, 'active' => '1' ), array( 'term' => 'abc', 'active' => '1' ), array( 'term' => get_option( 'default_category' ), 'active' => '1' ) ) ) ) );
check( 'hero 0.3.4: an ID that is not a product category (missing, text, a post category) is refused — back to «by name», with a message', array( 0, 0, 0 ) === array_slice( array_column( $bad['hero_items'], 'term' ), 0, 3 ) && in_array( 'hero_item_0', array_column( get_settings_errors( $S::OPTION ), 'code' ), true ) );
// دستهٔ بی‌محصول با عنوان دلخواه هم پنهان می‌ماند؛ دستهٔ حذف‌شده هیچ پیوندی نمی‌سازد.
$empty_cat = wp_insert_term( 'دستهٔ خالی آزمایشی ۰٫۳٫۴', 'product_cat' );
hero_items( array( 2 => array( 'term' => $empty_cat['term_id'], 'title' => 'عنوان دلخواه' ) ) );
$L = array_column( Catalog::hero_labels(), null, 'index' );
check( 'hero 0.3.4: a chosen category without displayable products stays hidden, whatever the title', 'empty' === $L[2]['status'] && ! in_array( 'عنوان دلخواه', array_column( $floats( html( 'hero' ) ), 0 ), true ) );
wp_delete_term( $empty_cat['term_id'], 'product_cat' );
$L = array_column( Catalog::hero_labels(), null, 'index' );
check( 'hero 0.3.4: a chosen category deleted later ⇒ «missing», no link, nothing printed — not silently re-pointed at the same-named category', 'missing' === $L[2]['status'] && '' === $L[2]['url'] && 3 === count( $floats( html( 'hero' ) ) ) && ! in_array( 'عنوان دلخواه', array_column( $floats( html( 'hero' ) ), 0 ), true ) );
check( 'hero 0.3.4: …and saving the form then refuses that ID with a message (back to «by name»)', 0 === $S::sanitize( get_option( $S::OPTION ) )['hero_items'][2]['term'] );
// بازگشت به ۰٫۳٫۳: hero_labels همیشه دستهٔ هر ردیف است.
hero_items( array( 0 => array( 'term' => $terms[3] ), 2 => array( 'active' => false ) ) );
check( 'hero 0.3.4: hero_labels (what 0.3.3 reads) always mirrors each row\'s category, so a downgrade sees the same categories', array( $terms[3], 0, 0, 0 ) === $S::sanitize( get_option( $S::OPTION ) )['hero_labels'] && array( $terms[3], 0, 0, 0 ) === $S::get()['hero_labels'] );
// فقط Hero.
check( 'hero 0.3.4: the categories section is byte-for-byte unchanged by any of the above', $cats_before === html( 'categories' ) );
check( 'hero 0.3.4: no product category was created, renamed, moved or deleted (beyond the test\'s own temporary one)', $terms_before === wp_json_encode( array_map( static fn( $t ) => array( $t->term_id, $t->name, $t->slug, $t->parent, $t->count ), get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'term_id' ) ) ) ) );
hero_items( array() );
foreach ( $tmp as $id ) {
	wp_delete_post( $id, true );
}
check( 'labels: nothing was written to any term (names, parents, counts as before)', 'جراحی' === get_term( $surg->term_id, 'product_cat' )->name && $surg_parent === (int) get_term( $surg->term_id, 'product_cat' )->parent );

// قالب قیمت فقط در صفحهٔ اصلی: جداکنندهٔ «,» و فاصله، حتی وقتی سایت جداکننده ندارد و نماد بی فاصله است (Staging).
$fmt = array( get_option( 'woocommerce_price_thousand_sep' ), get_option( 'woocommerce_currency_pos' ), get_option( 'woocommerce_price_num_decimals' ), get_option( 'woocommerce_price_decimal_sep' ) );
// قالب قیمت سایت حتماً برمی‌گردد، حتی اگر این اجرا وسط کار بمیرد؛ وگرنه اجرای بعدی روی قالب دیگری می‌سنجد.
register_shutdown_function(
	static function () use ( $fmt ): void {
		update_option( 'woocommerce_price_thousand_sep', $fmt[0] );
		update_option( 'woocommerce_currency_pos', $fmt[1] );
		update_option( 'woocommerce_price_num_decimals', $fmt[2] );
		update_option( 'woocommerce_price_decimal_sep', $fmt[3] );
	}
);
update_option( 'woocommerce_price_thousand_sep', '' );
update_option( 'woocommerce_currency_pos', 'right' );
$pid  = $mk_simple( 'قیمت قالب ۰٫۳٫۳', '5634400' );
$pv   = $mk_var( 'بازهٔ قالب ۰٫۳٫۳', array( array( 'a', '3200000' ), array( 'b', '128500000' ) ) );
$ps   = $mk_simple( 'تخفیف قالب ۰٫۳٫۳', '12500000', '9800000' );
$site = $vis( wc_get_product( $pid )->get_price_html() );
check( 'price format: the site itself (product page) still shows its own format «5634400تومان»', '5634400تومان' === $site, $site );
$P2 = \Tecteb\Homepage\Price::of( wc_get_product( $pid ) );
check( 'price format: homepage shows «5,634,400 تومان» (comma thousands, space before the currency)', "5,634,400\u{00A0}تومان" === html_entity_decode( wp_strip_all_tags( (string) ( $P2['now'] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), wp_json_encode( $P2 ) );
check( 'price format: range and sale in the same format', 'از 3,200,000 تومان تا 128,500,000 تومان' === str_replace( "\u{00A0}", ' ', \Tecteb\Homepage\Price::text( wc_get_product( $pv ) ) ) && '9,800,000 تومان (قیمت قبلی 12,500,000 تومان)' === str_replace( "\u{00A0}", ' ', \Tecteb\Homepage\Price::text( wc_get_product( $ps ) ) ) );
check( 'price format: no global WooCommerce price filter added (wc_price_args, woocommerce_price_format, formatted_woocommerce_price)', ! has_filter( 'wc_price_args' ) && ! has_filter( 'woocommerce_price_format' ) && ! has_filter( 'formatted_woocommerce_price' ) && '5634400تومان' === $vis( wc_price( 5634400 ) ) );
settings( array( 'products' => array( $pid, $pv, $ps ), 'product_count' => 3 ) );
$h = html( 'products' );
check( 'price format: product cards on the homepage use it; no unformatted 5634400 anywhere', str_contains( $vis( $h ), '5,634,400' ) && ! str_contains( $vis( $h ), '5634400' ) && 1 === count( nodes( $h, '//del[' . cls( 'th-price__was' ) . ']' ) ) );
update_option( 'woocommerce_price_num_decimals', 2 );
update_option( 'woocommerce_price_decimal_sep', ',' );
update_option( 'woocommerce_price_thousand_sep', '.' );
check( 'price format: decimal comma site ⇒ its own thousands separator kept (no «1,234,56»)', "5.634.400,00\u{00A0}تومان" === html_entity_decode( wp_strip_all_tags( (string) ( \Tecteb\Homepage\Price::of( wc_get_product( $pid ) )['now'] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
update_option( 'woocommerce_price_thousand_sep', $fmt[0] );
update_option( 'woocommerce_currency_pos', $fmt[1] );
update_option( 'woocommerce_price_num_decimals', $fmt[2] );
update_option( 'woocommerce_price_decimal_sep', $fmt[3] );
foreach ( array( $pid, $pv, $ps ) as $id ) {
	foreach ( wc_get_product( $id )->get_children() as $child ) {
		wp_delete_post( $child, true );
	}
	wp_delete_post( $id, true );
}

// برندها: فقط آنچه مدیر ثبت کرده؛ بی برند ⇒ بی بخش.
$S = \Tecteb\Homepage\Settings::class;
$before = get_option( $S::OPTION );
check( 'brands: an option saved by 0.3.2 (no brands / hero_labels keys) reads as no brands and labels by name', array() === $S::normalize( array( 'products' => array( $p['monitor'] ) ), false )['brands'] && array( 0, 0, 0, 0 ) === $S::normalize( array(), false )['hero_labels'] );
check( 'brands: no brand ⇒ no section at all (no heading, no empty block)', ! str_contains( html(), 'th-section--brands' ) && ! str_contains( html(), 'برندهای همکار' ) && false === Renderer::plan()['sections']['brands']['show'] );
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
$img_ids = array();
foreach ( array( 'product-monitor-360.webp', 'product-oximeter-360.webp', 'product-pump-360.webp' ) as $f ) {
	$tmp = wp_tempnam( $f );
	copy( dirname( __DIR__, 3 ) . '/concept-c/assets/' . $f, $tmp );
	$img_ids[] = (int) media_handle_sideload( array( 'name' => 'logo-' . $f, 'tmp_name' => $tmp ), 0, 'logo test' );
}
$doc     = wp_insert_attachment( array( 'post_title' => 'not an image', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ) );
$form    = array_replace(
	$S::get(),
	array(
		'brands'    => array(
			array( 'name' => 'برند آزمایشی الف', 'logo' => $img_ids[0], 'url' => 'https://example.org/a', 'active' => '1', 'order' => '2' ),
			array( 'name' => 'برند آزمایشی ب', 'logo' => $img_ids[1], 'url' => '', 'active' => '1', 'order' => '1' ),
			array( 'name' => 'برند غیرفعال', 'logo' => $img_ids[2], 'url' => '', 'order' => '3' ),
			array( 'name' => 'برند لوگوی نامعتبر', 'logo' => $doc, 'url' => '', 'active' => '1', 'order' => '4' ),
			array( 'name' => '<script>x</script>برند ج', 'logo' => $img_ids[2], 'url' => 'javascript:alert(1)', 'active' => '1', 'order' => '5' ),
			array( 'name' => 'حذف‌شونده', 'logo' => $img_ids[0], 'url' => '', 'active' => '1', 'remove' => '1' ),
			array( 'name' => '', 'logo' => $img_ids[0], 'url' => '', 'active' => '1' ),
		),
		'add_brand' => array( 'name' => 'برند تازه', 'logo' => (string) $img_ids[1], 'url' => '/landing-seller/' ),
	)
);
update_option( $S::OPTION, $form );
$S::forget();
$B = $S::get()['brands'];
check( 'brands: saved in the admin order (order column), removed and nameless rows dropped, new one last', array( 'برند آزمایشی ب', 'برند آزمایشی الف', 'برند غیرفعال', 'برند لوگوی نامعتبر', 'برند ج', 'برند تازه' ) === array_column( $B, 'name' ), implode( ' | ', array_column( $B, 'name' ) ) );
check( 'brands: a non-image logo is refused (logo 0, inactive), name kept for the admin to fix', 0 === $B[3]['logo'] && false === $B[3]['active'] );
check( 'brands: markup stripped from the name, javascript: URL refused', 'برند ج' === $B[4]['name'] && '' === $B[4]['url'] && 'https://example.org/a' === $B[1]['url'] );
check( 'brands: saving brands leaves every other setting as it was', array_diff_key( $S::normalize( $before, false ), array( 'brands' => 1 ) ) === array_diff_key( $S::get(), array( 'brands' => 1 ) ) );
$h = html();
$order = array_map( static fn( $n ) => $n->getAttribute( 'aria-labelledby' ) ?: $n->getAttribute( 'class' ), nodes( $h, '//div[' . cls( 'tecteb-home-v1' ) . ']/section' ) );
check( 'brands: section right after the supplier section and last in the homepage', 'th-brands-title' === end( $order ) && 'th-supplier-title' === $order[ count( $order ) - 2 ], implode( ',', $order ) );
$alts = array_map( static fn( $n ) => $n->getAttribute( 'alt' ), nodes( $h, '//ul[' . cls( 'th-brands__list' ) . ']//img' ) );
check( 'brands: only active brands with a real image, in order, alt = brand name', array( 'برند آزمایشی ب', 'برند آزمایشی الف', 'برند ج', 'برند تازه' ) === $alts, implode( ' | ', $alts ) );
check( 'brands: optional link — with URL ⇒ <a href>, without ⇒ no link', array( 'https://example.org/a', home_url( '/landing-seller/' ) ) === array_map( static fn( $n ) => $n->getAttribute( 'href' ), nodes( $h, '//ul[' . cls( 'th-brands__list' ) . ']//a' ) ) && 2 === count( nodes( $h, '//ul[' . cls( 'th-brands__list' ) . ']//span[' . cls( 'th-brand' ) . ']' ) ) );
check( 'brands: nav buttons are real buttons, hidden until the script shows them; region is focusable and labelled', 2 === count( nodes( $h, '//div[@data-th-brands-nav and @hidden]/button[@type="button"]' ) ) && 1 === count( nodes( $h, '//div[@data-th-brands and @tabindex="0" and @role="region" and @aria-label]' ) ) );
check( 'brands: no third-party script or style (carousel is the plugin JS only)', ! preg_match( '#(swiper|slick|owl\.carousel|glide|splide)#i', $h ) );
// ۰٫۳٫۴: اندازهٔ دیداری یکسان — هر لوگو --th-logo-w از نسبت خودِ تصویرش.
$widths = array();
foreach ( nodes( $h, '//ul[' . cls( 'th-brands__list' ) . ']//img' ) as $img ) {
	$widths[] = preg_match( '/--th-logo-w:\s*([0-9.]+)rem/', $img->getAttribute( 'style' ), $m ) ? (float) $m[1] : -1;
}
check( 'brands 0.3.4: every logo carries --th-logo-w (balanced visual size)', array() !== $widths && ! in_array( -1, $widths, true ), wp_json_encode( $widths ) );
$lw = static fn( float $r ): float => (float) Catalog::logo_width( $r );
check( 'brands 0.3.4: wide logos get more width and less height than square ones, tall ones less width — never more than 3rem tall or 8.5rem wide, ratio kept', $lw( 4.0 ) > $lw( 1.0 ) && $lw( 1.0 ) > $lw( 0.5 ) && $lw( 4.0 ) / 4.0 < $lw( 1.0 ) && $lw( 0.5 ) / 0.5 <= 3.0 + 0.01 && $lw( 20.0 ) <= 8.5 && abs( $lw( 1.0 ) - 3.0 ) < 0.01 );
wp_delete_attachment( $img_ids[1], true );
$alts = array_map( static fn( $n ) => $n->getAttribute( 'alt' ), nodes( html(), '//ul[' . cls( 'th-brands__list' ) . ']//img' ) );
check( 'brands: a logo deleted from the media library ⇒ that brand silently left out', array( 'برند آزمایشی الف', 'برند ج' ) === $alts, implode( ' | ', $alts ) );
Cache::reset();
$purges = did_action( 'tecteb_home_purge' );
wp_delete_attachment( $img_ids[0], true );
check( 'brands: deleting a brand logo purges the homepage cache', $purges + 1 === did_action( 'tecteb_home_purge' ) );
Cache::reset();
$purges = did_action( 'tecteb_home_purge' );
$other  = wp_insert_attachment( array( 'post_title' => 'unrelated', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ) );
wp_delete_attachment( $other, true );
check( 'brands: deleting an unrelated attachment purges nothing', $purges === did_action( 'tecteb_home_purge' ) );
Cache::reset();
wp_set_current_user( (int) get_user_by( 'login', 'tthadmin' )->ID );
ob_start();
Admin::render();
$a = (string) ob_get_clean();
wp_set_current_user( 0 );
check( 'admin: hero labels and brands sections, with name/logo/url/active/order/remove per brand', str_contains( $a, 'id="tth-hero-labels"' ) && str_contains( $a, 'id="tth-brands"' ) && 6 === count( nodes( $a, '//tr[@data-tth-brand]' ) ) && 1 === count( nodes( $a, '//input[@name="tecteb_home_settings[add_brand][name]"]' ) ) && 6 === count( nodes( $a, '//input[contains(@name,"[remove]") and contains(@name,"[brands]")]' ) ) );
check( 'admin: each hero label row says what it is linked to (or why not)', 4 === count( nodes( $a, '//tr[@data-tth-hero-label]' ) ) && 1 === count( nodes( $a, '//tr[@data-tth-hero-label="0" and @data-tth-status="empty"]' ) ) );
wp_delete_post( $doc, true );
foreach ( $img_ids as $id ) {
	wp_delete_attachment( $id, true );
}
update_option( $S::OPTION, $before );
$S::forget();
check( 'brands: back to no brands ⇒ section gone again', ! str_contains( html(), 'th-section--brands' ) );

/* ------------------------------------------------------------ ۱۴. حذف */
$before = db_digest();
update_option( Settings::OPTION, Settings::defaults() );
define( 'WP_UNINSTALL_PLUGIN', 'tecteb-homepage/tecteb-homepage.php' );
include TECTEB_HOME_DIR . 'uninstall.php';
check( 'uninstall: removes only its own option', false === get_option( Settings::OPTION ) && $before === db_digest() );

echo "\n", $GLOBALS['tth_pass'], ' passed, ', $GLOBALS['tth_fail'], " failed\n";
exit( $GLOBALS['tth_fail'] > 0 ? 1 : 0 );
