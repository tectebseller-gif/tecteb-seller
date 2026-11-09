#!/usr/bin/env bash
# Header 0.2.6 و Homepage 0.3.7 روی Elementor ِ واقعی (4.2.3) و PRO Elements (4.2.2): دارایی‌های قالب‌هایی که چاپ
# نمی‌شوند بارگذاری نمی‌شوند، و هر جا که چاپ می‌شوند دارایی‌هایشان هست.
#
#   bash perf/tests/elementor-assets.sh
#
# پیش‌نیاز: بستر tth_perf روی 127.0.0.1:8092 (perf/tools/real-elementor-testbed.php seed — هدر ۲۶۵۲ «کل سایت» با
# منوی ناوبری، جست‌وجو، جعبهٔ آیکون، شبکه‌های اجتماعی، …؛ فوتر «کل سایت» با عنوان، فهرست آیکون و جداکننده؛ برگهٔ نخست
# ساخته‌شده با Elementor) و mu-plugin ِ آزمونی tthp-fallback-probes.php. فقط می‌خواند، جز دو تنظیم آزمونی که
# در پایان برمی‌گردند. خروجی: perf/evidence/elementor-real/assets-tests.txt
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SITE="${SITE:-http://127.0.0.1:8092}"
W="wp --allow-root --path=/home/user/wp2/core"
OUT="${OUT:-$HERE/perf/evidence/elementor-real/assets-tests.txt}"
TMP="$(mktemp -d)"
PASS=0; FAIL=0; LOG=()
check() { if [[ "$2" == "0" ]]; then PASS=$((PASS+1)); LOG+=("PASS $1"); else FAIL=$((FAIL+1)); LOG+=("FAIL $1${3:+  — $3}"); fi; }
[[ "$($W eval 'echo DB_NAME;' 2>/dev/null)" == "tth_perf" ]] || { echo "refused: not the tth_perf testbed" >&2; exit 1; }
# تنظیم‌هایی که این آزمون موقتاً عوض می‌کند، هر طور که تمام شود (حتی زیر یک جهش falsify) دقیقاً برمی‌گردند.
SAVED_HDR="$($W option get tecteb_header_settings --format=json 2>/dev/null)"; SAVED_HOME="$($W option get tecteb_home_settings --format=json 2>/dev/null)"
FRONT_EDIT_MODE=""
restore_settings() {
  [[ -n "$SAVED_HDR" ]] && $W option update tecteb_header_settings "$SAVED_HDR" --format=json >/dev/null 2>&1
  [[ -n "$SAVED_HOME" ]] && $W option update tecteb_home_settings "$SAVED_HOME" --format=json >/dev/null 2>&1
  [[ -n "$FRONT_EDIT_MODE" ]] && $W post meta update "$FRONT_EDIT_MODE" _elementor_edit_mode builder >/dev/null 2>&1
  [[ -n "${SECOND:-}" ]] && $W --user=tthadmin eval-file "$HERE/perf/tools/real-elementor-testbed.php" drop-second >/dev/null 2>&1
  rm -rf "$TMP"
}
trap restore_settings EXIT
JAR="$TMP/jar"; curl -s -c "$JAR" -b "$JAR" -o /dev/null "$SITE/wp-login.php"; curl -s -c "$JAR" -b "$JAR" -o /dev/null --data-urlencode "log=tthadmin" --data-urlencode "pwd=local-only-2026" --data-urlencode "testcookie=1" "$SITE/wp-login.php"
# صفحه (مهمان یا مدیر) ← JSON: قالب‌های رندرشده، و handle های css/js در head و body.
page() { local path="$1" who="${2:-guest}" f="$TMP/p.html"; if [[ "$who" == "admin" ]]; then curl -sL -b "$JAR" "$SITE$path" > "$f"; else curl -sL "$SITE$path" > "$f"; fi
  python3 - "$f" <<'EOF'
import re, sys, json
h = open(sys.argv[1]).read(); head, _, body = h.partition("</head>")
def ids(part, t):
    pat = r"<link[^>]*rel=['\"]stylesheet['\"][^>]*>" if t == "css" else r"<script[^>]*src=[^>]*>"
    return [re.sub(r"-(css|js)$", "", re.search(r"id=['\"]([^'\"]+)", m.group(0)).group(1)) for m in re.finditer(pat, part) if re.search(r"id=['\"]", m.group(0))]
print(json.dumps({"rendered": sorted(set(re.findall(r'data-elementor-id="(\d+)"', body))), "tth": 'id="tth-root"' in body,
  "head_css": ids(head, "css"), "body_css": ids(body, "css"), "js": ids(head, "js") + ids(body, "js"),
  "inline_2652": 'id="elementor-post-2652' in h, "comment": re.findall(r"<!-- tecteb-header: ([^>]*) -->", h)}))
EOF
}
has() { python3 -c 'import json,sys; d=json.loads(sys.argv[1]); k=sys.argv[2]; want=sys.argv[3:]; sys.exit(0 if all(w in d[k] for w in want) else 1)' "$@"; }
lacks() { python3 -c 'import json,sys; d=json.loads(sys.argv[1]); k=sys.argv[2]; want=sys.argv[3:]; sys.exit(0 if not any(w in d[k] for w in want) else 1)' "$@"; }
val() { python3 -c 'import json,sys; print(json.loads(sys.argv[1])[sys.argv[2]])' "$1" "$2"; }

