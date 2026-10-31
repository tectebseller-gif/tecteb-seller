<?php
/**
 * آزمون یکپارچهٔ tecteb-header روی وردپرس یکبارمصرفِ محلی (WooCommerce ۱۱٫۰٫۱ واقعی،
 * Elementor فقط شبیه‌ساز tests/fixtures/elementor-location-emulator.php).
 *
 *   wp eval-file tests/integration/run.php
 *
 * هر بررسی یک خط «ok|FAIL  نام» چاپ می‌کند؛ پایان: «N passed, M failed».
 *
 * @package Tecteb\Header
 */

use Tecteb\Header\Admin;
use Tecteb\Header\Cache;
use Tecteb\Header\Menu;
use Tecteb\Header\Renderer;
use Tecteb\Header\Replace;
use Tecteb\Header\Settings;

defined( 'ABSPATH' ) || exit;
if ( 'tth_disposable' !== DB_NAME || ! str_starts_with( home_url(), 'http://127.0.0.1' ) ) {
	fwrite( STDERR, "refused: not the disposable local site\n" );
	exit( 1 );
}

$GLOBALS['tthh_pass'] = 0;
$GLOBALS['tthh_fail'] = 0;
function tthh_ok( string $name, bool $cond, string $detail = '' ): void {
	if ( $cond ) {
		++$GLOBALS['tthh_pass'];
		echo "ok    {$name}\n";
	} else {
		++$GLOBALS['tthh_fail'];
		echo "FAIL  {$name}" . ( '' !== $detail ? "  — {$detail}" : '' ) . "\n";
	}
}
function tthh_save( array $value ): array {
	Settings::forget();
	update_option( Settings::OPTION, $value );
	Settings::forget();
	Menu::forget();
	return Settings::get();
}
function tthh_render( string $ctx = 'shortcode' ): string {
	Renderer::set_rendered( false );
	Menu::forget();
	Settings::forget();
	return Renderer::render( $ctx );
}
/** نام‌ها و نشانی‌های دسته در Mega Menu و در کشو. */
function tthh_cats( string $html ): array {
	$out = array( 'mega' => array(), 'drawer' => array(), 'mega_kids' => array(), 'drawer_kids' => array() );
	preg_match_all( '#<a class="tth-mega__main" href="([^"]+)">.*?<span class="tth-mega__name">([^<]*)</span>#s', $html, $m, PREG_SET_ORDER );
	foreach ( $m as $x ) {
		$out['mega'][] = $x[2] . '|' . $x[1];
	}
	preg_match_all( '#<details class="tth-drawer__cat">.*?<span class="tth-drawer__name">([^<]*)</span>.*?<ul><li><a href="([^"]+)">#s', $html, $m, PREG_SET_ORDER );
	foreach ( $m as $x ) {
		$out['drawer'][] = $x[1] . '|' . $x[2];
	}
	preg_match_all( '#<ul class="tth-mega__list">(.*?)</ul>#s', $html, $m );
	$out['mega_kids'] = preg_replace( '/\s+/', '', implode( '', $m[1] ) );
	preg_match_all( '#<details class="tth-drawer__cat">.*?<ul><li>.*?</li>(.*?)</ul></details>#s', $html, $m );
	$out['drawer_kids'] = preg_replace( '/\s+/', '', implode( '', $m[1] ) );
	return $out;
}
function tthh_snapshot(): string {
	global $wpdb;
	$parts = array();
	foreach ( array(
		"SELECT term_id,name,slug FROM {$wpdb->terms} ORDER BY term_id",
		"SELECT term_taxonomy_id,term_id,taxonomy,parent,count FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id",
		"SELECT meta_id,term_id,meta_key,meta_value FROM {$wpdb->termmeta} ORDER BY meta_id",
		"SELECT object_id,term_taxonomy_id FROM {$wpdb->term_relationships} ORDER BY object_id,term_taxonomy_id",
		"SELECT ID,post_title,post_status,post_type,post_modified,post_parent,menu_order FROM {$wpdb->posts} ORDER BY ID",
		"SELECT meta_id,post_id,meta_key,meta_value FROM {$wpdb->postmeta} ORDER BY meta_id",
	) as $sql ) {
		$parts[] = md5( (string) wp_json_encode( $wpdb->get_results( $sql, ARRAY_N ) ) ); // phpcs:ignore WordPress.DB
	}
	$parts[] = md5( (string) wp_json_encode( get_option( 'elementor_pro_theme_builder_conditions' ) ) );
	return implode( ',', $parts );
}
/** خروجی کامل header/footer ِ شبیه‌ساز Elementor در یک درخواست. */
function tthh_location( string $loc, bool $fresh = true ): string {
	if ( $fresh ) {
		TTHH_Locations_Manager::reset();
	}
	ob_start();
	$printed = elementor_theme_do_location( $loc );
	return ( $printed ? '' : '[not-printed]' ) . (string) ob_get_clean();
}

$ids   = get_option( 'tthh_fixture_ids' );
$mains = $ids['mains'];
$admin = get_user_by( 'login', 'tthadmin' );
wp_set_current_user( 0 );
delete_option( Settings::OPTION );
Settings::forget();

