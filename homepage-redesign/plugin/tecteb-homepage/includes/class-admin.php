<?php
/**
 * صفحهٔ تنظیمات: «ووکامرس ← صفحهٔ اصلی».
 *
 * هر تغییری که مدیر اینجا می‌دهد فقط option ِ همین افزونه را می‌نویسد؛ انتخاب
 * محصول یا دسته هیچ چیزی را روی خودِ محصول یا دسته (ویژه، قیمت، موجودی، دسته)
 * عوض نمی‌کند.
 *
 * انتخاب دسته و محصول هر دو جست‌وجومحورند (سایت بیش از هزار دسته و صدها محصول
 * دارد). جست‌وجو یک GET به همین صفحه است — نه AJAX و نه نقطهٔ پایانی تازه، پس
 * هیچ سطح حمله‌ای در بخش عمومی سایت اضافه نمی‌شود و همان مجوز همین صفحه کافی
 * است. دکمهٔ «افزودن» بخشی از همان فرم ذخیره است (nonce ِ settings_fields)، پس
 * هر چه تایپ شده با افزودن از دست نمی‌رود. ترتیب با عدد است و بی جاوااسکریپت
 * کار می‌کند.
 *
 * @package Tecteb\Homepage
 */

namespace Tecteb\Homepage;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const PAGE = 'tecteb-homepage';

	public static function capability(): string {
		/**
		 * مجوز ویرایش تنظیمات صفحهٔ اصلی. پیش‌فرض: مدیر فروشگاه (manage_woocommerce).
		 *
		 * @param string $capability
		 */
		return (string) apply_filters( 'tecteb_home_capability', 'manage_woocommerce' );
	}

	/** نام قلاب صفحهٔ تنظیمات (از add_submenu_page)، تا کتابخانهٔ رسانه فقط همین‌جا بارگذاری شود. */
	private static string $hook = '';

	public static function register_hooks(): void {
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_menu', array( self::class, 'menu' ), 60 );
		add_filter( 'option_page_capability_' . Settings::GROUP, array( self::class, 'capability' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TECTEB_HOME_FILE ), array( self::class, 'action_links' ) );
	}

	public static function register_setting(): void {
		register_setting(
			Settings::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	public static function menu(): void {
		self::$hook = (string) add_submenu_page(
			'woocommerce',
			__( 'صفحهٔ اصلی تک‌طب', 'tecteb-homepage' ),
			__( 'صفحهٔ اصلی', 'tecteb-homepage' ),
			self::capability(),
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	/** ۰٫۳٫۳: انتخابگر کتابخانهٔ رسانه برای لوگوی برندها — فقط روی همین صفحه. */
	public static function enqueue( string $hook ): void {
		if ( '' === self::$hook || $hook !== self::$hook || ! current_user_can( self::capability() ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'tecteb-home-admin', TECTEB_HOME_URL . 'assets/js/tecteb-home-admin.js', array(), TECTEB_HOME_VERSION, true );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'تنظیمات', 'tecteb-homepage' ) . '</a>' );
		return $links;
	}

	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'اجازهٔ دسترسی به این صفحه را ندارید.', 'tecteb-homepage' ), 403 );
		}

		Settings::forget();
		$s     = Settings::get();
		$plan  = Renderer::plan();
		$name  = Settings::OPTION;
		$query = isset( $_GET['tth_q'] ) ? sanitize_text_field( wp_unslash( $_GET['tth_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- فقط جست‌وجو، هیچ نوشتنی.
		$pq    = isset( $_GET['tth_pq'] ) ? sanitize_text_field( wp_unslash( $_GET['tth_pq'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- فقط جست‌وجو، هیچ نوشتنی.
		$hq    = isset( $_GET['tth_hq'] ) ? sanitize_text_field( wp_unslash( $_GET['tth_hq'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- فقط جست‌وجو، هیچ نوشتنی.

		// پیوندهای داخل متنِ جدول‌ها باید بی‌نیاز از رنگ تشخیص‌پذیر باشند (axe: link-in-text-block)؛ فقط همین صفحه.
		echo '<style>.tth-admin td a:not(.button){text-decoration:underline}</style>';
		echo '<div class="wrap tth-admin" dir="rtl">';
		echo '<h1>' . esc_html__( 'صفحهٔ اصلی تک‌طب', 'tecteb-homepage' ) . '</h1>';
		echo '<p>' . esc_html__( 'این تنظیمات فقط نمایش صفحهٔ اصلی را تعیین می‌کنند. هیچ محصول، دسته، سفارش یا فروشنده‌ای اینجا تغییر نمی‌کند.', 'tecteb-homepage' ) . '</p>';
		settings_errors( Settings::OPTION );

		self::status( $plan );

		echo '<form id="tth-search" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"></form>';
		echo '<form id="tth-hero-search" method="get" action="' . esc_url( admin_url( 'admin.php' ) . '#tth-hero-labels' ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"></form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( Settings::GROUP );
		submit_button( __( 'ذخیرهٔ تنظیمات', 'tecteb-homepage' ) );

		self::front_section( $s, $name );
		self::products_section( $s, $name, $pq );
		self::categories_section( $s, $name, $query );
		self::hero_labels_section( $plan, $name, $hq );
		self::links_section( $name );
		self::content_section( $s, $name );
		self::brands_section( $s, $name );

		submit_button( __( 'ذخیرهٔ تنظیمات', 'tecteb-homepage' ), 'primary', 'submit', true, array( 'id' => 'tth-submit-bottom' ) );
		echo '</form></div>';
	}

	private static function status( array $plan ): void {
		$labels = array(
			'route:shop'     => __( 'مسیر «فروشگاه»', 'tecteb-homepage' ),
			'route:repair'   => __( 'مسیر «درخواست تعمیرات تجهیزات پزشکی»', 'tecteb-homepage' ),
			'route:supplier' => __( 'مسیر «همکاری تأمین‌کنندگان»', 'tecteb-homepage' ),
			'hero'           => __( 'معرفی و جست‌وجو', 'tecteb-homepage' ),
			'categories'     => __( 'دسته‌بندی‌ها', 'tecteb-homepage' ),
			'products'       => __( 'محصولات منتخب', 'tecteb-homepage' ),
			'supplier'       => __( 'بخش همکاری تأمین‌کنندگان', 'tecteb-homepage' ),
			'brands'         => __( 'برندهای همکار', 'tecteb-homepage' ),
		);
		$rows = array();
		foreach ( $plan['routes'] as $key => $state ) {
			$rows[ 'route:' . $key ] = $state;
		}
		echo '<h2>' . esc_html__( 'آنچه روی صفحه دیده می‌شود', 'tecteb-homepage' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:56rem"><tbody>';
		foreach ( $rows + $plan['sections'] as $key => $state ) {
			echo '<tr><th scope="row" style="width:14rem">' . esc_html( $labels[ $key ] ) . '</th><td>'
				. ( $state['show'] ? '<strong>' . esc_html__( 'نمایش داده می‌شود', 'tecteb-homepage' ) . '</strong>' : esc_html__( 'نمایش داده نمی‌شود', 'tecteb-homepage' ) . ' — ' . esc_html( $state['reason'] ) )
				. '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<p data-tth-front-state="' . esc_attr( self::front_state()['code'] ) . '"><strong>' . esc_html( self::front_state()['text'] ) . '</strong></p>';
	}

	/**
	 * وضعیت صفحهٔ نخست سایت در یک جمله، از همان قاعده‌ای که PageTemplate::takes_over_front() می‌پرسد.
	 *
	 * @return array{code:string,text:string}
	 */
	public static function front_state(): array {
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$post  = $front > 0 ? get_post( $front ) : null;
		$title = $post instanceof \WP_Post ? get_the_title( $post ) : '';
		$on    = (bool) Settings::get()['front_page'];
		$prob  = PageTemplate::front_page_problem();
		$uses  = $post instanceof \WP_Post && ( has_shortcode( (string) $post->post_content, Plugin::SHORTCODE ) || PageTemplate::FILE === get_page_template_slug( $post ) || str_contains( (string) get_post_meta( $front, '_elementor_data', true ), '[' . Plugin::SHORTCODE ) );
		if ( '' !== $prob ) {
			return array( 'code' => 'blocked', 'text' => $prob );
		}
		if ( $on ) {
			return array(
				'code' => 'on',
				/* translators: %s: page title */
				'text' => '' !== $title ? sprintf( __( 'صفحهٔ نخست سایت اکنون طرح Concept C را نشان می‌دهد (برگهٔ «%s» در «تنظیمات ← خواندن» همچنان انتخاب‌شده و دست‌نخورده است).', 'tecteb-homepage' ), $title ) : __( 'صفحهٔ نخست سایت (آخرین نوشته‌ها) اکنون طرح Concept C را نشان می‌دهد.', 'tecteb-homepage' ),
			);
		}
		if ( $uses ) {
			/* translators: %s: page title */
			return array( 'code' => 'shortcode', 'text' => sprintf( __( 'صفحهٔ نخست سایت («%s») خودش shortcode یا قالب این افزونه را دارد و همین طرح را نشان می‌دهد.', 'tecteb-homepage' ), $title ) );
		}
		return array(
			'code' => 'off',
			/* translators: %s: page title */
			'text' => '' !== $title ? sprintf( __( 'صفحهٔ نخست سایت هنوز برگهٔ «%s» را نشان می‌دهد، نه این طرح. برای نمایش طرح، گزینهٔ «نمایش روی صفحهٔ نخست سایت» را در پایین روشن و ذخیره کنید.', 'tecteb-homepage' ), $title ) : __( 'صفحهٔ نخست سایت هنوز این طرح را نشان نمی‌دهد. برای نمایش، گزینهٔ «نمایش روی صفحهٔ نخست سایت» را روشن و ذخیره کنید.', 'tecteb-homepage' ),
		);
	}

	private static function front_section( array $s, string $name ): void {
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$post  = $front > 0 ? get_post( $front ) : null;
		$prob  = PageTemplate::front_page_problem();
		echo '<h2 id="tth-front">' . esc_html__( 'نمایش روی صفحهٔ نخست سایت', 'tecteb-homepage' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'صفحهٔ نخست فعلی (تنظیمات ← خواندن)', 'tecteb-homepage' ) . '</th><td>';
		if ( $post instanceof \WP_Post ) {
			echo '<strong>' . esc_html( get_the_title( $post ) ) . '</strong> <span dir="ltr">#' . esc_html( (string) $front ) . '</span>';
		} else {
			echo esc_html__( 'آخرین نوشته‌ها (برگهٔ ثابتی انتخاب نشده)', 'tecteb-homepage' );
		}
		echo '<p class="description">' . esc_html__( 'این افزونه این انتخاب را عوض نمی‌کند و هیچ برگه‌ای نمی‌سازد.', 'tecteb-homepage' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'طرح Concept C', 'tecteb-homepage' ) . '</th><td>';
		if ( '' !== $prob ) {
			echo '<p class="notice notice-warning inline" style="padding:8px 12px">' . esc_html( $prob ) . '</p>';
		}
		echo '<label><input type="checkbox" name="' . esc_attr( $name . '[front_page]' ) . '" value="1"' . checked( (bool) $s['front_page'], true, false ) . disabled( '' !== $prob, true, false ) . '> <strong>' . esc_html__( 'نمایش روی صفحهٔ نخست سایت، به‌جای محتوای برگهٔ فعلی', 'tecteb-homepage' ) . '</strong></label>';
		echo '<ul style="list-style:disc;margin-inline-start:1.5em">';
		echo '<li>' . esc_html__( 'برگهٔ فعلی، محتوا و دادهٔ Elementor آن نه نوشته می‌شوند و نه حذف؛ فقط هنگام نمایش، به‌جای محتوای آن، طرح Concept C بین هدر و فوتر سایت چاپ می‌شود. عنوان و متای سئوی همان برگه می‌ماند.', 'tecteb-homepage' ) . '</li>';
		echo '<li>' . esc_html__( 'بازگشت فوری: تیک را بردارید و ذخیره کنید (یا افزونه را غیرفعال کنید) ⇒ از درخواست بعد همان برگهٔ قبلی.', 'tecteb-homepage' ) . '</li>';
		echo '<li>' . esc_html__( 'هر ذخیرهٔ این صفحه کش صفحهٔ اصلی (WP Rocket / LiteSpeed) را پاک می‌کند — فقط صفحهٔ اصلی.', 'tecteb-homepage' ) . '</li>';
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'پیش‌نمایش فقط برای مدیر (بی اثر روی بازدیدکننده‌ها و کش):', 'tecteb-homepage' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( add_query_arg( 'tecteb_home_preview', '1', home_url( '/' ) ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'صفحهٔ نخست با طرح Concept C', 'tecteb-homepage' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( add_query_arg( 'tecteb_home_original', '1', home_url( '/' ) ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'صفحهٔ نخست فعلی (حتی وقتی گزینه روشن است)', 'tecteb-homepage' ) . '</a></p>';
		echo '</td></tr></tbody></table>';
	}

	private static function categories_section( array $s, string $name, string $query ): void {
		echo '<h2>' . esc_html__( 'دسته‌های صفحهٔ اصلی', 'tecteb-homepage' ) . '</h2>';
		/* translators: %s: عدد، نام یا نشانی‌ای که در همان جمله می‌آید. */
		echo '<p>' . esc_html( sprintf( __( 'حداکثر %s دسته. ترتیب نمایش با ستون «ترتیب» است (عدد کوچک‌تر، اول). نام، نشانی، تصویر بندانگشتی و توضیح هر دسته از خودِ ووکامرس خوانده می‌شود؛ دسته‌ای که محصولی ندارد نمایش داده نمی‌شود.', 'tecteb-homepage' ), Renderer::digits( Settings::MAX_CATEGORIES ) ) ) . '</p>';

		if ( array() === $s['categories'] ) {
			echo '<p><em>' . esc_html__( 'هنوز دسته‌ای انتخاب نشده است.', 'tecteb-homepage' ) . '</em></p>';
		} else {
			echo '<table class="widefat striped" style="max-width:72rem"><thead><tr>'
				. '<th style="width:5rem">' . esc_html__( 'ترتیب', 'tecteb-homepage' ) . '</th>'
				. '<th>' . esc_html__( 'دسته (مسیر کامل)', 'tecteb-homepage' ) . '</th>'
				. '<th style="width:8rem">' . esc_html__( 'محصول قابل‌نمایش', 'tecteb-homepage' ) . '</th>'
				. '<th style="width:9rem">' . esc_html__( 'آیکون (اگر تصویر ندارد)', 'tecteb-homepage' ) . '</th>'
				. '<th>' . esc_html__( 'متن کوتاه (اختیاری)', 'tecteb-homepage' ) . '</th>'
				. '<th style="width:5rem">' . esc_html__( 'حذف', 'tecteb-homepage' ) . '</th>'
				. '</tr></thead><tbody>';
			foreach ( $s['categories'] as $i => $row ) {
				$term  = get_term( (int) $row['id'], 'product_cat' );
				$field = $name . '[categories][' . $i . ']';
				echo '<tr>';
				echo '<td><input type="hidden" name="' . esc_attr( $field . '[id]' ) . '" value="' . esc_attr( (string) $row['id'] ) . '">'
					. '<input type="number" min="0" max="99" style="width:4.5rem" name="' . esc_attr( $field . '[order]' ) . '" value="' . esc_attr( (string) ( $i + 1 ) ) . '" aria-label="' . esc_attr__( 'ترتیب', 'tecteb-homepage' ) . '"></td>';
				if ( $term instanceof \WP_Term ) {
					$thumb = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
					echo '<td>' . esc_html( Catalog::path( $term ) ) . ' <a href="' . esc_url( (string) get_edit_term_link( $term, 'product_cat', 'product' ) ) . '">' . esc_html__( 'ویرایش در ووکامرس', 'tecteb-homepage' ) . '</a>'
						. ( $thumb > 0 ? '' : '<br><small>' . esc_html__( 'تصویر بندانگشتی ندارد؛ آیکون انتخابی نمایش داده می‌شود.', 'tecteb-homepage' ) . '</small>' ) . '</td>';
					echo '<td>' . ( Catalog::has_products( $term ) ? esc_html__( 'دارد', 'tecteb-homepage' ) : '<strong>' . esc_html__( 'ندارد — نمایش داده نمی‌شود', 'tecteb-homepage' ) . '</strong>' ) . '</td>';
				} else {
					/* translators: %s: عدد، نام یا نشانی‌ای که در همان جمله می‌آید. */
					echo '<td><strong>' . esc_html( sprintf( __( 'دستهٔ #%s در ووکامرس پیدا نشد — نمایش داده نمی‌شود.', 'tecteb-homepage' ), (string) $row['id'] ) ) . '</strong></td><td>—</td>';
				}
				echo '<td><select name="' . esc_attr( $field . '[icon]' ) . '" aria-label="' . esc_attr__( 'آیکون', 'tecteb-homepage' ) . '">';
				echo '<option value="">' . esc_html__( 'پیش‌فرض', 'tecteb-homepage' ) . '</option>';
				foreach ( self::category_icons() as $icon => $label ) {
					echo '<option value="' . esc_attr( $icon ) . '"' . selected( $row['icon'], $icon, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select></td>';
				echo '<td><input type="text" class="regular-text" maxlength="60" name="' . esc_attr( $field . '[note]' ) . '" value="' . esc_attr( $row['note'] ) . '" placeholder="' . esc_attr__( 'خالی: سطر اول توضیح دسته در ووکامرس', 'tecteb-homepage' ) . '" aria-label="' . esc_attr__( 'متن کوتاه', 'tecteb-homepage' ) . '"></td>';
				echo '<td><label><input type="checkbox" name="' . esc_attr( $field . '[remove]' ) . '" value="1"> ' . esc_html__( 'حذف', 'tecteb-homepage' ) . '</label></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'افزودن دسته', 'tecteb-homepage' ) . '</h3>';
		echo '<p><label for="tth-q" class="screen-reader-text">' . esc_html__( 'نام دسته', 'tecteb-homepage' ) . '</label>'
			. '<input form="tth-search" type="search" id="tth-q" name="tth_q" value="' . esc_attr( $query ) . '" placeholder="' . esc_attr__( 'بخشی از نام دسته…', 'tecteb-homepage' ) . '"> '
			. '<button form="tth-search" type="submit" class="button" id="tth-q-go">' . esc_html__( 'جست‌وجوی دسته', 'tecteb-homepage' ) . '</button></p>';

		if ( '' !== $query ) {
			$results  = Catalog::search_categories( $query );
			$selected = array_column( $s['categories'], 'id' );
			if ( array() === $results ) {
				/* translators: %s: عدد، نام یا نشانی‌ای که در همان جمله می‌آید. */
				echo '<p>' . esc_html( sprintf( __( 'دسته‌ای با «%s» پیدا نشد.', 'tecteb-homepage' ), $query ) ) . '</p>';
			} else {
				echo '<table class="widefat striped" style="max-width:56rem"><tbody>';
				foreach ( $results as $r ) {
					echo '<tr><td>' . esc_html( $r['path'] ) . '</td><td style="width:7rem">' . esc_html( Renderer::digits( $r['count'] ) ) . ' ' . esc_html__( 'محصول', 'tecteb-homepage' ) . '</td><td style="width:9rem">';
					if ( in_array( $r['id'], $selected, true ) ) {
						echo esc_html__( 'انتخاب‌شده', 'tecteb-homepage' );
					} else {
						echo '<button type="submit" class="button" name="' . esc_attr( $name . '[add_category]' ) . '" value="' . esc_attr( (string) $r['id'] ) . '">' . esc_html__( 'افزودن و ذخیره', 'tecteb-homepage' ) . '</button>';
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';
			}
		}
	}

	private static function products_section( array $s, string $name, string $query ): void {
		echo '<h2>' . esc_html__( 'محصولات منتخب صفحهٔ اصلی', 'tecteb-homepage' ) . '</h2>';
		echo '<p>' . esc_html__( 'محصولات را با نام، کد کالا (SKU) یا شناسه جست‌وجو و اضافه کنید؛ ترتیب نمایش با ستون «ترتیب» است. نام، تصویر، قیمت، موجودی و ویژگی‌ها همیشه از خودِ ووکامرس خوانده می‌شود. محصولی که منتشر نیست، در فروشگاه پنهان است، یا (اگر ووکامرس ناموجودها را پنهان می‌کند) ناموجود است، نمایش داده نمی‌شود و هیچ محصول دیگری جایش نمی‌آید. انتخاب در این فهرست هیچ چیزی را روی خودِ محصول عوض نمی‌کند.', 'tecteb-homepage' ) . '</p>';

		$status = Catalog::selection_status( $s['products'] );
		if ( array() === $s['products'] ) {
			echo '<p><strong>' . esc_html__( 'هنوز محصولی انتخاب نشده است؛ بخش محصولات در صفحهٔ اصلی نمایش داده نمی‌شود.', 'tecteb-homepage' ) . '</strong></p>';
		} else {
			echo '<table class="widefat striped" style="max-width:72rem"><thead><tr>'
				. '<th style="width:5rem">' . esc_html__( 'ترتیب', 'tecteb-homepage' ) . '</th>'
				. '<th style="width:3rem"></th>'
				. '<th>' . esc_html__( 'محصول', 'tecteb-homepage' ) . '</th>'
				. '<th>' . esc_html__( 'قیمت', 'tecteb-homepage' ) . '</th>'
				. '<th>' . esc_html__( 'در صفحهٔ اصلی', 'tecteb-homepage' ) . '</th>'
				. '<th style="width:5rem">' . esc_html__( 'حذف', 'tecteb-homepage' ) . '</th>'
				. '</tr></thead><tbody>';
			$shown = 0;
			foreach ( $s['products'] as $i => $id ) {
				$row   = $status[ $id ];
				$field = $name . '[products][' . $i . ']';
				$cut   = $row['shown'] && ++$shown > (int) $s['product_count'];
				echo '<tr>';
				echo '<td><input type="hidden" name="' . esc_attr( $field . '[id]' ) . '" value="' . esc_attr( (string) $id ) . '">'
					. '<input type="number" min="0" max="99" style="width:4.5rem" name="' . esc_attr( $field . '[order]' ) . '" value="' . esc_attr( (string) ( $i + 1 ) ) . '" aria-label="' . esc_attr__( 'ترتیب', 'tecteb-homepage' ) . '"></td>';
				echo '<td>' . wp_kses_post( $row['thumb'] ) . '</td>';
				echo '<td>' . esc_html( $row['name'] ) . ' <small dir="ltr">#' . esc_html( (string) $id ) . '</small>'
					. ( '' !== $row['edit'] ? ' <a href="' . esc_url( $row['edit'] ) . '">' . esc_html__( 'ویرایش در ووکامرس', 'tecteb-homepage' ) . '</a>' : '' ) . '</td>';
				echo '<td>' . esc_html( '' !== $row['price'] ? $row['price'] : '—' ) . '</td>';
				if ( ! $row['shown'] ) {
					echo '<td><strong>' . esc_html__( 'نمایش داده نمی‌شود', 'tecteb-homepage' ) . '</strong> — ' . esc_html( $row['reason'] ) . '</td>';
				} elseif ( $cut ) {
					echo '<td>' . esc_html__( 'بیش از تعداد نمایش؛ اگر محصولی بالاتر کنار برود، نمایش داده می‌شود.', 'tecteb-homepage' ) . '</td>';
				} else {
					echo '<td>' . esc_html__( 'نمایش داده می‌شود', 'tecteb-homepage' ) . '</td>';
				}
				echo '<td><label><input type="checkbox" name="' . esc_attr( $field . '[remove]' ) . '" value="1"> ' . esc_html__( 'حذف', 'tecteb-homepage' ) . '</label></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="tth-count">' . esc_html__( 'تعداد محصولات نمایش‌داده‌شده', 'tecteb-homepage' ) . '</label></th><td><select id="tth-count" name="' . esc_attr( $name . '[product_count]' ) . '">';
		for ( $n = 1; $n <= Settings::MAX_PRODUCTS; $n++ ) {
			echo '<option value="' . esc_attr( (string) $n ) . '"' . selected( (int) $s['product_count'], $n, false ) . '>' . esc_html( Renderer::digits( $n ) ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'دسکتاپ چهار کارت در هر ردیف دارد؛ مضرب ۴ ردیف کامل می‌سازد.', 'tecteb-homepage' ) . '</p></td></tr></table>';

		echo '<h3>' . esc_html__( 'افزودن محصول', 'tecteb-homepage' ) . '</h3>';
		echo '<p><label for="tth-pq" class="screen-reader-text">' . esc_html__( 'نام، کد کالا یا شناسهٔ محصول', 'tecteb-homepage' ) . '</label>'
			. '<input form="tth-search" type="search" id="tth-pq" name="tth_pq" value="' . esc_attr( $query ) . '" placeholder="' . esc_attr__( 'نام، کد کالا یا شناسهٔ محصول…', 'tecteb-homepage' ) . '"> '
			. '<button form="tth-search" type="submit" class="button" id="tth-pq-go">' . esc_html__( 'جست‌وجوی محصول', 'tecteb-homepage' ) . '</button></p>';
		if ( '' !== $query ) {
			$results = Catalog::search_products( $query );
			if ( array() === $results ) {
				/* translators: %s: عبارت جست‌وجو. */
				echo '<p>' . esc_html( sprintf( __( 'محصولی با «%s» پیدا نشد.', 'tecteb-homepage' ), $query ) ) . '</p>';
			} else {
				$stock = array(
					'instock'     => __( 'موجود', 'tecteb-homepage' ),
					'outofstock'  => __( 'ناموجود', 'tecteb-homepage' ),
					'onbackorder' => __( 'پیش‌سفارش', 'tecteb-homepage' ),
				);
				echo '<table class="widefat striped" style="max-width:72rem"><tbody>';
				foreach ( $results as $r ) {
					$note = 'publish' !== $r['status'] ? __( 'منتشر نشده', 'tecteb-homepage' ) : ( in_array( $r['visibility'], array( 'visible', 'catalog' ), true ) ? '' : __( 'در فروشگاه پنهان', 'tecteb-homepage' ) );
					echo '<tr><td style="width:3rem">' . wp_kses_post( $r['thumb'] ) . '</td><td>' . esc_html( $r['name'] ) . ' <small dir="ltr">#' . esc_html( (string) $r['id'] ) . '</small>'
						. ( '' !== $note ? ' — <em>' . esc_html( $note ) . '</em>' : '' ) . '</td>'
						. '<td>' . esc_html( '' !== $r['price'] ? $r['price'] : '—' ) . '</td><td>' . esc_html( $stock[ $r['stock'] ] ?? $r['stock'] ) . '</td><td style="width:9rem">';
					if ( in_array( $r['id'], $s['products'], true ) ) {
						echo esc_html__( 'انتخاب‌شده', 'tecteb-homepage' );
					} else {
						echo '<button type="submit" class="button" name="' . esc_attr( $name . '[add_product]' ) . '" value="' . esc_attr( (string) $r['id'] ) . '">' . esc_html__( 'افزودن و ذخیره', 'tecteb-homepage' ) . '</button>';
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';
			}
		}
	}

	/**
	 * ۰٫۳٫۴: چهار برچسب کنار تصویر Hero — عنوان، دستهٔ ووکامرس، ترتیب، فعال؛ و برای هر ردیف اینکه امروز به
	 * کدام دسته وصل است، یا چرا نمایش داده نمی‌شود. انتخاب دسته: شناسه در کادر، یا از جست‌وجوی همین بخش.
	 */
	private static function hero_labels_section( array $plan, string $name, string $query ): void {
		echo '<h2 id="tth-hero-labels">' . esc_html__( 'برچسب‌های کنار تصویر Hero', 'tecteb-homepage' ) . '</h2>';
		echo '<p>' . esc_html__( 'چهار برچسب روی تصویر Hero. عنوان، دسته، ترتیب (عدد کوچک‌تر، اول) و فعال بودن هر کدام اینجا تعیین می‌شود. عنوان مستقل از نام دسته در ووکامرس است؛ خالی بگذارید تا نام دسته نشان داده شود. اگر دسته‌ای تعیین نکنید، برچسب به دسته‌ای می‌رود که دقیقاً نام پیش‌فرضِ همان ردیف را دارد. برچسب غیرفعال، برچسبی که دسته‌اش پیدا نشود یا مبهم باشد، و دسته‌ای که محصول قابل‌نمایش ندارد نمایش داده نمی‌شوند و هیچ پیوند ساختگی‌ای ساخته نمی‌شود. هیچ دسته یا محصولی اینجا ساخته یا ویرایش نمی‌شود.', 'tecteb-homepage' ) . '</p>';
		$texts = array(
			'not_found' => __( 'دسته‌ای با نام پیش‌فرض این ردیف در ووکامرس پیدا نشد — نمایش داده نمی‌شود.', 'tecteb-homepage' ),
			'ambiguous' => __( 'چند دسته با نام پیش‌فرض این ردیف هست — تا یکی را انتخاب نکنید نمایش داده نمی‌شود:', 'tecteb-homepage' ),
			'missing'   => __( 'دستهٔ تعیین‌شده دیگر در ووکامرس نیست — نمایش داده نمی‌شود.', 'tecteb-homepage' ),
			'empty'     => __( 'این دسته محصول قابل‌نمایشی ندارد — نمایش داده نمی‌شود.', 'tecteb-homepage' ),
			'no_link'   => __( 'نشانی این دسته ساخته نشد — نمایش داده نمی‌شود.', 'tecteb-homepage' ),
		);
		$items = Settings::get()['hero_items'];
		// ردیف‌ها با شمارهٔ ثابت خودشان (همان که دکمه‌های «برای برچسب …» می‌گویند)؛ ترتیبِ نمایش ستون «ترتیب» است.
		$labels = $plan['hero_labels'];
		usort( $labels, static fn( array $a, array $b ): int => $a['index'] <=> $b['index'] );
		echo '<table class="widefat striped" style="max-width:80rem"><thead><tr>'
			. '<th style="width:4rem">' . esc_html__( 'برچسب', 'tecteb-homepage' ) . '</th>'
			. '<th style="width:5rem">' . esc_html__( 'ترتیب', 'tecteb-homepage' ) . '</th>'
			. '<th style="width:14rem">' . esc_html__( 'عنوان نمایشی', 'tecteb-homepage' ) . '</th>'
			. '<th style="width:10rem">' . esc_html__( 'شناسهٔ دسته', 'tecteb-homepage' ) . '</th>'
			. '<th>' . esc_html__( 'دستهٔ ووکامرس', 'tecteb-homepage' ) . '</th>'
			. '<th style="width:6rem">' . esc_html__( 'فعال', 'tecteb-homepage' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $labels as $label ) {
			$i     = (int) $label['index'];
			$item  = $items[ $i ];
			$field = $name . '[hero_items][' . $i . ']';
			$n     = Renderer::digits( $i + 1 );
			echo '<tr data-tth-hero-label="' . esc_attr( (string) $i ) . '" data-tth-status="' . esc_attr( $label['status'] ) . '">';
			echo '<td><strong>' . esc_html( $n ) . '</strong></td>';
			/* translators: %s: label number */
			echo '<td><input type="number" min="0" max="99" style="width:4.5rem" name="' . esc_attr( $field . '[order]' ) . '" value="' . esc_attr( (string) $item['order'] ) . '" aria-label="' . esc_attr( sprintf( __( 'ترتیب برچسب %s', 'tecteb-homepage' ), $n ) ) . '"></td>';
			/* translators: 1: label number 2: default name */
			echo '<td><input type="text" maxlength="40" style="width:13rem" name="' . esc_attr( $field . '[title]' ) . '" value="' . esc_attr( $item['title'] ) . '" placeholder="' . esc_attr( '' !== $label['path'] ? __( 'خالی: نام دسته', 'tecteb-homepage' ) : $label['match'] ) . '" aria-label="' . esc_attr( sprintf( __( 'عنوان برچسب %1$s (پیش‌فرض: %2$s)', 'tecteb-homepage' ), $n, $label['match'] ) ) . '">'
				/* translators: %s: default name */
				. '<br><small>' . esc_html( sprintf( __( 'پیش‌فرض: %s', 'tecteb-homepage' ), $label['match'] ) ) . '</small></td>';
			/* translators: %s: label number */
			echo '<td><input type="number" min="0" step="1" style="width:8rem" name="' . esc_attr( $field . '[term]' ) . '" value="' . esc_attr( $item['term'] > 0 ? (string) $item['term'] : '' ) . '" aria-label="' . esc_attr( sprintf( __( 'شناسهٔ دستهٔ برچسب %s', 'tecteb-homepage' ), $n ) ) . '"><br><small>' . esc_html__( 'خالی = با نام پیش‌فرض', 'tecteb-homepage' ) . '</small></td><td>';
			if ( 'ok' === $label['resolved'] ) {
				echo esc_html( $label['path'] ) . ' <small dir="ltr">#' . esc_html( (string) $label['term'] ) . '</small> — '
					. esc_html( $label['pinned'] ? __( 'تعیین دستی', 'tecteb-homepage' ) : __( 'پیدا شده با نام پیش‌فرض', 'tecteb-homepage' ) );
				if ( $label['active'] ) {
					/* translators: %s: label name as shown */
					echo '<br>' . esc_html( sprintf( __( 'نمایش با عنوان «%s»', 'tecteb-homepage' ), $label['name'] ) ) . ' · <a href="' . esc_url( $label['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'دیدن صفحهٔ دسته', 'tecteb-homepage' ) . '</a>';
				}
			} else {
				echo '<strong>' . esc_html( $texts[ $label['resolved'] ] ?? '' ) . '</strong>';
				if ( '' !== $label['path'] ) {
					echo '<br>' . esc_html( $label['path'] ) . ' <small dir="ltr">#' . esc_html( (string) $label['term'] ) . '</small>';
				}
				if ( array() !== $label['candidates'] ) {
					echo '<ul style="margin:.25rem 1.25rem 0;list-style:disc">';
					foreach ( $label['candidates'] as $c ) {
						echo '<li>' . esc_html( $c['path'] ) . ' — ' . esc_html__( 'شناسه', 'tecteb-homepage' ) . ' <code dir="ltr">' . esc_html( (string) $c['id'] ) . '</code></li>';
					}
					echo '</ul>';
				}
			}
			if ( ! $label['active'] ) {
				echo '<br><em>' . esc_html__( 'غیرفعال — نمایش داده نمی‌شود.', 'tecteb-homepage' ) . '</em>';
			}
			/* translators: %s: label number */
			echo '</td><td><label><input type="checkbox" name="' . esc_attr( $field . '[active]' ) . '" value="1"' . checked( $item['active'], true, false ) . ' aria-label="' . esc_attr( sprintf( __( 'برچسب %s فعال است', 'tecteb-homepage' ), $n ) ) . '"> ' . esc_html__( 'فعال', 'tecteb-homepage' ) . '</label></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'انتخاب دسته برای یک برچسب', 'tecteb-homepage' ) . '</h3>';
		echo '<p><label for="tth-hq" class="screen-reader-text">' . esc_html__( 'نام دسته', 'tecteb-homepage' ) . '</label>'
			. '<input form="tth-hero-search" type="search" id="tth-hq" name="tth_hq" value="' . esc_attr( $query ) . '" placeholder="' . esc_attr__( 'بخشی از نام دسته…', 'tecteb-homepage' ) . '"> '
			. '<button form="tth-hero-search" type="submit" class="button" id="tth-hq-go">' . esc_html__( 'جست‌وجوی دسته', 'tecteb-homepage' ) . '</button></p>';
		if ( '' === $query ) {
			return;
		}
		$results = Catalog::search_categories( $query );
		if ( array() === $results ) {
			/* translators: %s: query */
			echo '<p>' . esc_html( sprintf( __( 'دسته‌ای با «%s» پیدا نشد.', 'tecteb-homepage' ), $query ) ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:72rem" id="tth-hero-results"><tbody>';
		foreach ( $results as $r ) {
			echo '<tr><td>' . esc_html( $r['path'] ) . ' <small dir="ltr">#' . esc_html( (string) $r['id'] ) . '</small></td><td style="width:7rem">' . esc_html( Renderer::digits( $r['count'] ) ) . ' ' . esc_html__( 'محصول', 'tecteb-homepage' ) . '</td><td>';
			foreach ( array_keys( Settings::HERO_LABELS ) as $i ) {
				/* translators: %s: label number */
				/* translators: 1: category path 2: label number */
				echo '<button type="submit" class="button button-small" style="margin:.1rem" name="' . esc_attr( $name . '[hero_pick]' ) . '" value="' . esc_attr( $i . ':' . $r['id'] ) . '" aria-label="' . esc_attr( sprintf( __( 'دستهٔ «%1$s» برای برچسب %2$s، و ذخیره', 'tecteb-homepage' ), $r['path'], Renderer::digits( $i + 1 ) ) ) . '">' . esc_html( sprintf( __( 'برای برچسب %s', 'tecteb-homepage' ), Renderer::digits( $i + 1 ) ) ) . '</button>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * ۰٫۳٫۳: برندهای همکار. لوگو از کتابخانهٔ رسانه (دکمهٔ «انتخاب لوگو»؛ بی جاوااسکریپت شناسهٔ پیوست را
	 * بنویسید)، نشانی اختیاری، فعال/غیرفعال، ترتیب با عدد، حذف با تیک. افزودن بخشی از همان فرم ذخیره است.
	 */
	private static function brands_section( array $s, string $name ): void {
		echo '<h2 id="tth-brands">' . esc_html__( 'برندهای همکار', 'tecteb-homepage' ) . '</h2>';
		/* translators: %s: number */
		echo '<p>' . esc_html( sprintf( __( 'لوگوی برندهایی که با تک‌طب همکاری می‌کنند، در کاروسلی پس از بخش همکاری تأمین‌کنندگان و پیش از فوتر. فقط برند فعالِ دارای لوگو نمایش داده می‌شود؛ بی هیچ برند فعالی کل بخش پنهان است. ترتیب با ستون «ترتیب» (عدد کوچک‌تر، اول). حداکثر %s برند.', 'tecteb-homepage' ), Renderer::digits( Settings::MAX_BRANDS ) ) ) . '</p>';
		$head = '<thead><tr>'
			. '<th style="width:5rem">' . esc_html__( 'ترتیب', 'tecteb-homepage' ) . '</th>'
			. '<th style="width:16rem">' . esc_html__( 'لوگو', 'tecteb-homepage' ) . '</th>'
			. '<th>' . esc_html__( 'نام برند', 'tecteb-homepage' ) . '</th>'
			. '<th>' . esc_html__( 'نشانی (اختیاری)', 'tecteb-homepage' ) . '</th>'
			. '<th style="width:5rem">' . esc_html__( 'فعال', 'tecteb-homepage' ) . '</th>'
			. '<th style="width:5rem">' . esc_html__( 'حذف', 'tecteb-homepage' ) . '</th>'
			. '</tr></thead>';
		if ( array() === $s['brands'] ) {
			echo '<p><em>' . esc_html__( 'هنوز برندی ثبت نشده است؛ بخش برندها در صفحهٔ اصلی نمایش داده نمی‌شود.', 'tecteb-homepage' ) . '</em></p>';
		} else {
			echo '<table class="widefat striped tth-brands" style="max-width:80rem">' . $head . '<tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- بالا ساخته و escape شده.
			foreach ( $s['brands'] as $i => $b ) {
				$field = $name . '[brands][' . $i . ']';
				echo '<tr data-tth-brand="' . esc_attr( (string) $i ) . '">';
				echo '<td><input type="number" min="0" max="999" style="width:4.5rem" name="' . esc_attr( $field . '[order]' ) . '" value="' . esc_attr( (string) ( $i + 1 ) ) . '" aria-label="' . esc_attr__( 'ترتیب', 'tecteb-homepage' ) . '"></td>';
				echo '<td>';
				self::logo_field( $field . '[logo]', 'tth-brand-logo-' . $i, (int) $b['logo'], $b['name'] );
				echo '</td>';
				echo '<td><input type="text" class="regular-text" maxlength="60" required name="' . esc_attr( $field . '[name]' ) . '" value="' . esc_attr( $b['name'] ) . '" aria-label="' . esc_attr__( 'نام برند', 'tecteb-homepage' ) . '"></td>';
				echo '<td><input type="text" class="regular-text" dir="ltr" name="' . esc_attr( $field . '[url]' ) . '" value="' . esc_attr( $b['url'] ) . '" placeholder="https://…" aria-label="' . esc_attr__( 'نشانی برند', 'tecteb-homepage' ) . '"></td>';
				echo '<td><label><input type="checkbox" name="' . esc_attr( $field . '[active]' ) . '" value="1"' . checked( (bool) $b['active'], true, false ) . '> ' . esc_html__( 'فعال', 'tecteb-homepage' ) . '</label>'
					. ( (int) $b['logo'] < 1 ? '<br><small>' . esc_html__( 'بی لوگو نمایش داده نمی‌شود', 'tecteb-homepage' ) . '</small>' : '' ) . '</td>';
				echo '<td><label><input type="checkbox" name="' . esc_attr( $field . '[remove]' ) . '" value="1"> ' . esc_html__( 'حذف', 'tecteb-homepage' ) . '</label></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'افزودن برند', 'tecteb-homepage' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="tth-new-brand-name">' . esc_html__( 'نام برند', 'tecteb-homepage' ) . '</label></th><td><input type="text" class="regular-text" maxlength="60" id="tth-new-brand-name" name="' . esc_attr( $name . '[add_brand][name]' ) . '" value=""></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'لوگو', 'tecteb-homepage' ) . '</th><td>';
		self::logo_field( $name . '[add_brand][logo]', 'tth-new-brand-logo', 0, '' );
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="tth-new-brand-url">' . esc_html__( 'نشانی (اختیاری)', 'tecteb-homepage' ) . '</label></th><td><input type="text" class="regular-text" dir="ltr" id="tth-new-brand-url" name="' . esc_attr( $name . '[add_brand][url]' ) . '" value="" placeholder="https://…"></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'افزودن برند و ذخیره', 'tecteb-homepage' ), 'secondary', 'tth-add-brand', false );
	}

	/** لوگو: پیش‌نمایش + شناسهٔ پیوست + دکمهٔ کتابخانهٔ رسانه (assets/js/tecteb-home-admin.js). */
	private static function logo_field( string $field, string $id, int $logo, string $name ): void {
		$valid = $logo > 0 && wp_attachment_is_image( $logo );
		echo '<span class="tth-logo-preview" data-tth-preview="' . esc_attr( $id ) . '" style="display:inline-block;min-width:4rem;vertical-align:middle">'
			// «medium» بی برش است (thumbnail مربعی برش می‌خورد و لوگوی پهن را نصفه نشان می‌دهد)؛ بی lazy، چون پیش‌نمایش است.
			. ( $valid ? wp_get_attachment_image( $logo, 'medium', false, array( 'style' => 'max-height:48px;max-width:10rem;width:auto;height:auto', 'alt' => $name, 'loading' => false ) ) : '' )
			. '</span> ';
		echo '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html__( 'شناسهٔ پیوست لوگو', 'tecteb-homepage' ) . '</label>'
			. '<input type="number" min="0" step="1" style="width:6rem" id="' . esc_attr( $id ) . '" name="' . esc_attr( $field ) . '" value="' . esc_attr( $logo > 0 ? (string) $logo : '' ) . '"> '
			. '<button type="button" class="button tth-pick-logo" data-tth-target="' . esc_attr( $id ) . '">' . esc_html__( 'انتخاب لوگو', 'tecteb-homepage' ) . '</button>';
		if ( $logo > 0 && ! $valid ) {
			echo '<br><strong>' . esc_html__( 'این شناسه تصویر معتبری از کتابخانهٔ رسانه نیست.', 'tecteb-homepage' ) . '</strong>';
		}
	}

	private static function links_section( string $name ): void {
		$fields = array(
			'repair'         => array( __( 'درخواست تعمیرات تجهیزات پزشکی', 'tecteb-homepage' ), __( 'نشانی صفحهٔ تعمیرات موجود در سایت (پیش‌فرض: /https-tecteb-com-repair-request/). فقط پیوند است؛ فرم تعمیرات در صفحهٔ اصلی نیست. خالی: کارت تعمیرات نمایش داده نمی‌شود.', 'tecteb-homepage' ) ),
			'supplier'       => array( __( 'همکاری تأمین‌کنندگان', 'tecteb-homepage' ), __( 'پیش‌فرض: /landing-seller/. خالی: کارت همکاری در Hero و بخش همکاری نمایش داده نمی‌شوند.', 'tecteb-homepage' ) ),
			'supplier_terms' => array( __( 'شرایط همکاری', 'tecteb-homepage' ), __( 'خالی: دکمهٔ «شرایط همکاری» نمایش داده نمی‌شود.', 'tecteb-homepage' ) ),
			'all_categories' => array( __( 'همهٔ دسته‌ها', 'tecteb-homepage' ), __( 'خالی: پیوند «همهٔ دسته‌ها» نمایش داده نمی‌شود.', 'tecteb-homepage' ) ),
		);
		echo '<h2>' . esc_html__( 'مقصد دکمه‌ها', 'tecteb-homepage' ) . '</h2>';
		$shop = Renderer::shop_url();
		echo '<p>' . ( '' !== $shop
			? esc_html__( 'فروشگاه: صفحهٔ فروشگاه ووکامرس', 'tecteb-homepage' ) . ' <code dir="ltr">' . esc_html( $shop ) . '</code> ' . esc_html__( '(از تنظیمات ووکامرس؛ اینجا تغییر نمی‌کند).', 'tecteb-homepage' )
			: '<strong>' . esc_html__( 'صفحهٔ فروشگاه در «ووکامرس ← تنظیمات ← محصولات» تعیین نشده یا منتشر نیست؛ کارت فروشگاه نمایش داده نمی‌شود.', 'tecteb-homepage' ) . '</strong>' ) . '</p>';
		echo '<p>' . esc_html__( 'مسیر نسبی (مثل /vendor/application/) با نشانی همین محیط کامل می‌شود، پس روی staging و سایت اصلی هرکدام به خودش می‌رود. هر نشانی را یک ثابت در wp-config.php هم می‌تواند تعیین کند که با کپی دیتابیس جابه‌جا نمی‌شود.', 'tecteb-homepage' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		foreach ( $fields as $key => $meta ) {
			$id = 'tth-link-' . $key;
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $meta[0] ) . '</label></th><td>';
			if ( 'constant' === Settings::link_source( $key ) ) {
				/* translators: %s: عدد، نام یا نشانی‌ای که در همان جمله می‌آید. */
				echo '<code dir="ltr">' . esc_html( Settings::link_raw( $key ) ?: '—' ) . '</code><p class="description">' . esc_html( sprintf( __( 'با ثابت %s در wp-config.php تعیین شده و اینجا ویرایش نمی‌شود.', 'tecteb-homepage' ), Settings::LINKS[ $key ] ) ) . '</p>';
				echo '<input type="hidden" name="' . esc_attr( $name . '[links][' . $key . ']' ) . '" value="' . esc_attr( Settings::get()['links'][ $key ] ) . '">';
			} else {
				echo '<input type="text" dir="ltr" class="regular-text code" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name . '[links][' . $key . ']' ) . '" value="' . esc_attr( Settings::get()['links'][ $key ] ) . '" placeholder="https://… ' . esc_attr__( 'یا', 'tecteb-homepage' ) . ' /…">';
				echo '<p class="description">' . esc_html( $meta[1] ) . ' <code dir="ltr">' . esc_html( Settings::LINKS[ $key ] ) . '</code></p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
	}

	private static function content_section( array $s, string $name ): void {
		echo '<h2>' . esc_html__( 'متن‌ها', 'tecteb-homepage' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		self::textarea( 'tth-hero-lead', __( 'متن زیر عنوان اصلی', 'tecteb-homepage' ), $name . '[hero_lead]', $s['hero_lead'], 2, __( 'خالی: نمایش داده نمی‌شود.', 'tecteb-homepage' ) );
		self::input( 'tth-aud-label', __( 'برچسب مخاطبان', 'tecteb-homepage' ), $name . '[audience_label]', $s['audience_label'] );
		self::textarea( 'tth-aud', __( 'مخاطبان (هر خط یکی)', 'tecteb-homepage' ), $name . '[audience]', implode( "\n", $s['audience'] ), 4, __( 'خالی: خط مخاطبان نمایش داده نمی‌شود.', 'tecteb-homepage' ) );
		/* translators: %s: عدد، نام یا نشانی‌ای که در همان جمله می‌آید. */
		self::textarea( 'tth-chips', __( 'جست‌وجوهای پیشنهادی (هر خط یکی)', 'tecteb-homepage' ), $name . '[chips]', implode( "\n", $s['chips'] ), 4, sprintf( __( 'حداکثر %s واژه؛ هر کدام به جست‌وجوی همان واژه در محصولات می‌رود. خالی: نمایش داده نمی‌شود.', 'tecteb-homepage' ), Renderer::digits( Settings::MAX_CHIPS ) ) );
		echo '</table>';

		echo '<h3>' . esc_html__( 'همکاری تأمین‌کنندگان', 'tecteb-homepage' ) . '</h3>';
		echo '<p>' . esc_html__( 'مراحل همکاری باید با فرایند واقعی همین محیط یکی باشند. خالی: مراحل نمایش داده نمی‌شوند و فقط دکمهٔ درخواست همکاری می‌ماند (اگر نشانی‌اش تعیین شده باشد).', 'tecteb-homepage' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		self::input( 'tth-sup-title', __( 'عنوان', 'tecteb-homepage' ), $name . '[supplier_title]', $s['supplier_title'] );
		self::textarea( 'tth-sup-lead', __( 'متن', 'tecteb-homepage' ), $name . '[supplier_lead]', $s['supplier_lead'], 2, '' );
		echo '</table>';
		self::rows_table( $name . '[supplier_steps]', $s['supplier_steps'], Settings::MAX_STEPS );

		echo '<h3>' . esc_html__( 'فونت', 'tecteb-homepage' ) . '</h3><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'فونت صفحهٔ اصلی', 'tecteb-homepage' ) . '</th><td>';
		foreach ( array( 'bundled' => __( 'وزیرمتن، همراه افزونه (۱۱۱ کیلوبایت، طرح تأییدشده)', 'tecteb-homepage' ), 'site' => __( 'فونت فعلی سایت', 'tecteb-homepage' ) ) as $value => $label ) {
			echo '<label style="display:block"><input type="radio" name="' . esc_attr( $name . '[font]' ) . '" value="' . esc_attr( $value ) . '"' . checked( $s['font'], $value, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '</td></tr></table>';
	}

	private static function rows_table( string $field, array $rows, int $max ): void {
		echo '<table class="widefat striped" style="max-width:72rem"><thead><tr><th style="width:3rem">#</th><th>' . esc_html__( 'عنوان', 'tecteb-homepage' ) . '</th><th>' . esc_html__( 'توضیح', 'tecteb-homepage' ) . '</th></tr></thead><tbody>';
		for ( $i = 0; $i < $max; $i++ ) {
			$row = $rows[ $i ] ?? array( 'title' => '', 'text' => '' );
			echo '<tr><td>' . esc_html( Renderer::digits( $i + 1 ) ) . '</td>'
				. '<td><input type="text" class="regular-text" maxlength="50" name="' . esc_attr( $field . '[' . $i . '][title]' ) . '" value="' . esc_attr( $row['title'] ) . '" aria-label="' . esc_attr__( 'عنوان', 'tecteb-homepage' ) . '"></td>'
				. '<td><input type="text" class="large-text" maxlength="140" name="' . esc_attr( $field . '[' . $i . '][text]' ) . '" value="' . esc_attr( $row['text'] ) . '" aria-label="' . esc_attr__( 'توضیح', 'tecteb-homepage' ) . '"></td></tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'ردیفی که عنوان ندارد نادیده گرفته می‌شود.', 'tecteb-homepage' ) . '</p>';
	}

	private static function input( string $id, string $label, string $name, string $value ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><input type="text" class="large-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></td></tr>';
	}

	private static function textarea( string $id, string $label, string $name, string $value, int $rows, string $help ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><textarea class="large-text" rows="' . esc_attr( (string) $rows ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>'
			. ( '' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>';
	}

	/** @return array<string,string> */
	private static function category_icons(): array {
		return array(
			'monitor'    => __( 'پایش و مانیتورینگ', 'tecteb-homepage' ),
			'or'         => __( 'اتاق عمل', 'tecteb-homepage' ),
			'ultrasound' => __( 'تصویربرداری', 'tecteb-homepage' ),
			'lab'        => __( 'آزمایشگاه', 'tecteb-homepage' ),
			'syringe'    => __( 'تزریق و مصرفی', 'tecteb-homepage' ),
			'emergency'  => __( 'اورژانس', 'tecteb-homepage' ),
			'care'       => __( 'توان‌بخشی', 'tecteb-homepage' ),
			'dental'     => __( 'دندان‌پزشکی', 'tecteb-homepage' ),
			'store'      => __( 'فروشگاه', 'tecteb-homepage' ),
			'image'      => __( 'عمومی', 'tecteb-homepage' ),
		);
	}
}
