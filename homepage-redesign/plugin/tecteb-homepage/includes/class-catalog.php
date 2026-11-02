<?php
/**
 * خواندن از ووکامرس — و فقط خواندن.
 *
 * هر چیزی که صفحهٔ اصلی نشان می‌دهد از API عمومی ووکامرس و وردپرس می‌آید:
 * wc_get_products()، get_term()، get_term_link()، قیمت از Price (همان API ِ قیمت ووکامرس) و
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

	/** ۰٫۳٫۴: اندازهٔ دیداری لوگوی برندها (rem² و rem) — Catalog::logo_width(). */
	private const LOGO_AREA  = 9.0;
	private const LOGO_MAX_H = 3.0; // ≤ بلندی درونی کوچک‌ترین کارت (۴٫۷۵rem − ۱٫۵rem − ۲px)
	private const LOGO_MAX_W = 8.5;

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
				'price'  => Price::text( $product ),
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
				'price'      => Price::text( $product ),
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
			'price'    => Price::of( $product ),
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

	/**
	 * ۰٫۳٫۳ / ۰٫۳٫۴ — چهار برچسب کنار تصویر Hero (Settings ← hero_items)، به ترتیبِ ستون «ترتیب»، هر کدام با
	 * دستهٔ واقعیِ ووکامرس.
	 *
	 * اگر مدیر دسته‌ای تعیین کرده همان دسته؛ وگرنه دسته‌ای که نامش دقیقاً **نام پیش‌فرضِ همان ردیف** است
	 * (Settings::HERO_LABELS — با یکی‌کردن «ي/ك» ِ عربی، نیم‌فاصله و فاصله‌های تکراری). عنوانِ نمایشی مستقل
	 * از نام دسته است و در این جست‌وجو به کار نمی‌رود؛ عنوانِ خالی ⇒ نام دسته. چند دسته با یک نام: اگر دقیقاً
	 * یکی در ریشه است همان، وگرنه «مبهم» و چاپ نمی‌شود — حدس زده نمی‌شود. دستهٔ بی‌محصول و ردیف غیرفعال هم
	 * چاپ نمی‌شوند. هیچ چیز نوشته نمی‌شود.
	 *
	 * @return list<array{index:int,order:int,active:bool,resolved:string,title:string,match:string,name:string,icon:string,status:string,url:string,term:int,path:string,pinned:bool,candidates:list<array{id:int,path:string}>}>
	 *         status: ok | inactive | not_found | ambiguous | missing | empty | no_link
	 */
	public static function hero_labels(): array {
		$items = Settings::get()['hero_items'];
		$index = null;
		$out   = array();
		foreach ( Settings::HERO_LABELS as $i => $slot ) {
			$item = $items[ $i ] ?? array( 'title' => $slot[0], 'term' => 0, 'order' => $i + 1, 'active' => true );
			$row  = array(
				'index'      => $i,
				'order'      => (int) $item['order'],
				'active'     => (bool) $item['active'],
				'title'      => (string) $item['title'],
				'match'      => $slot[0],
				'name'       => (string) $item['title'],
				'icon'       => $slot[1],
				'status'     => '',
				'url'        => '',
				'term'       => 0,
				'path'       => '',
				'pinned'     => (int) $item['term'] > 0,
				'candidates' => array(),
			);
			$term = null;
			if ( $row['pinned'] ) {
				$term          = get_term( (int) $item['term'], 'product_cat' );
				$row['status'] = $term instanceof \WP_Term ? '' : 'missing';
			} else {
				$index   = $index ?? self::terms_by_name();
				$matches = $index[ self::name_key( $slot[0] ) ] ?? array();
				$roots   = array_values( array_filter( $matches, static fn( \WP_Term $t ): bool => 0 === (int) $t->parent ) );
				if ( array() === $matches ) {
					$row['status'] = 'not_found';
				} elseif ( 1 === count( $matches ) || 1 === count( $roots ) ) {
					$term = 1 === count( $matches ) ? $matches[0] : $roots[0];
				} else {
					$row['status']     = 'ambiguous';
					$row['candidates'] = array_map( static fn( \WP_Term $t ): array => array( 'id' => (int) $t->term_id, 'path' => self::path( $t ) ), $matches );
				}
			}
			if ( $term instanceof \WP_Term ) {
				$row['term'] = (int) $term->term_id;
				$row['path'] = self::path( $term );
				if ( '' === $row['name'] ) {
					$row['name'] = (string) $term->name;
				}
				$link = get_term_link( $term );
				if ( ! self::has_products( $term ) ) {
					$row['status'] = 'empty';
				} elseif ( is_wp_error( $link ) ) {
					$row['status'] = 'no_link';
				} else {
					$row['status'] = 'ok';
					$row['url']    = (string) $link;
				}
			}
			if ( '' === $row['name'] ) {
				$row['name'] = $slot[0];
			}
			$row['resolved'] = $row['status']; // وضعیت دسته، مستقل از فعال بودن — برای صفحهٔ تنظیمات.
			if ( ! $row['active'] ) {
				$row['status'] = 'inactive';
				$row['url']    = '';
			}
			$out[] = $row;
		}
		usort( $out, static fn( array $a, array $b ): int => array( $a['order'], $a['index'] ) <=> array( $b['order'], $b['index'] ) );
		return $out;
	}

	/** کلید مقایسهٔ نام: «ي/ك» ِ عربی ⇒ فارسی، بی نیم‌فاصله، فاصله‌های یکی‌شده. */
	public static function name_key( string $name ): string {
		$name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$name = strtr( $name, array( 'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', "\u{200C}" => '', "\u{200F}" => '', "\u{00A0}" => ' ' ) );
		return trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
	}

	/** @return array<string,list<\WP_Term>> همهٔ دسته‌های محصول، با کلید name_key. یک کوئری. */
	private static function terms_by_name(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		$index = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			if ( $t instanceof \WP_Term ) {
				$index[ self::name_key( (string) $t->name ) ][] = $t;
			}
		}
		return $index;
	}

	/**
	 * ۰٫۳٫۳ — برندهای همکارِ قابل‌نمایش: فعال، با لوگویی که امروز هنوز تصویرِ کتابخانهٔ رسانه است.
	 * لوگوی حذف‌شده یا غیرتصویری برند را کنار می‌گذارد؛ هیچ تصویر یا نامی ساخته نمی‌شود.
	 *
	 * @return list<array{name:string,url:string,image:string}>
	 */
	public static function brands(): array {
		$out = array();
		foreach ( Settings::get()['brands'] as $b ) {
			if ( empty( $b['active'] ) || (int) $b['logo'] < 1 || ! wp_attachment_is_image( (int) $b['logo'] ) ) {
				continue;
			}
			$attrs = array(
				'class'    => 'th-brand__img',
				'alt'      => $b['name'],
				'loading'  => 'lazy',
				'decoding' => 'async',
				'sizes'    => '160px',
			);
			$src   = wp_get_attachment_image_src( (int) $b['logo'], 'medium' );
			if ( is_array( $src ) && (int) $src[1] > 0 && (int) $src[2] > 0 ) {
				$attrs['style'] = '--th-logo-w:' . self::logo_width( (int) $src[1] / (int) $src[2] );
			}
			$img = (string) wp_get_attachment_image( (int) $b['logo'], 'medium', false, $attrs );
			if ( '' === $img ) {
				continue;
			}
			$out[] = array(
				'name'  => (string) $b['name'],
				'url'   => '' !== $b['url'] ? ( str_starts_with( $b['url'], '/' ) ? home_url( $b['url'] ) : $b['url'] ) : '',
				'image' => $img,
			);
		}
		return $out;
	}

	/**
	 * ۰٫۳٫۴ — پهنای نمایشی یک لوگو برای اندازهٔ دیداری یکسان: مساحت تقریباً ثابت (LOGO_AREA rem²)، پس
	 * پهنا = √(مساحت × نسبت). بلندی از LOGO_MAX_H و پهنا از LOGO_MAX_W بیشتر نمی‌شود، و هر دو حد با همان
	 * نسبت تصویر اعمال می‌شوند — لوگو هرگز کشیده نمی‌شود. CSS آن را به ظرف هم محدود می‌کند (max-inline-size).
	 */
	public static function logo_width( float $ratio ): string {
		$ratio = max( 0.05, min( 20.0, $ratio ) );
		$w     = sqrt( self::LOGO_AREA * $ratio );
		$w     = min( $w, self::LOGO_MAX_H * $ratio, self::LOGO_MAX_W );
		return sprintf( '%.2Frem', $w );
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