// ---------------------------------------------------------------- ۰. پیش‌فرض‌ها
$before_any = tthh_snapshot();
$d          = Settings::get();
tthh_ok( 'defaults: nothing stored on activation', null === $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT option_id FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", Settings::OPTION ) ) );
tthh_ok( 'defaults: no categories (no sample IDs stored)', array() === $d['categories'] );
tthh_ok( 'defaults: hide_empty is OFF (req 4)', false === $d['hide_empty'] );
tthh_ok( 'defaults: site-wide replacement is OFF', false === $d['replace'] );
tthh_ok( 'defaults: wishlist off, woocommerce search', 'off' === $d['wishlist_mode'] && 'woocommerce' === $d['search_mode'] );
$src = '';
foreach ( glob( TECTEB_HEADER_DIR . '{*.php,includes/*.php,templates/*.php}', GLOB_BRACE ) as $f ) {
	$src .= file_get_contents( $f ); // phpcs:ignore
}
tthh_ok( 'baseline: 13 names in code, no term IDs', 13 === count( Settings::BASELINE ) && ! preg_match( "/'term_id'\s*=>\s*1\d\d\b/", $src ) );
$empty = tthh_render();
tthh_ok( 'no categories ⇒ plain link to shop, no mega', str_contains( $empty, 'class="tth-cats__btn" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) ) && ! str_contains( $empty, 'tth-mega' ) );
tthh_ok( 'no nav menu ⇒ no fake menu items', ! str_contains( $empty, 'tth-menu' ) );

// ---------------------------------------------------------------- ۱. اعتبارسنجی
$all = array();
foreach ( $mains as $id ) {
	$all[] = array( 'term_id' => $id, 'enabled' => true, 'icon_id' => 0, 'children' => array() );
}
$bad   = $all;
$bad[] = array( 'term_id' => 999999, 'enabled' => true );
$bad[] = array( 'term_id' => (int) get_option( 'default_product_cat' ), 'enabled' => true );
$bad[] = array( 'term_id' => $mains['جراحی'], 'enabled' => true );
$bad[] = array( 'term_id' => 'abc', 'enabled' => true );
$bad[0]['children'] = array(
	array( 'term_id' => $ids['chair'], 'enabled' => true ),
);
$bad[7]['children'] = array(
	array( 'term_id' => $ids['chair'], 'enabled' => true ),
	array( 'term_id' => $ids['chair'], 'enabled' => true ),
	array( 'term_id' => $ids['xray'], 'enabled' => false ),
	array( 'term_id' => $mains['جراحی'], 'enabled' => true ),
);
$bad[7]['icon_id']  = $ids['png'];
$bad[1]['icon_id']  = $ids['webp'];
$bad[2]['icon_id']  = $ids['svg'];
$bad[3]['icon_id']  = $ids['jpg'];
$bad[4]['icon_id']  = $ids['page'];
$s                  = tthh_save( array( 'categories' => $bad ) );
$saved_ids          = array_map( static fn( $c ) => $c['term_id'], $s['categories'] );
tthh_ok( 'sanitize: missing / default / duplicate / non-numeric mains dropped', array_values( $mains ) === $saved_ids, wp_json_encode( $saved_ids ) );
tthh_ok( 'sanitize: child that is not a descendant dropped', array() === $s['categories'][0]['children'] );
tthh_ok( 'sanitize: duplicate child dropped, disabled kept, sibling-main rejected', array( array( 'term_id' => $ids['chair'], 'enabled' => true ), array( 'term_id' => $ids['xray'], 'enabled' => false ) ) === $s['categories'][7]['children'] );
tthh_ok( 'icon: PNG accepted', $ids['png'] === $s['categories'][7]['icon_id'] );
tthh_ok( 'icon: WebP accepted', $ids['webp'] === $s['categories'][1]['icon_id'] );
tthh_ok( 'icon: SVG rejected (req 5)', 0 === $s['categories'][2]['icon_id'] );
tthh_ok( 'icon: JPEG rejected', 0 === $s['categories'][3]['icon_id'] );
tthh_ok( 'icon: non-attachment rejected', 0 === $s['categories'][4]['icon_id'] );
tthh_ok( 'settings: only IDs/flags stored — no names, slugs or URLs', ! preg_match( '/name|slug|url|title/', (string) wp_json_encode( $s['categories'] ) ) );
$too_many = array();
foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'exclude' => array( (int) get_option( 'default_product_cat' ) ) ) ) as $t ) {
	$too_many[] = array( 'term_id' => $t->term_id, 'enabled' => true );
}
tthh_ok( 'sanitize: at most 16 mains', 16 === count( tthh_save( array( 'categories' => $too_many ) )['categories'] ) );

foreach ( array(
	'/landing-seller/'               => '/landing-seller/',
	'https://tecteb.com/x/'          => 'https://tecteb.com/x/',
	'javascript:alert(1)'            => '',
	'//evil.example/'                => '',
	'/a"onmouseover="x'              => '',
	'data:text/html,1'               => '',
	'ftp://x/'                       => '',
) as $in => $want ) {
	tthh_ok( "url: {$in}", $want === tthh_save( array( 'supplier_url' => $in ) )['supplier_url'] );
}
tthh_ok( 'search shortcode: single shortcode only', '' === tthh_save( array( 'search_mode' => 'shortcode', 'search_shortcode' => '<script>x</script>' ) )['search_shortcode'] && 'woocommerce' === Settings::get()['search_mode'] );
tthh_ok( 'search shortcode: [name attr] accepted', '[fibosearch]' === tthh_save( array( 'search_mode' => 'shortcode', 'search_shortcode' => '[fibosearch]' ) )['search_shortcode'] );
tthh_save( array( 'search_mode' => 'woocommerce', 'search_shortcode' => '' ) );
tthh_ok( 'wishlist link without URL ⇒ off', 'off' === tthh_save( array( 'wishlist_mode' => 'link', 'wishlist_url' => '' ) )['wishlist_mode'] );
tthh_ok( 'logo: SVG rejected', 0 === tthh_save( array( 'logo_id' => $ids['svg'] ) )['logo_id'] );
tthh_ok( 'logo_text: tags stripped, 40 chars', 'تک‌طب' === tthh_save( array( 'logo_text' => '<b>تک‌طب</b>' ) )['logo_text'] );
tthh_ok( 'nav menu: unknown menu rejected', 0 === tthh_save( array( 'nav_menu' => 987654 ) )['nav_menu'] );

