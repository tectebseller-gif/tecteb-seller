#!/usr/bin/env python3
"""
آزمون معماری افزونه، از بایت‌های فایل‌ها — بی وردپرس.

مرزهایی که مالک تعیین کرد، اینجا قابل‌سنجش‌اند نه فقط نوشته:
  * هیچ وابستگی به دکان یا Tecteb Marketplace (نام، namespace، پیشوند، جدول).
  * هیچ نوشتنی در ووکامرس/وردپرس: نه متا، نه پست، نه ترم، نه $wpdb، نه save().
    تنها نوشتنِ مجاز، option ِ خودِ افزونه است و آن را register_setting می‌نویسد.
  * هیچ منطق تجاری: نه سبد، نه سفارش، نه ایمیل، نه درخواست شبکه.
  * هیچ loop ِ ووکامرس (قلاب‌هایی که دکان در کارت محصول می‌نویسد).
  * CSS ِ ایزوله: هر انتخابگر زیر .tecteb-home-v1؛ هیچ نشانی بیرونی.
  * هیچ نشانه‌ای از پیش‌نمایش در فایل‌های ارسالی.

کامنت‌های PHP پیش از جست‌وجو خالی می‌شوند (php -r token_get_all)، چون
docblockها همین نام‌ها را برای توضیح می‌آورند.

اجرا:  python3 homepage-redesign/plugin/tests/static/check.py
"""
from __future__ import annotations

import json
import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2] / "tecteb-homepage"
results: list[tuple[bool, str, str]] = []


def check(name: str, ok: bool, detail: str = "") -> None:
    results.append((ok, name, detail))


def php_code(path: pathlib.Path) -> str:
    """کد PHP بی کامنت و بی رشته‌های HTML ِ بیرون از تگ PHP."""
    script = (
        "$t=token_get_all(file_get_contents($argv[1]));$o='';"
        "foreach($t as $x){if(is_array($x)){if(in_array($x[0],[T_COMMENT,T_DOC_COMMENT,T_INLINE_HTML],true))"
        "{$o.=' ';continue;}$o.=$x[1];}else{$o.=$x;}}echo $o;"
    )
    return subprocess.run(["php", "-r", script, str(path)], capture_output=True, text=True, check=True).stdout


php_files = sorted(ROOT.rglob("*.php"))
code = {p.relative_to(ROOT).as_posix(): php_code(p) for p in php_files}
raw = {p.relative_to(ROOT).as_posix(): p.read_text(encoding="utf-8") for p in ROOT.rglob("*") if p.is_file() and p.suffix in {".php", ".css", ".js", ".svg", ".txt"}}

# --- ۱. وابستگی‌ها
for needle, label in [
    (r"(?i)dokan", "دکان"),
    (r"\btmc_", "پیشوند Tecteb Marketplace (tmc_)"),
    (r"Tecteb\\\\?Marketplace", "namespace بازارگاه"),
    (r"tecteb-marketplace-core", "slug بازارگاه"),
    (r"(?i)elementor\\\\|\\\\Elementor\\b|Elementor\\\\Plugin", "کلاس‌های Elementor"),
]:
    hits = [f for f, c in code.items() if re.search(needle, c)]
    check(f"no dependency on {label}", not hits, ", ".join(hits))

# _elementor_data خوانده می‌شود (فقط تشخیص shortcode برای بارگذاری CSS)؛ هیچ API دیگری از Elementor نه.
elementor = sorted({m for c in code.values() for m in re.findall(r"[\"']_?elementor[a-z_]*[\"']", c)})
check("Elementor is only read via the _elementor_data meta key", elementor in ([], ["'_elementor_data'"]), str(elementor))

# --- ۲. هیچ نوشتنی
writes = [
    "update_post_meta", "add_post_meta", "delete_post_meta", "wp_insert_post", "wp_update_post", "wp_delete_post",
    "wp_trash_post(", "wp_insert_term", "wp_update_term", "wp_delete_term", "update_term_meta", "add_term_meta",
    "delete_term_meta", "wp_set_object_terms", "wp_set_post_terms", "set_featured", "set_catalog_visibility",
    "->save(", "$wpdb", "update_option(", "add_option(", "update_user_meta", "wp_insert_user", "wp_update_user",
    "set_transient", "update_site_option", "wp_schedule_event", "flush_rewrite_rules", "add_rewrite_rule",
]
for w in writes:
    hits = [f for f, c in code.items() if w in c]
    check(f"no write: {w}", not hits, ", ".join(hits))

