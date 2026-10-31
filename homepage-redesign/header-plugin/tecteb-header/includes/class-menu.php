<?php
/**
 * تنها خوانندهٔ دادهٔ هدر. دسکتاپ (Mega Menu) و موبایل (کشو) هر دو از همین دو متد
 * می‌خوانند — categories() و nav_items() — پس نمی‌توانند با هم اختلاف پیدا کنند.
 *
 * قواعد نمایش دسته (همان که نمونهٔ طراحی در shared/menu-render.js اجرا می‌کرد):
 *   • نام و نشانی هر بار از product_cat خوانده می‌شود؛ تنظیمات فقط شناسه نگه می‌دارند.
 *   • دستهٔ غیرفعال، حذف‌شده یا (فقط وقتی hide_empty روشن است) بی‌محصول نمایش داده
 *     نمی‌شود — و هیچ دستهٔ دیگری خودکار جایش نمی‌آید.
 *   • زیردسته فقط وقتی نمایش داده می‌شود که فعال باشد، وجود داشته باشد و **هنوز**
 *     زیرمجموعهٔ همان دستهٔ اصلی باشد.
 *
 * این کلاس فقط می‌خواند: هیچ تابع نوشتن روی دسته، محصول یا فهرست وردپرس ندارد.
 *
 * @package Tecteb\Header
 */

namespace Tecteb\Header;

defined( 'ABSPATH' ) || exit;

final class Menu {

	/** @var list<array<string,mixed>>|null */
	private static ?array $cats = null;

	public static function forget(): void {
		self::$cats = null;
	}

	/**
	 * دسته‌های قابل‌نمایش، به ترتیب تنظیمات.
	 *
	 * @return list<array{id:int,name:string,url:string,icon:string,children:list<array{id:int,name:string,url:string}>}>
	 */
	public static function categories(): array {
		if ( null !== self::$cats ) {
			return self::$cats;
		}
		$s   = Settings::get();
		$fp  = md5( (string) wp_json_encode( array( TECTEB_HEADER_VERSION, $s['categories'], $s['hide_empty'] ) ) );
		$key = Cache::key();
		$hit = get_transient( $key );
		if ( is_array( $hit ) && ( $hit['fp'] ?? '' ) === $fp && is_array( $hit['cats'] ?? null ) ) {
			self::$cats = $hit['cats'];
			return self::$cats;
		}
		self::$cats = self::resolve( $s );
		set_transient(
			$key,
			array(
				'fp'   => $fp,
				'cats' => self::$cats,
			),
			12 * HOUR_IN_SECONDS
		);
		return self::$cats;
	}

	/**
	 * @param array<string,mixed> $s
	 * @return list<array<string,mixed>>
	 */
	public static function resolve( array $s ): array {
		if ( ! taxonomy_exists( 'product_cat' ) || array() === $s['categories'] ) {
			return array();
		}
		$ids = array();
		foreach ( $s['categories'] as $c ) {
			$ids[] = (int) $c['term_id'];
			foreach ( $c['children'] as $k ) {
				$ids[] = (int) $k['term_id'];
			}
		}
		$terms = array();
		$found = get_terms(
			array(
				'taxonomy'               => 'product_cat',
				'include'                => array_values( array_unique( $ids ) ),
				'hide_empty'             => false,
				'update_term_meta_cache' => true,
			)
		);
		if ( is_array( $found ) ) {
			foreach ( $found as $t ) {
				$terms[ (int) $t->term_id ] = $t;
			}
		}

		$shown = static function ( ?\WP_Term $t ) use ( $s ): bool {
			return $t instanceof \WP_Term && ( ! $s['hide_empty'] || self::count( $t ) > 0 );
		};

		$out = array();
		foreach ( $s['categories'] as $c ) {
			$t = $terms[ (int) $c['term_id'] ] ?? null;
			if ( empty( $c['enabled'] ) || ! $shown( $t ) ) {
				continue;
			}
			$url = self::link( $t );
			if ( '' === $url ) {
				continue;
			}
			$children = array();
			foreach ( $c['children'] as $k ) {
				$kt = $terms[ (int) $k['term_id'] ] ?? null;
				if ( empty( $k['enabled'] ) || ! $shown( $kt ) || ! in_array( (int) $t->term_id, array_map( 'intval', get_ancestors( (int) $kt->term_id, 'product_cat', 'taxonomy' ) ), true ) ) {
					continue;
				}
				$kurl = self::link( $kt );
				if ( '' !== $kurl ) {
					$children[] = array(
						'id'   => (int) $kt->term_id,
						'name' => (string) $kt->name,
						'url'  => $kurl,
					);
				}
			}
			$out[] = array(
				'id'       => (int) $t->term_id,
				'name'     => (string) $t->name,
				'url'      => $url,
				'icon'     => self::icon_url( (int) $c['icon_id'] ),
				'children' => $children,
			);
		}
		return $out;
	}

	/**
	 * شمار محصولات دسته. ووکامرس شمار **با زیردسته‌ها** را در product_count_product_cat
	 * نگه می‌دارد؛ term->count فقط محصولات مستقیم است و دستهٔ مادرِ بی‌محصولِ مستقیم را
	 * خالی می‌خواند.
	 */
	public static function count( \WP_Term $t ): int {
		$wc = get_term_meta( (int) $t->term_id, 'product_count_product_cat', true );
		return is_numeric( $wc ) ? (int) $wc : (int) $t->count;
	}

