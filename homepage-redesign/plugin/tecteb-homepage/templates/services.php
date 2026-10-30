<?php
/**
 * ۴. خدمات B2B — فقط وقتی مدیر بخش را روشن و دست‌کم یک خدمتِ تأییدشده وارد کرده.
 *
 * هیچ خدمتی در افزونه پیش‌فرض ندارد: متن هر کارت همان است که مدیر نوشته.
 * دکمهٔ «ثبت درخواست تأمین» فقط با نشانی تأییدشدهٔ استعلام چاپ می‌شود.
 *
 * @var array $plan Renderer::plan()
 * @package Tecteb\Homepage
 */

use Tecteb\Homepage\Renderer;

defined( 'ABSPATH' ) || exit;

$settings = $plan['settings'];
?>
<section class="th-section th-section--deep" aria-labelledby="th-services-title">
	<div class="th-deep__bg" aria-hidden="true"></div>
	<div class="th-wrap th-services">
		<header class="th-services__head th-reveal">
			<p class="th-eyebrow th-eyebrow--light"><?php echo esc_html__( 'خرید سازمانی', 'tecteb-homepage' ); ?></p>
			<h2 class="th-head__title th-head__title--light" id="th-services-title"><?php echo esc_html( $settings['services_title'] ); ?></h2>
			<?php if ( '' !== $settings['services_lead'] ) : ?>
				<p class="th-services__lead"><?php echo esc_html( $settings['services_lead'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $plan['links']['quote'] ) : ?>
				<a class="th-btn th-btn--primary" href="<?php echo esc_url( $plan['links']['quote'] ); ?>">
					<span><?php echo esc_html__( 'ثبت درخواست تأمین', 'tecteb-homepage' ); ?></span>
					<?php echo Renderer::icon( 'arrow', 'th-icon th-icon--dir' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?>
				</a>
			<?php endif; ?>
		</header>
		<ul class="th-services__grid" role="list">
			<?php foreach ( $settings['services'] as $service ) : ?>
				<li class="th-glass th-service th-reveal">
					<span class="th-service__icon"><?php echo Renderer::icon( $service['icon'], '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?></span>
					<h3 class="th-service__title"><?php echo esc_html( $service['title'] ); ?></h3>
					<?php if ( '' !== $service['text'] ) : ?>
						<p class="th-service__text"><?php echo esc_html( $service['text'] ); ?></p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