deletes = [f for f, c in code.items() if "delete_option(" in c]
check("delete_option only in uninstall.php", deletes == ["uninstall.php"], str(deletes))

# --- ۳. هیچ منطق تجاری یا درخواست شبکه
for w in ["WC()->cart", "add_to_cart", "wc_create_order", "WC_Order", "wp_mail", "wp_remote_", "wp_safe_remote_", "curl_", "fsockopen", "file_get_contents( 'http", "register_rest_route", "wp_ajax_", "admin_post_"]:
    hits = [f for f, c in code.items() if w in c]
    check(f"no business logic / network: {w}", not hits, ", ".join(hits))

# --- ۴. هیچ loop ِ ووکامرس
for w in ["wc_get_template", "woocommerce_template_loop", "do_action( 'woocommerce", 'do_action( "woocommerce', "woocommerce_shop_loop", "wc_setup_loop"]:
    hits = [f for f, c in code.items() if w in c]
    check(f"no WooCommerce loop: {w}", not hits, ", ".join(hits))

# --- ۴-ب. ۰٫۳٫۲: جست‌وجوی Hero فقط کادر و دکمه؛ قیمت فقط با API ِ ووکامرس، بی واحد پولِ ثابت
hero = raw["templates/hero.php"]
check("hero search: no category select (0.3.2)", "<select" not in hero and "product_cat" not in hero)
money = [f for f, c in code.items() if re.search(r"تومان|ریال|['\"](IRT|IRR)['\"]", c)]
check("no hard-coded currency in code (WooCommerce settings decide)", not money, ", ".join(money))
check("product cards never print raw get_price_html() (screen-reader text)", "get_price_html" not in code["templates/products.php"] and "Price::of(" in code["includes/class-catalog.php"])

# --- ۴-ج. ۰٫۳٫۳
# readme.txt (فهرست تغییرات) همین برداشتن را شرح می‌دهد، پس از این سنجش بیرون است.
check("hero: the «بازار B2B تجهیزات پزشکی» label is not generated anywhere", not any("بازار B2B" in c for f, c in raw.items() if f != "readme.txt") and "th-pill" not in raw["templates/hero.php"])
labels = re.search(r"HERO_LABELS = array\((.*?)\n\t\);", code["includes/class-settings.php"], re.S)
check("hero labels: names and icons only — no hard-coded term ID or slug", bool(labels) and not re.search(r"\b\d{2,}\b|-", labels.group(1)), labels.group(1)[:120] if labels else "missing")
price_hooks = [f for f, c in code.items() if re.search(r"add_filter\(\s*'(wc_price_args|woocommerce_price_format|formatted_woocommerce_price|woocommerce_get_price_html|raw_woocommerce_price|wc_get_price_thousand_separator|woocommerce_price_thousand_separator)'", c)]
check("price: no global WooCommerce price filter (format applied per call on the homepage only)", not price_hooks, ", ".join(price_hooks))
check("price: thousands separator / format passed as wc_price() arguments", "'thousand_separator' => $thousand" in code["includes/class-price.php"] and "wc_price( $model[ $key ], $args )" in code["includes/class-price.php"])
libs = [f for f, c in raw.items() if re.search(r"(?i)swiper|slick|owl\.carousel|glide\.js|splide|cdn\.|https?://(?!www\.w3\.org)", c) and f.endswith((".js", ".css"))]
check("brands carousel: no third-party library or remote asset", not libs, ", ".join(libs))
check("brands carousel: no autoplay (no setInterval / setTimeout in the front script)", not re.search(r"setInterval|setTimeout", raw["assets/js/tecteb-home.js"]))
check("admin: media library loaded only on this plugin's own page, behind the capability", "if ( '' === self::$hook || $hook !== self::$hook || ! current_user_can( self::capability() ) )" in code["includes/class-admin.php"])
check("brands: saved through the plugin option only (settings_fields nonce + option_page_capability)", "settings_fields( Settings::GROUP )" in code["includes/class-admin.php"] and "option_page_capability_" in code["includes/class-admin.php"] and "brands" in code["includes/class-settings.php"])

