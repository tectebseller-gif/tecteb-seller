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
import re
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
     # ۰٫۳٫۲: قیمت حالا از Price::of() است؛ همان گارد روی شاخهٔ «یک مبلغ».
     "<span class=\"th-price__part\"><span class=\"th-price__now\"><?php echo Renderer::price_html( $price['now'] );", "<span class=\"th-price__part\"><span class=\"th-price__now\"><?php echo wp_kses_post( $price['now'] );", "integration"),
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
    # ۰٫۳٫۲: قیمت و جست‌وجو
    ("price guard dropped: a price hidden by the site is shown anyway", "includes/class-price.php",
     "\t\tif ( '' === $shown ) {\n\t\t\treturn array( 'kind' => 'none' );\n\t\t}\n", "", "integration"),
    ("price guard dropped: our number shown although the site replaced the price text", "includes/class-price.php",
     # ۰٫۳٫۳: گارد حالا خودش wc_price() ِ قالب سایت را می‌سازد.
     "if ( ! str_contains( $shown, self::visible_text( wc_price( $model[ $key ] ) ) ) ) {", "if ( false ) {", "integration"),
    ("sale ignored (old price lost, discount invisible)", "includes/class-price.php",
     "\t\tif ( $product->is_on_sale() ) {", "\t\tif ( false ) {", "integration"),
    ("variable range collapsed to one price", "includes/class-price.php",
     "\t\t\tif ( $min !== $max ) {", "\t\t\tif ( false ) {", "integration"),
    ("card back to raw get_price_html() (English screen-reader text)", "includes/class-catalog.php",
     "'price'    => Price::of( $product ),", "'price'    => array( 'kind' => 'text', 'text' => wp_strip_all_tags( $product->get_price_html() ) ),", "integration"),
    ("old price no longer struck through", "assets/css/tecteb-home.css",
     "  text-decoration: line-through;\n  text-decoration-thickness: 1px;", "  text-decoration: none;\n  text-decoration-thickness: 1px;", "prices"),
    ("Hero category dropdown back in the search form", "templates/hero.php",
     '\t\t\t\t<input type="hidden" name="post_type" value="product">\n',
     '\t\t\t\t<select class="th-search__select" name="product_cat"><option value="">همهٔ دسته‌ها</option></select>\n\t\t\t\t<input type="hidden" name="post_type" value="product">\n', "prices"),
    # ۰٫۳٫۳
    ("Hero «بازار B2B» label printed again", "templates/hero.php",
     '\t\t\t<h1 class="th-hero__title th-in"', '\t\t\t<p class="th-pill">بازار B2B تجهیزات پزشکی</p>\n\t\t\t<h1 class="th-hero__title th-in"', "integration"),
    ("Hero label linked to a category with no products", "includes/class-catalog.php",
     "if ( ! self::has_products( $term ) ) {", "if ( false ) {", "integration"),
    ("ambiguous Hero label guessed (first match taken)", "includes/class-catalog.php",
     [("1 === count( $matches ) || 1 === count( $roots )", "array() !== $matches"),
      ("$term = 1 === count( $matches ) ? $matches[0] : $roots[0];", "$term = $matches[0];")], None, "integration"),
    ("Hero labels out of the required order", "includes/class-settings.php",
     "\t\tarray( 'آزمایشگاه', 'lab' ),\n\t\tarray( 'جراحی', 'or' ),", "\t\tarray( 'جراحی', 'or' ),\n\t\tarray( 'آزمایشگاه', 'lab' ),", "integration"),
    ("price shown in the site format (no thousands separator, no space)", "includes/class-price.php",
     "wc_price( $model[ $key ], $args )", "wc_price( $model[ $key ] )", "integration"),
    ("price guard compared in the homepage format (every price falls back to raw text)", "includes/class-price.php",
     "self::visible_text( wc_price( $model[ $key ] ) )", "self::visible_text( wc_price( $model[ $key ], self::display_args() ) )", "integration"),
    ("inactive brand shown", "includes/class-catalog.php",
     "empty( $b['active'] ) ||", "", "integration"),
    ("non-image logo accepted", "includes/class-settings.php",
     "if ( $logo > 0 && ! wp_attachment_is_image( $logo ) ) {", "if ( false ) {", "integration"),
    ("brand URL not sanitised (javascript: kept)", "includes/class-settings.php",
     "$clean = self::clean_url( $url );", "$clean = $url;", "integration"),
    ("empty brands section printed", "includes/class-renderer.php",
     "'show'   => array() !== $brands,", "'show'   => true,", "integration"),
    ("carousel buttons printed live without the script (dead buttons without JS)", "templates/brands.php",
     "data-th-brands-nav hidden", "data-th-brands-nav", "integration"),
    ("carousel ignores RTL (next scrolls the wrong way)", "assets/js/tecteb-home.js",
     "left: rtl ? -step : step", "left: step", "herobrands"),
    ("Hero labels keep the corner layout on a narrow image (cover the monitor)", "assets/css/tecteb-home.css",
     "container: th-art / inline-size;", "", "herobrands"),
    ("brand logo stretched to its frame", "assets/css/tecteb-home.css",
     [("  inline-size: var(--th-logo-w, auto);\n  block-size: auto;\n  max-inline-size: 100%;\n  max-block-size: 100%;\n  object-fit: contain;", "  inline-size: 100%;\n  block-size: 100%;\n  max-inline-size: 100%;\n  max-block-size: 100%;\n  object-fit: fill;")], None, "herobrands"),
    # ۰٫۳٫۴
    ("inactive Hero label printed", "includes/class-catalog.php",
     "\t\t\tif ( ! $row['active'] ) {", "\t\t\tif ( false ) {", "integration"),
    ("Hero title tied to the category name (admin title ignored)", "includes/class-catalog.php",
     "\t\t\t\tif ( '' === $row['name'] ) {\n\t\t\t\t\t$row['name'] = (string) $term->name;", "\t\t\t\tif ( true ) {\n\t\t\t\t\t$row['name'] = (string) $term->name;", "integration"),
    ("Hero order from the panel ignored", "includes/class-catalog.php",
     "\t\tusort( $out, static fn( array $a, array $b ): int => array( $a['order'], $a['index'] ) <=> array( $b['order'], $b['index'] ) );\n\t\treturn $out;", "\t\treturn $out;", "integration"),
    ("a hidden Hero label leaves a hole (positions not filled in order)", "templates/hero.php",
     "$floats     = array_values( array_filter( $plan['hero_labels'], static fn( array $label ): bool => 'ok' === $label['status'] ) );", "$floats     = array_filter( $plan['hero_labels'], static fn( array $label ): bool => 'ok' === $label['status'] );", "integration"),
    ("upgrade from 0.3.3 drops its pinned category", "includes/class-settings.php",
     "\t\t\t\t$out[ $i ]['term']  = $term;\n", "", "integration"),
    ("hero_labels no longer mirrors the rows (a downgrade to 0.3.3 loses the categories)", "includes/class-settings.php",
     "$out['hero_labels'] = array_map( static fn( array $row ): int => $row['term'], $out['hero_items'] );", "$out['hero_labels'] = self::hero_labels( $in['hero_labels'] ?? array(), false );", "integration"),
    ("a deleted pinned category silently re-pointed at the same-named one", "includes/class-settings.php",
     "if ( $report && $id > 0 && ! ( get_term( $id, 'product_cat' ) instanceof \\WP_Term ) ) {", "if ( $id > 0 && ! ( get_term( $id, 'product_cat' ) instanceof \\WP_Term ) ) {", "integration"),
    ("«برای برچسب …» from the search results ignored", "includes/class-settings.php",
     "\t\t\t$rows[ (int) $m[1] ]['term'] = (int) $m[2];\n", "", "integration"),
    ("Hero category ID accepted without checking it is a product category", "includes/class-settings.php",
     "if ( $report && $id > 0 && ! ( get_term( $id, 'product_cat' ) instanceof \\WP_Term ) ) {", "if ( false ) {", "integration"),
    ("Hero title not sanitised (markup kept)", "includes/class-settings.php",
     "'title'  => self::text( $row['title'] ?? '', 40 ),", "'title'  => (string) ( $row['title'] ?? '' ),", "integration"),
    ("long Hero titles not capped (labels run into each other and the device)", "assets/css/tecteb-home.css",
     "max-inline-size: calc(50% - 0.375rem); }", "max-inline-size: none; }", "adminhero"),
    ("brand logo sizes not balanced (each fills its frame)", "includes/class-catalog.php",
     "\t\t\t\t$attrs['style'] = '--th-logo-w:' . self::logo_width( (int) $src[1] / (int) $src[2] );\n", "", "integration"),
    # ۰٫۳٫۵ — «محصولات منتخب»: ردیف دکمه‌های دسته برداشته، «فروشگاه» فقط در موبایل پنهان.
    ("0.3.5: category filter row printed again in the products section", "templates/products.php",
     "\t\t<ul class=\"th-products\"", "\t\t<nav class=\"th-filter\"><ul><li><a class=\"th-chip th-chip--on\" href=\"#\">همه</a></li></ul></nav>\n\t\t<ul class=\"th-products\"", "integration"),
    ("0.3.5: «فروشگاه» eyebrow not hidden on mobile", "assets/css/tecteb-home.css",
     ".th-head--shop .th-eyebrow { display: none; }", ".th-head--shop .th-eyebrow { }", "products"),
    ("0.3.5: «فروشگاه» eyebrow hidden on tablet and desktop too", "assets/css/tecteb-home.css",
     "@container th-home (max-width: 599px) {\n  .tecteb-home-v1:is(.tecteb-home-v1, #th-boost).th-c .th-head--shop", "@container th-home (min-width: 0px) {\n  .tecteb-home-v1:is(.tecteb-home-v1, #th-boost).th-c .th-head--shop", "products"),
    ("0.3.5: «ورود به فروشگاه» dropped from the products section", "templates/products.php",
     "<a class=\"th-more\" href=\"<?php echo esc_url( $links['shop'] ); ?>\"><?php echo esc_html__( 'ورود به فروشگاه', 'tecteb-homepage' ); ?>", "<a class=\"th-more-x\" href=\"<?php echo esc_url( $links['shop'] ); ?>\"><?php echo esc_html__( 'ورود به فروشگاه', 'tecteb-homepage' ); ?>", "products"),
    # ۰٫۳٫۶ — سرعت
    ("0.3.6: supplier eyebrow logo eager again (PNG in the initial load)", "templates/supplier.php",
     "assets/img/tecteb-logo-mark-96.webp' ); ?>\" width=\"20\" height=\"20\" alt=\"\" loading=\"lazy\" decoding=\"async\">", "assets/img/tecteb-logo-mark-96.png' ); ?>\" width=\"20\" height=\"20\" alt=\"\">", "static"),
    ("0.3.6: comments back in the shipped stylesheet", "assets/css/tecteb-home.css",
     ".tecteb-home-v1:is(.tecteb-home-v1, #th-boost).th-c .th-head--shop .th-eyebrow { display: none; }", "/* x */ .tecteb-home-v1:is(.tecteb-home-v1, #th-boost).th-c .th-head--shop .th-eyebrow { display: none; }", "static"),
]


