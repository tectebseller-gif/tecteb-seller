<?php
/**
 * بستر آزمون با Elementor ِ واقعی (4.2.3) و PRO Elements (4.2.2) — فقط روی وردپرس یکبارمصرف tth_perf.
 *
 *   wp --path=/home/user/wp2/core eval-file perf/tools/real-elementor-testbed.php seed|report|purge
 *
 * seed: پس از fixtures ِ هر دو افزونه، قالب‌های ساختگی ۲۶۵۲ را برمی‌دارد و با API ِ خودِ Elementor یک قالب هدر
 *   (شناسهٔ ۲۶۵۲، شرط «کل سایت») و یک قالب فوتر می‌سازد با همان نوع ابزارک‌هایی که PageSpeed ِ Production در
 *   «render-blocking» نام برده (تصویر، منوی ناوبری، جست‌وجو، جعبهٔ آیکون، شبکه‌های اجتماعی، فهرست آیکون، جداکننده،
 *   عنوان، انیمیشن buzz-out، آیکون‌های Font Awesome). Elementor فایل post-*.css و فهرست دارایی‌های صفحه را خودش
 *   می‌سازد — هیچ CSS ِ دست‌نویسی در کار نیست.
 * report: دارایی‌های ثبت‌شدهٔ هر قالب (`_elementor_page_assets`) و فایل CSS.
 */

if ( 'tth_perf' !== DB_NAME ) {
	fwrite( STDERR, "refused: not the tth_perf testbed\n" );
	exit( 1 );
}

$cmd = $args[0] ?? 'report';

function tthp_id(): string {
	return substr( md5( uniqid( '', true ) ), 0, 7 );
}

function tthp_widget( string $type, array $settings ): array {
	return array( 'id' => tthp_id(), 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => array() );
}

function tthp_container( array $children, array $settings = array() ): array {
	return array( 'id' => tthp_id(), 'elType' => 'container', 'isInner' => false, 'settings' => $settings + array( 'flex_direction' => 'row' ), 'elements' => $children );
}

function tthp_fa( string $value, string $library = 'fa-solid' ): array {
	return array( 'value' => $value, 'library' => $library );
}

function tthp_header_data(): array {
	$logo  = attachment_url_to_postid( '' );
	$menus = wp_get_nav_menus();
	$menu  = $menus ? $menus[0]->slug : '';
	return array(
		tthp_container( array(
			tthp_widget( 'image', array( 'image' => array( 'url' => plugins_url( 'tecteb-header/assets/img/tecteb-logo-mark-96.png' ), 'id' => '' ) ) ),
			tthp_widget( 'nav-menu', array( 'menu' => $menu, 'layout' => 'horizontal' ) ),
			tthp_widget( 'search', array() ),
			tthp_widget( 'icon-box', array( 'selected_icon' => tthp_fa( 'fas fa-phone' ), 'title_text' => 'پشتیبانی', 'description_text' => '021', 'hover_animation' => 'buzz-out' ) ),
		) ),
		tthp_container( array(
			tthp_widget( 'social-icons', array( 'social_icon_list' => array(
				array( '_id' => tthp_id(), 'social_icon' => tthp_fa( 'fab fa-instagram', 'fa-brands' ), 'link' => array( 'url' => '#' ) ),
				array( '_id' => tthp_id(), 'social_icon' => tthp_fa( 'fab fa-telegram', 'fa-brands' ), 'link' => array( 'url' => '#' ) ),
			) ) ),
			tthp_widget( 'icon-list', array( 'icon_list' => array(
				array( '_id' => tthp_id(), 'text' => 'ارسال سریع', 'selected_icon' => tthp_fa( 'far fa-clock', 'fa-regular' ) ),
			) ) ),
			tthp_widget( 'divider', array() ),
			tthp_widget( 'heading', array( 'title' => 'تک‌طب' ) ),
		) ),
	);
}

function tthp_footer_data(): array {
	return array(
		tthp_container( array(
			tthp_widget( 'heading', array( 'title' => 'تماس با تک‌طب' ) ),
			tthp_widget( 'icon-list', array( 'icon_list' => array(
				array( '_id' => tthp_id(), 'text' => 'تهران', 'selected_icon' => tthp_fa( 'fas fa-map-marker-alt' ) ),
			) ) ),
			tthp_widget( 'divider', array() ),
		) ),
	);
}