# ۰٫۳٫۴
settings_php = code["includes/class-settings.php"]
check("hero labels 0.3.4: title, category, order and active stored in the plugin option (hero_items), four fixed rows", "'hero_items'" in settings_php and "function hero_items(" in settings_php and "function default_hero_items(" in settings_php)
check("hero labels 0.3.4: a 0.3.3 option (no hero_items) is read, never rewritten on read (no update_option in Settings)", "hero_items_from_pins" in settings_php and "update_option" not in settings_php)
check("hero labels 0.3.4: hero_labels (0.3.3) is derived from the rows so a downgrade sees the same categories", "$out['hero_labels'] = array_map( static fn( array $row ): int => $row['term'], $out['hero_items'] );" in settings_php)
check("hero labels 0.3.4: no WooCommerce term is ever written", not re.search(r"wp_(insert|update|delete)_term|wp_set_object_terms", "".join(c for f, c in code.items())))
check("hero labels 0.3.4: the label text is a span that can be shortened with an ellipsis", 'class="th-float__text"' in raw["templates/hero.php"] and "text-overflow: ellipsis" in raw["assets/css/tecteb-home.css"])
check("brands 0.3.4: logo size from the image's own ratio (no fixed width/height that could stretch it)", "function logo_width(" in code["includes/class-catalog.php"] and "object-fit: contain" in raw["assets/css/tecteb-home.css"])
check("0.3.4: no change to the header plugin, the theme or the footer from this plugin (no tth- selectors, no header/footer hooks)", not re.search(r"\.tth-|#tth-root|get_header|get_footer\(\s*'", raw["assets/css/tecteb-home.css"]) )

# --- ۵. هر echo در قالب‌ها escape شده یا دلیلِ نوشته‌شده دارد
for f, text in raw.items():
    if not f.startswith("templates/") or not f.endswith(".php"):
        continue
    for i, line in enumerate(text.splitlines(), 1):
        if "echo " not in line:
            continue
        for stmt in re.findall(r"echo\s+(.+?);", line):
            ok = re.match(r"(esc_html|esc_attr|esc_url|esc_textarea|wp_kses_post|esc_html__|esc_attr__)\s*\(", stmt) or "phpcs:ignore WordPress.Security.EscapeOutput" in line
            # شرطی که فقط بین دو رشتهٔ ثابتِ کلاس انتخاب می‌کند.
            ok = ok or re.search(r"\?\s*'[\w -]*'\s*:\s*'[\w -]*'$", stmt)
            if not ok:
                check(f"escaped echo {f}:{i}", False, stmt)
check("templates: no inline <script>", not [f for f, t in raw.items() if f.startswith("templates/") and "<script" in t.lower()])

# --- ۶. CSS ایزوله
css = (ROOT / "assets/css/tecteb-home.css").read_text(encoding="utf-8")
font_css = (ROOT / "assets/css/tecteb-home-font.css").read_text(encoding="utf-8")
body = re.sub(r"/\*.*?\*/", "", css, flags=re.S)


def selectors(text: str):
    depth = 0
    buf = ""
    stack: list[str] = []
    for ch in text:
        if ch == "{":
            prelude = buf.strip()
            buf = ""
            stack.append(prelude)
            if not prelude.startswith("@") and not any(s.startswith("@keyframes") for s in stack[:-1]):
                yield prelude
        elif ch == "}":
            if stack:
                stack.pop()
            buf = ""
        else:
            buf += ch


def split_top(prelude: str) -> list[str]:
    parts, depth, cur = [], 0, ""
    for ch in prelude:
        depth += ch in "(["
        depth -= ch in ")]"
        if ch == "," and depth == 0:
            parts.append(cur.strip()); cur = ""
        else:
            cur += ch
    return [p for p in parts + [cur.strip()] if p]


bad = []
count = 0
for prelude in selectors(body):
    for sel in split_top(prelude):
        if re.fullmatch(r"(from|to|\d+%)(\s*,\s*(from|to|\d+%))*", sel):
            continue
        count += 1
        if not sel.startswith(".tecteb-home-v1"):
            bad.append(sel)
