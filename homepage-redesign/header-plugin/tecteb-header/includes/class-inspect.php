<?php
/**
 * بررسی فقط‌خواندنیِ یک قالب هدر Elementor (پیش‌فرض ۲۶۵۲) پیش از جایگزینی.
 *
 * چه چیزی در آن قالب هست که با جایگزینی از صفحه می‌رود، و چه چیزی **با وجود جایگزینی** هنوز بارگذاری
 * می‌شود و می‌تواند روی هدر تازه یا بقیهٔ صفحه اثر بگذارد:
 *   - ابزارک‌ها از متای `_elementor_data` (نوع و تعداد، و هر چیزی که رفتار سراسری دارد: HTML،
 *     shortcode، اسکریپت در متن، منو، JetMenu/Jet، سبد، جست‌وجو، پیوند popup، sticky، motion effects،
 *     Custom CSS ِ هر عنصر)؛
 *   - Custom CSS ِ تنظیمات قالب (`_elementor_page_settings`)؛
 *   - فایل `uploads/elementor/css/post-<id>.css` — Elementor Pro آن را با `enqueue_styles` برای هر
 *     قالبِ مکان بارگذاری می‌کند، **حتی وقتی** قالب از صف چاپ برداشته شده است؛ انتخابگرهایی از آن که
 *     به `.elementor-<id>` محدود نیستند «سراسری» گزارش می‌شوند؛
 *   - CSS ِ Kit ِ فعال و «CSS اضافی» ِ سفارشی‌ساز، با همان معیار؛
 *   - callback های روی قلاب‌های مکان header و قالب (`get_header`، `wp_body_open`، …) با فایلشان؛
 *   - تنظیمات WP Rocket ِ مرتبط و نسخهٔ افزونه‌های مرتبط.
 *
 * فقط با get_post*، get_option، file_get_contents و Reflection می‌خواند؛ هیچ متد نوشتنی، هیچ اجرای
 * callback و هیچ فراخوانی API ِ Elementor ندارد (tests/static/check.py همین را از بایت‌های فایل می‌سنجد).
 *
 * @package Tecteb\Header
 */

namespace Tecteb\Header;

defined( 'ABSPATH' ) || exit;

final class Inspect {

	public const HOOKS = array(
		'elementor/theme/before_do_header',
		'elementor/theme/after_do_header',
		'elementor/theme/register_locations',
		'get_header',
		'wp_body_open',
		'elementor/frontend/after_enqueue_styles',
	);

	private const ROCKET_KEYS = array( 'minify_css', 'remove_unused_css', 'async_css', 'minify_js', 'minify_concatenate_js', 'defer_all_js', 'delay_js', 'lazyload', 'cache_logged_user', 'cache_mobile', 'do_caching_mobile_files' );

	/** ابزارک‌هایی که رفتار یا اسکریپتی بیرون از خودِ هدر دارند. */
	private const NOTABLE = array( 'html', 'shortcode', 'nav-menu', 'woocommerce-menu-cart', 'search-form', 'search', 'wp-widget-search', 'form', 'popup', 'template' );

	/** @return array<string,mixed> */
	public static function report( int $id ): array {
		$post = get_post( $id );
		$out  = array(
			'id'       => $id,
			'exists'   => $post instanceof \WP_Post,
			'versions' => Elementor::versions(),
		);
		if ( ! $post instanceof \WP_Post ) {
			return $out;
		}
		$conds           = get_post_meta( $id, '_elementor_conditions', true );
		$out['template'] = array(
			'title'      => (string) $post->post_title,
			'post_type'  => (string) $post->post_type,
			'status'     => (string) $post->post_status,
			'type'       => (string) get_post_meta( $id, '_elementor_template_type', true ),
			'modified'   => (string) $post->post_modified,
			'conditions' => is_array( $conds ) ? array_values( array_map( 'strval', $conds ) ) : array(),
		);
		$out['widgets']  = self::widgets( $id );

		$page               = get_post_meta( $id, '_elementor_page_settings', true );
		$out['page_css']    = is_array( $page ) && isset( $page['custom_css'] ) ? self::css_summary( (string) $page['custom_css'], $id ) : null;
		$out['post_css']    = self::css_file( $id, $id );
		$kit                = (int) get_option( 'elementor_active_kit', 0 );
		$kit_page           = $kit ? get_post_meta( $kit, '_elementor_page_settings', true ) : null;
		$out['kit']         = array(
			'id'         => $kit,
			'file'       => $kit ? self::css_file( $kit, 0 ) : null,
			'custom_css' => is_array( $kit_page ) && isset( $kit_page['custom_css'] ) ? self::css_summary( (string) $kit_page['custom_css'], 0 ) : null,
		);
		$out['customizer']  = function_exists( 'wp_get_custom_css' ) ? self::css_summary( (string) wp_get_custom_css(), 0 ) : null;
		$out['hooks']       = self::hooks();
		$rocket             = get_option( 'wp_rocket_settings', null );
		$out['wp_rocket']   = is_array( $rocket ) ? array_intersect_key( $rocket, array_flip( self::ROCKET_KEYS ) ) : null;
		$out['plugins']     = self::plugins();
		return $out;
	}