// زبانه‌ها: فرم یک زبانه بقیه را پاک نمی‌کند؛ چک‌باکس ِ نفرستاده یعنی خاموش.
$s = tthh_save(
	array(
		'categories' => $all,
		'nav_menu'   => $ids['menu'],
		'nav_hidden' => array( $ids['nav']['cats'], $ids['nav']['seller'] ),
		'hide_empty' => true,
	)
);
$s = tthh_save( array( '_tab' => 'links', 'nav_menu' => $ids['menu'], 'supplier_url' => '/landing-seller/', 'search_mode' => 'woocommerce', 'wishlist_mode' => 'off', 'font' => 'plugin' ) );
tthh_ok( 'tab links: categories untouched', 13 === count( $s['categories'] ) && true === $s['hide_empty'] );
tthh_ok( 'tab links: unchecked checkbox ⇒ off', false === $s['home_search_toggle'] && array() === $s['nav_hidden'] );
$s = tthh_save( array( '_tab' => 'categories', 'categories' => $all ) );
tthh_ok( 'tab categories: hide_empty unchecked ⇒ off', false === $s['hide_empty'] && 0 === $s['nav_menu'] - $ids['menu'] );
$s = tthh_save( array( '_tab' => 'replace' ) );
tthh_ok( 'tab replace: unchecked ⇒ replace off, rest kept', false === $s['replace'] && 13 === count( $s['categories'] ) );
$rev = array();
foreach ( array_reverse( $all ) as $i => $c ) {
	$rev[] = $c + array( 'order' => 13 - $i );
}
$s = tthh_save( array( '_tab' => 'categories', 'categories' => $rev ) );
tthh_ok( 'order field reorders (no-JS path)', array_values( $mains ) === array_map( static fn( $c ) => $c['term_id'], $s['categories'] ) );
$withremove                  = $all;
$withremove[2]['remove']     = 1;
$withremove[0]['enabled']    = '';
$s                           = tthh_save( array( '_tab' => 'categories', 'categories' => $withremove, 'add_main' => array( $ids['other_top'], $mains['جراحی'] ) ) );
tthh_ok( 'remove drops one, add_main appends new and skips duplicate', 13 === count( $s['categories'] ) && $ids['other_top'] === end( $s['categories'] )['term_id'] && ! in_array( $mains['چشم‌پزشكی'], array_column( $s['categories'], 'term_id' ), true ) );
tthh_ok( 'unchecked enabled ⇒ disabled (kept in list)', false === $s['categories'][0]['enabled'] );

