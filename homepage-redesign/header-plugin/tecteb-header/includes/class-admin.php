<?php
/**
 * پیشخوان «تک‌طب ← مدیریت هدر».
 *
 * اگر منوی سطح اولی با عنوان «تک‌طب» از قبل در پیشخوان هست، صفحه زیر همان می‌نشیند و
 * منوی تازه‌ای ساخته نمی‌شود؛ فقط وقتی نیست، یک «تک‌طب» با همین یک زیرمنو ساخته می‌شود.
 *
 * ذخیره از راه Settings API (options.php): nonce ِ `tecteb_header-options` و مجوز
 * Settings::capability() را خودِ وردپرس می‌سنجد. جست‌وجوی دسته و انتخاب فهرست وردپرس
 * GET اند و چیزی ذخیره نمی‌کنند. صفحه بی JavaScript هم کامل کار می‌کند (ترتیب با عدد،
 * آیکون با شناسهٔ پیوست)؛ JavaScript فقط کتابخانهٔ رسانه و جابه‌جایی را راحت‌تر می‌کند.
 *
 * @package Tecteb\Header
 */

namespace Tecteb\Header;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const SLUG = 'tecteb-header';
	public const HUB  = 'tecteb';

	private const TABS = array(
		'cats'    => 'دسته‌بندی‌ها',
		'import'  => 'انتقال از منوی فعلی',
		'links'   => 'تنظیمات',
		'replace' => 'جایگزینی هدر',
		'data'    => 'دادهٔ ذخیره‌شده',
	);

	/** @var list<string> */
	private static array $hooks = array();

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 99 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/** نامک منوی «تک‌طب» ِ موجود، یا '' اگر نیست. */
	public static function parent_slug(): string {
		global $menu;
		$found = '';
		foreach ( (array) $menu as $item ) {
			if ( ! is_array( $item ) || ! isset( $item[0], $item[2] ) ) {
				continue;
			}
			$title = html_entity_decode( wp_strip_all_tags( (string) preg_replace( '/<span.*$/su', '', (string) $item[0] ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$title = str_replace( array( ' ', "\u{200C}", "\u{00A0}" ), '', Settings::normalize_name( $title ) );
			if ( 'تکطب' === $title || 'tecteb' === strtolower( $title ) ) {
				$found = (string) $item[2];
				break;
			}
		}
		/**
		 * نامک منوی والد «مدیریت هدر». '' ⇒ افزونه منوی «تک‌طب» را خودش می‌سازد.
		 *
		 * @param string $slug
		 */
		return (string) apply_filters( 'tecteb_header_parent_slug', $found );
	}

	public static function menu(): void {
		$cap    = Settings::capability();
		$title  = __( 'مدیریت هدر', 'tecteb-header' );
		$parent = self::parent_slug();
		$own    = '' === $parent;
		if ( $own ) {
			// منوی «تک‌طب» با نامک جدا (`tecteb`)، تا وردپرس تنها زیرمنو را جمع نکند (زیرمنوی تنهایی که
			// نامکش با والد یکی باشد پنهان می‌شود). مورد خودکار «تک‌طب» از زیرمنو برداشته می‌شود و
			// پیوند منوی والد به اولین زیرمنو — «مدیریت هدر» — می‌رود.
			$parent       = self::HUB;
			self::$hooks[] = (string) add_menu_page( __( 'تک‌طب', 'tecteb-header' ), __( 'تک‌طب', 'tecteb-header' ), $cap, self::HUB, array( self::class, 'render' ), 'dashicons-heart', 58 );
		}
		self::$hooks[] = (string) add_submenu_page( $parent, $title, $title, $cap, self::SLUG, array( self::class, 'render' ) );
		if ( $own ) {
			remove_submenu_page( self::HUB, self::HUB );
		}
	}

	/** @param string $hook */
	public static function assets( $hook ): void {
		if ( ! in_array( (string) $hook, self::$hooks, true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'tecteb-header-admin', TECTEB_HEADER_URL . 'assets/css/admin.css', array(), TECTEB_HEADER_VERSION );
		wp_enqueue_script( 'tecteb-header-admin', TECTEB_HEADER_URL . 'assets/js/admin.js', array( 'media-editor' ), TECTEB_HEADER_VERSION, true );
		wp_localize_script(
			'tecteb-header-admin',
			'TTHAdminL10n',
			array(
				'mimes'  => Settings::ICON_MIMES,
				'title'  => __( 'انتخاب آیکون (PNG یا WebP)', 'tecteb-header' ),
				'button' => __( 'انتخاب', 'tecteb-header' ),
				'badMime'=> __( 'فقط PNG یا WebP پذیرفته می‌شود.', 'tecteb-header' ),
			)
		);
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/** @return string */
	private static function tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- فقط انتخاب زبانه برای نمایش.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'cats';
		return isset( self::TABS[ $tab ] ) ? $tab : 'cats';
	}

	public static function render(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'اجازهٔ دسترسی به این صفحه را ندارید.', 'tecteb-header' ), 403 );
		}
		Settings::forget();
		$s   = Settings::get();
		$tab = self::tab();
		echo '<div class="wrap tth-hdr">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'مدیریت هدر', 'tecteb-header' ) . '</h1>';
		echo '<span class="tth-version">' . esc_html( sprintf( /* translators: %s: version */ __( 'نسخهٔ %s', 'tecteb-header' ), TECTEB_HEADER_VERSION ) ) . '</span>';
		echo '<hr class="wp-header-end">';
		settings_errors();
		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'بخش‌های مدیریت هدر', 'tecteb-header' ) . '">';
		foreach ( self::TABS as $key => $label ) {
			$current = $key === $tab;
			printf(
				'<a class="nav-tab%1$s" href="%2$s"%3$s>%4$s</a>',
				$current ? ' nav-tab-active' : '',
				esc_url( self::url( array( 'tab' => $key ) ) ),
				$current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav><div class="tth-tab">';
		match ( $tab ) {
			'import'  => self::tab_import( $s ),
			'links'   => self::tab_links( $s ),
			'replace' => self::tab_replace( $s ),
			'data'    => self::tab_data( $s ),
			default   => self::tab_cats( $s ),
		};
		echo '</div></div>';
	}

	private static function form_open( string $tab ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( 'tecteb_header' );
		echo '<input type="hidden" name="' . esc_attr( Settings::OPTION ) . '[_tab]" value="' . esc_attr( $tab ) . '">';
	}

	private static function name( string ...$path ): string {
		return Settings::OPTION . '[' . implode( '][', $path ) . ']';
	}

	/** مسیر کامل یک دسته: «مادر › فرزند». */
	private static function path( \WP_Term $t ): string {
		$names = array();
		foreach ( array_reverse( get_ancestors( (int) $t->term_id, 'product_cat', 'taxonomy' ) ) as $a ) {
			$at = get_term( (int) $a, 'product_cat' );
			if ( $at instanceof \WP_Term ) {
				$names[] = $at->name;
			}
		}
		$names[] = $t->name;
		return implode( ' › ', $names );
	}

	private static function count_pill( \WP_Term $t ): string {
		/* translators: %s: number of products */
		return '<span class="tth-pill" title="' . esc_attr__( 'شمار محصولات (با زیردسته‌ها)', 'tecteb-header' ) . '">' . esc_html( number_format_i18n( Menu::count( $t ) ) ) . '</span>';
	}

	private static function icon_preview( int $id ): string {
		$url = Menu::icon_url( $id );
		return '' !== $url
			? '<img src="' . esc_url( $url ) . '" alt="">'
			: '<span class="tth-icon__none">' . esc_html__( 'بی آیکون', 'tecteb-header' ) . '</span>';
	}

	// ------------------------------------------------------------------ دسته‌ها

	/** @param array<string,mixed> $s */
	private static function tab_cats( array $s ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- جست‌وجوی فقط‌خواندنی.
		$find = isset( $_GET['tth_find'] ) ? sanitize_text_field( wp_unslash( $_GET['tth_find'] ) ) : '';

		echo '<p class="description">' . wp_kses_post( __( 'ترتیب فهرست همان ترتیب هدر است. «در هدر» را خاموش کنید تا دسته بی حذف‌شدن پنهان شود. عنوان و نشانی هر دسته از ووکامرس خوانده می‌شود؛ اینجا فقط <strong>شناسه</strong> ذخیره می‌شود.', 'tecteb-header' ) ) . '</p>';
		if ( array() === $s['categories'] ) {
			echo '<div class="notice notice-info inline"><p>' . wp_kses_post(
				sprintf(
					/* translators: %s: link to import tab */
					__( 'هنوز هیچ دسته‌ای برای هدر انتخاب نشده و هدر فقط پیوند «دسته‌بندی کالاها» به فروشگاه را نشان می‌دهد. برای شروع، سیزده دستهٔ فعلی را با شناسهٔ واقعی‌شان از <a href="%s">انتقال از منوی فعلی</a> وارد کنید، یا از جست‌وجوی زیر بیفزایید.', 'tecteb-header' ),
					esc_url( self::url( array( 'tab' => 'import' ) ) )
				)
			) . '</p></div>';
		}

		// جست‌وجو (GET، جدا از فرم ذخیره — فرم تودرتو مجاز نیست).
		echo '<form method="get" class="tth-find" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="cats">';
		echo '<label for="tth-find">' . esc_html__( 'افزودن دستهٔ اصلی — جست‌وجو در دسته‌های محصول:', 'tecteb-header' ) . '</label>';
		echo '<input type="search" id="tth-find" name="tth_find" value="' . esc_attr( $find ) . '" placeholder="' . esc_attr__( 'نام دسته…', 'tecteb-header' ) . '">';
		echo '<button class="button">' . esc_html__( 'جست‌وجو', 'tecteb-header' ) . '</button></form>';

		self::form_open( 'categories' );
		$chosen = array_map( static fn( $c ) => (int) $c['term_id'], $s['categories'] );

		if ( '' !== $find ) {
			$found = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'search'     => $find,
					'number'     => 40,
					'exclude'    => array_merge( $chosen, array( (int) get_option( 'default_product_cat', 0 ) ) ),
				)
			);
			echo '<fieldset class="tth-card"><legend><strong>' . esc_html( sprintf( /* translators: %s: query */ __( 'نتیجهٔ جست‌وجوی «%s»', 'tecteb-header' ), $find ) ) . '</strong></legend>';
			if ( ! is_array( $found ) || array() === $found ) {
				echo '<p>' . esc_html__( 'دسته‌ای پیدا نشد.', 'tecteb-header' ) . '</p>';
			} else {
				echo '<ul class="tth-pick">';
				foreach ( $found as $t ) {
					printf(
						'<li><label><input type="checkbox" name="%1$s[]" value="%2$d"> %3$s <small>#%2$d · %4$s</small></label></li>',
						esc_attr( self::name( 'add_main' ) ),
						(int) $t->term_id,
						esc_html( self::path( $t ) ),
						/* translators: %s: count */
						esc_html( sprintf( __( '%s محصول', 'tecteb-header' ), number_format_i18n( Menu::count( $t ) ) ) )
					);
				}
				echo '</ul><p class="description">' . esc_html__( 'دسته‌های تیک‌خورده با «ذخیرهٔ تغییرات» به انتهای فهرست افزوده می‌شوند (حداکثر ۱۶).', 'tecteb-header' ) . '</p>';
			}
			echo '</fieldset>';
		}

		echo '<div class="tth-layout"><div class="tth-editor">';
		echo '<ol class="tth-cards" aria-label="' . esc_attr__( 'دسته‌های اصلی هدر', 'tecteb-header' ) . '">';
		foreach ( $s['categories'] as $i => $c ) {
			self::card( $i, $c, $s, count( $s['categories'] ) );
		}
		echo '</ol>';

		echo '<p><label><input type="checkbox" name="' . esc_attr( self::name( 'hide_empty' ) ) . '" value="1"' . checked( $s['hide_empty'], true, false ) . '> ' . esc_html__( 'دسته و زیردسته‌ای که هیچ محصولی ندارد در هدر نمایش داده نشود', 'tecteb-header' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'پیش‌فرض: خاموش، تا هیچ‌کدام از دسته‌های فعلی ناخواسته ناپدید نشود. انتخاب حذف نمی‌شود؛ دسته با گرفتن محصول دوباره دیده می‌شود.', 'tecteb-header' ) . '</p>';
		echo '<div class="tth-form-foot">';
		submit_button( __( 'ذخیرهٔ تغییرات', 'tecteb-header' ), 'primary', 'submit', false );
		/* translators: %d: count */
		echo '<span class="description">' . esc_html( sprintf( __( '%1$s دستهٔ اصلی از حداکثر %2$s', 'tecteb-header' ), number_format_i18n( count( $s['categories'] ) ), number_format_i18n( Settings::MAX_MAINS ) ) ) . '</span>';
		echo '</div></div>';

		self::preview_box();
		echo '</div></form>';
	}

	/**
	 * @param array<string,mixed> $c
	 * @param array<string,mixed> $s
	 */
	private static function card( int $i, array $c, array $s, int $total ): void {
		$id   = (int) $c['term_id'];
		$term = get_term( $id, 'product_cat' );
		$pfx  = static fn( string ...$p ): string => self::name( 'categories', (string) $i, ...$p );
		$off  = empty( $c['enabled'] );
		echo '<li class="tth-card' . ( $off ? ' is-off' : '' ) . '" data-tth-card>';
		echo '<input type="hidden" name="' . esc_attr( $pfx( 'term_id' ) ) . '" value="' . esc_attr( (string) $id ) . '">';
		echo '<div class="tth-card__row">';

		echo '<span class="tth-order">';
		echo '<label class="screen-reader-text" for="tth-o-' . esc_attr( (string) $i ) . '">' . esc_html__( 'ترتیب', 'tecteb-header' ) . '</label>';
		echo '<input type="number" class="small-text" id="tth-o-' . esc_attr( (string) $i ) . '" name="' . esc_attr( $pfx( 'order' ) ) . '" value="' . esc_attr( (string) ( $i + 1 ) ) . '" min="1" step="1" data-tth-order>';
		echo '<button type="button" class="button-link" data-tth-move="up" hidden aria-label="' . esc_attr__( 'بالاتر', 'tecteb-header' ) . '"' . disabled( 0 === $i, true, false ) . '><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>';
		echo '<button type="button" class="button-link" data-tth-move="down" hidden aria-label="' . esc_attr__( 'پایین‌تر', 'tecteb-header' ) . '"' . disabled( $total - 1 === $i, true, false ) . '><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>';
		echo '</span>';

		$icon_field = 'tth-icon-' . $i;
		echo '<span class="tth-icon-field">';
		echo '<span class="tth-icon" data-tth-preview="' . esc_attr( $icon_field ) . '">' . self::icon_preview( (int) $c['icon_id'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escape‌شده در icon_preview.
		echo '<label class="screen-reader-text" for="' . esc_attr( $icon_field ) . '">' . esc_html__( 'شناسهٔ پیوست آیکون (PNG یا WebP)', 'tecteb-header' ) . '</label>';
		echo '<input type="number" min="0" step="1" id="' . esc_attr( $icon_field ) . '" name="' . esc_attr( $pfx( 'icon_id' ) ) . '" value="' . esc_attr( (string) (int) $c['icon_id'] ) . '" data-tth-nojs>';
		echo '<button type="button" class="button button-small" data-tth-media="' . esc_attr( $icon_field ) . '" hidden>' . esc_html__( 'انتخاب آیکون', 'tecteb-header' ) . '</button>';
		echo '<button type="button" class="button-link" data-tth-media-clear="' . esc_attr( $icon_field ) . '" hidden>' . esc_html__( 'بی آیکون', 'tecteb-header' ) . '</button>';
		echo '</span>';

		echo '<span class="tth-card__title">';
		if ( $term instanceof \WP_Term ) {
			echo '<strong>' . esc_html( $term->name ) . '</strong>';
			echo '<small>#' . esc_html( (string) $id ) . ' · ' . esc_html( self::path( $term ) ) . ' · ' . self::count_pill( $term ) . '</small>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escape‌شده در count_pill.
			if ( $s['hide_empty'] && Menu::count( $term ) < 1 ) {
				echo '<span class="tth-issue tth-issue--warning">' . esc_html__( 'بی محصول — تا وقتی «پنهان‌کردن دستهٔ بی‌محصول» روشن است دیده نمی‌شود.', 'tecteb-header' ) . '</span>';
			}
		} else {
			echo '<strong>#' . esc_html( (string) $id ) . '</strong>';
			echo '<span class="tth-issue tth-issue--error">' . esc_html__( 'این دسته در ووکامرس دیگر وجود ندارد؛ در هدر نمایش داده نمی‌شود و با ذخیره کنار می‌رود.', 'tecteb-header' ) . '</span>';
		}
		echo '</span>';

		echo '<label class="tth-switch"><input type="checkbox" name="' . esc_attr( $pfx( 'enabled' ) ) . '" value="1"' . checked( ! $off, true, false ) . '> ' . esc_html__( 'در هدر', 'tecteb-header' ) . '</label>';
		echo '<label><input type="checkbox" name="' . esc_attr( $pfx( 'remove' ) ) . '" value="1"> ' . esc_html__( 'حذف از هدر', 'tecteb-header' ) . '</label>';
		echo '</div>';

		// زیردسته‌ها
		echo '<div class="tth-kids"><strong>' . esc_html__( 'زیردسته‌های منتخب', 'tecteb-header' ) . '</strong>';
		if ( array() !== $c['children'] ) {
			echo '<ul class="tth-kids__list">';
			foreach ( $c['children'] as $j => $k ) {
				$kid  = (int) $k['term_id'];
				$kt   = get_term( $kid, 'product_cat' );
				$kpfx = static fn( string $p ): string => $pfx( 'children', (string) $j, $p );
				echo '<li class="tth-kid' . ( empty( $k['enabled'] ) ? ' is-off' : '' ) . '">';
				echo '<input type="hidden" name="' . esc_attr( $kpfx( 'term_id' ) ) . '" value="' . esc_attr( (string) $kid ) . '">';
				echo '<span class="tth-kid__name">';
				if ( $kt instanceof \WP_Term ) {
					echo '<strong>' . esc_html( $kt->name ) . '</strong><small>#' . esc_html( (string) $kid ) . ' · ' . esc_html( self::path( $kt ) ) . '</small>';
					if ( ! term_is_ancestor_of( $id, $kid, 'product_cat' ) ) {
						echo '<span class="tth-issue tth-issue--error">' . esc_html__( 'دیگر زیرمجموعهٔ این دسته نیست؛ نمایش داده نمی‌شود و با ذخیره کنار می‌رود.', 'tecteb-header' ) . '</span>';
					}
				} else {
					echo '<strong>#' . esc_html( (string) $kid ) . '</strong><span class="tth-issue tth-issue--error">' . esc_html__( 'در ووکامرس وجود ندارد.', 'tecteb-header' ) . '</span>';
				}
				echo '</span>';
				echo '<label class="tth-switch"><input type="checkbox" name="' . esc_attr( $kpfx( 'enabled' ) ) . '" value="1"' . checked( ! empty( $k['enabled'] ), true, false ) . '> ' . esc_html__( 'در هدر', 'tecteb-header' ) . '</label>';
				echo '<label><input type="checkbox" name="' . esc_attr( $kpfx( 'remove' ) ) . '" value="1"> ' . esc_html__( 'حذف', 'tecteb-header' ) . '</label>';
				echo '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="description">' . esc_html__( 'بدون زیردسته؛ در هدر فقط «همهٔ محصولات …» نمایش داده می‌شود.', 'tecteb-header' ) . '</p>';
		}
		if ( $term instanceof \WP_Term && count( $c['children'] ) < Settings::MAX_CHILDREN ) {
			$have = array_map( static fn( $k ) => (int) $k['term_id'], $c['children'] );
			$desc = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'child_of'   => $id,
					'hide_empty' => false,
					'exclude'    => $have,
					'number'     => 300,
					'orderby'    => 'name',
				)
			);
			if ( is_array( $desc ) && array() !== $desc ) {
				$sel = 'tth-add-' . $i;
				echo '<p><label for="' . esc_attr( $sel ) . '">' . esc_html__( 'افزودن زیردسته (فقط زیرمجموعه‌های همین دسته):', 'tecteb-header' ) . '</label> ';
				echo '<select id="' . esc_attr( $sel ) . '" name="' . esc_attr( $pfx( 'add_child' ) ) . '[]"><option value="">' . esc_html__( '— انتخاب —', 'tecteb-header' ) . '</option>';
				foreach ( $desc as $d ) {
					echo '<option value="' . esc_attr( (string) (int) $d->term_id ) . '">' . esc_html( self::path( $d ) . ' (#' . (int) $d->term_id . ')' ) . '</option>';
				}
				echo '</select></p>';
			}
		}
		echo '</div></li>';
	}

	private static function preview_box(): void {
		$preview  = add_query_arg( 'tth_preview', '1', home_url( '/' ) );
		echo '<aside class="tth-preview" aria-labelledby="tth-preview-title">';
		echo '<div class="tth-preview__head"><h2 id="tth-preview-title">' . esc_html__( 'پیش‌نمایش', 'tecteb-header' ) . '</h2>';
		echo '<div class="tth-seg" role="group" aria-label="' . esc_attr__( 'اندازهٔ پیش‌نمایش', 'tecteb-header' ) . '" hidden data-tth-js>';
		echo '<button type="button" class="button" aria-pressed="true" data-tth-view="desktop">' . esc_html__( 'دسکتاپ', 'tecteb-header' ) . '</button>';
		echo '<button type="button" class="button" aria-pressed="false" data-tth-view="mobile">' . esc_html__( 'موبایل', 'tecteb-header' ) . '</button>';
		echo '</div></div>';
		echo '<p class="description">' . esc_html__( 'همان هدر واقعی با تنظیمات ذخیره‌شده، روی صفحهٔ اصلی سایت و فقط برای شما. تغییرهای ذخیره‌نشده پس از ذخیره دیده می‌شوند.', 'tecteb-header' ) . '</p>';
		echo '<div class="tth-preview__frame" data-tth-frame-wrap data-view="desktop"><iframe title="' . esc_attr__( 'پیش‌نمایش هدر', 'tecteb-header' ) . '" src="' . esc_url( $preview ) . '" loading="lazy"></iframe></div>';
		echo '<p><a href="' . esc_url( $preview ) . '" target="_blank" rel="noopener">' . esc_html__( 'باز کردن پیش‌نمایش در زبانهٔ تازه', 'tecteb-header' ) . '</a></p>';
		echo '</aside>';
	}

	// ------------------------------------------------------------------ انتقال

	/** @param array<string,mixed> $s */
	private static function tab_import( array $s ): void {
		echo '<h2>' . esc_html__( 'انتقال از منوی فعلی', 'tecteb-header' ) . '</h2>';
		echo '<p>' . wp_kses_post( __( 'هر دو روش زیر <strong>فقط می‌خوانند</strong>: نه فهرست وردپرس، نه قالب Elementor و نه هیچ دسته‌ای تغییر نمی‌کند. تا تیک «جایگزینی» را نزنید و ذخیره نکنید، هیچ شناسه‌ای ذخیره نمی‌شود. آیکون و زیردسته‌های دسته‌ای که از قبل تنظیم شده نگه داشته می‌شود.', 'tecteb-header' ) ) . '</p>';

		// الف: فهرست وردپرس
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- انتخاب فقط‌خواندنی.
		$menu_id = isset( $_GET['tth_menu'] ) ? absint( $_GET['tth_menu'] ) : (int) $s['nav_menu'];
		$menus   = wp_get_nav_menus();
		echo '<h3>' . esc_html__( 'الف) از یک فهرست وردپرس («نمایش ← فهرست‌ها»)', 'tecteb-header' ) . '</h3>';
		echo '<form method="get" class="tth-find" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="import">';
		echo '<label for="tth-menu">' . esc_html__( 'فهرست:', 'tecteb-header' ) . '</label> <select id="tth-menu" name="tth_menu"><option value="0">' . esc_html__( '— انتخاب —', 'tecteb-header' ) . '</option>';
		foreach ( $menus as $m ) {
			echo '<option value="' . esc_attr( (string) (int) $m->term_id ) . '"' . selected( $menu_id, (int) $m->term_id, false ) . '>' . esc_html( $m->name ) . '</option>';
		}
		echo '</select> <button class="button">' . esc_html__( 'خواندن', 'tecteb-header' ) . '</button></form>';

		if ( $menu_id ) {
			$rows = Menu::menu_categories( $menu_id );
			if ( array() === $rows ) {
				echo '<p>' . esc_html__( 'در این فهرست هیچ موردی از نوع «دستهٔ محصول» نیست. اگر دسته‌ها در قالب Elementor یا JetEngine به‌صورت پیوند سفارشی‌اند، از روش «ب» استفاده کنید.', 'tecteb-header' ) . '</p>';
			} else {
				self::form_open( 'import' );
				echo '<table class="widefat striped tth-import"><caption class="screen-reader-text">' . esc_html__( 'دسته‌های فهرست', 'tecteb-header' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'ترتیب', 'tecteb-header' ) . '</th><th scope="col">' . esc_html__( 'دسته', 'tecteb-header' ) . '</th><th scope="col">' . esc_html__( 'شناسه', 'tecteb-header' ) . '</th><th scope="col">' . esc_html__( 'نتیجه', 'tecteb-header' ) . '</th></tr></thead><tbody>';
				$n = 0;
				foreach ( $rows as $r ) {
					++$n;
					echo '<tr><td>' . esc_html( number_format_i18n( $n ) ) . '</td><td>' . esc_html( $r['title'] ) . '</td><td>' . esc_html( (string) $r['term_id'] ) . '</td><td>';
					if ( $r['exists'] ) {
						echo '<input type="hidden" name="' . esc_attr( self::name( 'import' ) ) . '[]" value="' . esc_attr( (string) $r['term_id'] ) . '"><span class="tth-ok">' . esc_html__( 'منتقل می‌شود', 'tecteb-header' ) . '</span>';
					} else {
						echo '<span class="tth-issue tth-issue--error">' . esc_html__( 'دسته در ووکامرس نیست — منتقل نمی‌شود', 'tecteb-header' ) . '</span>';
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';
				echo '<p class="tth-import__go"><label><input type="checkbox" name="' . esc_attr( self::name( 'import_apply' ) ) . '" value="1"> ' . esc_html__( 'فهرست دسته‌های هدر با همین فهرست و همین ترتیب جایگزین شود', 'tecteb-header' ) . '</label> ';
				submit_button( __( 'ذخیره', 'tecteb-header' ), 'secondary', 'submit', false );
				echo '</p></form>';
			}
		}

		// ب: نام‌های مبنا
		echo '<h3>' . esc_html__( 'ب) از نام سیزده دستهٔ فعلی', 'tecteb-header' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'نام‌ها از قالب فعلی هدر سایت (Header، ۲۶۵۲) برداشته شده‌اند؛ شناسهٔ هر کدام از دسته‌های واقعی همین سایت پیدا می‌شود (یکسان‌سازی ي/ك و نیم‌فاصله). جایی که نام پیدا نشود یا مبهم باشد، چیزی پیشنهاد نمی‌شود.', 'tecteb-header' ) . '</p>';
		self::form_open( 'import' );
		echo '<table class="widefat striped tth-baseline"><thead><tr><th scope="col">' . esc_html__( 'ترتیب', 'tecteb-header' ) . '</th><th scope="col">' . esc_html__( 'نام در هدر فعلی', 'tecteb-header' ) . '</th><th scope="col">' . esc_html__( 'دستهٔ پیداشده', 'tecteb-header' ) . '</th><th scope="col">' . esc_html__( 'نتیجه', 'tecteb-header' ) . '</th></tr></thead><tbody>';
		$found = 0;
		foreach ( Menu::baseline_matches() as $n => $m ) {
			echo '<tr><td>' . esc_html( number_format_i18n( $n + 1 ) ) . '</td><td>' . esc_html( $m['name'] ) . '</td><td>';
			if ( 'found' === $m['status'] ) {
				++$found;
				$t = get_term( $m['term_id'], 'product_cat' );
				echo esc_html( ( $t instanceof \WP_Term ? self::path( $t ) : '' ) . ' (#' . $m['term_id'] . ')' );
				echo '<input type="hidden" name="' . esc_attr( self::name( 'baseline' ) ) . '[]" value="' . esc_attr( (string) $m['term_id'] ) . '"></td><td><span class="tth-ok">' . esc_html__( 'پیدا شد', 'tecteb-header' ) . '</span>';
			} elseif ( 'ambiguous' === $m['status'] ) {
				echo esc_html( '#' . implode( '، #', $m['candidates'] ) ) . '</td><td><span class="tth-issue tth-issue--warning">' . esc_html__( 'چند دستهٔ هم‌نام — با جست‌وجوی زبانهٔ «دسته‌بندی‌ها» دستی انتخاب کنید', 'tecteb-header' ) . '</span>';
			} else {
				echo '—</td><td><span class="tth-issue tth-issue--error">' . esc_html__( 'پیدا نشد', 'tecteb-header' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $found > 0 ) {
			/* translators: %s: count */
			echo '<p class="tth-import__go"><label><input type="checkbox" name="' . esc_attr( self::name( 'baseline_apply' ) ) . '" value="1"> ' . esc_html( sprintf( __( 'فهرست دسته‌های هدر با همین %s دستهٔ پیداشده و همین ترتیب جایگزین شود', 'tecteb-header' ), number_format_i18n( $found ) ) ) . '</label> ';
			submit_button( __( 'ذخیره', 'tecteb-header' ), 'secondary', 'submit', false );
			echo '</p>';
		}
		echo '</form>';
	}

	// ------------------------------------------------------------------ تنظیمات

	/** @param array<string,mixed> $s */
	private static function tab_links( array $s ): void {
		self::form_open( 'links' );
		echo '<table class="form-table" role="presentation">';

		// منوی اصلی
		echo '<tr><th scope="row"><label for="tth-nav">' . esc_html__( 'منوی اصلی', 'tecteb-header' ) . '</label></th><td><select id="tth-nav" name="' . esc_attr( self::name( 'nav_menu' ) ) . '"><option value="0">' . esc_html__( '— بدون منو —', 'tecteb-header' ) . '</option>';
		foreach ( wp_get_nav_menus() as $m ) {
			echo '<option value="' . esc_attr( (string) (int) $m->term_id ) . '"' . selected( (int) $s['nav_menu'], (int) $m->term_id, false ) . '>' . esc_html( $m->name ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'پیوندهای ردیف آبی (صفحه اصلی، فروشگاه، وبلاگ، …) از همین فهرست وردپرس خوانده می‌شوند، در دسکتاپ و کشوی موبایل. فقط سطح اول نمایش داده می‌شود.', 'tecteb-header' ) . '</p>';
		if ( $s['nav_menu'] ) {
			$items = wp_get_nav_menu_items( (int) $s['nav_menu'], array( 'update_post_term_cache' => false ) );
			$top   = is_array( $items ) ? array_filter( $items, static fn( $it ) => 0 === (int) $it->menu_item_parent ) : array();
			if ( array() !== $top ) {
				echo '<fieldset><legend>' . esc_html__( 'پنهان در هدر (مثلاً موردی که خودش «دسته‌بندی کالاها» است):', 'tecteb-header' ) . '</legend><ul class="tth-pick">';
				foreach ( $top as $it ) {
					echo '<li><label><input type="checkbox" name="' . esc_attr( self::name( 'nav_hidden' ) ) . '[]" value="' . esc_attr( (string) (int) $it->ID ) . '"' . checked( in_array( (int) $it->ID, array_map( 'intval', $s['nav_hidden'] ), true ), true, false ) . '> ' . esc_html( wp_strip_all_tags( (string) $it->title ) ) . ' <small dir="ltr">' . esc_html( (string) $it->url ) . '</small></label></li>';
				}
				echo '</ul></fieldset>';
			}
		}
		echo '</td></tr>';

		// همکاری
		echo '<tr><th scope="row"><label for="tth-supplier">' . esc_html__( 'پیوند «همکاری تأمین‌کنندگان»', 'tecteb-header' ) . '</label></th><td><input type="text" id="tth-supplier" class="regular-text code" dir="ltr" name="' . esc_attr( self::name( 'supplier_url' ) ) . '" value="' . esc_attr( $s['supplier_url'] ) . '"><p class="description">' . wp_kses_post( __( 'مسیر نسبی (مثل <code>/landing-seller/</code>) یا نشانی کامل http(s). خالی ⇒ دکمه نمایش داده نمی‌شود.', 'tecteb-header' ) ) . '</p></td></tr>';

		// لوگو
		echo '<tr><th scope="row">' . esc_html__( 'لوگو', 'tecteb-header' ) . '</th><td><span class="tth-icon-field"><span class="tth-icon" data-tth-preview="tth-logo">' . self::icon_preview( (int) $s['logo_id'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escape‌شده در icon_preview.
		echo '<label for="tth-logo">' . esc_html__( 'شناسهٔ پیوست (PNG یا WebP؛ ۰ ⇒ نشان پیش‌فرض تک‌طب)', 'tecteb-header' ) . '</label><input type="number" min="0" id="tth-logo" name="' . esc_attr( self::name( 'logo_id' ) ) . '" value="' . esc_attr( (string) (int) $s['logo_id'] ) . '" data-tth-nojs>';
		echo '<button type="button" class="button button-small" data-tth-media="tth-logo" hidden>' . esc_html__( 'انتخاب از کتابخانهٔ رسانه', 'tecteb-header' ) . '</button><button type="button" class="button-link" data-tth-media-clear="tth-logo" hidden>' . esc_html__( 'نشان پیش‌فرض', 'tecteb-header' ) . '</button></span>';
		echo '<p><label for="tth-logo-text">' . esc_html__( 'نوشتهٔ کنار لوگو:', 'tecteb-header' ) . '</label> <input type="text" id="tth-logo-text" name="' . esc_attr( self::name( 'logo_text' ) ) . '" value="' . esc_attr( $s['logo_text'] ) . '" maxlength="40"></p></td></tr>';

		// جست‌وجو
		echo '<tr><th scope="row">' . esc_html__( 'جست‌وجو', 'tecteb-header' ) . '</th><td><fieldset>';
		echo '<label><input type="radio" name="' . esc_attr( self::name( 'search_mode' ) ) . '" value="woocommerce"' . checked( $s['search_mode'], 'woocommerce', false ) . '> ' . esc_html__( 'جست‌وجوی استاندارد محصولات ووکامرس (?s=…&post_type=product)', 'tecteb-header' ) . '</label><br>';
		echo '<label><input type="radio" name="' . esc_attr( self::name( 'search_mode' ) ) . '" value="shortcode"' . checked( $s['search_mode'], 'shortcode', false ) . '> ' . esc_html__( 'shortcode ِ افزونهٔ جست‌وجوی سایت:', 'tecteb-header' ) . '</label> <input type="text" class="regular-text code" dir="ltr" name="' . esc_attr( self::name( 'search_shortcode' ) ) . '" value="' . esc_attr( $s['search_shortcode'] ) . '" aria-label="' . esc_attr__( 'shortcode ِ جست‌وجو', 'tecteb-header' ) . '">';
		echo '<p class="description">' . esc_html__( 'جست‌وجوی فعلی سایت («جستجو سریع …») هنوز شناسایی نشده؛ این افزونه آن را تقلید نمی‌کند. حالت shortcode فقط خروجی همان افزونه را جای کادر می‌گذارد و ظاهر و رفتارش در این محیط آزموده نشده است.', 'tecteb-header' ) . '</p>';
		echo '<label><input type="checkbox" name="' . esc_attr( self::name( 'home_search_toggle' ) ) . '" value="1"' . checked( $s['home_search_toggle'], true, false ) . '> ' . esc_html__( 'در موبایلِ صفحهٔ اصلی، کادر جست‌وجوی هدر به دکمه جمع شود (جست‌وجوی Hero جایش را دارد)', 'tecteb-header' ) . '</label>';
		echo '</fieldset></td></tr>';

		// علاقه‌مندی
		echo '<tr><th scope="row">' . esc_html__( 'علاقه‌مندی‌ها', 'tecteb-header' ) . '</th><td><fieldset>';
		echo '<label><input type="radio" name="' . esc_attr( self::name( 'wishlist_mode' ) ) . '" value="off"' . checked( $s['wishlist_mode'], 'off', false ) . '> ' . esc_html__( 'نمایش داده نشود', 'tecteb-header' ) . '</label><br>';
		echo '<label><input type="radio" name="' . esc_attr( self::name( 'wishlist_mode' ) ) . '" value="link"' . checked( $s['wishlist_mode'], 'link', false ) . '> ' . esc_html__( 'پیوند به صفحهٔ علاقه‌مندی‌ها:', 'tecteb-header' ) . '</label> <input type="text" class="regular-text code" dir="ltr" name="' . esc_attr( self::name( 'wishlist_url' ) ) . '" value="' . esc_attr( $s['wishlist_url'] ) . '" aria-label="' . esc_attr__( 'نشانی صفحهٔ علاقه‌مندی‌ها', 'tecteb-header' ) . '">';
		echo '<p class="description">' . wp_kses_post( __( 'افزونهٔ علاقه‌مندی سایت هنوز شناسایی نشده، پس هیچ شمارنده‌ای نمایش داده نمی‌شود. شمارنده فقط وقتی هست که یک تطبیق‌دهندهٔ واقعی از فیلتر <code>tecteb_header_wishlist_count</code> آن را برگرداند.', 'tecteb-header' ) ) . '</p>';
		echo '</fieldset></td></tr>';

		// سبد
		echo '<tr><th scope="row">' . esc_html__( 'سبد خرید', 'tecteb-header' ) . '</th><td><p>' . esc_html__( 'شمار اقلام و نشانی سبد از خودِ ووکامرس خوانده می‌شود.', 'tecteb-header' ) . '</p>';
		echo '<label><input type="checkbox" name="' . esc_attr( self::name( 'cart_fragments' ) ) . '" value="1"' . checked( $s['cart_fragments'], true, false ) . '> ' . esc_html__( 'به‌روزرسانی شمارنده با cart fragments ِ ووکامرس (یک درخواست AJAX در هر صفحه — فقط اگر کش صفحه شمارنده را کهنه نشان می‌دهد)', 'tecteb-header' ) . '</label></td></tr>';

		// فونت
		echo '<tr><th scope="row">' . esc_html__( 'فونت هدر', 'tecteb-header' ) . '</th><td><fieldset>';
		echo '<label><input type="radio" name="' . esc_attr( self::name( 'font' ) ) . '" value="plugin"' . checked( $s['font'], 'plugin', false ) . '> ' . esc_html__( 'Vazirmatn ِ طرح (۱۱۰ کیلوبایت، همراه افزونه)', 'tecteb-header' ) . '</label><br>';
		echo '<label><input type="radio" name="' . esc_attr( self::name( 'font' ) ) . '" value="inherit"' . checked( $s['font'], 'inherit', false ) . '> ' . esc_html__( 'فونت فعلی سایت', 'tecteb-header' ) . '</label>';
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'دسترسی', 'tecteb-header' ) . '</th><td><p>' . wp_kses_post( sprintf( /* translators: %s: capability */ __( 'فقط کاربری با مجوز <code>%s</code>. هر ذخیره با nonce ِ همین صفحه.', 'tecteb-header' ), esc_html( Settings::capability() ) ) ) . '</p></td></tr>';
		echo '</table>';
		submit_button( __( 'ذخیرهٔ تنظیمات', 'tecteb-header' ) );
		echo '</form>';
	}

	// ------------------------------------------------------------------ جایگزینی

	/** @param array<string,mixed> $s */
	private static function tab_replace( array $s ): void {
		$on = (bool) $s['replace'];
		echo '<h2>' . esc_html__( 'جایگزینی هدر Elementor', 'tecteb-header' ) . ' <span class="tth-state tth-state--' . ( $on ? 'on' : 'off' ) . '">' . ( $on ? esc_html__( 'روشن', 'tecteb-header' ) : esc_html__( 'خاموش', 'tecteb-header' ) ) . '</span></h2>';
		echo '<p>' . wp_kses_post( __( 'وقتی روشن است، هر جا Elementor هدر Theme Builder (امروز قالب «Header»، ۲۶۵۲) را چاپ می‌کند، همان خروجی کنار گذاشته و این هدر <strong>همان‌جا</strong> چاپ می‌شود — در همهٔ صفحه‌ها، دسکتاپ و موبایل. قالب ۲۶۵۲، شرط نمایش آن و فوتر Elementor نه خوانده، نه نوشته و نه حذف می‌شوند.', 'tecteb-header' ) ) . '</p>';
		echo '<ul class="tth-steps">';
		echo '<li>' . esc_html__( 'دو هدر هم‌زمان ممکن نیست: هدر افزونه فقط جای هدری چاپ می‌شود که Elementor همان لحظه چاپ کرده و کنار گذاشته شده؛ اگر Elementor هدری چاپ نکند، این افزونه هم چاپ نمی‌کند.', 'tecteb-header' ) . '</li>';
		echo '<li>' . esc_html__( 'بازگشت سریع: همین گزینه را خاموش و ذخیره کنید (یا افزونه را غیرفعال کنید) — از درخواست بعد همان ۲۶۵۲. پس از ذخیره، کش LiteSpeed (اگر نصب است) پاک‌سازی می‌شود.', 'tecteb-header' ) . '</li>';
		echo '<li>' . esc_html__( 'در ویرایشگر و پیش‌نمایش Elementor هیچ چیزی جایگزین نمی‌شود.', 'tecteb-header' ) . '</li>';
		echo '</ul>';
		echo '<p>' . esc_html__( 'پیش از روشن‌کردن، روی همان سایت و فقط برای خودتان ببینید:', 'tecteb-header' ) . ' ';
		echo '<a href="' . esc_url( add_query_arg( 'tth_preview', '1', home_url( '/' ) ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'هدر افزونه به‌جای هدر فعلی', 'tecteb-header' ) . '</a> · ';
		echo '<a href="' . esc_url( add_query_arg( 'tth_original', '1', home_url( '/' ) ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'هدر فعلی Elementor (حتی وقتی جایگزینی روشن است)', 'tecteb-header' ) . '</a></p>';

		$env = array(
			__( 'Elementor', 'tecteb-header' )         => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
			__( 'Elementor Pro', 'tecteb-header' )     => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '',
			__( 'LiteSpeed Cache', 'tecteb-header' )   => defined( 'LSCWP_V' ) ? (string) LSCWP_V : '',
			__( 'قالب فعال', 'tecteb-header' )          => (string) wp_get_theme()->get( 'Name' ) . ' ' . (string) wp_get_theme()->get( 'Version' ),
		);
		echo '<table class="widefat striped" style="max-width:40rem"><caption>' . esc_html__( 'آنچه روی همین سایت دیده می‌شود (فقط اطلاع؛ سازگاری را ثابت نمی‌کند)', 'tecteb-header' ) . '</caption><tbody>';
		foreach ( $env as $k => $v ) {
			echo '<tr><th scope="row">' . esc_html( $k ) . '</th><td>' . ( '' !== trim( $v ) ? esc_html( $v ) : esc_html__( 'دیده نشد', 'tecteb-header' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Elementor Pro روی این سایت دیده نشد؛ جایگزینی فقط با هدر Theme Builder ِ Elementor Pro کار می‌کند و اینجا اثری ندارد.', 'tecteb-header' ) . '</p></div>';
		}

		self::form_open( 'replace' );
		echo '<p><label><input type="checkbox" name="' . esc_attr( self::name( 'replace' ) ) . '" value="1"' . checked( $on, true, false ) . '> <strong>' . esc_html__( 'هدر Elementor را در همهٔ صفحه‌ها با این هدر جایگزین کن', 'tecteb-header' ) . '</strong></label></p>';
		echo '<p><label><input type="checkbox" name="' . esc_attr( self::name( 'assets_everywhere' ) ) . '" value="1"' . checked( $s['assets_everywhere'], true, false ) . '> ' . esc_html__( 'CSS و JS ِ هدر در head همهٔ صفحه‌ها بارگذاری شود (برای استفاده با shortcode در قالب Elementor؛ با جایگزینی روشن، خودکار است)', 'tecteb-header' ) . '</label></p>';
		submit_button( __( 'ذخیره', 'tecteb-header' ) );
		echo '</form>';
		echo '<h3>' . esc_html__( 'راه دوم: shortcode در قالب Elementor', 'tecteb-header' ) . '</h3>';
		echo '<p>' . wp_kses_post( __( 'به‌جای این گزینه می‌شود یک قالب هدر تازه در Theme Builder ساخت، ابزارک Shortcode با <code>[tecteb_header]</code> در آن گذاشت و شرط «کل سایت» را از ۲۶۵۲ به آن داد. بازگشت = برگرداندن شرط به ۲۶۵۲. هر دو راه در README آمده‌اند؛ هیچ‌کدام را این افزونه خودکار انجام نمی‌دهد.', 'tecteb-header' ) ) . '</p>';
	}

	/** @param array<string,mixed> $s */
	private static function tab_data( array $s ): void {
		echo '<h2>' . esc_html__( 'دادهٔ ذخیره‌شده', 'tecteb-header' ) . '</h2>';
		echo '<p>' . wp_kses_post( __( 'option ِ <code>tecteb_header_settings</code>: فقط شناسه، روشن/خاموش و ترتیب. <strong>هیچ نام، نامک یا نشانی دسته‌ای ذخیره نمی‌شود.</strong>', 'tecteb-header' ) ) . '</p>';
		$stored = get_option( Settings::OPTION, null );
		if ( null === $stored ) {
			echo '<p>' . esc_html__( 'هنوز چیزی ذخیره نشده؛ پیش‌فرض‌ها خوانده می‌شوند.', 'tecteb-header' ) . '</p>';
		}
		echo '<pre class="tth-json" dir="ltr" tabindex="0">' . esc_html( (string) wp_json_encode( $s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
	}
}