def run(kind: str) -> tuple[int, str]:
    if kind == "static":
        p = subprocess.run([sys.executable, str(PLUGIN / "tests/static/check.py")], capture_output=True, text=True)
    elif kind == "integration":
        subprocess.run(WP + ["eval-file", "tests/fixtures/seed.php", "seed"], cwd=PLUGIN, capture_output=True)
        p = subprocess.run(WP + ["eval-file", "tests/integration/run.php"], cwd=PLUGIN, capture_output=True, text=True)
    elif kind == "herobrands":
        for a in (["tests/fixtures/seed.php", "seed"], ["tests/fixtures/state.php", "full"]):
            subprocess.run(WP + ["eval-file"] + a, cwd=PLUGIN, capture_output=True)
        env = dict(os.environ, AXE=AXE, NODE_PATH="/opt/node-tools/node_modules", OUT=str(PLUGIN / "tests/evidence/falsification-browser"))
        p = subprocess.run(["node", "tests/browser/hero-brands.mjs"], cwd=PLUGIN, capture_output=True, text=True, env=env)
    elif kind == "adminhero":
        for a in (["tests/fixtures/seed.php", "seed"], ["tests/fixtures/state.php", "full"]):
            subprocess.run(WP + ["eval-file"] + a, cwd=PLUGIN, capture_output=True)
        env = dict(os.environ, AXE=AXE, NODE_PATH="/opt/node-tools/node_modules", OUT=str(PLUGIN / "tests/evidence/falsification-browser"))
        p = subprocess.run(["node", "tests/browser/admin-hero-labels.mjs"], cwd=PLUGIN, capture_output=True, text=True, env=env)
    elif kind == "prices":
        for a in (["tests/fixtures/seed.php", "seed"], ["tests/fixtures/state.php", "full"], ["tests/fixtures/prices.php", "seed"]):
            subprocess.run(WP + ["eval-file"] + a, cwd=PLUGIN, capture_output=True)
        env = dict(os.environ, AXE=AXE, NODE_PATH="/opt/node-tools/node_modules", OUT=str(PLUGIN / "tests/evidence/falsification-browser"))
        p = subprocess.run(["node", "tests/browser/prices.mjs"], cwd=PLUGIN, capture_output=True, text=True, env=env)
        subprocess.run(WP + ["eval-file", "tests/fixtures/prices.php", "reset"], cwd=PLUGIN, capture_output=True)
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
    elif kind == "products":
        subprocess.run(WP + ["eval-file", "tests/fixtures/seed.php", "seed"], cwd=PLUGIN, capture_output=True)
        env = dict(os.environ, NODE_PATH="/opt/node-tools/node_modules", LABEL="falsify-tmp")
        p = subprocess.run(["node", "tests/browser/products-head.mjs"], cwd=PLUGIN, capture_output=True, text=True, env=env)
        for f in (PLUGIN / "tests/evidence/products-head").glob("falsify-tmp*"):
            f.unlink()
    else:
        subprocess.run(WP + ["eval-file", "tests/fixtures/seed.php", "seed"], cwd=PLUGIN, capture_output=True)
        env = dict(os.environ, AXE=AXE, NODE_PATH="/opt/node-tools/node_modules", OUT=str(PLUGIN / "tests/evidence/falsification-browser"))
        p = subprocess.run(["node", "tests/browser/run.mjs"], cwd=PLUGIN, capture_output=True, text=True, env=env)
    fails = [ln for ln in p.stdout.splitlines() if ln.startswith("FAIL")]
    # سوییتی که بی خط جمع‌بندی تمام شود مرده است (خطای مرگبار زیر جهش): این هم «گرفته شد» است، نه «جان سالم برد».
    if not fails and not re.search(r"^\d+ passed, \d+ failed$", p.stdout, re.M):
        tail = (p.stdout + p.stderr).strip().splitlines()
        return 1, "CRASHED before the summary — " + (tail[-1][:120] if tail else "")
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
