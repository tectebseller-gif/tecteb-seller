<?php
/**
 * ۶. برندهای همکار (۰٫۳٫۳) — بلافاصله پس از دعوت تأمین‌کنندگان و پیش از فوتر سایت.
 *
 * فقط برندهایی که مدیر در «ووکامرس ← صفحهٔ اصلی» ثبت و فعال کرده و لوگویشان امروز تصویرِ کتابخانهٔ
 * رسانه است (Catalog::brands()). بی هیچ برندی این بخش اصلاً چاپ نمی‌شود (Renderer::plan()).
 *
 * کاروسل یک فهرست افقی با اسکرول بومی است: لمس و چرخ و کلیدهای جهت بی اسکریپت کار می‌کنند، و
 * دکمه‌های «قبلی/بعدی» با اسکریپت (tecteb-home.js) ظاهر می‌شوند — بی اسکریپت پنهان می‌مانند تا دکمهٔ
 * مرده‌ای نباشد. هیچ حرکت خودکاری نیست.
 *
 * @var array $plan Renderer::plan()
 * @package Tecteb\Homepage
 */

use Tecteb\Homepage\Renderer;

defined( 'ABSPATH' ) || exit;

$brands = $plan['brands'];
$arrow  = Renderer::icon( 'arrow', 'th-icon th-icon--dir' );
?>
<section class="th-section th-section--white th-section--brands" aria-labelledby="th-brands-title">
	<div class="th-wrap">
		<header class="th-head th-head--brands">
			<div class="th-head__text">
				<h2 class="th-head__title" id="th-brands-title"><?php echo esc_html__( 'برندهای همکار', 'tecteb-homepage' ); ?></h2>
			</div>
			<div class="th-brands__nav" data-th-brands-nav hidden>
				<button type="button" class="th-brands__btn th-brands__btn--prev" data-th-brands-dir="-1" aria-controls="th-brands-list"><span class="th-visually-hidden"><?php echo esc_html__( 'برندهای قبلی', 'tecteb-homepage' ); ?></span><?php echo $arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?></button>
				<button type="button" class="th-brands__btn th-brands__btn--next" data-th-brands-dir="1" aria-controls="th-brands-list"><span class="th-visually-hidden"><?php echo esc_html__( 'برندهای بعدی', 'tecteb-homepage' ); ?></span><?php echo $arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::icon(). ?></button>
			</div>
		</header>
		<div class="th-brands" id="th-brands-list" data-th-brands tabindex="0" role="region" aria-label="<?php echo esc_attr__( 'فهرست برندهای همکار؛ با کلیدهای جهت یا کشیدن، پیمایش کنید', 'tecteb-homepage' ); ?>">
			<ul class="th-brands__list" role="list">
				<?php foreach ( $brands as $brand ) : ?>
					<li class="th-brands__item">
						<?php if ( '' !== $brand['url'] ) : ?>
							<a class="th-brand" href="<?php echo esc_url( $brand['url'] ); ?>"><?php echo $brand['image']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- خروجی wp_get_attachment_image() با alt = نام برند. ?></a>
						<?php else : ?>
							<span class="th-brand"><?php echo $brand['image']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- خروجی wp_get_attachment_image() با alt = نام برند. ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
</section>