// ---------------------------------------------------------------- ۲. انتقال (فقط خواندن)
$before_import = tthh_snapshot();
$menu_rows     = Menu::menu_categories( $ids['menu'] );
tthh_ok( 'import from WP menu: 13 product_cat items in menu order', array_values( $mains ) === array_column( $menu_rows, 'term_id' ), wp_json_encode( array_column( $menu_rows, 'term_id' ) ) );
tthh_ok( 'import: nested product_cat item under a product_cat item ignored', ! in_array( $ids['chair'], array_column( $menu_rows, 'term_id' ), true ) );
$jm = Menu::menu_categories( $ids['jm'] );
tthh_ok( 'custom-link menu (JetMenu shape): 12 categories resolved by URL, in menu order', 12 === count( $jm ) && $mains['پزشکی جنرال'] === $jm[0]['term_id'] && ! in_array( $mains['استریلیزاسیون'], array_column( $jm, 'term_id' ), true ), wp_json_encode( array_column( $jm, 'term_id' ) ) );
$cand = array_column( Menu::menu_candidates( $ids['jm'] ), null, 'title' );
tthh_ok( 'custom links: other domain, percent-encoded, no trailing slash, ?product_cat=, upper-case scheme all resolved', 'url' === $cand['پزشکی جنرال']['match'] && 'url' === $cand['قلب و عروق']['match'] && 'url' === $cand['چشم‌پزشكی']['match'] && 'url' === $cand['جراحی']['match'] );
tthh_ok( 'custom link with only a matching title ⇒ «name», not pre-selected by menu_categories', 'name' === $cand['استریلیزاسیون']['match'] && $mains['استریلیزاسیون'] === $cand['استریلیزاسیون']['term_id'] );
tthh_ok( 'non-category items (shop, parent #) not matched', '' === $cand['فروشگاه']['match'] && '' === $cand['دسته بندی کالا ها']['match'] );
$matches = Menu::baseline_matches();
$by      = array_column( $matches, null, 'name' );
tthh_ok( 'baseline: 13 rows', 13 === count( $matches ) );
tthh_ok( 'baseline: Arabic ك + ZWNJ normalised (چشم پزشکی)', 'found' === $by['چشم پزشکی']['status'] && $mains['چشم‌پزشكی'] === $by['چشم پزشکی']['term_id'] );
tthh_ok( 'baseline: Arabic ي normalised (گوش و حلق و بینی)', 'found' === $by['گوش و حلق و بینی']['status'] );
tthh_ok( 'baseline: top-level wins over same-named deep term (جراحی)', 'found' === $by['جراحی']['status'] && $mains['جراحی'] === $by['جراحی']['term_id'] );
tthh_ok( 'baseline: unknown name ⇒ missing, no guess (آزمایشگاه)', 'missing' === $by['آزمایشگاه']['status'] && 0 === $by['آزمایشگاه']['term_id'] );
tthh_ok( 'import preview writes nothing', $before_import === tthh_snapshot() && 13 === count( Settings::get()['categories'] ) );
$prev = Settings::get();
$di   = array_search( $mains['دندانپزشکی'], array_column( $prev['categories'], 'term_id' ), true );
$prev['categories'][ $di ]['icon_id']  = $ids['png'];
$prev['categories'][ $di ]['children'] = array( array( 'term_id' => $ids['chair'], 'enabled' => true ) );
$prev = tthh_save( $prev );
tthh_ok( 'fixture: dentistry has icon and child before import', $ids['png'] === $prev['categories'][ $di ]['icon_id'] && 1 === count( $prev['categories'][ $di ]['children'] ) );
$s = tthh_save( array( '_tab' => 'import', 'import' => array_column( $menu_rows, 'term_id' ) ) );
tthh_ok( 'import without the confirm checkbox changes nothing', $prev['categories'] === $s['categories'] );
$s = tthh_save( array( '_tab' => 'import', 'import_apply' => '1', 'import' => array_column( $menu_rows, 'term_id' ) ) );
tthh_ok( 'import apply: menu order; known keep their state, new are enabled', array_values( $mains ) === array_column( $s['categories'], 'term_id' ) && false === $s['categories'][0]['enabled'] && true === $s['categories'][2]['enabled'] );
tthh_ok( 'import apply: icon and children of known category kept', $ids['png'] === $s['categories'][7]['icon_id'] && 1 === count( $s['categories'][7]['children'] ) );
$s = tthh_save( array( '_tab' => 'import', 'baseline_apply' => '1', 'baseline' => array_values( array_filter( array_column( $matches, 'term_id' ) ) ) ) );
tthh_ok( 'baseline apply: 12 found saved with real IDs', 12 === count( $s['categories'] ) );

// ---------------------------------------------------------------- ۳. نمایش
$cfg = array();
foreach ( $mains as $id ) {
	$cfg[] = array( 'term_id' => $id, 'enabled' => true, 'icon_id' => 0, 'children' => array() );
}
$cfg[1]['children'] = array( array( 'term_id' => $ids['monitor'], 'enabled' => true ), array( 'term_id' => $ids['empty_kid'], 'enabled' => true ) );
$cfg[1]['icon_id']  = $ids['webp'];
$cfg[7]['children'] = array( array( 'term_id' => $ids['chair'], 'enabled' => true ), array( 'term_id' => $ids['xray'], 'enabled' => false ) );
$cfg[7]['icon_id']  = $ids['png'];
$cfg[12]['enabled'] = false;
tthh_save(
	array(
		'categories' => $cfg,
		'nav_menu'   => $ids['menu'],
		'nav_hidden' => array( $ids['nav']['cats'], $ids['nav']['seller'] ),
		'hide_empty' => false,
		'replace'    => false,
	)
);
$h = tthh_render();
$c = tthh_cats( $h );
tthh_ok( 'render: 12 enabled mains shown, disabled hidden, order kept', 12 === count( $c['mega'] ) && str_starts_with( $c['mega'][0], 'پزشکی جنرال|' ) && ! str_contains( $h, 'بیهوشی و تنفسی' ) );
tthh_ok( 'render: desktop and mobile from one source (same names + URLs)', $c['mega'] === $c['drawer'] && $c['mega_kids'] === $c['drawer_kids'], wp_json_encode( array( $c['mega_kids'], $c['drawer_kids'] ) ) );
tthh_ok( 'render: names and links from product_cat', str_contains( $h, esc_url( get_term_link( $mains['قلب و عروق'], 'product_cat' ) ) ) );
tthh_ok( 'render: disabled child hidden', str_contains( $h, 'یونیت دندانپزشکی' ) && ! str_contains( $h, 'رادیولوژی دندان' ) );
tthh_ok( 'render: hide_empty OFF shows empty categories', str_contains( $h, 'پزشکی جنرال' ) && str_contains( $h, 'زیردستهٔ بی‌محصول' ) );
tthh_ok( 'render: PNG/WebP icon <img>, others grid fallback', 2 === preg_match_all( '#tth-mega__icon"><img src="[^"]+\.(png|webp)"#', $h ) && 10 === substr_count( $h, 'tth-mega__icon tth-mega__icon--none' ) );
tthh_ok( 'render: nav from WP menu, hidden items skipped', str_contains( $h, '>درخواست تعمیرات</a>' ) && ! str_contains( $h, 'دسته بندی کالا ها' ) && ! str_contains( $h, 'در تک طب فروشنده شوید' ) );
tthh_ok( 'render: nav HTML in title stripped', str_contains( $h, '>تماس با ما !</a>' ) && ! str_contains( $h, '<b>!</b>' ) );
tthh_ok( 'render: nav appears in desktop and drawer', 2 === substr_count( $h, '>درخواست تعمیرات</a>' ) );
tthh_ok( 'render: supplier link «همکاری تأمین‌کنندگان» → /landing-seller/', 2 === substr_count( $h, 'href="' . home_url( '/landing-seller/' ) . '"' ) );
tthh_ok( 'render: search = standard WooCommerce product search', str_contains( $h, 'name="post_type" value="product"' ) && str_contains( $h, 'action="' . home_url( '/' ) . '"' ) );
tthh_ok( 'render: guest ⇒ login link to my-account', str_contains( $h, 'data-tth-guest' ) && str_contains( $h, esc_url( wc_get_page_permalink( 'myaccount' ) ) ) && ! str_contains( $h, 'tth-acct-menu' ) );
tthh_ok( 'render: wishlist OFF ⇒ no heart, no fake count', ! str_contains( $h, 'tth-tool--wish' ) && ! str_contains( $h, '#tth-i-heart"/></svg></span>' ) );
tthh_ok( 'render: no cart in CLI ⇒ cart link without badge', str_contains( $h, 'tth-tool--cart' ) && ! preg_match( '#tth-tool--cart.*?tth-badge#s', $h ) );
tthh_ok( 'render: no sample/demo markers', ! preg_match( '/data-src=|proto-note|data-proto|کاربر نمونه/u', $h ) );
tthh_ok( 'render: no banner role in shortcode context', ! str_contains( $h, 'role="banner"' ) );

