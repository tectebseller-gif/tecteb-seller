<?php
/**
 * خواندن از ووکامرس — و فقط خواندن.
 *
 * هر چیزی که صفحهٔ اصلی نشان می‌دهد از API عمومی ووکامرس و وردپرس می‌آید:
 * wc_get_products()، get_term()، get_term_link()، get_price_html() و
 * wp_get_attachment_image(). این کلاس هیچ تابعی ندارد که چیزی بنویسد، و
 * عمداً loop ِ ووکامرس (wc_get_template_part / قلاب‌های shop_loop) را صدا
 * نمی‌زند: آن قلاب‌ها جایی‌اند که افزونه‌های دیگر، از جمله دکان، در کارت
 * محصول چیزی می‌نویسند.
 *
 * @package Tecteb\Homepage
 */

namespace Tecteb\Homepage;

defined( 'ABSPATH' ) || exit;

final class Catalog {

	/**
	 * دسته‌های منتخب، به ترتیب مدیر. دسته‌ای که حذف شده یا محصولی ندارد کنار
	 * گذاشته می‌شود و صفحهٔ تنظیمات همین را می‌گوید.
	 *
	 * @return list<array{id:int,name:string,slug:string,url:string,image:string,icon:string,note:string}>
	 */
	public static function categories(): array {
		$out = array();
		foreach ( Settings::get()['categories'] as $row ) {
			$term = get_term( (int) $row['id'], 'product_cat' );
			if ( ! $term instanceof \WP_Term || ! self::has_products( $term ) ) {
				continue;
			}
			$url = get_term_link( $term );
			if ( is_wp_error( $url ) ) {
				continue;
			}
			$thumb = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
			$out[] = array(
				'id'    => (int) $term->term_id,
				'name'  => (string) $term->name,
				'slug'  => (string) $term->slug,
				'url'   => (string) $url,
				'image' => $thumb > 0 ? (string) wp_get_attachment_image( $thumb, 'thumbnail', false, array( 'alt' => '', 'loading' => 'lazy', 'decoding' => 'async', 'class' => 'th-orb__img' ) ) : '',
				'icon'  => '' !== $row['icon'] ? $row['icon'] : 'store',
				'note'  => '' !== $row['note'] ? $row['note'] : self::first_line( (string) $term->description ),
			);
		}
		return $out;
	}

	/**
	 * دسته محصولی دارد که در فروشگاه دیده شود؟ با زیردسته‌ها: دستهٔ والدی که خودش
	 * محصولی ندارد و زیردسته‌هایش دارند، خالی نیست. term->count فقط محصولِ مستقیم
	 * را می‌شمارد، پس از خودِ کوئری ووکامرس پرسیده می‌شود (یک شناسه، نه فهرست).
	 */
	public static function has_products( \WP_Term $term ): bool {
		$ids = wc_get_products(
			array(
				'status'     => 'publish',
				'visibility' => 'catalog',
				'category'   => array( $term->slug ),
				'limit'      => 1,
				'return'     => 'ids',
			)
		);
		return is_array( $ids ) && array() !== $ids;
	}

