<?php
/**
 * صفحهٔ نخست با Concept C (۰٫۳٫۱): هدر و پاورقی همان قالب سایت؛ محتوای برگهٔ صفحهٔ نخست خوانده یا
 * چاپ نمی‌شود و دست هم نمی‌خورد. فقط وقتی PageTemplate::takes_over_front() بله بگوید بارگذاری می‌شود.
 *
 * @package Tecteb\Homepage
 */

use Tecteb\Homepage\Renderer;

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="content" class="tecteb-home-page tecteb-home-front">
	<?php echo Renderer::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- هر جزء در قالب‌های همین افزونه escape شده است. ?>
</main>
<?php
get_footer();
