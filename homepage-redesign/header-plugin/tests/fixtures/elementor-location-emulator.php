<?php
/**
 * Plugin Name: TTHH — شبیه‌ساز وفادار مکان header/footer ِ Elementor Theme Builder (فقط آزمون محلی)
 *
 * Elementor Pro در محیط توسعه اجرا نشد (نصب افزونهٔ بیرونی مجاز نبود). این فایل همان بخشی از
 * Locations_Manager ِ Pro Elements 4.2.2 (هم‌نسخهٔ Elementor Pro 4.2.2 ِ Staging) را که
 * tecteb-header به آن تکیه دارد **خط‌به‌خط** بازسازی می‌کند:
 *   modules/theme-builder/classes/locations-manager.php
 *     add_doc_to_location · remove_doc_from_location · skip_doc_in_location · is_printed ·
 *     set_is_printed · get_documents_for_location · do_location (صف، before_do_, حلقهٔ while،
 *     after_do_, return true)
 *   modules/theme-builder/api.php: elementor_theme_do_location()
 * «اسناد بر اساس شرط» از option ِ واقعی Elementor (`elementor_pro_theme_builder_conditions`)
 * خوانده می‌شود، فقط برای شرط `include/general`، و فقط قالب منتشرشده (مکان header «multiple»
 * نیست، پس فقط اولی). چاپ سند همان پوستهٔ HTML ِ Theme_Document است.
 *
 * گزینه‌های آزمون: tthh_emulate (both|header|footer|none)، tthh_emulate_neighbors (افزونهٔ
 * دیگری که روی before/after_do_header چاپ می‌کند)، tthh_emulate_shortcode.
 * فقط روی وردپرس یکبارمصرف بارگذاری می‌شود.
 */

defined( 'ABSPATH' ) || exit;

if ( 'tth_disposable' !== DB_NAME ) {
	return;
}

final class TTHH_Locations_Manager {

	private static ?self $instance = null;
	private array $locations_queue   = array();
	private array $locations_skipped = array();
	private array $locations_printed = array();
	private array $did_locations     = array();

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function add_doc_to_location( $location, $document_id ) {
		if ( isset( $this->locations_skipped[ $location ][ $document_id ] ) ) {
			return;
		}
		if ( ! isset( $this->locations_queue[ $location ] ) ) {
			$this->locations_queue[ $location ] = array();
		}
		$this->locations_queue[ $location ][ $document_id ] = $document_id;
	}

	public function remove_doc_from_location( $location, $document_id ) {
		unset( $this->locations_queue[ $location ][ $document_id ] );
	}

	public function skip_doc_in_location( $location, $document_id ) {
		$this->remove_doc_from_location( $location, $document_id );
		$this->locations_skipped[ $location ][ $document_id ] = $document_id;
	}

	public function is_printed( $location, $document_id ) {
		return isset( $this->locations_printed[ $location ][ $document_id ] );
	}

	public function set_is_printed( $location, $document_id ) {
		$this->locations_printed[ $location ][ $document_id ] = $document_id;
		$this->remove_doc_from_location( $location, $document_id );
	}

	public function did_location( $location ) {
		return in_array( $location, $this->did_locations, true );
	}

	public function get_documents_for_location( string $location ): array {
		return $this->locations_queue[ $location ] ?? array();
	}

	/** Conditions_Manager::get_documents_for_location، ساده‌شده به include/general. */
	private function documents_by_conditions( string $location ): array {
		$mode = get_option( 'tthh_emulate', 'both' );
		if ( 'none' === $mode || ( 'both' !== $mode && $mode !== $location ) ) {
			return array();
		}
		$all = get_option( 'elementor_pro_theme_builder_conditions', array() );
		foreach ( (array) ( $all[ $location ] ?? array() ) as $id => $conds ) {
			if ( in_array( 'include/general', (array) $conds, true ) && 'publish' === get_post_status( (int) $id ) ) {
				return array( (int) $id => (int) $id ); // مکان header/footer «multiple» نیست.
			}
		}
		return array();
	}

	private function print_document( string $location, int $id ): void {
		$tag = 'header' === $location ? 'header' : 'footer';
		echo '<' . $tag . ' data-elementor-type="' . esc_attr( $location ) . '" data-elementor-id="' . (int) $id . '" class="elementor elementor-' . (int) $id . ' elementor-location-' . esc_attr( $location ) . '" data-tthh-' . ( 'header' === $location ? 'original' : 'footer' ) . '>';
		echo 'header' === $location
			? '<div class="elementor-section"><a href="/" class="tthh-orig-logo">تک‌طب (هدر Elementor ' . (int) $id . ')</a><form role="search"><input type="search" aria-label="جستجو سریع" placeholder="جستجو سریع ..."></form></div>'
			: '<p>فوتر Elementor (دست‌نخورده)</p>';
		if ( 'header' === $location && get_option( 'tthh_emulate_shortcode' ) ) {
			echo do_shortcode( '[tecteb_header]' );
		}
		echo '</' . $tag . '>';
	}

	/** Locations_Manager::do_location — همان ترتیب. */
	public function do_location( $location ) {
		$documents_by_conditions = $this->documents_by_conditions( $location );
		foreach ( $documents_by_conditions as $document_id => $document ) {
			$this->add_doc_to_location( $location, $document_id );
		}
		if ( empty( $this->locations_queue[ $location ] ) ) {
			return false;
		}
		do_action( "elementor/theme/before_do_{$location}", $this );
		while ( ! empty( $this->locations_queue[ $location ] ) ) {
			$document_id = key( $this->locations_queue[ $location ] );
			if ( $this->is_printed( $location, $document_id ) ) {
				$this->skip_doc_in_location( $location, $document_id );
				continue;
			}
			$this->print_document( $location, (int) $document_id );
			$this->did_locations[] = $location;
			$this->set_is_printed( $location, $document_id );
		}
		do_action( "elementor/theme/after_do_{$location}", $this );
		return true;
	}

	/** فقط برای آزمون: هر درخواست با صف تازه. */
	public static function reset(): void {
		self::$instance = null;
	}
}

if ( ! function_exists( 'elementor_theme_do_location' ) ) {
	function elementor_theme_do_location( $location ) {
		return TTHH_Locations_Manager::instance()->do_location( $location );
	}
}

// افزونهٔ همسایه که روی همان دو اکشن چاپ می‌کند — نباید حذف شود.
add_action(
	'elementor/theme/before_do_header',
	static function () {
		if ( get_option( 'tthh_emulate_neighbors' ) ) {
			echo '<div data-tthh-neighbor="before">neighbor-before</div>';
		}
	},
	10
);
add_action(
	'elementor/theme/after_do_header',
	static function () {
		if ( get_option( 'tthh_emulate_neighbors' ) ) {
			echo '<div data-tthh-neighbor="after">neighbor-after</div>';
		}
	},
	10
);
