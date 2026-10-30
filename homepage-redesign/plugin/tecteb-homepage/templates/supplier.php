<?php
/**
 * ۵. دعوت به همکاری تأمین‌کنندگان — فقط با نشانی تأییدشدهٔ همین محیط.
 *
 * مراحل همکاری پیش‌فرض ندارند: باید با فرایند واقعی همان محیط (ثبت‌نام فعلی،
 * یا مسیر درخواست فروشندگی بازارگاه) یکی باشند، پس متنشان را مدیر می‌نویسد.
 *
 * @var array $plan Renderer::plan()
 * @package Tecteb\Homepage
 */

use Tecteb\Homepage\Renderer;

defined( 'ABSPATH' ) || exit;

$settings     = $plan['settings'];
$links = $plan['links'];
$steps = $settings['supplier_steps'];
?>
<section class="th-section th-section--mist th-section--join" aria-labelledby="th-supplier-title">
	<div class="th-wrap">
		<div class="th-join th-reveal<?php echo array() === $steps ? ' th-join--solo' : ''; ?>">
			<div class="th-join__light" aria-hidden="true"></div>
			<div class="th-join__copy">
				<p class="th-eyebrow th-eyebrow--light"><img class="th-eyebrow__logo" src="<?php echo esc_url( TECTEB_HOME_URL . 'assets/img/tecteb-logo-mark-96.png' ); ?>" width="20" height="20" alt=""><?php echo esc_html__( 'برای تأمین‌کنندگان', 'tecteb-homepage' ); ?></p>
				<h2 class="th-join__title" id="th-supplier-title"><?php echo esc_html( $settings['supplier_title'] ); ?></h2>
				<?php if ( '' !== $settings['supplier_lead'] ) : ?>
					<p class="th-join__lead"><?php echo esc_html( $settings['supplier_lead'] ); ?></p>
				<?php endif; ?>
				<div class="th-join__actions">
					<a class="th-btn th-btn--light" href="<?php echo esc_url( $links['supplier'] ); ?>"><span><?php echo esc_html__( 'درخواست همکاری', 'tecteb-homepage' ); ?></span><?php echo Renderer::icon( 'arrow', 'th-icon th-icon--dir' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?></a>
					<?php if ( '' !== $links['supplier_terms'] ) : ?>
						<a class="th-btn th-btn--ghost" href="<?php echo esc_url( $links['supplier_terms'] ); ?>"><?php echo esc_html__( 'شرایط همکاری', 'tecteb-homepage' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( array() !== $steps ) : ?>
				<ol class="th-steps">
					<?php foreach ( $steps as $i => $step ) : ?>
						<li class="th-step th-glass"><span class="th-step__n" aria-hidden="true"><?php echo esc_html( Renderer::digits( $i + 1 ) ); ?></span><span><strong><?php echo esc_html( $step['title'] ); ?></strong><?php echo esc_html( $step['text'] ); ?></span></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
	</div>
</section>