	private static function link( \WP_Term $t ): string {
		$url = get_term_link( $t );
		return is_string( $url ) ? $url : '';
	}

	public static function icon_url( int $id ): string {
		if ( ! Settings::is_icon( $id ) ) {
			return '';
		}
		$src = wp_get_attachment_image_src( $id, array( 48, 48 ) );
		return is_array( $src ) && ! empty( $src[0] ) ? (string) $src[0] : '';
	}

	/**
	 * پیوندهای منوی اصلی از «نمایش ← فهرست‌ها» (فقط سطح اول)، بی آنچه مدیر پنهان کرده.
	 *
	 * @return list<array{id:int,title:string,url:string,current:bool,target:string,rel:string}>
	 */
	public static function nav_items(): array {
		$s = Settings::get();
		if ( ! $s['nav_menu'] ) {
			return array();
		}
		$items = wp_get_nav_menu_items( (int) $s['nav_menu'], array( 'update_post_term_cache' => false ) );
		if ( ! is_array( $items ) || array() === $items ) {
			return array();
		}
		if ( function_exists( '_wp_menu_item_classes_by_context' ) ) {
			_wp_menu_item_classes_by_context( $items );
		}
		$hidden = array_map( 'intval', $s['nav_hidden'] );
		$out    = array();
		foreach ( $items as $item ) {
			if ( 0 !== (int) $item->menu_item_parent || in_array( (int) $item->ID, $hidden, true ) || ! empty( $item->_invalid ) ) {
				continue;
			}
			$url = (string) $item->url;
			if ( '' === $url || '#' === $url ) {
				continue;
			}
			$out[] = array(
				'id'      => (int) $item->ID,
				'title'   => wp_strip_all_tags( (string) $item->title ),
				'url'     => $url,
				'current' => ! empty( $item->current ),
				'target'  => '_blank' === (string) $item->target ? '_blank' : '',
				'rel'     => (string) $item->xfn,
			);
		}
		return $out;
	}

	/** نشانی فروشگاه ووکامرس. */
	public static function shop_url(): string {
		$url = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';
		return '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * دسته‌های product_cat ِ یک فهرست وردپرس، به ترتیب خودِ فهرست — فقط خواندنی، برای
	 * پیشنهاد در داشبورد. سطح اول دسته‌ها = هر موردی که مادرش خودش دستهٔ محصول نیست.
	 *
	 * @return list<array{term_id:int,title:string,exists:bool}>
	 */
	public static function menu_categories( int $menu_id ): array {
		$items = $menu_id ? wp_get_nav_menu_items( $menu_id, array( 'update_post_term_cache' => false ) ) : false;
		if ( ! is_array( $items ) ) {
			return array();
		}
		$is_cat = array();
		foreach ( $items as $item ) {
			$is_cat[ (int) $item->ID ] = 'taxonomy' === $item->type && 'product_cat' === $item->object;
		}
		$out  = array();
		$seen = array();
		foreach ( $items as $item ) {
			if ( ! $is_cat[ (int) $item->ID ] || ! empty( $is_cat[ (int) $item->menu_item_parent ] ) ) {
				continue;
			}
			$id = (int) $item->object_id;
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$term        = get_term( $id, 'product_cat' );
			$out[]       = array(
				'term_id' => $id,
				'title'   => $term instanceof \WP_Term ? (string) $term->name : wp_strip_all_tags( (string) $item->title ),
				'exists'  => $term instanceof \WP_Term,
			);
		}
		return $out;
	}

	/**
	 * سیزده نام مبنا ⇒ دسته‌های واقعی با همان نام (پس از یکسان‌سازی ي/ك و نیم‌فاصله).
	 * دستهٔ سطح اول بر دستهٔ هم‌نامِ عمیق‌تر مقدم است؛ اگر چند نامزد بماند، «مبهم» است و
	 * پیشنهادی داده نمی‌شود.
	 *
	 * @return list<array{name:string,term_id:int,status:string,candidates:list<int>}>
	 */
	public static function baseline_matches(): array {
		$all = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'all',
				'number'     => 0,
			)
		);
		$by = array();
		if ( is_array( $all ) ) {
			foreach ( $all as $t ) {
				$by[ Settings::normalize_name( (string) $t->name ) ][] = $t;
			}
		}
		$out = array();
		foreach ( Settings::BASELINE as $name ) {
			$cands = $by[ Settings::normalize_name( $name ) ] ?? array();
			$top   = array_values( array_filter( $cands, static fn( $t ) => 0 === (int) $t->parent ) );
			$pick  = 1 === count( $top ) ? $top : ( array() === $top && 1 === count( $cands ) ? $cands : array() );
			$out[] = array(
				'name'       => $name,
				'term_id'    => $pick ? (int) $pick[0]->term_id : 0,
				'status'     => $pick ? 'found' : ( $cands ? 'ambiguous' : 'missing' ),
				'candidates' => array_map( static fn( $t ) => (int) $t->term_id, $cands ),
			);
		}
		return $out;
	}
}
