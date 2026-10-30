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
check( 'defaults: products section shows', 1 === count( nodes( $h, '//*[@id="th-products-title"]' ) ) );
check( 'defaults: no services section', 0 === count( nodes( $h, '//*[@id="th-services-title"]' ) ) );
check( 'defaults: no supplier section', 0 === count( nodes( $h, '//*[@id="th-supplier-title"]' ) ) );
check( 'defaults: no route cards (quote/supplier)', 0 === count( nodes( $h, '//a[' . cls( 'th-route' ) . ']' ) ) );
check( 'defaults: no quote buttons on cards', 0 === count( nodes( $h, '//a[' . cls( 'th-product__quote' ) . ']' ) ) );
check( 'defaults: no quick chips', 0 === count( nodes( $h, '//*[' . cls( 'th-quick' ) . ']' ) ) );
check( 'defaults: no category select without categories', 0 === count( nodes( $h, '//select[@name="product_cat"]' ) ) );
check( 'defaults: audience line shows', 1 === count( nodes( $h, '//*[' . cls( 'th-audience' ) . ']' ) ) );
check( 'no temporary #th-route links anywhere', ! str_contains( $h, '#th-route' ) && ! str_contains( $h, 'data-th-route' ) );
foreach ( array( 'th-sample', 'th-imgnote', 'th-hero__note', 'proto-', 'تصویر نمایشی', 'نمونه', 'در انتظار تأیید' ) as $needle ) {
	check( "no preview marker «{$needle}»", ! str_contains( $h, $needle ) );
}
$hrefs = array_map( static fn( $n ) => $n->getAttribute( 'href' ), nodes( $h, '//a' ) );
check( 'every link is absolute http(s) on this site', array() === array_filter( $hrefs, static fn( $u ) => ! str_starts_with( $u, home_url() ) ), implode( ' ', $hrefs ) );
check( 'search form goes to home_url with post_type=product', 1 === count( nodes( $h, '//form[@role="search" and @action="' . esc_url( home_url( '/' ) ) . '" and @method="get"]//input[@name="post_type" and @value="product"]' ) ) );
check( 'hero image eager, high priority, excluded from lazyload', 1 === count( nodes( $h, '//img[@fetchpriority="high" and @data-no-lazy="1" and not(@loading)]' ) ) );

