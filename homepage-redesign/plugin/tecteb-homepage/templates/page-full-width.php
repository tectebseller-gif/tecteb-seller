<?php
/**
 * قالب صفحهٔ تمام‌عرض: هدر و پاورقی قالب سایت، و محتوای صفحه بی عنوان و بی قاب.
 *
 * @package Tecteb\Homepage
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="content" class="tecteb-home-page">
	<?php
	while ( have_posts() ) {
		the_post();
		the_content();
	}
	?>
</main>
<?php
get_footer();
