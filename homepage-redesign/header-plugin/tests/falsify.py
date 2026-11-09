#!/usr/bin/env python3
"""
Falsification: هر گارد را عمداً می‌شکند و می‌پرسد آیا آزمون‌ها شکست می‌خورند.

  python3 homepage-redesign/header-plugin/tests/falsify.py

هر جهش: فایل کپی می‌شود، needle دقیقاً یک بار جایگزین می‌شود (وگرنه خودِ جهش «شکست» است،
نه گاردی شکست‌ناپذیر)، سوییت مربوط اجرا می‌شود، و فایل در finally بایت‌به‌بایت برمی‌گردد.
جهشی که هیچ آزمونی را نشکند «SURVIVED» است — یعنی آن گارد آزمون ندارد.
"""
from __future__ import annotations

import hashlib
import pathlib
import subprocess
import sys

HERE = pathlib.Path(__file__).resolve().parents[1]
SRC = HERE / "tecteb-header"
WP = ["wp", "--allow-root", "--path=/home/user/wp/core"]


def static() -> bool:
    return subprocess.run([sys.executable, str(HERE / "tests/static/check.py")], capture_output=True).returncode == 0


def integration() -> bool:
    subprocess.run(WP + ["eval-file", str(HERE / "tests/fixtures/seed.php"), "seed"], capture_output=True, check=True)
    out = subprocess.run(WP + ["eval-file", str(HERE / "tests/integration/run.php")], capture_output=True, text=True).stdout
    return out.rstrip().endswith(" 0 failed")


def compact() -> bool:
    """۰٫۲٫۴: نوار چسبانِ دسکتاپ یک‌ردیفه، بی همبرگری (tests/compact-desktop.mjs)."""
    r = subprocess.run(["node", str(HERE / "tests/compact-desktop.mjs")], capture_output=True, env={**__import__("os").environ, "NODE_PATH": "/opt/node-tools/node_modules", "LABEL": "falsify-tmp"})
    for f in (HERE / "evidence/compact-desktop").glob("falsify-tmp*"):
        f.unlink()
    return r.returncode == 0


def early() -> bool:
    """۰٫۲٫۵: حالت JS پیش از اولین رسم و دو مسیر شکستش (tests/early-js.mjs)."""
    r = subprocess.run(["node", str(HERE / "tests/early-js.mjs")], capture_output=True, env={**__import__("os").environ, "NODE_PATH": "/opt/node-tools/node_modules"})
    return r.returncode == 0


def isolation() -> bool:
    subprocess.run(WP + ["eval-file", str(HERE / "tests/fixtures/seed.php"), "seed"], capture_output=True, check=True)
    subprocess.run(WP + ["eval-file", str(HERE / "tests/fixtures/seed.php"), "configure", "on"], capture_output=True, check=True)
    r = subprocess.run(["node", str(HERE / "tests/isolation.mjs"), "falsify-tmp"], capture_output=True, env={**__import__("os").environ, "NODE_PATH": "/opt/node-tools/node_modules"})
    for ext in ("txt", "json"):
        (HERE / "evidence/isolation" / f"falsify-tmp.{ext}").unlink(missing_ok=True)
    return r.returncode == 0


def navoverflow() -> bool:
    # منوی بلندِ سایت اصلی (۰٫۲٫۳): هیچ پیوندی روی «همکاری تأمین‌کنندگان» نیفتد.
    r = subprocess.run(["node", str(HERE / "tests/nav-overflow.mjs")], capture_output=True, env={**__import__("os").environ, "NODE_PATH": "/opt/node-tools/node_modules", "LABEL": "falsify-tmp"})
    (HERE / "evidence/nav-overflow/falsify-tmp.txt").unlink(missing_ok=True)
    for f in (HERE / "evidence/nav-overflow").glob("falsify-tmp-*.png"):
        f.unlink()
    return r.returncode == 0


