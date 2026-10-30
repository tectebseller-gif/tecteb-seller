<?php
/**
 * تنظیمات افزونه: یک option، یک شکل، و یک تابع که هر ورودی را به آن شکل می‌برد.
 *
 * این افزونه فقط **این** option را می‌نویسد. دسته‌های منتخب همین‌جا نگه داشته
 * می‌شوند، نه در term meta ِ ووکامرس، تا هیچ ردیفی از ووکامرس یا بازارگاه
 * لمس نشود؛ و چون staging و production دو دیتابیس‌اند، هر محیط تنظیم خودش را
 * دارد. هر نشانی را یک ثابت در wp-config.php هم می‌تواند تعیین کند، که هنگام
 * کپی‌کردن دیتابیس از یک محیط به دیگری جابه‌جا نمی‌شود.
 *
 * نشانیِ خالی یعنی «مقصد تأییدشده‌ای نیست» و دکمه‌اش چاپ نمی‌شود.
 *
 * از ۰٫۲٫۰ محصولات صفحهٔ اصلی فهرستِ دستیِ همین option اند (شناسهٔ محصول، به
 * ترتیب مدیر) — نه ستارهٔ «ویژه»ٔ ووکامرس — و «استعلام سازمانی» و بخش خدمات
 * عمومی برداشته شدند. کلیدهای option ِ نسخهٔ ۰٫۱٫۰ (quote، services_*) خوانده
 * نمی‌شوند و با اولین ذخیره از option می‌روند.
 *
 * @package Tecteb\Homepage
 */