// XSS
$x                 = $cfg;
$x[]               = array( 'term_id' => $ids['xss'], 'enabled' => true, 'icon_id' => 0, 'children' => array() );
tthh_save( array( 'categories' => $x ) );
$h = tthh_render();
tthh_ok( 'escape: category name with HTML is escaped', str_contains( $h, 'دسته &lt;img src=x onerror=alert(1)&gt; &quot;ویژه&quot; &amp; &lt;b&gt;' ) && ! str_contains( $h, '<img src=x' ) && ! str_contains( $h, '& <b>' ) );
tthh_save( array( 'categories' => $cfg ) );

// hide_empty
tthh_save( array( 'hide_empty' => true ) );
$h = tthh_render();
tthh_ok( 'hide_empty ON: main without products hidden', ! str_contains( $h, '>پزشکی جنرال<' ) );
tthh_ok( 'hide_empty ON: parent whose products are in sub-subcategory shown', str_contains( $h, '>قلب و عروق<' ) && str_contains( $h, '>مانیتورینگ قلب<' ) );
// ووکامرس روی get_terms ِ جلوی سایت count را با شمار «با زیردسته‌ها» جایگزین می‌کند
// (wc_change_term_counts)؛ در پیشخوان و هر جای دیگری که آن فیلتر نیست، شمار مستقیم است.
// پس قاعده باید بی آن فیلتر هم درست باشد.
$had = remove_filter( 'get_terms', 'wc_change_term_counts', 10 );
Cache::bump();
$h2 = tthh_render();
if ( $had ) {
	add_filter( 'get_terms', 'wc_change_term_counts', 10, 2 );
}
Cache::bump();
tthh_ok( 'hide_empty ON without WooCommerce count filter: parent with nested products still shown', $had && str_contains( $h2, '>قلب و عروق<' ) && ! str_contains( $h2, '>پزشکی جنرال<' ) );
tthh_ok( 'hide_empty ON: empty child hidden', ! str_contains( $h, 'زیردستهٔ بی‌محصول' ) );
tthh_ok( 'hide_empty ON: selection not deleted', 13 === count( Settings::get()['categories'] ) );
tthh_save( array( 'hide_empty' => false ) );

// خواندن زنده از ووکامرس: تغییر نام، جابه‌جایی، حذف — بی تغییر تنظیمات.
$settings_before = get_option( Settings::OPTION );
wp_update_term( $mains['جراحی'], 'product_cat', array( 'name' => 'جراحی و اتاق عمل' ) );
$h = tthh_render();
tthh_ok( 'live: renamed category shows new name (cache invalidated on edit)', str_contains( $h, '>جراحی و اتاق عمل<' ) );
wp_update_term( $mains['جراحی'], 'product_cat', array( 'name' => 'جراحی' ) );
wp_update_term( $ids['chair'], 'product_cat', array( 'parent' => $mains['جراحی'] ) );
$h = tthh_render();
tthh_ok( 'live: child moved out of its main ⇒ hidden', ! str_contains( $h, 'یونیت دندانپزشکی' ) );
wp_update_term( $ids['chair'], 'product_cat', array( 'parent' => $mains['دندانپزشکی'] ) );
$tmp = wp_insert_term( 'دستهٔ موقت حذف', 'product_cat' );
$cx  = $cfg;
$cx[] = array( 'term_id' => $tmp['term_id'], 'enabled' => true, 'icon_id' => 0, 'children' => array() );
tthh_save( array( 'categories' => $cx ) );
tthh_ok( 'live: temp category shown', str_contains( tthh_render(), 'دستهٔ موقت حذف' ) );
wp_delete_term( $tmp['term_id'], 'product_cat' );
tthh_ok( 'live: deleted category disappears, nothing replaces it', ! str_contains( tthh_render(), 'دستهٔ موقت حذف' ) && 12 === count( tthh_cats( tthh_render() )['mega'] ) );
tthh_save( array( 'categories' => $cfg ) );
tthh_ok( 'live: settings value not rewritten by WooCommerce changes', $settings_before['categories'] === get_option( Settings::OPTION )['categories'] );

