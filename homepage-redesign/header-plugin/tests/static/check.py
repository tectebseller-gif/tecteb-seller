#!/usr/bin/env python3
"""
آزمون معماری tecteb-header از بایت‌های فایل‌ها — بی وردپرس.

  python3 homepage-redesign/header-plugin/tests/static/check.py

مرزهای دستور مالک که از سورس قابل‌سنجش‌اند:
  * دارایی‌های هدر همان نمونهٔ تأییدشدهٔ B اند (بایت‌به‌بایت).
  * هیچ وابستگی به دکان، Tecteb Marketplace یا افزونهٔ صفحهٔ اصلی.
  * هیچ نوشتنی روی ووکامرس/وردپرس: نه ترم، نه متا، نه پست، نه فهرست، نه $wpdb؛ تنها
    نوشتن، option های خودِ افزونه است. هیچ دستی به قالب Elementor (۲۶۵۲) یا شرط نمایش.
  * بارگذاری SVG فعال نمی‌شود (upload_mimes دست نمی‌خورد).
  * CSS ِ ایزوله: هر انتخابگر جلویی زیر .tecteb-header-v1، پیشخوان زیر .tth-hdr.
  * هیچ دادهٔ نمونه/ساختگی در فایل‌های ارسالی؛ هیچ شناسهٔ نمونه.
  * پیش‌فرض‌ها: بی دسته، hide_empty خاموش، جایگزینی خاموش، علاقه‌مندی خاموش.
  * هیچ پردازش مستقیم $_POST؛ ذخیره فقط از Settings API.
"""
from __future__ import annotations

import pathlib
import re
import subprocess
import sys

HERE = pathlib.Path(__file__).resolve().parents[2]
ROOT = HERE / "tecteb-header"
PROTO = HERE.parent / "header"
results: list[tuple[bool, str, str]] = []


def check(name: str, ok: bool, detail: str = "") -> None:
    results.append((ok, name, detail))


def php_code(path: pathlib.Path) -> str:
    script = (
        "$t=token_get_all(file_get_contents($argv[1]));$o='';"
        "foreach($t as $x){if(is_array($x)){if(in_array($x[0],[T_COMMENT,T_DOC_COMMENT],true))"
        "{$o.=' ';continue;}$o.=$x[1];}else{$o.=$x;}}echo $o;"
    )
    return subprocess.run(["php", "-r", script, str(path)], capture_output=True, text=True, check=True).stdout


php_files = sorted(ROOT.rglob("*.php"))
code = {p.relative_to(ROOT).as_posix(): php_code(p) for p in php_files}
allcode = "\n".join(code.values())

# --- ۱. دارایی‌های تأییدشده
for ship, proto in [
    ("assets/css/header-base.css", "shared/header-base.css"),
    ("assets/css/header-b.css", "b/header-b.css"),
    ("assets/js/header.js", "shared/header.js"),
]:
    check(f"approved asset byte-identical: {ship}", (ROOT / ship).read_bytes() == (PROTO / proto).read_bytes())
tpl = (PROTO / "shared/header.html").read_text(encoding="utf-8")
sprite = (ROOT / "templates/sprite.svg").read_text(encoding="utf-8")
sym = lambda s: set(re.findall(r'<symbol id="(tth-i-[a-z]+)"[^>]*>.*?</symbol>', s))
proto_syms = {m.group(0) for m in re.finditer(r'<symbol id="tth-i-(?!c-)[a-z]+"[^>]*>.*?</symbol>', tpl)}
ship_syms = {m.group(0) for m in re.finditer(r'<symbol id="tth-i-[a-z]+"[^>]*>.*?</symbol>', sprite)}
check("sprite = approved UI icons (sample category icons removed)", proto_syms == ship_syms and len(ship_syms) == 11)
# همهٔ کلاس‌های ساختاری نمونه در قالب هست
header_php = (ROOT / "templates/header.php").read_text(encoding="utf-8")
proto_classes = set(re.findall(r'class="([^"{}]+)"', tpl))
proto_classes = {c for cl in proto_classes for c in cl.split() if c.startswith("tth-") and c not in {"proto-note"}}
missing = sorted(c for c in proto_classes if c not in header_php + sprite + (ROOT / "includes/class-renderer.php").read_text(encoding="utf-8"))
check("template keeps every tth- class of the approved markup", not missing, ", ".join(missing))