/* ------------------------------------------------------------ ۲. محصولات ویژه */
settings( array( 'product_count' => 8 ) );
$h     = html( 'products' );
$cards = nodes( $h, '//article[' . cls( 'th-product' ) . ']' );
$names = texts( $h, '//h3[' . cls( 'th-product__title' ) . ']' );
check( 'featured: exactly the 4 published+catalog-visible featured products, in menu_order', array( 'مانیتور علائم حیاتی پنج‌پارامتری', 'پالس‌اکسیمتر انگشتی با نمایشگر دوخطی و قاب محافظ سیلیکونی', 'سرنگ‌پمپ تک‌کاناله', 'سانتریفیوژ رومیزی <b>آزمایش</b>' ) === $names, implode( ' | ', $names ) );
foreach ( array( 'پیش‌نویس', 'پنهان از فروشگاه', 'فقط در جست‌وجو', 'غیرویژه' ) as $absent ) {
	check( "featured: «{$absent}» product excluded", ! str_contains( $h, $absent ) );
}
// ووکامرس ۱۱٫۰٫۱ با featured=true خودش exclude-from-catalog را کنار می‌گذارد
// (class-wc-product-data-store-cpt.php ۲۳۵۱–۲۳۵۶)؛ گارد ما دومی است و جدا pin می‌شود،
// چون اگر رفتار ووکامرس عوض شود، فقط همین آرگومان محصول پنهان را بیرون نگه می‌دارد.
$q = Catalog::featured_query( 4 );
check( 'query contract: publish + featured + catalog visibility, menu_order then date', 'publish' === $q['status'] && true === $q['featured'] && 'catalog' === $q['visibility'] && array( 'menu_order' => 'ASC', 'date' => 'DESC' ) === $q['orderby'] && 4 === $q['limit'] );
check( 'product title is escaped, not markup', ! str_contains( $h, '<b>آزمایش</b>' ) && str_contains( $h, '&lt;b&gt;آزمایش&lt;/b&gt;' ) );
check( 'specs: two visible attributes, hidden one not shown', array( 'ECG · SpO2 · NIBP', 'رومیزی' ) === texts( $h, '(//ul[' . cls( 'th-product__specs' ) . '])[1]/li' ) && ! str_contains( $h, 'نباید دیده شود' ) );
check( 'price: WooCommerce price html for priced product', str_contains( $h, 'woocommerce-Price-amount' ) );
check( 'price: product without price says «قیمت اعلام نشده»', 1 === count( nodes( $h, '//p[' . cls( 'th-product__price--ask' ) . ']' ) ) );
check( 'stock: out of stock shows «فعلاً ناموجود» and ناموجود badge', 1 === count( nodes( $h, '//p[' . cls( 'th-product__price--out' ) . ']' ) ) && in_array( 'ناموجود', texts( $h, '//span[' . cls( 'th-badge--out' ) . ']' ), true ) );
check( 'stock: backorder badge «پیش‌سفارش»', in_array( 'پیش‌سفارش', texts( $h, '//span[' . cls( 'th-badge' ) . ']' ), true ) );
check( 'image: WordPress attachment with srcset, sizes, lazy, width/height', 3 === count( nodes( $h, '//span[' . cls( 'th-product__pic' ) . ']/img[@srcset and @sizes and @loading="lazy" and @width and @height]' ) ) );
check( 'image: product without image gets the empty state', 1 === count( nodes( $h, '//div[' . cls( 'th-product__media--empty' ) . ']' ) ) );
check( 'image alt falls back to the product name', in_array( 'مانیتور علائم حیاتی پنج‌پارامتری', array_map( static fn( $n ) => $n->getAttribute( 'alt' ), nodes( $h, '//span[' . cls( 'th-product__pic' ) . ']/img' ) ), true ) );
check( 'category label from product category', 'پایش بیمار' === ( texts( $h, '//p[' . cls( 'th-product__cat' ) . ']' )[0] ?? '' ) );
check( 'card links to product permalink', get_permalink( $p['monitor'] ) === nodes( $h, '//h3/a' )[0]->getAttribute( 'href' ) );
check( 'no WooCommerce loop markup (no ul.products / add-to-cart)', ! str_contains( $h, 'add_to_cart' ) && 0 === count( nodes( $h, '//ul[' . cls( 'products' ) . ']' ) ) );

settings( array( 'product_count' => 4 ) );
( $tmp = wc_get_product( $p['pump'] ) )->set_featured( false );
$tmp->save();
( $tmp = wc_get_product( $p['centri'] ) )->set_featured( false );
$tmp->save();
$h = html( 'products' );
check( 'fewer than 4 featured: exactly 2 cards, nothing added', 2 === count( nodes( $h, '//article' ) ), (string) count( nodes( $h, '//article' ) ) );
check( 'fewer than 4 featured: non-featured product not used as filler', ! str_contains( $h, 'دستکش جراحی' ) );
foreach ( array( 'monitor', 'oxi' ) as $k ) {
	( $tmp = wc_get_product( $p[ $k ] ) )->set_featured( false );
	$tmp->save();
}
$plan = Renderer::plan();
check( 'zero featured: products section hidden, with a reason', ! $plan['sections']['products']['show'] && '' !== $plan['sections']['products']['reason'] && '' === html( 'products' ) );
foreach ( array( 'monitor', 'oxi', 'pump', 'centri' ) as $k ) {
	( $tmp = wc_get_product( $p[ $k ] ) )->set_featured( true );
	$tmp->save();
}
update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
$h = html( 'products' );
check( 'hide out of stock setting is honoured (backorder still shown)', 3 === count( nodes( $h, '//article' ) ) && ! str_contains( $h, 'سانتریفیوژ' ) && str_contains( $h, 'سرنگ‌پمپ' ) );
update_option( 'woocommerce_hide_out_of_stock_items', 'no' );

