<?php
/**
 * ۱. معرفی و جست‌وجوی محصولات (Hero تیره).
 *
 * @var array $plan Renderer::plan()
 * @package Tecteb\Homepage
 */

use Tecteb\Homepage\Renderer;

defined( 'ABSPATH' ) || exit;

$settings          = $plan['settings'];
$links      = $plan['links'];
// ۰٫۳٫۳: چهار برچسب ثابتِ کنار تصویر (Catalog::hero_labels())، فقط آن‌هایی که دستهٔ واقعیِ دارای محصول دارند.
$floats     = array_filter( $plan['hero_labels'], static fn( array $label ): bool => 'ok' === $label['status'] );
$slots      = array( 'one', 'two', 'three', 'four' );
$img        = TECTEB_HOME_URL . 'assets/img/';
$arrow      = Renderer::icon( 'arrow', 'th-icon th-icon--dir' );
?>
<section class="th-hero th-hero--dark" aria-labelledby="th-hero-title" data-th-light>
	<div class="th-hero__bg" aria-hidden="true">
		<span class="th-hero__glow th-hero__glow--a"></span>
		<span class="th-hero__glow th-hero__glow--b"></span>
		<span class="th-hero__grid"></span>
		<span class="th-hero__pointer"></span>
	</div>
	<div class="th-wrap th-hero__layout">
		<div class="th-hero__copy">
			<h1 class="th-hero__title th-in" id="th-hero-title" style="--th-i:1">
				<span class="th-hero__line"><?php echo esc_html__( 'تجهیزات پزشکی را دقیق پیدا کنید،', 'tecteb-homepage' ); ?></span>
				<span class="th-hero__line th-hero__line--em"><?php echo esc_html__( 'با اطمینان تأمین کنید.', 'tecteb-homepage' ); ?></span>
			</h1>
			<?php if ( '' !== $settings['hero_lead'] ) : ?>
				<p class="th-hero__lead th-in" style="--th-i:2"><?php echo esc_html( $settings['hero_lead'] ); ?></p>
			<?php endif; ?>
			<form class="th-search th-in" style="--th-i:3" role="search" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
				<div class="th-search__field">
					<label class="th-visually-hidden" for="th-c-search"><?php echo esc_html__( 'جست‌وجوی محصولات', 'tecteb-homepage' ); ?></label>
					<?php echo Renderer::icon( 'search', 'th-icon th-search__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ساخته‌شده و escape‌شده در Renderer::icon(). ?>
					<input class="th-search__input" id="th-c-search" type="search" name="s" placeholder="<?php echo esc_attr__( 'نام محصول را جست‌وجو کنید…', 'tecteb-homepage' ); ?>" autocomplete="off" enterkeyhint="search">
				</div>
				<input type="hidden" name="post_type" value="product">
				<button class="th-btn th-btn--primary th-search__submit" type="submit">
					<span><?php echo esc_html__( 'جست‌وجو', 'tecteb-homepage' ); ?></span>
					<?php echo $arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?>
				</button>
			</form>
			<?php if ( array() !== $settings['chips'] ) : ?>
				<div class="th-quick th-in" style="--th-i:4">
					<span class="th-quick__label"><?php echo esc_html__( 'پرجست‌وجو', 'tecteb-homepage' ); ?></span>
					<ul class="th-quick__list" role="list">
						<?php foreach ( $settings['chips'] as $chip ) : ?>
							<li><a class="th-chip th-chip--glass" href="<?php echo esc_url( Renderer::search_url( $chip ) ); ?>"><?php echo esc_html( $chip ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<figure class="th-hero__art">
			<picture class="th-hero__render">
				<source type="image/webp" media="(max-width: 699px)" srcset="<?php echo esc_url( $img . 'hero-3d-dark-720.webp' ); ?>">
				<img class="skip-lazy" data-no-lazy="1" src="<?php echo esc_url( $img . 'hero-3d-dark-1200.webp' ); ?>" width="1200" height="960" alt="" fetchpriority="high" decoding="async">
			</picture>
			<?php if ( array() !== $floats ) : ?>
				<ul class="th-floats" role="list" aria-label="<?php echo esc_attr__( 'دسته‌های پرکاربرد', 'tecteb-homepage' ); ?>">
					<?php foreach ( $floats as $i => $label ) : ?>
						<li class="th-floats__item"><a class="th-float th-float--<?php echo esc_attr( $slots[ $i ] ); ?>" href="<?php echo esc_url( $label['url'] ); ?>"><?php echo Renderer::icon( $label['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?><?php echo esc_html( $label['name'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</figure>
	</div>
	<?php
	// سه مسیر اصلی: فروشگاه، درخواست تعمیرات، همکاری تأمین‌کنندگان — هر کدام فقط
	// با مقصد معتبر. مسیری که مقصد ندارد اصلاً چاپ نمی‌شود، نه به‌صورت دکمهٔ مرده.
	$routes = array(
		'shop'     => array( 'store', __( 'همهٔ محصولات و دسته‌های تجهیزات پزشکی', 'tecteb-homepage' ), __( 'فروشگاه', 'tecteb-homepage' ) ),
		'repair'   => array( 'wrench', __( 'برای بیمارستان‌ها، کلینیک‌ها و آزمایشگاه‌ها', 'tecteb-homepage' ), __( 'درخواست تعمیرات تجهیزات پزشکی', 'tecteb-homepage' ) ),
		'supplier' => array( 'network', __( 'برای تولیدکنندگان و واردکنندگان', 'tecteb-homepage' ), __( 'همکاری تأمین‌کنندگان', 'tecteb-homepage' ) ),
	);
	$shown  = array_filter( $routes, static fn( string $key ): bool => $plan['routes'][ $key ]['show'], ARRAY_FILTER_USE_KEY );
	?>
	<?php if ( array() !== $shown ) : ?>
		<nav class="th-wrap th-routes" data-count="<?php echo esc_attr( (string) count( $shown ) ); ?>" aria-label="<?php echo esc_attr__( 'مسیرهای اصلی تک‌طب', 'tecteb-homepage' ); ?>">
			<?php foreach ( $shown as $key => $route ) : ?>
				<a class="th-route th-route--<?php echo esc_attr( $key ); ?>" href="<?php echo esc_url( $links[ $key ] ); ?>">
					<span class="th-route__icon"><?php echo Renderer::icon( $route[0], '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?></span>
					<span class="th-route__text">
						<span class="th-route__kicker"><?php echo esc_html( $route[1] ); ?></span>
						<span class="th-route__title"><?php echo esc_html( $route[2] ); ?></span>
					</span>
					<span class="th-route__go"><?php echo $arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>
	<?php if ( array() !== $settings['audience'] ) : ?>
		<div class="th-wrap">
			<p class="th-audience">
				<?php if ( '' !== $settings['audience_label'] ) : ?>
					<span class="th-audience__label"><?php echo esc_html( $settings['audience_label'] ); ?></span>
				<?php endif; ?>
				<?php foreach ( $settings['audience'] as $who ) : ?>
					<span><?php echo esc_html( $who ); ?></span>
				<?php endforeach; ?>
			</p>
		</div>
	<?php endif; ?>
</section>