/** صفحهٔ نخست ِ پیشین ساخته‌شده با Elementor — محتوایش با takeover ِ Tecteb Homepage هرگز چاپ نمی‌شود. */
function tthp_front_data(): array {
	return array(
		tthp_container( array(
			tthp_widget( 'image-carousel', array( 'carousel' => array(
				array( 'id' => '', 'url' => plugins_url( 'tecteb-homepage/assets/img/tecteb-logo-mark-96.png' ) ),
				array( 'id' => '', 'url' => plugins_url( 'tecteb-header/assets/img/tecteb-logo-mark-96.png' ) ),
			) ) ),
			tthp_widget( 'heading', array( 'title' => 'صفحهٔ نخست قدیمی' ) ),
			tthp_widget( 'testimonial', array( 'testimonial_content' => 'نمونه', 'testimonial_name' => 'مشتری' ) ),
			tthp_widget( 'counter', array( 'ending_number' => 120 ) ),
			tthp_widget( 'button', array( 'text' => 'خرید', 'hover_animation' => 'grow', 'selected_icon' => tthp_fa( 'fas fa-cart-plus' ) ) ),
		) ),
	);
}

function tthp_elementor_front(): int {
	$front = (int) get_option( 'page_on_front' );
	if ( ! $front ) {
		return 0;
	}
	$doc = \Elementor\Plugin::$instance->documents->get( $front );
	$doc->save( array( 'elements' => tthp_front_data(), 'settings' => array() ) );
	update_post_meta( $front, '_elementor_edit_mode', 'builder' );
	return $front;
}

function tthp_create( string $type, string $title, array $data, array $conditions, int $import_id = 0 ): int {
	$post = array( 'post_title' => $title, 'post_status' => 'publish' );
	if ( $import_id ) {
		$post['import_id'] = $import_id;
	}
	$doc = \Elementor\Plugin::$instance->documents->create( $type, $post );
	$doc->save( array( 'elements' => $data, 'settings' => array() ) );
	update_post_meta( $doc->get_main_id(), '_tthp_fixture', 1 );
	\ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->save_conditions( $doc->get_main_id(), $conditions );
	return (int) $doc->get_main_id();
}

function tthp_purge(): void {
	// Kit (تنظیمات سایت) دست نمی‌خورد: فقط قالب‌های هدر و فوتر.
	foreach ( get_posts( array( 'post_type' => 'elementor_library', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
		if ( in_array( get_post_meta( $id, '_elementor_template_type', true ), array( 'header', 'footer' ), true ) ) {
			wp_delete_post( $id, true );
		}
	}
	delete_option( 'elementor_pro_theme_builder_conditions' );
	$css = wp_upload_dir()['basedir'] . '/elementor/css';
	foreach ( glob( $css . '/post-*.css' ) ?: array() as $f ) {
		unlink( $f );
	}
}

function tthp_report(): void {
	$front = (int) get_option( 'page_on_front' );
	printf( "front page %d built_with_elementor=%s page_assets=%s\n", $front, get_post_meta( $front, '_elementor_edit_mode', true ), wp_json_encode( get_post_meta( $front, '_elementor_page_assets', true ) ) );
	foreach ( get_posts( array( 'post_type' => 'elementor_library', 'post_status' => 'any', 'numberposts' => -1 ) ) as $p ) {
		$assets = get_post_meta( $p->ID, '_elementor_page_assets', true );
		$file   = wp_upload_dir()['basedir'] . '/elementor/css/post-' . $p->ID . '.css';
		printf( "%d %s type=%s conditions=%s\n  page_assets=%s\n  css=%s\n", $p->ID, $p->post_title, get_post_meta( $p->ID, '_elementor_template_type', true ),
			wp_json_encode( get_post_meta( $p->ID, '_elementor_conditions', true ) ), wp_json_encode( $assets ), is_file( $file ) ? filesize( $file ) . ' bytes' : 'none' );
	}
	echo 'conditions cache: ', wp_json_encode( get_option( 'elementor_pro_theme_builder_conditions' ) ), "\n";
}

switch ( $cmd ) {
	case 'seed':
		tthp_purge();
		$h = tthp_create( 'header', 'Header', tthp_header_data(), array( array( 'type' => 'include', 'name' => 'general' ) ), 2652 );
		$f = tthp_create( 'footer', 'Footer', tthp_footer_data(), array( array( 'type' => 'include', 'name' => 'general' ) ) );
		update_option( 'elementor_css_print_method', 'external' );
		$fp = tthp_elementor_front();
		echo "header=$h footer=$f front=$fp\n";
		tthp_report();
		break;
	case 'purge':
		tthp_purge();
		echo "purged\n";
		break;
	default:
		tthp_report();
}