HEADER_ONLY=(widget-image widget-nav-menu widget-search e-animation-buzz-out widget-icon-box widget-social-icons e-apple-webkit elementor-post-2652)
FOOTER=(widget-heading widget-icon-list widget-divider elementor-post-2770 elementor-frontend elementor-post-11)
FRONT_ONLY=(swiper e-swiper widget-image-carousel widget-testimonial widget-counter e-animation-grow elementor-post-2753)
FOOTER_ID="$($W post list --post_type=elementor_library --meta_key=_elementor_template_type --meta_value=footer --field=ID 2>/dev/null | head -1)"
FOOTER=(widget-heading widget-icon-list widget-divider "elementor-post-$FOOTER_ID" elementor-frontend elementor-post-11)
FRONT="$($W option get page_on_front 2>/dev/null)"
FRONT_ONLY=(swiper e-swiper widget-image-carousel widget-testimonial widget-counter e-animation-grow "elementor-post-$FRONT")

# ۱. صفحهٔ نخست، مهمان، هر دو جایگزینی روشن.
d="$(page /)"; LOG+=("front page (guest): $d")
[[ "$(val "$d" tth)" == "True" ]] && lacks "$d" rendered 2652 "$FRONT" && has "$d" rendered "$FOOTER_ID"; check "front page: Header B printed, Elementor header 2652 and the old Elementor front page NOT printed, Elementor footer printed" $?
lacks "$d" head_css "${HEADER_ONLY[@]}" && lacks "$d" body_css "${HEADER_ONLY[@]}"; check "front page: none of the replaced header's assets load (post-2652.css, nav-menu, search, icon-box, social-icons, buzz-out, apple-webkit, image)" $?
lacks "$d" head_css "${FRONT_ONLY[@]}" && lacks "$d" body_css "${FRONT_ONLY[@]}"; check "front page: none of the old Elementor front page's assets load (post-$FRONT.css, swiper, image-carousel, testimonial, counter, grow)" $?
has "$d" head_css "${FOOTER[@]}"; check "front page: everything the Elementor footer needs is still in <head> (heading, icon-list, divider, post-$FOOTER_ID.css, frontend, kit)" $?
lacks "$d" js smartmenus swiper jquery-numerator; check "front page: smartmenus (header nav menu), swiper and numerator (old front page) not loaded" $?
has "$d" head_css tecteb-header-base tecteb-home; check "front page: header.css and tecteb-home.css still load" $?

# ۲. برگهٔ دیگر و فروشگاه: هدر جایگزین‌شده، صفحهٔ نخست اثری ندارد.
for p in /shop/ /cart/ "/?page_id=$($W eval 'echo get_page_by_path("plain-page") ? get_page_by_path("plain-page")->ID : 0;' 2>/dev/null)"; do
  d="$(page "$p")"
  [[ "$(val "$d" tth)" == "True" ]] && lacks "$d" rendered 2652 && lacks "$d" head_css "${HEADER_ONLY[@]}" && has "$d" head_css "${FOOTER[@]}"; check "$p: Header B, no header-2652 assets, footer assets present" $? "$d"
done

# ۳. بازگشت‌ها: هر جا ۲۶۵۲ چاپ می‌شود، دارایی‌هایش هست.
d="$(page '/?tth_original=1' admin)"
has "$d" rendered 2652 && has "$d" head_css "${HEADER_ONLY[@]}"; check "admin ?tth_original=1: Elementor header 2652 printed WITH its CSS and assets in <head> (nothing skipped)" $? "$d"
d="$(page '/?tthp_readd=1')"
has "$d" rendered 2652 && [[ "$(val "$d" tth)" == "False" ]] && python3 -c 'import json,sys; d=json.loads(sys.argv[1]); w=["widget-nav-menu","widget-search","widget-icon-box","widget-social-icons","elementor-post-2652"]; sys.exit(0 if all(x in d["head_css"]+d["body_css"] for x in w) and "smartmenus" in d["js"] else 1)' "$d"
check "fallback: another plugin re-adds 2652 after this one (elementor_printed) ⇒ Elementor header printed, and its CSS, widget assets and smartmenus are loaded again (late, in the body)" $? "$d"
d="$(page '/?tthp_flip=1')"
has "$d" rendered 2652 && [[ "$(val "$d" tth)" == "False" ]] && python3 -c 'import json,sys; d=json.loads(sys.argv[1]); w=["widget-nav-menu","widget-search","widget-icon-box","elementor-post-2652"]; sys.exit(0 if all(x in d["head_css"]+d["body_css"] for x in w) else 1)' "$d"
check "fallback: replacement switched off between <head> and the header (start: off) ⇒ Elementor header printed with its assets restored" $? "$d"
d="$(page '/?tecteb_home_original=1' admin)"
has "$d" rendered "$FRONT" && has "$d" head_css "${FRONT_ONLY[@]}"; check "admin ?tecteb_home_original=1: old Elementor front page printed WITH its CSS and assets in <head>" $? "$d"

