<?php
/**
 * فقط می‌خواند — وضعیت «شمارش محصولات دسته‌های مادر» در Homepage، بی هیچ تغییری (۰٫۳٫۵: موضوع مستقل، تصمیم با مالک).
 *
 * wp eval-file tests/fixtures/parent-count.php
 *
 * برای هر دستهٔ ریشه‌ای که زیردسته دارد: عددی که جست‌وجوی دستهٔ صفحهٔ تنظیمات نشان می‌دهد (term->count، فقط محصولِ
 * مستقیم)، شمار محصولات منتشرشده با زیردسته‌ها (همان کوئری‌ای که فروشگاه برای آرشیو دسته می‌زند)، و جوابی که
 * Catalog::has_products() — دروازهٔ کارت دسته و برچسب Hero — می‌دهد.
 *
 * @package Tecteb\Homepage
 */

defined( 'ABSPATH' ) || exit;

if ( 'tth_disposable' !== DB_NAME ) {
	echo "refused: not the disposable site\n";
	return;
}

$tth_rows = array();
foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0 ) ) as $tth_t ) {
	$tth_kids = get_term_children( (int) $tth_t->term_id, 'product_cat' );
	if ( array() === $tth_kids || is_wp_error( $tth_kids ) ) {
		continue;
	}
	$tth_all = wc_get_products( array( 'status' => 'publish', 'category' => array( $tth_t->slug ), 'limit' => -1, 'return' => 'ids' ) );
	$tth_shown = '';
	foreach ( \Tecteb\Homepage\Catalog::search_categories( $tth_t->name, 30 ) as $tth_r ) {
		if ( (int) $tth_r['id'] === (int) $tth_t->term_id ) {
			$tth_shown = (string) $tth_r['count'];
		}
	}
	$tth_rows[] = sprintf(
		"%s (#%d): settings search shows %s · direct term->count %d · published with subcategories %d · has_products %s",
		$tth_t->name,
		$tth_t->term_id,
		'' === $tth_shown ? '—' : $tth_shown,
		(int) $tth_t->count,
		count( $tth_all ),
		\Tecteb\Homepage\Catalog::has_products( $tth_t ) ? 'yes' : 'no'
	);
}
echo implode( "\n", $tth_rows ), "\n";
