#!/usr/bin/env python3
"""
Falsification: هر گارد مهم یک بار شکسته می‌شود و باید دست‌کم یک آزمون قرمز شود.

    python3 homepage-redesign/plugin/tools/falsify.py [n ...]

هر جهش روی یک فایل اعمال، سوییت مربوط اجرا، و فایل **بایت‌به‌بایت** برگردانده
می‌شود (در finally، پس قطع اجرا هم جهش را جا نمی‌گذارد). جهشی که needle اش در
فایل نیست یا بیش از یک بار هست، خودش شکست است — «هیچ‌چیز سنجیده نشد» نباید
شبیه «گارد شکستنی نیست» باشد. فقط روی وردپرس یکبارمصرف محلی.
"""
from __future__ import annotations

import hashlib
import os
import pathlib
import subprocess
import sys

PLUGIN = pathlib.Path(__file__).resolve().parents[1]
SRC = PLUGIN / "tecteb-homepage"
WP = os.environ.get("WP", "wp --allow-root --path=/home/user/wp/core").split()
AXE = os.environ.get("AXE", "")

MUTATIONS = [
    ("selected IDs ignored (query not restricted to the selection)", "includes/class-catalog.php",
     "\t\t\t'include'    => array_values( array_map( 'intval', $ids ) ),\n", "", "integration"),
    ("catalog visibility dropped (hidden products leak)", "includes/class-catalog.php",
     "\t\t\t'include'    => array_values( array_map( 'intval', $ids ) ),\n\t\t\t'status'     => 'publish',\n\t\t\t'visibility' => 'catalog',\n",
     "\t\t\t'include'    => array_values( array_map( 'intval', $ids ) ),\n\t\t\t'status'     => 'publish',\n", "integration"),
    ("publish status dropped (drafts leak)", "includes/class-catalog.php",
     "\t\t\t'include'    => array_values( array_map( 'intval', $ids ) ),\n\t\t\t'status'     => 'publish',\n",
     "\t\t\t'include'    => array_values( array_map( 'intval', $ids ) ),\n", "integration"),
    ("hide-out-of-stock ignored", "includes/class-catalog.php",
     "\t\t\t$args['stock_status'] = array( 'instock', 'onbackorder' );\n", "", "integration"),
    ("admin order ignored (WooCommerce order used)", "includes/class-catalog.php",
     "\t\tforeach ( $ids as $id ) {\n\t\t\tif ( isset( $found[ $id ] ) ) {",
     "\t\tforeach ( array_keys( $found ) as $id ) {\n\t\t\tif ( isset( $found[ $id ] ) ) {", "integration"),
    ("display count ignored", "includes/class-catalog.php",
     "\t\t\tif ( count( $out ) >= (int) $s['product_count'] ) {", "\t\t\tif ( count( $out ) >= 99 ) {", "integration"),
    ("featured auto-fill reintroduced", "includes/class-catalog.php",
     "\t\tif ( array() === $ids ) {\n\t\t\treturn array();\n\t\t}\n\t\t$found",
     "\t\tif ( array() === $ids ) {\n\t\t\t$ids = wc_get_products( array( 'featured' => true, 'status' => 'publish', 'return' => 'ids' ) );\n\t\t}\n\t\t$found", "integration"),
    ("any post ID accepted as a product", "includes/class-settings.php",
     "\t\treturn 'product' === get_post_type( $id ) && 'trash' !== get_post_status( $id );",
     "\t\treturn $id > 0;", "integration"),
    ("product price printed through wp_kses_post (bdi lost)", "templates/products.php",
     "<?php echo Renderer::price_html( $product['price'] );", "<?php echo wp_kses_post( $product['price'] );", "integration"),
    ("repair card shown without a destination", "includes/class-renderer.php",
     "'repair'   => array( 'show' => '' !== $links['repair'],", "'repair'   => array( 'show' => true,", "integration"),
    ("supplier card without a destination", "includes/class-renderer.php",
     "'supplier' => array( 'show' => '' !== $links['supplier'],", "'supplier' => array( 'show' => true,", "integration"),
    ("shop URL without the page guard", "includes/class-renderer.php",
     "\t\tif ( $id < 1 || 'page' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {\n\t\t\treturn '';\n\t\t}\n",
     "", "integration"),
    ("any URL accepted (javascript:)", "includes/class-settings.php",
     "\t\t$parts = wp_parse_url( $value );", "\t\treturn $value;\n\t\t$parts = wp_parse_url( $value );", "integration"),
    ("category name printed unescaped", "templates/categories.php",
     "<?php echo esc_html( $category['name'] ); ?>", "<?php echo $category['name']; ?>", "integration"),
    ("assets loaded on every page", "includes/class-assets.php",
     "return (bool) apply_filters( 'tecteb_home_should_enqueue', $uses );", "return true;", "integration"),
    ("parent category counted by direct products only", "includes/class-catalog.php",
     "if ( ! $term instanceof \\WP_Term || ! self::has_products( $term ) ) {", "if ( ! $term instanceof \\WP_Term || (int) $term->count < 1 ) {", "integration"),
    ("a write slipped into the reader", "includes/class-catalog.php",
     "\tpublic static function selected_products(): array {\n", "\tpublic static function selected_products(): array {\n\t\tupdate_post_meta( 0, '_tth_x', '1' );\n", "static"),
    ("Dokan dependency", "includes/class-renderer.php",
     "\tpublic static function shop_url(): string {\n", "\tpublic static function shop_url(): string {\n\t\tif ( function_exists( 'dokan' ) ) { return ''; }\n", "static"),
    # ۰٫۳٫۰: لایهٔ ایزوله به‌تنهایی جلوی نشت به عنوان‌ها را می‌گیرد (اندازه‌گیری‌شده: با برداشتن فقط این
    # سخت‌سازی، آزمون ایزوله ۰ تفاوت). پس جهش هر دو لایه را برمی‌دارد و باید آزمون Hello/kit ِ مرورگر بگیردش.
    ("heading hardening and isolation layer both removed (theme/kit leak)", "assets/css/tecteb-home.css",
     [(".tecteb-home-v1:is(.tecteb-home-v1, #th-boost).th-c :where(h1, h2, h3) {\n  margin: 0;\n  font-family: inherit;\n  font-style: normal;\n  letter-spacing: normal;\n  text-transform: none;",
       ".tecteb-home-v1:is(.tecteb-home-v1, #th-boost).th-c :where(h1, h2, h3) {\n  margin: 0;\n  font-family: inherit;"),
      (":where(:not(svg, svg *, img))::after { all: revert; }", ":where(:not(svg, svg *, img))::after { }")], None, "browser"),
    ("route grid layout dropped (three cards not side by side)", "assets/css/tecteb-home.css",
     '  .tecteb-home-v1:is(.tecteb-home-v1, #th-boost).th-c .th-routes[data-count="3"] { grid-template-columns: repeat(3, minmax(0, 1fr)); }\n', '', "browser"),
    # ۰٫۳٫۰
    ("isolation layer dropped (site-wide CSS reaches the homepage)", "assets/css/tecteb-home.css",
     ":where(:not(svg, svg *, img))::after { all: revert; }", ":where(:not(svg, svg *, img))::after { }", "isolation"),
    ("one rule loses its ID-weight boost", "assets/css/tecteb-home.css",
     "\n.tecteb-home-v1:is(.tecteb-home-v1, #th-boost).th-c .th-route {", "\n.tecteb-home-v1.th-c .th-route {", "static"),
    ("repair default dropped (confirmed URL lost)", "includes/class-settings.php",
     "'repair'         => '/https-tecteb-com-repair-request/',", "'repair'         => '',", "integration"),
    ("supplier default points elsewhere", "includes/class-settings.php",
     "'supplier'       => '/landing-seller/',", "'supplier'       => '/supplier/',", "integration"),
    ("saved empty URL silently falls back to the default", "includes/class-settings.php",
     "array_key_exists( $key, $links ) ? $links[ $key ] : $d['links'][ $key ]", "( $links[ $key ] ?? '' ) ?: $d['links'][ $key ]", "integration"),
    ("header font not shared (double download)", "includes/class-assets.php",
     "\t\tif ( ! defined( 'TECTEB_HEADER_URL' ) || ! defined( 'TECTEB_HEADER_DIR' ) ) {", "\t\tif ( true ) {", "integration"),
    ("WP Rocket home purge dropped (stale homepage after a selection change)", "includes/class-cache.php",
     "\t\t\trocket_clean_home();\n", "", "integration"),
    ("WP Rocket purge widened to the whole site", "includes/class-cache.php",
     "\t\t\trocket_clean_home();\n", "\t\t\trocket_clean_home();\n\t\t\tif ( function_exists( 'rocket_clean_domain' ) ) {\n\t\t\t\trocket_clean_domain();\n\t\t\t}\n", "integration"),
    ("hero text claims an unconfirmed side-by-side comparison", "includes/class-settings.php",
     "مشخصات هر محصول را ببینید", "مشخصات را کنار هم ببینید", "integration"),
    # ۰٫۳٫۱: نمایش روی صفحهٔ نخست
    ("front-page takeover ignores the switch", "includes/class-page-template.php",
     "return (bool) Settings::get()['front_page'];", "return true;", "integration"),
    ("an option saved by 0.3.0 (no front_page key) reads as on — sites change on update", "includes/class-settings.php",
     "$out['front_page'] = ! empty( $in['front_page'] );", "$out['front_page'] = (bool) ( $in['front_page'] ?? true );", "integration"),
    ("preview parameter honoured for visitors", "includes/class-page-template.php",
     "if ( $admin && isset( $_GET['tecteb_home_preview'] ) ) {", "if ( isset( $_GET['tecteb_home_preview'] ) ) {", "integration"),
    ("admin 'original' parameter ignored", "includes/class-page-template.php",
     "if ( $admin && isset( $_GET['tecteb_home_original'] ) ) {", "if ( false ) {", "integration"),
    ("shop-page guard removed (shop archive replaced)", "includes/class-page-template.php",
     "if ( '' !== self::front_page_problem() ) {", "if ( false ) {", "integration"),
    ("Elementor editor preview replaced too", "includes/class-page-template.php",
     "isset( $_GET['elementor-preview'] )", "false", "integration"),
    ("takeover also on paginated front page", "includes/class-page-template.php",
     "! is_front_page() || is_paged()", "! is_front_page()", "integration"),
    ("assets not loaded on the taken-over front page", "includes/class-assets.php",
     "$uses = PageTemplate::takes_over_front();", "$uses = false;", "integration"),
]


