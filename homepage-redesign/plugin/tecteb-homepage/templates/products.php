<?php
/**
 * ۳. فروشگاه و محصولات منتخب — محصولاتی که مدیر در «ووکامرس ← صفحهٔ اصلی»
 * انتخاب و مرتب کرده، با داده‌های زندهٔ ووکامرس (نام، تصویر، قیمت، موجودی،
 * ویژگی‌ها، پیوند). محصولی که حذف یا پنهان شده کنار می‌رود و چیزی جایش نمی‌آید.
 *
 * @var array $plan Renderer::plan()
 * @package Tecteb\Homepage
 */

use Tecteb\Homepage\Renderer;

defined( 'ABSPATH' ) || exit;

$links  = $plan['links'];
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
							<?php elseif ( 'text' === $product['price']['kind'] ) : ?>
								<p class="th-product__price th-product__price--ask"><?php echo esc_html( $product['price']['text'] ); ?></p>
							<?php elseif ( 'none' !== $product['price']['kind'] ) : ?>
								<?php $price = $product['price']; ?>
								<p class="th-product__price th-price th-price--<?php echo esc_attr( $price['kind'] ); ?>">
									<?php if ( 'sale' === $price['kind'] ) : ?>
										<span class="th-price__part"><span class="th-visually-hidden"><?php echo esc_html__( 'قیمت قبلی:', 'tecteb-homepage' ); ?></span><del class="th-price__was"><?php echo Renderer::price_html( $price['was'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses در Renderer::price_html(). ?></del></span>
										<span class="th-price__part"><span class="th-visually-hidden"><?php echo esc_html__( 'قیمت فعلی:', 'tecteb-homepage' ); ?></span><ins class="th-price__now"><?php echo Renderer::price_html( $price['now'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses در Renderer::price_html(). ?></ins></span>
									<?php elseif ( 'range' === $price['kind'] ) : ?>
										<span class="th-price__part"><span class="th-price__word"><?php echo esc_html__( 'از', 'tecteb-homepage' ); ?></span> <span class="th-price__now"><?php echo Renderer::price_html( $price['from'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses در Renderer::price_html(). ?></span></span>
										<span class="th-price__part"><span class="th-price__word"><?php echo esc_html__( 'تا', 'tecteb-homepage' ); ?></span> <span class="th-price__now"><?php echo Renderer::price_html( $price['to'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses در Renderer::price_html(). ?></span></span>
									<?php else : ?>
										<span class="th-price__part"><span class="th-price__now"><?php echo Renderer::price_html( $price['now'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses در Renderer::price_html(). ?></span></span>
									<?php endif; ?>
									<?php if ( isset( $price['suffix'] ) ) : ?>
										<span class="th-price__suffix"><?php echo esc_html( $price['suffix'] ); ?></span>
									<?php endif; ?>
								</p>
							<?php else : ?>
								<p class="th-product__price th-product__price--ask"><?php echo esc_html__( 'قیمت اعلام نشده', 'tecteb-homepage' ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				</article></li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