check(f"css: every selector scoped under .tecteb-home-v1 ({count} selectors)", not bad and count > 200, "; ".join(bad[:5]))
check("css: no @font-face in the main stylesheet (font is its own file)", "@font-face" not in body)
check("css: font file has exactly one @font-face with a unique family", font_css.count("@font-face") == 1 and '"TH Vazirmatn"' in font_css and "../fonts/Vazirmatn-wght.woff2" in font_css)
check("css: font file exists", (ROOT / "assets/fonts/Vazirmatn-wght.woff2").is_file() and (ROOT / "assets/fonts/OFL.txt").is_file())
ext = re.findall(r"url\(\s*['\"]?(https?:|//)", css + font_css)
check("css: no external url()", not ext, str(ext))
imp = re.findall(r"[^;{}]*!important", body)
check("css: !important only where the approved design has it (reduced motion, audience separator)", len(imp) == 3 and all("transition: none" in i or "animation: none" in i or "display: none" in i for i in imp), str(imp))
hardening = re.sub(r"/\*.*?\*/", "", (ROOT.parent / "src/hardening.css").read_text(encoding="utf-8"), flags=re.S)
check("css: hardening adds no !important", "!important" not in hardening)
BOOST = ".tecteb-home-v1:is(.tecteb-home-v1, #th-boost)"
# 0.3.6: CSS ِ ساخته‌شده بی توضیح است، پس فاصله‌های خالیِ جای توضیح‌ها کوتاه شده‌اند — مقایسه بی حساسیت به فاصلهٔ سفید.
_ws = lambda t: re.sub(r"\s+", " ", t).strip()
check("css: shipped stylesheet ends with the hardening rules (boosted)", _ws(hardening.replace(".tecteb-home-v1", BOOST))[-200:] in _ws(body))
# ۰٫۳٫۰ — ایزولگی در برابر CSS ِ سراسری سایت (tools/port-assets.py: boost + لایهٔ all: revert)
check("css: isolation layer (all: revert) precedes the design rules", 0 < body.find("all: revert") < body.find("--th-"))
check("css: every root selector carries the ID-weight boost", body.count(".tecteb-home-v1") == 2 * body.count(BOOST) and BOOST in body)
check("css: no element on the page needs id=th-boost", "th-boost" not in "".join(v for k, v in raw.items() if k.endswith(".php")))
check("assets: shared header font only via TECTEB_HEADER_* constants, read-only", "TECTEB_HEADER_DIR" in raw["includes/class-assets.php"] and not re.search(r"tecteb_header_settings|Tecteb\\Header", raw["includes/class-assets.php"]))
check("css: no physical left/right properties", not re.search(r"(?<![-\w])(margin|padding|border)-(left|right)\b|(?<![-\w])(left|right)\s*:", body))
check("css: every animation is under prefers-reduced-motion: no-preference",
      all("no-preference" in body[max(0, m.start() - 4000):m.start()] for m in re.finditer(r"animation(-name)?\s*:\s*(?!none)", body)))
js = (ROOT / "assets/js/tecteb-home.js").read_text(encoding="utf-8")
check("js: no network, no storage, scoped to .tecteb-home-v1", not re.search(r"fetch\(|XMLHttpRequest|localStorage|sessionStorage|document\.cookie|https?://", js) and ".tecteb-home-v1" in js)
check("js: honours reduced motion and fine pointer only", "prefers-reduced-motion: reduce" in js and "pointer: fine" in js)

# --- ۶-ب. تصمیم‌های ۰٫۲٫۰
check("products: no featured-flag selection left in code", not [f for f, c in code.items() if "'featured'" in c or "featured_" in c])
# کامنت‌ها کنار می‌روند: docblock ها همین نام‌ها را برای توضیحِ برداشتنشان می‌آورند.
shipped = {f: (code[f] if f in code else re.sub(r"/\*.*?\*/", "", t, flags=re.S)) for f, t in raw.items() if f != "readme.txt"}
for needle in ["درخواست تأمین", "استعلام", "پیش‌فاکتور", "th-product__quote", "th-services", "th-service", "th-route--buyer", "TECTEB_HOME_QUOTE_URL"]:
    hits = [f for f, t in shipped.items() if needle in t]
    check(f"retired in 0.2.0: «{needle}» not in shipped files", not hits, ", ".join(hits))