namespace Tecteb\Homepage;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'tecteb_home_settings';
	public const GROUP  = 'tecteb_home';
	public const SCHEMA = 1;

	public const MAX_CATEGORIES = 8;
	public const MAX_PRODUCTS   = 12;
	public const MAX_SELECTED   = 24;
	public const MAX_CHIPS      = 6;
	public const MAX_AUDIENCE   = 6;
	public const MAX_STEPS      = 3;

	/** نام هر نشانی ← ثابتی که می‌تواند در wp-config.php تعیینش کند. */
	public const LINKS = array(
		'repair'         => 'TECTEB_HOME_REPAIR_URL',
		'supplier'       => 'TECTEB_HOME_SUPPLIER_URL',
		'supplier_terms' => 'TECTEB_HOME_SUPPLIER_TERMS_URL',
		'all_categories' => 'TECTEB_HOME_CATEGORIES_URL',
	);

	/** آیکون‌های قابل‌انتخاب برای دستهٔ بی‌تصویر؛ کلید = نام symbol در sprite. */
	public const CATEGORY_ICONS = array( 'monitor', 'or', 'ultrasound', 'lab', 'syringe', 'emergency', 'care', 'dental', 'store', 'image' );

	/** نتیجهٔ memoise‌شدهٔ get() در همین درخواست. */
	private static ?array $cache = null;

	public static function defaults(): array {
		return array(
			'schema'              => self::SCHEMA,
			'categories'          => array(),
			'products'            => array(),
			'product_count'       => 4,
			'links'               => array_fill_keys( array_keys( self::LINKS ), '' ),
			'hero_lead'           => 'برای بیمارستان‌ها، کلینیک‌ها و آزمایشگاه‌ها: تجهیزات را جست‌وجو کنید، مشخصات را کنار هم ببینید و از فروشگاه تک‌طب تهیه کنید.',
			'audience_label'      => 'ویژهٔ خرید حرفه‌ای',
			'audience'            => array( 'بیمارستان‌ها', 'کلینیک‌ها', 'آزمایشگاه‌ها', 'مطب‌ها', 'مراکز دندان‌پزشکی' ),
			'chips'               => array(),
			'supplier_title'      => 'محصولاتتان را به خریداران حرفه‌ای تجهیزات پزشکی برسانید',
			'supplier_lead'       => 'تولیدکننده یا واردکننده‌اید؟ در تک‌طب فروشگاه خودتان را داشته باشید و به مراکز درمانی عرضه کنید.',
			'supplier_steps'      => array(),
			'font'                => 'bundled',
		);
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPTION, array() );
			self::$cache = self::normalize( is_array( $raw ) ? $raw : array(), false );
		}
		return self::$cache;
	}

	public static function forget(): void {
		self::$cache = null;
	}

	/**
	 * نشانی نهایی یک مقصد، یا '' اگر مقصد تأییدشده‌ای نیست.
	 * ثابت wp-config بر option مقدم است؛ مسیر نسبی با home_url ِ همین محیط کامل می‌شود.
	 */
	public static function link( string $key ): string {
		$raw = self::link_raw( $key );
		if ( '' === $raw ) {
			return '';
		}
		return str_starts_with( $raw, '/' ) ? home_url( $raw ) : $raw;
	}

	/** مقدار ذخیره‌شده (یا ثابت)، پیش از کامل‌شدن مسیر نسبی. */
	public static function link_raw( string $key ): string {
		if ( ! isset( self::LINKS[ $key ] ) ) {
			return '';
		}
		$constant = self::LINKS[ $key ];
		if ( defined( $constant ) ) {
			return self::clean_url( (string) constant( $constant ) );
		}
		return self::get()['links'][ $key ] ?? '';
	}

	/** 'constant' اگر wp-config تعیینش کرده، وگرنه 'option'. */
	public static function link_source( string $key ): string {
		return isset( self::LINKS[ $key ] ) && defined( self::LINKS[ $key ] ) ? 'constant' : 'option';
	}

	/**
	 * پاک‌سازی برای register_setting. وردپرس همین را روی **هر** update_option صدا
	 * می‌زند، پس ورودی ممکن است فرم مدیریت باشد یا شکلِ از قبل نرمال‌شده؛ هر دو
	 * یک خروجی دارند.
	 */
	public static function sanitize( $input ): array {
		self::forget();
		return self::normalize( is_array( $input ) ? $input : array(), true );
	}

	/**
	 * @param array $in      ورودی خام.
	 * @param bool  $report  پیام خطا برای صفحهٔ تنظیمات ثبت شود (فقط هنگام ذخیره).
	 */
	public static function normalize( array $in, bool $report ): array {
		$d   = self::defaults();
		$out = $d;

		$out['categories'] = self::categories( $in['categories'] ?? array(), $in['add_category'] ?? null, $report );

		$out['products'] = self::products( $in['products'] ?? array(), $in['add_product'] ?? null, $report );

		$count                = isset( $in['product_count'] ) ? (int) $in['product_count'] : $d['product_count'];
		$out['product_count'] = max( 1, min( self::MAX_PRODUCTS, $count ) );

		$links = is_array( $in['links'] ?? null ) ? $in['links'] : array();
		foreach ( array_keys( self::LINKS ) as $key ) {
			$given = trim( (string) ( $links[ $key ] ?? '' ) );
			$clean = self::clean_url( $given );
			if ( '' !== $given && '' === $clean && $report ) {
				self::error( 'link_' . $key, sprintf( 'نشانی «%s» پذیرفته نشد و خالی ماند: فقط https://… ، http://… یا مسیری که با / شروع شود.', $given ) );
			}
			$out['links'][ $key ] = $clean;
		}

		foreach ( array( 'hero_lead' => 220, 'audience_label' => 40, 'supplier_title' => 110, 'supplier_lead' => 240 ) as $key => $max ) {
			$out[ $key ] = array_key_exists( $key, $in ) ? self::text( $in[ $key ], $max ) : $d[ $key ];
		}

		$out['audience'] = array_key_exists( 'audience', $in ) ? self::lines( $in['audience'], self::MAX_AUDIENCE, 30 ) : $d['audience'];
		$out['chips']    = self::lines( $in['chips'] ?? array(), self::MAX_CHIPS, 40 );

		$out['supplier_steps'] = self::rows( $in['supplier_steps'] ?? array(), self::MAX_STEPS );

		$out['font'] = 'site' === ( $in['font'] ?? '' ) ? 'site' : 'bundled';

		return $out;
	}

	/**
	 * نشانی مجاز: خالی، مسیر نسبی (/…، نه //…)، یا http(s) با میزبان. هر چیز
	 * دیگری — javascript:، data:، نشانی بی‌میزبان — رشتهٔ خالی می‌شود.
	 */
	public static function clean_url( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '#^/(?!/)[^\s<>"\'\\\\]*$#', $value ) ) {
			return '' === esc_url_raw( home_url( $value ) ) ? '' : $value;
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		return esc_url_raw( $value, array( 'http', 'https' ) );
	}

	private static function categories( $rows, $add, bool $report ): array {
		$list = array();
		$seen = array();
		$i    = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}
			$id = absint( $row['id'] ?? 0 );
			if ( 0 === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$list[]      = array(
				'id'    => $id,
				'icon'  => in_array( $row['icon'] ?? '', self::CATEGORY_ICONS, true ) ? $row['icon'] : '',
				'note'  => self::text( $row['note'] ?? '', 60 ),
				'order' => isset( $row['order'] ) && is_numeric( $row['order'] ) ? (float) $row['order'] : (float) ( $i + 1 ),
				'index' => $i++,
			);
		}

		$add = absint( $add );
		if ( $add > 0 && ! isset( $seen[ $add ] ) ) {
			$term = get_term( $add, 'product_cat' );
			if ( $term instanceof \WP_Term ) {
				$list[] = array( 'id' => $add, 'icon' => '', 'note' => '', 'order' => PHP_INT_MAX, 'index' => $i++ );
			} elseif ( $report ) {
				self::error( 'category_missing', 'این دسته در ووکامرس پیدا نشد و افزوده نشد.' );
			}
		}

		usort(
			$list,
			static fn( array $a, array $b ): int => array( $a['order'], $a['index'] ) <=> array( $b['order'], $b['index'] )
		);

		if ( count( $list ) > self::MAX_CATEGORIES ) {
			if ( $report ) {
				self::error( 'category_limit', sprintf( 'حداکثر %d دسته در صفحهٔ اصلی نمایش داده می‌شود؛ دسته‌های اضافه افزوده نشدند.', self::MAX_CATEGORIES ) );
			}
			$list = array_slice( $list, 0, self::MAX_CATEGORIES );
		}

		return array_map(
			static fn( array $c ): array => array( 'id' => $c['id'], 'icon' => $c['icon'], 'note' => $c['note'] ),
			$list
		);
	}

	/**
	 * محصولات منتخب: فقط شناسه‌ها، به ترتیب مدیر. هر شناسه باید پست ِ نوع `product`
	 * باشد (نه تنوع، نه پست دیگر)، و محصول تازه نباید در زباله باشد. وضعیت انتشار و نمایش اینجا
	 * سنجیده نمی‌شود: محصولی که امروز پیش‌نویس است می‌تواند انتخاب بماند و فقط
	 * تا منتشرشدنش نمایش داده نمی‌شود — صفحهٔ تنظیمات دلیلش را می‌گوید.
	 * هیچ چیزی روی خودِ محصول نوشته نمی‌شود.
	 *
	 * @return list<int>
	 */
	private static function products( $rows, $add, bool $report ): array {
		$list = array();
		$i    = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row = is_array( $row ) ? $row : array( 'id' => $row );
			if ( ! empty( $row['remove'] ) ) {
				continue;
			}
			$id = absint( $row['id'] ?? 0 );
			// ردیفِ از قبل انتخاب‌شده تا وقتی پستِ محصول هست می‌ماند، حتی در زباله: از
			// خروجی کنار می‌رود و صفحهٔ تنظیمات دلیلش را می‌گوید، ولی بی‌صدا از فهرست
			// مدیر پاک نمی‌شود. فقط محصولی که کاملاً حذف شده دیگر چیزی برای نگه‌داشتن ندارد.
			if ( $id > 0 && ! isset( $list[ $id ] ) && 'product' === get_post_type( $id ) ) {
				$list[ $id ] = array( isset( $row['order'] ) && is_numeric( $row['order'] ) ? (float) $row['order'] : (float) ( $i + 1 ), $i++ );
			}
		}

		$add = absint( $add );
		if ( $add > 0 && ! isset( $list[ $add ] ) ) {
			if ( self::is_product( $add ) ) {
				$list[ $add ] = array( (float) PHP_INT_MAX, $i++ );
			} elseif ( $report ) {
				self::error( 'product_missing', 'این شناسه محصول ووکامرس نیست و افزوده نشد.' );
			}
		}

		uasort( $list, static fn( array $a, array $b ): int => $a <=> $b );
		$ids = array_keys( $list );
		if ( count( $ids ) > self::MAX_SELECTED ) {
			if ( $report ) {
				self::error( 'product_limit', sprintf( 'حداکثر %d محصول می‌تواند انتخاب شود؛ محصولات اضافه افزوده نشدند.', self::MAX_SELECTED ) );
			}
			$ids = array_slice( $ids, 0, self::MAX_SELECTED );
		}
		return array_map( 'intval', $ids );
	}

	private static function is_product( int $id ): bool {
		return 'product' === get_post_type( $id ) && 'trash' !== get_post_status( $id );
	}

	private static function rows( $rows, int $max ): array {
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title = self::text( $row['title'] ?? '', 50 );
			if ( '' === $title ) {
				continue;
			}
			$out[] = array(
				'title' => $title,
				'text'  => self::text( $row['text'] ?? '', 140 ),
			);
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	/** فهرست یک‌خطی: آرایه یا متن چندخطی. */
	private static function lines( $value, int $max, int $len ): array {
		$items = is_array( $value ) ? $value : preg_split( '/\R/u', (string) $value );
		$out   = array();
		foreach ( $items as $item ) {
			$t = self::text( $item, $len );
			if ( '' !== $t && ! in_array( $t, $out, true ) ) {
				$out[] = $t;
			}
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	private static function text( $value, int $max ): string {
		$t = trim( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) );
		return mb_substr( $t, 0, $max );
	}

	private static function error( string $code, string $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, $code, $message );
		}
	}
}