// cache
$key = Cache::key();
tthh_render();
tthh_ok( 'cache: categories cached in transient', is_array( get_transient( $key ) ) );
$gen = (int) get_option( Cache::GEN );
tthh_save( array( 'logo_text' => 'تک‌طب' ) );
tthh_ok( 'cache: settings save bumps generation', (int) get_option( Cache::GEN ) === $gen + 1 );
$gen = (int) get_option( Cache::GEN );
wp_update_term( $ids['other_kid'], 'product_cat', array( 'description' => 'x' ) );
tthh_ok( 'cache: editing an unconfigured category does not bump', (int) get_option( Cache::GEN ) === $gen );
$purges = 0;
add_action( 'litespeed_purge_all', static function () use ( &$purges ) { ++$purges; } );
Cache::reset();
tthh_save( array( 'logo_text' => 'تک طب' ) );
tthh_save( array( 'logo_text' => 'تک‌طب' ) );
tthh_ok( 'cache: litespeed_purge_all fired once per request on save', 1 === $purges );

// یک بار در هر درخواست
Renderer::set_rendered( false );
$first  = Renderer::render( 'shortcode' );
$second = Renderer::render( 'shortcode' );
tthh_ok( 'render once: second call prints only a comment', str_contains( $first, 'data-tth-header' ) && ! str_contains( $second, 'data-tth-header' ) );

// حساب واردشده
wp_set_current_user( $admin->ID );
$h = tthh_render();
tthh_ok( 'member: account menu from wc_get_account_menu_items', str_contains( $h, 'id="tth-acct-menu"' ) && str_contains( $h, esc_url( wc_get_account_endpoint_url( 'orders' ) ) ) && str_contains( $h, esc_html( $admin->display_name ) ) );
tthh_ok( 'member: logout uses wc_logout_url (nonce)', str_contains( $h, esc_url( wc_logout_url() ) ) && str_contains( wc_logout_url(), '_wpnonce' ) );
tthh_ok( 'member: no guest link', ! str_contains( $h, 'data-tth-guest' ) );
wp_set_current_user( 0 );

// علاقه‌مندی
tthh_save( array( 'wishlist_mode' => 'link', 'wishlist_url' => '/wishlist/' ) );
$h = tthh_render();
tthh_ok( 'wishlist link: heart to admin URL, no count without adapter', str_contains( $h, 'tth-tool--wish" href="' . home_url( '/wishlist/' ) . '"' ) && ! preg_match( '#tth-tool--wish.*?tth-badge#s', explode( 'tth-tool--cart', $h )[0] ) );
add_filter( 'tecteb_header_wishlist_count', static fn() => 3 );
$h = tthh_render();
tthh_ok( 'wishlist: count only from adapter filter', str_contains( $h, 'data-tth-count>۳</span>' ) );
remove_all_filters( 'tecteb_header_wishlist_count' );
tthh_save( array( 'wishlist_mode' => 'off' ) );

// ---------------------------------------------------------------- ۴. جایگزینی هدر Elementor (شبیه‌ساز وفادار به Pro Elements 4.2.2)
use Tecteb\Header\Elementor;

// خواندن فقط‌خواندنیِ قالب‌ها و شرط‌ها
$tpls = array_column( Elementor::header_templates(), null, 'id' );
tthh_ok( 'templates: only Header-type templates listed (no footer/section)', array( 2652, 2653, 2654 ) === array_keys( $tpls ), wp_json_encode( array_keys( $tpls ) ) );
tthh_ok( 'templates: 2652 published, condition include/general = «کل سایت»', 'publish' === $tpls[2652]['status'] && array( 'include/general' ) === $tpls[2652]['conditions'] && array( 'شامل: کل سایت' ) === $tpls[2652]['labels'] );
tthh_ok( 'templates: exclude conditions labelled', in_array( 'به‌جز: صفحهٔ اصلی', $tpls[2654]['labels'], true ) );
tthh_ok( 'templates: conditions cache read and compared', array( 'include/general' ) === $tpls[2652]['cached'] && null === $tpls[2653]['cached'] );
tthh_ok( 'is_header_template: header yes; footer, section, product no', Elementor::is_header_template( 2652 ) && ! Elementor::is_header_template( 2700 ) && ! Elementor::is_header_template( 2655 ) && ! Elementor::is_header_template( $ids['products'][0] ) );
$s = tthh_save( array( 'replace_templates' => array( 2652, 2700, 2655, $ids['products'][0], 999999 ) ) );
tthh_ok( 'settings: replace_templates keeps only Header templates', array( 2652 ) === $s['replace_templates'] );
$s = tthh_save( array( 'replace_templates' => array(), 'replace' => true ) );
tthh_ok( 'settings: replacement cannot be switched on without a chosen template', false === $s['replace'] );