/* ------------------------------------------------------------ ۳. دسته‌ها */
settings( array( 'categories' => array( array( 'id' => $c['consum'] ), array( 'id' => $c['monitor'] ), array( 'id' => $c['or'] ) ) ) );
$h = html();
check( 'categories: shown in the admin order', array( 'ملزومات مصرفی', 'پایش بیمار', 'اتاق عمل' ) === texts( $h, '//span[' . cls( 'th-cat__name' ) . ']' ), implode( ' | ', texts( $h, '//span[' . cls( 'th-cat__name' ) . ']' ) ) );
check( 'categories: link to the WooCommerce term link', get_term_link( $c['monitor'], 'product_cat' ) === nodes( $h, '//a[' . cls( 'th-cat' ) . ']' )[1]->getAttribute( 'href' ) );
check( 'categories: note = first line of description only', in_array( 'مانیتور، پالس‌اکسیمتر و ECG', texts( $h, '//span[' . cls( 'th-cat__meta' ) . ']' ), true ) && ! str_contains( $h, 'سطر دوم' ) );
check( 'categories: thumbnail image used when present', 1 === count( nodes( $h, '//span[' . cls( 'th-orb' ) . ']/img[' . cls( 'th-orb__img' ) . ']' ) ) );
check( 'categories: search select lists the selected categories', array( 'همهٔ دسته‌ها', 'ملزومات مصرفی', 'پایش بیمار', 'اتاق عمل' ) === texts( $h, '//select[@name="product_cat"]/option' ) );
check( 'categories: hero floats = first two categories', array( 'ملزومات مصرفی', 'پایش بیمار' ) === texts( $h, '//a[' . cls( 'th-float' ) . ']' ) );
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
$opts = nodes( $h, '//select[@name="product_cat"]/option[@value!=""]' );
check( 'chips: blank and duplicate lines dropped', array( 'دستکش جراحی', 'مانیتور' ) === texts( $h, '//ul[' . cls( 'th-quick__list' ) . ']/li' ) );
$chip = nodes( $h, '//ul[' . cls( 'th-quick__list' ) . ']//a' )[0]->getAttribute( 'href' );
check( 'chips: link is a product search for the word', str_contains( $chip, 'post_type=product' ) && str_contains( rawurldecode( $chip ), 's=دستکش جراحی' ), $chip );
$res = http( '/?' . wp_parse_url( $chip, PHP_URL_QUERY ) );
check( 'chips: the search URL finds the product on this WordPress', str_contains( $res, 'دستکش جراحی غیرویژه' ) );
$slug = $opts[0]->getAttribute( 'value' );
check( 'search: select value is the readable (decoded) slug', 'پایش-بیمار' === $slug, $slug );
$res = http( '/?s=&post_type=product&product_cat=' . rawurlencode( $slug ) );
check( 'search: category-scoped search returns that category\'s products', str_contains( $res, 'مانیتور علائم حیاتی' ) && ! str_contains( $res, 'دستکش جراحی غیرویژه' ) );
$res = http( '/?s=' . rawurlencode( 'پالس' ) . '&post_type=product&product_cat=' );
check( 'search: plain word search with empty category works', str_contains( $res, 'پالس‌اکسیمتر انگشتی' ) );

/* ------------------------------------------------------------ ۵. پیوندها */
$s = Settings::sanitize( array( 'links' => array( 'quote' => 'javascript:alert(1)', 'supplier' => 'data:text/html,x', 'supplier_terms' => '//evil.example/x', 'all_categories' => 'ftp://x' ) ) );
check( 'links: javascript:, data:, protocol-relative and ftp are refused', array( '', '', '', '' ) === array_values( $s['links'] ), wp_json_encode( $s['links'] ) );
$s = Settings::sanitize( array( 'links' => array( 'quote' => ' /quote/ ', 'supplier' => 'https://example.com/a?b=1&c=2' ) ) );
check( 'links: relative path kept relative, https kept', '/quote/' === $s['links']['quote'] && 'https://example.com/a?b=1&c=2' === $s['links']['supplier'] );
settings( array( 'links' => array( 'quote' => '/quote/', 'supplier' => '', 'supplier_terms' => '', 'all_categories' => '/all-categories/' ), 'quote_product_param' => 'product_id' ) );
$h = html();
check( 'links: relative path resolved against this site', 1 === count( nodes( $h, '//a[' . cls( 'th-route--buyer' ) . ' and @href="' . esc_url( home_url( '/quote/' ) ) . '"]' ) ) );
check( 'links: product quote carries the product id param', str_contains( nodes( $h, '//a[' . cls( 'th-product__quote' ) . ']' )[0]->getAttribute( 'href' ), 'product_id=' . $p['monitor'] ) );
check( 'links: four quote buttons on four cards', 4 === count( nodes( $h, '//a[' . cls( 'th-product__quote' ) . ']' ) ) );
check( 'links: supplier route hidden while supplier is empty', 0 === count( nodes( $h, '//a[' . cls( 'th-route--supplier' ) . ']' ) ) );
check( 'links: «همهٔ دسته‌ها» appears with its destination', 1 === count( nodes( $h, '//a[' . cls( 'th-more' ) . ' and @href="' . esc_url( home_url( '/all-categories/' ) ) . '"]' ) ) );
check( 'links: bad param name refused', '' === Settings::sanitize( array( 'quote_product_param' => 'a b"' ) )['quote_product_param'] );

