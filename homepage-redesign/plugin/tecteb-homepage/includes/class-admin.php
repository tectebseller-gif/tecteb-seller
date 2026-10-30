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

	public static function register_hooks(): void {
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
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
		add_submenu_page(
			'woocommerce',
			__( 'صفحهٔ اصلی تک‌طب', 'tecteb-homepage' ),
			__( 'صفحهٔ اصلی', 'tecteb-homepage' ),
			self::capability(),
			self::PAGE,
			array( self::class, 'render' )
		);
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

		echo '<div class="wrap" dir="rtl">';
		echo '<h1>' . esc_html__( 'صفحهٔ اصلی تک‌طب', 'tecteb-homepage' ) . '</h1>';
		echo '<p>' . esc_html__( 'این تنظیمات فقط نمایش صفحهٔ اصلی را تعیین می‌کنند. هیچ محصول، دسته، سفارش یا فروشنده‌ای اینجا تغییر نمی‌کند.', 'tecteb-homepage' ) . '</p>';
		settings_errors( Settings::OPTION );

		self::status( $plan );

		echo '<form id="tth-search" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"></form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( Settings::GROUP );
		submit_button( __( 'ذخیرهٔ تنظیمات', 'tecteb-homepage' ) );

		self::products_section( $s, $name, $pq );
		self::categories_section( $s, $name, $query );
		self::links_section( $name );
		self::content_section( $s, $name );

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

		$front = (int) get_option( 'page_on_front' );
		if ( 'page' === get_option( 'show_on_front' ) && $front > 0 ) {
			$post = get_post( $front );
			$uses = $post instanceof \WP_Post && ( has_shortcode( (string) $post->post_content, Plugin::SHORTCODE ) || PageTemplate::FILE === get_page_template_slug( $post ) || str_contains( (string) get_post_meta( $front, '_elementor_data', true ), '[' . Plugin::SHORTCODE ) );
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: page title */
					$uses ? __( 'صفحهٔ اصلی فعلی سایت («%s») همین طرح را نشان می‌دهد.', 'tecteb-homepage' ) : __( 'صفحهٔ اصلی فعلی سایت («%s») هنوز این طرح را نشان نمی‌دهد. این افزونه صفحهٔ اصلی را عوض نمی‌کند؛ تعویض با «تنظیمات ← خواندن» است.', 'tecteb-homepage' ),
					$post instanceof \WP_Post ? get_the_title( $post ) : '#' . $front
				)
			) . '</p>';
		}
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

	private static function links_section( string $name ): void {
		$fields = array(
			'repair'         => array( __( 'درخواست تعمیرات تجهیزات پزشکی', 'tecteb-homepage' ), __( 'نشانی صفحهٔ تعمیرات موجود در سایت. خالی: کارت تعمیرات نمایش داده نمی‌شود.', 'tecteb-homepage' ) ),
			'supplier'       => array( __( 'همکاری تأمین‌کنندگان', 'tecteb-homepage' ), __( 'خالی: کارت همکاری در Hero و بخش همکاری نمایش داده نمی‌شوند. نشانی همین محیط را بنویسید؛ ثبت‌نام فعلی یا مسیر بازارگاه پس از تأیید.', 'tecteb-homepage' ) ),
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