$footer_alone = tthh_location( 'footer' );
tthh_save( array( 'replace_templates' => array( 2652 ), 'replace' => false ) );
Renderer::set_rendered( false );
$out = tthh_location( 'header' );
tthh_ok( 'replace OFF: Elementor header 2652 printed, plugin header not', str_contains( $out, 'data-elementor-id="2652"' ) && ! str_contains( $out, 'data-tth-header' ) );
tthh_save( array( 'replace' => true ) );
Renderer::set_rendered( false );
$out = tthh_location( 'header' );
tthh_ok( 'replace ON: exactly one header — plugin header; 2652 not rendered', ! str_contains( $out, 'data-tthh-original' ) && 1 === substr_count( $out, 'data-tth-header' ) && 'replaced' === Replace::decision() );
tthh_ok( 'replace ON: plugin header is the page banner', str_contains( $out, 'role="banner"' ) );
tthh_ok( 'replace ON: footer untouched (byte-identical)', $footer_alone === tthh_location( 'footer' ) );
tthh_ok( 'replace ON: no HTML comment for visitors', ! str_contains( $out, '<!-- tecteb-header' ) );

update_option( 'tthh_emulate_neighbors', 1 );
Renderer::set_rendered( false );
$level = ob_get_level();
$out   = tthh_location( 'header' );
$pb    = strpos( $out, 'neighbor-before' );
$ph    = strpos( $out, 'data-tth-header' );
$pa    = strpos( $out, 'neighbor-after' );
tthh_ok( 'other plugins on before/after_do_header keep their output, in order', false !== $pb && false !== $ph && false !== $pa && $pb < $ph && $ph < $pa );
tthh_ok( 'no output buffer opened or left behind', $level === ob_get_level() );
delete_option( 'tthh_emulate_neighbors' );

Renderer::set_rendered( false );
TTHH_Locations_Manager::reset();
$out = tthh_location( 'header', false ) . tthh_location( 'header', false );
tthh_ok( 'header location run twice in one request ⇒ still one header, 2652 never printed', 1 === substr_count( $out, 'data-tth-header' ) && ! str_contains( $out, 'data-tthh-original' ) );

Renderer::set_rendered( false );
$early = Renderer::render( 'shortcode' );
$out   = tthh_location( 'header' );
tthh_ok( 'shortcode printed earlier + replace ⇒ 2652 removed, nothing second', str_contains( $early, 'data-tth-header' ) && ! str_contains( $out, 'data-tth-header' ) && ! str_contains( $out, 'data-tthh-original' ) );

update_option( 'tthh_emulate_shortcode', 1 );
Renderer::set_rendered( false );
$out = tthh_location( 'header' );
tthh_ok( 'replace ON + [tecteb_header] inside the Elementor template ⇒ exactly one', 1 === substr_count( $out, 'data-tth-header' ) );
tthh_save( array( 'replace' => false ) );
Renderer::set_rendered( false );
$out = tthh_location( 'header' );
tthh_ok( 'shortcode in Elementor template, replace OFF ⇒ one plugin header inside the 2652 shell', 1 === substr_count( $out, 'data-tth-header' ) && str_contains( $out, 'data-tthh-original' ) );
delete_option( 'tthh_emulate_shortcode' );
tthh_save( array( 'replace' => true ) );

// قالب انتخاب‌نشده (هدر ویژهٔ یک صفحه) دست نمی‌خورد.
$conds = get_option( 'elementor_pro_theme_builder_conditions' );
update_option( 'elementor_pro_theme_builder_conditions', array( 'header' => array( 2654 => array( 'include/general' ) ) ) + $conds );
Renderer::set_rendered( false );
$out = tthh_location( 'header' );
tthh_ok( 'template not in allowlist ⇒ Elementor prints it, plugin prints nothing', str_contains( $out, 'data-elementor-id="2654"' ) && ! str_contains( $out, 'data-tth-header' ) && 'not_allowed' === Replace::decision() );
update_option( 'elementor_pro_theme_builder_conditions', $conds );

// شیء غیرمنتظره ⇒ هیچ.
Renderer::set_rendered( false );
ob_start();
do_action( 'elementor/theme/before_do_header' );
do_action( 'elementor/theme/after_do_header' );
$out = (string) ob_get_clean();
tthh_ok( 'no locations manager object ⇒ plugin prints nothing', '' === trim( $out ) && 'no_manager' === Replace::decision() );
$broken = new class() {
	private array $q = array( 'header' => array( 2652 => 2652 ) );
	public function get_documents_for_location( string $l ): array { return $this->q[ $l ] ?? array(); }
	public function remove_doc_from_location( $l, $id ) {} // phpcs:ignore -- عمداً کار نمی‌کند.
	public function add_doc_to_location( $l, $id ) { $this->q[ $l ][ $id ] = $id; }
};
Renderer::set_rendered( false );
ob_start();
do_action( 'elementor/theme/before_do_header', $broken );
do_action( 'elementor/theme/after_do_header', $broken );
$out = (string) ob_get_clean();
tthh_ok( 'queue not emptied ⇒ documents restored, plugin prints nothing', '' === trim( $out ) && 'queue_not_emptied' === Replace::decision() && array( 2652 => 2652 ) === $broken->get_documents_for_location( 'header' ) );

update_option( 'tthh_emulate', 'footer' );
Renderer::set_rendered( false );
tthh_ok( 'no Elementor header for the page ⇒ plugin prints nothing (theme fallback)', '[not-printed]' === tthh_location( 'header' ) );
update_option( 'tthh_emulate', 'both' );
$_GET['elementor-preview'] = '1';
Renderer::set_rendered( false );
tthh_ok( 'Elementor editor/preview ⇒ never replaced', str_contains( tthh_location( 'header' ), 'data-tthh-original' ) );
unset( $_GET['elementor-preview'] );