# --- ۲. وابستگی‌ها
for needle, label in [
    (r"(?i)dokan", "Dokan"),
    (r"\btmc_", "Tecteb Marketplace prefix"),
    (r"Tecteb\\\\?Marketplace", "Tecteb Marketplace namespace"),
    (r"tecteb-marketplace-core", "Tecteb Marketplace slug"),
    (r"tecteb_home|Tecteb\\\\?Homepage|tecteb-homepage", "homepage plugin"),
    (r"\\\\?Elementor\\\\", "Elementor PHP classes"),
]:
    hits = [f for f, c in code.items() if re.search(needle, c)]
    check(f"no dependency on {label}", not hits, ", ".join(hits))

# --- ۳. هیچ نوشتنی جز option های خودمان
writes = [
    r"\bwp_(insert|update|delete)_term\b", r"\b(add|update|delete)_term_meta\b", r"\bwp_set_object_terms\b",
    r"\bwp_(insert|update|delete|trash)_post\b", r"\b(add|update|delete)_post_meta\b", r"\bwp_delete_attachment\b",
    r"\bwp_update_nav_menu_item\b", r"\bwp_(create|delete|update)_nav_menu\b", r"\$wpdb\b", r"->save\(", r"\bset_category_ids\b",
    r"\bupdate_user_meta\b", r"\bset_theme_mod\b", r"\bwp_insert_attachment\b", r"\bset_post_thumbnail\b",
]
for w in writes:
    hits = [f for f, c in code.items() if re.search(w, c)]
    check(f"no write call: {w}", not hits, ", ".join(hits))
opts = {o.strip() for o in re.findall(r"\b(?:update|add|delete)_option\(\s*([^,)]+)", allcode)}
check("options written: only our own", opts <= {"self::GEN", "'tecteb_header_settings'", "'tecteb_header_gen'"}, str(opts))
check("no upload_mimes / SVG enabling", not re.search(r"upload_mimes|wp_check_filetype_and_ext|image/svg", allcode))
check("ICON_MIMES = PNG + WebP only", re.search(r"ICON_MIMES\s*=\s*array\(\s*'image/png',\s*'image/webp'\s*\)", code["includes/class-settings.php"]) is not None)
check("no 2652 / Elementor template or condition access", not re.search(r"\b2652\b|_elementor_conditions|_elementor_data|elementor_pro_theme_builder_conditions|\bupdate_post\(", allcode))
check("no direct $_POST handling (Settings API only)", "$_POST" not in allcode and "$_REQUEST" not in allcode)
check("every $_GET read is sanitised or only isset()",
      all(re.search(r"(sanitize_\w+|absint)\(\s*(wp_unslash\(\s*)?\$_GET", line) or "isset( $_GET" in line or "unset(" in line
          for line in allcode.splitlines() if "$_GET" in line))
check("no outbound HTTP", not re.search(r"wp_remote_|curl_|file_get_contents\(\s*['\"]https?", allcode))

# --- ۴. CSS
def selectors(css: str) -> list[str]:
    """انتخابگرهای هر قاعدهٔ سبک، با پیمودن @media/@supports؛ @font-face و @keyframes قاعدهٔ انتخابگر ندارند."""
    css = re.sub(r"/\*.*?\*/", "", css, flags=re.S)
    out: list[str] = []

    def walk(text: str) -> None:
        i = 0
        while i < len(text):
            j = text.find("{", i)
            if j < 0:
                return
            prelude = text[i:j].strip().split(";")[-1].strip()
            depth, k = 1, j + 1
            while depth and k < len(text):
                depth += {"{": 1, "}": -1}.get(text[k], 0)
                k += 1
            body = text[j + 1:k - 1]
            if prelude.startswith(("@media", "@supports", "@container", "@layer")):
                walk(body)
            elif not prelude.startswith("@"):
                parts, buf, par = [], "", 0
                for ch in prelude:
                    par += {"(": 1, ")": -1}.get(ch, 0)
                    if ch == "," and par == 0:
                        parts.append(buf); buf = ""
                    else:
                        buf += ch
                parts.append(buf)
                out.extend(p.strip() for p in parts if p.strip())
            i = k
    walk(css)
    return out


