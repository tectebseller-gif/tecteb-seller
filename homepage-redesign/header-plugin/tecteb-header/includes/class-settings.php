<?php
/**
 * تنظیمات هدر: یک option، فقط شناسه، ترتیب و وضعیت نمایش.
 *
 * نام و نشانی هیچ دسته‌ای ذخیره نمی‌شود؛ در هر نمایش از product_cat خوانده می‌شود
 * (Menu::categories). ترتیب = ترتیب آرایه. هیچ چیز روی دسته، محصول یا term meta
 * نوشته نمی‌شود — تنها نوشتن این افزونه همین option است.
 *
 * هر ورودی پیش از ذخیره اعتبارسنجی می‌شود و ورودی نامعتبر کنار می‌رود (نه حدس):
 *   • دستهٔ اصلی باید یک product_cat موجود باشد، تکراری نباشد و دستهٔ پیش‌فرض ووکامرس نباشد.
 *   • زیردسته باید **زیرمجموعهٔ همان دستهٔ اصلی** باشد.
 *   • آیکون باید پیوستی از کتابخانهٔ رسانه با نوع PNG یا WebP باشد (SVG پذیرفته نمی‌شود).
 *   • نشانی‌ها فقط مسیر نسبی (/…) یا http(s).
 *
 * @package Tecteb\Header
 */