/* ------------------------------------------------------------ ۶. خدمات */
settings( array( 'services_enabled' => false, 'services' => array( array( 'title' => 'استعلام', 'text' => 'x' ) ) ) );
check( 'services: items but switched off → hidden', ! str_contains( html(), 'th-services-title' ) );
settings( array( 'services_enabled' => true, 'services' => array() ) );
check( 'services: switched on but no items → hidden', ! str_contains( html(), 'th-services-title' ) );
check( 'services: nothing ships by default', array() === Settings::defaults()['services'] && false === Settings::defaults()['services_enabled'] );
settings( array( 'services_enabled' => true, 'services' => array( array( 'title' => 'خدمت تأییدشده', 'text' => 'توضیح', 'icon' => 'list' ), array( 'title' => '', 'text' => 'بی عنوان' ) ) ) );
$h = html( 'services' );
check( 'services: only rows with a title, as written', array( 'خدمت تأییدشده' ) === texts( $h, '//h3[' . cls( 'th-service__title' ) . ']' ) && ! str_contains( $h, 'بی عنوان' ) );
check( 'services: CTA follows the quote link', 1 === count( nodes( $h, '//a[' . cls( 'th-btn--primary' ) . ' and @href="' . esc_url( home_url( '/quote/' ) ) . '"]' ) ) );
settings( array( 'links' => array_merge( Settings::get()['links'], array( 'quote' => '' ) ) ) );
check( 'services: no CTA without the quote link', 0 === count( nodes( html( 'services' ), '//a[' . cls( 'th-btn' ) . ']' ) ) );

/* ------------------------------------------------------------ ۷. همکاری */
check( 'supplier: hidden without a link', '' === html( 'supplier' ) );
settings( array( 'links' => array_merge( Settings::get()['links'], array( 'supplier' => '/vendor/application/' ) ) ) );
$h = html( 'supplier' );
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
settings( array( 'categories' => array( array( 'id' => $c['monitor'] ), array( 'id' => $c['or'] ) ), 'product_count' => 8 ) );
$before = db_digest();
for ( $i = 0; $i < 3; $i++ ) {
	html();
}
Renderer::plan();
Catalog::featured_total();
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
check( 'assets: homepage loads css, font and js', str_contains( $page, 'tecteb-home.css' ) && str_contains( $page, 'tecteb-home-font.css' ) && str_contains( $page, 'tecteb-home.js' ) );
check( 'assets: css loads in <head>, not late in the footer', strpos( $page, 'tecteb-home.css' ) < strpos( $page, '</head>' ) );
check( 'assets: js is deferred', (bool) preg_match( '#<script[^>]+tecteb-home\.js[^>]*defer|<script[^>]+defer[^>]+tecteb-home\.js#', $page ) );
check( 'assets: font preloaded', str_contains( $page, 'rel="preload"' ) && str_contains( $page, 'Vazirmatn-wght.woff2' ) );
foreach ( array( 'plain page' => $plain, 'shop' => $shop, 'product' => $prod ) as $name => $body ) {
	check( "assets: nothing loads on {$name}", '' !== $body && ! str_contains( $body, 'tecteb-home' ) );
}
check( 'assets: no external host referenced by our assets', ! preg_match( '#https?://(?!127\.0\.0\.1)[^"\')\s]+#', file_get_contents( TECTEB_HOME_DIR . 'assets/css/tecteb-home.css' ) . file_get_contents( TECTEB_HOME_DIR . 'assets/js/tecteb-home.js' ) ) );
settings( array( 'font' => 'site' ) );
$page = http( '/tecteb-home-new/' );
check( 'font=site: no font css, no preload, root gets th-font-site', ! str_contains( $page, 'tecteb-home-font.css' ) && ! str_contains( $page, 'Vazirmatn-wght.woff2' ) && str_contains( $page, 'th-font-site' ) );
settings( array( 'font' => 'bundled' ) );