def elementor() -> bool:
    """۰٫۲٫۶: دارایی‌های قالب جایگزین‌شده روی Elementor ِ واقعی (perf/tests/elementor-assets.sh، بستر tth_perf روی 8092)."""
    import os, tempfile
    out = pathlib.Path(tempfile.gettempdir()) / "tth-falsify-elementor.txt"
    r = subprocess.run(["bash", str(HERE.parent / "perf/tests/elementor-assets.sh")], capture_output=True, env={**os.environ, "OUT": str(out)})
    out.unlink(missing_ok=True)
    return r.returncode == 0


S, I, ISO, NAV, EL = static, integration, isolation, navoverflow, elementor
MUTATIONS = [
    ("allow SVG icons", "includes/class-settings.php", "array( 'image/png', 'image/webp' );", "array( 'image/png', 'image/webp', 'image/svg+xml' );", [S, I]),
    ("hide_empty default on", "includes/class-settings.php", "'hide_empty'         => false,", "'hide_empty'         => true,", [S, I]),
    ("drop descendant check on save", "includes/class-settings.php", "! term_is_ancestor_of( $id, $kid, 'product_cat' )", "false", [I]),
    ("drop descendant check at render", "includes/class-menu.php", "|| ! in_array( (int) $t->term_id, array_map( 'intval', get_ancestors( (int) $kt->term_id, 'product_cat', 'taxonomy' ) ), true )", "", [I]),
    ("ignore hide_empty at render", "includes/class-menu.php", "( ! $s['hide_empty'] || self::count( $t ) > 0 )", "true", [I]),
    ("hide_empty uses direct count only", "includes/class-menu.php", "return is_numeric( $wc ) ? (int) $wc : (int) $t->count;", "return (int) $t->count;", [I]),
    ("render twice per request", "includes/class-renderer.php", "if ( self::$rendered ) {", "if ( false ) {", [I]),
    ("2652 not removed from the queue", "includes/class-replace.php", "			$manager->remove_doc_from_location( 'header', $id );\n", "", [I]),
    ("replace ignores the switch", "includes/class-replace.php", "		self::$decision = 'off';\n		if ( ! self::active() ) {\n			return;", "		self::$decision = 'off';\n		if ( false ) {\n			return;", [I]),
    ("preview for everyone", "includes/class-replace.php", "if ( $admin && isset( $_GET['tth_preview'] ) ) {", "if ( isset( $_GET['tth_preview'] ) ) {", [S, I]),
    ("template allowlist ignored", "includes/class-replace.php", "if ( array() !== array_diff( $ids, $allowed ) ) {", "if ( false ) {", [I]),
    ("emptied-queue verification skipped", "includes/class-replace.php", "if ( array() !== (array) $manager->get_documents_for_location( 'header' ) ) {", "if ( false ) {", [I]),
    ("header printed although nothing was removed", "includes/class-replace.php", "		if ( array() === self::$removed ) {", "		if ( false ) {", [I]),
    ("explanation comment shown to visitors", "includes/class-replace.php", "		if ( current_user_can( Settings::capability() ) ) {\n			echo \"\\n<!-- tecteb-header: \"", "		if ( true ) {\n			echo \"\\n<!-- tecteb-header: \"", [I]),
    ("start hook not last", "includes/class-replace.php", "'start' ), PHP_INT_MAX", "'start' ), 0", [S]),
    ("non-header template accepted", "includes/class-settings.php", "if ( Elementor::is_header_template( $tid ) ) {", "if ( true ) {", [I]),
    ("replacement on without a template", "includes/class-settings.php", "if ( $out['replace'] && array() === $out['replace_templates'] ) {", "if ( false ) {", [I]),
    ("WP Rocket purge dropped", "includes/class-cache.php", "			rocket_clean_domain();\n", "", [S, I]),
    ("font URL relative again", "includes/class-assets.php", "esc_url_raw( TECTEB_HEADER_URL . 'assets/fonts/Vazirmatn-wght.woff2' )", "'../fonts/Vazirmatn-wght.woff2'", [I]),
    ("custom-link URL matching off", "includes/class-menu.php", "if ( isset( $by_path[ self::path_key( $url ) ] ) && '/' !== self::path_key( $url ) ) {", "if ( false ) {", [I]),
    ("title-only matches imported as certain", "includes/class-menu.php", "! in_array( $r['match'], array( 'taxonomy', 'url' ), true ) || isset( $seen", "! in_array( $r['match'], array( 'taxonomy', 'url', 'name' ), true ) || isset( $seen", [I]),
    ("cart fragments off by default", "includes/class-settings.php", "'cart_fragments'     => true,", "'cart_fragments'     => false,", [I]),
    ("category name not escaped", "templates/header.php", "<span class=\"tth-mega__name\"><?php echo esc_html( $tth_c['name'] ); ?>", "<span class=\"tth-mega__name\"><?php echo $tth_c['name']; ?>", [S, I]),
    ("drawer from another source", "templates/header.php", "<?php foreach ( $tth_cats as $tth_c ) : ?>\n\t\t\t\t<details", "<?php foreach ( array_reverse( $tth_cats ) as $tth_c ) : ?>\n\t\t\t\t<details", [I]),
    ("fake wishlist count", "includes/class-account.php", "'count' => is_int( $count ) && $count >= 0 ? $count : null,", "'count' => 2,", [I]),
    ("javascript: URLs accepted", "includes/class-settings.php", "if ( '' !== $clean && preg_match( '#^https?://#i', $clean ) ) {", "if ( '' !== $value ) {\n\t\t\treturn $value;\n\t\t}\n\t\tif ( '' !== $clean ) {", [I]),
    ("always create own «تک‌طب» menu", "includes/class-admin.php", "$found = (string) $item[2];", "$found = '';", [I]),
    ("no cache bump on category edit", "includes/class-cache.php", "		if ( self::configured( (int) $term_id ) ) {\n			self::bump();", "		if ( false ) {\n			self::bump();", [I]),
    ("weaker capability", "includes/class-settings.php", "'tecteb_header_capability', 'manage_options'", "'tecteb_header_capability', 'read'", [I]),
    ("tab form resets other settings", "includes/class-settings.php", "		$out = $current;\n", "		$out = self::defaults();\n", [I]),
    ("store category names", "includes/class-settings.php", "'icon_id'  => self::image_id( $c['icon_id'] ?? 0, 'icon-' . $id, $errors ),", "'icon_id'  => self::image_id( $c['icon_id'] ?? 0, 'icon-' . $id, $errors ),\n\t\t\t\t'name'     => $term->name,", [I]),
    ("unscoped CSS rule", "assets/css/tecteb-header.css", "/* جست‌وجوی سفارشی", "body { margin: 0; }\n/* جست‌وجوی سفارشی", [S]),
    ("approved header.js edited", "assets/js/header.js", "open(d), 140);", "open(d), 300);", [S]),
    ("default categories pre-filled with a sample ID", "includes/class-settings.php", "'categories'         => array(),", "'categories'         => array( array( 'term_id' => 101, 'enabled' => true, 'icon_id' => 0, 'children' => array() ) ),", [S, I]),
    # 0.2.1
    ("double header: finish prints although Elementor printed", "includes/class-replace.php", "! method_exists( $manager, 'did_location' ) || $manager->did_location( 'header' ) ) {", "! method_exists( $manager, 'did_location' ) ) {", [I]),
    ("double header: header printed earlier not checked", "includes/class-replace.php", "		if ( $manager->did_location( 'header' ) ) {", "		if ( false ) {", [I]),
    ("CSS isolation layer dropped", "assets/css/header.css", " :where(:not(svg, svg *, img))::after { all: revert; }", " :where(:not(svg, svg *, img))::after { }", [S, ISO]),
    ("header root id dropped", "templates/header.php", "<div id=\"tth-root\" class=", "<div class=", [S, ISO]),
    ("inspector writes an option", "includes/class-inspect.php", "		$out['plugins']     = self::plugins();", "		$out['plugins']     = self::plugins();\n\t\tupdate_option( 'tthh_x', 1 );", [S, I]),
    ("inspector creates the upload dir", "includes/class-inspect.php", "wp_upload_dir( null, false )", "wp_upload_dir()", [S]),
    ("inspector prints all WP Rocket settings", "includes/class-inspect.php", "array_intersect_key( $rocket, array_flip( self::ROCKET_KEYS ) )", "$rocket", [I]),
    # 0.2.2
    ("site-wide header: last in Elementor's order instead of first", "includes/class-elementor.php", "'printed'    => $ids[0] ?? 0,", "'printed'    => $ids ? end( $ids ) : 0,", [I]),
    ("site-wide header: unpublished template counted", "includes/class-elementor.php", "&& 'publish' === get_post_status( (int) $id ) ) {\n\t\t\t\t$ids[] = (int) $id;", ") {\n\t\t\t\t$ids[] = (int) $id;", [I]),
    ("admin: no error when the printed template is unselected", "includes/class-admin.php", "if ( $site['printed'] && ! in_array( $site['printed'], $allowed, true ) ) {", "if ( false ) {", [I]),
    ("comment: queue/selection detail dropped", "includes/class-replace.php", "( '' !== self::$detail ? ' (' . self::$detail . ')' : '' )", "''", [I]),
    # 0.2.3
    ("menu row: long menu no longer wraps (links run under «همکاری تأمین‌کنندگان»)", "assets/css/header.css", "  display: flex;\n  flex-wrap: wrap;\n  align-items: center;\n  gap: 0 clamp(0.25rem, 1.2vw, 1rem);", "  display: flex;\n  align-items: center;\n  gap: 0 clamp(0.25rem, 1.2vw, 1rem);", [NAV]),
    # 0.2.4
    ("compact desktop: one-row grid dropped (sticky bar back to two rows)", "assets/css/header.css", "    #tth-root.tecteb-header-v1[data-js].is-compact .tth-bar {\n      display: grid;", "    #tth-root.tecteb-header-v1[data-js].is-compact .tth-bar {\n      display: block;", [compact]),
    ("compact desktop: burger shown again", "assets/css/header.css", "    #tth-root.tecteb-header-v1[data-js].is-compact .tth-mega { inset-inline: 0; }\n", "    #tth-root.tecteb-header-v1[data-js].is-compact .tth-mega { inset-inline: 0; }\n    #tth-root.tecteb-header-v1[data-js].is-compact .tth-burger { display: inline-flex; }\n", [compact]),
    ("compact desktop: menu row not full height (mega menu opens over the bar)", "assets/css/header.css", ".tth-nav__row { align-self: stretch; min-block-size: 0; }", ".tth-nav__row { min-block-size: 0; }", [compact]),
    ("compact desktop: menu row covers the bar and takes the clicks", "assets/css/header.css", ".is-compact .tth-nav { background: none; border: 0; pointer-events: none; }", ".is-compact .tth-nav { background: none; border: 0; }", [compact]),
    ("compact desktop: categories button hidden with the menu links", "assets/css/header.css", ".tth-nav :is(.tth-menu, .tth-seller) { display: none; }", ".tth-nav :is(.tth-menu, .tth-seller, .tth-cats) { display: none; }", [compact]),
    # 0.2.5
    ("early JS mode: inline script removed (header paints in its tall no-JS form first; CLS)", "templates/header.php", "r.setAttribute('data-js','');addEventListener", "addEventListener", [S, early]),
    ("early JS mode: no fallback when header.js never runs (dead buttons)", "templates/header.php", "if(!r.hasAttribute('data-tth-ready'))r.removeAttribute('data-js');", "", [S, early]),
    ("early JS mode: ignores a delayed/rewritten header.js", "templates/header.php", "if(!r||!s||(s.type&&!/^(text\\/javascript|module)$/i.test(s.type)))return;", "if(!r)return;", [S, early]),
    ("ready marker dropped from header.js", "assets/js/header.js", "    root.setAttribute('data-tth-ready', '');", "", [S, early]),
    ("bar logo loses fetchpriority=high", "templates/header.php", 'width="96" height="96" alt="" fetchpriority="high">', 'width="96" height="96" alt="">', [S]),
    ("tecteb-header.css back to a render-blocking file", "includes/class-assets.php", "wp_register_style( 'tecteb-header', false, array( 'tecteb-header-base' ), $v );", "wp_register_style( 'tecteb-header', TECTEB_HEADER_URL . 'assets/css/tecteb-header.css', array( 'tecteb-header-base' ), $v );", [S]),
    # ۰٫۲٫۶ — دارایی‌های قالب جایگزین‌شده
    ("replaced header's CSS and assets loaded again (filter never hides it)", "includes/class-replace.php", "return ( 'header' === $location && in_array( (int) $template_id, self::$assets_skipped, true ) ) ? 0 : $template_id;", "return $template_id;", [S, EL]),
    ("hiding filter left on after Pro's enqueue step (header vanishes at render)", "includes/class-replace.php", "\t\tremove_filter( 'elementor/theme/get_location_templates/template_id', array( self::class, 'hide_from_enqueue' ), PHP_INT_MAX );\n", "", [S, EL]),
    ("no restore when the replacement backs out at render (start)", "includes/class-replace.php", "\t\t\t// Elementor هدر خودش را چاپ می‌کند: آنچه در <head> بارگذاری نشد برگردد (0.2.6).\n\t\t\tself::restore_skipped_assets();", "\t\t\t// Elementor هدر خودش را چاپ می‌کند: آنچه در <head> بارگذاری نشد برگردد (0.2.6).", [S, EL]),
    ("no restore when Elementor prints its header after all (finish)", "includes/class-replace.php", "\t\t\tself::$decision = 'elementor_printed';\n\t\t\tself::restore_skipped_assets();", "\t\t\tself::$decision = 'elementor_printed';", [S, EL]),
    ("assets skipped even when the queue holds a template not selected for replacement", "includes/class-replace.php", "if ( array() === $ids || array() !== array_diff( $ids, $allowed ) || in_array( (int) get_the_ID(), $ids, true ) ) {", "if ( array() === $ids || in_array( (int) get_the_ID(), $ids, true ) ) {", [S, EL]),
    ("assets skipped on the header template's own page", "includes/class-replace.php", "if ( array() === $ids || array() !== array_diff( $ids, $allowed ) || in_array( (int) get_the_ID(), $ids, true ) ) {", "if ( array() === $ids || array() !== array_diff( $ids, $allowed ) ) {", [S]),
    ("assets skipped when the replacement is off", "includes/class-replace.php", "\t\tself::$assets_skipped = array();\n\t\tif ( ! self::active() ) {\n\t\t\treturn;\n\t\t}\n\t\t$conditions = self::conditions_manager();", "\t\tself::$assets_skipped = array();\n\t\t$conditions = self::conditions_manager();", [S, EL]),
    ("write a term", "includes/class-cache.php", "	public static function bump(): void {\n", "	public static function bump(): void {\n\t\twp_update_term( 1, 'product_cat', array() );\n", [S]),
]