check("services template removed", not (ROOT / "templates/services.php").exists())
hero = raw["templates/hero.php"]
check("hero: three routes shop → repair → supplier", hero.find("'shop'") < hero.find("'repair'") < hero.find("'supplier'") and "درخواست تعمیرات تجهیزات پزشکی" in hero)
check("routes: each printed only with a destination (plan['routes'][...]['show'])", "$plan['routes'][ $key ]['show']" in hero)
check("sprite: wrench icon for the repair route", 'id="th-i-wrench"' in raw["templates/sprite.svg"])
check("shop: from wc_get_page_permalink('shop'), guarded", "wc_get_page_permalink( 'shop' )" in code["includes/class-renderer.php"] and "'publish' !== get_post_status( $id )" in code["includes/class-renderer.php"])
check("admin: product search is GET on the settings page, not AJAX/REST", "tth_pq" in code["includes/class-admin.php"] and not [f for f, c in code.items() if "wp_ajax" in c or "admin_post_" in c or "register_rest_route" in c])

# --- ۷. هیچ نشانهٔ پیش‌نمایش
for needle in ["th-sample", "th-imgnote", "th-hero__note", "proto-", "data-th-route", "#th-route", "تصویر نمایشی", "نمونه", "sample-"]:
    hits = [f for f, t in raw.items() if needle in t and f != "readme.txt"]
    check(f"no preview marker «{needle}» in shipped files", not hits, ", ".join(hits))

# --- ۸. هر فایلی که کد به آن ارجاع می‌دهد هست
for ref in re.findall(r"'(assets/[^']+|templates/[^']+|css/[^']+|js/[^']+|fonts/[^']+|img/[^']+)'", "".join(raw.values())):
    for base in ("", "assets/"):
        if (ROOT / (base + ref)).exists():
            break
    else:
        if "%" not in ref and not ref.endswith("/"):
            check(f"referenced file exists: {ref}", False)
for name in ["tecteb-home-v1", "Requires Plugins:  woocommerce", "Requires PHP:      8.1", "Text Domain:       tecteb-homepage"]:
    check(f"plugin header/markup has «{name}»", name in raw["tecteb-homepage.php"] or name in "".join(raw.values()))

# --- ۰٫۳٫۶: سرعت
check("0.3.6: supplier eyebrow logo is the lossless WebP, lazy and async (below the fold)", 'tecteb-logo-mark-96.webp\' ); ?>" width="20" height="20" alt="" loading="lazy" decoding="async">' in raw["templates/supplier.php"] and (ROOT / "assets/img/tecteb-logo-mark-96.webp").is_file())
check("0.3.6: shipped tecteb-home.css carries no comments except the provenance header", raw["assets/css/tecteb-home.css"].count("/*") == 1)
_pt = code["includes/class-page-template.php"]
check("0.3.7: the current post is hidden only around Elementor's post-asset block (last on after_enqueue_styles, restored first on after_enqueue_post_styles, safety restore on wp_enqueue_scripts)",
      "add_action( 'elementor/frontend/after_enqueue_styles', array( self::class, 'hide_front_document' ), PHP_INT_MAX );" in _pt
      and "add_action( 'elementor/frontend/after_enqueue_post_styles', array( self::class, 'restore_front_document' ), PHP_INT_MIN );" in _pt
      and "add_action( 'wp_enqueue_scripts', array( self::class, 'restore_front_document' ), PHP_INT_MAX );" in _pt)
check("0.3.7: only when this plugin takes over the front page (not in the editor, preview, ?tecteb_home_original=1 or with the option off)",
      "if ( false !== self::$stashed_post || ! self::takes_over_front() ) {" in _pt)
check("0.3.7: restore puts back exactly the stashed post and clears the stash", re.search(r"function restore_front_document\(\): void \{\s*if \( false === self::\$stashed_post \) \{\s*return;\s*\}\s*\$GLOBALS\['post'\]\s*= self::\$stashed_post;\s*self::\$stashed_post = false;", _pt) is not None)
check("0.3.7: nothing is dequeued or deregistered by handle, no Elementor data written", not re.search(r"wp_(dequeue|deregister)_(style|script)|update_post_meta|delete_post_meta", _pt))
check("0.3.6: no lazy-loading on the Hero image (above the fold, fetchpriority=high kept)", 'fetchpriority="high"' in raw["templates/hero.php"] or "fetchpriority" in code.get("includes/class-renderer.php", ""))

fails = [r for r in results if not r[0]]
for ok, name, detail in results:
    print(("PASS " if ok else "FAIL ") + name + ("" if ok or not detail else "  — " + detail))
print(f"\n{len(results) - len(fails)} passed, {len(fails)} failed")
sys.exit(1 if fails else 0)