tthh_save( array( 'replace' => false ) );
$_GET['tth_preview'] = '1';
Renderer::set_rendered( false );
tthh_ok( '?tth_preview=1 for a visitor ⇒ ignored', str_contains( tthh_location( 'header' ), 'data-tthh-original' ) );
wp_set_current_user( $admin->ID );
Renderer::set_rendered( false );
$out = tthh_location( 'header' );
tthh_ok( '?tth_preview=1 for an admin ⇒ plugin header, with an admin-only explanation', 1 === substr_count( $out, 'data-tth-header' ) && str_contains( $out, '<!-- tecteb-header: replaced Elementor header template 2652 -->' ) );
unset( $_GET['tth_preview'] );
tthh_save( array( 'replace' => true ) );
$_GET['tth_original'] = '1';
Renderer::set_rendered( false );
tthh_ok( '?tth_original=1 for an admin ⇒ Elementor header even when replace is on', str_contains( tthh_location( 'header' ), 'data-tthh-original' ) );
wp_set_current_user( 0 );
Renderer::set_rendered( false );
tthh_ok( '?tth_original=1 for a visitor ⇒ ignored', ! str_contains( tthh_location( 'header' ), 'data-tthh-original' ) );
unset( $_GET['tth_original'] );
tthh_save( array( 'replace' => false ) );

// WP Rocket: پاک‌سازی کش با ذخیره (بدلِ تابع ِ WP Rocket فقط در همین آزمون).
if ( ! function_exists( 'rocket_clean_domain' ) ) {
	function rocket_clean_domain() { // phpcs:ignore
		$GLOBALS['tthh_rocket'] = ( $GLOBALS['tthh_rocket'] ?? 0 ) + 1;
	}
}
Cache::reset();
$GLOBALS['tthh_rocket'] = 0;
tthh_save( array( 'logo_text' => 'تک طب' ) );
tthh_save( array( 'logo_text' => 'تک‌طب' ) );
tthh_ok( 'WP Rocket: rocket_clean_domain() once per request on save', 1 === $GLOBALS['tthh_rocket'] );
tthh_ok( 'font: inline @font-face with absolute URL (survives CSS minify/relocation)', str_contains( \Tecteb\Header\Assets::font_css(), 'url("' . TECTEB_HEADER_URL . 'assets/fonts/Vazirmatn-wght.woff2")' ) );
tthh_ok( 'cart fragments on by default (cached pages show the real count)', true === Settings::defaults()['cart_fragments'] );

// ---------------------------------------------------------------- ۵. منوی پیشخوان
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user( $admin->ID );
global $menu, $submenu, $_wp_submenu_nopriv, $admin_page_hooks, $_registered_pages;
$menu    = array();
$submenu = array();
Admin::menu();
$tops = array_filter( $menu, static fn( $m ) => str_contains( (string) $m[0], 'تک‌طب' ) );
tthh_ok( 'admin menu: no «تک‌طب» ⇒ one created, «مدیریت هدر» its only (visible) submenu', 1 === count( $tops ) && array( 'مدیریت هدر' ) === array_column( $submenu[ Admin::HUB ] ?? array(), 0 ) && array( Admin::SLUG ) === array_column( $submenu[ Admin::HUB ], 2 ) );
$menu    = array();
$submenu = array();
add_menu_page( 'تک‌طب', 'تک&zwnj;طب <span class="awaiting-mod">2</span>', 'manage_options', 'tecteb-hub', '__return_null' );
add_menu_page( 'بازارگاه تک‌طب', 'بازارگاه تک‌طب', 'manage_options', 'tmc-dashboard', '__return_null' );
Admin::menu();
$tops = array_filter( $menu, static fn( $m ) => str_contains( wp_strip_all_tags( (string) $m[0] ), 'طب' ) );
tthh_ok( 'admin menu: existing «تک‌طب» reused, no duplicate top-level', 2 === count( $tops ) && in_array( 'مدیریت هدر', array_column( $submenu['tecteb-hub'] ?? array(), 0 ), true ) && empty( $submenu[ Admin::HUB ] ) && ! in_array( Admin::HUB, array_column( $menu, 2 ), true ) );
tthh_ok( 'admin menu: «بازارگاه تک‌طب» is not taken as «تک‌طب»', empty( $submenu['tmc-dashboard'] ) );
tthh_ok( 'capability: option page capability = manage_options', 'manage_options' === apply_filters( 'option_page_capability_tecteb_header', 'manage_options' ) && 'manage_options' === Settings::capability() );
wp_set_current_user( 0 );

// ---------------------------------------------------------------- ۶. هیچ نوشتنی روی ووکامرس
// اندازه پیش از هر نوشتن عمدیِ همین آزمون (تغییر نام/جابه‌جایی بالا) گرفته نشده؛ پس اینجا یک دور
// تازه: تنظیمات، import، نمایش و جایگزینی — و مقایسه.
$snap = tthh_snapshot();
tthh_save( array( '_tab' => 'import', 'import_apply' => '1', 'import' => array_column( Menu::menu_categories( $ids['menu'] ), 'term_id' ) ) );
Menu::baseline_matches();
tthh_render();
tthh_save( array( 'replace' => true ) );
Renderer::set_rendered( false );
tthh_location( 'header' );
tthh_save( array( 'replace' => false, 'categories' => $cfg ) );
tthh_ok( 'no writes to terms, term meta, relationships, posts, post meta or menus', $snap === tthh_snapshot() );

echo "\n{$GLOBALS['tthh_pass']} passed, {$GLOBALS['tthh_fail']} failed\n";