for f in ["header-base.css", "header-b.css", "tecteb-header.css", "tecteb-header-font.css"]:
    css = (ROOT / "assets/css" / f).read_text(encoding="utf-8")
    bad = [s for s in selectors(css) if not s.startswith(".tecteb-header-v1")]
    check(f"css scoped under .tecteb-header-v1: {f}", not bad, " | ".join(bad[:5]))
    check(f"css has no external URL: {f}", not re.search(r"url\(\s*['\"]?(https?:)?//", css))
admin_css = (ROOT / "assets/css/admin.css").read_text(encoding="utf-8")
bad = [s for s in selectors(admin_css) if not s.startswith(".tth-hdr")]
check("admin css scoped under .tth-hdr", not bad, " | ".join(bad[:5]))
check("no global selectors (body/header/footer/.elementor/.woocommerce)",
      not any(re.match(r"^(html|body|header|footer|\.elementor|\.woocommerce|\.dokan)\b", s) for f in (ROOT / "assets/css").glob("*.css") for s in selectors(f.read_text(encoding="utf-8"))))

# --- ۵. نمونه/ساختگی و پیش‌فرض‌ها
shipped = "\n".join(p.read_text(encoding="utf-8", errors="ignore") for p in ROOT.rglob("*") if p.suffix in {".php", ".css", ".js", ".svg", ".txt"})
check("no prototype markers shipped", not re.search(r"data-src=|proto-note|data-proto|کاربر نمونه|#wishlist|categories\.sample", shipped))
check("no sample term IDs (101–115, 201+)", not re.search(r"'term_id'\s*=>\s*(1[01]\d|2\d\d)\b", allcode))
s = code["includes/class-settings.php"]
d = s[s.index("function defaults"):s.index("public static function get")]
for key, want in [("categories", "array()"), ("hide_empty", "false"), ("replace", "false"), ("wishlist_mode", "'off'"), ("assets_everywhere", "false"), ("search_mode", "'woocommerce'")]:
    check(f"default {key} = {want}", re.search(rf"'{key}'\s*=>\s*{re.escape(want)}", d) is not None)
check("baseline names: exactly the 13 site categories", len(re.findall(r"^\t\t'[^']+',$", s[s.index("BASELINE"):s.index(");", s.index("BASELINE"))], re.M)) == 13)

# --- ۶. امنیت قالب
tpl_code = code["templates/header.php"]
ALLOWED = r"(esc_|wp_kses|\$tth_(icon|nav_link)\(|file_get_contents|do_shortcode|\\Tecteb\\Header\\Renderer::cart_link|0 ===|\$vm\['banner'\]|\$tth_acct\['logged_in'\])"
unescaped = [m.group(0) for m in re.finditer(r"\becho\s+[^;]{0,60}", tpl_code) if not re.match(r"echo\s+" + ALLOWED, m.group(0))]
check("template: every echo escaped or a documented helper", not unescaped, " | ".join(unescaped[:3]))
check("uninstall deletes only own options/transient",
      set(re.findall(r"delete_(?:option|transient)\(\s*'([a-z_]+)", code["uninstall.php"])) <= {"tecteb_header_settings", "tecteb_header_gen", "tecteb_header_cats_"})
check("capability gate on admin page", "current_user_can( Settings::capability() )" in code["includes/class-admin.php"])
check("option_page_capability filter registered", "option_page_capability_tecteb_header" in code["includes/class-settings.php"])
check("preview/original params gated by capability", re.search(r"\$admin\s*&&\s*isset\(\s*\$_GET\['tth_original'\]", code["includes/class-replace.php"]) and re.search(r"\$admin\s*&&\s*isset\(\s*\$_GET\['tth_preview'\]", code["includes/class-replace.php"]))

fails = [r for r in results if not r[0]]
for ok, name, detail in results:
    print(("ok    " if ok else "FAIL  ") + name + (f"  — {detail}" if detail and not ok else ""))
print(f"\n{len(results) - len(fails)} passed, {len(fails)} failed")
sys.exit(1 if fails else 0)
