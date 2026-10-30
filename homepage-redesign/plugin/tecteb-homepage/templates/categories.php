<?php
/**
 * ۲. دسته‌بندی‌های منتخب — همان دسته‌هایی که مدیر در «ووکامرس ← صفحهٔ اصلی» انتخاب و مرتب کرده.
 *
 * @var array $plan Renderer::plan()
 * @package Tecteb\Homepage
 */

use Tecteb\Homepage\Renderer;

defined( 'ABSPATH' ) || exit;

$arrow = Renderer::icon( 'arrow', 'th-icon th-icon--dir' );
?>
<section class="th-section th-section--mist" aria-labelledby="th-cats-title">
	<div class="th-wrap">
		<header class="th-head th-reveal">
			<div class="th-head__text">
				<p class="th-eyebrow"><?php echo esc_html__( 'دسته‌بندی‌ها', 'tecteb-homepage' ); ?></p>
				<h2 class="th-head__title" id="th-cats-title"><?php echo esc_html__( 'از دستهٔ درست شروع کنید', 'tecteb-homepage' ); ?></h2>
			</div>
			<?php if ( '' !== $plan['links']['all_categories'] ) : ?>
				<a class="th-more" href="<?php echo esc_url( $plan['links']['all_categories'] ); ?>"><?php echo esc_html__( 'همهٔ دسته‌ها', 'tecteb-homepage' ); ?><?php echo $arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?></a>
			<?php endif; ?>
		</header>
		<ul class="th-cats" role="list">
			<?php foreach ( $plan['categories'] as $category ) : ?>
				<li class="th-reveal"><a class="th-cat" href="<?php echo esc_url( $category['url'] ); ?>">
					<span class="th-orb">
						<?php
						if ( '' !== $category['image'] ) {
							echo $category['image']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- خروجی wp_get_attachment_image().
						} else {
							echo Renderer::icon( $category['icon'], '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon().
						}
						?>
					</span>
					<span class="th-cat__name"><?php echo esc_html( $category['name'] ); ?></span>
					<?php if ( '' !== $category['note'] ) : ?>
						<span class="th-cat__meta"><?php echo esc_html( $category['note'] ); ?></span>
					<?php endif; ?>
					<?php echo Renderer::icon( 'arrow', 'th-icon th-icon--dir th-cat__go' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?>
				</a></li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