def run(kind: str) -> tuple[int, str]:
    if kind == "static":
        p = subprocess.run([sys.executable, str(PLUGIN / "tests/static/check.py")], capture_output=True, text=True)
    elif kind == "integration":
        subprocess.run(WP + ["eval-file", "tests/fixtures/seed.php", "seed"], cwd=PLUGIN, capture_output=True)
        p = subprocess.run(WP + ["eval-file", "tests/integration/run.php"], cwd=PLUGIN, capture_output=True, text=True)
    elif kind == "isolation":
        subprocess.run(WP + ["eval-file", "tests/fixtures/seed.php", "seed"], cwd=PLUGIN, capture_output=True)
        subprocess.run(WP + ["eval-file", "tests/fixtures/state.php", "full"], cwd=PLUGIN, capture_output=True)
        subprocess.run(WP + ["eval-file", "tests/fixtures/state.php", "front"], cwd=PLUGIN, capture_output=True)
        env = dict(os.environ, NODE_PATH="/opt/node-tools/node_modules")
        p = subprocess.run(["node", "tests/isolation.mjs", "falsify-tmp"], cwd=PLUGIN, capture_output=True, text=True, env=env)
        for ext in ("txt", "json"):
            (PLUGIN / "tests/evidence/isolation" / f"falsify-tmp.{ext}").unlink(missing_ok=True)
        n = 0 if p.returncode == 0 else 1
        return n, (p.stdout.strip().splitlines() or [""])[0][:140]
    else:
        subprocess.run(WP + ["eval-file", "tests/fixtures/seed.php", "seed"], cwd=PLUGIN, capture_output=True)
        env = dict(os.environ, AXE=AXE, NODE_PATH="/opt/node-tools/node_modules", OUT=str(PLUGIN / "tests/evidence/falsification-browser"))
        p = subprocess.run(["node", "tests/browser/run.mjs"], cwd=PLUGIN, capture_output=True, text=True, env=env)
    fails = [ln for ln in p.stdout.splitlines() if ln.startswith("FAIL")]
    return len(fails), (fails[0][:140] if fails else p.stdout.strip().splitlines()[-1:] and p.stdout.strip().splitlines()[-1])