# ۴. کلیدها خاموش ⇒ دقیقاً رفتار Elementor.
$W eval '$s = get_option("tecteb_header_settings"); $s["replace"] = false; update_option("tecteb_header_settings", $s);' >/dev/null 2>&1
d="$(page /)"
has "$d" rendered 2652 && has "$d" head_css "${HEADER_ONLY[@]}"; check "header replacement off: Elementor header 2652 and all its assets in <head>, as without this plugin" $? "$d"
$W eval '$s = get_option("tecteb_header_settings"); $s["replace"] = true; update_option("tecteb_header_settings", $s);' >/dev/null 2>&1
$W eval '$s = get_option("tecteb_home_settings"); $s["front_page"] = false; update_option("tecteb_home_settings", $s);' >/dev/null 2>&1
d="$(page /)"
has "$d" rendered "$FRONT" && has "$d" head_css "${FRONT_ONLY[@]}"; check "front-page takeover off: old Elementor front page and all its assets in <head>, as without this plugin" $? "$d"
$W eval '$s = get_option("tecteb_home_settings"); $s["front_page"] = true; update_option("tecteb_home_settings", $s);' >/dev/null 2>&1

# ۴-ب. قالب هدری که در فهرست جایگزینی نیست (هدر دومی فقط برای برگهٔ نخست): Elementor چاپش می‌کند و Header B کنار
# می‌رود، پس دارایی‌هایش باید از همان اول در <head> باشند — نه پنهان و بعد دیر در body.
SECOND="$($W --user=tthadmin eval-file "$HERE/perf/tools/real-elementor-testbed.php" second-header 2>/dev/null | tail -1)"
curl -s -o /dev/null "$SITE/"; d="$(page /)"
has "$d" rendered "$SECOND" && [[ "$(val "$d" tth)" == "False" ]] && has "$d" head_css "elementor-post-$SECOND"; check "front page with a second Elementor header ($SECOND) that is not in the replacement list: Elementor prints it, Header B stands aside, and its post-$SECOND.css is in <head> from the start" $? "$d"
$W --user=tthadmin eval-file "$HERE/perf/tools/real-elementor-testbed.php" drop-second >/dev/null 2>&1; SECOND=""

# ۵. برگهٔ نخستِ غیر-Elementor: Homepage هیچ کاری نمی‌کند (و پست جاری هرگز خالی نمی‌ماند).
FRONT_EDIT_MODE="$FRONT"; $W post meta update "$FRONT" _elementor_edit_mode '' >/dev/null 2>&1
d="$(page /)"
[[ "$(val "$d" tth)" == "True" ]] && lacks "$d" head_css "${FRONT_ONLY[@]}" && has "$d" head_css "${FOOTER[@]}"; check "front page no longer built with Elementor but with stale page-assets meta (Elementor still loads it): skipped too — no front-page assets, footer intact" $? "$d"
$W post meta update "$FRONT" _elementor_edit_mode builder >/dev/null 2>&1; FRONT_EDIT_MODE=""

# ۶. هیچ خطا یا هشدار PHP از دو افزونه.
: > /home/user/wp2/core/wp-content/debug.log
for p in / /shop/ '/?tthp_readd=1' '/?tthp_flip=1'; do curl -s -o /dev/null "$SITE$p"; done
! grep -E "tecteb-(header|homepage)" /home/user/wp2/core/wp-content/debug.log >/dev/null; check "no PHP notice, warning or error from either plugin in debug.log" $? "$(grep -m2 -E 'tecteb-' /home/user/wp2/core/wp-content/debug.log)"

{ echo "tecteb-header $($W plugin get tecteb-header --field=version 2>/dev/null) · tecteb-homepage $($W plugin get tecteb-homepage --field=version 2>/dev/null) · Elementor $($W plugin get elementor --field=version 2>/dev/null) · PRO Elements $($W plugin get pro-elements --field=version 2>/dev/null)"; echo; printf '%s\n' "${LOG[@]}"; echo; echo "$PASS passed, $FAIL failed"; } > "$OUT"
grep -E "^(PASS|FAIL)" "$OUT"; echo; echo "$PASS passed, $FAIL failed"
exit $(( FAIL > 0 ? 1 : 0 ))