	/** @return array<string,mixed> */
	private static function widgets( int $id ): array {
		$raw  = get_post_meta( $id, '_elementor_data', true );
		$data = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
		$out  = array(
			'readable' => is_array( $data ),
			'types'    => array(),
			'notable'  => array(),
			'flags'    => array(),
		);
		if ( ! is_array( $data ) ) {
			return $out;
		}
		$walk = static function ( array $els, string $trail ) use ( &$walk, &$out, $id ): void {
			foreach ( $els as $el ) {
				if ( ! is_array( $el ) ) {
					continue;
				}
				$type = (string) ( $el['widgetType'] ?? $el['elType'] ?? '?' );
				$eid  = (string) ( $el['id'] ?? '' );
				$s    = is_array( $el['settings'] ?? null ) ? $el['settings'] : array();
				$here = ( '' === $trail ? '' : $trail . ' > ' ) . $type . ( '' !== $eid ? '#' . $eid : '' );
				$out['types'][ $type ] = ( $out['types'][ $type ] ?? 0 ) + 1;
				if ( in_array( $type, self::NOTABLE, true ) || str_starts_with( $type, 'jet-' ) || str_starts_with( $type, 'wp-widget-' ) ) {
					$out['notable'][] = $here;
				}
				$flat = wp_json_encode( $s );
				$flat = is_string( $flat ) ? $flat : '';
				if ( preg_match( '/<script\b/i', $flat ) ) {
					$out['flags'][] = array( 'script', $here );
				}
				if ( str_contains( $flat, '#elementor-action' ) ) {
					$out['flags'][] = array( 'popup_action', $here );
				}
				if ( ! empty( $s['sticky'] ) ) {
					$out['flags'][] = array( 'sticky:' . (string) $s['sticky'], $here );
				}
				foreach ( $s as $k => $v ) {
					if ( is_string( $k ) && ( str_starts_with( $k, 'motion_fx_' ) || str_starts_with( $k, '_animation' ) || 'animation' === $k ) && ! empty( $v ) && 'none' !== $v && 'no' !== $v ) {
						$out['flags'][] = array( 'motion:' . $k, $here );
						break;
					}
				}
				if ( isset( $s['custom_css'] ) && '' !== trim( (string) $s['custom_css'] ) ) {
					$out['flags'][] = array( 'custom_css', $here, self::css_summary( (string) $s['custom_css'], $id ) );
				}
				if ( isset( $s['_element_id'] ) && '' !== (string) $s['_element_id'] ) {
					$out['flags'][] = array( 'element_id:' . (string) $s['_element_id'], $here );
				}
				if ( is_array( $el['elements'] ?? null ) ) {
					$walk( $el['elements'], $here );
				}
			}
		};
		$walk( $data, '' );
		ksort( $out['types'] );
		return $out;
	}

	/** @return array{path:string,exists:bool,bytes:int,summary:array<string,mixed>|null} */
	private static function css_file( int $post_id, int $scope_id ): array {
		$up   = wp_upload_dir( null, false );
		$path = trailingslashit( (string) $up['basedir'] ) . 'elementor/css/post-' . $post_id . '.css';
		$ok   = is_readable( $path );
		$css  = $ok ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- فایل محلی، فقط خواندن.
		return array(
			'path'    => $path,
			'exists'  => $ok,
			'bytes'   => strlen( $css ),
			'summary' => $ok ? self::css_summary( $css, $scope_id ) : null,
		);
	}

