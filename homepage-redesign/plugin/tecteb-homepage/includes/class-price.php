<?php
/**
 * قیمت یک محصول برای کارت صفحهٔ اصلی — از API ِ خودِ ووکامرس، بی هیچ عدد یا واحد ساختگی (۰٫۳٫۲).
 *
 * چرا نه خودِ get_price_html():
 * ووکامرس در بازهٔ قیمت (محصول متغیر و گروهی) و در تخفیف، کنار عددهای دیدنی یک متن مخصوص صفحه‌خوان
 * می‌گذارد — `<span class="screen-reader-text">Price range: X through Y</span>` و «Original price was: …» /
 * «Current price is: …» (wc_format_price_range و wc_format_sale_price در includes/wc-formatting-functions.php).
 * پنهان‌ماندن آن متن کار CSS ِ قالب است، و لایهٔ ایزولهٔ ۰٫۳٫۰ (`all: revert` روی هر عنصر داخل صفحهٔ
 * اصلی، تا CSS ِ سراسری سایت به طرح نرسد) همان را هم برمی‌گرداند؛ پس متن دیدنی شد، و انگلیسی، چون آن
 * رشته در بستهٔ فارسی ترجمه نشده بود. پنهان‌کردن دوبارهٔ آن با CSS متن انگلیسی را برای صفحه‌خوان نگه می‌داشت
 * و چیدمانِ «عدد – عدد» را هم درست نمی‌کرد.
 *
 * پس این کلاس همان تصمیم‌های get_price_html() را با همان توابع می‌گیرد — get_variation_prices(true)،
 * wc_get_price_to_display()، is_on_sale()، get_price_suffix() و wc_price() (واحد پول، جای نماد، رقم
 * اعشار و جداکننده‌ها همه از تنظیمات ووکامرس) — و ساختار را خودش می‌سازد: «از X تا Y»، «قیمت قبلی /
 * قیمت فعلی» با برچسب فارسی برای صفحه‌خوان.
 *
 * و یک گارد: خروجیِ get_price_html() ِ همان محصول همچنان مرجع است، چون فیلترهای سایت (پنهان‌کردن قیمت
 * برای مهمان، «استعلام قیمت»، افزونه‌های دیگر) آنجا اعمال می‌شوند. اگر ووکامرس هیچ قیمتی نشان نمی‌دهد،
 * این‌جا هم نه؛ اگر متن دیگری نشان می‌دهد («استعلام قیمت»)، همان متن؛ و اگر عددی که ما می‌سازیم در
 * خروجی ووکامرس نیست (فیلتری آن را عوض کرده)، متنِ دیدنیِ خودِ ووکامرس — هرگز عددی که سایت نشان نمی‌دهد.
 *
 * @package Tecteb\Homepage
 */

namespace Tecteb\Homepage;

defined( 'ABSPATH' ) || exit;

final class Price {

	/**
	 * @return array{kind:string,now?:string,was?:string,from?:string,to?:string,text?:string,suffix?:string}
	 *         kind: amount | sale | range | text | none. مبلغ‌ها HTML ِ wc_price() اند، با قالب display_args().
	 */
	public static function of( \WC_Product $product ): array {
		$shown = self::visible_text( (string) $product->get_price_html() );
		if ( '' === $shown ) {
			return array( 'kind' => 'none' );
		}
		$model = self::model( $product );
		if ( null === $model ) {
			return array( 'kind' => 'text', 'text' => $shown );
		}
		$amounts = array();
		$args    = self::display_args();
		foreach ( array( 'now', 'was', 'from', 'to' ) as $key ) {
			if ( isset( $model[ $key ] ) ) {
				// گارد با قالب خودِ سایت سنجیده می‌شود؛ نمایش با قالب صفحهٔ اصلی (display_args()).
				if ( ! str_contains( $shown, self::visible_text( wc_price( $model[ $key ] ) ) ) ) {
					return array( 'kind' => 'text', 'text' => $shown );
				}
				$amounts[ $key ] = wc_price( $model[ $key ], $args );
			}
		}
		$suffix = self::visible_text( (string) $product->get_price_suffix() );
		$out    = array( 'kind' => $model['kind'] ) + $amounts;
		if ( '' !== $suffix && str_contains( $shown, $suffix ) ) {
			$out['suffix'] = $suffix;
		}
		return $out;
	}

	/** یک خط متن ساده (صفحهٔ تنظیمات): مبلغ، «از … تا …» یا «… (قیمت قبلی …)»، با واحد پولِ خودِ ووکامرس. */
	public static function text( \WC_Product $product ): string {
		$p = self::of( $product );
		switch ( $p['kind'] ) {
			case 'amount':
				$s = self::visible_text( $p['now'] );
				break;
			case 'sale':
				/* translators: 1: current price 2: regular price */
				$s = sprintf( __( '%1$s (قیمت قبلی %2$s)', 'tecteb-homepage' ), self::visible_text( $p['now'] ), self::visible_text( $p['was'] ) );
				break;
			case 'range':
				/* translators: 1: lowest price 2: highest price */
				$s = sprintf( __( 'از %1$s تا %2$s', 'tecteb-homepage' ), self::visible_text( $p['from'] ), self::visible_text( $p['to'] ) );
				break;
			case 'text':
				return $p['text'];
			default:
				return '';
		}
		return isset( $p['suffix'] ) ? $s . ' ' . $p['suffix'] : $s;
	}