namespace Tecteb\Header;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION       = 'tecteb_header_settings';
	public const SCHEMA       = 1;
	public const MAX_MAINS    = 16;
	public const MAX_CHILDREN = 12;
	public const ICON_MIMES   = array( 'image/png', 'image/webp' );

	/**
	 * سیزده دستهٔ اصلی فعلی سایت (از تصویر Staging ِ مالک). فقط **نام** است و هرگز به‌عنوان
	 * دادهٔ ذخیره‌شده به کار نمی‌رود؛ داشبورد با آن دسته‌های واقعی را پیدا و پیشنهاد می‌کند و
	 * تنها با تأیید صریح مدیر شناسه‌های واقعی ذخیره می‌شوند.
	 */
	public const BASELINE = array(
		'پزشکی جنرال',
		'قلب و عروق',
		'چشم پزشکی',
		'جراحی',
		'زنان و زایمان',
		'ارتوپدی',
		'توانبخشی',
		'دندانپزشکی',
		'آزمایشگاه',
		'استریلیزاسیون',
		'گوش و حلق و بینی',
		'هتلینگ',
		'بیهوشی و تنفسی',
	);

	private static ?array $memo = null;

	/** فیلدهای هر زبانهٔ داشبورد. */
	private const GROUPS = array(
		'categories' => array( 'categories', 'hide_empty' ),
		'import'     => array( 'categories' ),
		'links'      => array( 'nav_menu', 'nav_hidden', 'supplier_url', 'logo_id', 'logo_text', 'search_mode', 'search_shortcode', 'wishlist_mode', 'wishlist_url', 'cart_fragments', 'font', 'home_search_toggle' ),
		'replace'    => array( 'replace_templates', 'replace', 'assets_everywhere' ),
	);

	/** @var array<string,bool> پیامی که در همین درخواست گزارش شده. */
	private static array $reported = array();

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'schema'             => self::SCHEMA,
			'categories'         => array(),
			'hide_empty'         => false,
			'nav_menu'           => 0,
			'nav_hidden'         => array(),
			'supplier_url'       => '/landing-seller/',
			'logo_id'            => 0,
			'logo_text'          => 'تک‌طب',
			'search_mode'        => 'woocommerce',
			'search_shortcode'   => '',
			'wishlist_mode'      => 'off',
			'wishlist_url'       => '',
			'cart_fragments'     => true,
			'font'               => 'plugin',
			'home_search_toggle' => true,
			'assets_everywhere'  => false,
			'replace'            => false,
			'replace_templates'  => array(),
		);
	}

	/** @return array<string,mixed> */
	public static function get(): array {
		if ( null === self::$memo ) {
			$stored     = get_option( self::OPTION, array() );
			self::$memo = self::shape( is_array( $stored ) ? $stored : array() );
		}
		return self::$memo;
	}

	public static function forget(): void {
		self::$memo = null;
	}

	/**
	 * شکل ذخیره‌شده را بی اعتبارسنجی دوباره به شکل کامل می‌رساند (کلید غایب ⇒ پیش‌فرض).
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public static function shape( array $raw ): array {
		$d   = self::defaults();
		$out = array();
		foreach ( $d as $key => $default ) {
			$value = array_key_exists( $key, $raw ) ? $raw[ $key ] : $default;
			if ( is_bool( $default ) ) {
				$value = (bool) $value;
			} elseif ( is_int( $default ) ) {
				$value = (int) $value;
			} elseif ( is_string( $default ) ) {
				$value = is_scalar( $value ) ? (string) $value : $default;
			} elseif ( ! is_array( $value ) ) {
				$value = $default;
			}
			$out[ $key ] = $value;
		}
		$out['schema'] = self::SCHEMA;
		return $out;
	}

	public static function capability(): string {
		/**
		 * مجوز ورود به «مدیریت هدر» و ذخیرهٔ آن.
		 *
		 * @param string $cap
		 */
		return (string) apply_filters( 'tecteb_header_capability', 'manage_options' );
	}

	public static function register(): void {
		register_setting(
			'tecteb_header',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
		add_filter( 'option_page_capability_tecteb_header', array( self::class, 'capability' ) );
	}

	/**
	 * ورودی فرم یا update_option ⇒ شکل ذخیره‌شده. هر چیزی نامعتبر کنار می‌رود و دلیلش
	 * به‌صورت پیام تنظیمات گزارش می‌شود.
	 *
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		// options.php ورودی را پیش از update_option خودش unslash می‌کند؛ اینجا دوباره نه.
		// هیچ میان‌بری برای «از قبل پاک است» نیست: هر فراخوانی کامل اعتبارسنجی می‌شود
		// (sanitize_option روی اولین add_option دو بار صدا زده می‌شود و این بی‌خطر است).
		$input = is_array( $input ) ? $input : array();
		$current = self::get();
		$errors  = array();

		// هر زبانهٔ داشبورد فرم جدای خودش را دارد و فقط فیلدهای همان زبانه را می‌فرستد؛ بقیهٔ
		// تنظیمات همان که بود می‌ماند. چک‌باکس ِ نفرستاده در فرم همان زبانه یعنی «خاموش».
		// فراخوانی از کد (update_option) بی `_tab`: هر کلیدی که آمده اعتبارسنجی می‌شود و
		// کلید غایب دست نمی‌خورد.
		$tab    = is_string( $input['_tab'] ?? null ) ? $input['_tab'] : '';
		$form   = isset( self::GROUPS[ $tab ] );
		$fields = $form ? self::GROUPS[ $tab ] : array_keys( array_diff_key( self::defaults(), array( 'schema' => 0 ) ) );
		$given  = static fn( string $k ): bool => $form || array_key_exists( $k, $input );
		$flag   = static fn( string $k ): bool => $form ? ! empty( $input[ $k ] ) : (bool) $input[ $k ];

		$out = $current;
		foreach ( $fields as $k ) {
			if ( 'categories' === $k ) {
				if ( 'import' === $tab ) {
					$out['categories'] = self::sanitize_categories( $input, $current, $errors ) ?? $current['categories'];
				} elseif ( $given( 'categories' ) || isset( $input['add_main'] ) ) {
					$out['categories'] = self::sanitize_categories( $input, $current, $errors ) ?? $current['categories'];
				}
				continue;
			}
			if ( ! $given( $k ) ) {
				continue;
			}
			switch ( $k ) {
				case 'hide_empty':
				case 'cart_fragments':
				case 'home_search_toggle':
				case 'assets_everywhere':
				case 'replace':
					$out[ $k ] = $flag( $k );
					break;
				case 'nav_menu':
					$out[ $k ] = self::menu_id( $input[ $k ] ?? 0, $errors );
					break;
				case 'replace_templates':
					$out[ $k ] = array();
					foreach ( self::ids( $input[ $k ] ?? array() ) as $tid ) {
						if ( Elementor::is_header_template( $tid ) ) {
							$out[ $k ][] = $tid;
						} else {
							/* translators: %d: post id */
							$errors[] = array( 'tpl-' . $tid, sprintf( __( 'شناسهٔ %d قالب هدر Elementor نیست و پذیرفته نشد.', 'tecteb-header' ), $tid ) );
						}
					}
					break;
				case 'nav_hidden':
					$out[ $k ] = self::ids( $input[ $k ] ?? array() );
					break;
				case 'supplier_url':
				case 'wishlist_url':
					$out[ $k ] = self::url( $input[ $k ] ?? '', $k, $errors );
					break;
				case 'logo_id':
					$out[ $k ] = self::image_id( $input[ $k ] ?? 0, 'logo', $errors );
					break;
				case 'logo_text':
					$out[ $k ] = mb_substr( sanitize_text_field( is_scalar( $input[ $k ] ?? '' ) ? (string) ( $input[ $k ] ?? '' ) : '' ), 0, 40 );
					break;
				case 'search_mode':
					$out[ $k ] = in_array( $input[ $k ] ?? '', array( 'woocommerce', 'shortcode' ), true ) ? $input[ $k ] : 'woocommerce';
					break;
				case 'search_shortcode':
					$out[ $k ] = self::shortcode( $input[ $k ] ?? '', $errors );
					break;
				case 'wishlist_mode':
					$out[ $k ] = in_array( $input[ $k ] ?? '', array( 'off', 'link' ), true ) ? $input[ $k ] : 'off';
					break;
				case 'font':
					$out[ $k ] = in_array( $input[ $k ] ?? '', array( 'plugin', 'inherit' ), true ) ? $input[ $k ] : 'plugin';
					break;
			}
		}
		$out           = self::shape( $out );
		$out['schema'] = self::SCHEMA;

		if ( $out['replace'] && array() === $out['replace_templates'] ) {
			$out['replace'] = false;
			$errors[]       = array( 'replace', __( 'جایگزینی روشن نشد: نخست قالب هدر Elementor ِ جایگزین‌شونده (امروز «Header»، ۲۶۵۲) را انتخاب کنید.', 'tecteb-header' ) );
		}
		if ( 'shortcode' === $out['search_mode'] && '' === $out['search_shortcode'] ) {
			$out['search_mode'] = 'woocommerce';
			$errors[]           = array( 'search', __( 'برای جست‌وجوی سفارشی shortcode وارد نشده بود؛ جست‌وجوی استاندارد ووکامرس نگه داشته شد.', 'tecteb-header' ) );
		}
		if ( 'link' === $out['wishlist_mode'] && '' === $out['wishlist_url'] ) {
			$out['wishlist_mode'] = 'off';
			$errors[]             = array( 'wishlist', __( 'برای علاقه‌مندی‌ها نشانی وارد نشده بود؛ نماد علاقه‌مندی نمایش داده نمی‌شود.', 'tecteb-header' ) );
		}

		foreach ( $errors as $e ) {
			if ( ! isset( self::$reported[ $e[0] . $e[1] ] ) && function_exists( 'add_settings_error' ) ) {
				self::$reported[ $e[0] . $e[1] ] = true;
				add_settings_error( self::OPTION, 'tth-' . $e[0], $e[1], 'warning' );
			}
		}
		self::forget();
		return $out;
	}

	/**
	 * @param array<string,mixed>       $input
	 * @param array<string,mixed>       $current
	 * @param list<array{string,string}> $errors
	 * @return list<array<string,mixed>>|null  null ⇒ چیزی برای اعمال نبود (فهرست دست نمی‌خورد).
	 */
	private static function sanitize_categories( array $input, array $current, array &$errors ): ?array {
		$rows = array();

		// ورود از فهرست وردپرس یا از نام‌های مبنا: کل فهرست را با همان ترتیب جایگزین می‌کند،
		// ولی آیکون و زیردسته‌های دسته‌ای که از قبل تنظیم شده نگه داشته می‌شود.
		$replace_with = null;
		if ( ! empty( $input['import_apply'] ) ) {
			$replace_with = self::ids( $input['import'] ?? array() );
		} elseif ( ! empty( $input['baseline_apply'] ) ) {
			$replace_with = self::ids( $input['baseline'] ?? array() );
		}
		if ( null === $replace_with && 'import' === ( $input['_tab'] ?? '' ) ) {
			return null;
		}
		if ( null !== $replace_with ) {
			$known = array();
			foreach ( $current['categories'] as $c ) {
				$known[ (int) $c['term_id'] ] = $c;
			}
			foreach ( $replace_with as $i => $id ) {
				$rows[] = $known[ $id ] ?? array(
					'term_id'  => $id,
					'enabled'  => true,
					'icon_id'  => 0,
					'children' => array(),
					'order'    => $i,
				);
			}
		} else {
			foreach ( array_values( (array) ( $input['categories'] ?? array() ) ) as $i => $c ) {
				if ( ! is_array( $c ) || ! empty( $c['remove'] ) ) {
					continue;
				}
				$c['order'] = isset( $c['order'] ) && is_numeric( $c['order'] ) ? (float) $c['order'] : (float) $i;
				$c['index'] = $i;
				$rows[]     = $c;
			}
			usort(
				$rows,
				static fn( $a, $b ) => array( $a['order'], $a['index'] ) <=> array( $b['order'], $b['index'] )
			);
			foreach ( self::ids( $input['add_main'] ?? array() ) as $id ) {
				$rows[] = array(
					'term_id'  => $id,
					'enabled'  => true,
					'icon_id'  => 0,
					'children' => array(),
				);
			}
		}

		$default = (int) get_option( 'default_product_cat', 0 );
		$seen    = array();
		$out     = array();
		foreach ( $rows as $c ) {
			$id   = absint( $c['term_id'] ?? 0 );
			$term = $id ? get_term( $id, 'product_cat' ) : null;
			if ( ! $term instanceof \WP_Term ) {
				$errors[] = array( 'cat-missing', sprintf( /* translators: %d: term id */ __( 'دستهٔ %d در ووکامرس وجود ندارد و کنار گذاشته شد.', 'tecteb-header' ), $id ) );
				continue;
			}
			if ( $id === $default ) {
				$errors[] = array( 'cat-default', __( 'دستهٔ پیش‌فرض ووکامرس («دسته‌بندی نشده») به هدر افزوده نمی‌شود.', 'tecteb-header' ) );
				continue;
			}
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			if ( count( $out ) >= self::MAX_MAINS ) {
				/* translators: %d: limit */
				$errors[] = array( 'cat-max', sprintf( __( 'حداکثر %d دستهٔ اصلی؛ بقیه کنار گذاشته شد.', 'tecteb-header' ), self::MAX_MAINS ) );
				break;
			}
			$seen[ $id ] = true;

			$children = array();
			$kseen    = array();
			$kids     = array_values( (array) ( $c['children'] ?? array() ) );
			foreach ( self::ids( $c['add_child'] ?? array() ) as $kid ) {
				$kids[] = array(
					'term_id' => $kid,
					'enabled' => true,
				);
			}
			foreach ( $kids as $k ) {
				if ( ! is_array( $k ) || ! empty( $k['remove'] ) ) {
					continue;
				}
				$kid = absint( $k['term_id'] ?? 0 );
				if ( ! $kid || isset( $kseen[ $kid ] ) ) {
					continue;
				}
				$kterm = get_term( $kid, 'product_cat' );
				if ( ! $kterm instanceof \WP_Term || ! term_is_ancestor_of( $id, $kid, 'product_cat' ) ) {
					/* translators: 1: child id, 2: main category name */
					$errors[] = array( 'child-' . $kid, sprintf( __( 'دستهٔ %1$d زیرمجموعهٔ «%2$s» نیست و کنار گذاشته شد.', 'tecteb-header' ), $kid, $term->name ) );
					continue;
				}
				if ( count( $children ) >= self::MAX_CHILDREN ) {
					break;
				}
				$kseen[ $kid ] = true;
				$children[]    = array(
					'term_id' => $kid,
					'enabled' => ! empty( $k['enabled'] ),
				);
			}

			$out[] = array(
				'term_id'  => $id,
				'enabled'  => ! empty( $c['enabled'] ),
				'icon_id'  => self::image_id( $c['icon_id'] ?? 0, 'icon-' . $id, $errors ),
				'children' => $children,
			);
		}
		return $out;
	}

	/**
	 * @param mixed $value
	 * @return list<int>
	 */
	public static function ids( $value ): array {
		$out = array();
		foreach ( (array) $value as $v ) {
			$id = is_scalar( $v ) ? absint( $v ) : 0;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/** پیوستی که تصویر PNG یا WebP باشد؛ هر چیز دیگری (از جمله SVG) ⇒ ۰. */
	public static function is_icon( int $id ): bool {
		return $id > 0
			&& 'attachment' === get_post_type( $id )
			&& in_array( (string) get_post_mime_type( $id ), self::ICON_MIMES, true );
	}

	/**
	 * @param mixed                      $value
	 * @param list<array{string,string}> $errors
	 */
	private static function image_id( $value, string $what, array &$errors ): int {
		$id = is_scalar( $value ) ? absint( $value ) : 0;
		if ( 0 === $id ) {
			return 0;
		}
		if ( ! self::is_icon( $id ) ) {
			$errors[] = array( $what, __( 'تصویر انتخاب‌شده PNG یا WebP نبود و ذخیره نشد.', 'tecteb-header' ) );
			return 0;
		}
		return $id;
	}

	/**
	 * @param mixed                      $value
	 * @param list<array{string,string}> $errors
	 */
	private static function menu_id( $value, array &$errors ): int {
		$id = is_scalar( $value ) ? absint( $value ) : 0;
		if ( $id && ! wp_get_nav_menu_object( $id ) ) {
			$errors[] = array( 'nav-menu', __( 'فهرست وردپرس انتخاب‌شده وجود ندارد.', 'tecteb-header' ) );
			return 0;
		}
		return $id;
	}

	/**
	 * فقط مسیر نسبی (/…) یا نشانی http(s). خالی ⇒ خالی.
	 *
	 * @param mixed                      $value
	 * @param list<array{string,string}> $errors
	 */
	public static function url( $value, string $what = '', ?array &$errors = null ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '#^/(?!/)[^\s<>"\']*$#u', $value ) ) {
			return $value;
		}
		$clean = esc_url_raw( $value, array( 'http', 'https' ) );
		if ( '' !== $clean && preg_match( '#^https?://#i', $clean ) ) {
			return $clean;
		}
		if ( null !== $errors ) {
			$errors[] = array( $what, __( 'نشانی پذیرفته نشد؛ فقط مسیر نسبی (/…) یا http(s).', 'tecteb-header' ) );
		}
		return '';
	}

	/**
	 * فقط یک shortcode ِ تنها، مثل [name attr="x"] — نه HTML و نه متن آزاد.
	 *
	 * @param mixed                      $value
	 * @param list<array{string,string}> $errors
	 */
	private static function shortcode( $value, array &$errors ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^\[[a-zA-Z0-9_\-]+(?:\s[^\[\]<>]*)?\]$/', $value ) ) {
			return $value;
		}
		$errors[] = array( 'search-shortcode', __( 'shortcode ِ جست‌وجو پذیرفته نشد؛ فقط یک shortcode مثل [name] مجاز است.', 'tecteb-header' ) );
		return '';
	}

	/** برای مقایسهٔ نام فارسی: ي/ك عربی، نیم‌فاصله و فاصله‌های تکراری یکی می‌شوند. */
	public static function normalize_name( string $name ): string {
		$name = str_replace( array( 'ي', 'ك', "\u{200C}", "\u{00A0}" ), array( 'ی', 'ک', ' ', ' ' ), $name );
		return trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
	}
}
