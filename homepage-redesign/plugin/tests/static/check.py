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
check("css: shipped stylesheet ends with the hardening rules", hardening.strip()[-200:] in body)
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

fails = [r for r in results if not r[0]]
for ok, name, detail in results:
    print(("PASS " if ok else "FAIL ") + name + ("" if ok or not detail else "  — " + detail))
print(f"\n{len(results) - len(fails)} passed, {len(fails)} failed")
sys.exit(1 if fails else 0)
