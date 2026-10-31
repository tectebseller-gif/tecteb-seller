<?php
/**
 * خواندن فقط‌خواندنیِ قالب‌های هدر Theme Builder و شرط نمایش آن‌ها.
 *
 * Elementor Pro شرط‌های هر قالب را در متای `_elementor_conditions` ِ همان پست (فهرستی از
 * رشته‌هایی مثل `include/general` = «کل سایت») و یک رونوشت در option ِ
 * `elementor_pro_theme_builder_conditions` نگه می‌دارد؛ نوع قالب در `_elementor_template_type`
 * است. این کلاس فقط با get_posts، get_post_meta و get_option می‌خواند — هیچ متد نوشتنی، هیچ
 * فراخوانی API ِ Elementor و هیچ تغییری در شرط‌ها.
 *
 * @package Tecteb\Header
 */

namespace Tecteb\Header;

defined( 'ABSPATH' ) || exit;

final class Elementor {

	public const CONDITIONS_OPTION = 'elementor_pro_theme_builder_conditions';

	/** @return array{elementor:string,pro:string,theme_builder:bool} */
	public static function versions(): array {
		return array(
			'elementor'     => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '',
			'pro'           => defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) ELEMENTOR_PRO_VERSION : '',
			'theme_builder' => class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ),
		);
	}

	/**
	 * همهٔ قالب‌های نوع «Header»، با وضعیت و شرط‌ها.
	 *
	 * @return list<array{id:int,title:string,status:string,conditions:list<string>,labels:list<string>,cached:list<string>|null}>
	 */
	public static function header_templates(): array {
		if ( ! post_type_exists( 'elementor_library' ) && ! self::has_library_posts() ) {
			return array();
		}
		$ids    = get_posts(
			array(
				'post_type'        => 'elementor_library',
				'post_status'      => array( 'publish', 'draft', 'private', 'pending', 'future' ),
				'numberposts'      => 50,
				'fields'           => 'ids',
				'meta_key'         => '_elementor_template_type', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- فقط در پیشخوان.
				'meta_value'       => 'header', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- فقط در پیشخوان.
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		$cache  = get_option( self::CONDITIONS_OPTION, array() );
		$cached = is_array( $cache ) && isset( $cache['header'] ) && is_array( $cache['header'] ) ? $cache['header'] : array();
		$out    = array();
		foreach ( $ids as $id ) {
			$id    = (int) $id;
			$conds = get_post_meta( $id, '_elementor_conditions', true );
			$conds = is_array( $conds ) ? array_values( array_map( 'strval', $conds ) ) : array();
			$out[] = array(
				'id'         => $id,
				'title'      => (string) get_the_title( $id ),
				'status'     => (string) get_post_status( $id ),
				'conditions' => $conds,
				'labels'     => array_map( array( self::class, 'label' ), $conds ),
				'cached'     => isset( $cached[ $id ] ) && is_array( $cached[ $id ] ) ? array_values( array_map( 'strval', $cached[ $id ] ) ) : null,
			);
		}
		return $out;
	}

	/** پست‌های elementor_library بی ثبت نوع پست (Elementor غیرفعال) هم خوانده شوند. */
	private static function has_library_posts(): bool {
		return (bool) get_posts(
			array(
				'post_type'   => 'elementor_library',
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
	}

	/** آیا این شناسه یک قالب Theme Builder از نوع Header است؟ */
	public static function is_header_template( int $id ): bool {
		return $id > 0
			&& 'elementor_library' === get_post_type( $id )
			&& 'header' === (string) get_post_meta( $id, '_elementor_template_type', true );
	}

	/** برچسب فارسی یک شرط Elementor؛ ناشناخته‌ها همان رشتهٔ خام. */
	public static function label( string $condition ): string {
		$parts = explode( '/', $condition );
		$mode  = array_shift( $parts );
		$rest  = implode( '/', $parts );
		$known = array(
			'general'                => __( 'کل سایت', 'tecteb-header' ),
			'archive'                => __( 'همهٔ بایگانی‌ها', 'tecteb-header' ),
			'singular'               => __( 'همهٔ نوشته‌ها و برگه‌ها', 'tecteb-header' ),
			'singular/front_page'    => __( 'صفحهٔ اصلی', 'tecteb-header' ),
			'singular/page'          => __( 'همهٔ برگه‌ها', 'tecteb-header' ),
			'singular/not_found404'  => __( 'صفحهٔ ۴۰۴', 'tecteb-header' ),
			'product_archive'        => __( 'بایگانی محصولات', 'tecteb-header' ),
			'product'                => __( 'همهٔ محصولات', 'tecteb-header' ),
		);
		$what = $known[ $rest ] ?? $rest;
		return 'exclude' === $mode
			/* translators: %s: condition */
			? sprintf( __( 'به‌جز: %s', 'tecteb-header' ), $what )
			/* translators: %s: condition */
			: sprintf( __( 'شامل: %s', 'tecteb-header' ), $what );
	}
}