def main() -> int:
    picks = [int(a) for a in sys.argv[1:]] or list(range(1, len(MUTATIONS) + 1))
    bad = 0
    for n in picks:
        name, rel, needle, repl, kind = MUTATIONS[n - 1]
        path = SRC / rel
        original = path.read_bytes()
        digest = hashlib.sha256(original).hexdigest()
        text = original.decode("utf-8")
        pairs = needle if isinstance(needle, list) else [(needle, repl)]
        counts = [text.count(a) for a, _ in pairs]
        if any(c != 1 for c in counts):
            print(f"{n:2} NOT APPLIED ({counts} matches) — {name}")
            bad += 1
            continue
        for a, b in pairs:
            text = text.replace(a, b)
        try:
            path.write_text(text, encoding="utf-8")
            fails, first = run(kind)
        finally:
            path.write_bytes(original)
        restored = hashlib.sha256(path.read_bytes()).hexdigest() == digest
        caught = fails > 0
        bad += (not caught) or (not restored)
        print(f"{n:2} {'CAUGHT ' if caught else 'SURVIVED'} [{kind}] {fails} failing — {name}{'' if restored else '  ⛔ NOT RESTORED'}\n     first: {first}")
    print(f"\n{len(picks) - bad} of {len(picks)} mutations caught and restored")
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