	/**
	 * همان تصمیم‌های get_price_html() ِ ووکامرس ۱۱ (abstract-wc-product.php، class-wc-product-variable.php،
	 * class-wc-product-grouped.php)، با عدد به‌جای HTML. null یعنی ووکامرس عددی ندارد که نشان دهد.
	 *
	 * @return array{kind:string,now?:float|string,was?:float|string,from?:float|string,to?:float|string}|null
	 */
	private static function model( \WC_Product $product ): ?array {
		if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_prices' ) ) {
			$prices = $product->get_variation_prices( true );
			if ( empty( $prices['price'] ) ) {
				return null;
			}
			$min     = current( $prices['price'] );
			$max     = end( $prices['price'] );
			$min_reg = current( $prices['regular_price'] );
			$max_reg = end( $prices['regular_price'] );
			if ( $min !== $max ) {
				return array( 'kind' => 'range', 'from' => $min, 'to' => $max );
			}
			if ( $product->is_on_sale() && $min_reg === $max_reg ) {
				return array( 'kind' => 'sale', 'was' => $max_reg, 'now' => $min );
			}
			return array( 'kind' => 'amount', 'now' => $min );
		}

		if ( $product->is_type( 'grouped' ) ) {
			$child_prices = array();
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child instanceof \WC_Product && $child->is_visible() && '' !== $child->get_price() ) {
					$child_prices[] = wc_get_price_to_display( $child );
				}
			}
			if ( array() === $child_prices ) {
				return null;
			}
			$min = min( $child_prices );
			$max = max( $child_prices );
			// اگر ووکامرس «Free!» نشان دهد، «۰» ِ ما در متن او نیست و گارد of() متن خودش را می‌آورد.
			return $min !== $max ? array( 'kind' => 'range', 'from' => $min, 'to' => $max ) : array( 'kind' => 'amount', 'now' => $min );
		}

		if ( '' === $product->get_price() ) {
			return null;
		}
		if ( $product->is_on_sale() ) {
			return array(
				'kind' => 'sale',
				'was'  => wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) ),
				'now'  => wc_get_price_to_display( $product ),
			);
		}
		return array( 'kind' => 'amount', 'now' => wc_get_price_to_display( $product ) );
	}

	/**
	 * ۰٫۳٫۳ — قالب عدد فقط برای کارت‌های صفحهٔ اصلی: جداکنندهٔ هزارگان «,» و فاصله بین عدد و واحد پول.
	 * روی Staging جداکنندهٔ هزارگان ووکامرس خالی است و نماد بی فاصله بعد از عدد (`5634400تومان`).
	 *
	 * این‌ها آرگومان‌های خودِ wc_price() اند و فقط در همین فراخوان اثر دارند: هیچ فیلتر سراسری ووکامرس
	 * (wc_price_args، woocommerce_price_format، …) اضافه یا عوض نمی‌شود، پس قیمت در فروشگاه، صفحهٔ محصول،
	 * سبد و سفارش همان است که بود. مقدار، واحد پول، نماد، تعداد اعشار، جداکنندهٔ اعشار و مالیات همه از
	 * ووکامرس می‌آیند و دست نمی‌خورند. دو استثنا، هر دو برای اینکه عدد غلط خوانده نشود:
	 * - اگر جداکنندهٔ اعشار خودش «,» است (و اعشار نمایش داده می‌شود)، جداکنندهٔ هزارگان ِ سایت می‌ماند؛
	 * - فاصله فقط وقتی اضافه می‌شود که نماد **بعد از** عدد است (right ⇒ right_space)؛ نمادِ پیش از عدد
	 *   (مثل «$») همان‌طور که سایت تعیین کرده می‌ماند.
	 *
	 * @return array{thousand_separator:string,price_format:string}
	 */
	public static function display_args(): array {
		$thousand = ',';
		if ( wc_get_price_decimals() > 0 && ',' === wc_get_price_decimal_separator() ) {
			$thousand = wc_get_price_thousand_separator();
		}
		$format = get_woocommerce_price_format();
		if ( 'right' === get_option( 'woocommerce_currency_pos' ) ) {
			$format = '%2$s&nbsp;%1$s';
		}
		return array(
			'thousand_separator' => $thousand,
			'price_format'       => $format,
		);
	}

	/**
	 * متنی که بیننده می‌بیند: بی متن مخصوص صفحه‌خوانِ ووکامرس، بی برچسب، با فاصله‌های یکی‌شده.
	 */
	private static function visible_text( string $html ): string {
		$html = (string) preg_replace( '#<span[^>]*class="[^"]*\bscreen-reader-text\b[^"]*"[^>]*>.*?</span>#su', '', $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