	/**
	 * آرگومان‌های کوئری محصولات منتخب — جدا، تا آزمون و صفحهٔ تنظیمات همان را بپرسند.
	 * فقط شناسه‌هایی که مدیر انتخاب کرده (include)، و از میان آن‌ها فقط آنچه
	 * ووکامرس امروز در فروشگاه نشان می‌دهد: منتشرشده، قابل‌مشاهده در فهرست، و اگر
	 * «پنهان‌کردن ناموجودها» روشن است، نه ناموجود.
	 *
	 * @param list<int> $ids
	 */
	public static function selected_query( array $ids ): array {
		$args = array(
			'include'    => array_values( array_map( 'intval', $ids ) ),
			'status'     => 'publish',
			'visibility' => 'catalog',
			'limit'      => max( 1, count( $ids ) ),
			'return'     => 'objects',
		);
		// همان رفتار فروشگاه: «پنهان‌کردن ناموجودها» فقط outofstock را پنهان می‌کند، نه پیش‌سفارش.
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			$args['stock_status'] = array( 'instock', 'onbackorder' );
		}
		return $args;
	}

	/**
	 * محصولات منتخب مدیر، به ترتیب خودش، حداکثر به تعداد تنظیم‌شده. محصولی که حذف،
	 * پنهان، پیش‌نویس یا (با تنظیم ووکامرس) ناموجود شده کنار می‌رود و **هیچ محصول
	 * دیگری جایش نمی‌آید**؛ تعداد از میان همانِ قابل‌نمایش‌ها شمرده می‌شود.
	 *
	 * @return list<array>
	 */
	public static function selected_products(): array {
		$s   = Settings::get();
		$ids = $s['products'];
		if ( array() === $ids ) {
			return array();
		}
		$found = array();
		foreach ( (array) wc_get_products( self::selected_query( $ids ) ) as $product ) {
			if ( $product instanceof \WC_Product && '' === $product->get_post_password() ) {
				$found[ $product->get_id() ] = $product;
			}
		}
		$out = array();
		foreach ( $ids as $id ) {
			if ( isset( $found[ $id ] ) ) {
				$out[] = self::product_view( $found[ $id ] );
			}
			if ( count( $out ) >= (int) $s['product_count'] ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * وضعیت هر محصول منتخب برای صفحهٔ تنظیمات: نمایش داده می‌شود، یا چرا نه.
	 *
	 * @param list<int> $ids
	 * @return array<int,array{name:string,shown:bool,reason:string,edit:string,thumb:string,price:string,stock:string}>
	 */
	public static function selection_status( array $ids ): array {
		$visible = array();
		if ( array() !== $ids ) {
			$q           = self::selected_query( $ids );
			$q['return'] = 'ids';
			$visible     = array_map( 'intval', (array) wc_get_products( $q ) );
		}
		$out = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof \WC_Product ) {
				$out[ $id ] = array( 'name' => '#' . $id, 'shown' => false, 'reason' => 'محصول پیدا نشد (حذف شده است).', 'edit' => '', 'thumb' => '', 'price' => '', 'stock' => '' );
				continue;
			}
			$reason = '';
			if ( 'publish' !== $product->get_status() ) {
				$reason = 'منتشر نشده است (' . $product->get_status() . ').';
			} elseif ( ! in_array( $product->get_catalog_visibility(), array( 'visible', 'catalog' ), true ) ) {
				$reason = 'در فروشگاه پنهان است (نمایش در فهرست: ' . $product->get_catalog_visibility() . ').';
			} elseif ( '' !== $product->get_post_password() ) {
				$reason = 'رمزدار است.';
			} elseif ( ! in_array( $id, $visible, true ) ) {
				$reason = 'ناموجود است و ووکامرس ناموجودها را پنهان می‌کند.';
			}
			$out[ $id ] = array(
				'name'   => (string) $product->get_name(),
				'shown'  => '' === $reason,
				'reason' => $reason,
				'edit'   => (string) get_edit_post_link( $id, 'raw' ),
				'thumb'  => $product->get_image_id() ? (string) wp_get_attachment_image( (int) $product->get_image_id(), array( 40, 40 ), false, array( 'alt' => '' ) ) : '',
				'price'  => wp_strip_all_tags( (string) $product->get_price_html() ),
				'stock'  => (string) $product->get_stock_status(),
			);
		}
		return $out;
	}

	/**
	 * جست‌وجوی محصولات برای صفحهٔ تنظیمات: با شناسه، یا با نام/SKU از جست‌وجوی خودِ
	 * ووکامرس (search_products). همهٔ وضعیت‌ها جز زباله، بی تنوع‌ها.
	 *
	 * @return list<array{id:int,name:string,status:string,visibility:string,price:string,stock:string,thumb:string}>
	 */
	public static function search_products( string $query, int $limit = 30 ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}
		$ids = array();
		if ( ctype_digit( $query ) ) {
			$ids[] = (int) $query;
		}
		$store = \WC_Data_Store::load( 'product' );
		foreach ( (array) $store->search_products( wc_clean( $query ), '', false, true, $limit ) as $id ) {
			$ids[] = (int) $id;
		}
		$out = array();
		foreach ( array_unique( $ids ) as $id ) {
			$product = 'product' === get_post_type( $id ) ? wc_get_product( $id ) : null;
			if ( ! $product instanceof \WC_Product || 'trash' === $product->get_status() ) {
				continue;
			}
			$out[] = array(
				'id'         => $id,
				'name'       => (string) $product->get_name(),
				'status'     => (string) $product->get_status(),
				'visibility' => (string) $product->get_catalog_visibility(),
				'price'      => wp_strip_all_tags( (string) $product->get_price_html() ),
				'stock'      => (string) $product->get_stock_status(),
				'thumb'      => $product->get_image_id() ? (string) wp_get_attachment_image( (int) $product->get_image_id(), array( 40, 40 ), false, array( 'alt' => '' ) ) : '',
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	public static function product_view( \WC_Product $product ): array {
		$image_id = (int) $product->get_image_id();
		$alt      = $image_id > 0 ? trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ) : '';
		$price    = (string) $product->get_price_html();

		return array(
			'id'       => (int) $product->get_id(),
			'title'    => (string) $product->get_name(),
			'url'      => (string) $product->get_permalink(),
			'image'    => $image_id > 0 ? (string) wp_get_attachment_image(
				$image_id,
				'woocommerce_thumbnail',
				false,
				array(
					'alt'      => '' !== $alt ? $alt : $product->get_name(),
					'loading'  => 'lazy',
					'decoding' => 'async',
					'sizes'    => '(max-width: 479px) 34vw, (max-width: 899px) 45vw, 290px',
				)
			) : '',
			'category' => self::primary_category( $product ),
			'specs'    => self::specs( $product ),
			'price'    => '' !== trim( wp_strip_all_tags( $price ) ) ? $price : '',
			'stock'    => (string) $product->get_stock_status(),
		);
	}

	/** دستهٔ اصلی: انتخاب Rank Math یا Yoast اگر هست، وگرنه اولین دستهٔ محصول. */
	public static function primary_category( \WC_Product $product ): string {
		$id    = $product->get_id();
		$terms = get_the_terms( $id, 'product_cat' );
		if ( ! is_array( $terms ) || array() === $terms ) {
			return '';
		}
		foreach ( array( 'rank_math_primary_product_cat', '_yoast_wpseo_primary_product_cat' ) as $key ) {
			$primary = (int) get_post_meta( $id, $key, true );
			foreach ( $terms as $term ) {
				if ( $primary > 0 && (int) $term->term_id === $primary ) {
					return (string) $term->name;
				}
			}
		}
		return (string) $terms[0]->name;
	}

	/**
	 * «مشخصات کلیدی»: مقدار دو ویژگیِ قابل‌مشاهدهٔ اول محصول. چیزی ساخته نمی‌شود؛
	 * محصول بی‌ویژگی، چیپ ندارد.
	 *
	 * @return list<string>
	 */
	public static function specs( \WC_Product $product ): array {
		$out = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || ! $attribute->get_visible() ) {
				continue;
			}
			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
				: $attribute->get_options();
			$text   = trim( implode( ' · ', array_map( 'strval', (array) $values ) ) );
			if ( '' !== $text ) {
				$out[] = mb_strlen( $text ) > 40 ? mb_substr( $text, 0, 39 ) . '…' : $text;
			}
			if ( count( $out ) >= 2 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * جست‌وجوی دسته برای صفحهٔ تنظیمات، با مسیر کامل — «لوازم جانبی» در چند شاخه هست.
	 *
	 * @return list<array{id:int,name:string,path:string,count:int}>
	 */
	public static function search_categories( string $query, int $limit = 30 ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'search'     => $query,
				'number'     => $limit,
				'orderby'    => 'name',
			)
		);
		$out = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$out[] = array(
				'id'    => (int) $term->term_id,
				'name'  => (string) $term->name,
				'path'  => self::path( $term ),
				'count' => (int) $term->count,
			);
		}
		return $out;
	}

	public static function path( \WP_Term $term ): string {
		$names = array();
		foreach ( array_reverse( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ) as $ancestor ) {
			$t = get_term( $ancestor, 'product_cat' );
			if ( $t instanceof \WP_Term ) {
				$names[] = $t->name;
			}
		}
		$names[] = $term->name;
		return implode( ' › ', $names );
	}

	private static function first_line( string $html ): string {
		$text = trim( preg_split( '/\R/u', wp_strip_all_tags( $html ) )[0] ?? '' );
		return mb_strlen( $text ) > 48 ? mb_substr( $text, 0, 47 ) . '…' : $text;
	}
}
