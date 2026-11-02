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
 * نشانیِ خالی یعنی «مقصد تأییدشده‌ای نیست» و دکمه‌اش چاپ نمی‌شود. از ۰٫۳٫۰ تعمیرات و همکاری پیش‌فرضِ
 * تأییدشدهٔ مالک را دارند (DEFAULT_LINKS).
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

	/**
	 * مقصدهای تأییدشدهٔ مالک (۰٫۳٫۰): صفحهٔ موجود تعمیرات و صفحهٔ موجود همکاری. فقط پیش‌فرض‌اند؛
	 * پس از اولین ذخیره، مقدار فرم (حتی خالی = پنهان) مقدم است، و ثابت wp-config بر هر دو.
	 */
	public const DEFAULT_LINKS = array(
		'repair'         => '/https-tecteb-com-repair-request/',
		'supplier'       => '/landing-seller/',
		'supplier_terms' => '',
		'all_categories' => '',
	);

	/**
	 * ۰٫۳٫۳: چهار برچسب شناور کنار تصویر Hero، به همین ترتیب (دستور مالک) — [نام، آیکون sprite].
	 * هیچ شناسه یا اسلاگی اینجا نیست: هر برچسب با **نام دقیق** در دسته‌های واقعی ووکامرس پیدا می‌شود
	 * (Catalog::hero_labels())، و مدیر می‌تواند برای هر کدام شناسهٔ دسته را دستی تعیین کند (hero_labels).
	 * برچسبی که دستهٔ متناظر ندارد چاپ نمی‌شود.
	 */
	public const HERO_LABELS = array(
		array( 'پزشکی جنرال', 'care' ),
		array( 'بیهوشی و تنفسی', 'emergency' ),
		array( 'آزمایشگاه', 'lab' ),
		array( 'جراحی', 'or' ),
	);

	/** ۰٫۳٫۳: حداکثر برند در کاروسل «برندهای همکار». */
	public const MAX_BRANDS = 40;

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
			'links'               => self::DEFAULT_LINKS,
			'hero_lead'           => 'برای بیمارستان‌ها، کلینیک‌ها و آزمایشگاه‌ها: تجهیزات را جست‌وجو کنید، مشخصات هر محصول را ببینید و از فروشگاه تک‌طب تهیه کنید.',
			'audience_label'      => 'ویژهٔ خرید حرفه‌ای',
			'audience'            => array( 'بیمارستان‌ها', 'کلینیک‌ها', 'آزمایشگاه‌ها', 'مطب‌ها', 'مراکز دندان‌پزشکی' ),
			'chips'               => array(),
			'supplier_title'      => 'محصولاتتان را به خریداران حرفه‌ای تجهیزات پزشکی برسانید',
			'supplier_lead'       => 'تولیدکننده یا واردکننده‌اید؟ در تک‌طب فروشگاه خودتان را داشته باشید و به مراکز درمانی عرضه کنید.',
			'supplier_steps'      => array(),
			'font'                => 'bundled',
			// ۰٫۳٫۱: نمایش Concept C روی صفحهٔ نخست ِ «تنظیمات ← خواندن» بی تغییر آن برگه. پیش‌فرض خاموش.
			'front_page'          => false,
			// ۰٫۳٫۳: شناسهٔ دستهٔ دستیِ هر برچسب Hero؛ ۰ = پیدا کردن با نام. از ۰٫۳٫۴ از hero_items مشتق
			// می‌شود (همان دستهٔ هر ردیف) و فقط برای بازگشت به ۰٫۳٫۳ نوشته می‌شود؛ ۰٫۳٫۴ آن را نمی‌خواند.
			'hero_labels'         => array( 0, 0, 0, 0 ),
			// ۰٫۳٫۴: چهار ردیف برچسب Hero — عنوان، دسته، ترتیب، فعال. نبودنش (option ِ ۰٫۳٫۳) ⇒ از hero_labels.
			'hero_items'          => self::default_hero_items(),
			// ۰٫۳٫۳: برندهای همکار — فقط آنچه مدیر ثبت کرده.
			'brands'              => array(),
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

		// بی هیچ option ِ ذخیره‌شده (نصب تازه) پیش‌فرض‌های تأییدشده؛ پس از ذخیره، همان که فرم فرستاده.
		$links = is_array( $in['links'] ?? null ) ? $in['links'] : $d['links'];
		foreach ( array_keys( self::LINKS ) as $key ) {
			$given = trim( (string) ( array_key_exists( $key, $links ) ? $links[ $key ] : $d['links'][ $key ] ) );
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

		$out['front_page'] = ! empty( $in['front_page'] );

		// ۰٫۳٫۴: option ِ ۰٫۳٫۳ کلید hero_items ندارد ⇒ ردیف‌ها از شناسه‌های دستیِ ۰٫۳٫۳ ساخته می‌شوند، با همان
		// عنوانی که ۰٫۳٫۳ نشان می‌داد. hero_labels همیشه از ردیف‌ها مشتق می‌شود تا بازگشت به ۰٫۳٫۳ همان دسته‌ها را ببیند.
		$out['hero_items']  = is_array( $in['hero_items'] ?? null )
			? self::hero_items( $in['hero_items'], (string) ( $in['hero_pick'] ?? '' ), $report )
			: self::hero_items_from_pins( self::hero_labels( $in['hero_labels'] ?? array(), $report ) );
		$out['hero_labels'] = array_map( static fn( array $row ): int => $row['term'], $out['hero_items'] );

		// option ِ ۰٫۳٫۲ کلید brands ندارد ⇒ هنوز برندی ثبت نشده. فرم پیشخوان هر برندِ ثبت‌شده را ردیف به ردیف
		// می‌فرستد، پس فرمی که هیچ ردیفی ندارد یعنی همه حذف شده‌اند — همان جواب. add_brand ردیف «برند تازه» است.
		$out['brands'] = self::brands( $in['brands'] ?? array(), $in['add_brand'] ?? null, $report );

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

	/**
	 * شناسهٔ دستی هر برچسب Hero: ۰ (با نام پیدا شود) یا شناسهٔ یک دستهٔ موجود ووکامرس.
	 *
	 * @return list<int>
	 */
	private static function hero_labels( $rows, bool $report ): array {
		$rows = is_array( $rows ) ? array_values( $rows ) : array();
		$out  = array();
		foreach ( array_keys( self::HERO_LABELS ) as $i ) {
			$id = absint( $rows[ $i ] ?? 0 );
			if ( $id > 0 && ! ( get_term( $id, 'product_cat' ) instanceof \WP_Term ) ) {
				if ( $report ) {
					/* translators: 1: label name 2: term ID */
					self::error( 'hero_label_' . $i, sprintf( 'برای برچسب «%1$s» دستهٔ #%2$d در ووکامرس پیدا نشد؛ برچسب با نام پیدا می‌شود.', self::HERO_LABELS[ $i ][0], $id ) );
				}
				$id = 0;
			}
			$out[] = $id;
		}
		return $out;
	}

	/**
	 * ۰٫۳٫۴ — چهار ردیف پیش‌فرض: چهار عنوانِ ۰٫۳٫۳ به همان ترتیب، فعال، بی دستهٔ دستی (پیدا کردن با نام).
	 *
	 * @return list<array{title:string,term:int,order:int,active:bool}>
	 */
	public static function default_hero_items(): array {
		$out = array();
		foreach ( self::HERO_LABELS as $i => $slot ) {
			$out[] = array( 'title' => $slot[0], 'term' => 0, 'order' => $i + 1, 'active' => true );
		}
		return $out;
	}

	/**
	 * ارتقا از ۰٫۳٫۳: ردیفی که شناسهٔ دستی دارد همان دسته را نگه می‌دارد و عنوانش نام همان دسته است (آنچه
	 * ۰٫۳٫۳ نشان می‌داد)؛ بقیه عنوان پیش‌فرض و پیدا کردن با نام. هیچ چیز نوشته نمی‌شود.
	 *
	 * @param list<int> $pins
	 */
	private static function hero_items_from_pins( array $pins ): array {
		$out = self::default_hero_items();
		foreach ( $out as $i => $row ) {
			$term = (int) ( $pins[ $i ] ?? 0 );
			if ( $term > 0 ) {
				$t                   = get_term( $term, 'product_cat' );
				$out[ $i ]['term']  = $term;
				$out[ $i ]['title'] = $t instanceof \WP_Term ? self::text( $t->name, 40 ) : $row['title'];
			}
		}
		return $out;
	}

	/**
	 * ۰٫۳٫۴ — ردیف‌های برچسب Hero از فرم (یا شکل ذخیره‌شده). همیشه دقیقاً چهار ردیف، با همان شناسهٔ ردیف
	 * (اندیس) که نام پیش‌فرض و آیکون را تعیین می‌کند. عنوانِ خالی ⇒ نام دسته هنگام نمایش. دستهٔ نامعتبر هنگام
	 * ذخیره ⇒ ۰ و پیام. hero_pick («اندیس:شناسه») انتخاب از نتیجهٔ جست‌وجوی همان بخش است. هیچ دسته‌ای نوشته نمی‌شود.
	 *
	 * @return list<array{title:string,term:int,order:int,active:bool}>
	 */
	private static function hero_items( array $rows, string $pick, bool $report ): array {
		$rows = array_values( $rows );
		if ( preg_match( '/^([0-9]+):([0-9]+)$/', $pick, $m ) && isset( self::HERO_LABELS[ (int) $m[1] ] ) ) {
			$rows[ (int) $m[1] ]          = is_array( $rows[ (int) $m[1] ] ?? null ) ? $rows[ (int) $m[1] ] : array();
			$rows[ (int) $m[1] ]['term'] = (int) $m[2];
		}
		$list = array();
		foreach ( self::default_hero_items() as $i => $default ) {
			$row = is_array( $rows[ $i ] ?? null ) ? $rows[ $i ] : $default;
			$id  = absint( $row['term'] ?? 0 );
			// فقط هنگام ذخیره رد می‌شود. دسته‌ای که بعداً از ووکامرس حذف شود در خواندن همان شناسه می‌ماند تا
			// Catalog «missing» بگوید و برچسب پنهان شود — نه اینکه بی‌صدا به دستهٔ هم‌نامِ دیگری برود.
			if ( $report && $id > 0 && ! ( get_term( $id, 'product_cat' ) instanceof \WP_Term ) ) {
				if ( $report ) {
					/* translators: 1: label number 2: term ID */
					self::error( 'hero_item_' . $i, sprintf( 'برای برچسب شمارهٔ %1$d دستهٔ #%2$d در ووکامرس پیدا نشد؛ برچسب با نام پیش‌فرضش («%3$s») پیدا می‌شود.', $i + 1, $id, self::HERO_LABELS[ $i ][0] ) );
				}
				$id = 0;
			}
			$list[] = array(
				'title'  => self::text( $row['title'] ?? '', 40 ),
				'term'   => $id,
				'active' => ! empty( $row['active'] ),
				'order'  => isset( $row['order'] ) && is_numeric( $row['order'] ) ? (float) $row['order'] : (float) ( $i + 1 ),
				'index'  => $i,
			);
		}
		$sorted = $list;
		usort( $sorted, static fn( array $a, array $b ): int => array( $a['order'], $a['index'] ) <=> array( $b['order'], $b['index'] ) );
		foreach ( $sorted as $rank => $row ) {
			$list[ $row['index'] ]['order'] = $rank + 1;
		}
		return array_map(
			static fn( array $r ): array => array( 'title' => $r['title'], 'term' => $r['term'], 'order' => (int) $r['order'], 'active' => $r['active'] ),
			$list
		);
	}

	/**
	 * برندهای همکار: نام (لازم)، لوگو (پیوستِ تصویری کتابخانهٔ رسانه)، نشانی اختیاری، فعال، ترتیب.
	 * ردیفی که لوگوی معتبر ندارد نگه داشته می‌شود تا مدیر درستش کند، ولی غیرفعال و بی لوگو؛ صفحه فقط
	 * برند فعالِ دارای تصویر را نشان می‌دهد (Catalog::brands()).
	 *
	 * @return list<array{name:string,logo:int,url:string,active:bool}>
	 */
	private static function brands( $rows, $add, bool $report ): array {
		$list = array();
		$i    = 0;
		$rows = is_array( $rows ) ? array_values( $rows ) : array();
		if ( is_array( $add ) ) {
			$add['order'] = PHP_INT_MAX;
			$rows[]       = $add + array( 'active' => '1', 'new' => '1' );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}
			$name = self::text( $row['name'] ?? '', 60 );
			$logo = absint( $row['logo'] ?? 0 );
			$url  = trim( (string) ( $row['url'] ?? '' ) );
			if ( '' === $name ) {
				if ( $report && ( $logo > 0 || '' !== $url ) ) {
					self::error( 'brand_name', 'برندی بی نام ذخیره نشد؛ نام برند لازم است.' );
				}
				continue;
			}
			$active = ! empty( $row['active'] );
			if ( $logo > 0 && ! wp_attachment_is_image( $logo ) ) {
				if ( $report ) {
					/* translators: %s: brand name */
					self::error( 'brand_logo_' . $i, sprintf( 'لوگوی برند «%s» تصویر معتبری از کتابخانهٔ رسانه نیست؛ برند ذخیره شد ولی تا انتخاب لوگو نمایش داده نمی‌شود.', $name ) );
				}
				$logo = 0;
			}
			if ( 0 === $logo && $active ) {
				if ( $report && empty( $row['new'] ) ) {
					/* translators: %s: brand name */
					self::error( 'brand_nologo_' . $i, sprintf( 'برند «%s» لوگو ندارد و نمایش داده نمی‌شود.', $name ) );
				}
				$active = false;
			}
			$clean = self::clean_url( $url );
			if ( '' !== $url && '' === $clean && $report ) {
				/* translators: %s: brand name */
				self::error( 'brand_url_' . $i, sprintf( 'نشانی برند «%s» پذیرفته نشد و خالی ماند: فقط https://… ، http://… یا مسیری که با / شروع شود.', $name ) );
			}
			$list[] = array(
				'name'   => $name,
				'logo'   => $logo,
				'url'    => $clean,
				'active' => $active,
				'order'  => isset( $row['order'] ) && is_numeric( $row['order'] ) ? (float) $row['order'] : (float) ( $i + 1 ),
				'index'  => $i++,
			);
		}
		usort( $list, static fn( array $a, array $b ): int => array( $a['order'], $a['index'] ) <=> array( $b['order'], $b['index'] ) );
		if ( count( $list ) > self::MAX_BRANDS ) {
			if ( $report ) {
				self::error( 'brand_limit', sprintf( 'حداکثر %d برند؛ برندهای اضافه ذخیره نشدند.', self::MAX_BRANDS ) );
			}
			$list = array_slice( $list, 0, self::MAX_BRANDS );
		}
		return array_map(
			static fn( array $b ): array => array( 'name' => $b['name'], 'logo' => $b['logo'], 'url' => $b['url'], 'active' => $b['active'] ),
			$list
		);
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
