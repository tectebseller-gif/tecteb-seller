<?php
/**
 * ۳. فروشگاه و محصولات منتخب — محصولات «ویژه»ٔ ووکامرس (ستارهٔ فهرست محصولات).
 *
 * اگر کمتر از تعداد تنظیم‌شده محصول ویژه وجود داشت، همان تعداد نمایش داده
 * می‌شود؛ هیچ محصول دیگری جایگزین نمی‌شود.
 *
 * @var array $plan Renderer::plan()
 * @package Tecteb\Homepage
 */

use Tecteb\Homepage\Renderer;

defined( 'ABSPATH' ) || exit;

$links  = $plan['links'];
$param  = $plan['settings']['quote_product_param'];
$arrow  = Renderer::icon( 'arrow', 'th-icon th-icon--dir' );
$chips  = array_slice( $plan['categories'], 0, 4 );
$badges = array(
	'instock'     => array( 'th-badge--in', __( 'موجود', 'tecteb-homepage' ) ),
	'onbackorder' => array( 'th-badge--in', __( 'پیش‌سفارش', 'tecteb-homepage' ) ),
	'outofstock'  => array( 'th-badge--out', __( 'ناموجود', 'tecteb-homepage' ) ),
);
?>
<section class="th-section th-section--white" aria-labelledby="th-products-title">
	<div class="th-wrap">
		<header class="th-head th-reveal">
			<div class="th-head__text">
				<p class="th-eyebrow"><?php echo esc_html__( 'فروشگاه', 'tecteb-homepage' ); ?></p>
				<h2 class="th-head__title" id="th-products-title"><?php echo esc_html__( 'محصولات منتخب برای مراکز درمانی', 'tecteb-homepage' ); ?></h2>
			</div>
			<?php if ( '' !== $links['shop'] ) : ?>
				<a class="th-more" href="<?php echo esc_url( $links['shop'] ); ?>"><?php echo esc_html__( 'ورود به فروشگاه', 'tecteb-homepage' ); ?><?php echo $arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?></a>
			<?php endif; ?>
		</header>
		<?php if ( array() !== $chips ) : ?>
			<nav class="th-filter th-reveal" aria-label="<?php echo esc_attr__( 'رفتن به دسته‌ها', 'tecteb-homepage' ); ?>">
				<ul class="th-filter__list" role="list">
					<?php if ( '' !== $links['shop'] ) : ?>
						<li><a class="th-chip th-chip--on" href="<?php echo esc_url( $links['shop'] ); ?>"><?php echo esc_html__( 'همه', 'tecteb-homepage' ); ?></a></li>
					<?php endif; ?>
					<?php foreach ( $chips as $category ) : ?>
						<li><a class="th-chip" href="<?php echo esc_url( $category['url'] ); ?>"><?php echo esc_html( $category['name'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>
		<?php endif; ?>
		<ul class="th-products" role="list" data-count="<?php echo esc_attr( (string) count( $plan['products'] ) ); ?>">
			<?php foreach ( $plan['products'] as $product ) : ?>
				<?php $badge = $badges[ $product['stock'] ] ?? null; ?>
				<li class="th-reveal"><article class="th-product">
					<div class="th-product__media<?php echo '' === $product['image'] ? ' th-product__media--empty' : ''; ?>">
						<?php if ( '' !== $product['image'] ) : ?>
							<span class="th-product__pic"><?php echo $product['image']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- خروجی wp_get_attachment_image(). ?></span>
						<?php else : ?>
							<?php echo Renderer::icon( 'image', 'th-product__art' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?>
							<span class="th-product__empty"><?php echo esc_html__( 'بدون تصویر', 'tecteb-homepage' ); ?></span>
						<?php endif; ?>
						<?php if ( null !== $badge ) : ?>
							<span class="th-badge <?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span>
						<?php endif; ?>
					</div>
					<div class="th-product__body">
						<?php if ( '' !== $product['category'] ) : ?>
							<p class="th-product__cat"><?php echo esc_html( $product['category'] ); ?></p>
						<?php endif; ?>
						<h3 class="th-product__title"><a href="<?php echo esc_url( $product['url'] ); ?>"><?php echo esc_html( $product['title'] ); ?></a></h3>
						<?php if ( array() !== $product['specs'] ) : ?>
							<ul class="th-product__specs" role="list" aria-label="<?php echo esc_attr__( 'مشخصات کلیدی', 'tecteb-homepage' ); ?>">
								<?php foreach ( $product['specs'] as $spec ) : ?>
									<li><?php echo esc_html( $spec ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<div class="th-product__foot">
							<?php if ( 'outofstock' === $product['stock'] ) : ?>
								<p class="th-product__price th-product__price--out"><?php echo esc_html__( 'فعلاً ناموجود', 'tecteb-homepage' ); ?></p>
							<?php elseif ( '' !== $product['price'] ) : ?>
								<p class="th-product__price"><?php echo wp_kses_post( $product['price'] ); ?></p>
							<?php else : ?>
								<p class="th-product__price th-product__price--ask"><?php echo esc_html__( 'قیمت اعلام نشده', 'tecteb-homepage' ); ?></p>
							<?php endif; ?>
							<?php if ( '' !== $links['quote'] ) : ?>
								<a class="th-product__quote" href="<?php echo esc_url( Renderer::quote_url( $links['quote'], $product['id'], $param ) ); ?>"><?php echo esc_html__( 'استعلام سازمانی', 'tecteb-homepage' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
				</article></li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
