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
$categories = $plan['categories'];
$floats     = array_slice( $categories, 0, 2 );
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
			<p class="th-pill th-in" style="--th-i:0"><img class="th-pill__logo" src="<?php echo esc_url( $img . 'tecteb-logo-mark-96.png' ); ?>" width="24" height="24" alt="<?php echo esc_attr__( 'تک‌طب', 'tecteb-homepage' ); ?>"><?php echo esc_html__( 'بازار B2B تجهیزات پزشکی', 'tecteb-homepage' ); ?></p>
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
				<?php if ( array() !== $categories ) : ?>
					<span class="th-search__sep" aria-hidden="true"></span>
					<div class="th-search__scope">
						<label class="th-visually-hidden" for="th-c-scope"><?php echo esc_html__( 'در دستهٔ', 'tecteb-homepage' ); ?></label>
						<select class="th-search__select" id="th-c-scope" name="product_cat">
							<option value=""><?php echo esc_html__( 'همهٔ دسته‌ها', 'tecteb-homepage' ); ?></option>
							<?php foreach ( $categories as $category ) : ?>
								<option value="<?php echo esc_attr( rawurldecode( $category['slug'] ) ); ?>"><?php echo esc_html( $category['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>
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
			<?php foreach ( $floats as $i => $category ) : ?>
				<a class="th-float <?php echo 0 === $i ? 'th-float--one' : 'th-float--two'; ?>" href="<?php echo esc_url( $category['url'] ); ?>">
					<?php echo Renderer::icon( $category['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?><?php echo esc_html( $category['name'] ); ?>
				</a>
			<?php endforeach; ?>
		</figure>
	</div>
	<?php if ( '' !== $links['quote'] || '' !== $links['supplier'] ) : ?>
		<div class="th-wrap th-routes">
			<?php if ( '' !== $links['quote'] ) : ?>
				<a class="th-route th-route--buyer" href="<?php echo esc_url( $links['quote'] ); ?>">
					<span class="th-route__icon"><?php echo Renderer::icon( 'quote', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?></span>
					<span class="th-route__text">
						<span class="th-route__kicker"><?php echo esc_html__( 'برای بیمارستان‌ها، کلینیک‌ها و واحدهای خرید', 'tecteb-homepage' ); ?></span>
						<span class="th-route__title"><?php echo esc_html__( 'درخواست تأمین', 'tecteb-homepage' ); ?></span>
					</span>
					<span class="th-route__go"><?php echo $arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?></span>
				</a>
			<?php endif; ?>
			<?php if ( '' !== $links['supplier'] ) : ?>
				<a class="th-route th-route--supplier" href="<?php echo esc_url( $links['supplier'] ); ?>">
					<span class="th-route__icon"><?php echo Renderer::icon( 'network', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?></span>
					<span class="th-route__text">
						<span class="th-route__kicker"><?php echo esc_html__( 'برای تولیدکنندگان و واردکنندگان', 'tecteb-homepage' ); ?></span>
						<span class="th-route__title"><?php echo esc_html__( 'همکاری تأمین‌کنندگان', 'tecteb-homepage' ); ?></span>
					</span>
					<span class="th-route__go"><?php echo $arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?></span>
				</a>
			<?php endif; ?>
		</div>
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