	/**
	 * انتخابگرهایی که به `.elementor-<scope>` (یا واژهٔ `selector` ِ Custom CSS) محدود نیستند — اگر
	 * scope صفر است همه — و شمار قاعده‌های `!important` در میان آن‌ها.
	 *
	 * @return array{bytes:int,rules:int,unscoped:int,important:int,examples:list<string>}
	 */
	public static function css_summary( string $css, int $scope_id ): array {
		$css   = (string) preg_replace( '~/\*.*?\*/~s', '', $css );
		$rules = 0;
		$out   = array();
		$imp   = 0;
		if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $r ) {
				$sel = trim( $r[1] );
				if ( '' === $sel || str_starts_with( $sel, '@' ) || preg_match( '/^(from|to|[\d.]+%)(\s*,\s*(from|to|[\d.]+%))*$/', $sel ) ) {
					continue;
				}
				++$rules;
				// قاعدهٔ Elementor برای Custom CSS: واژهٔ `selector` با کلاس همان عنصر/قالب جایگزین می‌شود.
				$scoped = $scope_id > 0 && ( str_contains( $sel, '.elementor-' . $scope_id ) || 1 === preg_match( '/(^|[\s,>+~])selector\b/', $sel ) );
				if ( ! $scoped ) {
					$out[] = preg_replace( '/\s+/', ' ', $sel );
					if ( str_contains( $r[2], '!important' ) ) {
						++$imp;
					}
				}
			}
		}
		$out = array_values( array_unique( $out ) );
		return array(
			'bytes'     => strlen( $css ),
			'rules'     => $rules,
			'unscoped'  => count( $out ),
			'important' => $imp,
			'examples'  => array_slice( $out, 0, 25 ),
		);
	}

	/** @return array<string,list<array{priority:int,callback:string,file:string}>> */
	public static function hooks(): array {
		global $wp_filter;
		$out = array();
		foreach ( self::HOOKS as $hook ) {
			$out[ $hook ] = array();
			if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $prio => $cbs ) {
				foreach ( $cbs as $cb ) {
					$out[ $hook ][] = array(
						'priority' => (int) $prio,
						'callback' => self::callback_name( $cb['function'] ),
						'file'     => self::callback_file( $cb['function'] ),
					);
				}
			}
		}
		return $out;
	}

	/** @param mixed $fn */
	private static function callback_name( $fn ): string {
		if ( is_string( $fn ) ) {
			return $fn;
		}
		if ( is_array( $fn ) && 2 === count( $fn ) ) {
			return ( is_object( $fn[0] ) ? get_class( $fn[0] ) . '->' : (string) $fn[0] . '::' ) . (string) $fn[1];
		}
		return $fn instanceof \Closure ? 'Closure' : ( is_object( $fn ) ? get_class( $fn ) . '->__invoke' : '?' );
	}

	/** @param mixed $fn */
	private static function callback_file( $fn ): string {
		try {
			if ( is_array( $fn ) && 2 === count( $fn ) ) {
				$r = new \ReflectionMethod( $fn[0], (string) $fn[1] );
			} elseif ( is_string( $fn ) && str_contains( $fn, '::' ) ) {
				$r = new \ReflectionMethod( $fn );
			} elseif ( is_string( $fn ) || $fn instanceof \Closure ) {
				$r = new \ReflectionFunction( $fn );
			} elseif ( is_object( $fn ) ) {
				$r = new \ReflectionMethod( $fn, '__invoke' );
			} else {
				return '';
			}
		} catch ( \ReflectionException $e ) {
			return '';
		}
		$file = (string) $r->getFileName();
		$file = '' === $file ? 'internal' : str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file ) );
		return $file . ( $r->getStartLine() ? ':' . $r->getStartLine() : '' );
	}

	/** @return array<string,string> */
	private static function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = (array) get_option( 'active_plugins', array() );
		$out    = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( in_array( $file, $active, true ) && preg_match( '/elementor|jet|rocket|woocommerce|litespeed|cache|tecteb|search|wishlist/i', $file ) ) {
				$out[ $file ] = (string) ( $data['Version'] ?? '' );
			}
		}
		ksort( $out );
		return $out;
	}

	/** گزارش متنی برای WP-CLI و پیشخوان. */
	public static function text( array $r ): string {
		$l   = array();
		$l[] = '== tecteb-header: read-only inspection of template #' . $r['id'] . ' (nothing was written)';
		$v   = $r['versions'];
		$l[] = 'elementor=' . ( $v['elementor'] ?: '-' ) . ' elementor_pro=' . ( $v['pro'] ?: '-' ) . ' theme_builder=' . ( $v['theme_builder'] ? 'yes' : 'no' );
		if ( empty( $r['exists'] ) ) {
			$l[] = 'template: NOT FOUND';
			return implode( "\n", $l ) . "\n";
		}
		$t   = $r['template'];
		$l[] = sprintf( 'template: "%s" post_type=%s status=%s type=%s modified=%s', $t['title'], $t['post_type'], $t['status'], $t['type'] ?: '-', $t['modified'] );
		$l[] = 'conditions: ' . ( $t['conditions'] ? implode( ', ', $t['conditions'] ) : '(none)' );
		$w   = $r['widgets'];
		$l[] = '-- widgets (_elementor_data): ' . ( $w['readable'] ? 'readable' : 'NOT READABLE / EMPTY' );
		foreach ( $w['types'] as $type => $n ) {
			$l[] = sprintf( '   %-28s %d', $type, $n );
		}
		$l[] = '-- notable widgets (behaviour outside the header box): ' . count( $w['notable'] );
		foreach ( $w['notable'] as $n ) {
			$l[] = '   ' . $n;
		}
		$l[] = '-- flags: ' . count( $w['flags'] );
		foreach ( $w['flags'] as $f ) {
			$l[] = '   ' . $f[0] . '  @ ' . $f[1] . ( isset( $f[2] ) ? '  ' . self::css_line( $f[2] ) : '' );
		}
		$l[] = '-- template custom CSS (page settings): ' . ( null === $r['page_css'] ? '(none)' : self::css_line( $r['page_css'] ) );
		self::file_lines( $l, 'template CSS file', $r['post_css'] );
		$l[] = '   note: Elementor Pro enqueues this file for every location template, even when the template is removed from the print queue.';
		$k   = $r['kit'];
		$l[] = '-- active kit: ' . ( $k['id'] ? '#' . $k['id'] : '(none)' );
		if ( $k['file'] ) {
			self::file_lines( $l, 'kit CSS file', $k['file'] );
		}
		$l[] = '   kit custom CSS: ' . ( null === $k['custom_css'] ? '(none)' : self::css_line( $k['custom_css'] ) );
		$l[] = '-- customizer additional CSS: ' . ( null === $r['customizer'] || 0 === $r['customizer']['bytes'] ? '(none)' : self::css_line( $r['customizer'] ) );
		if ( $r['customizer'] && $r['customizer']['examples'] ) {
			foreach ( array_slice( $r['customizer']['examples'], 0, 10 ) as $e ) {
				$l[] = '      ' . $e;
			}
		}
		$l[] = '-- hooks';
		foreach ( $r['hooks'] as $hook => $cbs ) {
			$l[] = '   ' . $hook . ': ' . count( $cbs );
			foreach ( $cbs as $cb ) {
				$l[] = sprintf( '      %d  %s  %s', $cb['priority'], $cb['callback'], $cb['file'] );
			}
		}
		$l[] = '-- wp_rocket_settings: ' . ( null === $r['wp_rocket'] ? '(option not present)' : '' );
		foreach ( (array) $r['wp_rocket'] as $k2 => $val ) {
			$l[] = '   ' . $k2 . '=' . ( is_scalar( $val ) ? (string) $val : wp_json_encode( $val ) );
		}
		$l[] = '-- related active plugins';
		foreach ( $r['plugins'] as $file => $ver ) {
			$l[] = '   ' . $file . ' ' . $ver;
		}
		return implode( "\n", $l ) . "\n";
	}

	/** @param array<string,mixed> $s */
	private static function css_line( array $s ): string {
		return sprintf( '%d bytes, %d rules, %d not scoped to the template, %d with !important', $s['bytes'], $s['rules'], $s['unscoped'], $s['important'] );
	}

	/**
	 * @param list<string>        $l
	 * @param array<string,mixed> $f
	 */
	private static function file_lines( array &$l, string $label, array $f ): void {
		$l[] = '-- ' . $label . ': ' . $f['path'] . ( $f['exists'] ? ' (' . $f['bytes'] . ' bytes)' : ' (not present)' );
		if ( $f['summary'] ) {
			$l[] = '   ' . self::css_line( $f['summary'] );
			foreach ( array_slice( $f['summary']['examples'], 0, 15 ) as $e ) {
				$l[] = '      ' . $e;
			}
		}
	}
}