survived, broken = [], []
# python3 tests/falsify.py [n …] — فقط همان جهش‌ها (شمارهٔ ۱-مبنا)؛ بی آرگومان همه.
picks = [int(a) for a in sys.argv[1:]] or list(range(1, len(MUTATIONS) + 1))
for name, rel, needle, repl, suites in [MUTATIONS[i - 1] for i in picks]:
    path = SRC / rel
    orig = path.read_bytes()
    digest = hashlib.sha256(orig).hexdigest()
    text = orig.decode("utf-8")
    if text.count(needle) != 1:
        broken.append(name)
        print(f"BROKEN    {name}  (needle found {text.count(needle)}×)")
        continue
    try:
        path.write_text(text.replace(needle, repl), encoding="utf-8")
        caught = [s.__name__ for s in suites if not s()]
    finally:
        path.write_bytes(orig)
    assert hashlib.sha256(path.read_bytes()).hexdigest() == digest
    if caught:
        print(f"caught    {name}  ← {', '.join(caught)}")
    else:
        survived.append(name)
        print(f"SURVIVED  {name}")

subprocess.run(WP + ["eval-file", str(HERE / "tests/fixtures/seed.php"), "seed"], capture_output=True)
print(f"\n{len(picks) - len(survived) - len(broken)}/{len(picks)} caught, {len(survived)} survived, {len(broken)} not applied")
sys.exit(1 if survived or broken else 0)