/* ------------------------------------------------------------ ۱۱. قالب صفحه */
$page = http( '/tecteb-home-new/' );
check( 'page template: site header kept', str_contains( $page, 'id="site-header"' ) );
check( 'page template: site footer kept', str_contains( $page, 'id="site-footer"' ) );
check( 'page template: no page title, one h1', 1 === preg_match_all( '#<h1[\s>]#', $page ) && ! str_contains( $page, 'صفحهٔ اصلی تازه</h1>' ) );
check( 'page template: offered in the page template list', isset( wp_get_theme()->get_page_templates( null, 'page' )[ \Tecteb\Homepage\PageTemplate::FILE ] ) );

/* ------------------------------------------------------------ ۱۲. کش */
$purged = array();
add_action( 'litespeed_purge_url', static function ( $url ) use ( &$purged ) {
	$purged[] = $url;
} );
Cache::reset();
( $tmp = wc_get_product( $p['oxi'] ) )->set_featured( false );
$tmp->save();
( $tmp = wc_get_product( $p['oxi'] ) )->set_featured( true );
$tmp->save();
check( 'cache: featured toggle purges the homepage URL once per request', array( home_url( '/' ) ) === $purged, wp_json_encode( $purged ) );
$purged = array();
Cache::reset();
settings( array( 'product_count' => 4 ) );
check( 'cache: saving settings purges the homepage', array( home_url( '/' ) ) === $purged );
$purged = array();
Cache::reset();
wp_update_term( $c['or'], 'product_cat', array( 'description' => 'به‌روز' ) );
check( 'cache: editing a category purges the homepage', array( home_url( '/' ) ) === $purged );

/* ------------------------------------------------------------ ۱۳. مدیریت */
Admin::register_hooks();
$admin = get_user_by( 'login', 'tthadmin' );
wp_set_current_user( $admin->ID );
check( 'admin: settings capability is manage_woocommerce', 'manage_woocommerce' === Admin::capability() && 'manage_woocommerce' === apply_filters( 'option_page_capability_' . Settings::GROUP, 'manage_options' ) );
$sub = wp_insert_user( array( 'user_login' => 'tth_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
check( 'admin: a subscriber cannot reach the page', ! user_can( $sub, Admin::capability() ) );
wp_delete_user( $sub );
settings( array( 'categories' => array( array( 'id' => $c['monitor'] ), array( 'id' => $c['evil'] ) ), 'services_enabled' => false ) );
$_GET['tth_q'] = 'دسته';
ob_start();
Admin::render();
$a = (string) ob_get_clean();
unset( $_GET['tth_q'] );
check( 'admin: page renders with settings form and nonce', str_contains( $a, 'action="' . esc_url( admin_url( 'options.php' ) ) . '"' ) && (bool) preg_match( '#name=[\'"]option_page[\'"] value=[\'"]tecteb_home[\'"]#', $a ) && str_contains( $a, '_wpnonce' ) );
check( 'admin: category search lists matches with full path and add buttons', str_contains( $a, 'تجهیزات پزشکی › پایش بیمار' ) || str_contains( $a, 'دستهٔ بی‌محصول' ) );
check( 'admin: add button is part of the save form', str_contains( $a, 'name="tecteb_home_settings[add_category]"' ) );
check( 'admin: hostile names escaped in the admin page', ! str_contains( $a, '<script>alert' ) && ! str_contains( $a, '<img src=x' ) );
check( 'admin: status table explains hidden sections', str_contains( $a, 'تا تأیید محتوا خاموش است' ) );
check( 'admin: wp-config constant shown read-only', str_contains( $a, 'TECTEB_HOME_SUPPLIER_URL' ) && str_contains( $a, 'staging.example' ) );
wp_set_current_user( 0 );

/* ------------------------------------------------------------ ۱۴. حذف */
$before = db_digest();
update_option( Settings::OPTION, Settings::defaults() );
define( 'WP_UNINSTALL_PLUGIN', 'tecteb-homepage/tecteb-homepage.php' );
include TECTEB_HOME_DIR . 'uninstall.php';
check( 'uninstall: removes only its own option', false === get_option( Settings::OPTION ) && $before === db_digest() );

echo "\n", $GLOBALS['tth_pass'], ' passed, ', $GLOBALS['tth_fail'], " failed\n";
exit( $GLOBALS['tth_fail'] > 0 ? 1 : 0 );
