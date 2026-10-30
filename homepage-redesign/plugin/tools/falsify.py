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
    ("featured flag dropped from the product query", "includes/class-catalog.php",
     "\t\t\t'featured'   => true,\n", "", "integration"),
    ("catalog visibility dropped (hidden products leak)", "includes/class-catalog.php",
     "\t\t\t'visibility' => 'catalog',\n\t\t\t'limit'      => $limit,", "\t\t\t'limit'      => $limit,", "integration"),
    ("quote button printed without a destination", "templates/products.php",
     "<?php if ( '' !== $links['quote'] ) : ?>\n\t\t\t\t\t\t\t\t<a class=\"th-product__quote\"", "<?php if ( true ) : ?>\n\t\t\t\t\t\t\t\t<a class=\"th-product__quote\"", "integration"),
    ("any URL accepted (javascript:)", "includes/class-settings.php",
     "\t\t$parts = wp_parse_url( $value );", "\t\treturn $value;\n\t\t$parts = wp_parse_url( $value );", "integration"),
    ("services shown while switched off", "includes/class-renderer.php",
     "$services = $s['services_enabled'] && array() !== $s['services'];", "$services = array() !== $s['services'];", "integration"),
    ("supplier section without a link", "includes/class-renderer.php",
     "'show'   => '' !== $links['supplier'],", "'show'   => true,", "integration"),
    ("category name printed unescaped", "templates/categories.php",
     "<?php echo esc_html( $category['name'] ); ?>", "<?php echo $category['name']; ?>", "integration"),
    ("assets loaded on every page", "includes/class-assets.php",
     "return (bool) apply_filters( 'tecteb_home_should_enqueue', $uses );", "return true;", "integration"),
    ("parent category counted by direct products only", "includes/class-catalog.php",
     "if ( ! $term instanceof \\WP_Term || ! self::has_products( $term ) ) {", "if ( ! $term instanceof \\WP_Term || (int) $term->count < 1 ) {", "integration"),
    ("a write slipped into the reader", "includes/class-catalog.php",
     "\tpublic static function featured_total(): int {\n", "\tpublic static function featured_total(): int {\n\t\tupdate_post_meta( 0, '_tth_x', '1' );\n", "static"),
    ("Dokan dependency", "includes/class-renderer.php",
     "\tpublic static function shop_url(): string {\n", "\tpublic static function shop_url(): string {\n\t\tif ( function_exists( 'dokan' ) ) { return ''; }\n", "static"),
    ("heading hardening removed (theme/kit leak)", "assets/css/tecteb-home.css",
     ".tecteb-home-v1.th-c :where(h1, h2, h3) {\n  margin: 0;\n  font-family: inherit;\n  font-style: normal;\n  letter-spacing: normal;\n  text-transform: none;",
     ".tecteb-home-v1.th-c :where(h1, h2, h3) {\n  margin: 0;\n  font-family: inherit;", "browser"),
]


def run(kind: str) -> tuple[int, str]:
    if kind == "static":
        p = subprocess.run([sys.executable, str(PLUGIN / "tests/static/check.py")], capture_output=True, text=True)
    elif kind == "integration":
        subprocess.run(WP + ["eval-file", "tests/fixtures/seed.php", "seed"], cwd=PLUGIN, capture_output=True)
        p = subprocess.run(WP + ["eval-file", "tests/integration/run.php"], cwd=PLUGIN, capture_output=True, text=True)
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
        if text.count(needle) != 1:
            print(f"{n:2} NOT APPLIED ({text.count(needle)} matches) — {name}")
            bad += 1
            continue
        try:
            path.write_text(text.replace(needle, repl), encoding="utf-8")
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
